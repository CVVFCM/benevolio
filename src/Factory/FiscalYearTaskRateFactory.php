<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\FiscalYear;
use App\Entity\FiscalYearTaskRate;
use App\Entity\Task;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<FiscalYearTaskRate>
 */
final class FiscalYearTaskRateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FiscalYearTaskRate::class;
    }

    /**
     * Both arguments are required by the constructor, so there is no defaults() that
     * could invent them: a rate for the wrong exercice or the wrong association's task
     * would be silently meaningless.
     */
    public function forTask(FiscalYear $fiscalYear, Task $task): self
    {
        return $this->with(['fiscalYear' => $fiscalYear, 'task' => $task]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }
}
