<?php

declare(strict_types=1);

namespace Harness;

/** The turn's correctable tool errors went past `Options::$toolRetries`; `Ended::$error` holds it. */
final class ToolRetriesExceeded extends \RuntimeException
{
}
