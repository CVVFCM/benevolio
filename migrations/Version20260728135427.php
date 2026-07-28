<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The declaration model: the people who give, their submissions, and the
 * individual contributed actions inside each one.
 *
 * person carries the Address value object as address_* columns and the Email
 * value object as a single column. declaration_action has no organization_id on
 * purpose — it is scoped through its declaration.
 */
final class Version20260728135427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the person, declaration and declaration_action tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE declaration (id UUID NOT NULL, state VARCHAR(255) NOT NULL, accuracy_attested BOOLEAN NOT NULL, expenses_waived BOOLEAN NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, person_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7AA3DAC2217BBB47 ON declaration (person_id)');
        $this->addSql('CREATE INDEX IDX_7AA3DAC232C8A3DE ON declaration (organization_id)');
        $this->addSql('CREATE TABLE declaration_action (id UUID NOT NULL, state VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL, title VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, date DATE NOT NULL, consecutive_days SMALLINT DEFAULT 1 NOT NULL, journeys SMALLINT DEFAULT 0 NOT NULL, distance_km INT DEFAULT 0 NOT NULL, own_vehicle BOOLEAN NOT NULL, fiscal_power VARCHAR(255) DEFAULT NULL, work_hours NUMERIC(5, 2) NOT NULL, declaration_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_65DD7F44C06258A3 ON declaration_action (declaration_id)');
        $this->addSql('CREATE TABLE person (id UUID NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, address_number VARCHAR(20) DEFAULT NULL, address_street VARCHAR(200) NOT NULL, address_postcode VARCHAR(16) NOT NULL, address_city VARCHAR(120) NOT NULL, address_country CHAR(2) NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_34DCD17632C8A3DE ON person (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_person_organization_email ON person (organization_id, email)');
        $this->addSql('ALTER TABLE declaration ADD CONSTRAINT FK_7AA3DAC2217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE declaration ADD CONSTRAINT FK_7AA3DAC232C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE declaration_action ADD CONSTRAINT FK_65DD7F44C06258A3 FOREIGN KEY (declaration_id) REFERENCES declaration (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD17632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration DROP CONSTRAINT FK_7AA3DAC2217BBB47');
        $this->addSql('ALTER TABLE declaration DROP CONSTRAINT FK_7AA3DAC232C8A3DE');
        $this->addSql('ALTER TABLE declaration_action DROP CONSTRAINT FK_65DD7F44C06258A3');
        $this->addSql('ALTER TABLE person DROP CONSTRAINT FK_34DCD17632C8A3DE');
        $this->addSql('DROP TABLE declaration');
        $this->addSql('DROP TABLE declaration_action');
        $this->addSql('DROP TABLE person');
    }
}
