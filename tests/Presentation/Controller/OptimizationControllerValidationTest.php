<?php

declare(strict_types=1);

namespace Tests\Presentation\Controller;

use App\Presentation\Controller\OptimizationController;
use PHPUnit\Framework\TestCase;

/**
 * Tests OptimizationController's input validation logic in isolation
 * using reflection to access the private validateCalculateInput() method.
 *
 * @covers \App\Presentation\Controller\OptimizationController
 */
class OptimizationControllerValidationTest extends TestCase
{
    private OptimizationController $controller;

    protected function setUp(): void
    {
        $this->controller = new OptimizationController([
            'weather_api_key'    => 'test-key',
            'default_budget'     => 5000.0,
            'default_drivers'    => 100,
            'default_base_cost'  => 1000.0,
            'max_bonus_multiplier' => 1.5,
        ]);
    }

    private function validate(array $input): ?string
    {
        $method = new \ReflectionMethod(OptimizationController::class, 'validateCalculateInput');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $input);
    }

    public function testValidInputPassesValidation(): void
    {
        $this->assertNull($this->validate([
            'budget'    => 5000.0,
            'drivers'   => 10,
            'base_cost' => 500.0,
        ]));
    }

    public function testEmptyInputPassesValidation(): void
    {
        // Empty input uses config defaults, so no validation errors
        $this->assertNull($this->validate([]));
    }

    public function testNegativeBudgetFailsValidation(): void
    {
        $error = $this->validate(['budget' => -100.0]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('budget', $error);
    }

    public function testZeroBudgetFailsValidation(): void
    {
        $error = $this->validate(['budget' => 0]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('budget', $error);
    }

    public function testNegativeDriversFailsValidation(): void
    {
        $error = $this->validate(['drivers' => -1]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('drivers', $error);
    }

    public function testZeroDriversFailsValidation(): void
    {
        $error = $this->validate(['drivers' => 0]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('drivers', $error);
    }

    public function testNegativeBaseCostFailsValidation(): void
    {
        $error = $this->validate(['base_cost' => -500.0]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('base_cost', $error);
    }

    public function testZeroBaseCostFailsValidation(): void
    {
        $error = $this->validate(['base_cost' => 0.0]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('base_cost', $error);
    }

    public function testFallbackZoneWithValidCoordinatesPassesValidation(): void
    {
        $this->assertNull($this->validate([
            'fallback_zone' => ['lat' => -34.6, 'lon' => -58.4],
        ]));
    }

    public function testFallbackZoneMissingLatFailsValidation(): void
    {
        $error = $this->validate([
            'fallback_zone' => ['lon' => -58.4],
        ]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('fallback_zone', $error);
    }

    public function testFallbackZoneMissingLonFailsValidation(): void
    {
        $error = $this->validate([
            'fallback_zone' => ['lat' => -34.6],
        ]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('fallback_zone', $error);
    }

    public function testFallbackZoneWithNonNumericLatFailsValidation(): void
    {
        $error = $this->validate([
            'fallback_zone' => ['lat' => 'bad-value', 'lon' => -58.4],
        ]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('fallback_zone', $error);
    }

    // -------------------------------------------------------------------------
    // parseFallbackZone — via reflection
    // -------------------------------------------------------------------------

    private function parseFallbackZone(mixed $raw): ?array
    {
        $method = new \ReflectionMethod(OptimizationController::class, 'parseFallbackZone');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $raw);
    }

    public function testParseFallbackZoneReturnsNullForNonArray(): void
    {
        $this->assertNull($this->parseFallbackZone(null));
        $this->assertNull($this->parseFallbackZone('string'));
        $this->assertNull($this->parseFallbackZone(42));
    }

    public function testParseFallbackZoneReturnsNullWhenLatLonMissing(): void
    {
        $this->assertNull($this->parseFallbackZone(['id' => 'test']));
    }

    public function testParseFallbackZoneReturnsNormalizedArray(): void
    {
        $result = $this->parseFallbackZone([
            'id'  => 'myZone',
            'name'=> 'My Zone',
            'lat' => '-34.6',
            'lon' => '-58.4',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('myZone',   $result['id']);
        $this->assertSame('My Zone',  $result['name']);
        $this->assertSame(-34.6, $result['lat']);
        $this->assertSame(-58.4, $result['lon']);
    }

    public function testParseFallbackZoneUsesDefaultsWhenIdAndNameMissing(): void
    {
        $result = $this->parseFallbackZone(['lat' => 0.0, 'lon' => 0.0]);

        $this->assertSame('global',       $result['id']);
        $this->assertSame('Current Area', $result['name']);
    }
}
