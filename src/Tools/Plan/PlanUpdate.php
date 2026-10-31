<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

use Harness\Call;
use Harness\Hints;
use Harness\Part\Text;
use Harness\Result;
use Harness\Retry;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `plan_update`: changes one step's status, and optionally its note. Mark a step `in_progress`
 * when you start it and `done` when you finish it; never leave a step `in_progress` while working
 * on another one. A step you decide not to do becomes `skipped`, with a note saying why.
 */
final readonly class PlanUpdate implements Tool
{
    public function __construct(private Plan $plan)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('plan_update', 'Updates one step of the plan by its number. Mark a step in_progress when you start it, done when you finish it, or skipped (with a note why) when you decide not to do it. Keep at most one step in_progress at a time.', [
            'type'       => 'object',
            'properties' => [
                'step'   => ['type' => 'integer', 'description' => 'The step number, as shown by plan_set or the previous plan_update'],
                'status' => ['type' => 'string', 'enum' => \array_map(static fn (Status $s): string => $s->value, Status::cases()), 'description' => 'The step\'s new status'],
                'note'   => ['type' => 'string', 'description' => 'An optional short note, such as why a step was skipped'],
            ],
            'required' => ['step', 'status'],
        ], new Hints(idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $id     = Input::int($input, 'step', 0, 1);
        $status = Status::tryFrom((string) ($input['status'] ?? ''));
        if (null === $status) {
            throw new Retry("'status' must be one of: " . \implode(', ', \array_map(static fn (Status $s): string => $s->value, Status::cases())));
        }
        $note = $input['note'] ?? null;
        if (null !== $note && !\is_string($note)) {
            throw new Retry("'note' must be a string");
        }
        try {
            $this->plan->update($id, $status, $note);
        } catch (\InvalidArgumentException $e) {
            return new Result([new Text($e->getMessage())], error: true);
        }

        return Result::text($this->plan->render());
    }
}
