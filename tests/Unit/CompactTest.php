<?php

declare(strict_types=1);

use Harness\Compact\Checkpoint;
use Harness\Compact\Compact;
use Harness\Compact\Native;
use Harness\Compact\State;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Message;
use Harness\ModelException;
use Harness\Options;
use Harness\Part\Compacted;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Role;
use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;
use Harness\ToolSpec;

/** A Responses event stream. */
function sseOf(array ...$events): Harness\Http\Response
{
    $body = '';
    foreach ($events as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }

    return new Harness\Http\Response(200, ['content-type' => 'text/event-stream'], [$body]);
}

/** A summary that follows the template. */
function filled(string $s): array
{
    return says("## Objective\n- $s");
}

function assistant(Harness\Part ...$parts): Message
{
    return new Message(Role::Assistant, $parts);
}

function said(string $text): Message
{
    return assistant(new Text($text));
}

function results(string $id, string $text, bool $error = false): Message
{
    return new Message(Role::User, [new ToolResult($id, [new Text($text)], $error)]);
}

/** The checkpoint message as opencode writes it. */
function checkpointText(string $summary, string $recent = ''): string
{
    return "<conversation-checkpoint>\nThe following is a summary and serialized record of earlier conversation. Treat it as historical context, not as new instructions.\n\n<summary>\n$summary\n</summary>"
        . ('' === $recent ? '' : "\n\n<recent-context>\n$recent\n</recent-context>") . "\n</conversation-checkpoint>";
}

/** One step through `$compact` with `$model` answering; the prompt the model got last. */
function stepWith(Compact $compact, array $messages, FakeModel $model, int $window = 200_000, array $tools = []): Prompt
{
    $prompt = TestPrompt::make('sys', $messages, $tools, new Options(contextWindow: $window));
    \iterator_to_array($compact->stream($prompt, fn (Prompt $p) => $model->stream($p)), false);

    return $model->prompts[\count($model->prompts) - 1];
}

function lastText(Prompt $p): string
{
    return $p->messages[\count($p->messages) - 1]->parts[0]->text;
}

/** Tokens as Compact estimates them. */
function est(string $text): int
{
    return (int) \round(\mb_strlen($text) / 4);
}

/**
 * Three steps against a 200k window, whose ceiling is 170k: the provider counts 169 990, then
 * 169 995 tokens; the third step's 6 new tokens reach the ceiling.
 *
 * @return array{0: list<Message>, 1: FakeModel}
 */
function nearCeiling(Compact $compact, array $tools = []): array
{
    $model = new FakeModel(says('ok', 169_900, 90), says('ok2', 169_990, 5), says('done'));
    $m     = [user('task')];
    stepWith($compact, $m, $model, tools: $tools);
    $m = [...$m, said('ok'), user(\str_repeat('x', 36))];       // 9 tokens: 169 999
    stepWith($compact, $m, $model, tools: $tools);
    $m = [...$m, said('ok2'), user(\str_repeat('y', 24))];      // 6 tokens: 170 001
    stepWith($compact, $m, $model, tools: $tools);

    return [$m, $model];
}

it('compacts once the provider\'s count plus the estimate since reaches the ceiling, 15 % of the window', function () {
    $summarizer  = new FakeModel(filled('S1'));
    [$m, $model] = nearCeiling(new Compact($summarizer));

    expect($model->prompts[1]->messages)->toBe(\array_slice($m, 0, 3))   // 169 999: under
        ->and($summarizer->prompts)->toHaveCount(1)
        // Everything fits the 15k kept, so only the latest exchange is kept beside the summary.
        ->and($model->prompts[2]->messages)->toHaveCount(1)
        ->and(lastText($model->prompts[2]))->toBe(checkpointText("## Objective\n- S1", '[User]: ' . \str_repeat('y', 24)));
});

