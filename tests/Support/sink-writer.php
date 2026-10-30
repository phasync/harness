<?php

declare(strict_types=1);

// Writes `$argv[2]` events through a JsonlEvents sink on `$argv[1]`, with text of varying length:
// two of these at once on one file must leave every line whole.
require __DIR__ . '/../../vendor/autoload.php';

\fiber\setup(new FiberInterop\Phasync\Adapter());
$sink = new Harness\Transcript\JsonlEvents($argv[1]);
\fiber\run(static function () use ($sink, $argv) {
    for ($i = 0; $i < (int) $argv[2]; ++$i) {
        $sink->write(new Harness\Event\TextDelta(1, $argv[3], "m$i", \str_repeat('x', \random_int(1, 400))));
        if (0 === $i % 50) {
            \fiber\sleep(0.001);
        }
    }
});
