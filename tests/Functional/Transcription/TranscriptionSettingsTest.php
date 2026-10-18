<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcription;

use App\Entity\TranscriptionSettings;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TranscriptionSettingsTest extends WebTestCase
{
    public function testAdminChangesModelAndThreads(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/transcription');
        $client->submitForm('Speichern', [
            'transcription_settings[model]' => 'small',
            'transcription_settings[threads]' => '2',
            'transcription_settings[language]' => '',
        ]);

        self::assertResponseRedirects('/admin/settings/transcription');
        $settings = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(TranscriptionSettings::class)
            ->findAll();
        self::assertCount(1, $settings);
        self::assertSame('small', $settings[0]->getModel());
        self::assertSame(2, $settings[0]->getThreads());
        self::assertNull($settings[0]->getLanguage(), 'leer = Sprache automatisch erkennen');
    }

    public function testInvalidLanguageIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/transcription');
        $client->submitForm('Speichern', ['transcription_settings[language]' => 'Deutsch']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegularUserHasNoAccess(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request('GET', '/admin/settings/transcription');

        self::assertResponseStatusCodeSame(403);
    }
}
