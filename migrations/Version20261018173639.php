<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018173639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serienfotos: Fingerabdruck, Schärfe, bestes Foto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE photo ADD fingerprint LONGTEXT DEFAULT NULL, ADD sharpness DOUBLE PRECISION DEFAULT NULL, ADD burst_pinned TINYINT DEFAULT 0 NOT NULL, ADD burst_excluded TINYINT DEFAULT 0 NOT NULL, ADD burst_cover_id INT DEFAULT NULL',
        );
        $this->addSql(
            'ALTER TABLE photo ADD CONSTRAINT FK_14B78418112E8488 FOREIGN KEY (burst_cover_id) REFERENCES photo (id) ON DELETE SET NULL',
        );
        $this->addSql('CREATE INDEX IDX_14B78418112E8488 ON photo (burst_cover_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo DROP FOREIGN KEY FK_14B78418112E8488');
        $this->addSql('DROP INDEX IDX_14B78418112E8488 ON photo');
        $this->addSql(
            'ALTER TABLE photo DROP fingerprint, DROP sharpness, DROP burst_pinned, DROP burst_excluded, DROP burst_cover_id',
        );
    }
}
