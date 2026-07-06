<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

class GeocodingController
{
    private const MAX_QUERY_LENGTH   = 200;
    private const LAT_MIN            = -90.0;
    private const LAT_MAX            = 90.0;
    private const LON_MIN            = -180.0;
    private const LON_MAX            = 180.0;
    private const GEOCODING_BASE_URL = 'http://api.openweathermap.org/geo/1.0';
    private const NOMINATIM_BASE_URL = 'https://nominatim.openstreetmap.org/search.php';

    public function __construct(private readonly array $config)
    {
        $this->apiKey = $config['weather_api_key'];
    }

    private string $apiKey;

    public function search(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $lat   = $_GET['lat'] ?? null;
        $lon   = $_GET['lon'] ?? null;

        $isCoordinateSearch = ($lat !== null && $lon !== null);

        if (!$isCoordinateSearch && $query === '') {
            $this->sendError(400, 'Query or Lat/Lon is required');
            return;
        }

        if ($isCoordinateSearch) {
            $this->handleCoordinateSearch($lat, $lon);
        } else {
            $this->handleTextSearch($query);
        }
    }

    private function handleCoordinateSearch(mixed $rawLat, mixed $rawLon): void
    {
        if (!$this->areValidCoordinates($rawLat, $rawLon)) {
            $this->sendError(400, 'lat must be in [-90, 90] and lon in [-180, 180]');
            return;
        }

        $lat = (float) $rawLat;
        $lon = (float) $rawLon;

        $url      = self::GEOCODING_BASE_URL . '/reverse?lat=' . $lat . '&lon=' . $lon . '&limit=1&appid=' . urlencode($this->apiKey);
        $response = $this->fetchUrl($url);

        if ($response === null) {
            $this->sendError(500, 'Failed to reach geocoding service');
            return;
        }

        $data = json_decode($response, true);

        if (empty($data)) {
            $this->sendJson([]);
            return;
        }

        $city      = $data[0];
        $cleanName = $this->extractCleanCityName($city);
        $boundary  = $this->fetchUrbanBoundary($cleanName, $city['country'] ?? '');

        // Extract bounding box and geometry from the real boundary so the
        // MockZoneGenerator can produce a city-wide grid instead of circles.
        [$bboxMinLat, $bboxMaxLat, $bboxMinLon, $bboxMaxLon, $boundaryGeom] =
            $this->extractBboxAndGeometry($boundary);

        $generator = new \App\Infrastructure\Service\MockZoneGenerator();
        $zones     = $generator->generate(
            lat: (float) $city['lat'],
            lon: (float) $city['lon'],
            boundaryGeojson: $boundaryGeom,
            bboxMinLat: $bboxMinLat,
            bboxMaxLat: $bboxMaxLat,
            bboxMinLon: $bboxMinLon,
            bboxMaxLon: $bboxMaxLon,
        );

        $this->sendJson([
            'city'     => $city['name'],
            'lat'      => $city['lat'],
            'lon'      => $city['lon'],
            'boundary' => $boundary,
            'zones'    => [
                'type'     => 'FeatureCollection',
                'features' => $zones,
            ],
        ]);
    }

    private function handleTextSearch(string $query): void
    {
        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            $this->sendError(400, 'Query exceeds maximum allowed length');
            return;
        }

        $url      = self::GEOCODING_BASE_URL . '/direct?q=' . urlencode($query) . '&limit=5&appid=' . urlencode($this->apiKey);
        $response = $this->fetchUrl($url);

        if ($response === null) {
            $this->sendError(500, 'Failed to reach geocoding service');
            return;
        }

        $data   = json_decode($response, true);
        $result = [];

        foreach ((array) $data as $city) {
            $result[] = [
                'name' => $city['name'] . ($city['state'] ?? '') . ', ' . $city['country'],
                'lat'  => $city['lat'],
                'lon'  => $city['lon'],
            ];
        }

