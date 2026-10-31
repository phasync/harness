<?php

declare(strict_types=1);

use Harness\Accumulator;
use Harness\Cancel;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Http\Response;
use Harness\Mcp\Connection;
use Harness\Mcp\McpException;
use Harness\Mcp\McpServer;
use Harness\Message;
use Harness\ModelException;
use Harness\Options;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\ToolChoice;
use Harness\ToolSpec;
use Harness\UnsupportedContentException;
use Harness\UnsupportedOptionException;

/*
 * Mcp\McpTool::result() builds a `Result` straight from `content ?? []` and `structuredContent ??
 * null` (src/Mcp/McpTool.php): a `tools/call` reply with `isError: true` and neither `content` nor
 * `structuredContent` (one of the adversarial threats this module's charter names explicitly: "a
 * tool result with isError and no content") legitimately yields `Result([], null, error: true)`.
 * `Run::toolResult()` (src/Run.php:771) passes `$result->content` straight into `ToolResult`
 * unchanged, so an MCP tool failing this way produces `ToolResult($id, [], true)`.
 *
 * Chat Completions and the Responses API both guard this case: `($part->error ? 'Error: ' : '') .
 * implode("\n", $text)` always yields a non-empty string, even when `$text` is empty, because of
 * the 'Error: ' prefix (src/Provider/OpenAI/ChatCompletions.php and .../Responses.php). Anthropic's
 * `Messages::user()` has no such guard: `'content' => array_map($this->block(...), $part->content)`
 * maps an empty `$part->content` straight to an empty array, so the request sent to the Messages
 * API carries `{"type":"tool_result","tool_use_id":"...","content":[],"is_error":true}`. The
 * Messages API rejects a tool_result block whose content is an empty list (it requires at least one
 * block), so this turns a tool failure the host already observed as an *error result* into a 400
 * from the provider instead -- the one path through this module that is documented to carry an
 * error to the model (docs/anthropic.md: "ToolResult maps to tool_result with is_error") cannot
 * actually reach the model when the tool contributed no content, which happens whenever an MCP
 * server reports isError without content (no made-up server needed: a server that fails before
 * producing any output, e.g. a precondition check, has nothing else to put in `content`).
 *
 * Verified directly against the real code (both adapters): the Anthropic request body's tool_result
 * block has "content": [] when ToolResult content is empty, while Chat Completions' tool message
 * for the identical ToolResult always has non-empty content.
 */
it('sends a non-empty tool_result content block for a ToolResult with no content', function () {
    $body = '';
    foreach ([
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ['type' => 'message_stop'],
    ] as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }
    $transport = new FakeTransport(new Response(200, [], \str_split($body, 17)));
    $messages  = [
        new Message(Role::User, [new Text('run it')]),
        new Message(Role::Assistant, [new ToolCall('t1', 'broken', [])]),
        // What Mcp\McpTool::result() builds for a `tools/call` reply {"isError": true} with
        // neither "content" nor "structuredContent": Result([], null, error: true), carried into
        // ToolResult unchanged by Run::toolResult().
        new Message(Role::User, [new ToolResult('t1', [], true)]),
    ];
    $model = new Messages($transport, 'key', 'claude-x');
    [...$model->stream(new Prompt(null, $messages, [new ToolSpec('broken', 'd', ['type' => 'object'])], new Options(), live()))];

    $sent  = \json_decode($transport->requests[0]['body'], true);
    $block = $sent['messages'][2]['content'][0];
    // #14: this sent "content": [], which the Messages API rejects
    expect($block['type'])->toBe('tool_result')
        ->and($block['content'])->toBe([['type' => 'text', 'text' => 'Error']]);
});

/*
 * OpenAI's Chat Completions streaming delta carries a `refusal` field alongside `content` and
 * `reasoning_content`/`reasoning` (used for structured-output refusals: the model declines and
 * explains why, streamed as `choices[].delta.refusal` chunks instead of `delta.content`, finishing
 * with `finish_reason: "content_filter"`). `ChatCompletions::delta()` (src/Provider/OpenAI/
 * ChatCompletions.php) only reads `delta['reasoning_content']`, `delta['reasoning']`,
 * `delta['content']` and `delta['tool_calls']` -- there is no branch for `delta['refusal']` at all,
 * so every `refusal` chunk is silently thrown away: no `TextDelta`, no `ReasoningDelta`, nothing.
 * The stream still ends correctly (`finish_reason: content_filter` -> `Finish(FinishReason::
 * Refusal)`, matching docs/openai.md), but the assistant message `Accumulator::message()` builds
 * has zero parts, so the only description of *why* the model refused is gone.
 *
 * Contrast with the Responses API in this same module, which maps the equivalent field on purpose:
 * `case 'response.refusal.delta': $refused = true; yield new TextDelta($e['delta']);` (src/Provider/
 * OpenAI/Responses.php). Chat Completions has the `$refused`-equivalent information available (the
 * `finish_reason`) but never captures the refusal text itself -- an asymmetry between the two
 * OpenAI-family providers in this module, not a documented limitation (docs/openai.md's
 * ChatCompletions section says nothing about `refusal` being dropped).
 *
 * Verified directly against the real code (both adapters): streaming `delta: {"refusal": "..."}`
 * followed by `finish_reason: "content_filter"` yields only a bare `Finish(Refusal)`; the refusal
 * text never appears as any Delta.
 */
it('reports the model\'s refusal text, not just that it refused', function () {
    $body = 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['refusal' => "I can't help with that."], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'content_filter']]]) . "\n\n"
          . "data: [DONE]\n\n";
    $model  = new ChatCompletions(new FakeTransport(new Response(200, [], \str_split($body, 19))), 'http://h/v1', 'm');
    $deltas = [...$model->stream(new Prompt(null, [], [], new Options(), live()))];

    // #17: only the Finish came out; the refusal text is the reply and is yielded as text
    expect($deltas)->toContainEqual(new TextDelta("I can't help with that."))
        ->and($deltas)->toContainEqual(new Finish(FinishReason::Refusal));
});

