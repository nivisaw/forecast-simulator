<?php

declare(strict_types=1);

namespace Tests\Domain\Model;

use App\Domain\Model\Weather;
use App\Domain\Model\Zone;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Model\Zone
 */
class ZoneTest extends TestCase
{
    private function makeZone(
        string $id = 'z1',
        string $name = 'Test Zone',
        array $geometry = ['type' => 'Point', 'coordinates' => [0.0, 0.0]]
    ): Zone {
        return new Zone(id: $id, name: $name, geometry: $geometry);
    }

    public function testImmutableGettersReturnConstructorValues(): void
    {
        $zone = $this->makeZone(id: 'abc', name: 'Downtown');

        $this->assertSame('abc', $zone->getId());
        $this->assertSame('Downtown', $zone->getName());
    }

    public function testDefaultMutableFieldsAreZero(): void
    {
        $zone = $this->makeZone();

        $this->assertSame(0, $zone->getDrivers());
        $this->assertSame(0.0, $zone->getWeatherScore());
        $this->assertSame(1.0, $zone->getBonusMultiplier());
        $this->assertSame(0.0, $zone->getAllocatedBudget());
        $this->assertNull($zone->getWeather());
    }

    public function testSettersUpdateMutableFields(): void
    {
        $zone = $this->makeZone();

        $zone->setDrivers(5);
        $zone->setWeatherScore(0.75);
        $zone->setBonusMultiplier(1.3);
        $zone->setAllocatedBudget(500.0);

        $this->assertSame(5, $zone->getDrivers());
        $this->assertSame(0.75, $zone->getWeatherScore());
        $this->assertSame(1.3, $zone->getBonusMultiplier());
        $this->assertSame(500.0, $zone->getAllocatedBudget());
    }

    public function testSetWeatherStoresWeatherObject(): void
    {
        $zone    = $this->makeZone();
        $weather = new Weather(isRaining: true, temperature: 30.0, windSpeed: 20.0, humidity: 70.0);

        $zone->setWeather($weather);

        $this->assertSame($weather, $zone->getWeather());
    }

    /**
     * @dataProvider driverScoreProvider
     */
    public function testGetDriverScoreFormula(int $drivers, float $expectedScore): void
    {
        $zone = $this->makeZone();
        $zone->setDrivers($drivers);

        $this->assertEqualsWithDelta($expectedScore, $zone->getDriverScore(), 0.0001);
    }

    /** @return array<string, array{int, float}> */
    public function driverScoreProvider(): array
    {
        return [
            'zero drivers gives max score'   => [0,  1.0],
            'five drivers gives 0.5'          => [5,  0.5],
            'ten drivers gives zero'          => [10, 0.0],
            'eleven drivers clamps to zero'   => [11, 0.0],
        ];
    }

    public function testGetOptimizationScoreIsWeightedAverage(): void
    {
        $zone = $this->makeZone();
        $zone->setDrivers(0);        // driverScore = 1.0
        $zone->setWeatherScore(0.8); // weatherScore = 0.8

        // (0.8 * 0.5) + (1.0 * 0.5) = 0.9
        $this->assertEqualsWithDelta(0.9, $zone->getOptimizationScore(), 0.0001);
    }

    public function testToArrayContainsAllRequiredKeys(): void
    {
        $zone  = $this->makeZone();
        $array = $zone->toArray();

        foreach (['id', 'name', 'drivers', 'weather_score', 'driver_score', 'optimization_score', 'bonus_multiplier', 'allocated_budget', 'weather'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    public function testToArrayWeatherIsNullWhenNotSet(): void
    {
        $this->assertNull($this->makeZone()->toArray()['weather']);
    }

    public function testToArrayWeatherIsArrayWhenSet(): void
    {
        $zone    = $this->makeZone();
        $weather = new Weather(isRaining: false, temperature: 20.0, windSpeed: 10.0, humidity: 50.0);
        $zone->setWeather($weather);

        $this->assertIsArray($zone->toArray()['weather']);
    }

    public function testGetGeometryReturnsOriginalArray(): void
    {
        $geometry = ['type' => 'Point', 'coordinates' => [1.23, 4.56]];
        $zone     = $this->makeZone(geometry: $geometry);

        $this->assertSame($geometry, $zone->getGeometry());
    }
}
