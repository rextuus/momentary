<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Photo;
use App\Service\Photo\PhotoAnalyzer;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ImportPhotosCommandTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/photo-import-' . bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->dir . '/a.JPG', 'erstes');
        $filesystem->dumpFile($this->dir . '/Urlaub/b.heic', 'zweites');
        $filesystem->dumpFile($this->dir . '/Urlaub/c-kopie.jpg', 'zweites');
        $filesystem->dumpFile($this->dir . '/Urlaub/video.mov', 'kein Foto');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([
            $this->dir,
            self::getContainer()->get(StoragePathProvider::class)->getAbsoluteFilePath('photos'),
        ]);
        parent::tearDown();
    }

    public function testImportsFoldersRecursivelyAndSkipsDuplicatesAndOtherFiles(): void
    {
        $kernel = self::bootKernel();
        self::getContainer()->set(PhotoAnalyzer::class, $this->createStub(PhotoAnalyzer::class));

        $tester = new CommandTester(new Application($kernel)->find('app:photo:import'));
        $tester->execute(['paths' => [$this->dir]]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 Foto(s) importiert', $tester->getDisplay());
        self::assertStringContainsString('1 bereits vorhanden', $tester->getDisplay());
        $titles = array_map(
            static fn(Photo $photo): ?string => $photo->getTitle(),
            self::getContainer()->get(EntityManagerInterface::class)->getRepository(Photo::class)->findAll(),
        );
        self::assertEqualsCanonicalizing(['a', 'b'], $titles);
    }

    public function testUnknownOwnerFails(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:photo:import'));

        $tester->execute(['paths' => [$this->dir], '--owner' => 'niemand@example.com']);

        self::assertSame(1, $tester->getStatusCode());
    }
}