/*
 * `ChatCompletions::stream()` reads `$chunk['choices'] ?? []` and processes EVERY choice in it
 * (src/Provider/OpenAI/ChatCompletions.php): `foreach ($chunk['choices'] ?? [] as $choice) { yield
 * from $this->delta($choice['delta'] ?? [], $ids); ... }`, with no filtering by `choice['index']`.
 * `Options::$provider['openai']` is documented to be "merged into the request body last, so it can
 * set anything" (docs/openai.md) -- including `n` (OpenAI's "how many choices to generate"), and a
 * server asked for `n > 1` streams each choice's deltas interleaved by `choices[].index`, exactly
 * like tool-call argument fragments are interleaved by `tool_calls[].index` (a case this module
 * handles correctly via `$ids`). But `Model::stream()`'s contract (and `Accumulator`'s: "Folds the
 * deltas of one reply into the assistant `Message`") is ONE reply. With `n > 1`, text from
 * choice 1 lands in the very same `Accumulator` as choice 0's -- consecutive `TextDelta`s extend
 * whatever text part is last, regardless of which independent choice produced them -- so two
 * unrelated completions are silently spliced into one assistant message with no error at all; the
 * same missing index check would just as well splice two different choices' tool calls together
 * (a shared index across choices colliding in `$ids`).
 *
 * Verified directly against the real code (both adapters): two choices (`index: 0` streaming
 * "Hello", `index: 1` streaming "Goodbye") fold, through the real `Accumulator`, into one `Text`
 * part reading "HelloGoodbye" -- not an error, not two separate replies, not even choice 0 alone.
 */
it('fails loudly when the server streams a second choice instead of splicing it into the reply', function () {
    $body = 'data: ' . \json_encode(['choices' => [
        ['index' => 0, 'delta' => ['content' => 'Hello'], 'finish_reason' => null],
        ['index' => 1, 'delta' => ['content' => 'Goodbye'], 'finish_reason' => null],
    ]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [
              ['index' => 0, 'delta' => [], 'finish_reason' => 'stop'],
              ['index' => 1, 'delta' => [], 'finish_reason' => 'stop'],
          ]]) . "\n\n"
          . "data: [DONE]\n\n";
    $model = new ChatCompletions(new FakeTransport(new Response(200, [], \str_split($body, 23))), 'http://h/v1', 'm');
    // #20: this folded choice 1 into choice 0 as "HelloGoodbye"; a second choice breaks the one-reply contract
    expect(fn () => [...$model->stream(new Prompt(null, [], [], new Options(), live()))])
        ->toThrow(ModelException::class, 'second choice');
});

it('refuses n other than 1 before the request is sent', function () {
    $t = new FakeTransport();
    expect(fn () => [...(new ChatCompletions($t, 'http://h/v1', 'm'))->stream(new Prompt(null, [user('hi')], [], new Options(provider: ['openai' => ['n' => 2]]), live()))])
        ->toThrow(UnsupportedOptionException::class, 'n must be 1')
        ->and($t->requests)->toBe([]);
});

/*
 * A `thinking` content block's `signature` is built only from `signature_delta` events
 * (`$blocks[$i]['signature'] .= $delta['signature']`, src/Provider/Anthropic/Messages.php) and
 * defaults to `''` from `content_block_start` if none ever arrive. Nothing stops `content_block_stop`
 * from closing a `thinking` block before any `signature_delta` showed up -- exactly what happens
 * when the reply is cut short by `max_tokens` while still inside a thinking block (the API still
 * closes the open block with `content_block_stop` so `message_delta`/`message_stop` can follow, but
 * the model never got to emit the signature it signs the block with once it is done reasoning).
 * `message()`'s `ReasoningDelta` then carries `signature: ''` into `raw['blocks']` verbatim, and
 * `Messages::assistant()` replays `raw['blocks']` unchanged on the next turn to the same model
 * (`array_push($out, ...($part->raw['blocks'] ?? []))`) with no check at all -- so a `thinking`
 * block that never got a signature goes back to the API as `{"type":"thinking", ...,
 * "signature": ""}`.
 *
 * docs/anthropic.md promises the Reasoning part's blocks are "replayed unchanged ... (with
 * signatures)" -- i.e. the contract assumes a complete, signed block, not a half-built one from a
 * turn the model never finished signing. The Messages API verifies a thinking block's signature
 * when it is replayed and rejects an empty or invalid one; silently sending `signature: ""` turns a
 * truncated turn (which already ended cleanly, `FinishReason::Length`) into a hard failure on the
 * *next* turn instead of handling it where the module already handles everything else it cannot
 * carry forward (by failing loudly, e.g. `UnsupportedOptionException`/`UnsupportedContentException`
 * elsewhere in this same class) or by dropping the incomplete block the way a cut-off tool call's
 * invalid JSON is reported rather than guessed at.
 *
 * Verified directly against the real code (both adapters): a thinking block cut off by `max_tokens`
 * before any `signature_delta` accumulates into `raw['blocks']` with `"signature": ""`, and replaying
 * that Reasoning part to the same model sends that empty signature on to the API unchanged.
 */
it('does not replay a thinking block whose signature never arrived', function () {
    $body = '';
    foreach ([
        ['type' => 'message_start', 'message' => ['usage' => []]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'cut off mid-thought']],
        // Cut short by max_tokens: content_block_stop closes the block, but no signature_delta ever came.
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => []],
        ['type' => 'message_stop'],
    ] as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }
    $acc = new Accumulator();
    foreach ((new Messages(new FakeTransport(new Response(200, [], \str_split($body, 23))), 'k', 'claude-x'))
        ->stream(new Prompt(null, [], [], new Options(), live())) as $delta) {
        $acc->add($delta);
    }
    $assistant = $acc->message();
    expect($assistant->parts[0])->toBeInstanceOf(Reasoning::class)
        ->and($assistant->parts[0]->raw['blocks'][0]['signature'])->toBe('');  // confirms the setup

    // Replay that reply as the prior turn of a new request to the same model.
    $t = new FakeTransport(new Response(200, [], \str_split(
        "event: message_delta\ndata: " . \json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
        . "event: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n",
        23,
    )));
    [...(new Messages($t, 'k', 'claude-x'))->stream(new Prompt(null, [user('go'), $assistant], [], new Options(), live()))];
    $sent     = \json_decode($t->requests[0]['body'], true);
    // #24: the empty signature was replayed verbatim, which the API rejects; the block is dropped,
    // and this reply had nothing else, so the assistant turn goes with it
    expect($sent['messages'])->toHaveCount(1)
        ->and(\json_encode($sent))->not->toContain('"thinking"');
}); // #24: the unsigned block was replayed verbatim; the API rejects an empty signature

