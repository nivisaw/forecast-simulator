<?php

declare(strict_types=1);

namespace Tests\Domain\Model;

use App\Domain\Model\Weather;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Model\Weather
 */
class WeatherTest extends TestCase
{
    private function makeWeather(
        bool $isRaining = false,
        float $temperature = 20.0,
        float $windSpeed = 10.0,
        float $humidity = 50.0
    ): Weather {
        return new Weather(
            isRaining: $isRaining,
            temperature: $temperature,
            windSpeed: $windSpeed,
            humidity: $humidity
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $weather = $this->makeWeather(isRaining: true, temperature: 35.5, windSpeed: 25.0, humidity: 80.0);

        $this->assertTrue($weather->isRaining());
        $this->assertSame(35.5, $weather->getTemperature());
        $this->assertSame(25.0, $weather->getWindSpeed());
        $this->assertSame(80.0, $weather->getHumidity());
    }

    public function testIsRainingReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->makeWeather()->isRaining());
    }

    public function testToArrayContainsAllKeys(): void
    {
        $weather = $this->makeWeather(isRaining: true, temperature: 22.0, windSpeed: 8.0, humidity: 60.0);
        $array   = $weather->toArray();

        $this->assertArrayHasKey('is_raining', $array);
        $this->assertArrayHasKey('temperature', $array);
        $this->assertArrayHasKey('wind_speed', $array);
        $this->assertArrayHasKey('humidity', $array);
    }

    public function testToArrayValuesMatchGetters(): void
    {
        $weather = $this->makeWeather(isRaining: false, temperature: 15.0, windSpeed: 5.0, humidity: 40.0);
        $array   = $weather->toArray();

        $this->assertFalse($array['is_raining']);
        $this->assertSame(15.0, $array['temperature']);
        $this->assertSame(5.0,  $array['wind_speed']);
        $this->assertSame(40.0, $array['humidity']);
    }
}
