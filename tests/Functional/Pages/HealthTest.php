<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pages;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthIsPublicAndReportsDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'database' => 'ok'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }
}
