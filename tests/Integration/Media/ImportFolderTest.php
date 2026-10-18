<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\Video;
use App\Message\ScanImportFolderMessage;
use App\MessageHandler\ScanImportFolderMessageHandler;
use App\Service\Media\ImportFolder;
use App\Service\Media\MediaImporter;
use App\Service\Photo\PhotoAnalyzer;
use App\Service\Photo\PhotoSearchIndex;
use App\Service\Storage\StoragePathProvider;
use App\Service\Video\Queue\ImportQueueManager;
use App\Service\VideoSearchIndex;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VideoFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImportFolderTest extends KernelTestCase
{
    private string $root;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(PhotoAnalyzer::class, $this->createStub(PhotoAnalyzer::class));
        $container->set(ImportQueueManager::class, $this->createStub(ImportQueueManager::class));
        $container->set(PhotoSearchIndex::class, $this->createStub(PhotoSearchIndex::class));
        $container->set(VideoSearchIndex::class, $this->createStub(VideoSearchIndex::class));

        // Im Test liegt der Storage unter var/storage (siehe phpunit.xml.dist und tests/bootstrap.php)
        $this->root = $container->get(StoragePathProvider::class)->getImportAbsolutePath();
        if (!str_contains($this->root, '/var/storage/')) {
            self::fail('Abbruch: Import-Ordner liegt nicht im Test-Storage: ' . $this->root);
        }
        new Filesystem()->remove($this->root);
    }

    protected function tearDown(): void
    {
        $paths = self::getContainer()->get(StoragePathProvider::class);
        $filesystem = new Filesystem();
        $filesystem->remove($this->root);
        foreach (['videos', 'photos'] as $dir) {
            $filesystem->remove($paths->getAbsoluteFilePath($dir));
        }
        parent::tearDown();
    }

    private function put(string $relativePath, string $content, string $age = '-1 hour'): void
    {
        $path = $this->root . '/' . $relativePath;
        new Filesystem()->dumpFile($path, $content);
        touch($path, (int) strtotime($age));
    }

    public function testListsSupportedFilesWithBatchAndReadiness(): void
    {
        $this->put('Mallorca/VIDEO/20250319_121537.MOV', 'video');
        $this->put('Mallorca/JPG/20250319_133537.JPG', 'foto');
        $this->put('einzeln.heic', 'heic', 'now');
        $this->put('notizen.txt', 'text');
        $this->put('.versteckt.jpg', 'x');
        $this->put(ImportFolder::DUPLICATES_DIR . '/alt.jpg', 'alt');

        $files = $this->folder()->files();

        self::assertSame(
            ['Mallorca/JPG/20250319_133537.JPG', 'Mallorca/VIDEO/20250319_121537.MOV', 'einzeln.heic'],
            array_map(static fn($f) => $f->relativePath, $files),
        );
        self::assertSame(['Mallorca', 'Mallorca', null], array_map(static fn($f) => $f->batch, $files));
        self::assertSame(
            [MediaImporter::TYPE_PHOTO, MediaImporter::TYPE_VIDEO, MediaImporter::TYPE_PHOTO],
            array_map(static fn($f) => $f->type, $files),
        );
        self::assertSame(
            [true, true, false],
            array_map(static fn($f) => $f->ready, $files),
            'gerade geänderte Datei wird evtl. noch hochgeladen',
        );
    }

    public function testRecognizesOldVideosWithoutHashByTheTimestampInTheName(): void
    {
        // Ältere Videos: kein Hash, nur Kamerazeit (aus dem Dateinamen, 12:15:37 deutscher Zeit)
        $video = VideoFactory::createOne();
        $video->setRecordedAt(
            new \DateTimeImmutable('2025-03-19 11:15:37', new \DateTimeZone('UTC')),
            Medium::DATE_FILENAME,
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->put('mallorca/20250319_121537.MOV', 'video');
        $this->put('mallorca/20250319_121538.MOV', 'anderes video');

        $files = $this->folder()->files();

        self::assertSame([true, false], array_map(static fn($f) => $f->likelyDuplicate, $files));
        self::assertSame(1, $this->folder()->queue(), 'automatisch nur, was nicht vermutlich schon da ist');
        self::assertFileExists($this->root . '/mallorca/20250319_121537.MOV');
    }

    public function testQueueImportsReadyFilesAndMovesDuplicatesAside(): void
    {
        $owner = UserFactory::createOne();
        $this->put('Mallorca/VIDEO/20250319_121537.MOV', 'video');
        $this->put('Mallorca/JPG/20250319_133537.JPG', 'foto');
        $this->put('Mallorca/JPG/kopie.jpg', 'foto');
        $this->put('noch-im-upload.jpg', 'neu', 'now');

        // Messenger läuft im Test synchron: die Nachrichten werden sofort verarbeitet
        $count = $this->folder()->queue($owner);

        self::assertSame(3, $count, 'nur fertig hochgeladene Dateien');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $photos = $em->getRepository(Photo::class)->findAll();
        $videos = $em->getRepository(Video::class)->findAll();
        self::assertCount(1, $photos, 'Kopie mit gleichem Inhalt zählt nur einmal');
        self::assertCount(1, $videos);
        foreach ([$photos[0], $videos[0]] as $medium) {
            self::assertSame($owner->getId(), $medium->getOwner()?->getId());
            self::assertSame(['Mallorca'], array_map(static fn($t) => $t->getName(), $medium->getTags()->toArray()));
        }

        self::assertFileDoesNotExist($this->root . '/Mallorca/VIDEO/20250319_121537.MOV');
        self::assertFileDoesNotExist($this->root . '/Mallorca/JPG/20250319_133537.JPG');
        self::assertFileExists(
            $this->root . '/' . ImportFolder::DUPLICATES_DIR . '/Mallorca/JPG/kopie.jpg',
            'nichts wird ungefragt gelöscht',
        );
        self::assertFileExists($this->root . '/noch-im-upload.jpg');
        self::assertSame(
            ['noch-im-upload.jpg'],
            array_map(static fn($f) => $f->relativePath, $this->folder()->files()),
        );
    }

    public function testSelectedFilesAndPathsOutsideTheFolder(): void
    {
        $this->put('a.jpg', 'a', 'now');
        $this->put('b.jpg', 'b');
        new Filesystem()->dumpFile(\dirname($this->root) . '/geheim.jpg', 'geheim');

        self::assertSame(1, $this->folder()->queue(null, ['a.jpg']), 'ausgewählte Dateien auch wenn gerade geändert');
        self::assertNull($this->folder()->import('../geheim.jpg'));
        self::assertNull($this->folder()->import('gibt-es-nicht.jpg'));
        self::assertFileExists(\dirname($this->root) . '/geheim.jpg');
        new Filesystem()->remove(\dirname($this->root) . '/geheim.jpg');
    }

    public function testScheduledScanOnlyWhenWatchingIsEnabled(): void
    {
        $folder = $this->createMock(ImportFolder::class);
        $folder->method('isWatched')->willReturn(false);
        $folder->expects(self::never())->method('queue');
        new ScanImportFolderMessageHandler($folder)(new ScanImportFolderMessage());

        $watched = $this->createMock(ImportFolder::class);
        $watched->method('isWatched')->willReturn(true);
        $watched->expects(self::once())->method('queue');
        new ScanImportFolderMessageHandler($watched)(new ScanImportFolderMessage());
    }

    public function testDetectsTheMediaType(): void
    {
        self::assertSame(MediaImporter::TYPE_VIDEO, MediaImporter::typeOf('clip.MTS'));
        self::assertSame(MediaImporter::TYPE_PHOTO, MediaImporter::typeOf('IMG_0001.HEIC'));
        self::assertNull(MediaImporter::typeOf('notes.txt'));
        self::assertNull(MediaImporter::typeOf('ohne-endung'));
    }

    private function folder(): ImportFolder
    {
        return self::getContainer()->get(ImportFolder::class);
    }
}
