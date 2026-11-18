<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eigenschaften des Originals am Video: Halbbild-Reihenfolge (interlaced Camcorder-Material, für Deinterlacing und
 * eine spätere KI-Aufbereitung) und native Bildhöhe (Bezug der Mindestgröße bei der Gesichtserkennung).
 * Beides wird beim nächsten Verarbeiten gesetzt; bestehende Videos bleiben NULL.
 */
final class Version20261118162218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Video: Halbbild-Reihenfolge (field_order) und native Bildhöhe (source_height) des Originals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD field_order VARCHAR(12) DEFAULT NULL, ADD source_height INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP field_order, DROP source_height');
    }
}
