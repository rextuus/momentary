<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Face;

use App\Entity\FaceRecognitionSettings;
use App\Entity\VideoFace;
use App\Service\Face\FaceForgetter;
use App\Service\Face\FaceThresholds;
use App\Service\Face\Provider\FaceRecognitionProviderInterface;
use App\Service\Face\Provider\FaceRecognitionProviders;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FaceForgetterTest extends TestCase
{
    public function testOnlyTheProviderThatCreatedTheFacesIsAsked(): void
    {
        $rekognition = $this->createMock(FaceRecognitionProviderInterface::class);
        $rekognition->expects(self::never())->method('forgetFaces');
        $insightface = $this->createMock(FaceRecognitionProviderInterface::class);
        $insightface->expects(self::once())->method('forgetFaces')->with(['a', 'b']);

        $labels = FaceForgetter::labelsByProvider([
            self::face('a', 'insightface'),
            self::face('b', 'insightface'),
            self::face(null, 'rekognition'),
        ]);
        new FaceForgetter(self::providers($rekognition, $insightface), new NullLogger())->forget($labels, 'Video 1');

        self::assertSame(['insightface' => ['a', 'b']], $labels);
    }

    public function testProviderErrorsAreLoggedAndNeverBlock(): void
    {
        $rekognition = $this->createStub(FaceRecognitionProviderInterface::class);
        $rekognition->method('forgetFaces')->willThrowException(new \RuntimeException('Security token expired'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::logicalAnd(
                self::stringContains('Video 7: 1 Gesichter bei rekognition nicht entfernt'),
                self::stringContains('Security token expired'),
            ));

        new FaceForgetter(
            self::providers($rekognition, $this->createStub(FaceRecognitionProviderInterface::class)),
            $logger,
        )->forget(['rekognition' => ['aws-1']], 'Video 7');
    }

    public function testThresholdsUseTheScaleOfTheProvider(): void
    {
        $settings = new FaceRecognitionSettings();

        $insightface = FaceThresholds::of($settings, 'insightface');
        self::assertEqualsWithDelta(50.0, $insightface->match, 1e-9);
        self::assertEqualsWithDelta(25.0, $insightface->candidate, 1e-9);
        self::assertFalse($insightface->genderCheck, 'standardmäßig aus');

        $rekognition = FaceThresholds::of($settings, 'rekognition');
        self::assertSame(90.0, $rekognition->match);
        self::assertTrue($rekognition->genderCheck);
    }

    private static function providers(
        FaceRecognitionProviderInterface $rekognition,
        FaceRecognitionProviderInterface $insightface,
    ): FaceRecognitionProviders {
        return new FaceRecognitionProviders(new ServiceLocator([
            'rekognition' => static fn() => $rekognition,
            'insightface' => static fn() => $insightface,
        ]), 'insightface');
    }

    private static function face(?string $label, string $provider): VideoFace
    {
        return new VideoFace()
            ->setFaceLabel($label)
            ->setProvider($provider);
    }
}
