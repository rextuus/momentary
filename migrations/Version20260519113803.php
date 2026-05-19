<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519113803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video ADD COLUMN converted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN duration DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN download_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN conversion_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN scene_detection_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN frame_extraction_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN face_analysis_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refinement_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN estimated_conversion_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN estimated_scene_detection_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN estimated_frame_extraction_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN estimated_face_analysis_duration INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__video AS SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at FROM video');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, youtube_url VARCHAR(255) DEFAULT NULL, source_file VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL, analysis_fps DOUBLE PRECISION DEFAULT NULL, min_scene_length_for_refinement DOUBLE PRECISION DEFAULT NULL, refined_analysis_fps DOUBLE PRECISION DEFAULT NULL, merge_empty_scenes_with_last_person_scene BOOLEAN DEFAULT 0 NOT NULL, error_message CLOB DEFAULT NULL, local_path VARCHAR(1000) DEFAULT NULL, total_frames INTEGER DEFAULT 0 NOT NULL, processed_frames INTEGER DEFAULT 0 NOT NULL, downloaded_at DATETIME DEFAULT NULL, scenes_detected_at DATETIME DEFAULT NULL, frames_extracted_at DATETIME DEFAULT NULL, faces_analyzed_at DATETIME DEFAULT NULL, refined_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO video (id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at) SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at FROM __temp__video');
        $this->addSql('DROP TABLE __temp__video');
    }
}
