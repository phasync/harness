<?php

declare(strict_types=1);

use Harness\Compact\Compact;
use Harness\Message;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Reason;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;
use Harness\Tools;
use Harness\ToolSpec;
use Psr\Log\AbstractLogger;

/** A counter that sees only the messages' JSON size, so tests set budgets in bytes. */
function bytesOf(Prompt $p): int
{
    return \strlen(\json_encode(\array_map(fn (Message $m) => $m->toArray(), $p->messages)));
}

/** A summarizer that answers every call with S1, S2, ... and records the prompts. */
final class Summaries implements Harness\Model
{
    /** @var list<Prompt> */
    public array $prompts = [];

    public function stream(Prompt $prompt): iterable
    {
        $this->prompts[] = $prompt;

        return says('S' . \count($this->prompts));
    }
}

function assistant(Harness\Part ...$parts): Message
{
    return new Message(Role::Assistant, $parts);
}

/** u0, then $pairs tool steps (each 300 bytes of result), then a closing reply and question. */
function agentic(int $pairs): array
{
    $m = [user('the task')];
    for ($i = 1; $i <= $pairs; ++$i) {
        $m[] = assistant(new Reasoning("thinking $i"), new ToolCall("c$i", 'read', ['n' => $i]));
        $m[] = new Message(Role::User, [new ToolResult("c$i", [new Text(\str_repeat((string) $i, 300))])]);
    }

    return $m;
}

/** Streams one step through the middleware and returns the prompt the model got. */
function step(Compact $compact, array $messages, ?string $system = 'sys', array $tools = []): Prompt
{
    $model = new FakeModel(says('ok'));
    \iterator_to_array($compact->stream(TestPrompt::make($system, $messages, $tools), fn (Prompt $p) => $model->stream($p)), false);

    return $model->prompts[0];
}

it('replaces the oldest half with a summary, cut where tool pairs stay together, the rest unchanged', function () {
    $summarizer = new Summaries();
    $compact    = new Compact($summarizer, 2000, bytesOf(...));
    $messages   = agentic(5);                     // 11 messages, about 2.3 KB
    $spec       = new ToolSpec('read', 'Reads', ['type' => 'object']);
    $prompt     = step($compact, $messages, 'sys', [$spec]);

    // After the first message, 10 remain; half is 5, so the cut would fall on message 6, a tool
    // result: it moves back to message 5, the call it answers.
    expect($prompt->messages)->toHaveCount(8)
        ->and($prompt->messages[0])->toBe($messages[0])
        ->and($prompt->messages[1]->role)->toBe(Role::User)
        ->and($prompt->messages[1]->parts[0]->text)->toEndWith("\n\nS1")
        ->and($summarizer->prompts)->toHaveCount(1)
        ->and(\array_slice($prompt->messages, 2))->toBe(\array_slice($messages, 5))
        ->and($prompt->system)->toBe('sys')
        ->and($prompt->tools)->toBe([$spec])
        ->and($compact->last())->toEqual(new Harness\Compact\Compaction(4, 6, 'S1', bytesOf(TestPrompt::make(null, $messages)), bytesOf($prompt), false));

    $asked = $summarizer->prompts[0];
    $text  = $asked->messages[0]->parts[0]->text;
    expect($asked->tools)->toBe([])
        ->and($asked->system)->toContain('Remaining')
        ->and($text)->not->toContain('the task')->toContain('[calls read {"n":2}]')->toContain(\str_repeat('2', 300))
        ->and($text)->not->toContain('[calls read {"n":3}]')
        ->and($text)->not->toContain('thinking');
});

it('reuses the summary while the conversation fits, and summarizes again with it when it does not', function () {
    $summarizer = new Summaries();
    $compact    = new Compact($summarizer, 2000, bytesOf(...));
    $messages   = agentic(5);
    $first      = step($compact, $messages);
    $summary    = $first->messages[1];

    // One more short exchange: no new summary, the same prefix objects.
    $messages[] = assistant(new Text('short'));
    $messages[] = user('more');
    $second     = step($compact, $messages);
    expect($summarizer->prompts)->toHaveCount(1)
        ->and($second->messages[1])->toBe($summary)
        ->and(\array_slice($second->messages, 2))->toBe(\array_slice($messages, 5))
        ->and($compact->last()->reused)->toBeTrue()
        ->and($compact->last()->removed)->toBe(4);

    // Much more: over the budget again, so the earlier summary is summarized with what follows.
    $messages = [...$messages, ...\array_slice(agentic(4), 1)];
    $third    = step($compact, $messages);
    $rounds   = \count($summarizer->prompts) - 1;
    expect($rounds)->toBeGreaterThanOrEqual(1)
        ->and($summarizer->prompts[1]->messages[0]->parts[0]->text)->toContain('S1')
        ->and($third->messages[0])->toBe($messages[0])
        ->and($third->messages[1]->parts[0]->text)->toEndWith('S' . ($rounds + 1))
        ->and($compact->last()->reused)->toBeFalse()
        ->and(bytesOf($third))->toBeLessThanOrEqual(2000)
        ->and(\array_slice($third->messages, -1)[0])->toBe(\array_slice($messages, -1)[0]);
});

