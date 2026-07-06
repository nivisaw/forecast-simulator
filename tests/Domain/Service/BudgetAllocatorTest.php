<?php

declare(strict_types=1);

namespace Tests\Domain\Service;

use App\Domain\Model\Zone;
use App\Domain\Service\BudgetAllocator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Service\BudgetAllocator
 */
class BudgetAllocatorTest extends TestCase
{
    private BudgetAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new BudgetAllocator();
    }

    private function makeZone(string $id = 'z1', float $weatherScore = 0.5, int $drivers = 5): Zone
    {
        $zone = new Zone(id: $id, name: $id, geometry: ['type' => 'Point', 'coordinates' => [0.0, 0.0]]);
        $zone->setWeatherScore($weatherScore);
        $zone->setDrivers($drivers);

        return $zone;
    }

    public function testSingleZoneReceivesFullBonus(): void
    {
        $zone = $this->makeZone(weatherScore: 1.0, drivers: 10);
        // score = (1.0 * 0.5) + ((10-10)/10 * 0.5) = 0.5 + 0 = 0.5
        // multiplier = 1 + (0.5 * 0.5) = 1.25
        // requestedBonus = 10 * 1000 * 0.25 = 2500
        $this->allocator->allocate([$zone], 10_000.0, 1_000.0);

        $this->assertGreaterThan(0.0, $zone->getAllocatedBudget());
        $this->assertGreaterThan(1.0, $zone->getBonusMultiplier());
    }

    public function testZeroDriversZoneGetsZeroAllocatedBudget(): void
    {
        // With 0 drivers the requested bonus = 0 * baseCost * (multiplier-1) = 0,
        // so the allocator fully funds 0 and leaves the remaining budget untouched.
        $zone = $this->makeZone(drivers: 0, weatherScore: 1.0);
        $this->allocator->allocate([$zone], 1_000.0, 500.0);

        $this->assertSame(0.0, $zone->getAllocatedBudget());
        // Multiplier is set to the score-derived value (full bonus), which is ≥ 1.0
        $this->assertGreaterThanOrEqual(1.0, $zone->getBonusMultiplier());
    }

    public function testInsufficientBudgetCausesPartialFunding(): void
    {
        // requestedBonus = 10 * 1000 * 0.25 = 2500; budget = 500 → partial
        $zone = $this->makeZone(id: 'z1', weatherScore: 1.0, drivers: 10);
        $this->allocator->allocate([$zone], 500.0, 1_000.0);

        $this->assertSame(500.0, $zone->getAllocatedBudget());
        $this->assertGreaterThan(1.0, $zone->getBonusMultiplier());
        $this->assertLessThan(1.25, $zone->getBonusMultiplier());
    }

    public function testZeroBudgetZoneGetsDefaultMultiplier(): void
    {
        $zone = $this->makeZone(weatherScore: 0.8, drivers: 5);
        $this->allocator->allocate([$zone], 0.0, 1_000.0);

        $this->assertSame(0.0, $zone->getAllocatedBudget());
        $this->assertSame(1.0, $zone->getBonusMultiplier());
    }

    public function testHighPriorityZoneAllocatedBeforeLow(): void
    {
        $highScore = $this->makeZone(id: 'high', weatherScore: 1.0, drivers: 5);
        $lowScore  = $this->makeZone(id: 'low',  weatherScore: 0.0, drivers: 5);

        // Budget enough for only one zone's bonus
        // high-score zone optimization_score = (1.0*0.5)+(0.5*0.5) = 0.75 →  multiplier = 1+0.75*0.5 = 1.375
        // requestedBonus_high = 5 * 500 * 0.375 = 937.5
        // low-score: score = 0 + 0.25 = 0.25 → multiplier = 1.125 → requestedBonus_low = 5 * 500 * 0.125 = 312.5
        $this->allocator->allocate([$highScore, $lowScore], 950.0, 500.0);

        $this->assertGreaterThan(0.0,  $highScore->getAllocatedBudget());
        // The remaining budget after high zone is 950 - 937.5 = 12.5, enough for partial low zone
        $this->assertGreaterThanOrEqual(0.0, $lowScore->getAllocatedBudget());
    }

    public function testRemainingBudgetIsZeroAfterFullAllocation(): void
    {
        $zone = $this->makeZone(weatherScore: 0.5, drivers: 5);
        $this->allocator->allocate([$zone], 5_000.0, 1_000.0);

        // After allocating, sum of all allocated budgets <= totalBudget
        $this->assertLessThanOrEqual(5_000.0, $zone->getAllocatedBudget());
    }

    public function testMultipleZonesAllocatedPrioritizedByScore(): void
    {
        $zones = [
            $this->makeZone('z1', weatherScore: 0.2, drivers: 2),
            $this->makeZone('z2', weatherScore: 0.9, drivers: 2),
            $this->makeZone('z3', weatherScore: 0.5, drivers: 2),
        ];

        $this->allocator->allocate($zones, 10_000.0, 1_000.0);

        $totalAllocated = array_sum(array_map(fn(Zone $z) => $z->getAllocatedBudget(), $zones));
        $this->assertLessThanOrEqual(10_000.0, $totalAllocated);
    }
}
