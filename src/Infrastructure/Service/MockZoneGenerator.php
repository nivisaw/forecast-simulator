<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

/**
 * Generates a synthetic set of GeoJSON polygon features arranged in a
 * rectangular grid that covers the full bounding box of a city.
 *
 * This replaces the old concentric-ring approach, which left large portions
 * of the city without zone coverage. The grid is divided into quadrants and
 * sub-zones are named accordingly (Norte, Sur, Este, Oeste, Centro).
 *
 * When the optional $boundaryGeojson is supplied (Polygon or MultiPolygon),
 * cells are only included when their centroid falls inside the real boundary,
 * ensuring no zone is generated outside the city limits.
 */
class MockZoneGenerator
{
    /**
     * Default grid dimensions used when no explicit coverage area is supplied.
     * These represent degrees. ~0.01° ≈ 1.1 km at the equator.
     */
    private const DEFAULT_HALF_SPAN = 0.18; // ~20 km radius from center
    private const COLS              = 10;
    private const ROWS              = 10;

    /** Quadrant name prefixes, ordered [NW, NE, SW, SE] centre label. */
    private const QUADRANT_LABELS = [
        'Norte-Occidente',
        'Norte-Oriente',
        'Sur-Occidente',
        'Sur-Oriente',
        'Centro',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generates a rectangular grid of GeoJSON polygon features.
     *
     * @param float                       $lat             City center latitude
     * @param float                       $lon             City center longitude
     * @param array<string, mixed>|null   $boundaryGeojson GeoJSON geometry of the real city boundary
     *                                                     (Polygon or MultiPolygon). When provided,
     *                                                     cells outside the boundary are skipped.
     * @param float|null                  $bboxMinLat      Bounding-box min latitude  (from Nominatim)
     * @param float|null                  $bboxMaxLat      Bounding-box max latitude
     * @param float|null                  $bboxMinLon      Bounding-box min longitude
     * @param float|null                  $bboxMaxLon      Bounding-box max longitude
     * @return array<int, array<string, mixed>> GeoJSON Feature objects
     */
    public function generate(
        float $lat,
        float $lon,
        ?array $boundaryGeojson = null,
        ?float $bboxMinLat = null,
        ?float $bboxMaxLat = null,
        ?float $bboxMinLon = null,
        ?float $bboxMaxLon = null,
    ): array {
        [$minLat, $maxLat, $minLon, $maxLon] = $this->resolveBoundingBox(
            $lat, $lon,
            $bboxMinLat, $bboxMaxLat, $bboxMinLon, $bboxMaxLon
        );

        // Extract the boundary polygon rings for point-in-polygon tests
        $boundaryRings = $this->extractBoundaryRings($boundaryGeojson);

        $latStep = ($maxLat - $minLat) / self::ROWS;
        $lonStep = ($maxLon - $minLon) / self::COLS;

        $features   = [];
        $totalIndex = 0;

        for ($row = 0; $row < self::ROWS; $row++) {
            for ($col = 0; $col < self::COLS; $col++) {
                $cellMinLat = $minLat + $row * $latStep;
                $cellMaxLat = $cellMinLat + $latStep;
                $cellMinLon = $minLon + $col * $lonStep;
                $cellMaxLon = $cellMinLon + $lonStep;

                $centroidLat = ($cellMinLat + $cellMaxLat) / 2;
                $centroidLon = ($cellMinLon + $cellMaxLon) / 2;

                // Skip cells whose centroid is outside the real boundary
                if (!empty($boundaryRings) && !$this->pointInAnyRing($centroidLat, $centroidLon, $boundaryRings)) {
                    continue;
                }

                $label      = $this->buildZoneLabel($row, $col, self::ROWS, self::COLS, $totalIndex);
                $features[] = $this->buildCellFeature(
                    minLat: $cellMinLat,
                    maxLat: $cellMaxLat,
                    minLon: $cellMinLon,
                    maxLon: $cellMaxLon,
                    label: $label,
                    featureId: 'mock-' . $totalIndex
                );
                $totalIndex++;
            }
        }

        return $features;
    }

    // -------------------------------------------------------------------------
    // Grid helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves the bounding box to use for grid generation.
     *
     * @return float[] [minLat, maxLat, minLon, maxLon]
     */
    private function resolveBoundingBox(
        float $lat,
        float $lon,
        ?float $bboxMinLat,
        ?float $bboxMaxLat,
        ?float $bboxMinLon,
        ?float $bboxMaxLon,
    ): array {
        if (
            $bboxMinLat !== null && $bboxMaxLat !== null
            && $bboxMinLon !== null && $bboxMaxLon !== null
        ) {
            // Use the bounding box as-is; the point-in-polygon filter will
            // discard any cells whose centroid falls outside the real boundary.
            return [
                $bboxMinLat,
                $bboxMaxLat,
                $bboxMinLon,
                $bboxMaxLon,
            ];
        }

        $span = self::DEFAULT_HALF_SPAN;
        return [$lat - $span, $lat + $span, $lon - $span, $lon + $span];
    }

    /**
     * Names a cell based roughly on its position relative to the grid center.
     */
    private function buildZoneLabel(int $row, int $col, int $rows, int $cols, int $index): string
    {
        $midRow = ($rows - 1) / 2;
        $midCol = ($cols - 1) / 2;

        // True centre cell
        if (abs($row - $midRow) <= 0.5 && abs($col - $midCol) <= 0.5) {
            return 'Centro ' . ($index + 1);
        }

        $ns = $row < $midRow ? 'Norte' : 'Sur';
        $eo = $col < $midCol ? 'Occidente' : 'Oriente';

        if (abs($col - $midCol) <= 0.5) {
            $eo = 'Centro';
        }
        if (abs($row - $midRow) <= 0.5) {
            $ns = 'Centro';
        }

        return $ns . '-' . $eo . ' ' . ($index + 1);
    }

    /**
     * Builds a rectangular GeoJSON feature for one grid cell.
     *
     * @return array<string, mixed>
     */
    private function buildCellFeature(
        float $minLat,
        float $maxLat,
        float $minLon,
        float $maxLon,
        string $label,
        string $featureId,
    ): array {
        // GeoJSON coordinates are [lon, lat]
        $coords = [
            [$minLon, $minLat],
            [$maxLon, $minLat],
            [$maxLon, $maxLat],
            [$minLon, $maxLat],
            [$minLon, $minLat], // close ring
        ];

        return [
            'type' => 'Feature',
            'id'   => $featureId,
            'properties' => [
                'ID'     => $featureId,
                'BARRIO' => $label,
                'type'   => 'mock',
            ],
            'geometry' => [
                'type'        => 'Polygon',
                'coordinates' => [$coords],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Boundary / Point-in-Polygon helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts all outer polygon rings from a GeoJSON Polygon or MultiPolygon
     * geometry object. Returns an empty array when the geometry is null or invalid.
     *
     * A "ring" is a flat array of [lon, lat] pairs.
     *
     * @param array<string, mixed>|null $geojson
     * @return float[][][]
     */
    private function extractBoundaryRings(?array $geojson): array
    {
        if ($geojson === null || empty($geojson['type'])) {
            return [];
        }

        $rings       = [];
        $coordinates = $geojson['coordinates'] ?? [];

        if ($geojson['type'] === 'Polygon') {
            if (!empty($coordinates[0])) {
                $rings[] = $coordinates[0];
            }
        } elseif ($geojson['type'] === 'MultiPolygon') {
            foreach ((array) $coordinates as $polygon) {
                if (!empty($polygon[0])) {
                    $rings[] = $polygon[0];
                }
            }
        }

        return $rings;
    }


    /**
     * Returns true when the given point is inside at least one of the provided rings.
     *
     * Uses the ray-casting algorithm (Jordan curve theorem).
     *
     * @param float[][][] $rings
     */
    private function pointInAnyRing(float $lat, float $lon, array $rings): bool
    {
        foreach ($rings as $ring) {
            if ($this->pointInRing($lat, $lon, $ring)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ray-casting point-in-polygon test for a single ring.
     *
     * @param float[][] $ring Array of [lon, lat] pairs
     */
    private function pointInRing(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n      = count($ring);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $ring[$i][0]; // lon
            $yi = (float) $ring[$i][1]; // lat
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            if ((($yi > $lat) !== ($yj > $lat)) &&
                ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)
            ) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
