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
function step(Compact $compact, array $messages, ?string $system = 'sys', array $tools = [], int $reported = 0): Prompt
{
    $model = new FakeModel(says('ok', $reported));
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
        ->and($compact->last())->toEqual(new Harness\Compact\Compaction(4, 6, 'S1', bytesOf(TestPrompt::make(null, $messages)), bytesOf($prompt), false, finish: Harness\FinishReason::Stop));

    $asked = $summarizer->prompts[0];
    $text  = $asked->messages[0]->parts[0]->text;
    expect($asked->tools)->toBe([])
        ->and($asked->system)->toContain('Remaining')
        ->and($text)->toStartWith("The agent's instructions (context only, do not summarize them):\n\nsys\n\nThe start of the conversation, which the agent keeps in full (context only, do not summarize it):\n\nUSER:\nthe task\n\nThe part to summarize")
        ->and($text)->toContain('[calls read {"n":2}]')->toContain(\str_repeat('2', 300))
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
        // The kept call and its result are context for the summarizer, not part of what it summarizes.
        ->and(\explode('The part to summarize', $summarizer->prompts[0]->messages[0]->parts[0]->text)[1])->not->toContain(\str_repeat('1', 300));
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
    $read = tool('read', fn (array $in) => Harness\Result::text(\str_repeat('x', 400)), new Harness\Hints(readOnly: true));
    // No usage reported, so the counter is not calibrated.
    $quiet = fn (array $deltas) => \array_values(\array_filter($deltas, fn ($d) => !$d instanceof Harness\Usage));
    $model = new FakeModel($quiet(calls(['c1', 'read', []])), $quiet(calls(['c2', 'read', []])), $quiet(calls(['c3', 'read', []])), $quiet(calls(['c4', 'read', []])), $quiet(says('done')));
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

it('asks once more when the summarizer gives no text, and fails the step the second time', function () {
    $empty   = [new Harness\Delta\Finish(Harness\FinishReason::Length)];
    $compact = new Compact($summarizer = new FakeModel($empty, $empty), 100);
    expect(fn () => step($compact, agentic(3)))->toThrow(RuntimeException::class, 'the summarizer returned no text (finish: length), twice');
    expect($summarizer->prompts)->toHaveCount(2)
        ->and($summarizer->prompts[1])->toBe($summarizer->prompts[0]);
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
    $prompt    = step(new Compact($summaries, 200, bytesOf(...), log: $log), agentic(4));
    // The last pair alone is over 200: it summarizes until only the first message, a summary and the last pair remain.
    expect(\count($summaries->prompts))->toBeGreaterThan(1)
        ->and(\array_slice($log->levels, -1))->toBe(['warning'])
        ->and($prompt->messages)->toHaveCount(4);
});

/** A logger that keeps [level, message with its placeholders filled]. */
function lines(): AbstractLogger
{
    return new class extends AbstractLogger {
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [$level, \preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($context[$m[1]] ?? $m[0]), (string) $message)];
        }
    };
}

it('calls the summarizer with its own options, never the step\'s', function () {
    $summaries = new Summaries();
    $compact   = new Compact($summaries, 2000, bytesOf(...));
    $model     = new FakeModel(says('ok', 0));
    $step      = new Harness\Options(maxOutputTokens: 50, provider: ['openai' => ['reasoning_effort' => 'high']]);
    \iterator_to_array($compact->stream(new Prompt('sys', agentic(5), [], $step, live()), fn (Prompt $p) => $model->stream($p)), false);
    expect($summaries->prompts[0]->options)->toEqual(new Harness\Options(maxOutputTokens: 1024))
        ->and($model->prompts[0]->options)->toBe($step);

    $own       = new Harness\Options(maxOutputTokens: 300, provider: ['openai' => ['chat_template_kwargs' => ['enable_thinking' => false]]]);
    $summaries = new Summaries();
    step(new Compact($summaries, 2000, bytesOf(...), summarizerOptions: $own), agentic(5));
    expect($summaries->prompts[0]->options)->toBe($own);
});

it('asks once more for a summary under a quarter of the one it replaces, and keeps the second', function () {
    $log        = lines();
    $summarizer = new FakeModel(says(\str_repeat('a', 400)), says('tiny'), says(\str_repeat('b', 120)));
    $compact    = new Compact($summarizer, 3200, bytesOf(...), log: $log);
    $messages   = agentic(6);
    step($compact, $messages);
    $messages = [...$messages, ...\array_slice(agentic(2), 1)];
    $prompt   = step($compact, $messages);
    expect($summarizer->prompts)->toHaveCount(3)
        ->and($summarizer->prompts[2])->toBe($summarizer->prompts[1])
        ->and($prompt->messages[1]->parts[0]->text)->toEndWith(\str_repeat('b', 120))
        ->and($compact->last()->previousSummaryBytes)->toBe(400)
        ->and($log->lines)->toContain(['warning', 'Compact: the summarizer collapsed the summary to 4 bytes from the 400-byte summary it replaces, under a quarter (finish: stop); asking once more']);
});

