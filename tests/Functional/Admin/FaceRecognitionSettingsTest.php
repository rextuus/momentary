<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\InsightFace\FaceServiceClient;
use App\Service\Face\InsightFace\FaceServiceException;
use App\Service\Face\Provider\FaceRecognitionProviderInterface;
use App\Service\Face\Provider\FaceRecognitionProviders;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use App\Tests\Factory\VideoFactory;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FaceRecognitionSettingsTest extends WebTestCase
{
    public function testAdminChangesSettingsAtRuntime(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('face_recognition_settings[matchThreshold]', '90.0');

        $client->submit($crawler
            ->selectButton('Speichern')
            ->form([
                'face_recognition_settings[qualityFilter]' => 'MEDIUM',
                'face_recognition_settings[matchThreshold]' => '92.5',
                'face_recognition_settings[videoLinkThreshold]' => '78',
                'face_recognition_settings[minFaceWidth]' => '5',
                'face_recognition_settings[trackingEnabled]' => false,
            ]));

        self::assertResponseRedirects('/admin/settings/face-recognition');
        $settings = self::getContainer()->get(FaceRecognitionSettingsProvider::class)->get();
        self::assertSame('MEDIUM', $settings->getQualityFilter());
        self::assertSame(92.5, $settings->getMatchThreshold());
        self::assertSame(78.0, $settings->getVideoLinkThreshold());
        self::assertFalse($settings->isTrackingEnabled());
        self::assertSame(0.05, $settings->getMinFaceWidth(), 'Prozentangabe wird als Anteil gespeichert');
        self::assertNotNull($settings->getUpdatedAt());
    }

    public function testInsightFaceShowsOnlyItsOwnFieldsAndKeepsRekognitionValues(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $provider = $this->createStub(FaceRecognitionProviderInterface::class);
        self::getContainer()->set(FaceRecognitionProviders::class, new FaceRecognitionProviders(new ServiceLocator([
            'insightface' => static fn() => $provider,
        ]), 'insightface'));
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'InsightFace (selbst gehostet)');
        self::assertSelectorNotExists('[name="face_recognition_settings[qualityFilter]"]');
        self::assertSelectorExists('[data-remote-content-url-value$="/face-recognition/face-service/status"]');
        self::assertSelectorNotExists('#aws-reset-form + *, .fa-aws');
        self::assertInputValueSame('face_recognition_settings[insightFaceMatchThreshold]', '0.50');
        self::assertInputValueSame('face_recognition_settings[insightFaceMinFacePx]', '40');

        $client->submit($crawler
            ->selectButton('Speichern')
            ->form([
                'face_recognition_settings[insightFaceMatchThreshold]' => '0.55',
                'face_recognition_settings[insightFaceMinFacePx]' => '48',
                'face_recognition_settings[insightFaceMaxFaces]' => '0',
                'face_recognition_settings[insightFaceGenderCheck]' => true,
            ]));

        self::assertResponseRedirects('/admin/settings/face-recognition');
        $settings = self::getContainer()->get(FaceRecognitionSettingsProvider::class)->get();
        self::assertSame(0.55, $settings->getInsightFaceMatchThreshold());
        self::assertSame(48, $settings->getInsightFaceMinFacePx());
        self::assertSame(0, $settings->getInsightFaceMaxFaces());
        self::assertTrue($settings->isInsightFaceGenderCheck());
        self::assertSame(90.0, $settings->getMatchThreshold(), 'Rekognition-Werte bleiben');
        self::assertSame(0.03, $settings->getMinFaceWidth());
    }

    public function testInsightFaceCandidateThresholdAboveOthersIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $provider = $this->createStub(FaceRecognitionProviderInterface::class);
        self::getContainer()->set(FaceRecognitionProviders::class, new FaceRecognitionProviders(new ServiceLocator([
            'insightface' => static fn() => $provider,
        ]), 'insightface'));
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $crawler = $client->submit($crawler
            ->selectButton('Speichern')
            ->form(['face_recognition_settings[insightFaceCandidateThreshold]' => '0.6']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Muss unter allen anderen Schwellen liegen',
            $crawler->filter('.invalid-feedback')->text(),
        );
    }

    public function testInvalidValuesAreRejected(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $client->submit($crawler
            ->selectButton('Speichern')
            ->form([
                'face_recognition_settings[matchThreshold]' => '150',
            ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            90.0,
            self::getContainer()->get(FaceRecognitionSettingsProvider::class)->get()->getMatchThreshold(),
        );
    }

    public function testCandidateThresholdAboveOtherThresholdsIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $crawler = $client->submit($crawler
            ->selectButton('Speichern')
            ->form([
                'face_recognition_settings[candidateThreshold]' => '95',
            ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Muss unter allen anderen Schwellen liegen',
            $crawler->filter('.invalid-feedback')->text(),
        );
    }

    public function testResetRestoresDefaults(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $client->submit($crawler
            ->selectButton('Speichern')
            ->form(['face_recognition_settings[matchThreshold]' => '95']));
        $crawler = $client->followRedirect();

        $client->submit($crawler->filter('#reset-form')->form());

        self::assertResponseRedirects('/admin/settings/face-recognition');
        self::assertSame(
            90.0,
            self::getContainer()->get(FaceRecognitionSettingsProvider::class)->get()->getMatchThreshold(),
        );
    }

    public function testFaceServiceStatusShowsModelAndFaces(): void
    {
        $client = static::createClient();
        $faceService = $this->createStub(FaceServiceClient::class);
        $faceService
            ->method('health')
            ->willReturn([
                'status' => 'ok',
                'model' => 'buffalo_l/w600k_r50',
                'det_size' => 640,
                'intra_op_threads' => 2,
                'inter_op_threads' => 1,
            ]);
        self::getContainer()->set(FaceServiceClient::class, $faceService);
        VideoFaceFactory::createMany(3, ['provider' => 'insightface']);
        VideoFaceFactory::createOne();
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/face-recognition/face-service/status');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'bereit');
        self::assertSelectorTextContains('body', 'buffalo_l/w600k_r50');
        self::assertSelectorTextContains('dl', '3', 'nur InsightFace-Gesichter');
    }

    public function testFaceServiceStatusShowsErrorInsteadOfFailing(): void
    {
        $client = static::createClient();
        $faceService = $this->createStub(FaceServiceClient::class);
        $faceService->method('health')->willThrowException(new FaceServiceException('Connection refused'));
        self::getContainer()->set(FaceServiceClient::class, $faceService);
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/face-recognition/face-service/status');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'face-service nicht erreichbar: Connection refused');
    }

    public function testAwsStatusShowsFaceCount(): void
    {
        $client = static::createClient();
        $rekognition = $this->createStub(AmazonRekognitionService::class);
        $rekognition->method('countIndexedFaces')->willReturn(1234);
        $rekognition->method('getCollectionId')->willReturn('test-collection');
        self::getContainer()->set(AmazonRekognitionService::class, $rekognition);
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/face-recognition/aws/status');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1.234');
        self::assertSelectorTextContains('body', 'test-collection');
    }

    public function testAwsStatusShowsErrorInsteadOfFailing(): void
    {
        $client = static::createClient();
        $rekognition = $this->createStub(AmazonRekognitionService::class);
        $rekognition
            ->method('countIndexedFaces')
            ->willThrowException(
                new AwsException('Invalid security token', $this->createStub(CommandInterface::class)),
            );
        self::getContainer()->set(AmazonRekognitionService::class, $rekognition);
        $client->loginUser(UserFactory::new()->admin()->create());

        $client->request('GET', '/admin/settings/face-recognition/aws/status');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'AWS nicht erreichbar');
    }

    public function testAdminResetsAwsCollection(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $rekognition = $this->createMock(AmazonRekognitionService::class);
        $rekognition->expects(self::once())->method('resetCollection');
        self::getContainer()->set(AmazonRekognitionService::class, $rekognition);
        $client->loginUser(UserFactory::new()->admin()->create());

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $client->submit($crawler->filter('#aws-reset-form')->form());

        self::assertResponseRedirects('/admin/settings/face-recognition');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'neu angelegt');
    }

    public function testAwsResetWithoutValidTokenDoesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $rekognition = $this->createMock(AmazonRekognitionService::class);
        $rekognition->expects(self::never())->method('resetCollection');
        self::getContainer()->set(AmazonRekognitionService::class, $rekognition);
        $client->loginUser(UserFactory::new()->admin()->create());

        // @mago-expect lint:no-literal-password
        $client->request('POST', '/admin/settings/face-recognition/aws/reset', ['_token' => 'falsch']);

        self::assertResponseRedirects('/admin/settings/face-recognition');
    }

    public function testAdminRegroupsAllVideos(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::new()->admin()->create());
        VideoFactory::createMany(2);

        $crawler = $client->request('GET', '/admin/settings/face-recognition');
        $client->submit($crawler->filter('#consolidate-form')->form());
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-success', '2 Videos neu gruppiert');
    }

    public function testRegularUserCannotTriggerAwsActions(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::createOne());

        foreach ([
            '/admin/settings/face-recognition/aws/reset',
            '/admin/settings/face-recognition/consolidate',
        ] as $url) {
            $client->request('POST', $url);
            self::assertResponseStatusCodeSame(403);
        }
        $client->request('GET', '/admin/settings/face-recognition/aws/status');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotOpenSettings(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::createOne());
        $client->request('GET', '/admin/settings/face-recognition');

        self::assertResponseStatusCodeSame(403);
    }
}