/*
 * #24's fix drops only the unsigned `thinking` block from `assistant()`'s loop
 * (src/Provider/Anthropic/Messages.php: `if ('thinking' === ... && '' === signature) { continue; }`);
 * every other part of the same message -- `Text`, `ToolCall` -- is still appended unconditionally
 * right after it in the same `foreach`. So a reply that had an unsigned thinking block followed by
 * a tool call is NOT "a reply left with nothing" (#24's fallback, where the whole turn drops out):
 * it still has its `tool_use` block, and that alone is what gets replayed.
 *
 * Anthropic's extended-thinking contract (reasoning this module's own docblock summarizes as
 * "Thinking is opaque and model-bound ... replayed unchanged") requires that when `thinking` is
 * enabled for a request, an assistant turn containing `tool_use` must be preceded by the
 * `thinking`/`redacted_thinking` block(s) that produced it; sending `tool_use` on its own in that
 * turn is rejected by the Messages API (`thinking` requires the turn to open with a thinking block
 * whenever that turn resulted in a tool call). Dropping the block because it has no signature *and*
 * keeping the `tool_use` that followed it therefore builds exactly the request shape the API
 * rejects -- #24 fixed the "nothing left" case but not this one.
 *
 * Verified directly against the real code (both adapters): an assistant message with
 * `Reasoning([thinking, signature: ''])` immediately followed by `ToolCall` replays as
 * `[{"type":"tool_use",...}]` with no thinking block in front of it, under a request that has
 * `thinking` enabled -- the one combination #24 did not cover.
 */
it('does not replay a tool_use whose preceding thinking block was dropped as unsigned', function () {
    $assistant = new Message(Role::Assistant, [
        new Reasoning('cut off', ['model' => 'claude-x', 'blocks' => [
            ['type' => 'thinking', 'thinking' => 'cut off mid-thought', 'signature' => ''],
        ]]),
        new ToolCall('t1', 'read', ['path' => 'a']),
    ]);
    // A real response is scripted so that, if the broken request is sent at all, the failure
    // reported is the module's own -- not FakeTransport running out of canned responses.
    $t = new FakeTransport(new Response(200, [], \str_split(
        "event: message_delta\ndata: " . \json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
        . "event: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n",
        23,
    )));
    $thinks = new Options(provider: ['anthropic' => ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2000]]]);
    $stream = fn () => [...(new Messages($t, 'k', 'claude-x'))->stream(new Prompt(
        null,
        [user('go'), $assistant],
        [new ToolSpec('read', 'd', ['type' => 'object'])],
        $thinks,
        live(),
    ))];

    // Today: no exception at all -- the broken request (tool_use with no thinking ahead of it,
    // under thinking: enabled) is actually sent, exactly the shape the Messages API rejects.
    expect($stream)->toThrow(UnsupportedContentException::class)
        ->and($t->requests)->toBe([]);
}); // #31: the tool_use went out without its thinking block

/*
 * Round 6's attack on #31's fix: an unsigned `thinking` block followed only by `Text` (no
 * `ToolCall`) -- `assistant()` drops the block and keeps the text, no exception. Verified directly:
 * this is correct, not a regression -- Anthropic requires a preserved thinking block only ahead of
 * a `tool_use` in that turn (what #31 already covers); a text-only continuation has no such
 * requirement. Also verified: a `redacted_thinking` block (type `redacted_thinking`, carrying
 * `data`, never `signature`) is never caught by the `'thinking' === $block['type'] && '' ===
 * $block['signature']` check, because the type comparison alone already excludes it -- it is kept
 * (with any following tool_use) exactly as it should be. Neither hypothesis held up; this module's
 * actual next defect is in `Mcp\McpServer`, below.
 *
 * `McpServer::tools()` (src/Mcp/McpServer.php) pages `tools/list` with:
 *   do { $page = $this->request('tools/list', ...); $cursor = $page['nextCursor'] ?? null; ... }
 *   while (null !== $cursor);
 * There is no check that `$cursor` actually changed between pages, and no cap on how many pages it
 * will fetch. A server with a pagination bug -- or one that is simply slow/misbehaving under load --
 * that keeps handing back the same `nextCursor` it just gave out (instead of `null` once there is no
 * more data) makes this loop run forever: every iteration re-requests the identical page and gets
 * the identical non-null cursor back, with no way out. This is the "hang" category this module's
 * charter names explicitly, and it is reachable from any `Run` step that reads an `McpServer`'s
 * tools (`tools()` is called every step to refresh an expired cache) -- one misbehaving MCP server
 * stalls the whole turn indefinitely, not just that one `tools/list` call.
 *
 * Verified directly against the real code (both adapters): a `Connection` stub that answers every
 * `tools/list` with the exact same `nextCursor` is called over and over with no sign of stopping --
 * bounded here at 50 calls only so the test itself terminates; nothing in `McpServer` would have
 * stopped it on its own.
 */
it('does not keep paging tools/list forever when the cursor never advances', function () {
    $conn = new class implements Connection {
        public int $calls = 0;

        public function request(string $method, array $params, Cancel $cancel, ?Closure $notify = null, array $headers = []): array
        {
            if ('server/discover' === $method) {
                return ['supportedVersions' => [McpServer::VERSION]];
            }
            if (++$this->calls > 50) {
                // Not a real protection in the code under test -- only so this test terminates.
                throw new RuntimeException("tools/list was called {$this->calls} times with the same cursor; tools() never noticed and never stopped");
            }

            // A server bug: every page claims the very same next page exists, forever.
            return ['tools' => [], 'nextCursor' => 'same-cursor-forever', 'ttlMs' => 60000];
        }
    };
    $server = new McpServer($conn);

    // #35: tools() requested the identical cursor past any bound; a repeated cursor is an McpException
    expect(fn () => $server->tools())->toThrow(McpException::class, 'does not advance')
        ->and($conn->calls)->toBe(2);
});

