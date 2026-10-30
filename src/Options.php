<?php

declare(strict_types=1);

namespace Harness;

/**
 * The portable model knobs, plus `provider` for the rest: `['openai' => [...], 'anthropic' => [...]]`,
 * each read only by the provider of that name. A provider throws `UnsupportedOptionException` for a
 * portable option it cannot honour. `toolRetries` is the number of correctable tool failures
 * (`Retry`, unknown tool, malformed input) a turn tolerates before it fails. `contextWindow` is
 * the model's context window in tokens as the host knows it: the core only validates it and passes
 * it on (`Compact` takes its budget from it). The harness assumes a server that errors when a
 * prompt exceeds the window; one that silently evicts the oldest tokens must be configured.
 */
final readonly class Options
{
    /** @param array<string, array<string, mixed>> $provider */
    public function __construct(
        public ?int $maxOutputTokens = null,
        public ?float $temperature = null,
        public ToolChoice $toolChoice = ToolChoice::Auto,
        public ?string $tool = null,
        public int $toolRetries = 3,
        public array $provider = [],
        public ?int $contextWindow = null,
    ) {
        if ((ToolChoice::Tool === $toolChoice) !== (null !== $tool)) {
            throw new \InvalidArgumentException('Options::$tool names the forced tool and is set exactly when toolChoice is ToolChoice::Tool');
        }
        if (null !== $maxOutputTokens && $maxOutputTokens < 1) {
            throw new \InvalidArgumentException('maxOutputTokens must be at least 1');
        }
        if (null !== $contextWindow && $contextWindow < 1) {
            throw new \InvalidArgumentException('contextWindow is the model\'s window in tokens, at least 1');
        }
        if ($toolRetries < 0) {
            throw new \InvalidArgumentException('toolRetries must be 0 or more');
        }
        foreach ($provider as $name => $opts) {
            if (!\is_string($name) || !\is_array($opts)) {
                throw new \InvalidArgumentException('Options::$provider is keyed by provider name, each value an array');
            }
        }
    }
}
