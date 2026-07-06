<?php

declare(strict_types=1);

namespace Tests\Domain\Service;

use App\Domain\Model\Weather;
use App\Domain\Service\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Service\ScoreCalculator
 */
class ScoreCalculatorTest extends TestCase
{
    private ScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ScoreCalculator();
    }

    private function makeWeather(
        bool $isRaining = false,
        float $temperature = 22.0,
        float $windSpeed = 8.0,
        float $humidity = 50.0
    ): Weather {
        return new Weather(
            isRaining: $isRaining,
            temperature: $temperature,
            windSpeed: $windSpeed,
            humidity: $humidity
        );
    }

    public function testIdealConditionsReturnZeroScore(): void
    {
        // 22°C, 8 km/h wind, no rain → all deviations are zero
        $score = $this->calculator->calculate($this->makeWeather());

        $this->assertSame(0.0, $score);
    }

    public function testRainAloneContributesFiftyPercent(): void
    {
        // Rain score = 1.0 × 0.5; temp and wind at ideal values
        $score = $this->calculator->calculate($this->makeWeather(isRaining: true));

        $this->assertEqualsWithDelta(0.5, $score, 0.0001);
    }

    public function testTemperatureDeviationNormalizedCorrectly(): void
    {
        // 42°C → deviation = 20, normalized = 20/20 = 1.0 → 1.0 × 0.3 = 0.3
        $score = $this->calculator->calculate($this->makeWeather(temperature: 42.0, windSpeed: 8.0));

        $this->assertEqualsWithDelta(0.3, $score, 0.0001);
    }

    public function testTemperatureDeviationCapsAtOne(): void
    {
        // 62°C → deviation = 40 > 20, normalized clamps to 1.0 → 0.3
        $score = $this->calculator->calculate($this->makeWeather(temperature: 62.0, windSpeed: 8.0));

        $this->assertEqualsWithDelta(0.3, $score, 0.0001);
    }

    public function testWindDeviationNormalizedCorrectly(): void
    {
        // 50 km/h wind → excess = 42, normalized = 42/42 = 1.0 → 1.0 × 0.2 = 0.2
        $score = $this->calculator->calculate($this->makeWeather(windSpeed: 50.0));

        $this->assertEqualsWithDelta(0.2, $score, 0.0001);
    }

    public function testWindBelowIdealContributesZero(): void
    {
        // 5 km/h < 8 km/h ideal → wind excess = 0
        $score = $this->calculator->calculate($this->makeWeather(windSpeed: 5.0));

        $this->assertSame(0.0, $score);
    }

    public function testMaxScoreIsClampedToOne(): void
    {
        // Rain + extreme temp + extreme wind → raw > 1.0, must clamp to 1.0
        $score = $this->calculator->calculate($this->makeWeather(isRaining: true, temperature: 62.0, windSpeed: 100.0));

        $this->assertSame(1.0, $score);
    }

    public function testCombinedRainAndWindScore(): void
    {
        // Rain (0.5) + wind @ 50 km/h (0.2) + ideal temp = 0.7
        $score = $this->calculator->calculate($this->makeWeather(isRaining: true, windSpeed: 50.0));

        $this->assertEqualsWithDelta(0.7, $score, 0.0001);
    }

    public function testScoreIsRoundedToFourDecimalPlaces(): void
    {
        // Just verify result has at most 4 decimal places
        $score = $this->calculator->calculate($this->makeWeather(temperature: 28.5, windSpeed: 20.3));

        $this->assertSame(round($score, 4), $score);
    }
}
