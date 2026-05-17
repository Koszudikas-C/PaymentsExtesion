<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add chromeIdentityId to customers table with unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customers ADD chromeIdentityId VARCHAR(255) DEFAULT NULL");
        $this->addSql("CREATE UNIQUE INDEX UNIQ_C8A1B5BE38006E0 ON customers (chromeIdentityId)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP INDEX UNIQ_C8A1B5BE38006E0 ON customers");
        $this->addSql("ALTER TABLE customers DROP chromeIdentityId");
    }
}
