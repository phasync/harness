<?php

declare(strict_types=1);

use Harness\Compact\Calibration;
use Harness\Compact\Checkpoint;
use Harness\Compact\Compact;
use Harness\Compact\State;
use Harness\FinishReason;
use Harness\Message;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Role;
use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;

it('rejects invalid Calibration initialization: lowReported must be at least 1', function () {
    expect(fn () => new Calibration(0, 0, 100, 200))->toThrow(
        InvalidArgumentException::class,
        'A calibration needs reported counts of at least 1 and the low point\'s count at most the high one\'s, got low (0, 0), high (100, 200)'
    );
});

it('serializes and deserializes Checkpoint with lifted message index', function () {
    // Test Checkpoint::jsonSerialize and fromArray preserves lifted message index
    $checkpoint = new Checkpoint(
        hash: 'abc123def456',
        covered: 10,
        kept: 2,
        lifted: 8,  // The lifted message (latest user message in the covered range)
        text: '## Goal' . "\n" . 'Task here',
        previousBytes: 1500,
        finish: FinishReason::Stop
    );

    // Serialize to JSON
    $json = \json_encode($checkpoint, \JSON_THROW_ON_ERROR);
    $data = \json_decode($json, true);

    // Deserialize back
    $restored = Checkpoint::fromArray($data);

    expect($restored->hash)->toBe('abc123def456')
        ->and($restored->covered)->toBe(10)
        ->and($restored->kept)->toBe(2)
        ->and($restored->lifted)->toBe(8)
        ->and($restored->text)->toBe('## Goal' . "\n" . 'Task here')
        ->and($restored->previousBytes)->toBe(1500)
        ->and($restored->finish)->toBe(FinishReason::Stop)
        ->and($restored->removed())->toBe(7);  // 10 covered - 2 kept - 1 lifted = 7
});

it('restores a checkpoint that no longer fits and updates it instead of summarizing from scratch', function () {
    // Verify docs line 152-154: restored checkpoint that exceeds budget is updated (UPDATE call),
    // not summarized from scratch (CREATE call).

    $bytesOf = fn (Prompt $p): int => \strlen(\json_encode(\array_map(fn (Message $m) => $m->toArray(), $p->messages)));

    // Mock summarizer to count calls
    $summaries1 = new class implements Harness\Model {
        public int $callCount = 0;

        public function stream(Prompt $p): iterable
        {
            ++$this->callCount;
            yield new Harness\Delta\TextDelta('Summary ' . $this->callCount);
            yield new Harness\Delta\Finish(FinishReason::Stop);
        }
    };

    // First Compact with tight budget to force checkpoint on large message
    $compact1 = new Compact($summaries1, 1500, $bytesOf);

    // Create a large message that will exceed budget and trigger checkpoint
    $largeMessages = [new Message(Role::User, [new Text('task')])];
    for ($i = 1; $i <= 8; ++$i) {
        $largeMessages[] = new Message(Role::Assistant, [new ToolCall("c$i", 'read', ['n' => $i])]);
        $largeMessages[] = new Message(Role::User, [new ToolResult("c$i", [new Text(\str_repeat((string) ($i % 10), 200))])]);
    }

    // Process to generate checkpoint
    $deltas1 = \iterator_to_array(
        $compact1->stream(TestPrompt::make('sys', $largeMessages), fn (Prompt $p) => new FakeModel(says('ok'))->stream($p)),
        false
    );

    $state1        = $compact1->state();
    $hasCheckpoint = !empty($state1->checkpoints);

    // Verify checkpoint was created
    expect($hasCheckpoint)->toBeTrue()
        ->and($compact1->last())->not->toBeNull()
        ->and($summaries1->callCount)->toBeGreaterThan(0);

    // Second Compact: restore state and check that UPDATE call (not CREATE) is made
    $summaries2 = new class implements Harness\Model {
        public array $prompts = [];

        public function stream(Prompt $p): iterable
        {
            $this->prompts[] = $p;
            yield new Harness\Delta\TextDelta('Updated');
            yield new Harness\Delta\Finish(FinishReason::Stop);
        }
    };

    $compact2 = new Compact($summaries2, 1500, $bytesOf, state: $state1);

    // Rebuild messages and EXTEND them to force the restored checkpoint to no longer fit
    $rebuilt = \array_map(
        fn (Message $m) => Message::fromArray(\json_decode(\json_encode($m->toArray()), true)),
        $largeMessages
    );

    // Add 4 more tool pairs (total 12 instead of 8) to exceed budget and require UPDATE
    for ($i = 9; $i <= 12; ++$i) {
        $rebuilt[] = new Message(Role::Assistant, [new ToolCall("c$i", 'read', ['n' => $i])]);
        $rebuilt[] = new Message(Role::User, [new ToolResult("c$i", [new Text(\str_repeat((string) ($i % 10), 200))])]);
    }

    // Process extended conversation - should trigger UPDATE call for the restored checkpoint
    $deltas2 = \iterator_to_array(
        $compact2->stream(TestPrompt::make('sys', $rebuilt), fn (Prompt $p) => new FakeModel(says('ok'))->stream($p)),
        false
    );

    // Verify UPDATE call was made (prompts[0] contains the checkpoint text, not null)
    expect($summaries2->prompts)->not->toBeEmpty()
        ->and($summaries2->prompts[0]->messages[0]->parts[0]->text ?? '')->toContain('current checkpoint');  // UPDATE has checkpoint text
});