it('fails the step on a second collapsed summary, naming both sizes', function () {
    $summarizer = new FakeModel(says(\str_repeat('a', 1093)), says(\str_repeat('c', 25)), says(\str_repeat('c', 25)));
    $compact    = new Compact($summarizer, 3200, bytesOf(...));
    $messages   = agentic(6);
    step($compact, $messages);
    expect($compact->last()->summary)->toHaveLength(1093);
    expect(fn () => step($compact, [...$messages, ...\array_slice(agentic(2), 1)]))
        ->toThrow(RuntimeException::class, 'collapsed the summary to 25 bytes from the 1,093-byte summary it replaces, under a quarter (finish: stop), twice');
});

it('throws at first use when the fixed part of the prompt is over half the budget', function () {
    $spec    = new ToolSpec('read', \str_repeat('Reads a file. ', 40), ['type' => 'object']);
    $compact = new Compact(new Summaries(), 400, keepFirst: 1);
    // 'sys' + the spec + the first message: (3 + 4 + 560 + 17 + 8) / 4 = 148, + 10 + 4 = 162 tokens; fine.
    step($compact, agentic(0), 'sys', [$spec]);
    $tight = new Compact(new Summaries(), 300);
    expect(fn () => step($tight, agentic(5), 'sys', [$spec]))
        ->toThrow(LogicException::class, 'Compact: the fixed part of the prompt (system text, tool specs and the first 1 messages) counts 162 tokens, over half the budget of 300: the budget must leave room above it for a summary and the recent half. Raise budgetTokens to 648 or more');

    // Compared calibrated once there is a line: twice the count, 324 is over half of 400. One report
    // is not enough: the difference of a long prompt may belong to the rest of it.
    $larger = [user('the task'), assistant(new Text('Which file?')), user(\str_repeat('a.txt ', 120))];
    step($compact, $larger, 'sys', [$spec], reported: 2 * Compact::approximate(TestPrompt::make('sys', $larger, [$spec])));
    step($compact, agentic(0), 'sys', [$spec], reported: 2 * Compact::approximate(TestPrompt::make('sys', agentic(0), [$spec])));
    expect(fn () => step($compact, agentic(0), 'sys', [$spec]))
        ->toThrow(LogicException::class, 'counts 324 tokens (counter ratio 2, offset 0), over half the budget of 400');
});

it('calibrates its count by the input tokens the provider reports, and logs the calibration', function () {
    $log       = lines();
    $summaries = new Summaries();
    $compact   = new Compact($summaries, 2000, bytesOf(...), log: $log);
    $first     = [user('the task')];
    step($compact, $first, reported: 2 * bytesOf(TestPrompt::make('sys', $first)));
    expect($compact->last())->toBeNull();

    // A second report, of a larger prompt, also twice its count: the ratio is 2. Twice the count of
    // the next prompt is over the budget, so it compacts, though the counter alone says it fits.
    $messages = agentic(3);
    step($compact, $messages, reported: 2 * bytesOf(TestPrompt::make('sys', $messages)));
    $messages[] = assistant(new Text('short'));
    $messages[] = user('more');
    expect(bytesOf(TestPrompt::make('sys', $messages)))->toBeLessThan(2000);
    step($compact, $messages);
    expect($summaries->prompts)->toHaveCount(1)
        ->and($compact->last()->ratio)->toBe(2.0)
        ->and($compact->last()->offset)->toBe(0.0)
        ->and($compact->last()->before)->toBe(2 * bytesOf(TestPrompt::make('sys', $messages)))
        ->and($compact->last()->after)->toBeLessThanOrEqual(2000)
        ->and($log->lines[0][1])->toEndWith('(budget 2000 tokens, counter ratio 2, offset 0)');

    // A step without a report (the one above) leaves the line, and so does a report of a prompt
    // between the smallest and the largest reported.
    step($compact, $messages, reported: 1);
    step($compact, $messages);
    expect($compact->last()->ratio)->toBe(2.0)
        ->and($compact->last()->offset)->toBe(0.0);
});

