<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\Accumulator;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps each step's prompt within `$budgetTokens` by replacing its oldest messages with a summary.
 * When `$count($prompt)` exceeds the budget, the oldest half of the messages after the first
 * `$keepFirst` is replaced by one user message holding a summary that `$summarizer` wrote in a side
 * call (what was asked, what was done, what is known, what remains), followed by an assistant
 * acknowledgement when the next kept message is a user message, so roles alternate from there on.
 * The system prompt, the tool specs and the first `$keepFirst` messages are never touched; the
 * cut moves back so a `ToolResult` stays with its `ToolCall`; `Reasoning` is left out of what is
 * summarized. Only the prompt handed on changes, never `Run::messages()`.
 *
 * The last cut and summary are remembered and reused while the conversation keeps the same
 * prefix and the result fits the budget, so the provider's cache sees a stable prefix; when the
 * budget is exceeded again, the earlier summary is summarized again together with the oldest half
 * of what follows it, round after round until the prompt fits or nothing is left to summarize (a
 * warning is logged then). Each new summary is logged at info level (messages removed, summary size);
 * `last()` says what the last step did. The side call has no tools, the step's `maxOutputTokens`
 * and provider options, and the turn's `Cancel`. A summarizer that returns no text fails the step.
 */
final class Compact implements ModelMiddleware
{
    private const PROMPT = <<<'TXT'
        You compress the earlier part of a conversation between a user and an AI agent that uses tools, so the agent can continue without it. Write a summary under four headings:
        1. Asked: what the user asked for, with every requirement and constraint.
        2. Done: what the agent did, step by step, with the tools used and their important results (paths, names, numbers, errors).
        3. Known: facts, decisions and findings established so far.
        4. Remaining: what is still to do, and open questions.
        Be complete about facts and terse in wording. Write only the summary.
        TXT;

    /** @var \Closure(Prompt): int */
    private readonly \Closure $count;
    private readonly LoggerInterface $log;
    /** @var list<Message> the conversation's messages up to the remembered cut */
    private array $summarized = [];
    /** @var list<Message> what replaces them: the kept first messages, the summary and its acknowledgement */
    private array $replacement = [];
    private string $summary    = '';
    private int $removed       = 0;
    private ?Compaction $last  = null;

    /** @param (\Closure(Prompt): int)|null $count null: `Compact::approximate(...)` */
    public function __construct(
        private readonly Model $summarizer,
        private readonly int $budgetTokens,
        ?\Closure $count = null,
        private readonly int $keepFirst = 1,
        ?LoggerInterface $log = null,
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
     * per message and 10 per tool spec. An approximation; pass the provider's tokenizer for precision.
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
        yield from $next($this->compact($prompt));
    }

    private function compact(Prompt $prompt): Prompt
    {
        $before = ($this->count)($prompt);
        if ($before <= $this->budgetTokens) {
            $this->last = null;

            return $prompt;
        }
        $messages = $prompt->messages;
        $known    = \count($this->summarized);
        $view     = $messages;
        $offset   = 0;      // $view[$i] is $messages[$i + $offset] from $floor on
        $floor    = 0;      // the start of what $view keeps verbatim after a summary
        // == is identity for the same objects, and a deep comparison for a restored copy.
        if ($known > 0 && \count($messages) > $known && \array_slice($messages, 0, $known) === $this->summarized) {
            $view   = [...$this->replacement, ...\array_slice($messages, $known)];
            $offset = $known - \count($this->replacement);
            $floor  = \count($this->replacement);
        }
        $rounds = 0;
        while (($after = ($this->count)(self::with($prompt, $view))) > $this->budgetTokens) {
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
            $summary = $this->summarize(\array_slice($view, $start, $cut - $start), $prompt);
            $replace = [new Message(Role::User, [new Text("[A summary of the earlier conversation, which was compacted to save context]\n\n$summary")])];
            if (Role::User === $view[$cut]->role) {
                $replace[] = new Message(Role::Assistant, [new Text('Understood. I continue from that summary.')]);
            }
            $known             = $cut + $offset;
            $this->summarized  = \array_slice($messages, 0, $known);
            $this->replacement = [...\array_slice($view, 0, $start), ...$replace];
            $this->summary     = $summary;
            $this->removed     = $known - $start;
            $view              = [...$this->replacement, ...\array_slice($messages, $known)];
            $offset            = $known - \count($this->replacement);
            $floor             = \count($this->replacement);
            ++$rounds;
            $this->log->info('Compact: replaced {removed} messages with a summary of {bytes} bytes (budget {budget} tokens)', ['removed' => $this->removed, 'bytes' => \strlen($summary), 'budget' => $this->budgetTokens]);
        }
        if ($view === $messages) {
            $this->last = null;

            return $prompt;
        }
        $this->last = new Compaction($this->removed, \count($messages) - \count($this->summarized), $this->summary, $before, $after, 0 === $rounds);

        return self::with($prompt, $view);
    }

    /** @param list<Message> $segment */
    private function summarize(array $segment, Prompt $step): string
    {
        $transcript = '';
        foreach ($segment as $message) {
            $transcript .= self::render($message);
        }
        $options = new Options(maxOutputTokens: $step->options->maxOutputTokens, provider: $step->options->provider);
        $prompt  = new Prompt(self::PROMPT, [new Message(Role::User, [new Text("The conversation so far:\n\n{$transcript}Write the summary.")])], [], $options, $step->cancel);
        $acc     = new Accumulator();
        foreach ($this->summarizer->stream($prompt) as $delta) {
            $acc->add($delta);
        }
        $text = '';
        foreach ($acc->message()->parts as $part) {
            if ($part instanceof Text) {
                $text .= $part->text;
            }
        }
        $text = \trim($text);
        if ('' === $text) {
            throw new \RuntimeException('Compact: the summarizer returned no text (finish: ' . ($acc->finish()?->value ?? 'none') . '); give it more output tokens');
        }

        return $text;
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
