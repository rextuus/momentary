<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519082658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video ADD COLUMN error_message CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__video AS SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, local_path FROM video');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, youtube_url VARCHAR(255) DEFAULT NULL, source_file VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL, analysis_fps DOUBLE PRECISION DEFAULT NULL, min_scene_length_for_refinement DOUBLE PRECISION DEFAULT NULL, refined_analysis_fps DOUBLE PRECISION DEFAULT NULL, merge_empty_scenes_with_last_person_scene BOOLEAN DEFAULT 0 NOT NULL, local_path VARCHAR(1000) DEFAULT NULL)');
        $this->addSql('INSERT INTO video (id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, local_path) SELECT id, title, youtube_url, source_file, created_at, status, analysis_fps, min_scene_length_for_refinement, refined_analysis_fps, merge_empty_scenes_with_last_person_scene, local_path FROM __temp__video');
        $this->addSql('DROP TABLE __temp__video');
    }
}
