<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

/**
 * The plan an agent keeps while it works: an ordered list of `Step`. `set()` replaces the whole
 * plan; `update()` changes one step's status (and note) by its id. `render()` is the compact
 * checklist the `plan_set` and `plan_update` tools return to the model, and that `Reminder` keeps in
 * front of it. `toArray()`/`fromArray()` persist it as `Harness\Compact\State` does.
 */
final class Plan
{
    /** @var list<Step> */
    private array $steps = [];

    /**
     * Replaces the plan: `$texts` become steps 1..count($texts), each `Status::Pending` with no note.
     *
     * @param list<string> $texts
     */
    public function set(array $texts): void
    {
        $this->steps = [];
        foreach ($texts as $i => $text) {
            $this->steps[] = new Step($i + 1, $text, Status::Pending, null);
        }
    }

    /**
     * Changes step `$id`'s status and note. Throws `\InvalidArgumentException` naming the ids that
     * exist when `$id` is not one of them.
     */
    public function update(int $id, Status $status, ?string $note = null): void
    {
        foreach ($this->steps as $i => $step) {
            if ($step->id === $id) {
                $this->steps[$i] = new Step($id, $step->text, $status, $note);

                return;
            }
        }
        throw new \InvalidArgumentException([] === $this->steps ? "Step $id does not exist; the plan has no steps (call plan_set first)" : "Step $id does not exist; the plan has steps " . \implode(', ', \array_map(static fn (Step $s): int => $s->id, $this->steps)));
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** The steps not yet finished: pending and in_progress, in order. */
    public function open(): array
    {
        return \array_values(\array_filter(
            $this->steps,
            static fn (Step $s): bool => Status::Pending === $s->status || Status::InProgress === $s->status,
        ));
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    /**
     * A compact checklist, one line per step: `[x]` done, `[>]` in progress, `[ ]` pending, `[-]`
     * skipped, each `<mark> <id>. <text>`, with ` — <note>` appended when the step has one.
     */
    public function render(): string
    {
        return \implode("\n", \array_map(static function (Step $step): string {
            $mark = match ($step->status) {
                Status::Pending    => ' ',
                Status::InProgress => '>',
                Status::Done       => 'x',
                Status::Skipped    => '-',
            };
            $line = "[$mark] {$step->id}. {$step->text}";

            return null === $step->note ? $line : "$line — {$step->note}";
        }, $this->steps));
    }

    /** @return list<array{id: int, text: string, status: string, note: ?string}> */
    public function toArray(): array
    {
        return \array_map(static fn (Step $s): array => $s->toArray(), $this->steps);
    }

    /** @param list<array{id: int, text: string, status: string, note: ?string}> $data */
    public static function fromArray(array $data): self
    {
        $plan        = new self();
        $plan->steps = \array_map(Step::fromArray(...), $data);

        return $plan;
    }
}
