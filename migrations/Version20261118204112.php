<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rohe Schnitte der Szenenerkennung am Video (vor dem Zusammenlegen leerer Szenen), für den späteren Abgleich einer
 * aufbereiteten Fassung mit dem Original. Bestehende Videos bekommen sie beim nächsten Verarbeiten.
 */
final class Version20261118204112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Video: rohe Schnitte der Szenenerkennung (scene_cuts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD scene_cuts JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP scene_cuts');
    }
}
