<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\UseCase\OptimizeBudgetUseCase;
use App\Domain\Service\BudgetAllocator;
use App\Domain\Service\ScoreCalculator;
use App\Infrastructure\Repository\JsonZoneRepository;
use App\Infrastructure\Weather\MockWeatherService;
use App\Infrastructure\Weather\OpenWeatherMapService;

class OptimizationController
{
    public function __construct(private readonly array $config)
    {
    }

    public function calculate(): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?? [];

        $validationError = $this->validateCalculateInput($input);
        if ($validationError !== null) {
            $this->sendError(400, $validationError);
            return;
        }

        $budget    = (float) ($input['budget']    ?? $this->config['default_budget']);
        $drivers   = (int)   ($input['drivers']   ?? $this->config['default_drivers']);
        $baseCost  = (float) ($input['base_cost'] ?? $this->config['default_base_cost']);
        $useMock   = (bool)  ($input['use_mock']  ?? true);

        $weatherService = $useMock
            ? new MockWeatherService($input['mock_weather'] ?? [])
            : new OpenWeatherMapService($this->config['weather_api_key']);

        $useCase = new OptimizeBudgetUseCase(
            new JsonZoneRepository(__DIR__ . '/../../../data/caba_barrios.geojson'),
            $weatherService,
            new ScoreCalculator(),
            new BudgetAllocator()
        );

        $fallbackZone = $this->parseFallbackZone($input['fallback_zone'] ?? null);
        $customZones  = $input['custom_zones'] ?? null;

        try {
            $results = $useCase->execute($budget, $drivers, $baseCost, $fallbackZone, $customZones);

            $this->sendJson([
                'status'           => 'success',
                'data'             => $results,
                'total_budget'     => $budget,
                'remaining_budget' => $budget - array_sum(array_column($results, 'allocated_budget')),
            ]);
        } catch (\Exception $e) {
            $this->sendError(500, $e->getMessage());
        }
    }

    public function getZones(): void
    {
        $repo  = new JsonZoneRepository(__DIR__ . '/../../../data/caba_barrios.geojson');
        $zones = $repo->findAll();

        $this->sendJson([
            'type'     => 'FeatureCollection',
            'features' => array_map(fn($z) => [
                'type'       => 'Feature',
                'id'         => $z->getId(),
                'properties' => [
                    'ID'     => $z->getId(),
                    'BARRIO' => $z->getName(),
                ],
                'geometry'   => $z->getGeometry(),
            ], $zones),
        ]);
    }

    /**
     * Validates the raw calculate request payload.
     *
     * Returns an error message string on validation failure, null on success.
     */
    private function validateCalculateInput(array $input): ?string
    {
        if (isset($input['budget']) && (float) $input['budget'] <= 0) {
            return 'budget must be a positive number';
        }

        if (isset($input['drivers']) && (int) $input['drivers'] <= 0) {
            return 'drivers must be a positive integer';
        }

        if (isset($input['base_cost']) && (float) $input['base_cost'] <= 0) {
            return 'base_cost must be a positive number';
        }

        if (isset($input['fallback_zone'])) {
            $fz = $input['fallback_zone'];
            if (!isset($fz['lat'], $fz['lon']) || !is_numeric($fz['lat']) || !is_numeric($fz['lon'])) {
                return 'fallback_zone must contain numeric lat and lon fields';
            }
        }

        return null;
    }

    /**
     * Safely parses the fallback_zone input, returning null if absent or invalid.
     *
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    private function parseFallbackZone(mixed $raw): ?array
    {
        if (!is_array($raw) || !isset($raw['lat'], $raw['lon'])) {
            return null;
        }

        return [
            'id'  => $raw['id']   ?? 'global',
            'name'=> $raw['name'] ?? 'Current Area',
            'lat' => (float) $raw['lat'],
            'lon' => (float) $raw['lon'],
        ];
    }

    /** @param mixed $payload */
    private function sendJson(mixed $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function sendError(int $statusCode, string $message): void
    {
        header('Content-Type: application/json', true, $statusCode);
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
}
