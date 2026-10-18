<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcription;

use App\Entity\Video;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\TranscriptionStatus;
use App\Enum\VideoStatus;
use App\Service\Gemini\GeminiService;
use App\Service\Storage\StoragePathProvider;
use App\Service\Transcription\AudioExtractor;
use App\Service\Transcription\WhisperRunner;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use App\Tests\Factory\VideoSceneFactory;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Button "Ton transkribieren": im Test-Env läuft die Nachricht synchron, Whisper und Gemini sind ersetzt.
 */
final class TranscribeVideoTest extends WebTestCase
{
    private KernelBrowser $client;
    private WhisperRunner&Stub $whisper;
    private GeminiService&Stub $gemini;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $audio = $this->createStub(AudioExtractor::class);
        $audio->method('extract')->willReturn(60.0);
        $this->whisper = $this->createStub(WhisperRunner::class);
        $this->gemini = $this->createStub(GeminiService::class);
        $meili = $this->createStub(Client::class);
        $index = $this->createStub(Indexes::class);
        $index->method('getFilterableAttributes')->willReturn(['tags', 'persons']);
        $meili->method('getIndex')->willReturn($index);

        $container = static::getContainer();
        $container->set(AudioExtractor::class, $audio);
        $container->set(WhisperRunner::class, $this->whisper);
        $container->set(GeminiService::class, $this->gemini);
        $container->set(Client::class, $meili);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->createdFiles);
        parent::tearDown();
    }

    public function testTranscribesSpeechFiltersNoiseAndTagsTheMatchingScene(): void
    {
        $video = $this->videoWithFile();
        $party = VideoSceneFactory::createOne([
            'video' => $video,
            'sceneNumber' => 1,
            'startSeconds' => 0.0,
            'endSeconds' => 30.0,
        ]);
        $pool = VideoSceneFactory::createOne([
            'video' => $video,
            'sceneNumber' => 2,
            'startSeconds' => 30.0,
            'endSeconds' => 60.0,
        ]);

        $this->whisper
            ->method('run')
            ->willReturn([
                'language' => 'de',
                'duration' => 60.0,
                'segments' => [
                    [
                        'start' => 2.0,
                        'end' => 5.0,
                        'text' => 'Happy Birthday, liebe Lisa!',
                        'avg_logprob' => -0.2,
                        'no_speech_prob' => 0.01,
                    ],
                    [
                        'start' => 40.0,
                        'end' => 44.0,
                        'text' => 'Untertitel im Auftrag des ZDF',
                        'avg_logprob' => -0.9,
                        'no_speech_prob' => 0.4,
                    ],
                ],
            ]);
        $this->gemini
            ->method('tagTranscript')
            ->willReturn([
                (int) $party->getId() => ['Anlässe' => ['Geburtstag'], 'Erwähnte Personen' => ['Lisa']],
            ]);

        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', '/video/' . $video->getId());
        $this->client->submitForm('Ton transkribieren');

        self::assertResponseRedirects();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Video::class, $video->getId());
        $transcript = $reloaded?->getTranscript();

        self::assertNotNull($transcript);
        self::assertSame(TranscriptionStatus::DONE, $transcript->getStatus());
        self::assertSame('de', $transcript->getLanguage());
        self::assertSame('Happy Birthday, liebe Lisa!', $transcript->getFullText(), 'Halluzination wurde verworfen');

        $tags = static fn(int $sceneId): array => array_map(
            static fn($tag): ?string => $tag->getName(),
            $entityManager->find(VideoScene::class, $sceneId)?->getTags() ?? [],
        );
        self::assertEqualsCanonicalizing(['Geburtstag', 'Lisa'], $tags((int) $party->getId()));
        self::assertSame([], $tags((int) $pool->getId()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('#transcript', 'Happy Birthday, liebe Lisa!');
    }

    public function testFailureIsShownInsteadOfRetrying(): void
    {
        $video = $this->videoWithFile();
        $this->whisper->method('run')->willThrowException(new \RuntimeException('Modell nicht gefunden'));

        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', '/video/' . $video->getId());
        $this->client->submitForm('Ton transkribieren');
        $this->client->followRedirect();

        self::assertSelectorTextContains('#transcript', 'Transkription fehlgeschlagen');
        self::assertSelectorTextContains('#transcript', 'Modell nicht gefunden');
        self::assertSelectorExists('#transcript button:contains("Neu transkribieren")');
    }

    public function testNotOfferedBeforeAnalysisIsFinished(): void
    {
        $video = VideoFactory::createOne(['status' => VideoStatus::TAGGING_SCENES]);

        $this->client->loginUser(UserFactory::new()->admin()->create());
        $this->client->request('GET', '/video/' . $video->getId());

        self::assertSelectorNotExists('#transcript form');
        self::assertSelectorTextContains('#transcript', 'erst möglich, wenn die Analyse');
    }

    private function videoWithFile(): Video
    {
        $file = FileFactory::createOne([
            'relativePath' => 'videos/transcribe-test.mp4',
            'purpose' => FilePurpose::VIDEO_CONVERTED,
        ]);
        $path = static::getContainer()->get(StoragePathProvider::class)->getAbsolutePath($file);
        new Filesystem()->dumpFile($path, 'dummy');
        $this->createdFiles[] = $path;

        return VideoFactory::new()->completed()->create(['convertedFile' => $file]);
    }
}
