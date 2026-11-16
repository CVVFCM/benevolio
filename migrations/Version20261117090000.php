<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an association choose to be named on the public home page.
 *
 * Off by default: appearing on a public page is the association's decision. The one
 * association in production, CVVFCM, asked to be listed, so it is switched on here —
 * otherwise the home page would open on an empty list. Matched by slug, which is the
 * stable public identifier; on a database without it, nothing is switched on.
 */
final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an association opt in to the public home page, and list CVVFCM';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD listed_on_homepage BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE organization SET listed_on_homepage = true WHERE slug = 'cvvfcm'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP listed_on_homepage');
    }
}
