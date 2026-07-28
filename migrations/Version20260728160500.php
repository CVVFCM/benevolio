<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turns the event type from an enum column into a per-association entity.
 *
 * THIS MIGRATION CARRIES DATA. declaration_action.event_type holds the old enum
 * values ('travaux', 'regate', …); every row has to land on the event_type row
 * belonging to *its own* association, reached through its declaration. Written by
 * hand because make:migration cannot know that mapping — it would simply drop the
 * column and add a NOT NULL FK, destroying the categories.
 *
 * The order matters: create and seed the table, add a nullable FK, backfill, prove
 * nothing was missed, and only then tighten the column. The DO block is the proof:
 * if a single row failed to map, the migration stops with a readable message
 * instead of leaving the data mangled.
 */
final class Version20260728160500 extends AbstractMigration
{
    /**
     * Old enum value => name given to the seeded EventType.
     *
     * Must stay in step with App\Organization\DefaultEventTypes::NAMES.
     *
     * @var array<string, string>
     */
    private const array VALUE_TO_NAME = [
        'travaux' => 'Travaux',
        'regate' => 'Régate',
        'encadrement' => 'Encadrement',
        'arbitrage' => 'Arbitrage',
        'autre' => 'Autre',
    ];

    public function getDescription(): string
    {
        return 'Move event types into their own per-organization table, preserving existing values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_type (id UUID NOT NULL, name VARCHAR(80) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_93151B8232C8A3DE ON event_type (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_type_organization_name ON event_type (organization_id, name)');
        $this->addSql('ALTER TABLE event_type ADD CONSTRAINT FK_93151B8232C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Seed the starter list for every association that already exists, so the
        // backfill below has somewhere to point and no organization is left with an
        // empty list (which would break its public form).
        foreach (self::VALUE_TO_NAME as $name) {
            $this->addSql(
                'INSERT INTO event_type (id, organization_id, name, active, created_at)
                 SELECT gen_random_uuid(), o.id, :name, true, NOW() FROM organization o',
                ['name' => $name],
            );
        }

        $this->addSql('ALTER TABLE declaration_action ADD event_type_id UUID DEFAULT NULL');

        // Match each action's old string to the type of its own association.
        foreach (self::VALUE_TO_NAME as $value => $name) {
            $this->addSql(
                'UPDATE declaration_action a
                    SET event_type_id = t.id
                   FROM declaration d, event_type t
                  WHERE a.declaration_id = d.id
                    AND t.organization_id = d.organization_id
                    AND t.name = :name
                    AND a.event_type = :value',
                ['name' => $name, 'value' => $value],
            );
        }

        // Stop loudly rather than tighten the column on half-mapped data. An
        // unexpected value in the old column lands here instead of being lost.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE unmapped integer;
            BEGIN
                SELECT COUNT(*) INTO unmapped FROM declaration_action WHERE event_type_id IS NULL;
                IF unmapped > 0 THEN
                    RAISE EXCEPTION
                        'Migration aborted: % declaration_action row(s) have an event_type that maps to no event_type row. Add the missing value to Version20260728160500::VALUE_TO_NAME and retry.',
                        unmapped;
                END IF;
            END $$;
            SQL);

        $this->addSql('ALTER TABLE declaration_action ALTER COLUMN event_type_id SET NOT NULL');
        $this->addSql('ALTER TABLE declaration_action ADD CONSTRAINT FK_65DD7F44401B253C FOREIGN KEY (event_type_id) REFERENCES event_type (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_65DD7F44401B253C ON declaration_action (event_type_id)');
        $this->addSql('ALTER TABLE declaration_action DROP event_type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration_action ADD event_type VARCHAR(255) DEFAULT NULL');

        // Rebuild the string column from the FK. A type the association renamed or
        // added itself has no enum equivalent, so it falls back to 'autre' — the
        // category that exists precisely for what does not fit elsewhere.
        foreach (self::VALUE_TO_NAME as $value => $name) {
            $this->addSql(
                'UPDATE declaration_action a
                    SET event_type = :value
                   FROM event_type t
                  WHERE a.event_type_id = t.id AND t.name = :name',
                ['value' => $value, 'name' => $name],
            );
        }

        $this->addSql("UPDATE declaration_action SET event_type = 'autre' WHERE event_type IS NULL");
        $this->addSql('ALTER TABLE declaration_action ALTER COLUMN event_type SET NOT NULL');

        $this->addSql('ALTER TABLE declaration_action DROP CONSTRAINT FK_65DD7F44401B253C');
        $this->addSql('DROP INDEX IDX_65DD7F44401B253C');
        $this->addSql('ALTER TABLE declaration_action DROP event_type_id');
        $this->addSql('DROP TABLE event_type');
    }
}
