<?php

declare(strict_types=1);

namespace Harness;

/**
 * Thrown by a tool for a failure the model may correct (a bad argument, a missing file). It
 * becomes an error result with this message, counted against `Options::$toolRetries`; past that
 * budget the turn fails with `ErrorKind::ToolRetriesExceeded`.
 */
final class Retry extends \RuntimeException
{
}
