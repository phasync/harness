<?php

declare(strict_types=1);

namespace Harness\Policy;

use Harness\Approval;
use Harness\Call;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Result;
use Harness\ToolMiddleware;

/**
 * An approval layer as a `ToolMiddleware`: the first `Rule` that matches a call decides, else
 * `$default`. `Allow` runs the call; `Deny` gives the model an error result naming the rule; `Ask`
 * ends the call with `needsInput([new Approval(reason: <the rule's text>)])`, without state, so a denial is
 * answered `Denied: <reason>` by the run. A web host answers it with an `Answer` in a later request;
 * a CLI answers at once in a middleware placed before (outside) the policy, the README's inline
 * idiom. A call the host answered already, and a call to a tool the run does not have (which the
 * run reports to the model), go on unchecked. `describe()` gives the rules as text for a system prompt.
 */
final readonly class Policy implements ToolMiddleware
{
    /** @var list<Rule> */
    private array $rules;

    /** @param list<Rule> $rules first match wins */
    public function __construct(array $rules, private Verdict $default = Verdict::Ask)
    {
        foreach ($rules as $rule) {
            if (!$rule instanceof Rule) {
                throw new \InvalidArgumentException('Policy takes a list of Rule (Rule::tool(), Rule::hints(), Rule::shell(), Rule::when()), got ' . \get_debug_type($rule));
            }
        }
        $this->rules = \array_values($rules);
    }

    public function call(ToolCall $call, Call $context, \Closure $next): Result
    {
        if ([] !== $context->answers() || null === $context->spec) {
            return $next($call, $context);
        }
        [$verdict, $text] = [$this->default, 'any other tool'];
        foreach ($this->rules as $rule) {
            if (null !== ($decided = $rule->decide($call, $context))) {
                [$verdict, $text] = [$decided->verdict, $decided->text];
                break;
            }
        }

        return match ($verdict) {
            Verdict::Allow => $next($call, $context),
            Verdict::Deny  => new Result([new Text("Denied by the policy rule: $text")], error: true),
            Verdict::Ask   => $context->needsInput([new Approval(reason: $text)]),
        };
    }

    /** The rules as text, one line each in order, ending with the default: for a system prompt. */
    public function describe(): string
    {
        $lines = ['Tool policy (the first matching rule applies):'];
        foreach ([...\array_map(static fn (Rule $r) => [$r->text, $r->verdict], $this->rules), ['any other tool', $this->default]] as [$text, $verdict]) {
            $lines[] = "- $text: " . match ($verdict) {
                Verdict::Allow => 'run without asking',
                Verdict::Deny  => 'never run',
                Verdict::Ask   => "need the user's approval",
            };
        }

        return \implode("\n", $lines);
    }
}
