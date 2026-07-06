<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Zone;

/**
 * Contract for any storage mechanism that provides Zone aggregates.
 *
 * Following the Dependency Inversion Principle, the Application layer
 * depends on this domain-level abstraction rather than on any concrete
 * infrastructure implementation.
 */
interface ZoneRepositoryInterface
{
    /**
     * Returns all available Zone aggregates.
     *
     * @return Zone[]
     */
    public function findAll(): array;
}
