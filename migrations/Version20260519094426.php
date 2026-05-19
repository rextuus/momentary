<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519094426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video ADD COLUMN downloaded_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN scenes_detected_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN frames_extracted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN faces_analyzed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refined_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN completed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__video AS SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames FROM video');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, youtube_url VARCHAR(255) DEFAULT NULL, source_file VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL, analysis_fps DOUBLE PRECISION DEFAULT NULL, min_scene_length_for_refinement DOUBLE PRECISION DEFAULT NULL, refined_analysis_fps DOUBLE PRECISION DEFAULT NULL, merge_empty_scenes_with_last_person_scene BOOLEAN DEFAULT 0 NOT NULL, error_message CLOB DEFAULT NULL, local_path VARCHAR(1000) DEFAULT NULL, total_frames INTEGER DEFAULT 0 NOT NULL, processed_frames INTEGER DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO video (id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames) SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames FROM __temp__video');
        $this->addSql('DROP TABLE __temp__video');
    }
}
