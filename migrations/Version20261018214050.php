<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018214050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web-Fassung von Videos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD web_file_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE video ADD CONSTRAINT FK_7CC7DA2C7B64D7F2 FOREIGN KEY (web_file_id) REFERENCES files (id) ON DELETE SET NULL',
        );
        $this->addSql('CREATE INDEX IDX_7CC7DA2C7B64D7F2 ON video (web_file_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP FOREIGN KEY FK_7CC7DA2C7B64D7F2');
        $this->addSql('DROP INDEX IDX_7CC7DA2C7B64D7F2 ON video');
        $this->addSql('ALTER TABLE video DROP web_file_id');
    }
}
