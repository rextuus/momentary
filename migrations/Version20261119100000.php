<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aufnahme-Segmente je Video (DV-Kassetten: Aufnahmezeit je Aufnahme aus den Subcodes, documentation/dv_aufnahmedatum.md).
 * Bestehende Videos bekommen sie beim nächsten Verarbeiten, sofern das Original DV ist.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aufnahme-Segmente (recording_segment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE recording_segment (id INT AUTO_INCREMENT NOT NULL, start_seconds DOUBLE PRECISION NOT NULL, end_seconds DOUBLE PRECISION NOT NULL, recorded_at DATETIME DEFAULT NULL, camera_recorded_at DATETIME DEFAULT NULL, source VARCHAR(16) NOT NULL, suspicious TINYINT DEFAULT 0 NOT NULL, recurring_date TINYINT DEFAULT 0 NOT NULL, video_id INT NOT NULL, INDEX idx_recording_segment_camera_time (camera_recorded_at), INDEX IDX_97D7CE0729C1004E (video_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE recording_segment ADD CONSTRAINT FK_97D7CE0729C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recording_segment DROP FOREIGN KEY FK_97D7CE0729C1004E');
        $this->addSql('DROP TABLE recording_segment');
    }
}
