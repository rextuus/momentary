<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Video;
use App\Enum\FilePurpose;
use App\Service\Storage\StoragePathProvider;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Signierte Stream-URLs für Hub und Android-App (ohne Jellyfin-API-Key).
 */
final class VideoStreamTest extends WebTestCase
{
    use OAuth2ClientTrait;

    private KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->directory = static::getContainer()
            ->get(StoragePathProvider::class)
            ->getAbsoluteFilePath('videos/stream-test');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function playableVideo(array $attributes = []): Video
    {
        new Filesystem()->dumpFile($this->directory . '/converted.mp4', str_repeat('0123456789', 100));
        $video = VideoFactory::new()->completed()->create($attributes);
        $video->setConvertedFile(FileFactory::createOne([
            'relativePath' => 'videos/stream-test/converted.mp4',
            'purpose' => FilePurpose::VIDEO_CONVERTED,
            'mimeType' => 'video/mp4',
        ]));
        static::getContainer()->get('doctrine')->getManager()->flush();

        return $video;
    }

    /**
     * @return array<string, mixed>
     */
    private function streamUrl(Video $video): array
    {
        $this->client->request('GET', sprintf('/api/videos/%d/stream-url', $video->getId()));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testOwnerGetsASignedUrlThatStreamsWithoutLoginAndSupportsSeeking(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->playableVideo(['owner' => $owner]);
        $this->authenticateApiClient($this->client, $owner);

        $data = $this->streamUrl($video);

        self::assertResponseIsSuccessful();
        self::assertSame('video/mp4', $data['contentType']);
        self::assertSame('original', $data['quality'], 'ohne Web-Fassung das Original');
        self::assertFalse($data['hasWebVersion']);
        self::assertStringStartsWith(sprintf('http://localhost/stream/video/%d?', $video->getId()), $data['url']);
        self::assertStringContainsString('_hash=', $data['url']);
        self::assertStringNotContainsString('api_key', $data['url']);
        $expires = new \DateTimeImmutable($data['expiresAt']);
        self::assertGreaterThan(new \DateTimeImmutable('+5 hours'), $expires);

        // Ohne Token, wie ein Fernseher beim Casten
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', $data['url'], server: ['HTTP_RANGE' => 'bytes=10-19']);
        self::assertResponseStatusCodeSame(206);
        self::assertSame('bytes 10-19/1000', $this->client->getResponse()->headers->get('Content-Range'));
        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTamperedExpiredAndForeignUrlsAreRejected(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->playableVideo(['owner' => $owner]);
        $other = VideoFactory::new()->completed()->create(['owner' => $owner]);
        $this->authenticateApiClient($this->client, $owner);
        $url = (string) $this->streamUrl($video)['url'];

        $attempts = [
            'andere ID' => str_replace('/stream/video/' . $video->getId(), '/stream/video/' . $other->getId(), $url),
            'Ablauf verlängert' => (string) preg_replace(
                '/_expiration=\d+/',
                '_expiration=' . (time() + 999_999),
                $url,
            ),
            'ohne Signatur' => explode('?', $url)[0],
            'Qualität umgestellt' => str_replace('quality=original', 'quality=web', $url),
        ];
        foreach ($attempts as $label => $attempt) {
            $this->client->request('GET', $attempt);
            self::assertResponseStatusCodeSame(403, $label);
        }

        $expired = static::getContainer()
            ->get(\App\Service\Video\VideoStreamUrls::class)
            ->create($video, $this->client->getRequest(), -10);
        $this->client->request('GET', $expired['url']);
        self::assertResponseStatusCodeSame(403, 'abgelaufen');
    }

    public function testWebVersionIsStreamedByDefaultAndTheOriginalOnRequest(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->playableVideo(['owner' => $owner]);
        new Filesystem()->dumpFile($this->directory . '/web.mp4', 'klein');
        $video->setWebFile(FileFactory::createOne([
            'relativePath' => 'videos/stream-test/web.mp4',
            'purpose' => FilePurpose::VIDEO_WEB,
            'mimeType' => 'video/mp4',
        ]));
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->authenticateApiClient($this->client, $owner);

        $web = $this->streamUrl($video);
        self::assertSame(['web', true], [$web['quality'], $web['hasWebVersion']]);
        $this->client->request('GET', sprintf('/api/videos/%d/stream-url?quality=original', $video->getId()));
        $original = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('original', $original['quality']);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', (string) $web['url']);
        self::assertSame('5', $this->client->getResponse()->headers->get('Content-Length'), 'Web-Fassung');
        $this->client->request('GET', (string) $original['url']);
        self::assertSame('1000', $this->client->getResponse()->headers->get('Content-Length'), 'Original');
    }

    public function testOnlyVisibleAndPlayableVideosGetAUrl(): void
    {
        $owner = UserFactory::createOne();
        $video = $this->playableVideo(['owner' => $owner]);
        $withoutFile = VideoFactory::new()->completed()->create(['owner' => $owner]);

        $this->client->request('GET', sprintf('/api/videos/%d/stream-url', $video->getId()));
        self::assertResponseStatusCodeSame(401, 'nur mit Token');

        $this->authenticateApiClient($this->client, UserFactory::createOne());
        $this->streamUrl($video);
        self::assertResponseStatusCodeSame(403, 'privates Video eines anderen');

        $this->authenticateApiClient($this->client, $owner);
        $this->streamUrl($withoutFile);
        self::assertResponseStatusCodeSame(409, 'noch keine MP4');
    }
}
