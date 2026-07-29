<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function sprintf;

/**
 * Moves valuation rates onto the exercice comptable.
 *
 * Adds `fiscal_year` and its two override tables, and drops the three rate columns
 * lot 5 put on `organization`, `task` and `declaration_action`. One migration, not
 * two, so no deployment ever sits in a state where the rates have moved but the old
 * columns are still there to be read by mistake.
 *
 * DATA LOSS, stated plainly: dropping `declaration_action.hourly_rate_cents`
 * destroys the per-line snapshots. That is the point of the change — a rate belongs
 * to a financial year, not to a row — and in practice every existing snapshot holds
 * the untouched default. But `down()` cannot bring them back: it restores the column,
 * not its contents.
 */
final class Version20260729102315 extends AbstractMigration
{
    /**
     * Kept in step with App\Entity\FiscalYear::DEFAULT_HOURLY_RATE_CENTS. Duplicated
     * on purpose — a migration must describe the schema as it was at this point in
     * time, and must not shift when a constant is retuned later.
     */
    private const int DEFAULT_HOURLY_RATE_CENTS = 1200;

    /** Kept in step with App\Entity\FiscalYear::DEFAULT_MILLI_EUROS_PER_KM. */
    private const int DEFAULT_MILLI_EUROS_PER_KM = 529;

    public function getDescription(): string
    {
        return 'Add fiscal_year with its per-task and per-fiscal-power rate overrides, and drop the rate columns from organization, task and declaration_action';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'CREATE TABLE fiscal_year (id UUID NOT NULL, name VARCHAR(40) NOT NULL, begins_on DATE NOT NULL, ends_on DATE NOT NULL, default_hourly_rate_cents INT DEFAULT %d NOT NULL, default_milli_euros_per_km INT DEFAULT %d NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))',
            self::DEFAULT_HOURLY_RATE_CENTS,
            self::DEFAULT_MILLI_EUROS_PER_KM,
        ));
        $this->addSql('CREATE INDEX IDX_1B2CE62432C8A3DE ON fiscal_year (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_fiscal_year_organization_name ON fiscal_year (organization_id, name)');
        $this->addSql('ALTER TABLE fiscal_year ADD CONSTRAINT FK_1B2CE62432C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // milli_euros_per_km, not cents: the published barème has three decimals
        // (0,529 €/km), so cents would round the law. See App\Entity\FiscalYear.
        $this->addSql('CREATE TABLE fiscal_year_mileage_rate (id UUID NOT NULL, fiscal_power VARCHAR(255) NOT NULL, milli_euros_per_km INT NOT NULL, fiscal_year_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A8E0557463F9139E ON fiscal_year_mileage_rate (fiscal_year_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_fiscal_year_mileage_rate ON fiscal_year_mileage_rate (fiscal_year_id, fiscal_power)');
        $this->addSql('ALTER TABLE fiscal_year_mileage_rate ADD CONSTRAINT FK_A8E0557463F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE fiscal_year_task_rate (id UUID NOT NULL, hourly_rate_cents INT NOT NULL, fiscal_year_id UUID NOT NULL, task_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2559EEB963F9139E ON fiscal_year_task_rate (fiscal_year_id)');
        $this->addSql('CREATE INDEX IDX_2559EEB98DB60186 ON fiscal_year_task_rate (task_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_fiscal_year_task_rate ON fiscal_year_task_rate (fiscal_year_id, task_id)');
        $this->addSql('ALTER TABLE fiscal_year_task_rate ADD CONSTRAINT FK_2559EEB963F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        // NO ACTION, not RESTRICT: PostgreSQL raises SQLSTATE 23001 for RESTRICT and
        // DBAL only maps 23503, so a RESTRICT violation escapes every catch. See
        // App\Entity\DeclarationAction.
        $this->addSql('ALTER TABLE fiscal_year_task_rate ADD CONSTRAINT FK_2559EEB98DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE NO ACTION NOT DEFERRABLE');

        $this->addSql('ALTER TABLE declaration_action DROP hourly_rate_cents');
        $this->addSql('ALTER TABLE organization DROP default_hourly_rate_cents');
        $this->addSql('ALTER TABLE task DROP hourly_rate_cents');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_year_mileage_rate DROP CONSTRAINT FK_A8E0557463F9139E');
        $this->addSql('ALTER TABLE fiscal_year_task_rate DROP CONSTRAINT FK_2559EEB963F9139E');
        $this->addSql('ALTER TABLE fiscal_year_task_rate DROP CONSTRAINT FK_2559EEB98DB60186');
        $this->addSql('ALTER TABLE fiscal_year DROP CONSTRAINT FK_1B2CE62432C8A3DE');
        $this->addSql('DROP TABLE fiscal_year_mileage_rate');
        $this->addSql('DROP TABLE fiscal_year_task_rate');
        $this->addSql('DROP TABLE fiscal_year');

        // WITH a default, unlike what make:migration generated. `ADD ... INT NOT NULL`
        // with no default fails outright on a table that already has rows, which is
        // every database this would ever be run against. The figure is the old
        // organization default; the original snapshots are gone for good.
        $this->addSql(sprintf(
            'ALTER TABLE declaration_action ADD hourly_rate_cents INT DEFAULT %d NOT NULL',
            self::DEFAULT_HOURLY_RATE_CENTS,
        ));
        $this->addSql(sprintf(
            'ALTER TABLE organization ADD default_hourly_rate_cents INT DEFAULT %d NOT NULL',
            self::DEFAULT_HOURLY_RATE_CENTS,
        ));
        $this->addSql('ALTER TABLE task ADD hourly_rate_cents INT DEFAULT NULL');
    }
}
