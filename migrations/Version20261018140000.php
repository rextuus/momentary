<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Medien-Basistyp: Video und Foto erben von "medium" (Class Table Inheritance), dazu Ereignisse.
 *
 * Bestehende Videos werden übernommen: jede Video-ID wird zur Medium-ID, Titel/Rechte/Tags wandern nach "medium",
 * Gesichter verweisen auf das Medium statt auf das Video.
 */
final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Medium-Basistyp für Videos und Fotos, Fotos, Ereignisse; bestehende Videos werden übernommen';
    }

    public function up(Schema $schema): void
    {
        // 1. Neue Tabellen
        $this->addSql(
            'CREATE TABLE medium (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, taken_at DATETIME DEFAULT NULL, is_public TINYINT DEFAULT 0 NOT NULL, owner_id INT DEFAULT NULL, type VARCHAR(16) NOT NULL, INDEX IDX_C67345B7C48F0688 (taken_at), INDEX IDX_C67345B77E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE medium_user_group (medium_id INT NOT NULL, user_group_id INT NOT NULL, INDEX IDX_978D59A1E252B6A5 (medium_id), INDEX IDX_978D59A11ED93D47 (user_group_id), PRIMARY KEY (medium_id, user_group_id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE medium_tags (medium_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_F7A33EF7E252B6A5 (medium_id), INDEX IDX_F7A33EF7BAD26311 (tag_id), PRIMARY KEY (medium_id, tag_id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE photo (file_hash VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, error_message LONGTEXT DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, camera VARCHAR(128) DEFAULT NULL, source_file_id INT DEFAULT NULL, display_file_id INT DEFAULT NULL, thumbnail_file_id INT DEFAULT NULL, id INT NOT NULL, UNIQUE INDEX UNIQ_14B7841858440AD6 (file_hash), INDEX IDX_14B78418DA14C104 (source_file_id), INDEX IDX_14B78418A4249BF7 (display_file_id), INDEX IDX_14B78418B16F1215 (thumbnail_file_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media_event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, owner_id INT DEFAULT NULL, INDEX IDX_24B7CE167E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE media_event_medium (event_id INT NOT NULL, medium_id INT NOT NULL, INDEX IDX_9D1B986971F7E88B (event_id), INDEX IDX_9D1B9869E252B6A5 (medium_id), PRIMARY KEY (event_id, medium_id)) DEFAULT CHARACTER SET utf8mb4',
        );

        // 2. Bestehende Videos übernehmen (gleiche IDs; AUTO_INCREMENT von "medium" läuft danach hinter der höchsten ID weiter)
        $this->addSql(
            "INSERT INTO medium (id, type, title, created_at, is_public, owner_id) SELECT id, 'video', title, created_at, is_public, owner_id FROM video",
        );
        $this->addSql(
            'INSERT INTO medium_user_group (medium_id, user_group_id) SELECT video_id, user_group_id FROM video_user_group',
        );
        $this->addSql('INSERT INTO medium_tags (medium_id, tag_id) SELECT video_id, tag_id FROM video_tags');

        // 3. Fremdschlüssel der neuen Tabellen
        $this->addSql(
            'ALTER TABLE medium ADD CONSTRAINT FK_C67345B77E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)',
        );
        $this->addSql(
            'ALTER TABLE medium_user_group ADD CONSTRAINT FK_978D59A1E252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE medium_user_group ADD CONSTRAINT FK_978D59A11ED93D47 FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE medium_tags ADD CONSTRAINT FK_F7A33EF7E252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE medium_tags ADD CONSTRAINT FK_F7A33EF7BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE photo ADD CONSTRAINT FK_14B78418BF396750 FOREIGN KEY (id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE photo ADD CONSTRAINT FK_14B78418DA14C104 FOREIGN KEY (source_file_id) REFERENCES files (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE photo ADD CONSTRAINT FK_14B78418A4249BF7 FOREIGN KEY (display_file_id) REFERENCES files (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE photo ADD CONSTRAINT FK_14B78418B16F1215 FOREIGN KEY (thumbnail_file_id) REFERENCES files (id) ON DELETE SET NULL',
        );
        $this->addSql(
            'ALTER TABLE media_event ADD CONSTRAINT FK_24B7CE167E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)',
        );
        $this->addSql(
            'ALTER TABLE media_event_medium ADD CONSTRAINT FK_9D1B986971F7E88B FOREIGN KEY (event_id) REFERENCES media_event (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE media_event_medium ADD CONSTRAINT FK_9D1B9869E252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );

        // 4. Alte Video-Tabellen/-Spalten entfernen (Daten sind übernommen)
        $this->addSql('ALTER TABLE video_tags DROP FOREIGN KEY `FK_682BC9FA29C1004E`');
        $this->addSql('ALTER TABLE video_tags DROP FOREIGN KEY `FK_682BC9FABAD26311`');
        $this->addSql('ALTER TABLE video_user_group DROP FOREIGN KEY `FK_FA5A72B1ED93D47`');
        $this->addSql('ALTER TABLE video_user_group DROP FOREIGN KEY `FK_FA5A72B29C1004E`');
        $this->addSql('DROP TABLE video_tags');
        $this->addSql('DROP TABLE video_user_group');
        $this->addSql('ALTER TABLE video DROP FOREIGN KEY `FK_7CC7DA2C7E3C61F9`');
        $this->addSql('DROP INDEX IDX_7CC7DA2C7E3C61F9 ON video');
        // Kapitel, Szenen usw. verweisen auf video.id; Typ und Werte bleiben gleich, nur AUTO_INCREMENT entfällt
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql(
            'ALTER TABLE video DROP title, DROP created_at, DROP is_public, DROP owner_id, CHANGE id id INT NOT NULL',
        );
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
        $this->addSql(
            'ALTER TABLE video ADD CONSTRAINT FK_7CC7DA2CBF396750 FOREIGN KEY (id) REFERENCES medium (id) ON DELETE CASCADE',
        );

        // 5. Gesichter hängen am Medium (Video-IDs sind jetzt Medium-IDs, die Werte bleiben gültig)
        $this->addSql('ALTER TABLE video_face DROP FOREIGN KEY `FK_28326BB29C1004E`');
        $this->addSql('DROP INDEX IDX_28326BB29C1004E ON video_face');
        $this->addSql('ALTER TABLE video_face CHANGE video_id medium_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE video_face ADD CONSTRAINT FK_28326BBE252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql('CREATE INDEX IDX_28326BBE252B6A5 ON video_face (medium_id)');
    }

    public function down(Schema $schema): void
    {
        // Fotos und Ereignisse gehen beim Zurückrollen verloren; Videos werden zurückgeführt
        $this->addSql('ALTER TABLE video_face DROP FOREIGN KEY FK_28326BBE252B6A5');
        $this->addSql('DROP INDEX IDX_28326BBE252B6A5 ON video_face');
        $this->addSql("DELETE FROM video_face WHERE medium_id IN (SELECT id FROM medium WHERE type <> 'video')");
        $this->addSql('ALTER TABLE video_face CHANGE medium_id video_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_28326BB29C1004E ON video_face (video_id)');

        $this->addSql('ALTER TABLE video DROP FOREIGN KEY FK_7CC7DA2CBF396750');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql(
            'ALTER TABLE video ADD title VARCHAR(255) DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, ADD is_public TINYINT DEFAULT 0 NOT NULL, ADD owner_id INT DEFAULT NULL, CHANGE id id INT AUTO_INCREMENT NOT NULL',
        );
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
        $this->addSql(
            'UPDATE video v JOIN medium m ON m.id = v.id SET v.title = m.title, v.created_at = m.created_at, v.is_public = m.is_public, v.owner_id = m.owner_id',
        );
        $this->addSql('ALTER TABLE video MODIFY title VARCHAR(255) NOT NULL, MODIFY created_at DATETIME NOT NULL');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C7E3C61F9 ON video (owner_id)');
        $this->addSql(
            'ALTER TABLE video ADD CONSTRAINT FK_7CC7DA2C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)',
        );
        $this->addSql(
            'ALTER TABLE video_face ADD CONSTRAINT FK_28326BB29C1004E FOREIGN KEY (video_id) REFERENCES video (id)',
        );

        $this->addSql(
            'CREATE TABLE video_tags (video_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_682BC9FA29C1004E (video_id), INDEX IDX_682BC9FABAD26311 (tag_id), PRIMARY KEY (video_id, tag_id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE video_user_group (video_id INT NOT NULL, user_group_id INT NOT NULL, INDEX IDX_FA5A72B29C1004E (video_id), INDEX IDX_FA5A72B1ED93D47 (user_group_id), PRIMARY KEY (video_id, user_group_id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'INSERT INTO video_tags (video_id, tag_id) SELECT t.medium_id, t.tag_id FROM medium_tags t JOIN video v ON v.id = t.medium_id',
        );
        $this->addSql(
            'INSERT INTO video_user_group (video_id, user_group_id) SELECT g.medium_id, g.user_group_id FROM medium_user_group g JOIN video v ON v.id = g.medium_id',
        );
        $this->addSql(
            'ALTER TABLE video_tags ADD CONSTRAINT FK_682BC9FA29C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE video_tags ADD CONSTRAINT FK_682BC9FABAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE video_user_group ADD CONSTRAINT FK_FA5A72B29C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE video_user_group ADD CONSTRAINT FK_FA5A72B1ED93D47 FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE',
        );

        $this->addSql('DROP TABLE media_event_medium');
        $this->addSql('DROP TABLE media_event');
        $this->addSql('DROP TABLE photo');
        $this->addSql('DROP TABLE medium_tags');
        $this->addSql('DROP TABLE medium_user_group');
        $this->addSql('DROP TABLE medium');
    }
}
