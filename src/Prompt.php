<?php

declare(strict_types=1);

namespace Harness;

/**
 * Everything a model call needs: the system text, the conversation, the tools of this step, the
 * options and the turn's `Cancel` signal, which the model passes on to its transport. A middleware
 * that rewrites the prompt passes `$prompt->cancel` on unchanged.
 */
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
        public Cancel $cancel,
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
