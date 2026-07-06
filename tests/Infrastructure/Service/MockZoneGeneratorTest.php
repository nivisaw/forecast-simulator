<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Service;

use App\Infrastructure\Service\MockZoneGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Infrastructure\Service\MockZoneGenerator
 */
class MockZoneGeneratorTest extends TestCase
{
    private MockZoneGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MockZoneGenerator();
    }

    public function testGeneratesExpectedNumberOfFeatures(): void
    {
        // Rings: 3 + 7 + 11 = 21 features
        $features = $this->generator->generate(lat: -34.6, lon: -58.4);

        $this->assertCount(21, $features);
    }

    public function testEveryFeatureIsAGeoJsonPolygon(): void
    {
        foreach ($this->generator->generate(0.0, 0.0) as $feature) {
            $this->assertSame('Feature',  $feature['type']);
            $this->assertSame('Polygon',  $feature['geometry']['type']);
            $this->assertArrayHasKey('coordinates', $feature['geometry']);
        }
    }

    public function testFeatureIdsAreUnique(): void
    {
        $features = $this->generator->generate(0.0, 0.0);
        $ids      = array_column($features, 'id');

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testFeaturePropertiesContainRequiredKeys(): void
    {
        foreach ($this->generator->generate(0.0, 0.0) as $feature) {
            $this->assertArrayHasKey('ID',     $feature['properties']);
            $this->assertArrayHasKey('BARRIO', $feature['properties']);
            $this->assertArrayHasKey('type',   $feature['properties']);
        }
    }

    public function testFeaturePropertyTypeIsMock(): void
    {
        foreach ($this->generator->generate(0.0, 0.0) as $feature) {
            $this->assertSame('mock', $feature['properties']['type']);
        }
    }

    public function testPolygonRingsAreClosedLoops(): void
    {
        foreach ($this->generator->generate(0.0, 0.0) as $feature) {
            $ring = $feature['geometry']['coordinates'][0];
            // First coordinate equals last coordinate (closed ring)
            $this->assertSame($ring[0], $ring[count($ring) - 1]);
        }
    }

    public function testGeneratedFeaturesAreNearCenterPoint(): void
    {
        $lat      = -34.6;
        $lon      = -58.4;
        $features = $this->generator->generate($lat, $lon);

        foreach ($features as $feature) {
            foreach ($feature['geometry']['coordinates'][0] as $point) {
                // All points should be within ~0.15° of center given size=0.08
                $this->assertEqualsWithDelta($lon, $point[0], 0.15, 'Longitude out of expected range');
                $this->assertEqualsWithDelta($lat, $point[1], 0.15, 'Latitude out of expected range');
            }
        }
    }

    public function testCustomSizeScalesOutput(): void
    {
        $bigFeatures   = $this->generator->generate(0.0, 0.0, 0.16);
        $smallFeatures = $this->generator->generate(0.0, 0.0, 0.04);

        // The big grid should have outermost ring coordinates farther from center
        $bigMaxDist   = $this->maxDistanceFromCenter($bigFeatures,   0.0, 0.0);
        $smallMaxDist = $this->maxDistanceFromCenter($smallFeatures, 0.0, 0.0);

        $this->assertGreaterThan($smallMaxDist, $bigMaxDist);
    }

    /** @param array<int, array<string, mixed>> $features */
    private function maxDistanceFromCenter(array $features, float $centerLat, float $centerLon): float
    {
        $max = 0.0;

        foreach ($features as $feature) {
            foreach ($feature['geometry']['coordinates'][0] as $point) {
                $dist = abs($point[0] - $centerLon) + abs($point[1] - $centerLat);
                $max  = max($max, $dist);
            }
        }

        return $max;
    }
}
