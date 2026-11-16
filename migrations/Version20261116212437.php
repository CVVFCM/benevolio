<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Back-office accounts without a password, and an owner per association.
 *
 * `password` becomes nullable: an account is now created without one and its holder
 * receives a login link to choose it (App\User\LoginLinkMailer). Null is what the
 * back office shows as « Invitation en attente ». An empty string was the old
 * placeholder for "no password"; it becomes NULL so the two do not coexist.
 *
 * Each association's first account becomes its owner (ROLE_OWNER), which is what
 * App\User\OwnerAssigner does for every account created from now on. "First" is the
 * smallest id — ids are UUID v7, so that is the oldest. In production that is the
 * single CVVFCM account. An association that somehow already has an owner is left
 * alone. `roles` is a `json` column, so it goes through jsonb to be appended to,
 * and jsonb_exists() rather than the `?` operator, which DBAL can read as a
 * placeholder.
 */
final class Version20261116212437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an account exist without a password, and make each association\'s first account its owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER password DROP NOT NULL');
        $this->addSql("UPDATE app_user SET password = NULL WHERE password = ''");

        $this->addSql(<<<'SQL'
            UPDATE app_user
            SET roles = (roles::jsonb || '["ROLE_OWNER"]'::jsonb)::json
            WHERE id IN (
                SELECT DISTINCT ON (organization_id) id
                FROM app_user
                WHERE organization_id IS NOT NULL
                ORDER BY organization_id, id
            )
            AND organization_id NOT IN (
                SELECT organization_id
                FROM app_user
                WHERE organization_id IS NOT NULL
                AND jsonb_exists(roles::jsonb, 'ROLE_OWNER')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE app_user
            SET roles = (roles::jsonb - 'ROLE_OWNER')::json
            WHERE jsonb_exists(roles::jsonb, 'ROLE_OWNER')
            SQL);

        // An account still waiting for its invitation gets the old placeholder back:
        // it could not log in before this migration either.
        $this->addSql("UPDATE app_user SET password = '' WHERE password IS NULL");
        $this->addSql('ALTER TABLE app_user ALTER password SET NOT NULL');
    }
}
