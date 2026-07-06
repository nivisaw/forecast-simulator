<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Model\Zone;
use App\Domain\Model\Weather;
use App\Domain\Repository\ZoneRepositoryInterface;
use App\Domain\Service\BudgetAllocator;
use App\Domain\Service\ScoreCalculator;
use App\Infrastructure\Weather\WeatherService;

class OptimizeBudgetUseCase
{
    public function __construct(
        private readonly ZoneRepositoryInterface $zoneRepository,
        private readonly WeatherService $weatherService,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly BudgetAllocator $budgetAllocator
    ) {
    }

    /**
     * @param array<string, mixed>[]|null $customZones
     * @param array<string, mixed>|null   $fallbackZone
     * @return array<int, array<string, mixed>>
     */
    public function execute(
        float $totalBudget,
        int $totalDrivers,
        float $baseCost,
        ?array $fallbackZone = null,
        ?array $customZones = null
    ): array {
        $zones = $this->resolveZones($customZones, $fallbackZone);

        if (empty($zones)) {
            return [];
        }

        $this->assignDriversToZones($zones, $totalDrivers);
        $this->enrichZonesWithWeather($zones);

        $this->budgetAllocator->allocate($zones, $totalBudget, $baseCost);

        usort($zones, fn(Zone $a, Zone $b) => $b->getOptimizationScore() <=> $a->getOptimizationScore());

        return array_map(fn(Zone $z) => $z->toArray(), $zones);
    }

    /**
     * Resolves the list of zones from custom input, repository, or a single fallback.
     *
     * @param array<string, mixed>[]|null $customZones
     * @param array<string, mixed>|null   $fallbackZone
     * @return Zone[]
     */
    private function resolveZones(?array $customZones, ?array $fallbackZone): array
    {
        if (!empty($customZones)) {
            return $this->buildZonesFromCustomInput($customZones);
        }

        $zones = $this->zoneRepository->findAll();

        if (empty($zones) && $fallbackZone !== null) {
            return [$this->createFallbackZone($fallbackZone)];
        }

        return $zones;
    }

    /**
     * @param array<string, mixed>[] $customZones
     * @return Zone[]
     */
    private function buildZonesFromCustomInput(array $customZones): array
    {
        return array_map(
            fn(array $cz) => new Zone(
                id: (string) $cz['id'],
                name: (string) $cz['name'],
                geometry: (array) $cz['geometry']
            ),
            $customZones
        );
    }

    /**
     * @param array<string, mixed> $fallbackZone
     */
    private function createFallbackZone(array $fallbackZone): Zone
    {
        return new Zone(
            id: (string) ($fallbackZone['id'] ?? 'global'),
            name: (string) ($fallbackZone['name'] ?? 'Current Area'),
            geometry: [
                'type'        => 'Point',
                'coordinates' => [(float) $fallbackZone['lon'], (float) $fallbackZone['lat']],
            ]
        );
    }

    /**
     * Distributes total drivers across zones as evenly as possible,
     * giving the remainder one driver at a time to the first zones.
     *
     * @param Zone[] $zones
     */
    private function assignDriversToZones(array $zones, int $totalDrivers): void
    {
        $zoneCount      = count($zones);
        $driversPerZone = intdiv($totalDrivers, $zoneCount);
        $remainder      = $totalDrivers % $zoneCount;

        foreach ($zones as $index => $zone) {
            $zone->setDrivers($driversPerZone + ($index < $remainder ? 1 : 0));
        }
    }

    /**
     * Fetches weather data for each zone and stores the resulting score.
     *
     * @param Zone[] $zones
     */
    private function enrichZonesWithWeather(array $zones): void
    {
        foreach ($zones as $zone) {
            [$lat, $lon] = $this->extractCoordinates($zone->getGeometry());

            $weather = $this->weatherService->getWeather($lat, $lon);
            $zone->setWeather($weather);
            $zone->setWeatherScore($this->scoreCalculator->calculate($weather));
        }
    }

    /**
     * Extracts a representative [lat, lon] pair from a GeoJSON geometry.
     *
     * @param array<string, mixed> $geometry
     * @return float[] [lat, lon]
     */
    private function extractCoordinates(array $geometry): array
    {
        if ($geometry['type'] === 'Point') {
            return [(float) $geometry['coordinates'][1], (float) $geometry['coordinates'][0]];
        }

        $ring = $geometry['coordinates'][0];

        if ($geometry['type'] === 'MultiPolygon') {
            $ring = $geometry['coordinates'][0][0];
        }

        return [(float) $ring[0][1], (float) $ring[0][0]];
    }
}