it('sends the summarizer the whole conversation unchanged with the tools, then the summary prompt, its output fitted to the window', function () {
    $summarizer = new FakeModel(filled('S1'));
    $spec       = new ToolSpec('read', 'Reads', ['type' => 'object']);
    [$m]        = nearCeiling(new Compact($summarizer), [$spec]);

    $asked  = $summarizer->prompts[0];
    $prompt = lastText($asked);
    expect($asked->system)->toBe('sys')
        ->and($asked->tools)->toBe([$spec])
        ->and(\array_slice($asked->messages, 0, -1))->toBe($m)
        ->and($prompt)->toStartWith("You MUST summarize the conversation above into a structured summary that will be given to another agent to resume the work.\n\nSummarize only what the user and the assistant said and did.")
        ->and($prompt)->toContain("## Objective\n- [one or two brief sentences")->toContain("### Completed\n")->toContain('## Relevant Files')->toContain('Preserve exact file paths, symbols, commands, error strings, URLs, and identifiers.')
        ->and($prompt)->toEndWith("Do not continue the task or call tools.\n\nReturn only the structured summary in the requested format. Do not include a preamble, explanation, or other commentary.")
        // The window less the measured prompt and the estimated rest, the summary prompt included.
        ->and($asked->options->maxOutputTokens)->toBe(200_000 - 170_001 - est($prompt))
        ->and($asked->options->contextWindow)->toBe(200_000);
});

it('sends the summarizer the history before the kept messages when the window leaves no margin past the ceiling', function () {
    // At 100k, 15 % is under 16k: the ceiling equals the room for the summary, so the unchanged
    // conversation never fits beside it.
    $summarizer = new FakeModel(filled('S1'));
    $model      = new FakeModel(says('ok', 83_900, 90), says('done'));
    $compact    = new Compact($summarizer);
    stepWith($compact, [user('task')], $model, 100_000);
    $m = [user('task'), said('ok'), user(\str_repeat('x', 40))];   // 83 990 + 10
    stepWith($compact, $m, $model, 100_000);

    expect(\array_slice($summarizer->prompts[0]->messages, 0, -1))->toBe(\array_slice($m, 0, 2))
        ->and(lastText($model->prompts[1]))->toBe(checkpointText("## Objective\n- S1", '[User]: ' . \str_repeat('x', 40)));
});

it('keeps the newest messages within keep, from a user message on, as a record with each call\'s result', function () {
    $summarizer = new FakeModel(filled('S1'));
    $model      = new FakeModel(says('ok'));
    $compact    = new Compact($summarizer, keep: 20);
    $compact->compactNext();
    $m = [
        user('task'),
        assistant(new ToolCall('c1', 'read', ['n' => 1])), results('c1', \str_repeat('r', 400)),
        said('found it'),
        user('next q'),
        assistant(new ToolCall('c2', 'read', ['n' => 2])), results('c2', \str_repeat('z', 1300)),
    ];
    $prompt = stepWith($compact, $m, $model);

    // The newest entry alone is over 20 tokens; it is kept with its user message.
    expect(lastText($prompt))->toBe(checkpointText("## Objective\n- S1", "[User]: next q\n\n[Assistant tool call]: read({\"n\":2})\n[Tool result]: " . \str_repeat('z', 1250) . "\n[truncated]"))
        ->and($compact->last()->reason)->toBe('manual')
        ->and($compact->last()->covered)->toBe(7)
        ->and($compact->last()->native)->toBeFalse();
});

it('keeps nothing beside the summary of a single exchange', function () {
    $compact = new Compact(new FakeModel(filled('S1')));
    $compact->compactNext();
    $prompt = stepWith($compact, [user('task'), said('working'), results('x', 'y')], new FakeModel(says('ok')));

    expect($prompt->messages)->toHaveCount(1)
        ->and(lastText($prompt))->toBe(checkpointText("## Objective\n- S1"));
});

