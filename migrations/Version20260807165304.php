<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260807165304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_access_token (
              identifier CHAR(80) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              expiry DATETIME NOT NULL,
              user_identifier VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              scopes TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              revoked TINYINT NOT NULL,
              client VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              INDEX IDX_454D9673C7440455 (client),
              PRIMARY KEY (identifier)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_access_token
            ADD
              CONSTRAINT `FK_454D9673C7440455` FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_authorization_code (
              identifier CHAR(80) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              expiry DATETIME NOT NULL,
              user_identifier VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              scopes TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              revoked TINYINT NOT NULL,
              client VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              INDEX IDX_509FEF5FC7440455 (client),
              PRIMARY KEY (identifier)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_authorization_code
            ADD
              CONSTRAINT `FK_509FEF5FC7440455` FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_client (
              name VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              secret VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              redirect_uris TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              grants TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              scopes TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              active TINYINT NOT NULL,
              allow_plain_text_pkce TINYINT DEFAULT 0 NOT NULL,
              identifier VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              PRIMARY KEY (identifier)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_refresh_token (
              identifier CHAR(80) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              expiry DATETIME NOT NULL,
              revoked TINYINT NOT NULL,
              access_token CHAR(80) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              INDEX IDX_4DD90732B6A2DD68 (access_token),
              PRIMARY KEY (identifier)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_refresh_token
            ADD
              CONSTRAINT `FK_4DD90732B6A2DD68` FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON
            UPDATE
              NO ACTION ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE person (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              identified TINYINT DEFAULT 0 NOT NULL,
              wasted TINYINT DEFAULT 0 NOT NULL,
              status VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT 'new' NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              show_count INT DEFAULT 0 NOT NULL,
              age INT DEFAULT NULL,
              gender VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              characteristics LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              full_name VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              relation VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              merged_into_id INT DEFAULT NULL,
              profile_face_id INT DEFAULT NULL,
              INDEX IDX_34DCD176285E57BA (merged_into_id),
              INDEX IDX_34DCD1762E76B24C (profile_face_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              person
            ADD
              CONSTRAINT `FK_34DCD176285E57BA` FOREIGN KEY (merged_into_id) REFERENCES person (id) ON
            UPDATE
              NO ACTION ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              person
            ADD
              CONSTRAINT `FK_34DCD1762E76B24C` FOREIGN KEY (profile_face_id) REFERENCES video_face (id) ON
            UPDATE
              NO ACTION ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tag (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              category_id INT NOT NULL,
              INDEX IDX_389B78312469DE2 (category_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              tag
            ADD
              CONSTRAINT `FK_389B78312469DE2` FOREIGN KEY (category_id) REFERENCES tag_category (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tag_category (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              color VARCHAR(7) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user (
              id INT AUTO_INCREMENT NOT NULL,
              email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              roles JSON NOT NULL,
              password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_group (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              owner_id INT NOT NULL,
              INDEX IDX_8F02BF9D7E3C61F9 (owner_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_group
            ADD
              CONSTRAINT `FK_8F02BF9D7E3C61F9` FOREIGN KEY (owner_id) REFERENCES user (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_group_member (
              id INT AUTO_INCREMENT NOT NULL,
              user_id INT NOT NULL,
              user_group_id INT NOT NULL,
              INDEX IDX_23AE5EAA1ED93D47 (user_group_id),
              INDEX IDX_23AE5EAAA76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_group_member
            ADD
              CONSTRAINT `FK_23AE5EAA1ED93D47` FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_group_member
            ADD
              CONSTRAINT `FK_23AE5EAAA76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_settings (
              id INT AUTO_INCREMENT NOT NULL,
              blur_forbidden_content TINYINT NOT NULL,
              user_id INT NOT NULL,
              UNIQUE INDEX UNIQ_5C844C5A76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_settings
            ADD
              CONSTRAINT `FK_5C844C5A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video (
              id INT AUTO_INCREMENT NOT NULL,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              source_file VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              created_at DATETIME NOT NULL,
              status VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              analysis_fps DOUBLE PRECISION DEFAULT NULL,
              min_scene_length_for_refinement DOUBLE PRECISION DEFAULT NULL,
              refined_analysis_fps DOUBLE PRECISION DEFAULT NULL,
              merge_empty_scenes_with_last_person_scene TINYINT DEFAULT 0 NOT NULL,
              error_message LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              local_path VARCHAR(1000) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              converted_video_path VARCHAR(1000) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              thumbnail_path VARCHAR(1000) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              total_frames INT DEFAULT 0 NOT NULL,
              processed_frames INT DEFAULT 0 NOT NULL,
              duration DOUBLE PRECISION DEFAULT NULL,
              current_frame_directory VARCHAR(500) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              current_refinement_frame_directory VARCHAR(500) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              jellyfin_path VARCHAR(511) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              jellyfin_item_id VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              directory_hash VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              owner_id INT DEFAULT NULL,
              is_public TINYINT DEFAULT 0 NOT NULL,
              INDEX IDX_7CC7DA2C7E3C61F9 (owner_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video
            ADD
              CONSTRAINT `FK_7CC7DA2C7E3C61F9` FOREIGN KEY (owner_id) REFERENCES user (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_chapter (
              id INT AUTO_INCREMENT NOT NULL,
              title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              start_seconds DOUBLE PRECISION NOT NULL,
              end_seconds DOUBLE PRECISION NOT NULL,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              video_id INT NOT NULL,
              INDEX IDX_1C6C5C8329C1004E (video_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_chapter
            ADD
              CONSTRAINT `FK_1C6C5C8329C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_face (
              id INT AUTO_INCREMENT NOT NULL,
              timestamp INT NOT NULL,
              face_label VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              face_image_path VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              bounding_box JSON DEFAULT NULL,
              embedding JSON DEFAULT NULL,
              age INT NOT NULL,
              gender VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              emotion VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              match_similarity DOUBLE PRECISION DEFAULT NULL,
              is_verified TINYINT NOT NULL,
              video_id INT DEFAULT NULL,
              person_id INT NOT NULL,
              video_scene_id INT DEFAULT NULL,
              detection_id INT DEFAULT NULL,
              matched_by_id INT DEFAULT NULL,
              INDEX IDX_28326BB1023CA69 (matched_by_id),
              INDEX IDX_28326BB217BBB47 (person_id),
              INDEX IDX_28326BB29C1004E (video_id),
              INDEX IDX_28326BBA8773B17 (detection_id),
              INDEX IDX_28326BBBEE26108 (video_scene_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_face
            ADD
              CONSTRAINT `FK_28326BB1023CA69` FOREIGN KEY (matched_by_id) REFERENCES video_face (id) ON
            UPDATE
              NO ACTION ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_face
            ADD
              CONSTRAINT `FK_28326BB217BBB47` FOREIGN KEY (person_id) REFERENCES person (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_face
            ADD
              CONSTRAINT `FK_28326BB29C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_face
            ADD
              CONSTRAINT `FK_28326BBA8773B17` FOREIGN KEY (detection_id) REFERENCES person (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_face
            ADD
              CONSTRAINT `FK_28326BBBEE26108` FOREIGN KEY (video_scene_id) REFERENCES video_scene (id) ON
            UPDATE
              NO ACTION ON DELETE
            SET
              NULL
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_processing_step (
              id INT AUTO_INCREMENT NOT NULL,
              step VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              created_at DATETIME NOT NULL,
              started_at DATETIME DEFAULT NULL,
              processed_at DATETIME DEFAULT NULL,
              finished_at DATETIME DEFAULT NULL,
              error_message LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              video_id INT NOT NULL,
              duration INT DEFAULT NULL,
              INDEX IDX_6DEE7F5B29C1004E (video_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_processing_step
            ADD
              CONSTRAINT `FK_6DEE7F5B29C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_scene (
              id INT AUTO_INCREMENT NOT NULL,
              scene_number INT NOT NULL,
              start_seconds DOUBLE PRECISION NOT NULL,
              end_seconds DOUBLE PRECISION NOT NULL,
              title VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              thumbnail_url VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              video_id INT NOT NULL,
              is_public TINYINT NOT NULL,
              INDEX IDX_561BE67829C1004E (video_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_scene
            ADD
              CONSTRAINT `FK_561BE67829C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_scene_tag (
              video_scene_id INT NOT NULL,
              tag_id INT NOT NULL,
              INDEX IDX_6638B543BEE26108 (video_scene_id),
              INDEX IDX_6638B543BAD26311 (tag_id),
              PRIMARY KEY (video_scene_id, tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_scene_tag
            ADD
              CONSTRAINT `FK_6638B543BAD26311` FOREIGN KEY (tag_id) REFERENCES tag (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_scene_tag
            ADD
              CONSTRAINT `FK_6638B543BEE26108` FOREIGN KEY (video_scene_id) REFERENCES video_scene (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_scene_user_group (
              video_scene_id INT NOT NULL,
              user_group_id INT NOT NULL,
              INDEX IDX_2F88A7881ED93D47 (user_group_id),
              INDEX IDX_2F88A788BEE26108 (video_scene_id),
              PRIMARY KEY (video_scene_id, user_group_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_scene_user_group
            ADD
              CONSTRAINT `FK_2F88A7881ED93D47` FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_scene_user_group
            ADD
              CONSTRAINT `FK_2F88A788BEE26108` FOREIGN KEY (video_scene_id) REFERENCES video_scene (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_tags (
              video_id INT NOT NULL,
              tag_id INT NOT NULL,
              INDEX IDX_682BC9FA29C1004E (video_id),
              INDEX IDX_682BC9FABAD26311 (tag_id),
              PRIMARY KEY (video_id, tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_tags
            ADD
              CONSTRAINT `FK_682BC9FA29C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_tags
            ADD
              CONSTRAINT `FK_682BC9FABAD26311` FOREIGN KEY (tag_id) REFERENCES tag (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE video_user_group (
              video_id INT NOT NULL,
              user_group_id INT NOT NULL,
              INDEX IDX_FA5A72B1ED93D47 (user_group_id),
              INDEX IDX_FA5A72B29C1004E (video_id),
              PRIMARY KEY (video_id, user_group_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_user_group
            ADD
              CONSTRAINT `FK_FA5A72B1ED93D47` FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_user_group
            ADD
              CONSTRAINT `FK_FA5A72B29C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `oauth2_access_token`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `oauth2_authorization_code`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `oauth2_client`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `oauth2_refresh_token`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `person`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `tag`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `tag_category`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `user`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `user_group`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `user_group_member`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `user_settings`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_chapter`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_face`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_processing_step`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_scene`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_scene_tag`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_scene_user_group`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_tags`');
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQL80Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySQL80Platform'."
        );

        $this->addSql('DROP TABLE `video_user_group`');
    }
}
