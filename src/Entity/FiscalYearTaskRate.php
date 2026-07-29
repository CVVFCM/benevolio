<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An hourly rate that applies to one task for one exercice.
 *
 * A row rather than a column on Task, because a task's rate is not a property of the
 * task: it holds for a financial year and changes with it. And a row rather than
 * columns on FiscalYear, because overrides are sparse — most associations set one
 * rate for everything and never create any of these.
 *
 * TENANCY — the same deliberate exception App\Entity\DeclarationAction makes: this is
 * NOT TenantAware, so OrganizationFilter does not scope it. It is reachable only
 * through its FiscalYear, which is scoped. Anything querying this table directly must
 * scope itself through the fiscal year.
 *
 * Not final: Doctrine needs to subclass entities for lazy-loading proxies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fiscal_year_task_rate')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_year_task_rate', columns: ['fiscal_year_id', 'task_id'])]
class FiscalYearTaskRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: FiscalYear::class, inversedBy: 'taskRates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FiscalYear $fiscalYear;

    /**
     * NO ACTION, like DeclarationAction's own link to Task: a task that has been
     * priced for an exercice must not be silently deletable. See that entity for why
     * NO ACTION and not RESTRICT.
     */
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'NO ACTION')]
    private Task $task;

    /** In cents, like every other amount here. */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive(message: 'Le taux horaire doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(
        value: FiscalYear::MAX_HOURLY_RATE_CENTS,
        message: 'Le taux horaire ne peut pas dépasser {{ compared_value }} centimes.',
    )]
    private int $hourlyRateCents = FiscalYear::DEFAULT_HOURLY_RATE_CENTS;

    public function __construct(FiscalYear $fiscalYear, Task $task)
    {
        $this->id = Uuid::v7();
        $this->fiscalYear = $fiscalYear;
        $this->task = $task;

        $fiscalYear->addTaskRate($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getHourlyRateCents(): int
    {
        return $this->hourlyRateCents;
    }

    public function setHourlyRateCents(int $hourlyRateCents): self
    {
        $this->hourlyRateCents = $hourlyRateCents;

        return $this;
    }
}
