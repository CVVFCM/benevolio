<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the double opt-in confirmation columns to declaration.
 *
 * Declarations that already exist were filed before confirmation was a thing, and
 * their volunteers cannot be asked retroactively to click a link they never
 * received. They are backfilled as confirmed at their submission time — anything
 * else would silently un-file work the association has already acted on.
 */
final class Version20260728172427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirmation token and confirmation timestamp to declaration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE declaration ADD confirmation_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD confirmation_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE declaration ADD confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7AA3DAC2C05FB297 ON declaration (confirmation_token)');

        // Existing rows predate confirmation: treat them as confirmed when they were
        // submitted, so nothing already in the treasurer's queue drops out of it.
        // Only the states that mean "the volunteer finished the form" — a future
        // awaiting_confirmation row is created by the application, not by this.
        $this->addSql(
            "UPDATE declaration SET confirmed_at = submitted_at
              WHERE confirmed_at IS NULL AND state IN ('submitted', 'validated', 'refused')",
        );
    }

    public function down(Schema $schema): void
    {
        // Anything still awaiting confirmation has no equivalent in the old model,
        // where a submitted declaration was final. Promote it rather than leave a
        // state the previous code cannot read.
        $this->addSql("UPDATE declaration SET state = 'submitted' WHERE state = 'awaiting_confirmation'");

        $this->addSql('DROP INDEX UNIQ_7AA3DAC2C05FB297');
        $this->addSql('ALTER TABLE declaration DROP confirmation_token');
        $this->addSql('ALTER TABLE declaration DROP confirmation_token_expires_at');
        $this->addSql('ALTER TABLE declaration DROP confirmed_at');
    }
}
