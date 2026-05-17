<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260517113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plan, subscriptionId and licenseExpiresAt to customers table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customers ADD plan VARCHAR(20) NOT NULL DEFAULT 'LIFETIME', ADD subscriptionId VARCHAR(100) DEFAULT NULL, ADD licenseExpiresAt DATETIME DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customers DROP plan, DROP subscriptionId, DROP licenseExpiresAt");
    }
}
