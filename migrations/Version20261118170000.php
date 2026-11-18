<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Events and actions (#44): each association's own list, and the link from a declaration
 * line to one of them. A linked line has no title of its own, so the column becomes
 * nullable; lines already filed keep theirs and stay unlinked.
 *
 * Two partial unique indexes on (name, begin date): a plain one treats NULL dates as
 * distinct and would let the same undated name in twice.
 */
final class Version20261118170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the activity table and link declaration actions to it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activity (id UUID NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(255) NOT NULL, begins_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_activity_organization_name_begins_on ON activity (organization_id, name, begins_on) WHERE (begins_on IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_activity_organization_name_undated ON activity (organization_id, name) WHERE (begins_on IS NULL)');
        $this->addSql('CREATE INDEX IDX_AC74095A32C8A3DE ON activity (organization_id)');
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_AC74095A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE declaration_action ADD activity_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration_action ALTER title DROP NOT NULL');
        $this->addSql('ALTER TABLE declaration_action ADD CONSTRAINT FK_65DD7F4481C06096 FOREIGN KEY (activity_id) REFERENCES activity (id) ON DELETE NO ACTION NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_65DD7F4481C06096 ON declaration_action (activity_id)');
    }

    public function down(Schema $schema): void
    {
        // A linked line has no title: give it its activity's name back before the link goes.
        $this->addSql('UPDATE declaration_action SET title = activity.name FROM activity WHERE declaration_action.activity_id = activity.id');
        $this->addSql('ALTER TABLE declaration_action DROP CONSTRAINT FK_65DD7F4481C06096');
        $this->addSql('DROP INDEX IDX_65DD7F4481C06096');
        $this->addSql('ALTER TABLE declaration_action DROP activity_id');
        $this->addSql('ALTER TABLE declaration_action ALTER title SET NOT NULL');
        $this->addSql('DROP TABLE activity');
    }
}
