<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The reçu fiscal becomes a volunteer's civil year instead of a declaration.
 *
 * Lot 7 issued one receipt per declaration, at validation. That could not work: a volunteer
 * files several declarations a year and carries a single figure to their income-tax return,
 * and no receipt could be complete before the year ended. So `receipt` loses its declaration
 * and gains `(person_id, year)`.
 *
 * **No rows are deleted.** Production very probably has none — S3 was never configured
 * before lot 8, so generation threw — but "very probably" is not a reason to drop tax
 * documents. `person_id` comes from the declaration the receipt was issued for, and `year`
 * from the earliest contribution on it, which is the year that receipt was about.
 *
 * The counter moves from `fiscal_year` to `organization`: the number is now one continuous
 * series per association (`0001`), because an exercice running September to August cannot
 * number a January-to-December document. Starting the new counter at 0 cannot collide — the
 * old numbers carry an exercice-name prefix, the new ones do not.
 *
 * `receipt_withheld_reason` goes with the rest of the per-declaration wiring: nothing writes
 * it any more, and a column nobody fills reads as a feature that exists.
 */
final class Version20260730090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make a receipt cover one volunteer for one civil year, and move the number counter to the association';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt ADD person_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE receipt ADD year INT DEFAULT NULL');

        // Backfill before the NOT NULL. The volunteer is the one the receipted declaration
        // belongs to; the year is the one its earliest contribution fell in, which is what
        // the document covered.
        $this->addSql(<<<'SQL'
            UPDATE receipt
            SET person_id = declaration.person_id,
                year = COALESCE(
                    (SELECT EXTRACT(YEAR FROM MIN(declaration_action.date))::int
                     FROM declaration_action
                     WHERE declaration_action.declaration_id = declaration.id),
                    EXTRACT(YEAR FROM receipt.issued_at)::int
                )
            FROM declaration
            WHERE declaration.id = receipt.declaration_id
            SQL);

        $this->addSql('ALTER TABLE receipt ALTER person_id SET NOT NULL');
        $this->addSql('ALTER TABLE receipt ALTER year SET NOT NULL');

        // NO ACTION, not CASCADE: deleting a volunteer must not delete their tax receipts.
        // NO ACTION rather than RESTRICT because RESTRICT raises SQLSTATE 23001, which DBAL
        // does not map to ForeignKeyConstraintViolationException — see AGENTS.md.
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B645217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE NO ACTION NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5399B645217BBB47 ON receipt (person_id)');
        $this->addSql('CREATE INDEX idx_receipt_organization_year ON receipt (organization_id, year)');

        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B645C06258A3');
        $this->addSql('DROP INDEX UNIQ_5399B645C06258A3');
        $this->addSql('ALTER TABLE receipt DROP declaration_id');

        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B64563F9139E');
        $this->addSql('DROP INDEX IDX_5399B64563F9139E');
        $this->addSql('ALTER TABLE receipt DROP fiscal_year_id');

        $this->addSql('ALTER TABLE receipt ALTER number TYPE VARCHAR(20)');

        $this->addSql('ALTER TABLE declaration DROP receipt_withheld_reason');

        $this->addSql('ALTER TABLE organization ADD last_receipt_sequence INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE fiscal_year DROP last_receipt_sequence');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_year ADD last_receipt_sequence INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE organization DROP last_receipt_sequence');

        $this->addSql('ALTER TABLE declaration ADD receipt_withheld_reason VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE receipt ALTER number TYPE VARCHAR(45)');

        // The declaration a receipt was issued for is not recoverable — one volunteer-year
        // can cover several of them, and reissues mean several receipts per year. Anything
        // still in the table would therefore have no declaration to point at, so this
        // direction only goes through on an empty table. Reversing a schema change is not
        // the same as reversing a decision.
        $this->addSql('DELETE FROM receipt');

        $this->addSql('ALTER TABLE receipt ADD declaration_id UUID NOT NULL');
        $this->addSql('ALTER TABLE receipt ADD fiscal_year_id UUID NOT NULL');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B645C06258A3 FOREIGN KEY (declaration_id) REFERENCES declaration (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5399B645C06258A3 ON receipt (declaration_id)');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B64563F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id) ON DELETE NO ACTION NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5399B64563F9139E ON receipt (fiscal_year_id)');

        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B645217BBB47');
        $this->addSql('DROP INDEX IDX_5399B645217BBB47');
        $this->addSql('DROP INDEX idx_receipt_organization_year');
        $this->addSql('ALTER TABLE receipt DROP person_id');
        $this->addSql('ALTER TABLE receipt DROP year');
    }
}
