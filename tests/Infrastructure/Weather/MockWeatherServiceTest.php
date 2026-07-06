<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Weather;

use App\Domain\Model\Weather;
use App\Infrastructure\Weather\MockWeatherService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Infrastructure\Weather\MockWeatherService
 */
class MockWeatherServiceTest extends TestCase
{
    public function testReturnsWeatherWithDefaultValues(): void
    {
        $service = new MockWeatherService();
        $weather = $service->getWeather(0.0, 0.0);

        $this->assertInstanceOf(Weather::class, $weather);
        $this->assertFalse($weather->isRaining());
        $this->assertSame(20.0, $weather->getTemperature());
        $this->assertSame(10.0, $weather->getWindSpeed());
        $this->assertSame(50.0, $weather->getHumidity());
    }

    public function testOverridesReplaceDefaultValues(): void
    {
        $service = new MockWeatherService([
            'is_raining'  => true,
            'temperature' => 35.0,
            'wind_speed'  => 60.0,
            'humidity'    => 90.0,
        ]);

        $weather = $service->getWeather(1.0, 1.0);

        $this->assertTrue($weather->isRaining());
        $this->assertSame(35.0, $weather->getTemperature());
        $this->assertSame(60.0, $weather->getWindSpeed());
        $this->assertSame(90.0, $weather->getHumidity());
    }

    public function testPartialOverridesPreserveRemainingDefaults(): void
    {
        $service = new MockWeatherService(['is_raining' => true]);
        $weather = $service->getWeather(0.0, 0.0);

        $this->assertTrue($weather->isRaining());
        $this->assertSame(20.0, $weather->getTemperature()); // default
    }

    public function testLatLonDoNotInfluenceResult(): void
    {
        $service = new MockWeatherService();

        $w1 = $service->getWeather(10.0, 20.0);
        $w2 = $service->getWeather(-90.0, 180.0);

        $this->assertSame($w1->getTemperature(), $w2->getTemperature());
        $this->assertSame($w1->isRaining(),      $w2->isRaining());
    }

    public function testImplementsWeatherServiceInterface(): void
    {
        $this->assertInstanceOf(
            \App\Infrastructure\Weather\WeatherService::class,
            new MockWeatherService()
        );
    }
}
