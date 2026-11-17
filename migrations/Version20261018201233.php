<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018201233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Share-Links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE share_link (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, password_hash VARCHAR(255) DEFAULT NULL, allow_download TINYINT DEFAULT 0 NOT NULL, label VARCHAR(120) DEFAULT NULL, view_count INT DEFAULT 0 NOT NULL, last_viewed_at DATETIME DEFAULT NULL, event_id INT DEFAULT NULL, medium_id INT DEFAULT NULL, created_by_id INT NOT NULL, UNIQUE INDEX UNIQ_8B6B94685F37A13B (token), INDEX IDX_8B6B9468B03A8386 (created_by_id), INDEX IDX_8B6B946871F7E88B (event_id), INDEX IDX_8B6B9468E252B6A5 (medium_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE share_link ADD CONSTRAINT FK_8B6B946871F7E88B FOREIGN KEY (event_id) REFERENCES media_event (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468E252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_link DROP FOREIGN KEY FK_8B6B946871F7E88B');
        $this->addSql('ALTER TABLE share_link DROP FOREIGN KEY FK_8B6B9468E252B6A5');
        $this->addSql('ALTER TABLE share_link DROP FOREIGN KEY FK_8B6B9468B03A8386');
        $this->addSql('DROP TABLE share_link');
    }
}