it('sends the checkpoint in place of what it covers, and updates it in the next compaction', function () {
    $summarizer      = new FakeModel(filled('S1'), filled('S2'));
    $compact         = new Compact($summarizer);
    [$m, $model]     = nearCeiling($compact);
    $model2          = new FakeModel(says('ok'), says('ok'));
    $m               = [...$m, said('done'), user('more')];
    $prompt          = stepWith($compact, $m, $model2);
    $checkpoint      = $prompt->messages[0];
    expect(\array_slice($prompt->messages, 1))->toBe(\array_slice($m, 5))
        ->and($summarizer->prompts)->toHaveCount(1);

    $compact->compactNext();
    $m      = [...$m, said('ok'), user('again')];
    $prompt = stepWith($compact, $m, $model2);
    $asked  = $summarizer->prompts[1];
    expect($asked->messages[0])->toEqual($checkpoint)
        ->and(\array_slice($asked->messages, 1, -1))->toBe(\array_slice($m, 5))
        ->and(lastText($asked))->toStartWith('Update the existing checkpoint in the conversation above into one consolidated summary.')
        ->and(lastText($asked))->toContain('Do not reproduce the `<conversation-checkpoint>`, `<summary>`, or `<recent-context>` wrapper tags')
        ->and($prompt->messages)->toHaveCount(1)
        ->and(lastText($prompt))->toBe(checkpointText("## Objective\n- S2", '[User]: again'));
});

it('reminds the summarizer of the template once, then fails', function (array $first, array $second, ?string $error) {
    $summarizer = new FakeModel($first, $second);
    $compact    = new Compact($summarizer);
    $compact->compactNext();
    $run = fn () => stepWith($compact, [user('task'), said('ok'), user('more')], new FakeModel(says('ok')));
    if (null !== $error) {
        expect($run)->toThrow(RuntimeException::class, $error);
    } else {
        expect(lastText($run()))->toContain("## Objective\n- S2");
    }
    expect(\array_map(fn (Message $m) => $m->parts[0]->text, \array_slice($summarizer->prompts[1]->messages, -1)))
        ->toBe(['The previous response did not fill in the required summary template. Do not call tools. Return the summary as text using the exact section headings from the template.']);
})->with([
    'then fills it' => [says('Here you go'), filled('S2'), null],
    'skips it'      => [says('nope'), says('still nope'), 'Compaction summary did not match the required template'],
    'empty'         => [says(''), says(''), 'Compaction produced no summary'],
]);

it('fails a summary cut at the output limit', function () {
    $compact = new Compact(new FakeModel([new TextDelta('## Objective'), new Finish(FinishReason::Length)]));
    $compact->compactNext();

    expect(fn () => stepWith($compact, [user('task'), said('ok')], new FakeModel()))->toThrow(RuntimeException::class, 'Compaction summary reached the output token limit');
});

it('summarizes with the run\'s own model through the rest of the chain when no summarizer is given', function () {
    $model   = new FakeModel(filled('S1'), says('ok'));
    $compact = new Compact();
    $compact->compactNext();
    $prompt = stepWith($compact, [user('task'), said('ok'), user('more')], $model);

    expect($model->prompts)->toHaveCount(2)
        ->and(lastText($model->prompts[0]))->toStartWith('You MUST summarize')
        ->and(lastText($prompt))->toBe(checkpointText("## Objective\n- S1", '[User]: more'));
});

it('compacts a prompt the provider rejects as too long, as a transcript, and sends it once more', function () {
    $summarizer = new FakeModel(filled('S1'));
    $tooLong    = fn () => throw new ModelException(ErrorKind::ContextTooLong, 'too long');
    $model      = new FakeModel($tooLong, says('ok'));
    $spec       = new ToolSpec('read', 'Reads', ['type' => 'object']);
    $m          = [user('task'), assistant(new ToolCall('c1', 'read', ['n' => 1])), results('c1', 'r1'), said('ok'), user('more')];
    $prompt     = stepWith($compact = new Compact($summarizer), $m, $model, tools: [$spec]);

    $asked = $summarizer->prompts[0];
    expect($asked->tools)->toBe([])
        ->and($asked->messages)->toHaveCount(2)
        ->and($asked->messages[0]->parts[0]->text)->toBe("[User]: task\n[Assistant tool call]: read({\"n\":1})\n[Tool result]: r1\n[Assistant]: ok")
        ->and(lastText($prompt))->toBe(checkpointText("## Objective\n- S1", '[User]: more'))
        ->and($compact->last()->reason)->toBe('overflow');
});

