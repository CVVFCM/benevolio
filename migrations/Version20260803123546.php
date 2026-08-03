<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An exercice is now open or closed, and that is what makes a rate trustworthy.
 *
 * While it is open the rates can change and no reçu fiscal may be issued from them; closing
 * freezes the rates, the dates and the name, and is what allows receipts. So the figure printed
 * on a receipt was settled before the document existed — see App\State\FiscalYearState.
 *
 * **Every existing exercice becomes OPEN.** Nothing has ever been closed, and closing is a
 * deliberate accounting act that must not be performed by a migration on someone's behalf. The
 * consequence is deliberate too: after this, generating a year's receipts asks the treasurer to
 * close the exercice first. Nothing regresses, because no receipt has ever been issued in
 * production — S3 was unconfigured until lot 8, so generation threw before it wrote anything.
 *
 * DEFAULT + NOT NULL in one statement, which is what makes this applicable to a table that
 * already has rows: `ADD state VARCHAR NOT NULL` with no default fails on a non-empty table.
 */
final class Version20260803123546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give each exercice an open/closed state, so rates are frozen before a receipt quotes them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE fiscal_year ADD state VARCHAR(255) DEFAULT 'open' NOT NULL");
        // And the default goes away again: it existed only to fill the rows that were already
        // there. The state of a new exercice is decided in PHP, by the property initialiser on
        // App\Entity\FiscalYear, and a column default left behind would be a second answer to
        // the same question — which is what doctrine:schema:validate would report.
        $this->addSql('ALTER TABLE fiscal_year ALTER state DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_year DROP state');
    }
}
