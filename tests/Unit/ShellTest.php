<?php

declare(strict_types=1);

use Harness\Event\ToolProgress;
use Harness\Tools\Shell\Shell;

it('runs a command and reports its output and exit code', function () {
    $events = [];
    $r      = \fiber\run(function () use (&$events) {
        return (new Shell(\sys_get_temp_dir()))->call(['command' => 'echo out; echo err >&2; pwd'], callFor('c', $events));
    });
    expect($r->content[0]->text)->toBe("Exit code 0\nout\nerr\n" . \realpath(\sys_get_temp_dir()) . "\n")
        ->and($r->error)->toBeFalse()
        ->and($r->value)->toBe(0)
        ->and($events)->not->toBeEmpty()
        ->and($events[0])->toBeInstanceOf(ToolProgress::class);
    $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => 'exit 3'], callFor()));
    expect($r->content[0]->text)->toBe('Exit code 3 (no output)')->and($r->error)->toBeTrue();
});

it('kills the whole process group on timeout', function () {
    $marker = \sys_get_temp_dir() . '/harness-shell-' . \bin2hex(\random_bytes(4));
    $t      = \microtime(true);
    $r      = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => "(sleep 0.5; touch $marker) & sleep 5", 'timeout' => 0.2], callFor()));
    expect($r->content[0]->text)->toStartWith('Killed: timed out after 0.2 s')
        ->and(\microtime(true) - $t)->toBeLessThan(1.5);
    \usleep(600000);
    expect(\file_exists($marker))->toBeFalse();
});

it('runs commands concurrently with other fibers', function () {
    $order = \fiber\run(function () {
        $log = [];
        $a   = \fiber\go(function () use (&$log) {
            (new Shell(\sys_get_temp_dir()))->call(['command' => 'sleep 0.1'], callFor());
            $log[] = 'shell';
        });
        \fiber\go(function () use (&$log) {
            \fiber\sleep(0.02);
            $log[] = 'other';
        });
        \fiber\await($a);

        return $log;
    });
    expect($order)->toBe(['other', 'shell']);
});

it('cuts the middle of long output', function () {
    $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir(), maxOutput: 100))->call(['command' => 'seq 1 1000'], callFor()));
    expect($r->content[0]->text)->toContain('bytes cut]')->toEndWith("1000\n")
        ->and(\strlen($r->content[0]->text))->toBeLessThan(200);
});
