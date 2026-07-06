<?php

declare(strict_types=1);

namespace App\Infrastructure\Weather;

use App\Domain\Model\Weather;

interface WeatherService
{
    public function getWeather(float $lat, float $lon): Weather;
}
