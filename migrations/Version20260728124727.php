<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-tenant foundation: the organization (tenant) table and the back-office
 * account table.
 *
 * Volunteers are absent on purpose — they have no account.
 */
final class Version20260728124727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the organization (tenant) and app_user tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE INDEX IDX_88BDF3E932C8A3DE ON app_user (organization_id)');
        $this->addSql('CREATE TABLE organization (id UUID NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1EE637C989D9B62 ON organization (slug)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E932C8A3DE');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE organization');
    }
}
