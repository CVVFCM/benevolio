<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Activity defaults (#46): the number of days and of hours an event or action usually
 * asks of a volunteer, pre-filled on the declaration form. Both optional; existing
 * activities start with none.
 */
final class Version20261118190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the default days and hours to activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity ADD default_days SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE activity ADD default_work_hours NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity DROP default_days');
        $this->addSql('ALTER TABLE activity DROP default_work_hours');
    }
}