/*
 * Round 7's attack on #35's fix: an alternating A, B, A, ... cursor is caught (the second A is a
 * repeat `$seen` already has). A server handing out 10 000 distinct, never-repeating cursors (even
 * with empty pages every time) is NOT caught by `$seen`, and docs/mcp.md only says "`tools/list`
 * (every page) is cached for its `ttlMs`" -- it never promises a page-count cap, so fetching however
 * many distinct pages a server claims to have is the documented contract working as intended, not a
 * new defect; this module's #35 fix was aimed at the one thing that IS a protocol violation (a
 * cursor that doesn't advance), and it still only catches that. `ttlMs` of `0`/negative also behaves
 * exactly as documented ("a list with ttlMs: 0 is fetched each step"): `$this->expires = microtime(true)
 * + $ttl / 1000` lands at or before now, so the very next `tools()` call re-fetches, matching the doc
 * -- not a defect either.
 *
 * The real gap is elsewhere in this same class: `Messages::stream()`'s `message_stop` handler maps
 * `stop_reason` to `Finish` purely from the server's say-so --
 *   'tool_use' => FinishReason::ToolCalls,
 * -- with no check that a `tool_use` content block (`ToolCallStart`/`ToolCallEnd`) was ever actually
 * yielded during the stream. docs/anthropic.md's own promise ("`stop_reason`: ... `tool_use` ->
 * ToolCalls") describes a reply that calls a tool; `FinishReason::ToolCalls` is the signal the rest
 * of the harness (Run, any host UI) reads to mean "there is a tool call to carry out" -- compare
 * Responses.php in this same module, which tracks its own `$called` flag and only reports
 * `FinishReason::ToolCalls` when a `function_call` item actually showed up, falling back to `Stop`
 * otherwise. Anthropic's `Messages` has no equivalent check: a server that claims `stop_reason:
 * "tool_use"` while every content block was `text` makes this provider yield `Finish(ToolCalls)` for
 * a message that, decoded by the real `Accumulator`, holds nothing but `Text` -- the one case this
 * exact `match` already treats everything else defensively (an unrecognised `stop_reason` throws
 * `ModelException` right there), yet this specific, checkable inconsistency sails through silently.
 *
 * Verified directly against the real code (both adapters): a stream with a single `text` block and
 * `stop_reason: "tool_use"` yields `Finish(FinishReason::ToolCalls)` with no `ToolCallStart` or
 * `ToolCallEnd` anywhere in the delta list.
 */
