<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018161934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Als verschieden markierte Personenpaare';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE person_pair_dismissal (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, person_a_id INT NOT NULL, person_b_id INT NOT NULL, UNIQUE INDEX UNIQ_72FA9FD1B138D773A38D789D (person_a_id, person_b_id), INDEX IDX_72FA9FD1B138D773 (person_a_id), INDEX IDX_72FA9FD1A38D789D (person_b_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
        );
        $this->addSql(
            'ALTER TABLE person_pair_dismissal ADD CONSTRAINT FK_72FA9FD1B138D773 FOREIGN KEY (person_a_id) REFERENCES person (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE person_pair_dismissal ADD CONSTRAINT FK_72FA9FD1A38D789D FOREIGN KEY (person_b_id) REFERENCES person (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person_pair_dismissal DROP FOREIGN KEY FK_72FA9FD1B138D773');
        $this->addSql('ALTER TABLE person_pair_dismissal DROP FOREIGN KEY FK_72FA9FD1A38D789D');
        $this->addSql('DROP TABLE person_pair_dismissal');
    }
}
