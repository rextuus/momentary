<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
