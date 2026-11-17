<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the association's address columns the lengths of App\ValueObject\Address.
 *
 * They are read back into that value object when a receipt is made, and they allowed
 * more than it accepts: a street of 201 characters saved fine and then could not be
 * printed. The number grows (10 → 20); the street and the city shrink (255 → 200,
 * 255 → 120).
 *
 * PostgreSQL refuses to shrink a column holding a longer value, and the migration
 * runs all-or-nothing, so a row that does not fit stops the deployment rather than
 * being cut. Shorten it by hand and deploy again — no real street is that long.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the association address columns with the Address value object';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ALTER address_number TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE organization ALTER address_street TYPE VARCHAR(200)');
        $this->addSql('ALTER TABLE organization ALTER address_city TYPE VARCHAR(120)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ALTER address_number TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE organization ALTER address_street TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE organization ALTER address_city TYPE VARCHAR(255)');
    }
}