it('takes a slope only from prompts far enough apart: until then, the offset alone', function () {
    // Two close prompts, the second reporting 50 tokens more than the first: a line through them
    // would have a ratio of 3 and count the next, larger prompt three times over.
    $compact = new Compact(new Summaries(), 2000, bytesOf(...));
    $a       = agentic(1);
    $b       = [...$a, assistant(new Text('ok')), user('go on')];
    $ca      = bytesOf(TestPrompt::make('sys', $a));
    $cb      = bytesOf(TestPrompt::make('sys', $b));
    step($compact, $a, reported: $ca + 300);
    step($compact, $b, reported: $cb + 300 + 2 * ($cb - $ca));
    $messages = agentic(5);
    step($compact, $messages);
    expect($compact->last()->ratio)->toBe(1.0)
        ->and($compact->last()->offset)->toBe((float) (300 + 2 * ($cb - $ca)))
        ->and($compact->last()->before)->toBe(bytesOf(TestPrompt::make('sys', $messages)) + 300 + 2 * ($cb - $ca));
});

it('takes a fixed preamble in the reports as an offset, not a ratio', function () {
    // The template adds 300 tokens to every prompt: a small first prompt reports several times its
    // count, and a ratio from it would count the next, larger prompt far over the budget.
    $summaries = new Summaries();
    $compact   = new Compact($summaries, 2100, bytesOf(...));
    $first     = [user('the task')];
    step($compact, $first, reported: bytesOf(TestPrompt::make('sys', $first)) + 300);
    $messages = agentic(3);                         // about 1.7 KB: 2.0 K with the preamble
    expect(bytesOf(TestPrompt::make('sys', $messages)) + 300)->toBeLessThan(2100);
    expect(step($compact, $messages, reported: bytesOf(TestPrompt::make('sys', $messages)) + 300)->messages)->toBe($messages)
        ->and($summaries->prompts)->toBe([]);

    // Two reports: the line through them has ratio 1 and offset 300, and the next step uses it.
    $messages = agentic(4);
    step($compact, $messages);
    expect($compact->last()->ratio)->toBe(1.0)
        ->and($compact->last()->offset)->toBe(300.0)
        ->and($compact->last()->before)->toBe(bytesOf(TestPrompt::make('sys', $messages)) + 300);
});

it('serves several conversations from one instance, each its own summary, without recomputing', function () {
    $summaries = new Summaries();
    $compact   = new Compact($summaries, 2000, bytesOf(...));
    $root      = user('the task');               // the template's first message, shared by every fork
    $fork      = function (string $who) use ($root): array {
        $m    = agentic(5);
        $m[0] = $root;
        foreach ($m as $i => $message) {
            if (Role::User === $message->role && $message->parts[0] instanceof ToolResult) {
                $m[$i] = new Message(Role::User, [new ToolResult($message->parts[0]->callId, [new Text("$who: " . $message->parts[0]->content[0]->text)])]);
            }
        }

        return $m;
    };
    $a   = $fork('a');
    $b   = $fork('b');
    $pa  = step($compact, $a);
    $pb  = step($compact, $b);
    $a[] = assistant(new Text('a goes on'));
    $b[] = assistant(new Text('b goes on'));
    $a[] = user('more a');
    $b[] = user('more b');
    $qa  = step($compact, $a);
    $qb  = step($compact, $b);
    expect($summaries->prompts)->toHaveCount(2)
        ->and($summaries->prompts[0]->messages[0]->parts[0]->text)->toContain('a: ')
        ->and($summaries->prompts[1]->messages[0]->parts[0]->text)->toContain('b: ')
        ->and($qa->messages[1])->toBe($pa->messages[1])
        ->and($qb->messages[1])->toBe($pb->messages[1])
        ->and($qa->messages[1]->parts[0]->text)->toEndWith('S1')
        ->and($qb->messages[1]->parts[0]->text)->toEndWith('S2')
        ->and($compact->last()->reused)->toBeTrue();
});

it('keeps the last 8 summaries: a ninth conversation evicts the oldest', function () {
    $summaries     = new Summaries();
    $compact       = new Compact($summaries, 2000, bytesOf(...));
    $conversations = [];
    for ($i = 0; $i < 9; ++$i) {
        step($compact, $conversations[$i] = agentic(5));
    }
    foreach ([8, 1, 0] as $i) {             // 8 and 1 are kept; 0 was evicted by 8
        $conversations[$i][] = assistant(new Text('on'));
        step($compact, $conversations[$i]);
    }
    expect($summaries->prompts)->toHaveCount(10);
});

