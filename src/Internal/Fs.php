<?php

declare(strict_types=1);

namespace Harness\Internal;

/**
 * @internal The tools' filesystem writes: a failure is a `\RuntimeException` carrying the OS's
 * reason, never a PHP warning
 */
final class Fs
{
    /** Creates `$dir` with its parents when it does not exist; `$label` names it in the error. */
    public static function mkdir(string $dir, string $label): void
    {
        if (\is_dir($dir)) {
            return;
        }
        $reason = self::try(static fn (): bool => \mkdir($dir, 0o777, true));
        if (null !== $reason && !\is_dir($dir)) { // made by someone else meanwhile is fine
            throw new \RuntimeException("Could not create $label: $reason");
        }
    }

    public static function read(string $abs, string $label): string
    {
        $content = null;
        $reason  = self::try(static function () use ($abs, &$content): bool {
            $content = \file_get_contents($abs);

            return false !== $content;
        });
        if (null !== $reason) {
            throw new \RuntimeException("Could not read $label: $reason");
        }

        return (string) $content;
    }

    public static function write(string $abs, string $content, string $label): void
    {
        $reason = self::try(static fn (): bool => false !== \file_put_contents($abs, $content));
        if (null !== $reason) {
            throw new \RuntimeException("Could not write $label: $reason");
        }
    }

    public static function unlink(string $abs, string $label): void
    {
        $reason = self::try(static fn (): bool => \unlink($abs));
        if (null !== $reason) {
            throw new \RuntimeException("Could not delete $label: $reason");
        }
    }

    /**
     * Runs `$op` with its warning captured: null when it succeeded, else the warning's text without
     * the function name and stream preamble (`Permission denied`), or `failed` when it failed silently.
     *
     * @param \Closure(): bool $op
     */
    private static function try(\Closure $op): ?string
    {
        $reason = null;
        \set_error_handler(static function (int $no, string $message) use (&$reason): bool {
            $reason = \preg_replace('/^\w+\(.*?\): (Failed to open stream: )?/', '', $message) ?? $message; // "mkdir(): " or "file_put_contents(/x): Failed to open stream: " go

            return true;
        });
        try {
            $ok = $op();
        } finally {
            \restore_error_handler();
        }

        return $ok ? null : ($reason ?? 'failed');
    }
}
