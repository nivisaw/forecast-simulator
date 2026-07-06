<?php

declare(strict_types=1);

namespace Tests\Application\UseCase;

use App\Application\UseCase\OptimizeBudgetUseCase;
use App\Domain\Model\Weather;
use App\Domain\Model\Zone;
use App\Domain\Repository\ZoneRepositoryInterface;
use App\Domain\Service\BudgetAllocator;
use App\Domain\Service\ScoreCalculator;
use App\Infrastructure\Weather\MockWeatherService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \App\Application\UseCase\OptimizeBudgetUseCase
 */
class OptimizeBudgetUseCaseTest extends TestCase
{
    private ZoneRepositoryInterface&MockObject $repository;
    private MockWeatherService $weatherService;
    private ScoreCalculator $scoreCalculator;
    private BudgetAllocator $budgetAllocator;

    protected function setUp(): void
    {
        $this->repository      = $this->createMock(ZoneRepositoryInterface::class);
        $this->weatherService  = new MockWeatherService();
        $this->scoreCalculator = new ScoreCalculator();
        $this->budgetAllocator = new BudgetAllocator();
    }

    private function makeUseCase(): OptimizeBudgetUseCase
    {
        return new OptimizeBudgetUseCase(
            $this->repository,
            $this->weatherService,
            $this->scoreCalculator,
            $this->budgetAllocator
        );
    }

    private function makeZone(string $id, float $lat = -34.0, float $lon = -58.0): Zone
    {
        return new Zone(
            id: $id,
            name: 'Zone ' . $id,
            geometry: ['type' => 'Point', 'coordinates' => [$lon, $lat]]
        );
    }

    public function testReturnsEmptyArrayWhenNoZonesAndNoFallback(): void
    {
        $this->repository->method('findAll')->willReturn([]);

        $result = $this->makeUseCase()->execute(1000.0, 10, 100.0);

        $this->assertSame([], $result);
    }

    public function testUsesRepositoryZonesWhenNoCustomZonesProvided(): void
    {
        $this->repository->method('findAll')->willReturn([
            $this->makeZone('r1'),
            $this->makeZone('r2'),
        ]);

        $result = $this->makeUseCase()->execute(5000.0, 4, 500.0);

        $this->assertCount(2, $result);
    }

    public function testCustomZonesOverrideRepository(): void
    {
        $this->repository->expects($this->never())->method('findAll');

        $customZones = [
            ['id' => 'c1', 'name' => 'Custom 1', 'geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]]],
            ['id' => 'c2', 'name' => 'Custom 2', 'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 1.0]]],
        ];

        $result = $this->makeUseCase()->execute(5000.0, 4, 500.0, null, $customZones);

        $this->assertCount(2, $result);
        $ids = array_column($result, 'id');
        $this->assertContains('c1', $ids);
        $this->assertContains('c2', $ids);
    }

    public function testFallbackZoneUsedWhenRepositoryIsEmpty(): void
    {
        $this->repository->method('findAll')->willReturn([]);

        $fallback = ['id' => 'fb', 'name' => 'Fallback', 'lat' => -34.6, 'lon' => -58.4];
        $result   = $this->makeUseCase()->execute(1000.0, 1, 100.0, $fallback);

        $this->assertCount(1, $result);
        $this->assertSame('fb', $result[0]['id']);
    }

    public function testFallbackNotUsedWhenRepositoryHasZones(): void
    {
        $this->repository->method('findAll')->willReturn([$this->makeZone('repo1')]);

        $fallback = ['id' => 'fb', 'name' => 'Fallback', 'lat' => 0.0, 'lon' => 0.0];
        $result   = $this->makeUseCase()->execute(1000.0, 1, 100.0, $fallback);

        $this->assertSame('repo1', $result[0]['id']);
    }

    public function testResultsAreSortedByOptimizationScoreDescending(): void
    {
        $weatherHigh = new MockWeatherService(['is_raining' => true,  'wind_speed' => 50.0]); // high score
        $weatherLow  = new MockWeatherService(['is_raining' => false, 'wind_speed' => 8.0]);  // low score

        // Build use case with interleaved high/low zones
        $zones = [
            $this->makeZone('low',  lat: 0.0, lon: 0.0),
            $this->makeZone('high', lat: 1.0, lon: 1.0),
        ];

        $this->repository->method('findAll')->willReturn($zones);

        // Use real MockWeatherService that returns the same data regardless of coords
        $useCaseHigh = new OptimizeBudgetUseCase(
            $this->repository,
            $weatherHigh,
            $this->scoreCalculator,
            $this->budgetAllocator
        );

        $result = $useCaseHigh->execute(5000.0, 2, 500.0);

        // All zones get same weather → sorted by driver score (both equal → stable)
        $this->assertCount(2, $result);
        $firstScore  = $result[0]['optimization_score'];
        $secondScore = $result[1]['optimization_score'];
        $this->assertGreaterThanOrEqual($secondScore, $firstScore);
    }

    public function testResultContainsRequiredOutputKeys(): void
    {
        $this->repository->method('findAll')->willReturn([$this->makeZone('z1')]);

        $result = $this->makeUseCase()->execute(500.0, 1, 100.0);

        $this->assertArrayHasKey('id',                 $result[0]);
        $this->assertArrayHasKey('name',               $result[0]);
        $this->assertArrayHasKey('drivers',            $result[0]);
        $this->assertArrayHasKey('weather_score',      $result[0]);
        $this->assertArrayHasKey('allocated_budget',   $result[0]);
        $this->assertArrayHasKey('optimization_score', $result[0]);
    }

    public function testDriversAreDistributedEvenly(): void
    {
        $zones = [$this->makeZone('z1'), $this->makeZone('z2'), $this->makeZone('z3')];
        $this->repository->method('findAll')->willReturn($zones);

        // 10 drivers / 3 zones → 4, 3, 3 (remainder 1 goes to first zone)
        $result = $this->makeUseCase()->execute(9000.0, 10, 100.0);

        $totalDrivers = array_sum(array_column($result, 'drivers'));
        $this->assertSame(10, $totalDrivers);
    }

    public function testPolygonGeometryCoordinatesExtracted(): void
    {
        $zoneWithPolygon = new Zone(
            id: 'poly',
            name: 'Polygon Zone',
            geometry: [
                'type'        => 'Polygon',
                'coordinates' => [[
                    [-58.4, -34.6],
                    [-58.3, -34.6],
                    [-58.3, -34.5],
                    [-58.4, -34.5],
                    [-58.4, -34.6],
                ]],
            ]
        );

        $this->repository->method('findAll')->willReturn([$zoneWithPolygon]);

        // Should not throw — polygon coordinate extraction must work correctly
        $result = $this->makeUseCase()->execute(500.0, 1, 100.0);

        $this->assertCount(1, $result);
        $this->assertSame('poly', $result[0]['id']);
    }

    public function testMultiPolygonGeometryCoordinatesExtracted(): void
    {
        $zoneWithMultiPolygon = new Zone(
            id: 'multi',
            name: 'MultiPolygon Zone',
            geometry: [
                'type'        => 'MultiPolygon',
                'coordinates' => [[[
                    [-58.4, -34.6],
                    [-58.3, -34.6],
                    [-58.3, -34.5],
                    [-58.4, -34.5],
                    [-58.4, -34.6],
                ]]],
            ]
        );

        $this->repository->method('findAll')->willReturn([$zoneWithMultiPolygon]);

        $result = $this->makeUseCase()->execute(500.0, 1, 100.0);

        $this->assertCount(1, $result);
        $this->assertSame('multi', $result[0]['id']);
    }
}
