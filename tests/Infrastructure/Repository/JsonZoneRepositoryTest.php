<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Repository;

use App\Domain\Model\Zone;
use App\Infrastructure\Repository\JsonZoneRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Infrastructure\Repository\JsonZoneRepository
 */
class JsonZoneRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/json_zone_repo_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    private function writeGeoJson(string $filename, mixed $contents): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, is_string($contents) ? $contents : json_encode($contents));

        return $path;
    }

    public function testReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $repo = new JsonZoneRepository('/nonexistent/path/zones.geojson');

        $this->assertSame([], $repo->findAll());
    }

    public function testReturnsEmptyArrayWhenJsonIsInvalid(): void
    {
        $path = $this->writeGeoJson('bad.geojson', 'this is not json {{{');
        $repo = new JsonZoneRepository($path);

        $this->assertSame([], $repo->findAll());
    }

    public function testReturnsEmptyArrayWhenFeaturesKeyMissing(): void
    {
        $path = $this->writeGeoJson('no_features.geojson', ['type' => 'FeatureCollection']);
        $repo = new JsonZoneRepository($path);

        $this->assertSame([], $repo->findAll());
    }

    public function testBuildsZoneFromValidFeature(): void
    {
        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [[
                'type'       => 'Feature',
                'properties' => ['ID' => '42', 'BARRIO' => 'Palermo'],
                'geometry'   => ['type' => 'Point', 'coordinates' => [-58.4, -34.6]],
            ]],
        ];

        $path  = $this->writeGeoJson('valid.geojson', $geojson);
        $zones = (new JsonZoneRepository($path))->findAll();

        $this->assertCount(1, $zones);
        $this->assertInstanceOf(Zone::class, $zones[0]);
        $this->assertSame('42', $zones[0]->getId());
        $this->assertSame('Palermo', $zones[0]->getName());
    }

    public function testSkipsFeaturesMissingGeometry(): void
    {
        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [
                [
                    'type'       => 'Feature',
                    'properties' => ['BARRIO' => 'Valid Zone'],
                    'geometry'   => ['type' => 'Point', 'coordinates' => [0.0, 0.0]],
                ],
                [
                    'type'       => 'Feature',
                    'properties' => ['BARRIO' => 'No Geometry Zone'],
                    // no 'geometry' key
                ],
            ],
        ];

        $path  = $this->writeGeoJson('mixed.geojson', $geojson);
        $zones = (new JsonZoneRepository($path))->findAll();

        $this->assertCount(1, $zones);
        $this->assertSame('Valid Zone', $zones[0]->getName());
    }

    public function testFallsBackToIndexWhenPropertiesHaveNoId(): void
    {
        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [[
                'type'       => 'Feature',
                'properties' => [],
                'geometry'   => ['type' => 'Point', 'coordinates' => [0.0, 0.0]],
            ]],
        ];

        $path  = $this->writeGeoJson('noId.geojson', $geojson);
        $zones = (new JsonZoneRepository($path))->findAll();

        $this->assertCount(1, $zones);
        $this->assertSame('0', $zones[0]->getId());     // falls back to index
        $this->assertSame('Zone 0', $zones[0]->getName()); // falls back to default name
    }

    public function testMultipleFeaturesAreAllLoaded(): void
    {
        $features = array_map(fn(int $i) => [
            'type'       => 'Feature',
            'properties' => ['ID' => (string) $i, 'BARRIO' => 'Zone ' . $i],
            'geometry'   => ['type' => 'Point', 'coordinates' => [0.0, 0.0]],
        ], range(0, 9));

        $path  = $this->writeGeoJson('ten.geojson', ['type' => 'FeatureCollection', 'features' => $features]);
        $zones = (new JsonZoneRepository($path))->findAll();

        $this->assertCount(10, $zones);
    }

    public function testImplementsZoneRepositoryInterface(): void
    {
        $repo = new JsonZoneRepository('/nonexistent');

        $this->assertInstanceOf(
            \App\Domain\Repository\ZoneRepositoryInterface::class,
            $repo
        );
    }
}
