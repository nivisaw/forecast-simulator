<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Weather;

class ScoreCalculator
{
    private const IDEAL_TEMPERATURE_CELSIUS = 22.0;
    private const TEMPERATURE_NORMALIZATION_RANGE = 20.0;

    private const IDEAL_WIND_SPEED_KMH = 8.0;
    private const WIND_NORMALIZATION_RANGE = 42.0;

    private const WEIGHT_RAIN        = 0.5;
    private const WEIGHT_TEMPERATURE = 0.3;
    private const WEIGHT_WIND        = 0.2;

    /**
     * Calculates a normalized weather-severity score in the [0, 1] range.
     *
     * Formula: (Rain × 0.5) + (NormalizedTemp × 0.3) + (NormalizedWind × 0.2)
     */
    public function calculate(Weather $weather): float
    {
        $rainScore        = $this->rainScore($weather);
        $normalizedTemp   = $this->normalizedTemperatureDeviation($weather);
        $normalizedWind   = $this->normalizedWindDeviation($weather);

        $score = ($rainScore * self::WEIGHT_RAIN)
               + ($normalizedTemp * self::WEIGHT_TEMPERATURE)
               + ($normalizedWind * self::WEIGHT_WIND);

        return round(min($score, 1.0), 4);
    }

    private function rainScore(Weather $weather): float
    {
        return $weather->isRaining() ? 1.0 : 0.0;
    }

    private function normalizedTemperatureDeviation(Weather $weather): float
    {
        $deviation = abs(self::IDEAL_TEMPERATURE_CELSIUS - $weather->getTemperature());

        return min($deviation / self::TEMPERATURE_NORMALIZATION_RANGE, 1.0);
    }

    private function normalizedWindDeviation(Weather $weather): float
    {
        $excessSpeed = max(0.0, $weather->getWindSpeed() - self::IDEAL_WIND_SPEED_KMH);

        return min($excessSpeed / self::WIND_NORMALIZATION_RANGE, 1.0);
    }
}
