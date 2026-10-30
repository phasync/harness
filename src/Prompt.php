<?php

declare(strict_types=1);

namespace Harness;

/**
 * Everything a model call needs: the system text, the conversation, the tools of this step, the
 * options and the turn's `Cancel` signal, which the model passes on to its transport. A middleware
 * that rewrites the prompt passes `$prompt->cancel` and `$prompt->continue` on unchanged.
 * `continue` is true when the last message is an assistant message to continue (a `Resume`), not
 * a finished turn: the model's deltas extend it. A provider that cannot continue a message throws
 * `UnsupportedOptionException`.
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
        public bool $continue = false,
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
        if ($continue && ([] === $messages || Role::Assistant !== $messages[\count($messages) - 1]->role)) {
            throw new \InvalidArgumentException('A prompt that continues a message ends with that assistant message');
        }
    }
}
