<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018100015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gesichtserkennungs-Einstellungen, Match-Kandidaten, Pipeline-Fortschritt, große Dateien (BIGINT) und Ton-Transkription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE face_recognition_settings (id INT AUTO_INCREMENT NOT NULL, quality_filter VARCHAR(10) NOT NULL, max_faces_per_frame INT NOT NULL, min_face_width DOUBLE PRECISION NOT NULL, candidate_threshold DOUBLE PRECISION NOT NULL, max_candidates INT NOT NULL, match_threshold DOUBLE PRECISION NOT NULL, suggestion_threshold DOUBLE PRECISION NOT NULL, video_link_threshold DOUBLE PRECISION NOT NULL, tracking_enabled TINYINT NOT NULL, tracking_min_iou DOUBLE PRECISION NOT NULL, tracking_max_gap_seconds INT NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE transcript_segment (id INT AUTO_INCREMENT NOT NULL, start_seconds DOUBLE PRECISION NOT NULL, end_seconds DOUBLE PRECISION NOT NULL, text LONGTEXT NOT NULL, avg_logprob DOUBLE PRECISION NOT NULL, transcript_id INT NOT NULL, INDEX IDX_B905D8DFDF135535 (start_seconds), INDEX IDX_B905D8DFA4F53E3E (transcript_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE transcription_settings (id INT AUTO_INCREMENT NOT NULL, model VARCHAR(32) NOT NULL, threads INT NOT NULL, language VARCHAR(8) DEFAULT NULL, transcribe_in_pipeline TINYINT DEFAULT 1 NOT NULL, derive_tags TINYINT NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE video_transcript (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, model VARCHAR(32) DEFAULT NULL, language VARCHAR(8) DEFAULT NULL, processed_seconds DOUBLE PRECISION NOT NULL, audio_seconds DOUBLE PRECISION NOT NULL, error LONGTEXT DEFAULT NULL, queued_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, video_id INT NOT NULL, UNIQUE INDEX UNIQ_28510F7529C1004E (video_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE transcript_segment ADD CONSTRAINT FK_B905D8DFA4F53E3E FOREIGN KEY (transcript_id) REFERENCES video_transcript (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE video_transcript ADD CONSTRAINT FK_28510F7529C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE',
        );
        $this->addSql('ALTER TABLE files CHANGE file_size file_size BIGINT NOT NULL');
        $this->addSql(
            'ALTER TABLE video ADD progress_step VARCHAR(32) DEFAULT NULL, ADD progress_done INT DEFAULT 0 NOT NULL, ADD progress_total INT DEFAULT 0 NOT NULL',
        );
        $this->addSql('ALTER TABLE video_face ADD match_candidates JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transcript_segment DROP FOREIGN KEY FK_B905D8DFA4F53E3E');
        $this->addSql('ALTER TABLE video_transcript DROP FOREIGN KEY FK_28510F7529C1004E');
        $this->addSql('DROP TABLE face_recognition_settings');
        $this->addSql('DROP TABLE transcript_segment');
        $this->addSql('DROP TABLE transcription_settings');
        $this->addSql('DROP TABLE video_transcript');
        $this->addSql('ALTER TABLE files CHANGE file_size file_size INT NOT NULL');
        $this->addSql('ALTER TABLE video DROP progress_step, DROP progress_done, DROP progress_total');
        $this->addSql('ALTER TABLE video_face DROP match_candidates');
    }
}
