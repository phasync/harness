<?php

declare(strict_types=1);

/*
 * A streamed conversation on the terminal. Ctrl-C during a reply interrupts it: you may retype its
 * last line (Enter keeps it) and the model goes on from there. A line typed while a reply streams
 * steers it: the model reads it at the next step, and tool calls not yet started are skipped. A
 * line ended with Alt+Enter, or starting with /queue (terminals differ), is queued instead: the
 * model answers it after the current reply. Ctrl-C at the prompt quits.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert php examples/chat.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Delivery;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Reason;
use Harness\Resume;
use Harness\Role;
use Harness\Run;
use Harness\Tools;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url   = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$model = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');
$run   = new Run($model, new Tools(), system: 'You are a helpful assistant. Be concise.', options: new Options(maxOutputTokens: 2000));

\pcntl_async_signals(true);
\pcntl_signal(\SIGINT, function () use ($run) {
    if (!$run->isRunning()) {
        echo "\n";
        exit(0);
    }
    $run->interrupt();
});

fiber\run(function () use ($run) {
    // One reader owns STDIN: while a reply streams, a line steers or queues; otherwise it is the
    // next line the prompt asks for.
    $lines   = new SplQueue();
    $arrived = null;
    fiber\go(function () use ($run, $lines, &$arrived) {
        while (false !== ($line = \fgets(fiber\readable(\STDIN)))) {
            if ($run->isRunning() && '' !== \trim($line)) {
                // Alt+Enter arrives as ESC before the newline.
                $queue = \str_starts_with(\trim($line), '/queue') || \str_ends_with(\rtrim($line, "\r\n"), "\e");
                $text  = \trim(\preg_replace('{^\s*/queue\b}', '', \rtrim(\rtrim($line, "\r\n"), "\e")));
                $run->interject(new Message(Role::User, [new Text($text)]), $queue ? Delivery::Queue : Delivery::Steer);
                continue;
            }
            $lines->enqueue($line);
            $arrived?->resolve();
        }
        $lines->enqueue(false);
        $arrived?->resolve();
    });
    $read = function (string $prompt) use ($lines, &$arrived): string|false {
        echo $prompt;
        while ($lines->isEmpty()) {
            fiber\await($arrived = new fiber\Deferred());
        }

        return $lines->dequeue();
    };

    while (false !== ($line = $read("\n\e[1myou>\e[0m "))) {
        if ('' === \trim($line)) {
            continue;
        }
        $input = new Message(Role::User, [new Text(\trim($line))]);
        while (null !== $input) {
            $reasoning = false;
            foreach ($run->turn($input) as $event) {
                if ($event instanceof Event\MessageStarted) {
                    echo "\e[1massistant>\e[0m ";
                } elseif ($event instanceof Event\MessageResumed) {
                    echo "\e[1massistant>\e[0m " . textOf($input->message);
                } elseif ($event instanceof Event\ReasoningDelta) {
                    echo "\e[2m{$event->text}\e[0m";
                    $reasoning = true;
                } elseif ($event instanceof Event\TextDelta) {
                    echo ($reasoning ? "\n\n" : '') . $event->text;
                    $reasoning = false;
                } elseif ($event instanceof Event\Interjected) {
                    echo "\n\e[2m[you, " . (Delivery::Queue === $event->delivery ? 'queued' : 'steering') . ': ' . textOf($event->message) . "]\e[0m\n";
                }
            }
            $ended = $run->ended();
            $input = null;
            echo match ($ended->reason) {
                Reason::Interrupted => "\n\e[33m[interrupted]\e[0m\n",
                Reason::Cancelled   => "\n\e[33m[cancelled]\e[0m\n",
                Reason::Failed      => "\n\e[31m[{$ended->errorKind->value}] {$ended->error->getMessage()}\e[0m\n",
                default             => "\n\e[2m[{$ended->usage->input} in, {$ended->usage->output} out]\e[0m\n",
            };
            if (Reason::Interrupted !== $ended->reason && [] !== $ended->queued) {
                // Lines typed as the turn was ending did not land: they are the next message.
                $input = new Message(Role::User, \array_merge(...\array_map(fn (Message $m) => $m->parts, $ended->queued)));
                continue;
            }
            if (Reason::Interrupted !== $ended->reason || null === $ended->partial || '' === textOf($ended->partial)) {
                continue;
            }
            // The partial reply: retype its last line, or keep it, and the model goes on from there.
            $text  = textOf($ended->partial);
            $cut   = \strrpos($text, "\n");
            $last  = false === $cut ? $text : \substr($text, $cut + 1);
            $typed = $read("\e[2mlast line:\e[0m $last\n\e[1mreplace with (Enter keeps it)>\e[0m ");
            if (false === $typed) {
                return;
            }
            $kept  = '' === \trim($typed) ? $text : (false === $cut ? '' : \substr($text, 0, $cut + 1)) . \rtrim($typed, "\r\n");
            $parts = \array_values(\array_filter($ended->partial->parts, fn ($p) => !$p instanceof Text));
            $input = new Resume(new Message(Role::Assistant, [...$parts, new Text($kept)]));
        }
    }
    echo "\n";
});

/** The text parts of a message, joined. */
function textOf(Message $message): string
{
    return \implode('', \array_map(fn ($p) => $p instanceof Text ? $p->text : '', $message->parts));
}
