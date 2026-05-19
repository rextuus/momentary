<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519150459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video ADD COLUMN converted_video_path VARCHAR(1000) DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refining_extraction_finished_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refining_analysis_finished_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refining_extraction_duration INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD COLUMN refining_analysis_duration INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__video AS SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, converted_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at, duration, download_duration, conversion_duration, scene_detection_duration, frame_extraction_duration, face_analysis_duration, current_frame_directory, refinement_duration, estimated_conversion_duration, estimated_scene_detection_duration, estimated_frame_extraction_duration, estimated_face_analysis_duration FROM video');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, youtube_url VARCHAR(255) DEFAULT NULL, source_file VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL, analysis_fps DOUBLE PRECISION DEFAULT NULL, min_scene_length_for_refinement DOUBLE PRECISION DEFAULT NULL, refined_analysis_fps DOUBLE PRECISION DEFAULT NULL, merge_empty_scenes_with_last_person_scene BOOLEAN DEFAULT 0 NOT NULL, error_message CLOB DEFAULT NULL, local_path VARCHAR(1000) DEFAULT NULL, total_frames INTEGER DEFAULT 0 NOT NULL, processed_frames INTEGER DEFAULT 0 NOT NULL, downloaded_at DATETIME DEFAULT NULL, converted_at DATETIME DEFAULT NULL, scenes_detected_at DATETIME DEFAULT NULL, frames_extracted_at DATETIME DEFAULT NULL, faces_analyzed_at DATETIME DEFAULT NULL, refined_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, duration DOUBLE PRECISION DEFAULT NULL, download_duration INTEGER DEFAULT NULL, conversion_duration INTEGER DEFAULT NULL, scene_detection_duration INTEGER DEFAULT NULL, frame_extraction_duration INTEGER DEFAULT NULL, face_analysis_duration INTEGER DEFAULT NULL, current_frame_directory VARCHAR(500) DEFAULT NULL, refinement_duration INTEGER DEFAULT NULL, estimated_conversion_duration INTEGER DEFAULT NULL, estimated_scene_detection_duration INTEGER DEFAULT NULL, estimated_frame_extraction_duration INTEGER DEFAULT NULL, estimated_face_analysis_duration INTEGER DEFAULT NULL)');
        $this->addSql('INSERT INTO video (id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, converted_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at, duration, download_duration, conversion_duration, scene_detection_duration, frame_extraction_duration, face_analysis_duration, current_frame_directory, refinement_duration, estimated_conversion_duration, estimated_scene_detection_duration, estimated_frame_extraction_duration, estimated_face_analysis_duration) SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, error_message, local_path, total_frames, processed_frames, downloaded_at, converted_at, scenes_detected_at, frames_extracted_at, faces_analyzed_at, refined_at, completed_at, duration, download_duration, conversion_duration, scene_detection_duration, frame_extraction_duration, face_analysis_duration, current_frame_directory, refinement_duration, estimated_conversion_duration, estimated_scene_detection_duration, estimated_frame_extraction_duration, estimated_face_analysis_duration FROM __temp__video');
        $this->addSql('DROP TABLE __temp__video');
    }
}
