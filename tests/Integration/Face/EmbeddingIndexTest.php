<?php

declare(strict_types=1);

namespace App\Tests\Integration\Face;

use App\Entity\Video;
use App\Service\Face\Embedding\EmbeddingIndex;
use App\Service\Face\Embedding\EmbeddingMath;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmbeddingIndexTest extends KernelTestCase
{
    private const string MODEL = 'buffalo_l/w600k_r50';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testComparesWithLimitedReferencesPerPersonVerifiedFirst(): void
    {
        $otherVideo = VideoFactory::createOne();
        $anna = PersonFactory::createOne();
        // Höchstens zwei Referenzen: das verifizierte und das sicherste bleiben; das kleine fällt trotz Treffer weg
        $this->face('anna-verified', [1.0, 0.1], $anna, $otherVideo, verified: true, score: 80.0);
        $this->face('anna-best', [1.0, 0.2], $anna, $otherVideo, score: 99.0);
        $this->face('anna-weak', [1.0, 0.0], $anna, $otherVideo, score: 75.0);
        $this->face('anna-tiny', [1.0, 0.0], $anna, $otherVideo, score: 99.0, sizePx: 30);

        $hits = $this->index(referencesPerPerson: 2)->candidates(
            self::vector(1.0, 0.0),
            (int) VideoFactory::createOne()->getId(),
            self::MODEL,
            0.5,
            10,
        );

        self::assertSame(['anna-verified', 'anna-best'], array_keys($hits));
    }

    public function testFacesOfTheSameMediumAreAlwaysCandidates(): void
    {
        $video = VideoFactory::createOne();
        $anna = PersonFactory::createOne();
        $this->face('anna-1', [1.0, 0.0], $anna, VideoFactory::createOne(), score: 99.0);
        $this->face('anna-in-video', [1.0, 0.05], $anna, $video, score: 10.0);

        $hits = $this->index(referencesPerPerson: 1)->candidates(
            self::vector(1.0, 0.0),
            (int) $video->getId(),
            self::MODEL,
            0.5,
            10,
        );

        self::assertEqualsCanonicalizing(['anna-1', 'anna-in-video'], array_keys($hits));
    }

    public function testOtherModelsAndWeakMatchesAreIgnored(): void
    {
        $video = VideoFactory::createOne();
        $this->face('same', [1.0, 0.0], PersonFactory::createOne(), $video);
        $this->face('other-model', [1.0, 0.0], PersonFactory::createOne(), $video, model: 'buffalo_s/w600k_mbf');
        $this->face('stranger', [0.0, 1.0], PersonFactory::createOne(), $video);

        $hits = $this->index()->candidates(self::vector(1.0, 0.0), (int) $video->getId(), self::MODEL, 0.3, 10);

        self::assertSame(['same'], array_keys($hits));
        self::assertEqualsWithDelta(1.0, $hits['same'], 1e-6);
    }

    public function testRememberAndForget(): void
    {
        $video = VideoFactory::createOne();
        $mediumId = (int) $video->getId();
        $index = $this->index();
        self::assertSame([], $index->candidates(self::vector(1.0, 0.0), $mediumId, self::MODEL, 0.3, 10));

        $index->remember('new', self::vector(1.0, 0.0), $mediumId, self::MODEL);
        self::assertSame(
            ['new'],
            array_keys($index->candidates(self::vector(1.0, 0.0), $mediumId, self::MODEL, 0.3, 10)),
        );

        $index->forget(['new']);
        self::assertSame([], $index->candidates(self::vector(1.0, 0.0), $mediumId, self::MODEL, 0.3, 10));
    }

    private function index(int $referencesPerPerson = 20): EmbeddingIndex
    {
        return new EmbeddingIndex(
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(FaceRecognitionSettingsProvider::class),
            $referencesPerPerson,
        );
    }

    /**
     * @param array{float, float} $direction
     */
    private function face(
        string $label,
        array $direction,
        object $person,
        Video $video,
        bool $verified = false,
        float $score = 90.0,
        string $model = self::MODEL,
        int $sizePx = 100,
    ): void {
        $face = VideoFaceFactory::createOne([
            'faceLabel' => $label,
            'person' => $person,
            'video' => $video,
            'isVerified' => $verified,
            'detectionScore' => $score,
            'faceSizePx' => $sizePx,
            'provider' => 'insightface',
        ]);
        $face->setEmbedding(self::vector(...$direction), $model);
        self::getContainer()->get('doctrine')->getManager()->flush();
    }

    /**
     * @return list<float>
     */
    private static function vector(float $x, float $y): array
    {
        return EmbeddingMath::normalize([$x, $y, ...array_fill(0, 510, 0.0)]);
    }
}
