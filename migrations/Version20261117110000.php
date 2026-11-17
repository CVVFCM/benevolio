<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes login links single-use.
 *
 * The counter is part of every link's signature and is bumped each time one is used
 * (App\Entity\User::invalidateLoginLinks()), so a used link — and any other still in
 * a mailbox for that account — stops working.
 *
 * Deploying this invalidates every link already sent: the signature now covers a
 * field the old links were not signed with. An invitation still pending is resent
 * from « Administrateurs »; a reset is asked for again.
 */
final class Version20261117110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Count login-link uses per account, so a link works once';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD login_link_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP login_link_version');
    }
}
