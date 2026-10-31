<?php

declare(strict_types=1);

use function fiber\go;
use function fiber\readable;
use function fiber\sleep;
use function fiber\writable;
use Harness\Accumulator;
use Harness\Delta;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\FinishReason;
use Harness\Http\StreamTransport;
use Harness\Http\TransportException;
use Harness\Message;
use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Role;

// Test fixture: unknown delta type for testing line 123 error path
final class ProbePartsUnknownDelta implements Delta
{
}

it('seeds Accumulator with assistant message and rejects non-assistant', function () {
    $seed = new Message(Role::Assistant, [new Text('previous')]);
    $acc  = new Accumulator($seed);
    expect($acc->message()->parts)->toEqual([new Text('previous')]);

    $userMsg = new Message(Role::User, [new Text('user')]);
    expect(fn () => new Accumulator($userMsg))->toThrow(InvalidArgumentException::class, 'assistant message');
});

it('preserves seeded Media and Reference parts through delta accumulation with tool calls', function () {
    $seed = new Message(Role::Assistant, [
        new Text('Caption:'),
        new Media('image/png', uri: 'https://example.com/image.png'),
        new Reference('https://example.com', 'Example'),
        new Text('Continued'),
    ]);
    $acc = new Accumulator($seed);

    // Extend seeded text with a new text delta (merges because last part is text)
    $acc->add(new TextDelta(' from deltas'));
    // Add a tool call with input
    $acc->add(new ToolCallStart('c1', 'read'));
    $acc->add(new ToolInputDelta('c1', '{"path":"file.txt"}'));
    $acc->add(new ToolCallEnd('c1'));
    $acc->add(new Finish(FinishReason::ToolCalls));

    $msg = $acc->message();
    expect($msg->parts)->toHaveCount(5)
        ->and($msg->parts[0])->toEqual(new Text('Caption:'))
        ->and($msg->parts[1])->toEqual(new Media('image/png', uri: 'https://example.com/image.png'))
        ->and($msg->parts[2])->toEqual(new Reference('https://example.com', 'Example'))
        ->and($msg->parts[3])->toEqual(new Text('Continued from deltas'))
        ->and($msg->parts[4])->toEqual(new ToolCall('c1', 'read', ['path' => 'file.txt']))
        ->and($acc->finish())->toBe(FinishReason::ToolCalls);
});

it('rejects unknown delta types as a provider bug', function () {
    $acc = new Accumulator();
    expect(fn () => $acc->add(new ProbePartsUnknownDelta()))->toThrow(LogicException::class, 'Unknown delta');
});

/**
 * A one-shot HTTP server for testing StreamTransport error paths: reads one request and writes
 * raw response chunks with pauses between them. Returns [port, server_task].
 */
function probeHttpServe(Closure $respond): array
{
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port   = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
    $task   = go(function () use ($server, $respond) {
        $conn = \stream_socket_accept(readable($server), 0);
        \stream_set_blocking($conn, false);
        $request = '';
        while (!\str_contains($request, "\r\n\r\n") || \strlen(\substr($request, \strpos($request, "\r\n\r\n") + 4)) < (int) (\preg_match('/content-length: (\d+)/i', $request, $m) ? $m[1] : 0)) {
            $request .= \fread(readable($conn), 65536);
        }
        foreach ($respond($request) as $chunk) {
            \fwrite(writable($conn), $chunk);
            sleep(0.01);
        }
        \fclose($conn);
        \fclose($server);

        return $request;
    });

    return [$port, $task];
}

it('rejects malformed status line from server (StreamTransport line 91 error path)', function () {
    expect(fn () => \fiber\run(function () {
        [$port] = probeHttpServe(fn () => [
            "INVALID 200 OK\r\n\r\n",
        ]);
        (new StreamTransport())->post("http://127.0.0.1:$port/", [], '', live());
    }))->toThrow(TransportException::class, 'Malformed status line');
});
