<?php

declare(strict_types=1);

namespace App\Infrastructure\Weather;

use App\Domain\Model\Weather;

class MockWeatherService implements WeatherService
{
    private array $data = [];

    public function __construct(array $overrides = [])
    {
        $this->data = array_merge([
            'is_raining' => false,
            'temperature' => 20.0,
            'wind_speed' => 10.0,
            'humidity' => 50.0
        ], $overrides);
    }

    public function getWeather(float $lat, float $lon): Weather
    {
        return new Weather(
            isRaining: (bool) $this->data['is_raining'],
            temperature: (float) $this->data['temperature'],
            windSpeed: (float) $this->data['wind_speed'],
            humidity: (float) $this->data['humidity']
        );
    }
}
