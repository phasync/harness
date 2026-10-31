<?php

declare(strict_types=1);

use function fiber\go;
use function fiber\readable;
use function fiber\sleep;
use Harness\Call;
use Harness\Cancel;
use Harness\Cancelled;
use Harness\Event\ToolCallReady;
use Harness\Event\ToolEnded;
use Harness\Event\ToolProgress;
use Harness\Hints;
use Harness\Http\Response;
use Harness\Http\StreamTransport;
use Harness\Part\ToolCall;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\FakeTransport;
use Harness\ToolMiddleware;
use Harness\Tools;

it('hands events over synchronously: a tool middleware runs after the host handled ToolCallReady', function () {
    $log = [];
    $mw  = new class($log) implements ToolMiddleware {
        private array $log;

        public function __construct(array &$log)
        {
            $this->log = &$log;
        }

        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            $this->log[] = 'middleware';

            return $next($call, $context);
        }
    };
    $run = new Run(new FakeModel(calls(['c1', 'echo', []]), says('ok')), new Tools(tool('echo', fn () => Result::text('x'))), middleware: [$mw]);
    \fiber\run(function () use ($run, &$log) {
        foreach ($run->turn(user('go')) as $event) {
            if ($event instanceof ToolCallReady) {
                $log[] = 'host: ready';
                sleep(0.05); // the turn's fibers get every chance to run ahead now
                $log[] = 'host: handled';
            } elseif ($event instanceof ToolEnded) {
                $log[] = 'host: ended';
            }
        }
    });
    expect($log)->toBe(['host: ready', 'host: handled', 'middleware', 'host: ended']);
});

it('makes concurrent emitters wait their turn, each until the host handled its event', function () {
    $log  = [];
    $tool = function (string $name) use (&$log) {
        return tool($name, function (array $in, Call $call) use ($name, &$log) {
            for ($i = 1; $i <= 3; ++$i) {
                $call->progress("$name$i");
                $log[] = "after $name$i";
                sleep(0.001);
            }

            return Result::text($name);
        }, new Hints(readOnly: true));
    };
    $run = new Run(new FakeModel(calls(['a', 'a', []], ['b', 'b', []]), says('ok')), new Tools($tool('a'), $tool('b')));
    \fiber\run(function () use ($run, &$log) {
        foreach ($run->turn(user('go')) as $event) {
            if ($event instanceof ToolProgress) {
                sleep(0.005);
                $log[] = "host {$event->value}";
            }
        }
    });
    foreach (['a1', 'a2', 'a3', 'b1', 'b2', 'b3'] as $v) {
        expect(\array_search("host $v", $log, true))->toBeLessThan(\array_search("after $v", $log, true));
    }
    // Both calls ran concurrently: their progress interleaves.
    expect(\array_search('host b1', $log, true))->toBeLessThan(\array_search('host a3', $log, true));
});

it('reports whether a turn is running', function () {
    $run  = new Run(new FakeModel(says('hi')), new Tools());
    $seen = [];
    \fiber\run(function () use ($run, &$seen) {
        $seen[] = $run->isRunning();
        foreach ($run->turn(user('go')) as $event) {
            $seen[] = $run->isRunning();
        }
        $seen[] = $run->isRunning();
    });
    expect($seen[0])->toBeFalse()
        ->and(\array_unique(\array_slice($seen, 1, -2)))->toBe([true])
        ->and($seen[\count($seen) - 2])->toBeFalse() // at TurnEnded the turn is over (#36)
        ->and(\end($seen))->toBeFalse();
});

/**
 * An HTTP server in the current run that answers one request with an SSE response head and then
 * stays silent until the client hangs up.
 *
 * @return int the port
 */
function silentServer(): int
{
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port   = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
    go(function () use ($server) {
        $conn = \stream_socket_accept(readable($server), 5);
        \stream_set_blocking($conn, false);
        $request = '';
        while (!\str_contains($request, "\r\n\r\n")) {
            $request .= \fread(readable($conn), 65536);
        }
        \fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n");
        while ('' !== \fread(readable($conn, 5), 65536) || !\feof($conn)) {
        }
        \fclose($conn);
        \fclose($server);
    });

    return $port;
}

it('ends a cancelled turn within the transport poll while the server is silent', function () {
    [$ended, $elapsed] = \fiber\run(function () {
        $port     = silentServer();
        $run      = new Run(new ChatCompletions(new StreamTransport(), "http://127.0.0.1:$port/v1", 'm'), new Tools());
        $consumer = go(function () use ($run) {
            foreach ($run->turn(user('go')) as $event) {
            }
        });
        sleep(0.3);
        $t0 = \microtime(true);
        $run->cancel();
        \fiber\await($consumer);

        return [$run->ended(), \microtime(true) - $t0];
    });
    expect($ended->reason)->toBe(Reason::Cancelled)
        ->and($elapsed)->toBeLessThan(0.5)
        ->and(\array_map(fn ($m) => $m->role, $ended->appended))->toBe([Role::User]);
});

it('gives the turn\'s Cancel signal to the model and on to the transport', function () {
    $transport = new FakeTransport(fn (string $url, array $headers, string $body, Cancel $cancel) => new Response(200, [], (function () use ($cancel) {
        while (!$cancel->isCancelled()) {
            sleep(0.25);
        }
        throw new Cancelled('the body saw the signal');
        yield '';
    })()));
    [$ended, $elapsed, $events] = \fiber\run(function () use ($transport) {
        $run      = new Run(new ChatCompletions($transport, 'http://h/v1', 'm'), new Tools());
        $events   = [];
        $consumer = go(function () use ($run, &$events) {
            foreach ($run->turn(user('go')) as $event) {
                $events[] = $event;
            }
        });
        sleep(0.1);
        $t0 = \microtime(true);
        $run->cancel();
        \fiber\await($consumer);

        return [$run->ended(), \microtime(true) - $t0, $events];
    });
    expect($ended->reason)->toBe(Reason::Cancelled)
        ->and($ended->error)->toBeNull()
        ->and($elapsed)->toBeLessThan(0.5)
        ->and(types($events))->toBe(['turn_started', 'step_started', 'message_started', 'step_ended', 'turn_ended']);
});

it('lets a Cancelled from elsewhere fail the turn when the run was not cancelled', function () {
    $run = new Run(new FakeModel(fn () => throw new Cancelled('not ours')), new Tools());
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->error->getMessage())->toBe('not ours');
});

it('ends Done with the finish reason of a reply cut by the token limit', function () {
    $run    = new Run(new FakeModel([new Harness\Delta\TextDelta('cut'), new Harness\Delta\Finish(Harness\FinishReason::Length)]), new Tools());
    $events = turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->ended()->finish)->toBe(Harness\FinishReason::Length)
        ->and(\end($events)->finish)->toBe(Harness\FinishReason::Length)
        ->and(\end($events)->toArray()['finish'])->toBe('length');
});