it('does not report ToolCalls when stop_reason says tool_use but no tool_use block was ever started', function () {
    $body = '';
    foreach ([
        ['type' => 'message_start', 'message' => ['usage' => []]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me check...']],
        ['type' => 'content_block_stop', 'index' => 0],
        // The server lies: it says it stopped to call a tool, but never opened a tool_use block.
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => []],
        ['type' => 'message_stop'],
    ] as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }
    $model = new Messages(new FakeTransport(new Response(200, [], \str_split($body, 23))), 'k', 'claude-x');

    // Today: no exception, and the deltas end with Finish(ToolCalls) even though the real
    // Accumulator would build a Text-only message (no ToolCallStart/End anywhere) from this stream.
    try {
        [...$model->stream(new Prompt(null, [], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        expect($e->kind)->toBe(ErrorKind::ModelError)
            ->and($e->getMessage())->toContain('no tool_use block');
    }
}); // #39: Finish(ToolCalls) with no tool call in the stream

/*
 * Round 8's attack on #39 and its siblings:
 *
 * - The converse for Anthropic (a real tool_use block present, but `stop_reason: end_turn`):
 *   executed through a full `Run` with a real tool. The tool IS run -- `Run::loop()` decides
 *   whether to execute tools purely from `$message->parts` holding a `ToolCall` (src/Run.php:
 *   `$calls = array_filter($message->parts, ... instanceof ToolCall)`), never from the `Finish`
 *   value, so nothing is silently skipped. `Finish(Stop)` for a turn that did call a tool is an odd
 *   label but not a wrong *result*, and docs/anthropic.md's mapping is a direct, unconditional
 *   value table ("`end_turn`/`stop_sequence` -> Stop") with no stated exception for a tool_use
 *   block being present -- not a new defect.
 * - Responses `response.completed` with `status: "incomplete"` and `incomplete_details.reason:
 *   max_output_tokens`: traced the code -- the `Finish` match keys only on `$e['type']`
 *   ('response.completed' vs 'response.incomplete'), so a `response.completed` event always maps to
 *   Stop/ToolCalls/Refusal by the `$refused`/`$called` flags, never Length, regardless of what
 *   `status`/`incomplete_details` the `response` object carries alongside it; docs/openai.md ties
 *   Length specifically to the `response.incomplete` *event type*, which is what the real API sends
 *   for a token-limited cutoff -- a `response.completed` event carrying `status: incomplete` would
 *   be the API contradicting its own event-type contract, not a gap in this code. Not pursued
 *   further as a defect of ours.
 * - There is no docs/run.md in this repo; README.md and Accumulator's own docblock were checked for
 *   a promise that a truncated tool call's error result tells the model exactly *why* the input was
 *   unusable (max_tokens vs. a genuine formatting mistake): neither says anything about that
 *   distinction (Accumulator's docblock only promises "the reason quotes at most 500 bytes of the
 *   input and gives its length"), so the earlier "not valid JSON" framing not naming truncation does
 *   not break a stated contract -- not pinned as a regression per the coordinator's own conditional
 *   ("if the docs anywhere promise it").
 *
 * - The one that reproduces: `ChatCompletions::stream()` has no guard at all analogous to #39's
 *   fix. `finish_reason: 'tool_calls'` maps straight to `FinishReason::ToolCalls` regardless of
 *   whether `$ids` (the tool calls actually seen) is empty -- the exact same defect class #39 fixed
 *   in `Anthropic\Messages`, now confirmed present in the sibling OpenAI provider in this same
 *   module.
 *
 * Verified directly against the real code (both adapters): a stream with ordinary `content` text
 * and `finish_reason: "tool_calls"` -- no `tool_calls` delta anywhere -- yields `Finish(ToolCalls)`
 * with no `ToolCallStart`/`ToolCallEnd` ever emitted.
 */
it('does not report ToolCalls when finish_reason says tool_calls but none were ever started', function () {
    $body = 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'Hello, no tools here.'], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
          . "data: [DONE]\n\n";
    $model = new ChatCompletions(new FakeTransport(new Response(200, [], \str_split($body, 19))), 'http://h/v1', 'm');

    // Today: no exception, and the deltas end with Finish(ToolCalls) with no tool call anywhere in
    // the stream -- the same defect class #39 fixed in Anthropic\Messages, unguarded here.
    try {
        [...$model->stream(new Prompt(null, [], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        expect($e->kind)->toBe(ErrorKind::ModelError)
            ->and($e->getMessage())->toContain('holds no tool call');
    }
}); // #45: Finish(ToolCalls) with no tool call in the stream, as #39 in Messages

/*
 * Round 9's attack on #45 and the third provider:
 *
 * - Responses `response.completed` with a `function_call` item present in `output` but no
 *   `response.output_item.added`/`.done` events for it (i.e. `$called` never set): traced -- every
 *   path that could make `$called` true is driven by the SSE events this code actually switches on,
 *   never by inspecting `response.completed`'s own `output` array, so this exact mismatch (`output`
 *   says a function_call exists, no event said so) cannot arise from what the code reads; it would
 *   require the API to omit the very events this provider relies on while still listing the item in
 *   the final payload -- not reachable the way #39/#45 were (those needed only a stop/finish value
 *   contradicting events already seen, not events contradicting a field this code never reads).
 * - A `function_call` item whose arguments stream in but `response.output_item.done` never fires:
 *   executed -- the only consequence is a missing `ToolCallEnd` delta during the live stream (no
 *   progress event for that call); `Accumulator::message()` decodes every call's accumulated JSON
 *   regardless of its `ended` flag, so the final message is unaffected. A real gap (ChatCompletions
 *   force-closes pending calls at stream end, src/Provider/OpenAI/ChatCompletions.php; Responses
 *   does not), but not a wrong final result -- same conclusion as earlier rounds' look at this.
 * - `tool_calls` delta with an index but no id on the first chunk for it: already covered by
 *   docs/openai.md ("a server that omits the call id gets a generated one") and the fallback
 *   `$call['id'] ?? ('call_' . bin2hex(random_bytes(8)))` -- working as documented.
 *
 * - The one that reproduces: `ChatCompletions::delta()`'s `$ids` map is keyed by `index` alone and
 *   is "sticky" for the rest of the stream -- `if (!isset($ids[$index])) { $ids[$index] = ...; yield
 *   new ToolCallStart(...); }` only ever fires once per index, no matter what a *later* chunk's
 *   `tool_calls[].id` or `.function.name` says for that same index. Compare Anthropic's
 *   `content_block_start`, which always overwrites `$blocks[$e['index']]` and always yields a fresh
 *   `ToolCallStart` for a `tool_use` block regardless of what was at that index before -- Chat
 *   Completions has no equivalent. A second, logically distinct tool call that reuses an index (the
 *   same bug class as n > 1's interleaved choices and the earlier duplicate-id cases, but via a
 *   buggy proxy restarting its per-message index counter instead of incrementing) is silently folded
 *   into the FIRST call at that index: its name is discarded entirely -- no `ToolCallStart`, no
 *   error, nothing -- and its arguments are appended as if they were more of the first call's JSON,
 *   corrupting what was a complete, valid call into invalid JSON.
 *
 * Verified directly against the real code (both adapters): two complete, independent tool calls
 * (`call_A`/`toolA` with valid arguments `{"x":1}`, then `call_B`/`toolB` with valid arguments
 * `{"y":2}`) both sent at `index: 0`. The real `Accumulator` ends up with exactly one `ToolCall`
 * part, named `toolA`, whose input is `[]` because its JSON is now `{"x":1}{"y":2}` -- invalid.
 * `toolB` never appears anywhere; a call that was never broken is reported broken, and a second,
 * fully valid call vanishes without a trace.
 */
it('does not fold a second tool call into the first when a chunk reuses the same index', function () {
    $body = 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
        ['index' => 0, 'id' => 'call_A', 'type' => 'function', 'function' => ['name' => 'toolA', 'arguments' => '{"x":1}']],
    ]], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
              ['index' => 0, 'id' => 'call_B', 'type' => 'function', 'function' => ['name' => 'toolB', 'arguments' => '{"y":2}']],
          ]], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
          . "data: [DONE]\n\n";
    $model = new ChatCompletions(new FakeTransport(new Response(200, [], \str_split($body, 23))), 'http://h/v1', 'm');

    // The API gives every tool call its own index; a new id at a seen index is a broken stream, not a merge.
    expect(fn () => [...$model->stream(new Prompt(null, [], [], new Options(), live()))])
        ->toThrow(ModelException::class, "changed its id from 'call_A' to 'call_B'");
}); // #49: one ToolCall named toolA with the invalid input {"x":1}{"y":2}; toolB vanished

/*
 * Round 10's attack on #49's fix:
 *
 * - A later chunk at a seen index repeating the SAME id (Azure and some proxies send the id on
 *   every chunk, not just the first, unlike OpenAI itself): executed -- `$call['id'] !== $ids[$index]`
 *   is false when they match, so the guard does not fire; the call reassembles correctly
 *   (`{"x":1}` from two fragments). Passes, as it must.
 * - First appearance of an index with an `id` but no `name`: already throws "began without a
 *   function name" via the existing `?? throw` on the first-appearance branch -- not new.
 * - `tool_calls` with indices out of order or skipped (`0` then `2`, no `1`): executed -- `$ids` is
 *   a plain associative array keyed by whatever index appears, with no assumption of contiguity or
 *   order anywhere (not even in the final `foreach ($ids as $id) yield new ToolCallEnd($id)`, which
 *   iterates insertion order, not numeric order, and correctness never depends on either). Both
 *   calls come out distinct and correct. Not a defect.
 *
 * - The one that reproduces: #49's guard is `elseif (isset($call['id']) && $call['id'] !==
 *   $ids[$index])` -- it only ever inspects `id`. `function.name` is read exclusively on the
 *   *first* appearance of an index (inside the `!isset($ids[$index])` branch) and is never looked
 *   at again afterwards. A later chunk at a seen index that carries a *different* `function.name`
 *   but omits `id` entirely -- exactly the shape of every ordinary continuation chunk for a single
 *   real call (OpenAI itself sends `id` only once, on the call's first chunk, and never repeats
 *   `name` either) -- sails straight through the `isset($call['id'])` check, which is false, so the
 *   `elseif` never even runs. This is #49's exact corruption (a second, independently valid tool
 *   call silently folded into the first, its name discarded, the first call's valid JSON wrecked
 *   into garbage), reopened by removing one field the fix never looked at.
 *
 * Verified directly against the real code (both adapters): `index: 0` first names `toolA` with
 * valid arguments `{"x":1}`; a second chunk at `index: 0`, with no `id` at all, names `toolB` with
 * its own valid arguments `{"y":2}`. The result is identical to #49's original bug: one `ToolCall`
 * named `toolA` whose JSON is now the invalid `{"x":1}{"y":2}`; `toolB` never appears.
 */
it('does not fold a second tool call into the first when only its name changes, not its id', function () {
    $body = 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
        ['index' => 0, 'id' => 'call_A', 'type' => 'function', 'function' => ['name' => 'toolA', 'arguments' => '{"x":1}']],
    ]], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
              ['index' => 0, 'function' => ['name' => 'toolB', 'arguments' => '{"y":2}']],
          ]], 'finish_reason' => null]]]) . "\n\n"
          . 'data: ' . \json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
          . "data: [DONE]\n\n";
    $model = new ChatCompletions(new FakeTransport(new Response(200, [], \str_split($body, 23))), 'http://h/v1', 'm');

    expect(fn () => [...$model->stream(new Prompt(null, [], [], new Options(), live()))])
        ->toThrow(ModelException::class, "changed its name from 'toolA' to 'toolB'");
}); // #55: #49 checked the id only; a continuation chunk carries none

