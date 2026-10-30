<?php

declare(strict_types=1);

namespace Harness;

/**
 * The portable model knobs, plus `provider` for the rest: `['openai' => [...], 'anthropic' => [...]]`,
 * each read only by the provider of that name. A provider throws `UnsupportedOptionException` for a
 * portable option it cannot honour. `toolRetries` is the number of correctable tool failures
 * (`Retry`, unknown tool, malformed input) a turn tolerates before it fails.
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
    ) {
        if ((ToolChoice::Tool === $toolChoice) !== (null !== $tool)) {
            throw new \InvalidArgumentException('Options::$tool names the forced tool and is set exactly when toolChoice is ToolChoice::Tool');
        }
        if (null !== $maxOutputTokens && $maxOutputTokens < 1) {
            throw new \InvalidArgumentException('maxOutputTokens must be at least 1');
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
