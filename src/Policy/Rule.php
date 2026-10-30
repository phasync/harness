<?php

declare(strict_types=1);

namespace Harness\Policy;

use Harness\Call;
use Harness\Part\ToolCall;

/**
 * One rule of a `Policy`: a test of the call and the `Verdict` when it matches, with a text
 * that names it in denials, approval reasons and `Policy::describe()`. Built by `tool()` (the
 * tool's name), `hints()` (the tool's annotations) or `when()` (any test). Name and hints are what
 * a rule should bind to; a test over the arguments (a pattern over a shell command) is bypassable
 * by a model that phrases the same effect differently.
 */
final readonly class Rule
{
    /** @param \Closure(ToolCall, Call): bool $test */
    private function __construct(public string $text, public Verdict $verdict, private \Closure $test)
    {
    }

    /** Matches the tool name exactly, or as a glob with `*`, `?` and `[...]` (`'mcp_*'`). */
    public static function tool(string $name, Verdict $verdict): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('Rule::tool() needs a tool name or a glob');
        }
        $glob = false !== \strpbrk($name, '*?[');

        return new self($glob ? "tools matching '$name'" : "the tool '$name'", $verdict, static fn (ToolCall $call): bool => $glob ? \fnmatch($name, $call->name, \FNM_NOESCAPE) : $call->name === $name);
    }

    /**
     * Matches tools whose `Hints` have every value given (`Rule::hints(Verdict::Allow, readOnly:
     * true)`); a tool the model named but the run does not have never matches.
     */
    public static function hints(Verdict $verdict, ?bool $readOnly = null, ?bool $destructive = null, ?bool $idempotent = null, ?bool $openWorld = null): self
    {
        $want = \array_filter(['readOnly' => $readOnly, 'destructive' => $destructive, 'idempotent' => $idempotent, 'openWorld' => $openWorld], static fn (?bool $v) => null !== $v);
        if ([] === $want) {
            throw new \InvalidArgumentException('Rule::hints() needs at least one of readOnly, destructive, idempotent, openWorld');
        }
        $words = ['readOnly' => 'read-only', 'destructive' => 'destructive', 'idempotent' => 'idempotent', 'openWorld' => 'open-world'];
        $text  = 'tools that are ' . \implode(' and ', \array_map(static fn (string $k, bool $v) => ($v ? '' : 'not ') . $words[$k], \array_keys($want), $want));

        return new self($text, $verdict, static function (ToolCall $call, Call $context) use ($want): bool {
            if (null === $context->spec) {
                return false;
            }
            $hints = $context->spec->hints->toArray();
            foreach ($want as $k => $v) {
                if ($hints[$k] !== $v) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Matches when `$test` returns true; `$text` names the rule ("tools of the billing server").
     *
     * @param \Closure(ToolCall, Call): bool $test
     */
    public static function when(\Closure $test, Verdict $verdict, string $text): self
    {
        if ('' === $text) {
            throw new \InvalidArgumentException('Rule::when() needs a text that names the rule, for denials, approvals and describe()');
        }

        return new self($text, $verdict, $test);
    }

    /** True when the rule applies to this call. */
    public function matches(ToolCall $call, Call $context): bool
    {
        $match = ($this->test)($call, $context);
        if (!\is_bool($match)) {
            throw new \LogicException("The test of the rule '{$this->text}' returned " . \get_debug_type($match) . ', not bool');
        }

        return $match;
    }
}
