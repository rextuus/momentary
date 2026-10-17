<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Basis für Tests, die die Pipeline über den (im Test-Env synchronen) Message-Bus laufen lassen.
 */
abstract class VideoPipelineTestCase extends KernelTestCase
{
    /** @var list<string> */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->createdPaths);
        $this->createdPaths = [];

        parent::tearDown();
    }

    /**
     * Die Handler schreiben ihren Fortschritt für das Worker-Log auf stdout.
     * Hier wird diese Ausgabe abgefangen, damit sie den Testlauf nicht stört.
     */
    protected function dispatch(object $message): string
    {
        ob_start();
        try {
            self::getContainer()->get('messenger.bus.default')->dispatch($message);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * ID einer bereits persistierten Entity.
     */
    protected static function idOf(object $entity): int
    {
        \assert(method_exists($entity, 'getId'), 'Entity braucht eine getId()-Methode.');
        $id = $entity->getId();
        self::assertIsInt($id, sprintf('%s ist noch nicht persistiert.', $entity::class));

        return $id;
    }

    /**
     * Lädt das Video frisch aus der Datenbank, damit der Stand nach der Pipeline geprüft wird.
     */
    protected function reload(Video $video): Video
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Video::class, self::idOf($video));
        self::assertNotNull($reloaded, 'Video wurde während der Pipeline gelöscht.');

        return $reloaded;
    }

    /**
     * Der einzige fehlgeschlagene Verarbeitungsschritt des Videos.
     */
    protected static function singleFailedStep(Video $video): VideoProcessingStep
    {
        $failed = $video
            ->getProcessingSteps()
            ->filter(static fn(VideoProcessingStep $step): bool => $step->getErrorMessage() !== null);
        self::assertCount(1, $failed);
        $step = $failed->first();
        self::assertInstanceOf(VideoProcessingStep::class, $step);

        return $step;
    }

    protected function trackCreatedPath(string $path): void
    {
        $this->createdPaths[] = $path;
    }
}
