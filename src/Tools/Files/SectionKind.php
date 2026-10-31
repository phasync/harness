<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal what a V4A patch section does to its file. */
enum SectionKind
{
    case Add;
    case Delete;
    case Update;
}
