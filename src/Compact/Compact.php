<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\Accumulator;
use Harness\Cancel;
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
 * Keeps each step's prompt within `$budgetTokens` by replacing its oldest messages with a summary.
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
 * until the prompt fits or nothing is left to summarize (a warning is logged then). The last 8
 * summaries are kept, each under the messages it summarized, so one instance may serve several
 * conversations at once (the forks of a sub-agent's template share it). Each new summary is logged
 * at info level; `last()` says what the last step, of any conversation, did.
 *
 * The side call has no tools, `$summarizerOptions` and the turn's `Cancel`. A summary that comes
 * back empty, or shorter than a quarter of the summary it replaces, is asked for once more; a
 * second such summary fails the step with a `\RuntimeException` naming both sizes.
 *
 * The count is `$count($prompt)` times a ratio Compact calibrates itself: after each step it passes
 * on, the provider's reported input tokens divided by `$count` of that prompt (the last ratio wins;
 * a report of zero is ignored), so an approximation that undercounts a chat template is corrected
 * after one step. The fixed part of the prompt (system text, tool specs, the first `$keepFirst`
 * messages), counted at first use, must count at most half the budget, by the calibrated count, or
 * the step throws a `\LogicException`: a budget that passes at first use fails on a later step
 * when the provider's report shows the counter undercounted.
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

    /** @var \Closure(Prompt): int */
    private readonly \Closure $count;
    private readonly LoggerInterface $log;
    /**
     * Newest first: the conversation's messages up to the cut (`summarized`), what replaces them
     * (the kept first messages, the summary and its acknowledgement), the summary, the number of
     * messages it stands for, and the size of the summary it replaced.
     *
     * @var list<array{summarized: list<Message>, replacement: list<Message>, summary: string, removed: int, previous: ?int}>
     */
    private array $cache      = [];
    private float $ratio      = 1.0;
    /** The fixed part of the prompt, by `$count`, at first use. */
    private ?int $fixed       = null;
    private ?Compaction $last = null;

    /** @param (\Closure(Prompt): int)|null $count null: `Compact::approximate(...)` */
    public function __construct(
        private readonly Model $summarizer,
        private readonly int $budgetTokens,
        ?\Closure $count = null,
        private readonly int $keepFirst = 1,
        ?LoggerInterface $log = null,
        private readonly Options $summarizerOptions = new Options(maxOutputTokens: 1024),
    ) {
        if ($budgetTokens < 1) {
            throw new \InvalidArgumentException('Compact needs a budget of at least 1 token');
        }
        if ($keepFirst < 0) {
            throw new \InvalidArgumentException('keepFirst is 0 or more');
        }
        $this->count = $count ?? self::approximate(...);
        $this->log   = $log ?? new NullLogger();
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

    /** What the last step's prompt went through: null when it went on unchanged. */
    public function last(): ?Compaction
    {
        return $this->last;
    }

    public function stream(Prompt $prompt, \Closure $next): iterable
    {
        [$compacted, $counted] = $this->compact($prompt);
        $reported              = 0;
        foreach ($next($compacted) as $delta) {
            if ($delta instanceof Usage) {
                $reported = \max($reported, $delta->input);
            }
            yield $delta;
        }
        if ($reported > 0 && $counted > 0) {
            $this->ratio = $reported / $counted;
        }
    }

    /** @return array{0: Prompt, 1: int} the prompt to hand on and `$count` of it */
    private function compact(Prompt $prompt): array
    {
        $ratio  = $this->ratio;   // another conversation's step may change it while this one waits
        $tokens = fn (int $counted): int => (int) \ceil($counted * $ratio);
        // Counted at first use, compared calibrated: a provider's report can show it too large later.
        $this->fixed ??= ($this->count)(self::with($prompt, \array_slice($prompt->messages, 0, $this->keepFirst)));
        if (2 * $tokens($this->fixed) > $this->budgetTokens) {
            throw new \LogicException("Compact: the fixed part of the prompt (system text, tool specs and the first {$this->keepFirst} messages) counts " . \number_format($tokens($this->fixed)) . ' tokens' . (1.0 === $ratio ? '' : ' (counter ratio ' . \round($ratio, 3) . ')') . ', over half the budget of ' . \number_format($this->budgetTokens) . ': the budget must leave room above it for a summary and the recent half. Raise budgetTokens to ' . \number_format(4 * $tokens($this->fixed)) . ' or more, or shorten the system prompt and tool descriptions');
        }
        $counted = ($this->count)($prompt);
        $before  = $tokens($counted);
        if ($before <= $this->budgetTokens) {
            $this->last = null;

            return [$prompt, $counted];
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
        $rounds = 0;
        while (($after = $tokens($counted = ($this->count)(self::with($prompt, $view)))) > $this->budgetTokens) {
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
            if ($cut >= \count($view)) {
                $this->log->warning('Compact: the prompt is {tokens} tokens, over the budget of {budget}, but has nothing more to summarize', ['tokens' => $after, 'budget' => $this->budgetTokens]);
                break;
            }
            // A later round summarizes the earlier summary together with what follows it.
            $previous = null === $entry ? null : \strlen($entry['summary']);
            $summary  = $this->summarize(\array_slice($view, 0, $start), \array_slice($view, $start, $cut - $start), $prompt->cancel, $previous);
            $replace  = [new Message(Role::User, [new Text("[A summary of the earlier conversation, which was compacted to save context]\n\n$summary")])];
            if (Role::User === $view[$cut]->role) {
                $replace[] = new Message(Role::Assistant, [new Text('Understood. I continue from that summary.')]);
            }
            $known  = $cut + $offset;
            $entry  = [
                'summarized'  => \array_slice($messages, 0, $known),
                'replacement' => [...\array_slice($view, 0, $start), ...$replace],
                'summary'     => $summary,
                'removed'     => $known - $start,
                'previous'    => $previous,
            ];
            $view   = [...$entry['replacement'], ...\array_slice($messages, $known)];
            $offset = $known - \count($entry['replacement']);
            $floor  = \count($entry['replacement']);
            ++$rounds;
            $this->log->info('Compact: replaced {removed} messages with a summary of {bytes} bytes (budget {budget} tokens, counter ratio {ratio})', ['removed' => $entry['removed'], 'bytes' => \strlen($summary), 'budget' => $this->budgetTokens, 'ratio' => \round($ratio, 3)]);
        }
        if (null === $entry || $view === $messages) {
            $this->last = null;

            return [$prompt, $counted];
        }
        $this->remember($entry);
        $this->last = new Compaction($entry['removed'], \count($messages) - \count($entry['summarized']), $entry['summary'], $before, $after, 0 === $rounds, $entry['previous'], $ratio);

        return [self::with($prompt, $view), $counted];
    }

    /**
     * Puts the entry first, in place of the entries it supersedes: its own, when reused, and the
     * earlier summaries of the same conversation, whose messages are a prefix of its own.
     *
     * @param array{summarized: list<Message>, replacement: list<Message>, summary: string, removed: int, previous: ?int} $entry
     */
    private function remember(array $entry): void
    {
        $known       = \count($entry['summarized']);
        $this->cache = [$entry, ...\array_filter($this->cache, static fn (array $e): bool => \count($e['summarized']) > $known || \array_slice($entry['summarized'], 0, \count($e['summarized'])) !== $e['summarized'])];
        $this->cache = \array_slice($this->cache, 0, self::CACHE);
    }

    /**
     * The summary of `$segment`, with the `$kept` messages before it for context (the task, which
     * the summarizer would otherwise report as unknown). A collapsed one (empty, or under a quarter
     * of the `$previous` summary's bytes) is asked for once more, and fails the step the second time.
     *
     * @param list<Message> $kept
     * @param list<Message> $segment
     */
    private function summarize(array $kept, array $segment, Cancel $cancel, ?int $previous): string
    {
        $context = '';
        foreach ($kept as $message) {
            $context .= self::render($message);
        }
        $transcript = '';
        foreach ($segment as $message) {
            $transcript .= self::render($message);
        }
        $ask = '' === $context
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
            $finish = $acc->finish()?->value ?? 'none';
            if ('' !== $text && (null === $previous || 4 * \strlen($text) >= $previous)) {
                return $text;
            }
            $what = null === $previous
                ? "returned no text (finish: $finish)"
                : 'collapsed the summary to ' . \number_format(\strlen($text)) . ' bytes from the ' . \number_format($previous) . "-byte summary it replaces, under a quarter (finish: $finish)";
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
        return new Prompt($prompt->system, $messages, $prompt->tools, $prompt->options, $prompt->cancel);
    }
}