it('compacts two conversations at once on one instance, each with its own summary', function () {
    // The summarizer waits, so both steps are inside compact() together.
    $summarizer = new FakeModel(...\array_fill(0, 2, function (Prompt $p) {
        \fiber\sleep(0.01);

        return says(\str_contains($p->messages[0]->parts[0]->text, 'from a') ? 'summary of a' : 'summary of b');
    }));
    $compact   = new Compact($summarizer, 2000, bytesOf(...));
    $a         = agentic(5);
    $b         = agentic(5);
    $a[2]      = new Message(Role::User, [new ToolResult('c1', [new Text('from a ' . \str_repeat('1', 300))])]);
    $b[2]      = new Message(Role::User, [new ToolResult('c1', [new Text('from b ' . \str_repeat('1', 300))])]);
    [$pa, $pb] = \fiber\run(fn () => \fiber\await_all([\fiber\go(fn () => step($compact, $a)), \fiber\go(fn () => step($compact, $b))]));
    expect($pa->messages[1]->parts[0]->text)->toEndWith('summary of a')
        ->and($pb->messages[1]->parts[0]->text)->toEndWith('summary of b');
    // Each conversation's next step reuses its own.
    $a[] = assistant(new Text('on'));
    $b[] = assistant(new Text('on'));
    expect(step($compact, $a)->messages[1])->toBe($pa->messages[1])
        ->and(step($compact, $b)->messages[1])->toBe($pb->messages[1]);
});

it('takes a summary cut at the output token limit as a collapse: asked once more, and fatal twice', function () {
    $cut     = [new Harness\Delta\TextDelta('1. Asked: the ta'), new Harness\Delta\Finish(Harness\FinishReason::Length)];
    $compact = new Compact($summarizer = new FakeModel($cut, says('a whole summary')), 2000, bytesOf(...), log: $log = lines());
    $prompt  = step($compact, agentic(5));
    expect($summarizer->prompts)->toHaveCount(2)
        ->and($prompt->messages[1]->parts[0]->text)->toEndWith('a whole summary')
        ->and($compact->last()->finish)->toBe(Harness\FinishReason::Stop)
        ->and($log->lines)->toContain(['warning', 'Compact: the summarizer was cut at its output token limit after 16 bytes (finish: length); asking once more']);

    $twice = new Compact(new FakeModel($cut, $cut), 2000, bytesOf(...));
    expect(fn () => step($twice, agentic(5)))->toThrow(RuntimeException::class, 'the summarizer was cut at its output token limit after 16 bytes (finish: length), twice');
});

it('gives the summarizer the run\'s system text as the agent\'s instructions, and none when there is none', function () {
    $summaries = new Summaries();
    step(new Compact($summaries, 2000, bytesOf(...)), agentic(5), 'Call every file a ledger.');
    step(new Compact($none = new Summaries(), 2000, bytesOf(...)), agentic(5), null);
    expect($summaries->prompts[0]->messages[0]->parts[0]->text)->toStartWith("The agent's instructions (context only, do not summarize them):\n\nCall every file a ledger.\n\nThe start of the conversation")
        ->and($none->prompts[0]->messages[0]->parts[0]->text)->toStartWith('The start of the conversation');
});

it('drops a round that would not shrink the prompt, and spends no side call on a part that small again', function () {
    $log        = lines();
    $summarizer = new FakeModel(says(\str_repeat('s', 600)));
    $compact    = new Compact($summarizer, 150, bytesOf(...), log: $log);
    $messages   = [user('the task'), assistant(new Text('a1')), user('u2'), assistant(new Text('a3')), user('u4')];
    $prompt     = step($compact, $messages);
    expect($prompt->messages)->toBe($messages)
        ->and($compact->last())->toBeNull()
        ->and($summarizer->prompts)->toHaveCount(1)
        ->and($log->lines[0][0])->toBe('warning')
        ->and($log->lines[0][1])->toStartWith('Compact: the prompt is ')->toEndWith('but has nothing more to summarize');

    // One more short exchange: the part to summarize is still smaller than the summary was.
    $messages[] = assistant(new Text('a5'));
    expect(step($compact, $messages)->messages)->toBe($messages)
        ->and($summarizer->prompts)->toHaveCount(1)
        ->and(\array_column($log->lines, 0))->toBe(['warning', 'warning']);
});