        $this->sendJson($result);
    }

    /**
     * Queries Nominatim for all polygons matching the city name and returns
     * the one with the smallest bounding-box area (most local / urban polygon).
     *
     * Both the GPS flow and the text-search flow use this method so the city
     * boundary is always drawn identically regardless of how the city was found.
     *
     * @return array<string, mixed>|null
     */
    private function fetchUrbanBoundary(string $cityName, string $country): ?array
    {
        $queryParam   = urlencode($cityName . ($country !== '' ? ', ' . $country : ''));
        $nominatimUrl = self::NOMINATIM_BASE_URL . '?q=' . $queryParam . '&polygon_geojson=1&format=json&limit=10';

        $context  = stream_context_create(['http' => ['header' => "User-Agent: MVP-Budget-Simulator/1.0\r\n"]]);
        $response = @file_get_contents($nominatimUrl, false, $context);

        if ($response === false) {
            return null;
        }

        $items = json_decode($response, true);

        if (!is_array($items) || empty($items)) {
            return null;
        }

        return $this->selectSmallestUrbanPolygon($items, $cityName);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function selectSmallestUrbanPolygon(array $items, string $cityName): ?array
    {
        $bestItem = null;
        $minArea  = PHP_FLOAT_MAX;

        foreach ($items as $item) {
            if (!$this->isPolygonItem($item)) {
                continue;
            }

            $area = $this->boundingBoxArea($item['boundingbox']);

            if ($area < $minArea) {
                $minArea  = $area;
                $bestItem = $item;
            }
        }

        if ($bestItem === null) {
            return null;
        }

        return [
            'type'       => 'Feature',
            'properties' => ['name' => $bestItem['display_name'] ?? $cityName],
            'geometry'   => $bestItem['geojson'],
        ];
    }

    /** @param array<string, mixed> $item */
    private function isPolygonItem(array $item): bool
    {
        if (empty($item['geojson'])) {
            return false;
        }
        if (!in_array($item['geojson']['type'], ['Polygon', 'MultiPolygon'], true)) {
            return false;
        }
        if (!isset($item['boundingbox']) || count($item['boundingbox']) !== 4) {
            return false;
        }

        return true;
    }

    /** @param string[] $boundingBox [latMin, latMax, lonMin, lonMax] */
    private function boundingBoxArea(array $boundingBox): float
    {
        $latDiff = abs((float) $boundingBox[1] - (float) $boundingBox[0]);
        $lonDiff = abs((float) $boundingBox[3] - (float) $boundingBox[2]);

        return $latDiff * $lonDiff;
    }

    /**
     * Extracts the bounding-box coordinates and the raw geometry from a
     * GeoJSON Feature as returned by fetchUrbanBoundary().
     *
     * @param  array<string, mixed>|null $boundary
     * @return array{float|null, float|null, float|null, float|null, array<string, mixed>|null}
     */
    private function extractBboxAndGeometry(?array $boundary): array
    {
        if ($boundary === null || empty($boundary['geometry'])) {
            return [null, null, null, null, null];
        }

        $geometry = $boundary['geometry'];

        // Walk all coordinate pairs to derive a bounding box on-the-fly
        $allCoords = $this->flattenCoordinates(
            $geometry['type'] ?? '',
            $geometry['coordinates'] ?? []
        );

        if (empty($allCoords)) {
            return [null, null, null, null, $geometry];
        }

        $lats = array_column($allCoords, 1);
        $lons = array_column($allCoords, 0);

        return [
            min($lats),
            max($lats),
            min($lons),
            max($lons),
            $geometry,
        ];
    }

    /**
     * Flattens GeoJSON coordinate arrays into a single list of [lon, lat] pairs.
     *
     * @param  mixed[] $coordinates
     * @return float[][]
     */
    private function flattenCoordinates(string $type, array $coordinates): array
    {
        if ($type === 'Polygon') {
            return $coordinates[0] ?? [];
        }

        if ($type === 'MultiPolygon') {
            $flat = [];
            foreach ($coordinates as $polygon) {
                foreach ((array) ($polygon[0] ?? []) as $pair) {
                    $flat[] = $pair;
                }
            }
            return $flat;
        }

        return [];
    }

    /**
     * Extracts the shortest, cleanest city name from OWM reverse-geocoding data.
     *
     * OWM sometimes returns verbose administrative labels like
     * "Bogota Capital District - Municipality". Searching Nominatim with that
     * full label skips the min-area urban polygon selection, so we prefer
     * the short local name stored in certain locale keys.
     *
     * @param array<string, mixed> $cityData
     */
    private function extractCleanCityName(array $cityData): string
    {
        $localNames       = is_array($cityData['local_names'] ?? null) ? $cityData['local_names'] : [];
        $preferredLocales = ['fr', 'de', 'pl', 'nl', 'sv', 'la', 'ku', 'sw'];

        foreach ($preferredLocales as $locale) {
            $candidate = trim((string) ($localNames[$locale] ?? ''));
            if ($candidate !== '' && !str_contains($candidate, '-') && !str_contains($candidate, ' Capital')) {
                return $candidate;
            }
        }

        $rawName = (string) ($cityData['name'] ?? '');

        return trim((string) preg_replace(
            '/\s+(Capital|District|Municipality|Province|Region|Estado|Provincia).*$/i',
            '',
            $rawName
        ));
    }

    private function areValidCoordinates(mixed $lat, mixed $lon): bool
    {
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return false;
        }

        $latFloat = (float) $lat;
        $lonFloat = (float) $lon;

        return $latFloat >= self::LAT_MIN && $latFloat <= self::LAT_MAX
            && $lonFloat >= self::LON_MIN && $lonFloat <= self::LON_MAX;
    }

    private function fetchUrl(string $url): ?string
    {
        $response = @file_get_contents($url);

        return $response !== false ? $response : null;
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
