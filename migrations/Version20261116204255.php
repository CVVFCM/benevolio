<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records which back-office account confirmed a declaration in the volunteer's stead.
 *
 * Nullable, and null on every existing row: until now the only way to confirm was the
 * volunteer's own click, so null — "the volunteer confirmed" — is true of all of them.
 *
 * No ON DELETE: an account that vouched for a declaration cannot quietly stop having.
 */
final class Version20261116204255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record who confirmed a declaration manually, on the volunteer\'s behalf';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration ADD confirmed_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD CONSTRAINT FK_7AA3DAC26F45385D FOREIGN KEY (confirmed_by_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7AA3DAC26F45385D ON declaration (confirmed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration DROP CONSTRAINT FK_7AA3DAC26F45385D');
        $this->addSql('DROP INDEX IDX_7AA3DAC26F45385D');
        $this->addSql('ALTER TABLE declaration DROP confirmed_by_id');
    }
}