/*
 * Round 11, worked through the coordinator's list in order:
 *
 * - Responses `function_call_output` for an unknown `call_id`: traced `Responses::user()` and
 *   `request()` -- there is no local validation anywhere against the prior assistant message's
 *   `function_call` items; the mismatched `call_id` is sent as-is. No `LogicException` is thrown
 *   before the request. It is a provider 400 after the request, mapped by `self::error()` like any
 *   other `invalid_request_error` (no special-cased code for it, so it falls to the generic
 *   `ErrorKind::ModelError` default) -- a plain, correctly-classified error, not a new defect.
 * - MCP elicitation with a field type the client does not know: `McpTool::needs()`'s per-field
 *   `match` already only branches on `'boolean'` vs. `enum` vs. a default `null` (free text) --
 *   every other type, known or not, takes the default branch and becomes a free-text `Question`.
 *   No defect; this is already the correct "don't know, ask as text" fallback.
 * - MCP `tools/call` `isError: true` with empty content: re-checked against #14's actual fix --
 *   it lives in `Mcp\McpTool::result()` itself (an empty `content` and no `structuredContent` now
 *   becomes one `Text` block, `"Error"` or `"(no output)"`), not in any one provider, so every
 *   provider (including Anthropic's now-guarded `tool_result`) sees non-empty content. Not a new
 *   defect; #14 is holding.
 * - A `resource` content item with a `blob` (not `text`): `McpTool::result()`'s `'resource'` branch
 *   already takes the `isset($c['resource']['text'])` / else-`Media` path correctly -- verified it
 *   decodes the base64 `blob` into `Media` with the resource's own `mimeType`. Not a defect.
 *
 * - The one that reproduces: Anthropic's 529/`overloaded_error` mapping to `ErrorKind::RateLimited`
 *   is correct (per docs/anthropic.md), but `retryAfter` is dropped on exactly one of this class's
 *   two paths. The top-level non-200 response path reads it correctly:
 *     throw self::error($error, $response->status, $response->headers['retry-after'] ?? null);
 *   but the in-stream `error` event (an `overloaded_error`/`rate_limit_error` arriving as an SSE
 *   event on an already-started 200 response, which is exactly how a mid-stream overload is
 *   reported) hardcodes it away:
 *     case 'error': throw self::error($e['error'], 200, null);
 *   `$response` is still the very same variable in scope at that point, holding whatever headers the
 *   200 response carried -- `$response->headers['retry-after'] ?? null` would read it exactly the
 *   same way the line above it already does. A host that backs off using `ModelException::$retryAfter`
 *   gets `null` and falls back to its own default/no backoff for this one path, even when the
 *   server told it precisely how long to wait.
 *
 * Verified directly against the real code (both adapters): a 200 response carrying `retry-after:
 * 30` whose body streams `message_start` then an in-band `error` event (`overloaded_error`) throws
 * `ModelException` with `kind: RateLimited` but `retryAfter: null` -- the header was right there on
 * the very `Response` object in scope and was never read.
 */
