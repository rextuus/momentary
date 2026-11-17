<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018144955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verworfene Ereignis-Vorschläge je Benutzer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE event_suggestion_dismissal (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, suggestion_key VARCHAR(64) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_E621EEC8A76ED39534809966 (user_id, suggestion_key), INDEX IDX_E621EEC8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE event_suggestion_dismissal ADD CONSTRAINT FK_E621EEC8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_suggestion_dismissal DROP FOREIGN KEY FK_E621EEC8A76ED395');
        $this->addSql('DROP TABLE event_suggestion_dismissal');
    }
}
