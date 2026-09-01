<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Services\Assets\AssetStatusTransitionService;
use App\Support\Assets\AssetStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AssetStatusTransitionTest extends TestCase
{
    private AssetStatusTransitionService $transitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transitions = new AssetStatusTransitionService();
    }

    public function test_legal_transitions_are_allowed(): void
    {
        $this->assertTrue($this->transitions->canTransition(AssetStatus::IN_STOCK, AssetStatus::ASSIGNED));
        $this->assertTrue($this->transitions->canTransition(AssetStatus::ASSIGNED, AssetStatus::IN_USE));
        $this->assertTrue($this->transitions->canTransition(AssetStatus::UNDER_REPAIR, AssetStatus::REPAIR_COMPLETED));
        $this->assertTrue($this->transitions->canTransition(AssetStatus::RETIRED, AssetStatus::DISPOSED));
    }

    public function test_disposed_is_a_terminal_state_with_no_way_out(): void
    {
        $this->assertFalse($this->transitions->canTransition(AssetStatus::DISPOSED, AssetStatus::IN_USE));
        $this->assertFalse($this->transitions->canTransition(AssetStatus::DISPOSED, AssetStatus::IN_STOCK));
        $this->assertFalse($this->transitions->canTransition(AssetStatus::DISPOSED, AssetStatus::RETIRED));
    }

    public function test_retired_can_only_move_to_disposed(): void
    {
        $this->assertTrue($this->transitions->canTransition(AssetStatus::RETIRED, AssetStatus::DISPOSED));
        $this->assertFalse($this->transitions->canTransition(AssetStatus::RETIRED, AssetStatus::IN_USE));
        $this->assertFalse($this->transitions->canTransition(AssetStatus::RETIRED, AssetStatus::IN_STOCK));
    }

    public function test_lost_and_missing_only_lead_to_under_inspection_or_each_other(): void
    {
        $this->assertTrue($this->transitions->canTransition(AssetStatus::LOST, AssetStatus::UNDER_INSPECTION));
        $this->assertFalse($this->transitions->canTransition(AssetStatus::LOST, AssetStatus::IN_USE));
    }

    public function test_assert_can_transition_throws_on_an_illegal_move(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->transitions->assertCanTransition(AssetStatus::DISPOSED, AssetStatus::IN_USE);
    }
}
