<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Processing;

use App\Enum\VideoStatus;
use App\Service\Video\Processing\Exception\VideoGoneException;
use App\Service\Video\Processing\Message\MergeScenesStepMessage;
use App\Service\Video\Processing\VideoFailure;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lässt sich der nächste Schritt nicht verschicken, darf das Video nicht still stehen bleiben.
 */
final class VideoProcessMessageDispatcherTest extends TestCase
{
    public function testUnreachableQueueFailsTheVideoWithReason(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('Connection refused'));
        $failure = $this->createMock(VideoFailure::class);
        $failure
            ->expects(self::once())
            ->method('fail')
            ->with(7, VideoStatus::MERGING_SCENES, self::callback(
                static fn(string $reason): bool => str_contains(
                    $reason,
                    'Warteschlange nicht erreichbar): Connection refused',
                ),
            ));

        $dispatched = new VideoProcessMessageDispatcher(
            $bus,
            new NullLogger(),
            $failure,
        )->dispatch(new MergeScenesStepMessage(7, 3, 'test'));

        self::assertFalse($dispatched);
    }

    public function testFailedStartIsLeftToTheImportQueue(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('Connection refused'));
        $failure = $this->createMock(VideoFailure::class);
        $failure->expects(self::never())->method('fail');
        $video = new \App\Entity\Video();
        new \ReflectionProperty($video, 'id')->setValue($video, 7);

        self::assertFalse(new VideoProcessMessageDispatcher(
            $bus,
            new NullLogger(),
            $failure,
        )->dispatchInitialProcessMessage($video));
    }

    public function testGoneVideoIsNotReportedAsFailure(): void
    {
        $message = new MergeScenesStepMessage(7, 3, 'test');
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new HandlerFailedException(
            new Envelope($message),
            [VideoGoneException::forId(7)],
        ));
        $failure = $this->createMock(VideoFailure::class);
        $failure->expects(self::never())->method('fail');

        self::assertFalse(new VideoProcessMessageDispatcher($bus, new NullLogger(), $failure)->dispatch($message));
    }
}