it('gives its calibration as a JSON value, and starts calibrated from one', function () {
    $compact = new Compact(new Summaries(), 100000, bytesOf(...));
    expect($compact->calibration())->toBeNull();
    $small = [user('the task')];
    $large = agentic(3);
    step($compact, $small, reported: bytesOf(TestPrompt::make('sys', $small)) + 300);
    step($compact, $large, reported: 2 * bytesOf(TestPrompt::make('sys', $large)) + 300);
    $calibration = $compact->calibration();
    $json        = \json_encode($calibration, \JSON_THROW_ON_ERROR);
    $restored    = Harness\Compact\Calibration::fromArray(\json_decode($json, true));
    expect($restored)->toEqual($calibration)
        ->and($calibration->fitted)->toBeTrue()
        ->and(\json_decode($json, true))->toBe([
            'low'    => ['counted' => bytesOf(TestPrompt::make('sys', $small)), 'reported' => bytesOf(TestPrompt::make('sys', $small)) + 300],
            'high'   => ['counted' => bytesOf(TestPrompt::make('sys', $large)), 'reported' => 2 * bytesOf(TestPrompt::make('sys', $large)) + 300],
            'ratio'  => $calibration->ratio,
            'offset' => $calibration->offset,
        ])
        ->and(fn () => Harness\Compact\Calibration::fromArray(['low' => ['counted' => 1]]))->toThrow(InvalidArgumentException::class);

    // A new instance with it counts calibrated from its first step: over the budget at once.
    $messages = agentic(5);
    $fresh    = new Compact(new Summaries(), $calibration->tokens(bytesOf(TestPrompt::make('sys', $messages))) - 1, bytesOf(...), calibration: $restored);
    step($fresh, $messages);
    expect($fresh->last())->not->toBeNull()
        ->and($fresh->last()->ratio)->toBe($calibration->ratio)
        ->and($fresh->calibration())->toBe($restored);
});

it('takes its budget from the context window less 20 %, and throws with neither', function () {
    $windowed = TestPrompt::make('sys', agentic(5), options: new Harness\Options(contextWindow: 2500));
    $model    = new FakeModel(says('ok'));
    $compact  = new Compact(new Summaries(), null, bytesOf(...), log: $log = lines());
    \iterator_to_array($compact->stream($windowed, fn (Prompt $p) => $model->stream($p)), false);
    expect($compact->last())->not->toBeNull()
        ->and(bytesOf($model->prompts[0]))->toBeLessThanOrEqual(2000)
        ->and($log->lines[0][1])->toContain('(budget 2000 tokens');

    expect(fn () => step(new Compact(new Summaries()), agentic(1)))->toThrow(LogicException::class, 'pass budgetTokens, or set Options::$contextWindow')
        ->and(fn () => \iterator_to_array((new Compact(new Summaries(), 3000))->stream($windowed, fn (Prompt $p) => (new FakeModel(says('ok')))->stream($p)), false))
        ->toThrow(LogicException::class, 'the budget of 3,000 tokens is over the context window of 2,500');
});

it('detects a prompt the server cut: a report under 70 % of the calibrated count', function () {
    $log     = lines();
    $compact = new Compact(new Summaries(), 100000, bytesOf(...), log: $log);
    $small   = [user('the task')];
    $large   = agentic(3);
    step($compact, $small, reported: bytesOf(TestPrompt::make('sys', $small)));
    step($compact, $large, reported: bytesOf(TestPrompt::make('sys', $large)));
    $calibration = $compact->calibration();
    expect($calibration->fitted)->toBeTrue()->and($log->lines)->toBe([]);

    $messages = agentic(5);
    $counted  = bytesOf(TestPrompt::make('sys', $messages));
    step($compact, $messages, reported: \intdiv($counted, 2));
    expect($compact->last()->truncatedUpstream)->toBeTrue()
        ->and($compact->last()->removed)->toBe(0)
        ->and($compact->last()->before)->toBe($counted)
        ->and($compact->calibration())->toBe($calibration)
        ->and($log->lines)->toBe([['warning', 'Compact: the provider reported ' . \intdiv($counted, 2) . " input tokens for a prompt calibrated at $counted: the server cut the prompt. Configure its context window (a server that evicts silently drops the system prompt first) and set Options::\$contextWindow"]]);

    // A report near the count is not a cut.
    step($compact, $messages, reported: $counted - 10);
    expect($compact->last())->toBeNull();
});

it('hands a continued prompt on as one, its last message untouched', function () {
    $messages = [...agentic(5), assistant(new Text('I will now'))];
    $model    = new FakeModel(says('ok'));
    \iterator_to_array((new Compact(new Summaries(), 2000, bytesOf(...)))->stream(TestPrompt::make('sys', $messages, continue: true), fn (Prompt $p) => $model->stream($p)), false);
    expect($model->prompts[0]->continue)->toBeTrue()
        ->and($model->prompts[0]->messages[\count($model->prompts[0]->messages) - 1])->toBe($messages[11])
        ->and(\count($model->prompts[0]->messages))->toBeLessThan(12);
});
