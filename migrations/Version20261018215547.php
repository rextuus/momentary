<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018215547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pools: Regeln und ausgenommene Medien';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE pool_rule (id INT AUTO_INCREMENT NOT NULL, taken_from DATE DEFAULT NULL, taken_until DATE DEFAULT NULL, pool_id INT NOT NULL, event_id INT DEFAULT NULL, tag_id INT DEFAULT NULL, INDEX IDX_C076BD8B7B3406DF (pool_id), INDEX IDX_C076BD8B71F7E88B (event_id), INDEX IDX_C076BD8BBAD26311 (tag_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'CREATE TABLE user_group_excluded_medium (user_group_id INT NOT NULL, medium_id INT NOT NULL, INDEX IDX_28AA5F191ED93D47 (user_group_id), INDEX IDX_28AA5F19E252B6A5 (medium_id), PRIMARY KEY (user_group_id, medium_id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE pool_rule ADD CONSTRAINT FK_C076BD8B7B3406DF FOREIGN KEY (pool_id) REFERENCES user_group (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE pool_rule ADD CONSTRAINT FK_C076BD8B71F7E88B FOREIGN KEY (event_id) REFERENCES media_event (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE pool_rule ADD CONSTRAINT FK_C076BD8BBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE user_group_excluded_medium ADD CONSTRAINT FK_28AA5F191ED93D47 FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE user_group_excluded_medium ADD CONSTRAINT FK_28AA5F19E252B6A5 FOREIGN KEY (medium_id) REFERENCES medium (id) ON DELETE CASCADE',
        );
        $this->addSql('ALTER TABLE user_group ADD description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_rule DROP FOREIGN KEY FK_C076BD8B7B3406DF');
        $this->addSql('ALTER TABLE pool_rule DROP FOREIGN KEY FK_C076BD8B71F7E88B');
        $this->addSql('ALTER TABLE pool_rule DROP FOREIGN KEY FK_C076BD8BBAD26311');
        $this->addSql('ALTER TABLE user_group_excluded_medium DROP FOREIGN KEY FK_28AA5F191ED93D47');
        $this->addSql('ALTER TABLE user_group_excluded_medium DROP FOREIGN KEY FK_28AA5F19E252B6A5');
        $this->addSql('DROP TABLE pool_rule');
        $this->addSql('DROP TABLE user_group_excluded_medium');
        $this->addSql('ALTER TABLE user_group DROP description');
    }
}
