<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530165640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE person ADD COLUMN show_count INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__person AS SELECT id, name, description, identified, wasted, status, profile_face_id FROM person');
        $this->addSql('DROP TABLE person');
        $this->addSql('CREATE TABLE person (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, identified BOOLEAN DEFAULT 0 NOT NULL, wasted BOOLEAN DEFAULT 0 NOT NULL, status VARCHAR(255) DEFAULT \'new\' NOT NULL, profile_face_id INTEGER DEFAULT NULL, CONSTRAINT FK_34DCD1762E76B24C FOREIGN KEY (profile_face_id) REFERENCES video_face (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO person (id, name, description, identified, wasted, status, profile_face_id) SELECT id, name, description, identified, wasted, status, profile_face_id FROM __temp__person');
        $this->addSql('DROP TABLE __temp__person');
        $this->addSql('CREATE INDEX IDX_34DCD1762E76B24C ON person (profile_face_id)');
    }
}
