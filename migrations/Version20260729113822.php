<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The CERFA receipt: the table that records it, and the data the form demands.
 *
 * `receipt` is the durable record. The PDF in object storage can be overwritten — the
 * key is `<year>/cerfa-firstname-lastname.pdf`, so a volunteer receipted twice in one
 * exercice replaces their earlier file — but the number, amount, date and printed
 * identity survive here, which is what makes "continuous per exercice and never reused"
 * verifiable after the fact.
 *
 * `fiscal_year.last_receipt_sequence` is that continuity: a counter, not `MAX(number)`,
 * so deleting a receipt cannot cause a number to be handed out twice.
 *
 * The `organization` columns are what form 2041-RD asks about the beneficiary and the
 * application never needed before — SIREN/RNA, a postal address, the objet. All
 * nullable, because every existing association predates them; issuance refuses rather
 * than printing a blank line, so nullable never means an invalid document.
 *
 * Every addition is nullable or defaulted, so this applies cleanly to the tables as they
 * already stand in production.
 */
final class Version20260729113822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the receipt table, the per-exercice receipt counter, and the organisation identity the CERFA requires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE receipt (id UUID NOT NULL, number VARCHAR(45) NOT NULL, amount_cents INT NOT NULL, storage_path VARCHAR(255) NOT NULL, volunteer_name VARCHAR(255) NOT NULL, volunteer_address VARCHAR(500) NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, declaration_id UUID NOT NULL, fiscal_year_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5399B645C06258A3 ON receipt (declaration_id)');
        $this->addSql('CREATE INDEX IDX_5399B64563F9139E ON receipt (fiscal_year_id)');
        $this->addSql('CREATE INDEX IDX_5399B64532C8A3DE ON receipt (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_organization_number ON receipt (organization_id, number)');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B645C06258A3 FOREIGN KEY (declaration_id) REFERENCES declaration (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B64563F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id) ON DELETE NO ACTION NOT DEFERRABLE');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B64532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE declaration ADD receipt_withheld_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_year ADD last_receipt_sequence INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE organization ADD siren_or_rna VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD address_number VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD address_street VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD address_postcode VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD address_city VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD address_country CHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD objet VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B645C06258A3');
        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B64563F9139E');
        $this->addSql('ALTER TABLE receipt DROP CONSTRAINT FK_5399B64532C8A3DE');
        $this->addSql('DROP TABLE receipt');
        $this->addSql('ALTER TABLE declaration DROP receipt_withheld_reason');
        $this->addSql('ALTER TABLE fiscal_year DROP last_receipt_sequence');
        $this->addSql('ALTER TABLE organization DROP siren_or_rna');
        $this->addSql('ALTER TABLE organization DROP address_number');
        $this->addSql('ALTER TABLE organization DROP address_street');
        $this->addSql('ALTER TABLE organization DROP address_postcode');
        $this->addSql('ALTER TABLE organization DROP address_city');
        $this->addSql('ALTER TABLE organization DROP address_country');
        $this->addSql('ALTER TABLE organization DROP objet');
    }
}