it('reads Retry-After for an in-stream error event, not only a non-200 response', function () {
    $body = '';
    foreach ([
        ['type' => 'message_start', 'message' => ['usage' => []]],
        ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
    ] as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }
    // The 200 response that starts the stream still carries a Retry-After header.
    $model = new Messages(new FakeTransport(new Response(200, ['retry-after' => '30'], \str_split($body, 23))), 'k', 'claude-x');

    try {
        [...$model->stream(new Prompt(null, [], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        // Today: retryAfter is null -- the in-stream 'error' case hardcodes it, ignoring the
        // Retry-After header that the very same $response it still has in scope carries.
        expect($e->kind)->toBe(ErrorKind::RateLimited)
            ->and($e->retryAfter)->toBe(30.0);
    }
}); // #59: the in-stream error path passed null

/*
 * Round 12's sweep of error paths across providers:
 *
 * - Retry-After as an HTTP-date, not seconds: all three providers share the same
 *   `is_numeric($retryAfter) ? (float) $retryAfter : null` guard; an HTTP-date string is not
 *   numeric, so `retryAfter` is just `null` -- a safe, silent "no retry hint", not a crash or a
 *   wrong number. A real gap (the HTTP-date form is valid per RFC 9110 and never parsed), but not
 *   a wrong *result* the way a bad value would be; lower priority than what follows.
 * - Chat Completions / Responses 429 with Retry-After: both already read it on their non-200 path
 *   (`$response->headers['retry-after'] ?? null`, same as Anthropic's fixed #59 path). Not a gap.
 * - Chat Completions data line that is not JSON: `json_decode($data, true, 512,
 *   \JSON_THROW_ON_ERROR)` is unguarded in all three providers alike (not an asymmetry), so a raw
 *   `\JsonException` reaches the caller instead of a `ModelException`. Through `Run`, the main
 *   loop's catch-all still classifies it `ErrorKind::ModelError` (the sensible default), so
 *   orchestrated turns degrade gracefully; a caller using a provider directly and catching
 *   `ModelException` specifically would be surprised, but this is consistent across the module and
 *   weaker than the next finding, which also runs through `Run` and gets the WRONG classification.
 * - A Responses stream with an `error` event mid-stream: hardcodes `retryAfter` to `null` exactly
 *   like Anthropic did before #59 (`case 'error': throw self::error(..., null, null);`) -- the
 *   same unfixed class of bug, just in the sibling provider; noted, not pinned as this round's one
 *   test since the next finding is a cleaner, more severe case through the very same code path.
 * - SSE ending without `[DONE]`/without a `finish_reason`: both already verified correct (`[DONE]`
 *   is only an optional early exit; `null` finish_reason at EOF already throws the documented error).
 *
 * - The one that reproduces: Anthropic's `stream()` never checks the response's `content-type`
 *   before handing its body straight to `Sse::parse()`, unlike `Mcp\HttpConnection`, which does
 *   check (`$type = strtolower($response->headers['content-type'] ?? '')`) before deciding whether
 *   to treat a body as SSE or JSON. A `200` response whose body is a plain JSON error object (no
 *   `data:`/blank-line framing at all) -- exactly what a buggy proxy or load balancer in front of
 *   the real API sends when it swallows the upstream failure -- yields nothing from `Sse::parse()`
 *   (no line in the body ever matches the SSE grammar), so the loop body never runs, and execution
 *   falls through to the generic `throw new ModelException(ErrorKind::TransportError, 'The stream
 *   ended before message_stop')`. The real error the server sent (here: an `overloaded_error`,
 *   which docs/anthropic.md says maps to `RateLimited`) is discarded entirely, and what reaches the
 *   host is both a worse message AND the wrong `ErrorKind` -- `TransportError` instead of
 *   `RateLimited` -- which would make a host's retry/backoff decision wrong, not just its logging.
 *
 * Verified directly against the real code (both adapters): a 200 response with
 * `content-type: application/json` whose body is `{"type":"error","error":{"type":
 * "overloaded_error","message":"The server is temporarily overloaded, please try again later"}}`
 * throws `ModelException(TransportError, 'The stream ended before message_stop')` -- not
 * `RateLimited` with the real message.
 */
it('reads a non-SSE JSON error body on a 200 response, not just non-200 statuses', function () {
    $body = \json_encode(['type' => 'error', 'error' => [
        'type'    => 'overloaded_error',
        'message' => 'The server is temporarily overloaded, please try again later',
    ]]);
    $model = new Messages(new FakeTransport(new Response(200, ['content-type' => 'application/json'], [$body])), 'k', 'claude-x');

    try {
        [...$model->stream(new Prompt(null, [], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        // Today: TransportError / "The stream ended before message_stop" -- the real
        // overloaded_error and its message are discarded because content-type is never checked.
        expect($e->kind)->toBe(ErrorKind::RateLimited)
            ->and($e->getMessage())->toContain('temporarily overloaded');
    }
}); // #65: Sse::parse() saw no events and the stream 'ended before message_stop'

/*
 * Round 13's attack on #65/#66/#67 and a sweep of the three providers' error paths:
 *
 * - `text/event-stream; charset=utf-8`: `Sse::isEventStream()` already splits on `;` and trims
 *   before comparing, so the parameter is ignored and this passes as a real event stream.
 *   Confirmed, not a defect.
 * - `application/x-ndjson` / `text/plain` with an SSE-shaped body: correctly takes the error path
 *   now (any non-`text/event-stream` content-type on a 200 is treated as an error body per #65) --
 *   right per docs, which never promised tolerance for a non-standard content-type on a streaming
 *   endpoint.
 * - A non-JSON (HTML) error body: the message quotes up to 2000 characters of it in all three
 *   providers (`substr($body/$text, 0, 2000)`) -- plain, working as intended; a host logging the
 *   exception sees enough of the page to diagnose a misconfigured proxy, not a defect.
 * - 200 JSON error body classification, `insufficient_quota`/`rate_limit_exceeded`/
 *   `context_length_exceeded`: `Responses::error()` takes the body's own `error.code` and checks
 *   `'rate_limit_exceeded' === $code` and `'context_length_exceeded' === $code` *regardless* of
 *   `$status` -- so it still correctly reports `RateLimited`/`ContextTooLong` even when the error
 *   arrived on a 200 (a proxy that normalizes every upstream failure to 200, which is exactly how
 *   this whole error path is reached here). `ChatCompletions::error()`, in the very same module,
 *   has no such code-based fallback at all: its match is `429 === $status` for `RateLimited` and a
 *   handful of *substring* checks on the raw body text for `ContextTooLong`, with nothing
 *   equivalent for rate limiting. The identical JSON body, `{"error":{"code":
 *   "rate_limit_exceeded",...}}`, delivered on the identical 200-with-wrong-content-type shape,
 *   is correctly classified `RateLimited` by `Responses` and wrongly classified the generic
 *   `ModelError` by `ChatCompletions` -- an asymmetry between the two OpenAI providers in this same
 *   module, the same bug *shape* as #65/#66/#67 (one provider's error path handles a case the
 *   sibling provider's does not) but in the classification logic rather than the content-type or
 *   retry-after plumbing those fixed.
 *
 * MCP, not pursued as the defect (checked, correct): a tool whose `inputSchema` is missing or not
 * an object -- `McpServer::spec()` falls back to `['type' => 'object']` only when `inputSchema` is
 * entirely absent; a present-but-non-object schema is passed through as-is, which is the server's
 * own spec violation, already covered by the same reasoning as #14-era conclusions about doing no
 * better than the server's own contract allows. A tool name with characters a provider rejects
 * (`search.web`, or 70 characters): `McpServer::spec()` already sanitizes via
 * `preg_replace('/[^a-zA-Z0-9_-]/', '_', ...)` and caps at `substr(..., 0, 64)` *before* any request
 * is built, matching OpenAI's `^[a-zA-Z0-9_-]{1,64}$` -- refused (rewritten) before the request,
 * never a provider 400 after. Not a defect.
 *
 * Verified directly against the real code (both adapters): the identical 200 response with
 * `content-type: application/json` and body `{"error":{"code":"rate_limit_exceeded","message":
 * "Rate limit reached, please slow down"}}` is `ErrorKind::RateLimited` through `Responses` and
 * `ErrorKind::ModelError` through `ChatCompletions`.
 */
it('classifies a rate-limit error code the same way Chat Completions and Responses do', function () {
    $body  = \json_encode(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached, please slow down']]);
    $model = new ChatCompletions(new FakeTransport(new Response(200, ['content-type' => 'application/json'], [$body])), 'http://h/v1', 'm');

    try {
        [...$model->stream(new Prompt(null, [], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        // Today: ModelError -- ChatCompletions::error() never looks at the body's own error code,
        // unlike Responses::error() in the same module, which correctly reports RateLimited here.
        expect($e->kind)->toBe(ErrorKind::RateLimited);
    }
}); // #74: ModelError; only HTTP 429 was looked at

/*
 * Round 14, request-side sweep:
 *
 * - System prompt AND `provider['anthropic']['system']` both set: `Messages::request()` sets
 *   `$request['system']` from `$prompt->system` first, then `array_replace($request,
 *   $o->provider['anthropic'] ?? [])` last -- the provider override wins, exactly as
 *   docs/anthropic.md says ("merged into the request body last"). Not a defect.
 * - `Options::$maxOutputTokens` 0 or negative: executed -- `Options`'s own constructor already
 *   throws `InvalidArgumentException` ("maxOutputTokens must be at least 1") before any `Prompt`
 *   can even be built, let alone a request sent. Already covered, not a gap.
 * - `toolChoice::Tool` naming a tool absent from the prompt's tools: traced all three providers --
 *   none check `$o->tool` against `$prompt->tools`' names (only that `$prompt->tools` is
 *   non-empty); this does reach the provider as a 400. A real, narrower gap, but the one below is
 *   a documented promise being broken outright, not merely an unvalidated combination -- reported
 *   here for the record, not pinned as this round's test.
 * - A `ToolSpec` with an empty/space-containing name reaching Anthropic directly (bypassing MCP's
 *   own sanitizing): confirmed via `ToolSpec`'s constructor is unguarded and `Messages::request()`
 *   sends `$t->name` verbatim -- a provider 400 after, not caught before. Same class as the
 *   toolChoice gap above; also not pinned, for the same reason.
 * - Two tools with the same name in one `Tools` collection: `Tools::__construct()`
 *   (`Harness\Tools`, not this module) does no deduplication; a later duplicate silently shadows
 *   the earlier one wherever `Run` builds its name-keyed tool map. Out of this module's charter
 *   (src/Tools.php, src/Run.php), noted but not this module's defect to fix.
 *
 * - The one that reproduces, and ties to `Options`'s own stated contract ("A provider throws
 *   `UnsupportedOptionException` for a portable option it cannot honour", Options.php's own
 *   docblock): `Options` validates `maxOutputTokens`, `contextWindow` and `toolRetries` in its
 *   constructor, but not `temperature` at all -- there is no local range check anywhere, and since
 *   the valid range differs per provider (Anthropic 0-1, OpenAI 0-2), it cannot live in the
 *   provider-agnostic `Options` class either; it has to be each provider's own job, exactly like
 *   the existing `toolChoice`-needs-tools check each provider already throws
 *   `UnsupportedOptionException` for. None of the three providers do this for `temperature`: a
 *   wildly out-of-range value (`5.0`, outside both providers' real ranges) is written straight into
 *   the request body and actually sent to the transport, producing a provider 400 the model cannot
 *   fix (it is a host misconfiguration, not something in the conversation), instead of the local,
 *   actionable `UnsupportedOptionException` this module's own stated contract promises for an
 *   option a provider cannot honour.
 *
 * Verified directly against the real code (both adapters): `Options(temperature: 5.0)` streamed
 * through `Anthropic\Messages` reaches `$this->transport->post()` -- a real POST attempt, not a
 * local exception -- confirmed by `FakeTransport` throwing its own "no response left" error only
 * after recording that request.
 */
it('refuses an out-of-range temperature before the request, not after a provider 400', function () {
    $t     = new FakeTransport();
    $model = new Messages($t, 'k', 'claude-x');

    // Today: no local exception at all -- the request is actually built and sent to the
    // transport (FakeTransport records it before complaining it has no response left).
    expect(fn () => [...$model->stream(new Prompt(null, [], [], new Options(temperature: 5.0), live()))])
        ->toThrow(UnsupportedOptionException::class)
        ->and($t->requests)->toBe([]);
}); // #76: sent as-is, a provider 400 the model cannot fix

it('refuses a ToolChoice::Tool naming a tool the prompt does not carry, before the request', function () {
    $t     = new FakeTransport();
    $model = new Messages($t, 'k', 'claude-x');
    $tools = [new ToolSpec('search', 'Search', ['type' => 'object'])];

    expect(fn () => [...$model->stream(new Prompt(null, [], $tools, new Options(toolChoice: ToolChoice::Tool, tool: 'fetch'), live()))])
        ->toThrow(UnsupportedOptionException::class, "names 'fetch'")
        ->and($t->requests)->toBe([]);
}); // #77: sent as-is, a provider 400 the model cannot fix
