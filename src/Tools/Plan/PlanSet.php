<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

use Harness\Call;
use Harness\Hints;
use Harness\Result;
use Harness\Retry;
use Harness\Tool;
use Harness\ToolSpec;

/**
 * `plan_set`: replaces the plan with a new ordered list of steps, each starting `pending`. Set the
 * plan before starting work on a task with more than two steps, so the goal and the open steps stay
 * in front of you as you work; a short task needs no plan.
 */
final readonly class PlanSet implements Tool
{
    /** The highest number of steps a plan may hold. */
    private const MAX_STEPS = 30;

    public function __construct(private Plan $plan)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('plan_set', 'Sets (or replaces) the plan: an ordered list of short step descriptions, each starting pending. Call this before starting work on a task with more than two steps; a task of one or two steps needs no plan. Replacing the plan starts every step over at pending.', [
            'type'       => 'object',
            'properties' => [
                'steps' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_STEPS,
                    'description' => 'The steps, in order, each a short description of one piece of work',
                ],
            ],
            'required' => ['steps'],
        ], new Hints(idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $steps = $input['steps'] ?? null;
        if (!\is_array($steps) || !\array_is_list($steps) || [] === $steps) {
            throw new Retry("'steps' must be a non-empty list of strings");
        }
        if (\count($steps) > self::MAX_STEPS) {
            throw new Retry('steps must hold at most ' . self::MAX_STEPS . ' entries');
        }
        foreach ($steps as $step) {
            if (!\is_string($step)) {
                throw new Retry("every entry of 'steps' must be a string");
            }
        }
        $this->plan->set($steps);

        return Result::text($this->plan->render());
    }
}
