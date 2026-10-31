<?php

declare(strict_types=1);

namespace Harness\Policy;

use Harness\Call;
use Harness\Part\ToolCall;

/**
 * One rule of a `Policy`: a test of the call and the `Verdict` when it matches, with a text
 * that names it in denials, approval reasons and `Policy::describe()`. Built by `tool()` (the
 * tool's name), `hints()` (the tool's annotations), `shell()` (a shell tool's parsed command) or
 * `when()` (any test). Name and hints are what a rule should bind to; a test over the arguments (a
 * pattern over a shell command) is bypassable by a model that phrases the same effect differently,
 * which is why `shell()` matches parsed argv and asks about whatever it cannot parse.
 */
final readonly class Rule
{
    /**
     * @param \Closure(ToolCall, Call): bool                    $test
     * @param (\Closure(ToolCall, Call): array{0: Verdict, 1: string})|null $decide the verdict and text for a matched call, when they depend on it
     */
    private function __construct(public string $text, public Verdict $verdict, private \Closure $test, private ?\Closure $decide = null)
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
     * Matches calls to the shell tool `$tool` and decides each by its `command` input, parsed into
     * simple commands (see `Shell`): `Deny` when one matches a `Shell::deny()` rule, `Allow` when
     * every one matches a `Shell::allow()` rule, `$otherwise` (default `Ask`) when one matches no
     * rule, and `Ask` for anything it cannot parse, an environment assignment or output redirected
     * to a file. A call without a string `command` is denied. The denial or approval reason names
     * the command that decided it.
     *
     * @param list<Shell> $rules first match wins, per simple command
     */
    public static function shell(array $rules, string $tool = 'shell', Verdict $otherwise = Verdict::Ask): self
    {
        foreach ($rules as $rule) {
            if (!$rule instanceof Shell) {
                throw new \InvalidArgumentException('Rule::shell() takes a list of Shell rules (Shell::allow(), Shell::deny()), got ' . \get_debug_type($rule));
            }
        }
        if (Verdict::Allow === $otherwise) {
            throw new \InvalidArgumentException('Rule::shell() cannot allow commands that match no rule: list them with Shell::allow()');
        }
        $rules  = \array_values($rules);
        $allows = \array_map(static fn (Shell $r) => $r->describe(), \array_values(\array_filter($rules, static fn (Shell $r) => Verdict::Allow === $r->verdict)));
        $denies = \array_map(static fn (Shell $r) => $r->describe(), \array_values(\array_filter($rules, static fn (Shell $r) => Verdict::Deny === $r->verdict)));
        $text   = "'$tool' commands other than " . \implode('; ', \array_filter([
            [] === $allows ? '' : \implode(', ', $allows) . ' (which run)',
            [] === $denies ? '' : \implode(', ', $denies) . ' (never)',
        ])) . ', or that cannot be parsed';

        return new self($text, $otherwise, static fn (ToolCall $call): bool => $call->name === $tool, static function (ToolCall $call) use ($rules, $otherwise): array {
            $command = $call->input['command'] ?? null;
            if (!\is_string($command)) {
                return [Verdict::Deny, 'shell calls need a string "command"'];
            }
            return Shell::check($command, $rules, $otherwise);
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

    /**
     * The rule that decides this call: this one, or for `shell()` one whose verdict and text are
     * this command's; null when the rule does not apply.
     */
    public function decide(ToolCall $call, Call $context): ?self
    {
        if (!$this->matches($call, $context)) {
            return null;
        }
        if (null === $this->decide) {
            return $this;
        }
        [$verdict, $text] = ($this->decide)($call, $context);

        return new self($text, $verdict, $this->test);
    }
}
