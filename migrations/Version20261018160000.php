<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aufnahmeort für alle Medien (bisher nur Fotos) und Ortsname';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE medium ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL, ADD place_name VARCHAR(255) DEFAULT NULL',
        );
        $this->addSql(
            'UPDATE medium m JOIN photo p ON p.id = m.id SET m.latitude = p.latitude, m.longitude = p.longitude',
        );
        $this->addSql('ALTER TABLE photo DROP latitude, DROP longitude');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE photo ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL',
        );
        $this->addSql(
            'UPDATE photo p JOIN medium m ON m.id = p.id SET p.latitude = m.latitude, p.longitude = m.longitude',
        );
        $this->addSql('ALTER TABLE medium DROP latitude, DROP longitude, DROP place_name');
    }
}
