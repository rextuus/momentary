<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bestätigte Gesichter über das Neuverarbeiten retten: Zeitpunkt, Box und Person am Video merken, bis die neuen
 * Gesichter erkannt sind (VerifiedFaceCarryover).
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Video: gemerkte bestätigte Gesichter beim Neuverarbeiten (verified_face_carryover)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD verified_face_carryover JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP verified_face_carryover');
    }
}
