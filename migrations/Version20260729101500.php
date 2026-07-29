<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames event_type to task and gives tasks, organizations and filed lines an
 * hourly rate.
 *
 * WRITTEN BY HAND, and it has to be. `make:migration` sees a table that vanished
 * and one that appeared, so it emits DROP TABLE event_type — every task, and with
 * it the label every filed declaration was filed under. A rename is invisible to a
 * schema diff.
 *
 * The constraint and index renames are not cosmetic: Doctrine derives those names
 * from the table and column names, so leaving them behind makes
 * `doctrine:schema:validate` report a permanent phantom diff.
 */
final class Version20260729101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename event_type to task, and add hourly rates on task, organization and declaration_action';
    }

    public function up(Schema $schema): void
    {
        // 1. The table, with its data. RENAME carries the rows, the primary key and
        //    the incoming foreign key with it.
        $this->addSql('ALTER TABLE event_type RENAME TO task');

        // 2. Names Doctrine derives from the table, so schema:validate stays clean.
        $this->addSql('ALTER INDEX event_type_pkey RENAME TO task_pkey');
        $this->addSql('ALTER INDEX uniq_event_type_organization_name RENAME TO uniq_task_organization_name');
        $this->addSql('ALTER INDEX idx_93151b8232c8a3de RENAME TO idx_527edb2532c8a3de');
        $this->addSql('ALTER TABLE task RENAME CONSTRAINT fk_93151b8232c8a3de TO fk_527edb2532c8a3de');

        // 3. The referencing column, and its own derived names.
        $this->addSql('ALTER TABLE declaration_action RENAME COLUMN event_type_id TO task_id');
        $this->addSql('ALTER INDEX idx_65dd7f44401b253c RENAME TO idx_65dd7f448db60186');
        $this->addSql('ALTER TABLE declaration_action RENAME CONSTRAINT fk_65dd7f44401b253c TO fk_65dd7f448db60186');

        // 4. The rates. The organization's is required and carries the default, so
        //    every existing association gets one without a separate UPDATE.
        $this->addSql('ALTER TABLE organization ADD default_hourly_rate_cents INT DEFAULT 1200 NOT NULL');
        $this->addSql('ALTER TABLE task ADD hourly_rate_cents INT DEFAULT NULL');

        // 5. The snapshot on each filed line. Nullable first: the value has to be
        //    computed from data that only exists once the two columns above do.
        $this->addSql('ALTER TABLE declaration_action ADD hourly_rate_cents INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE declaration_action a
               SET hourly_rate_cents = COALESCE(t.hourly_rate_cents, o.default_hourly_rate_cents)
              FROM task t
              JOIN organization o ON o.id = t.organization_id
             WHERE a.task_id = t.id
            SQL);

        // Rates did not exist before this migration, so every line resolves to the
        // organization default and none can be missed. Prove it rather than assume
        // it: a NULL here would become a NOT NULL violation one statement later,
        // with nothing to say which row or why.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE unpriced integer;
            BEGIN
                SELECT COUNT(*) INTO unpriced FROM declaration_action WHERE hourly_rate_cents IS NULL;
                IF unpriced > 0 THEN
                    RAISE EXCEPTION
                        'Migration aborted: % declaration_action row(s) could not be given an hourly rate. Every row should have resolved through its task to an organization default.',
                        unpriced;
                END IF;
            END $$;
            SQL);

        $this->addSql('ALTER TABLE declaration_action ALTER COLUMN hourly_rate_cents SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration_action DROP hourly_rate_cents');
        $this->addSql('ALTER TABLE task DROP hourly_rate_cents');
        $this->addSql('ALTER TABLE organization DROP default_hourly_rate_cents');

        $this->addSql('ALTER TABLE declaration_action RENAME CONSTRAINT fk_65dd7f448db60186 TO fk_65dd7f44401b253c');
        $this->addSql('ALTER INDEX idx_65dd7f448db60186 RENAME TO idx_65dd7f44401b253c');
        $this->addSql('ALTER TABLE declaration_action RENAME COLUMN task_id TO event_type_id');

        $this->addSql('ALTER TABLE task RENAME CONSTRAINT fk_527edb2532c8a3de TO fk_93151b8232c8a3de');
        $this->addSql('ALTER INDEX idx_527edb2532c8a3de RENAME TO idx_93151b8232c8a3de');
        $this->addSql('ALTER INDEX uniq_task_organization_name RENAME TO uniq_event_type_organization_name');
        $this->addSql('ALTER INDEX task_pkey RENAME TO event_type_pkey');

        $this->addSql('ALTER TABLE task RENAME TO event_type');
    }
}
