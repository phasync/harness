<?php

declare(strict_types=1);

namespace Harness;

/**
 * Thrown by code that saw its `Cancel` signal set and stopped waiting (`Http\StreamTransport`, a
 * model). The `Run` that set the signal ends the turn `Reason::Cancelled`; anywhere else it
 * propagates like any other exception.
 */
final class Cancelled extends \RuntimeException
{
}
