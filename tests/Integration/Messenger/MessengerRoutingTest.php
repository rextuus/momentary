<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\TranscribeVideoMessage;
use App\Service\Video\Processing\Message\TranscribeStepMessage;
use App\Service\Video\Processing\VideoProcessStepMessageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Jede Pipeline-Message muss über die Queue laufen, sonst würde der Schritt
 * synchron im Web-Request bzw. im vorherigen Handler ausgeführt.
 */
final class MessengerRoutingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string<VideoProcessStepMessageInterface>}>
     */
    public static function pipelineMessages(): iterable
    {
        $directory = \dirname(__DIR__, 3) . '/src/Service/Video/Processing/Message';

        foreach (new Finder()
            ->files()
            ->in($directory)
            ->name('*.php')
            ->sortByName() as $file) {
            $class =
                'App\\Service\\Video\\Processing\\Message\\'
                . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!is_subclass_of($class, VideoProcessStepMessageInterface::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            yield $reflection->getShortName() => [$class];
        }
    }

    public function testPipelineMessagesAreDiscovered(): void
    {
        self::assertGreaterThanOrEqual(20, iterator_count(self::pipelineMessages()));
    }

    /**
     * @param class-string<VideoProcessStepMessageInterface> $messageClass
     */
    #[DataProvider('pipelineMessages')]
    public function testPipelineMessageIsRoutedToVideoProcessingTransport(string $messageClass): void
    {
        /** @var SendersLocatorInterface $locator */
        $locator = self::getContainer()->get('messenger.senders_locator');

        // Der Konstruktor ist für die Routing-Entscheidung egal
        $message = new \ReflectionClass($messageClass)->newInstanceWithoutConstructor();
        $transports = array_keys(iterator_to_array($locator->getSenders(new Envelope($message))));

        // Die Transkription läuft stundenlang auf der CPU und hat deshalb einen eigenen Worker
        $expected = $messageClass === TranscribeStepMessage::class ? 'transcription' : 'async_video_processing';
        self::assertSame([$expected], $transports);
    }

    public function testManualTranscriptionUsesTheTranscriptionQueue(): void
    {
        $locator = self::getContainer()->get('messenger.senders_locator');
        $transports = array_keys(iterator_to_array($locator->getSenders(new Envelope(new TranscribeVideoMessage(1)))));

        self::assertSame(['transcription'], $transports);
    }

    /**
     * @param class-string<VideoProcessStepMessageInterface> $messageClass
     */
    #[DataProvider('pipelineMessages')]
    public function testPipelineMessageHasExactlyOneHandler(string $messageClass): void
    {
        $handlersLocator = self::getContainer()->get('messenger.bus.default.messenger.handlers_locator');
        $message = new \ReflectionClass($messageClass)->newInstanceWithoutConstructor();

        self::assertCount(1, iterator_to_array($handlersLocator->getHandlers(new Envelope($message))));
    }
}
