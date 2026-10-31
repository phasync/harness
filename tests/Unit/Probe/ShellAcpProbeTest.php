<?php

declare(strict_types=1);

use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;
use Harness\Tools\Shell\Shell;

it('throws when FakeModel runs out of replies', function () {
    $model = new FakeModel();
    expect(fn () => \fiber\run(fn () => \iterator_to_array($model->stream(TestPrompt::make()))))->toThrow(
        LogicException::class,
        'FakeModel has no reply left for call 1'
    );
});

it('captures only-stderr output with non-zero exit code', function () {
    $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => 'echo error >&2; exit 2'], callFor()));
    expect($r->content[0]->text)->toBe("Exit code 2\nerror\n")
        ->and($r->error)->toBeTrue()
        ->and($r->value)->toBe(2);
});

it('does not leave background children alive', function () {
    $marker = \sys_get_temp_dir() . '/harness-bg-child-' . \bin2hex(\random_bytes(4));
    $cmd    = "(sleep 2 && touch '$marker') &";

    try {
        $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => $cmd], callFor()));
        expect($r->content[0]->text)->toContain('Exit code 0');

        \usleep(2500000);

        expect(\file_exists($marker))->toBeFalse();
    } finally {
        if (\is_file($marker)) {
            \unlink($marker);
        }
    }
});

it('returns promptly when background child holds the pipe', function () {
    if (!\function_exists('posix_kill')) {
        $this->markTestSkipped('posix_kill not available');
    }

    $start = \microtime(true);
    $r     = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(
        ['command' => '(sleep 5) >/dev/null 2>&1 & echo $!'],
        callFor()
    ));
    $elapsed = \microtime(true) - $start;

    expect($elapsed)->toBeLessThan(0.5)
        ->and($r->content[0]->text)->toContain('Exit code 0');

    if (\preg_match('/Exit code 0\n(\d+)/', $r->content[0]->text, $m)) {
        $pid = (int) $m[1];
        expect($pid)->toBeGreaterThan(0);

        // The group gets SIGKILL'd promptly, but the killed grandchild is reparented (its own
        // parent, the backgrounded subshell, is dead too) and lingers as a zombie until whatever
        // reaps it gets around to it - a race the Shell tool does not control. Poll briefly
        // instead of checking once: a single immediate check is flaky under load (observed
        // failing when the whole suite runs, though it passes every time alone).
        $dead = false;
        for ($i = 0; $i < 20; ++$i) {
            if (!\posix_kill($pid, 0)) {
                $dead = true;
                break;
            }
            \usleep(50000);
        }
        expect($dead)->toBeTrue();
    }
});
