<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018144154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Herkunft der Aufnahmezeit (EXIF, Dateiname, Metadaten, von Hand korrigiert)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE medium ADD date_source VARCHAR(16) DEFAULT NULL');
        // Fotos bekamen ihre Aufnahmezeit bisher nur aus EXIF
        $this->addSql("UPDATE medium SET date_source = 'exif' WHERE type = 'photo' AND taken_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE medium DROP date_source');
    }
}
