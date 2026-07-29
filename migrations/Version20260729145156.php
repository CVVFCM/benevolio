<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The association's signature, so a reçu fiscal goes out signed.
 *
 * A table of its own rather than columns on `organization`: that row is read on every
 * request to resolve the tenant, and Doctrine holds the owning side here, so it hydrates
 * `signature_id` alone and fetches the image only when a receipt needs it. See
 * App\Entity\OrganizationSignature.
 *
 * `ON DELETE SET NULL` because this FK is not meant to refuse anything — deleting a
 * signature is a legitimate act. (The AGENTS.md note about RESTRICT raising SQLSTATE 23001,
 * which DBAL does not map to ForeignKeyConstraintViolationException, applies to FKs that
 * *do* refuse; do not "tighten" this one into RESTRICT.)
 *
 * Nullable and with no default, so it applies cleanly to the associations already in
 * production: none of them has a signature, and issuance does not require one.
 */
final class Version20260729145156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the organisation signature stamped onto reçus fiscaux';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_signature (id UUID NOT NULL, mime_type VARCHAR(64) NOT NULL, base64 TEXT NOT NULL, original_filename VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE organization ADD signature_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637CED61183A FOREIGN KEY (signature_id) REFERENCES organization_signature (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1EE637CED61183A ON organization (signature_id)');
    }

    public function down(Schema $schema): void
    {
        // Order matters, and the generated version had it backwards: PostgreSQL refuses to
        // DROP TABLE while another table's foreign key still depends on it. Constraint,
        // index and column first; the table last.
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637CED61183A');
        $this->addSql('DROP INDEX UNIQ_C1EE637CED61183A');
        $this->addSql('ALTER TABLE organization DROP signature_id');
        $this->addSql('DROP TABLE organization_signature');
    }
}