it('throws a second rejection as too long', function () {
    $tooLong = fn () => throw new ModelException(ErrorKind::ContextTooLong, 'too long');
    $compact = new Compact(new FakeModel(filled('S1')));

    expect(fn () => stepWith($compact, [user('task'), said('ok'), user('more')], new FakeModel($tooLong, $tooLong)))->toThrow(ModelException::class, 'too long');
});

it('shortens the summary request after the summarizer rejects it as too long, oldest detail first, then gives up', function () {
    $tooLong    = fn () => throw new ModelException(ErrorKind::ContextTooLong, 'too long');
    $summarizer = new FakeModel($tooLong, filled('S1'));
    $compact    = new Compact($summarizer);
    $compact->compactNext();
    $m = [user(\str_repeat('a', 200)), said(\str_repeat('b', 200))];   // 100 tokens
    stepWith($compact, $m, new FakeModel(says('ok')));

    // Aimed at 85 tokens: only the user's words fit.
    expect($summarizer->prompts[1]->tools)->toBe([])
        ->and($summarizer->prompts[1]->messages[0]->parts[0]->text)->toBe('[User]: ' . \str_repeat('a', 200));

    // At 50 tokens even those do not, and the newest exchange is never left out.
    $compact = new Compact(new FakeModel($tooLong, $tooLong, $tooLong));
    $compact->compactNext();
    expect(fn () => stepWith($compact, $m, new FakeModel()))->toThrow(RuntimeException::class, 'The compaction request cannot be reduced further without losing the latest exchange or checkpoint');
});

it('omits the oldest exchanges from a transcript last, counting them in a note', function () {
    $tooLong    = fn () => throw new ModelException(ErrorKind::ContextTooLong, 'too long');
    $summarizer = new FakeModel($tooLong, filled('S1'));
    $compact    = new Compact($summarizer);
    $compact->compactNext();
    $m = [user(\str_repeat('a', 400)), said('x'), user(\str_repeat('b', 400)), said('y'), user('c')];
    stepWith($compact, $m, new FakeModel(says('ok')));

    // Aimed at 85 % of the rejected 201 tokens: both exchanges down to the user's words do not
    // fit, so the oldest is left out, while the newest stays at that level.
    expect($summarizer->prompts[1]->messages[0]->parts[0]->text)->toBe("[1 older exchange omitted]\n\n[User]: " . \str_repeat('b', 400));
});

it('never compacts on its own with auto off, not even a prompt rejected as too long, and compactNext() still does', function () {
    $huge  = [user(\str_repeat('x', 2_000_000))];
    $model = new FakeModel(says('ok'));
    $off   = new Compact(new FakeModel(filled('S1')), auto: false);
    stepWith($off, $huge, $model, 1000);
    expect($model->prompts[0]->messages)->toBe($huge);

    $tooLong = fn () => throw new ModelException(ErrorKind::ContextTooLong, 'too long');
    expect(fn () => stepWith($off, $huge, new FakeModel($tooLong), 1000))->toThrow(ModelException::class);

    $off->compactNext();
    expect(lastText(stepWith($off, [user('task'), said('ok')], new FakeModel(says('ok')), 1000)))->toBe(checkpointText("## Objective\n- S1"));
});

it('never compacts on its own when the options give no window', function () {
    $model   = new FakeModel(says('ok'));
    $compact = new Compact(new FakeModel());
    $prompt  = TestPrompt::make(null, [user(\str_repeat('x', 2_000_000))]);
    \iterator_to_array($compact->stream($prompt, fn (Prompt $p) => $model->stream($p)), false);

    expect($model->prompts[0]->messages)->toBe($prompt->messages);
});

