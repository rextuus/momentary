<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018163802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kamera und Kamerazeit an allen Medien, gemerkte Abweichungen von Kamera-Uhren';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE camera_clock_offset (id INT AUTO_INCREMENT NOT NULL, cameras JSON NOT NULL, camera_from DATETIME NOT NULL, camera_to DATETIME NOT NULL, offset_seconds INT NOT NULL, label VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE medium ADD camera_taken_at DATETIME DEFAULT NULL, ADD camera VARCHAR(128) DEFAULT NULL',
        );
        $this->addSql('UPDATE medium m JOIN photo p ON p.id = m.id SET m.camera = p.camera');
        // Nicht korrigierte Zeiten sind die Zeiten der Kamera
        $this->addSql(
            "UPDATE medium SET camera_taken_at = taken_at WHERE date_source IN ('exif', 'filename', 'metadata')",
        );
        $this->addSql('ALTER TABLE photo DROP camera');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE camera_clock_offset');
        $this->addSql('ALTER TABLE photo ADD camera VARCHAR(128) DEFAULT NULL');
        $this->addSql('UPDATE photo p JOIN medium m ON m.id = p.id SET p.camera = m.camera');
        $this->addSql('ALTER TABLE medium DROP camera_taken_at, DROP camera');
    }
}
