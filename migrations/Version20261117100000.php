<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every declaration the name and address typed on it.
 *
 * A submission used to overwrite the volunteer's record at once; it now only states
 * an identity, copied to the volunteer when the declaration is confirmed (see
 * App\Entity\Declaration::$submittedFirstName).
 *
 * Existing declarations are backfilled from their volunteer: whatever each one typed
 * was written straight onto the person and is not recoverable separately, and the
 * person's current identity is exactly what those declarations ended up meaning. The
 * columns are added nullable, filled, then tightened, so the migration also runs on a
 * table that already has rows.
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store on each declaration the name and address typed on it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration ADD submitted_first_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_last_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_address_number VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_address_street VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_address_postcode VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_address_city VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD submitted_address_country CHAR(2) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE declaration d
            SET submitted_first_name = p.first_name,
                submitted_last_name = p.last_name,
                submitted_address_number = p.address_number,
                submitted_address_street = p.address_street,
                submitted_address_postcode = p.address_postcode,
                submitted_address_city = p.address_city,
                submitted_address_country = p.address_country
            FROM person p
            WHERE p.id = d.person_id
            SQL);

        $this->addSql('ALTER TABLE declaration ALTER submitted_first_name SET NOT NULL');
        $this->addSql('ALTER TABLE declaration ALTER submitted_last_name SET NOT NULL');
        $this->addSql('ALTER TABLE declaration ALTER submitted_address_street SET NOT NULL');
        $this->addSql('ALTER TABLE declaration ALTER submitted_address_postcode SET NOT NULL');
        $this->addSql('ALTER TABLE declaration ALTER submitted_address_city SET NOT NULL');
        $this->addSql('ALTER TABLE declaration ALTER submitted_address_country SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration DROP submitted_first_name');
        $this->addSql('ALTER TABLE declaration DROP submitted_last_name');
        $this->addSql('ALTER TABLE declaration DROP submitted_address_number');
        $this->addSql('ALTER TABLE declaration DROP submitted_address_street');
        $this->addSql('ALTER TABLE declaration DROP submitted_address_postcode');
        $this->addSql('ALTER TABLE declaration DROP submitted_address_city');
        $this->addSql('ALTER TABLE declaration DROP submitted_address_country');
    }
}
