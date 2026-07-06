<?php

declare(strict_types=1);

namespace Tests\Presentation\Controller;

use App\Presentation\Controller\GeocodingController;
use PHPUnit\Framework\TestCase;

/**
 * Tests the input validation logic of GeocodingController in isolation.
 *
 * HTTP output headers cannot be tested in CLI, so we isolate the validation
 * by inspecting thrown exceptions or by using output buffering where appropriate.
 *
 * @covers \App\Presentation\Controller\GeocodingController
 */
class GeocodingControllerValidationTest extends TestCase
{
    private GeocodingController $controller;

    protected function setUp(): void
    {
        $this->controller = new GeocodingController([
            'weather_api_key' => 'test-key-12345',
        ]);
    }

    // -------------------------------------------------------------------------
    // areValidCoordinates — tested via reflection (pure logic, no HTTP side-effects)
    // -------------------------------------------------------------------------

    /** @return array<string, array{mixed, mixed, bool}> */
    public function coordinateValidationProvider(): array
    {
        return [
            'valid center'              => [0.0,   0.0,    true],
            'valid north pole'          => [90.0,  0.0,    true],
            'valid south pole'          => [-90.0, 0.0,    true],
            'valid date line east'      => [0.0,   180.0,  true],
            'valid date line west'      => [0.0,   -180.0, true],
            'valid CABA'                => [-34.6, -58.4,  true],
            'lat above max'             => [90.1,  0.0,    false],
            'lat below min'             => [-90.1, 0.0,    false],
            'lon above max'             => [0.0,   180.1,  false],
            'lon below min'             => [0.0,   -180.1, false],
            'non-numeric lat string'    => ['abc', 0.0,    false],
            'non-numeric both'          => ['x',  'y',    false],
            'injection attempt lat'     => ['1.0&appid=evil', 0.0, false],
            'null lat'                  => [null,  0.0,    false],
            'array lat'                 => [[1],   0.0,    false],
        ];
    }

    /**
     * @dataProvider coordinateValidationProvider
     */
    public function testAreValidCoordinates(mixed $lat, mixed $lon, bool $expected): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'areValidCoordinates');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $lat, $lon);

        $this->assertSame($expected, $result);
    }

    // -------------------------------------------------------------------------
    // extractCleanCityName — pure logic, no HTTP side-effects
    // -------------------------------------------------------------------------

    public function testExtractCleanCityNamePrefersShortLocale(): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'extractCleanCityName');
        $method->setAccessible(true);

        $cityData = [
            'name'        => 'Bogota Capital District - Municipality',
            'local_names' => ['fr' => 'Bogota', 'en' => 'Bogota Capital District'],
        ];

        $result = $method->invoke($this->controller, $cityData);

        $this->assertSame('Bogota', $result);
    }

    public function testExtractCleanCityNameSkipsLocaleWithHyphen(): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'extractCleanCityName');
        $method->setAccessible(true);

        $cityData = [
            'name'        => 'São Paulo',
            'local_names' => ['fr' => 'São Paulo-some-suffix'],
        ];

        $result = $method->invoke($this->controller, $cityData);

        // Hyphened locale is rejected → falls back to pruned raw name
        $this->assertSame('São Paulo', $result);
    }

    public function testExtractCleanCityNameStripsAdminSuffix(): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'extractCleanCityName');
        $method->setAccessible(true);

        $cityData = [
            'name'        => 'Madrid Province',
            'local_names' => [],
        ];

        $result = $method->invoke($this->controller, $cityData);

        $this->assertSame('Madrid', $result);
    }

    public function testExtractCleanCityNameHandlesMissingLocalNames(): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'extractCleanCityName');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, ['name' => 'Lima']);

        $this->assertSame('Lima', $result);
    }

    // -------------------------------------------------------------------------
    // isPolygonItem — pure logic
    // -------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, bool}> */
    public function polygonItemProvider(): array
    {
        return [
            'valid Polygon' => [[
                'geojson'     => ['type' => 'Polygon'],
                'boundingbox' => ['0', '1', '0', '1'],
            ], true],
            'valid MultiPolygon' => [[
                'geojson'     => ['type' => 'MultiPolygon'],
                'boundingbox' => ['0', '1', '0', '1'],
            ], true],
            'missing geojson' => [['boundingbox' => ['0', '1', '0', '1']], false],
            'wrong geometry type' => [[
                'geojson'     => ['type' => 'Point'],
                'boundingbox' => ['0', '1', '0', '1'],
            ], false],
            'missing boundingbox' => [['geojson' => ['type' => 'Polygon']], false],
            'wrong boundingbox length' => [[
                'geojson'     => ['type' => 'Polygon'],
                'boundingbox' => ['0', '1'],
            ], false],
        ];
    }

    /**
     * @dataProvider polygonItemProvider
     * @param array<string, mixed> $item
     */
    public function testIsPolygonItem(array $item, bool $expected): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'isPolygonItem');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->controller, $item));
    }

    // -------------------------------------------------------------------------
    // boundingBoxArea — pure logic
    // -------------------------------------------------------------------------

    public function testBoundingBoxAreaCalculation(): void
    {
        $method = new \ReflectionMethod(GeocodingController::class, 'boundingBoxArea');
        $method->setAccessible(true);

        // latMin=0, latMax=2, lonMin=0, lonMax=3 → area = 2 * 3 = 6
        $area = $method->invoke($this->controller, ['0', '2', '0', '3']);

        $this->assertEqualsWithDelta(6.0, $area, 0.0001);
    }
}
