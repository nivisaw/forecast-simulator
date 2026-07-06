<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Model\Zone;
use App\Domain\Repository\ZoneRepositoryInterface;

/**
 * Loads Zone aggregates from a GeoJSON file on disk.
 *
 * Implements ZoneRepositoryInterface so the Application layer
 * never depends on this concrete infrastructure class directly.
 */
class JsonZoneRepository implements ZoneRepositoryInterface
{
    public function __construct(private readonly string $filePath)
    {
    }

    /**
     * @return Zone[]
     */
    public function findAll(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $raw  = file_get_contents($this->filePath);
        $data = json_decode((string) $raw, true);

        if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn(mixed $feature, int $index) => $this->buildZone($feature, $index),
                $data['features'],
                array_keys($data['features'])
            )
        ));
    }

    /**
     * Builds a Zone from a GeoJSON feature array, returning null when
     * the feature is structurally invalid (missing geometry or properties).
     */
    private function buildZone(mixed $feature, int $index): ?Zone
    {
        if (!is_array($feature) || !isset($feature['geometry']) || !is_array($feature['geometry'])) {
            return null;
        }

        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        $id   = (string) ($properties['ID'] ?? $properties['cartodb_id'] ?? $properties['BARRIO'] ?? $properties['nombre'] ?? (string) $index);
        $name = (string) ($properties['BARRIO'] ?? $properties['nombre'] ?? 'Zone ' . $index);

        return new Zone(id: $id, name: $name, geometry: $feature['geometry']);
    }
}
