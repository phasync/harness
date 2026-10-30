<?php

declare(strict_types=1);

use function fiber\sleep;
use Harness\Agents\AgentTool;
use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Event;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Message;
use Harness\Part\Text;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\Transcript\JsonlEvents;
use Harness\Transcript\JsonlTranscript;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-transcript-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
});

afterEach(function () {
    foreach (\glob($this->dir . '/*') as $f) {
        \unlink($f);
    }
    \rmdir($this->dir);
});

it('keeps a conversation in a JSONL file', function () {
    $t = new JsonlTranscript($this->dir . '/chat.jsonl');
    expect($t->load())->toBe([]);
    $run = new Run(new FakeModel(says('hello'), says('again')), new Tools());
    turn($run, user('hi'));
    $t->append($run->ended()->appended);
    turn($run, user('more'));
    $t->append($run->ended()->appended);
    expect($t->load())->toEqual($run->messages())
        ->and(\count(\file($this->dir . '/chat.jsonl')))->toBe(4);
    $t->clear();
    expect($t->load())->toBe([]);
});

it('refuses a transcript with a corrupt or cut-off line, naming the line', function () {
    $path = $this->dir . '/bad.jsonl';
    \file_put_contents($path, \json_encode((new Message(Role::User, [new Text('a')]))->toArray()) . "\n{\"role\":\"us\n");
    expect(fn () => (new JsonlTranscript($path))->load())->toThrow(RuntimeException::class, 'Line 2 of');
    \file_put_contents($path, \json_encode((new Message(Role::User, [new Text('a')]))->toArray()));
    expect(fn () => (new JsonlTranscript($path))->load())->toThrow(RuntimeException::class, 'Line 1 of');
});

it('writes every event of two concurrently streaming sub-agents as one whole line, and replays them', function () {
    $stream = fn (string $who) => function () use ($who) {
        for ($i = 0; $i < 1600; ++$i) {
            yield 0 === $i % 3 ? new ReasoningDelta("$who thinks $i " . \str_repeat('r', $i % 37)) : new TextDelta("$who $i " . \str_repeat('t', $i % 53) . " \"quoted\" ünïcode\n");
            sleep(0.0002);
        }
        yield new Finish(FinishReason::Stop);
    };
    $child  = fn (string $who) => new Run(new FakeModel($stream($who)), new Tools(), system: $who);
    $parent = new Run(
        new FakeModel(calls(['a', 'left', ['prompt' => 'x']], ['b', 'right', ['prompt' => 'y']]), says('both done')),
        new Tools(new AgentTool($child('L'), 'left', 'Left'), new AgentTool($child('R'), 'right', 'Right')),
    );
    expect($parent->tools->tools()[0]->spec()->hints)->toEqual(new Hints(readOnly: true));
    $path = $this->dir . '/events.jsonl';
    $sink = new JsonlEvents($path);
    $seen = \fiber\run(function () use ($parent, $sink) {
        $seen = [];
        foreach ($parent->turn(user('go')) as $event) {
            $sink->write($event);
            $seen[] = $event;
        }

        return $seen;
    });
    $lines = \file($path);
    expect(\count($lines))->toBe(\count($seen))
        ->and(\count($seen))->toBeGreaterThan(3200);
    foreach ($lines as $i => $line) {
        expect(\json_decode($line, true, 512, \JSON_THROW_ON_ERROR)['type'])->toBe($seen[$i]->toArray()['type']);
    }
    // The children streamed at the same time: their deltas interleave in the file.
    $parents = \array_map(fn (Event $e) => $e->parent, \array_slice($seen, 100, 50));
    expect(\array_unique(\array_filter($parents)))->toHaveCount(2);
    $replayed = \iterator_to_array((new JsonlEvents($path))->replay(), false);
    expect(\json_encode($replayed))->toBe(\json_encode($seen));
});

it('leaves every line whole when two processes write one file at once', function () {
    $path   = $this->dir . '/shared.jsonl';
    $script = \dirname(__DIR__) . '/Support/sink-writer.php';
    $procs  = [];
    foreach (['p1', 'p2'] as $who) {
        $procs[] = \proc_open([\PHP_BINARY, $script, $path, '3000', $who], [], $pipes);
    }
    foreach ($procs as $p) {
        expect(\proc_close($p))->toBe(0);
    }
    $count = ['p1' => 0, 'p2' => 0];
    foreach (\file($path) as $line) {
        ++$count[\json_decode($line, true, 512, \JSON_THROW_ON_ERROR)['parent']];
    }
    expect($count)->toBe(['p1' => 3000, 'p2' => 3000]);
});

/** A stream that takes at most 5 bytes per write and reports "would block" every other write. */
final class ChokedStream
{
    public static string $written = '';
    public static int $blocked    = 0;
    /** @var resource|null */
    public static $select;
    public mixed $context;
    private int $writes = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (1 === ++$this->writes % 2) {
            ++self::$blocked;

            return 0;
        }
        self::$written .= \substr($data, 0, 5);

        return \min(5, \strlen($data));
    }

    /** @return resource */
    public function stream_cast(int $as): mixed
    {
        return self::$select;
    }
}

it('finishes a line across short writes, waiting with fiber\writable()', function () {
    [ChokedStream::$select] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    \stream_wrapper_register('choked', ChokedStream::class);
    try {
        \fiber\run(function () {
            $sink = new JsonlEvents('choked://sink');
            $sink->write(new Event\TextDelta(1, null, 'm', 'hello'));
            $sink->write(new Event\StepEnded(1, null, 1));
        });
    } finally {
        \stream_wrapper_unregister('choked');
    }
    expect(ChokedStream::$written)->toBe('{"type":"text_delta","turn":1,"parent":null,"id":"m","text":"hello"}' . "\n" . '{"type":"step_ended","turn":1,"parent":null,"n":1}' . "\n")
        ->and(ChokedStream::$blocked)->toBeGreaterThan(10);
});
