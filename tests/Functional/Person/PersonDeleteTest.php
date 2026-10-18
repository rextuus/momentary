<?php

declare(strict_types=1);

namespace App\Tests\Functional\Person;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\VideoFace;
use App\Enum\PersonStatus;
use App\Service\Aws\AmazonRekognitionService;
use App\Tests\Factory\FileFactory;
use App\Tests\Factory\PersonFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFaceFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Fehlerkennungen (Spiegelungen, Plakate …) lassen sich in der Detailansicht komplett löschen.
 */
final class PersonDeleteTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testAdminDeletesPersonWithAllSightingsAndAwsFaces(): void
    {
        $client = static::createClient();
        // Mock muss über alle Requests der Live Component erhalten bleiben
        $client->disableReboot();
        $rekognition = $this->createMock(AmazonRekognitionService::class);
        $rekognition->expects($this->once())->method('deleteFaces')->with(['aws-1', 'aws-2']);
        static::getContainer()->set(AmazonRekognitionService::class, $rekognition);

        $reflection = PersonFactory::createOne(['name' => 'unknown_spiegel', 'status' => PersonStatus::NEW]);
        $other = PersonFactory::createOne(['name' => 'Anna', 'status' => PersonStatus::IDENTIFIED]);
        // Ein Frame mit Anna und der Spiegelung: das Bild muss für Anna erhalten bleiben
        $sharedFrame = FileFactory::createOne();
        $ownFrame = FileFactory::createOne();
        VideoFaceFactory::createOne(['person' => $reflection, 'faceLabel' => 'aws-1', 'faceImage' => $sharedFrame]);
        VideoFaceFactory::createOne(['person' => $reflection, 'faceLabel' => 'aws-2', 'faceImage' => $ownFrame]);
        $annasFace = VideoFaceFactory::createOne([
            'person' => $other,
            'faceImage' => $sharedFrame,
            'detection' => $reflection,
        ]);
        $reflectionId = $reflection->getId();
        $sharedFrameId = $sharedFrame->getId();
        $ownFrameId = $ownFrame->getId();
        $annasFaceId = $annasFace->getId();

        $component = $this
            ->createLiveComponent('PersonDetailComponent', ['person' => $reflection], $client)
            ->actingAs(UserFactory::new()->admin()->create())
            ->call('deletePerson');

        self::assertSame(Response::HTTP_FOUND, $component->response()->getStatusCode());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(Person::class, $reflectionId));
        self::assertSame(1, $entityManager->getRepository(VideoFace::class)->count([]), 'nur Annas Gesicht bleibt');
        self::assertNotNull($entityManager->find(File::class, $sharedFrameId), 'geteilter Frame bleibt');
        self::assertNull($entityManager->find(File::class, $ownFrameId), 'eigener Frame wird gelöscht');
        self::assertNull($entityManager->find(VideoFace::class, $annasFaceId)?->getDetection());
    }

    public function testRegularUserCannotDeletePersons(): void
    {
        $client = static::createClient();
        $person = PersonFactory::createOne(['status' => PersonStatus::NEW]);
        VideoFaceFactory::createOne(['person' => $person]);

        $client->catchExceptions(true);
        $component = $this->createLiveComponent('PersonDetailComponent', ['person' => $person], $client)->actingAs(
            UserFactory::createOne(),
        );

        try {
            $component->call('deletePerson');
            self::fail('Normale Nutzer dürfen keine Personen löschen.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('403', $e->getMessage() . $e->getCode());
        }

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNotNull(
            static::getContainer()->get(EntityManagerInterface::class)->find(Person::class, $person->getId()),
        );
    }
}