it('never touches the system prompt or the first keepFirst messages, and keeps a tool result with its kept call', function () {
    $summarizer = new Summaries();
    $compact    = new Compact($summarizer, 1500, bytesOf(...), keepFirst: 2);
    $messages   = agentic(6);                     // message 1 calls c1, message 2 holds its result
    $prompt     = step($compact, $messages, 'the system');
    expect($prompt->system)->toBe('the system')
        ->and(\array_slice($prompt->messages, 0, 3))->toBe(\array_slice($messages, 0, 3))
        ->and($prompt->messages[3]->parts[0]->text)->toContain('[A summary of the earlier conversation')
        ->and(bytesOf($prompt))->toBeLessThanOrEqual(1500)
        ->and($summarizer->prompts[0]->messages[0]->parts[0]->text)->not->toContain(\str_repeat('1', 300));
});

it('adds an assistant acknowledgement when the kept part starts with a user message', function () {
    $messages = [user('u0'), assistant(new Text('a1')), user('u2'), assistant(new Text('a3')), user('u4'), assistant(new Text('a5')), user('u6')];
    // A budget the first round meets: the roles after the summary alternate.
    $prompt = step(new Compact(new Summaries(), 30, fn (Prompt $p) => 5 * \count($p->messages), keepFirst: 1), $messages);
    expect(\array_map(fn (Message $m) => $m->role, $prompt->messages))->toBe([Role::User, Role::User, Role::Assistant, Role::User, Role::Assistant, Role::User])
        ->and(\array_slice($prompt->messages, 3))->toBe(\array_slice($messages, 4));
});

it('passes a prompt within the budget on unchanged', function () {
    $compact = new Compact(new FakeModel(), 100000);
    $prompt  = TestPrompt::make('sys', agentic(3));
    $model   = new FakeModel(says('ok'));
    \iterator_to_array($compact->stream($prompt, fn (Prompt $p) => $model->stream($p)), false);
    expect($model->prompts[0])->toBe($prompt)
        ->and($compact->last())->toBeNull();
});

it('compacts inside a run: the model sees the summary, the run keeps every message, and the compaction is logged', function () {
    $log = new class extends AbstractLogger {
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [$level, $message, $context];
        }
    };
    $read  = tool('read', fn (array $in) => Harness\Result::text(\str_repeat('x', 400)), new Harness\Hints(readOnly: true));
    $model = new FakeModel(calls(['c1', 'read', []]), calls(['c2', 'read', []]), calls(['c3', 'read', []]), calls(['c4', 'read', []]), says('done'));
    $run   = new Run($model, new Tools($read), middleware: [new Compact($summaries = new Summaries(), 1200, bytesOf(...), log: $log)]);
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->messages())->toHaveCount(10)
        ->and($model->prompts[4]->messages[1]->parts[0]->text)->toEndWith('S' . \count($summaries->prompts))
        ->and(\count($model->prompts[4]->messages))->toBeLessThan(9)
        ->and($log->lines[0][0])->toBe('info')
        ->and($log->lines[0][2]['removed'])->toBeGreaterThan(0)
        ->and($log->lines[0][2]['bytes'])->toBe(2)
        ->and(\array_column($log->lines, 0))->toBe(\array_fill(0, \count($summaries->prompts), 'info'));
});

it('approximates tokens as bytes / 4 plus fixed costs', function () {
    $p = TestPrompt::make(\str_repeat('s', 40), [user(\str_repeat('u', 400)), new Message(Role::User, [new Harness\Part\Media('image/png', 'PNG')])], [new ToolSpec('t', 'd', ['type' => 'object'])]);
    // (40 + 400 + 1 + 1 + strlen('{"type":"object"}') = 17) / 4 = 114, + 1000 media + 2 * 4 + 10
    expect(Compact::approximate($p))->toBe(114 + 1000 + 8 + 10);
});

it('fails the step when the summarizer gives no text', function () {
    $compact = new Compact(new FakeModel([new Harness\Delta\Finish(Harness\FinishReason::Length)]), 10);
    expect(fn () => step($compact, agentic(3)))->toThrow(RuntimeException::class, 'the summarizer returned no text (finish: length)');
});

it('summarizes round after round until the prompt fits, and warns when nothing is left', function () {
    $log = new class extends AbstractLogger {
        public array $levels = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->levels[] = $level;
        }
    };
    $summaries = new Summaries();
    $prompt    = step(new Compact($summaries, 1, bytesOf(...), log: $log), agentic(4));
    // Nothing fits 1 token: it summarizes until only the first message, a summary and the last pair remain.
    expect(\count($summaries->prompts))->toBeGreaterThan(1)
        ->and(\array_slice($log->levels, -1))->toBe(['warning'])
        ->and($prompt->messages)->toHaveCount(4);
});
