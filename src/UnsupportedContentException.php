<?php

declare(strict_types=1);

namespace Harness;

/** A provider was given a part it cannot send (a media type the API does not take, say). Thrown instead of dropping it. */
final class UnsupportedContentException extends \LogicException
{
}
