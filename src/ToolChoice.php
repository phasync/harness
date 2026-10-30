<?php

declare(strict_types=1);

namespace Harness;

/** Whether the model may, must not, or must call a tool. `Tool` forces the one named by `Options::$tool`. */
enum ToolChoice: string
{
    case Auto     = 'auto';
    case None     = 'none';
    case Required = 'required';
    case Tool     = 'tool';
}
