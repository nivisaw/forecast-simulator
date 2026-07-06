<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Zone;

class BudgetAllocator
{
    /**
     * Allocates budget greedily based on weather severity.
     * 
     * @param Zone[] $zones
     * @param float $totalBudget
     * @param float $baseCost
     * @param float $maxBonusFactor (e.g. 0.5 for 1.5x)
     */
    public function allocate(array $zones, float $totalBudget, float $baseCost, float $maxBonusFactor = 0.5): void
    {
        // 1. Sort zones by optimization score DESC
        usort($zones, fn(Zone $a, Zone $b) => $b->getOptimizationScore() <=> $a->getOptimizationScore());
        
        $remainingBudget = $totalBudget;
        
        foreach ($zones as $zone) {
            if ($remainingBudget <= 0) {
                $zone->setBonusMultiplier(1.0);
                $zone->setAllocatedBudget(0.0);
                continue;
            }
            
            $score = $zone->getOptimizationScore();
            $multiplier = 1.0 + ($score * $maxBonusFactor);
            
            // Total bonus cost for this zone = drivers * base_cost * (multiplier - 1)
            $requestedBonus = $zone->getDrivers() * $baseCost * ($multiplier - 1.0);
            
            if ($remainingBudget >= $requestedBonus) {
                // Fully fund
                $zone->setBonusMultiplier($multiplier);
                $zone->setAllocatedBudget($requestedBonus);
                $remainingBudget -= $requestedBonus;
            } else {
                // Partially fund
                // new_multiplier = 1 + (remaining / (drivers * base_cost))
                if ($zone->getDrivers() > 0 && $baseCost > 0) {
                    $possibleBonusFactor = $remainingBudget / ($zone->getDrivers() * $baseCost);
                    $newMultiplier = 1.0 + $possibleBonusFactor;
                } else {
                    $newMultiplier = 1.0;
                }
                
                $zone->setBonusMultiplier($newMultiplier);
                $zone->setAllocatedBudget($remainingBudget);
                $remainingBudget = 0;
            }
        }
    }
}
