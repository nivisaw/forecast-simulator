<?php

declare(strict_types=1);

namespace App\Domain\Model;

class Zone
{
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $geometry,
        private int $drivers = 0,
        private float $weatherScore = 0.0,
        private float $bonusMultiplier = 1.0,
        private float $allocatedBudget = 0.0,
        private ?Weather $weather = null
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGeometry(): array
    {
        return $this->geometry;
    }

    public function getDrivers(): int
    {
        return $this->drivers;
    }

    public function setDrivers(int $drivers): void
    {
        $this->drivers = $drivers;
    }

    public function getWeatherScore(): float
    {
        return $this->weatherScore;
    }

    public function setWeatherScore(float $score): void
    {
        $this->weatherScore = $score;
    }

    public function getBonusMultiplier(): float
    {
        return $this->bonusMultiplier;
    }

    public function setBonusMultiplier(float $multiplier): void
    {
        $this->bonusMultiplier = $multiplier;
    }

    public function getAllocatedBudget(): float
    {
        return $this->allocatedBudget;
    }

    public function setAllocatedBudget(float $budget): void
    {
        $this->allocatedBudget = $budget;
    }

    public function getWeather(): ?Weather
    {
        return $this->weather;
    }

    public function setWeather(Weather $weather): void
    {
        $this->weather = $weather;
    }

    public function getDriverScore(): float
    {
        return max(0, (10 - $this->drivers) / 10.0);
    }

    public function getOptimizationScore(): float
    {
        return ($this->weatherScore * 0.5) + ($this->getDriverScore() * 0.5);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'drivers' => $this->drivers,
            'weather_score' => $this->weatherScore,
            'driver_score' => $this->getDriverScore(),
            'optimization_score' => $this->getOptimizationScore(),
            'bonus_multiplier' => $this->bonusMultiplier,
            'allocated_budget' => $this->allocatedBudget,
            'weather' => $this->weather ? $this->weather->toArray() : null,
        ];
    }
}
