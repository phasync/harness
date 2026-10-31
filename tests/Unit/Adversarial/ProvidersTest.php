<?php

declare(strict_types=1);

use Harness\Accumulator;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\FinishReason;
use Harness\Http\Response;
use Harness\Message;
use Harness\ModelException;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\ToolSpec;
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
