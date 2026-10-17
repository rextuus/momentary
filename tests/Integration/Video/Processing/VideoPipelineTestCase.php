<?php

declare(strict_types=1);

namespace App\Tests\Integration\Video\Processing;

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

    protected function trackCreatedPath(string $path): void
    {
        $this->createdPaths[] = $path;
    }
}
