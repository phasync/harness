<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\Accumulator;
use Harness\Cancel;
use Harness\FinishReason;
use Harness\Message;
use Harness\Model;
use Harness\ModelMiddleware;
use Harness\Options;
use Harness\Part;
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Role;
use Harness\Usage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps each step's prompt within a token budget by replacing its oldest messages with a summary.
 * The budget is `$budgetTokens`, or when that is null the step's `Options::$contextWindow` less a
 * reserve of 20 %, taken at first use; with neither, the step throws a `\LogicException`.
 * When the prompt counts over the budget, the oldest half of the messages after the first
 * `$keepFirst` is replaced by one user message holding a summary that `$summarizer` wrote in a side
 * call (what was asked, what was done, what is known, what remains), followed by an assistant
 * acknowledgement when the next kept message is a user message, so roles alternate from there on.
 * The system prompt, the tool specs and the first `$keepFirst` messages are never touched; the
 * cut moves back so a `ToolResult` stays with its `ToolCall`; `Reasoning` is left out of what is
 * summarized. Only the prompt handed on changes, never `Run::messages()`.
 *
 * A summary is reused while the conversation keeps the same prefix and the result fits the budget,
 * so the provider's cache sees a stable prefix; when the budget is exceeded again, the earlier
 * summary is summarized again together with the oldest half of what follows it, round after round
 * until the prompt fits or nothing is left to summarize (a warning is logged then). A round whose
 * summary would not make the prompt smaller is dropped with that warning, and a later round runs
 * only for a part larger than the summary that was dropped, so no side call is spent per step on a
 * part too small to shrink. The last 8
 * summaries are kept, each under the messages it summarized, so one instance may serve several
 * conversations at once (the forks of a sub-agent's template share it). Each new summary is logged
 * at info level; `last()` says what the last step, of any conversation, did.
 *
 * The side call has no tools, `$summarizerOptions` and the turn's `Cancel`; it is given the run's
 * system text as the agent's instructions, the kept first messages and the part to summarize. A
 * summary that comes back empty, cut at the output token limit (`FinishReason::Length`), or shorter
 * than a quarter of the summary it replaces, is asked for once more; a second such summary fails
 * the step with a `\RuntimeException` naming what went wrong.
 *
 * The count is `$count($prompt)` calibrated by the provider's reported input tokens of the prompts
 * Compact passed on (a report of zero is ignored): `ratio * $count + offset`, the line through the
 * smallest and the largest prompt reported, once the largest counts at least twice the smallest;
 * until then the ratio is 1 and the offset the largest one's difference. A chat template adds a
 * fixed preamble (a Qwen template's tool instructions are some 270 tokens) and wraps every
 * message, so the offset holds the one and the ratio the other. The fixed part of the prompt
 * (system text, tool specs, the first `$keepFirst` messages), counted at first use, must count at
 * most half the budget, by the calibrated count once there is a line, or the step throws a
 * `\LogicException`: a budget that passes at first use fails on a later step when the provider's
 * reports show the counter undercounted. The calibration is a `Calibration` value: `calibration()`
 * returns it and the constructor takes one, so a host that builds a Compact per request keeps it.
 *
 * Once the calibration is a line, a reported input count under 70 % of the calibrated count means the server cut
 * the prompt (a server that evicts silently when the prompt exceeds its window): a warning names
 * both numbers, `last()` has `truncatedUpstream` set, and the report is left out of the calibration.
 */
final class Compact implements ModelMiddleware
{
    private const PROMPT = <<<'TXT'
        You compress the earlier part of a conversation between a user and an AI agent that uses tools, so the agent can continue without it. Write a summary under four headings:
        1. Asked: what the user asked for, with every requirement and constraint (the start of the conversation states the task).
        2. Done: what the agent did, step by step, with the tools used and their important results (paths, names, numbers, errors).
        3. Known: facts, decisions and findings established so far.
        4. Remaining: what is still to do, and open questions.
        Be complete about facts and terse in wording. Write only the summary.
        TXT;

    /** How many summaries are kept, one per conversation the instance serves. */
    private const CACHE = 8;

    /** The share of the context window a budget taken from it leaves for the prompt. */
    private const WINDOW_SHARE = 0.8;

    /** A report under this share of the calibrated count means the server cut the prompt. */
    private const TRUNCATED = 0.7;

    /** @var \Closure(Prompt): int */
    private readonly \Closure $count;
    private readonly LoggerInterface $log;
    /**
     * Newest first: the conversation's messages up to the cut (`summarized`), what replaces them
     * (the kept first messages, the summary and its acknowledgement), the summary, the number of
     * messages it stands for, the size of the summary it replaced and how its side call ended.
     *
     * @var list<array{summarized: list<Message>, replacement: list<Message>, summary: string, removed: int, previous: ?int, finish: FinishReason}>
     */
    private array $cache = [];
    private ?Calibration $calibration;
    /** The budget in use, fixed at first use. */
    private ?int $budget = null;
    /** The fixed part of the prompt, by `$count`, at first use. */
    private ?int $fixed = null;
    /** `$count` of the last summary that did not shrink the prompt: a smaller part is not summarized. */
    private ?int $noGain      = null;
    private ?Compaction $last = null;

    /**
     * @param int|null                     $budgetTokens null: the step's `Options::$contextWindow` less 20 %
     * @param (\Closure(Prompt): int)|null $count        null: `Compact::approximate(...)`
     * @param Calibration|null             $calibration  a calibration kept from an earlier instance
     */
    public function __construct(
        private readonly Model $summarizer,
        private readonly ?int $budgetTokens = null,
        ?\Closure $count = null,
        private readonly int $keepFirst = 1,
        ?LoggerInterface $log = null,
        private readonly Options $summarizerOptions = new Options(maxOutputTokens: 1024),
        ?Calibration $calibration = null,
    ) {
        if (null !== $budgetTokens && $budgetTokens < 1) {
            throw new \InvalidArgumentException('Compact needs a budget of at least 1 token, or null for the context window less 20 %');
        }
        if ($keepFirst < 0) {
            throw new \InvalidArgumentException('keepFirst is 0 or more');
        }
        $this->count       = $count ?? self::approximate(...);
        $this->log         = $log ?? new NullLogger();
        $this->calibration = $calibration;
    }

    /**
     * The default counter: the prompt's bytes / 4 (system text, text and reasoning, tool names and
     * input as JSON, tool result content, tool specs as JSON), 1000 tokens per media part, plus 4
     * per message and 10 per tool spec. An approximation, which Compact calibrates (see the class).
     */
    public static function approximate(Prompt $prompt): int
    {
        $bytes = \strlen($prompt->system ?? '');
        foreach ($prompt->tools as $spec) {
            $bytes += \strlen($spec->name) + \strlen($spec->description) + \strlen(\json_encode($spec->schema, \JSON_THROW_ON_ERROR));
        }
        $media = 0;
        foreach ($prompt->messages as $message) {
            $bytes += self::bytes($message->parts, $media);
        }

        return \intdiv($bytes, 4) + 1000 * $media + 4 * \count($prompt->messages) + 10 * \count($prompt->tools);
    }

    /** What the last step's prompt went through: null when it went on unchanged and was not cut upstream. */
    public function last(): ?Compaction
    {
        return $this->last;
    }

    /**
     * The counter's calibration from the provider's reports so far, null before the first. Persist
     * `toArray()` and pass `Calibration::fromArray()` to the next instance's constructor.
     */
    public function calibration(): ?Calibration
    {
        return $this->calibration;
    }

    public function stream(Prompt $prompt, \Closure $next): iterable
    {
        [$compacted, $counted, $calibration, $compaction] = $this->compact($prompt);
        $reported                                         = 0;
        foreach ($next($compacted) as $delta) {
            if ($delta instanceof Usage) {
                $reported = \max($reported, $delta->input);
            }
            yield $delta;
        }
        if (0 === $reported) {
            return;
        }
        // Only a line predicts a prompt of another size: one report's difference may belong to a
        // long prompt, and a short one would then report far under it without being cut.
        $estimate = true === $calibration?->fitted ? $calibration->tokens($counted) : null;
        if (null !== $estimate && $reported < self::TRUNCATED * $estimate) {
            $this->log->warning('Compact: the provider reported {reported} input tokens for a prompt calibrated at {estimate}: the server cut the prompt. Configure its context window (a server that evicts silently drops the system prompt first) and set Options::$contextWindow', ['reported' => $reported, 'estimate' => $estimate]);
            $this->last = null === $compaction
                ? new Compaction(0, \count($compacted->messages), '', $estimate, $estimate, false, null, $calibration->ratio, $calibration->offset, null, true)
                : new Compaction($compaction->removed, $compaction->kept, $compaction->summary, $compaction->before, $compaction->after, $compaction->reused, $compaction->previousSummaryBytes, $compaction->ratio, $compaction->offset, $compaction->finish, true);

            return;     // a cut prompt's report would teach the calibration the cut
        }
        $this->calibration = $this->calibration?->with($counted, $reported) ?? new Calibration($counted, $reported, $counted, $reported);
    }

    /**
     * The budget: `$budgetTokens`, or the context window less the reserve, fixed at first use.
     */
    private function budget(Prompt $prompt): int
    {
        if (null !== $this->budget) {
            return $this->budget;
        }
        $window = $prompt->options->contextWindow;
        if (null === $this->budgetTokens) {
            if (null === $window) {
                throw new \LogicException('Compact has no budget: pass budgetTokens, or set Options::$contextWindow on the run, whose window less a reserve of 20 % becomes the budget');
            }

            return $this->budget = (int) \floor(self::WINDOW_SHARE * $window);
        }
        if (null !== $window && $this->budgetTokens > $window) {
            throw new \LogicException('Compact: the budget of ' . \number_format($this->budgetTokens) . ' tokens is over the context window of ' . \number_format($window) . ' (Options::$contextWindow); set it below the window by the output tokens a step needs, or pass null for the window less 20 %');
        }

        return $this->budget = $this->budgetTokens;
    }

    /**
     * @return array{0: Prompt, 1: int, 2: ?Calibration, 3: ?Compaction} the prompt to hand on, `$count` of
     *                                                                   it, the calibration it was counted with and what was done
     */
    private function compact(Prompt $prompt): array
    {
        // Another conversation's step may change the calibration while this one waits.
        $cal         = $this->calibration;
        $fitted      = $cal?->fitted ?? false;
        $tokens      = fn (int $counted): int => null === $cal ? $counted : $cal->tokens($counted);
        $calibration = 'counter ratio ' . \round($cal?->ratio ?? 1.0, 3) . ', offset ' . \round($cal?->offset ?? 0.0);
        $budget      = $this->budget($prompt);
        // Counted at first use, compared calibrated once there is a line: one report's difference,
        // taken from a long restored conversation, says little about the fixed part.
        $this->fixed ??= ($this->count)(new Prompt($prompt->system, \array_slice($prompt->messages, 0, $this->keepFirst), $prompt->tools, $prompt->options, $prompt->cancel));
        $fixed = $fitted ? $tokens($this->fixed) : $this->fixed;
        if (2 * $fixed > $budget) {
            $raise = null === $this->budgetTokens ? 'Raise Options::$contextWindow (the budget is 80 % of it) or pass budgetTokens of ' : 'Raise budgetTokens to ';
            throw new \LogicException("Compact: the fixed part of the prompt (system text, tool specs and the first {$this->keepFirst} messages) counts " . \number_format($fixed) . ' tokens' . ($fitted ? " ($calibration)" : '') . ', over half the budget of ' . \number_format($budget) . ': the budget must leave room above it for a summary and the recent half. ' . $raise . \number_format(4 * $fixed) . ' or more, or shorten the system prompt and tool descriptions');
        }
        $counted = ($this->count)($prompt);
        $before  = $tokens($counted);
        if ($before <= $budget) {
            $this->last = null;

            return [$prompt, $counted, $cal, null];
        }
        $messages = $prompt->messages;
        // === holds the same Message objects: a fork's copy of the messages shares them.
        $entry = null;
        foreach ($this->cache as $e) {
            $known = \count($e['summarized']);
            if (\count($messages) > $known && (null === $entry || $known > \count($entry['summarized'])) && \array_slice($messages, 0, $known) === $e['summarized']) {
                $entry = $e;
            }
        }
        $view   = $messages;
        $offset = 0;      // $view[$i] is $messages[$i + $offset] from $floor on
        $floor  = 0;      // the start of what $view keeps verbatim after a summary
        if (null !== $entry) {
            $view   = [...$entry['replacement'], ...\array_slice($messages, \count($entry['summarized']))];
            $offset = \count($entry['summarized']) - \count($entry['replacement']);
            $floor  = \count($entry['replacement']);
        }
        $rounds  = 0;
        $nothing = 'Compact: the prompt is {tokens} tokens, over the budget of {budget}, but has nothing more to summarize';
        while (($after = $tokens($counted = ($this->count)(self::with($prompt, $view)))) > $budget) {
            $start = \min($this->keepFirst, \count($view));
            if ($start > 0 && $start < \count($view) && self::hasResults($view[$start])) {
                ++$start;   // the results of the last kept message's tool calls stay with it
            }
            $floor = \max($floor, $start);
            $cut   = \min(\max($start + \intdiv(\count($view) - $start, 2), $floor + 1), \count($view) - 1);
            while ($cut > $floor && self::hasResults($view[$cut])) {
                --$cut;     // never end the summarized part between a tool call and its result
            }
            if ($cut <= $floor) {
                // Too little before the cut: take the pair after it in whole, if a message remains.
                for ($cut = $floor + 1; $cut < \count($view) && self::hasResults($view[$cut]); ++$cut) {
                }
            }
            $segment = \array_slice($view, $start, $cut - $start);
            if ($cut >= \count($view) || (null !== $this->noGain && $this->countMessages($prompt, $segment) <= $this->noGain)) {
                $this->log->warning($nothing, ['tokens' => $after, 'budget' => $budget]);
                break;
            }
            // A later round summarizes the earlier summary together with what follows it.
            $previous            = null === $entry ? null : \strlen($entry['summary']);
            [$summary, $finish]  = $this->summarize($prompt->system, \array_slice($view, 0, $start), $segment, $prompt->cancel, $previous);
            $replace             = [new Message(Role::User, [new Text("[A summary of the earlier conversation, which was compacted to save context]\n\n$summary")])];
            if (Role::User === $view[$cut]->role) {
                $replace[] = new Message(Role::Assistant, [new Text('Understood. I continue from that summary.')]);
            }
            $known     = $cut + $offset;
            $candidate = [
                'summarized'  => \array_slice($messages, 0, $known),
                'replacement' => [...\array_slice($view, 0, $start), ...$replace],
                'summary'     => $summary,
                'removed'     => $known - $start,
                'previous'    => $previous,
                'finish'      => $finish,
            ];
            $next = [...$candidate['replacement'], ...\array_slice($messages, $known)];
            if (($this->count)(self::with($prompt, $next)) >= $counted) {
                // Two small messages replaced by a larger summary: keep them, and spend no side
                // call on a part this small again.
                $this->noGain = $this->countMessages($prompt, $replace);
                $this->log->warning($nothing, ['tokens' => $after, 'budget' => $budget, 'summaryBytes' => \strlen($summary)]);
                break;
            }
            $entry  = $candidate;
            $view   = $next;
            $offset = $known - \count($entry['replacement']);
            $floor  = \count($entry['replacement']);
            ++$rounds;
            $this->log->info('Compact: replaced {removed} messages with a summary of {bytes} bytes (budget {budget} tokens, {calibration})', ['removed' => $entry['removed'], 'bytes' => \strlen($summary), 'budget' => $budget, 'calibration' => $calibration]);
        }
        if (null === $entry || $view === $messages) {
            $this->last = null;

            return [$prompt, $counted, $cal, null];
        }
        $this->remember($entry);
        $this->last = new Compaction($entry['removed'], \count($messages) - \count($entry['summarized']), $entry['summary'], $before, $after, 0 === $rounds, $entry['previous'], $cal?->ratio ?? 1.0, $cal?->offset ?? 0.0, $entry['finish']);

        return [self::with($prompt, $view), $counted, $cal, $this->last];
    }

    /**
     * `$count` of messages alone, without the system text and tool specs.
     *
     * @param list<Message> $messages
     */
    private function countMessages(Prompt $prompt, array $messages): int
    {
        return ($this->count)(new Prompt(null, $messages, [], $prompt->options, $prompt->cancel));
    }

    /**
     * Puts the entry first, in place of the entries it supersedes: its own, when reused, and the
     * earlier summaries of the same conversation, whose messages are a prefix of its own.
     *
     * @param array{summarized: list<Message>, replacement: list<Message>, summary: string, removed: int, previous: ?int, finish: FinishReason} $entry
     */
    private function remember(array $entry): void
    {
        $known       = \count($entry['summarized']);
        $this->cache = [$entry, ...\array_filter($this->cache, static fn (array $e): bool => \count($e['summarized']) > $known || \array_slice($entry['summarized'], 0, \count($e['summarized'])) !== $e['summarized'])];
        $this->cache = \array_slice($this->cache, 0, self::CACHE);
    }

    /**
     * The summary of `$segment` and how its side call ended, with the agent's instructions and the
     * `$kept` messages before it for context (the task, which the summarizer would otherwise report
     * as unknown, in the terms the instructions use). A collapsed one (empty, cut at the token
     * limit, or under a quarter of the `$previous` summary's bytes) is asked for once more, and
     * fails the step the second time.
     *
     * @param list<Message> $kept
     * @param list<Message> $segment
     *
     * @return array{0: string, 1: FinishReason}
     */
    private function summarize(?string $system, array $kept, array $segment, Cancel $cancel, ?int $previous): array
    {
        $context = '';
        foreach ($kept as $message) {
            $context .= self::render($message);
        }
        $transcript = '';
        foreach ($segment as $message) {
            $transcript .= self::render($message);
        }
        $ask = null === $system || '' === \trim($system) ? '' : "The agent's instructions (context only, do not summarize them):\n\n" . \trim($system) . "\n\n";
        $ask .= '' === $context
            ? "The conversation so far:\n\n{$transcript}Write the summary."
            : "The start of the conversation, which the agent keeps in full (context only, do not summarize it):\n\n{$context}The part to summarize, which follows it:\n\n{$transcript}Write the summary.";
        $prompt = new Prompt(self::PROMPT, [new Message(Role::User, [new Text($ask)])], [], $this->summarizerOptions, $cancel);
        for ($attempt = 1;; ++$attempt) {
            $acc = new Accumulator();
            foreach ($this->summarizer->stream($prompt) as $delta) {
                $acc->add($delta);
            }
            $text = '';
            foreach ($acc->message()->parts as $part) {
                if ($part instanceof Text) {
                    $text .= $part->text;
                }
            }
            $text   = \trim($text);
            $finish = $acc->finish() ?? throw new \Harness\ModelException(\Harness\ErrorKind::ModelError, 'The summarizer\'s stream ended without a Finish delta');
            if ('' !== $text && FinishReason::Length !== $finish && (null === $previous || 4 * \strlen($text) >= $previous)) {
                return [$text, $finish];
            }
            $what = match (true) {
                '' === $text                     => "returned no text (finish: {$finish->value})",
                FinishReason::Length === $finish => 'was cut at its output token limit after ' . \number_format(\strlen($text)) . ' bytes (finish: length)',
                default                          => 'collapsed the summary to ' . \number_format(\strlen($text)) . ' bytes from the ' . \number_format((int) $previous) . "-byte summary it replaces, under a quarter (finish: {$finish->value})",
            };
            if (2 === $attempt) {
                throw new \RuntimeException("Compact: the summarizer $what, twice; a collapsed summary loses what the agent knew. Give it more output tokens or turn its thinking off in summarizerOptions, or use another summarizer");
            }
            $this->log->warning("Compact: the summarizer $what; asking once more", ['bytes' => \strlen($text), 'previous' => $previous]);
        }
    }

    /** A message as plain text for the summarizer, without reasoning. */
    private static function render(Message $message): string
    {
        $out = '';
        foreach ($message->parts as $part) {
            $out .= match (true) {
                $part instanceof Text       => $part->text . "\n",
                $part instanceof Reasoning  => '',
                $part instanceof ToolCall   => "[calls {$part->name} " . \json_encode((object) $part->input, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "]\n",
                $part instanceof ToolResult => '[' . ($part->error ? 'error' : 'result') . "]\n" . self::render(new Message(Role::User, $part->content)),
                $part instanceof Media      => "[{$part->mime}]\n",
                $part instanceof Reference  => '[' . ($part->title ?? $part->uri) . " <{$part->uri}>]\n",
            };
        }

        return '' === $out ? '' : (Role::User === $message->role ? 'USER' : 'ASSISTANT') . ":\n$out\n";
    }

    private static function hasResults(Message $message): bool
    {
        foreach ($message->parts as $part) {
            if ($part instanceof ToolResult) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Part> $parts */
    private static function bytes(array $parts, int &$media): int
    {
        $bytes = 0;
        foreach ($parts as $part) {
            if ($part instanceof Media) {
                ++$media;
                continue;
            }
            $bytes += match (true) {
                $part instanceof Text, $part instanceof Reasoning => \strlen($part->text),
                $part instanceof ToolCall                         => \strlen($part->name) + \strlen(\json_encode($part->input, \JSON_THROW_ON_ERROR)),
                $part instanceof ToolResult                       => self::bytes($part->content, $media),
                $part instanceof Reference                        => \strlen($part->uri) + \strlen($part->title ?? ''),
            };
        }

        return $bytes;
    }

    /** @param list<Message> $messages */
    private static function with(Prompt $prompt, array $messages): Prompt
    {
        return new Prompt($prompt->system, $messages, $prompt->tools, $prompt->options, $prompt->cancel, $prompt->continue);
    }
}
