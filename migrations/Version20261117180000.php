<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Volunteer accounts (#34): the profile fields « Mon profil » edits, and the version
 * that makes a login link single-use. Existing volunteers start with an empty profile.
 */
final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the volunteer profile (distance, vehicle) and login link version to person';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person ADD distance_km INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD fiscal_power VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD electric_vehicle BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE person ADD login_link_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP distance_km');
        $this->addSql('ALTER TABLE person DROP fiscal_power');
        $this->addSql('ALTER TABLE person DROP electric_vehicle');
        $this->addSql('ALTER TABLE person DROP login_link_version');
    }
}
