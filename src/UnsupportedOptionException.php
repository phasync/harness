<?php

declare(strict_types=1);

namespace Harness;

/** A provider was given an option it cannot honour. Thrown instead of dropping it. */
final class UnsupportedOptionException extends \LogicException
{
}
