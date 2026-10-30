<?php

declare(strict_types=1);

namespace Harness;

/** Everything a model call needs: the system text, the conversation, the tools of this step and the options. */
final readonly class Prompt
{
    /**
     * @param list<Message>  $messages
     * @param list<ToolSpec> $tools
     */
    public function __construct(
        public ?string $system,
        public array $messages,
        public array $tools,
        public Options $options,
    ) {
        foreach ($messages as $m) {
            if (!$m instanceof Message) {
                throw new \InvalidArgumentException('Prompt messages must be Message objects, got ' . \get_debug_type($m));
            }
        }
        foreach ($tools as $t) {
            if (!$t instanceof ToolSpec) {
                throw new \InvalidArgumentException('Prompt tools must be ToolSpec objects, got ' . \get_debug_type($t));
            }
        }
    }
}
