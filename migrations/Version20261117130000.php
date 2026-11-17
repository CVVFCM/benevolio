<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The electric-vehicle bonus: a flag on each declared line, and the bonus on each rate.
 *
 * Every existing line is not electric, so no amount already shown, booked or receipted
 * changes. Every existing rate gets the 20 % in force since 2021.
 */
final class Version20261117130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the electric-vehicle flag on declared lines and the electric bonus on mileage rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration_action ADD electric_vehicle BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE fiscal_year ADD default_electric_bonus_percent INT DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE fiscal_year_mileage_rate ADD electric_bonus_percent INT DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration_action DROP electric_vehicle');
        $this->addSql('ALTER TABLE fiscal_year DROP default_electric_bonus_percent');
        $this->addSql('ALTER TABLE fiscal_year_mileage_rate DROP electric_bonus_percent');
    }
}
