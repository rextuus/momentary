<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Selbst gehostete Gesichtserkennung (InsightFace): Anbieter und Embedding je Gesicht, eigene Filter und Schwellen.
 *
 * Nur ausreichend sichere und große Gesichter dienen als Referenz einer Person (insight_face_reference_*).
 *
 * Die alte JSON-Spalte video_face.embedding war nie befüllt und entfällt; Embeddings liegen jetzt als float32 in
 * embedding_data (2 KB). Bestehende Gesichter stammen aus Rekognition (provider = rekognition).
 */
final class Version20261118151552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gesichtserkennung: InsightFace (Anbieter, Embedding, Score je Gesicht; eigene Schwellen), Emotion optional';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE face_recognition_settings ADD insight_face_min_face_px INT DEFAULT 40 NOT NULL, ADD insight_face_max_faces INT DEFAULT 10 NOT NULL, ADD insight_face_candidate_threshold DOUBLE PRECISION DEFAULT 0.25 NOT NULL, ADD insight_face_video_link_threshold DOUBLE PRECISION DEFAULT 0.35 NOT NULL, ADD insight_face_suggestion_threshold DOUBLE PRECISION DEFAULT 0.38 NOT NULL, ADD insight_face_match_threshold DOUBLE PRECISION DEFAULT 0.5 NOT NULL, ADD insight_face_reference_min_score DOUBLE PRECISION DEFAULT 0.7 NOT NULL, ADD insight_face_reference_min_face_px INT DEFAULT 60 NOT NULL, ADD insight_face_gender_check TINYINT DEFAULT 0 NOT NULL',
        );
        $this->addSql(
            "ALTER TABLE video_face ADD provider VARCHAR(20) DEFAULT 'rekognition' NOT NULL, ADD embedding_data BLOB DEFAULT NULL, ADD embedding_model VARCHAR(64) DEFAULT NULL, ADD detection_score DOUBLE PRECISION DEFAULT NULL, ADD face_size_px INT DEFAULT NULL, DROP embedding, CHANGE emotion emotion VARCHAR(255) DEFAULT NULL",
        );
        $this->addSql('CREATE INDEX idx_video_face_embedding_model ON video_face (embedding_model)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE face_recognition_settings DROP insight_face_min_face_px, DROP insight_face_max_faces, DROP insight_face_candidate_threshold, DROP insight_face_video_link_threshold, DROP insight_face_suggestion_threshold, DROP insight_face_match_threshold, DROP insight_face_reference_min_score, DROP insight_face_reference_min_face_px, DROP insight_face_gender_check',
        );
        $this->addSql('DROP INDEX idx_video_face_embedding_model ON video_face');
        // InsightFace-Gesichter haben keine Emotion; die alte Spalte verlangt einen Wert
        $this->addSql("UPDATE video_face SET emotion = 'UNKNOWN' WHERE emotion IS NULL");
        $this->addSql(
            'ALTER TABLE video_face ADD embedding JSON DEFAULT NULL, DROP provider, DROP embedding_data, DROP embedding_model, DROP detection_score, DROP face_size_px, CHANGE emotion emotion VARCHAR(255) NOT NULL',
        );
    }
}