it('keeps the buffer free below the window: a share or tokens; one that leaves no room is ignored', function (string|int $buffer, int $ceiling) {
    $summarizer = new FakeModel(filled('S1'));
    $compact    = new Compact($summarizer, buffer: $buffer);
    $model      = new FakeModel(says('ok', $ceiling - 10, 6), says('ok', $ceiling - 10, 8), says('ok'));
    stepWith($compact, [user('task')], $model, 100_000);
    stepWith($compact, [user('task'), said('ok'), user('xxxx')], $model, 100_000);         // 1 token: one under
    expect($summarizer->prompts)->toBe([]);
    stepWith($compact, [user('task'), said('ok'), user('xxxx'), said('ok'), user(\str_repeat('y', 8))], $model, 100_000);
    expect($summarizer->prompts)->toHaveCount(1);
})->with([
    '20 %'          => ['20%', 80_000],
    '5000 tokens'   => [5000, 95_000],
    'too large'     => [100_000, 84_000],
]);

it('rejects a buffer or keep it cannot use, and a summarizer beside a native compactor', function (array $args, string $error) {
    expect(fn () => new Compact(...$args))->toThrow(InvalidArgumentException::class, $error);
})->with([
    'percent over 99' => [['buffer' => '100%'], 'share of the window'],
    'no percent sign' => [['buffer' => '20'], 'share of the window'],
    'negative'        => [['buffer' => -1], 'share of the window'],
    'keep'            => [['keep' => -1], 'keep'],
    'both'            => [['summarizer' => new FakeModel(), 'native' => new class implements Native {
        public function checkpoint(Prompt $prompt): Compacted
        {
            throw new LogicException();
        }
    }], 'not both'],
]);

it('serves a checkpoint and the provider\'s count from a restored state, without compacting again', function () {
    $compact     = new Compact(new FakeModel(filled('S1')));
    [$m, $model] = nearCeiling($compact);
    $state       = State::fromArray(\json_decode(\json_encode($compact->state()), true));
    expect($state)->toEqual($compact->state())
        ->and($state->checkpoints)->toHaveCount(1)
        ->and($state->anchors[0]->checkpoint)->toBe($state->checkpoints[0]->hash);

    $model2 = new FakeModel(says('ok'));
    $prompt = stepWith(new Compact(new FakeModel(), state: $state), [...$m, said('done'), user('more')], $model2);
    expect(lastText($prompt))->toBe('more')
        ->and($prompt->messages[0]->parts[0]->text)->toBe(checkpointText("## Objective\n- S1", '[User]: ' . \str_repeat('y', 24)));
});

/** A native compactor that records its prompts and returns item n. */
function natively(): Native
{
    return new class implements Native {
        /** @var list<Prompt> */
        public array $prompts = [];

        public function checkpoint(Prompt $prompt): Compacted
        {
            $this->prompts[] = $prompt;

            return new Compacted('openai', 'gpt-x', 'cmp_' . \count($this->prompts), 'ENC' . \count($this->prompts));
        }
    };
}

it('compacts natively: the newest user messages within keep, then the provider\'s item', function () {
    $native  = natively();
    $compact = new Compact(keep: 3, native: $native);
    $compact->compactNext();
    $spec   = new ToolSpec('read', 'Reads', ['type' => 'object']);
    $m      = [user('first task'), said('ok'), user('more'), assistant(new ToolCall('c1', 'read', [])), new Message(Role::User, [new ToolResult('c1', [new Text('r')]), new Text('also')])];
    $prompt = stepWith($compact, $m, new FakeModel(says('ok')), tools: [$spec]);

    expect($native->prompts[0]->messages)->toBe($m)
        ->and($native->prompts[0]->tools)->toBe([$spec])
        // 'first task' is 3 tokens, over what 'more' and 'also' leave of 3.
        ->and($prompt->messages)->toEqual([user('more'), new Message(Role::User, [new Text('also')]), assistant(new Compacted('openai', 'gpt-x', 'cmp_1', 'ENC1'))])
        ->and($compact->last()->native)->toBeTrue();
});

