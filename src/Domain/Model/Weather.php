<?php

declare(strict_types=1);

namespace App\Domain\Model;

class Weather
{
    public function __construct(
        private readonly bool $isRaining,
        private readonly float $temperature,
        private readonly float $windSpeed,
        private readonly float $humidity
    ) {
    }

    public function isRaining(): bool
    {
        return $this->isRaining;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getWindSpeed(): float
    {
        return $this->windSpeed;
    }

    public function getHumidity(): float
    {
        return $this->humidity;
    }

    public function toArray(): array
    {
        return [
            'is_raining' => $this->isRaining,
            'temperature' => $this->temperature,
            'wind_speed' => $this->windSpeed,
            'humidity' => $this->humidity,
        ];
    }
}
