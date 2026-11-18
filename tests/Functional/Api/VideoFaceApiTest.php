<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gesichter aus InsightFace haben keine Emotion: das Feld fehlt dann (API Platform lässt null-Werte weg).
 * Embedding und Anbieter sind intern und nie Teil der API.
 */
final class VideoFaceApiTest extends WebTestCase
{
    use OAuth2ClientTrait;

    public function testInsightFaceFaceHasNoEmotionAndNoInternals(): void
    {
        $client = static::createClient();
        $admin = UserFactory::new()->admin()->create();
        $video = VideoFactory::new()->completed()->create(['owner' => $admin]);
        $face = VideoFaceFactory::createOne(['video' => $video, 'emotion' => null, 'provider' => 'insightface']);
        $face->setEmbedding([0.6, 0.8], 'buffalo_l/w600k_r50');
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->authenticateApiClient($client, $admin);

        $client->request('GET', '/api/video_faces/' . $face->getId(), server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($face->getId(), $data['id']);
        self::assertArrayHasKey('gender', $data);
        foreach ([
            'emotion',
            'embedding',
            'embeddingData',
            'embeddingModel',
            'provider',
            'detectionScore',
        ] as $internal) {
            self::assertArrayNotHasKey($internal, $data, $internal);
        }
    }
}