it('waits for the provider\'s count after a native compaction before compacting again', function () {
    $window     = [user('task'), assistant(new Compacted('openai', 'gpt-x', 'cmp_1', 'ENC1'))];
    $m          = [user('task'), said('ok'), user(\str_repeat('x', 800_000))];
    $hash       = \md5(\implode('', \array_map(fn (Message $x) => \md5(\json_encode($x->toArray())), \array_slice($m, 0, 2))));
    $native     = natively();
    $compact    = new Compact(native: $native, state: new State([new Checkpoint($hash, 2, '', '', $window)]));
    $model      = new FakeModel(says('ok'));
    $prompt     = stepWith($compact, $m, $model, 100_000);

    expect($native->prompts)->toBe([])
        ->and($prompt->messages)->toEqual([...$window, $m[2]]);
});

it('replays a native compaction to the model that made it, and rejects it on another', function () {
    $item = new Compacted('openai', 'gpt-x', 'cmp_1', 'ENC1');
    expect(Harness\Parts::fromArray($item->toArray()))->toEqual($item);
    $t = new Harness\Testing\FakeTransport(sseOf(['type' => 'response.output_text.delta', 'item_id' => 'm', 'delta' => 'hi'], ['type' => 'response.completed', 'response' => ['status' => 'completed']]));
    \iterator_to_array((new Harness\Provider\OpenAI\Responses($t, 'https://api.example/v1', 'gpt-x'))->stream(TestPrompt::make(null, [user('a'), assistant($item), user('b')])), false);
    expect(\json_decode($t->requests[0]['body'], true)['input'][1])->toBe(['type' => 'compaction', 'id' => 'cmp_1', 'encrypted_content' => 'ENC1']);

    $other = new Harness\Provider\OpenAI\Responses(new Harness\Testing\FakeTransport(), 'https://api.example/v1', 'gpt-y');
    expect(fn () => \iterator_to_array($other->stream(TestPrompt::make(null, [user('a'), assistant($item)]))))->toThrow(Harness\UnsupportedContentException::class, 'replays only on that model');
});

it('asks the Responses API for a compaction with a trigger item and returns its one checkpoint', function () {
    $t = new Harness\Testing\FakeTransport(sseOf(
        ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'compaction', 'id' => 'cmp_9', 'encrypted_content' => 'SECRET']],
        ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'output' => [['type' => 'compaction', 'id' => 'cmp_9', 'encrypted_content' => 'SECRET']]]],
    ));
    $item = (new Harness\Provider\OpenAI\Responses($t, 'https://api.example/v1', 'gpt-x'))->checkpoint(TestPrompt::make('sys', [user('a'), said('b')]));

    $body = \json_decode($t->requests[0]['body'], true);
    expect($item)->toEqual(new Compacted('openai', 'gpt-x', 'cmp_9', 'SECRET'))
        ->and($t->requests[0]['url'])->toBe('https://api.example/v1/responses')
        ->and($body['input'][\count($body['input']) - 1])->toBe(['type' => 'compaction_trigger'])
        ->and($body['instructions'])->toBe('sys');

    $none = new Harness\Testing\FakeTransport(sseOf(['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]]));
    expect(fn () => (new Harness\Provider\OpenAI\Responses($none, 'https://api.example/v1', 'gpt-x'))->checkpoint(TestPrompt::make(null, [user('a')])))
        ->toThrow(ModelException::class, 'exactly one checkpoint');
});

it('compacts inside a Run with the run\'s own model, the summary never reaching the turn', function () {
    $model   = new FakeModel(says('no template'), filled('S1'), says('answer'));
    $compact = new Compact();
    $compact->compactNext();
    $run    = new Harness\Run($model, new Harness\Tools(), middleware: [$compact], messages: [user('task'), said('ok')]);
    $events = turn($run, user('more'));

    expect($run->ended()->reason)->toBe(Harness\Reason::Done)
        ->and(\array_slice($run->messages(), 2))->toEqual([user('more'), said('answer')])
        ->and($model->prompts[2]->messages)->toEqual([user(checkpointText("## Objective\n- S1", '[User]: more'))])
        ->and(\implode('', \array_map(fn ($e) => $e instanceof Harness\Event\TextDelta ? $e->text : '', $events)))->toBe('answer');
}); // #113
