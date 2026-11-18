<?php

declare(strict_types=1);

namespace App\Service\Video\Processing;

use App\Entity\Video;
use App\Service\Video\Processing\Exception\VideoGoneException;
use App\Service\Video\Processing\Message\ConvertStepMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verschickt den nächsten Pipeline-Schritt.
 *
 * Lässt er sich nicht verschicken (z. B. RabbitMQ nicht erreichbar) oder scheitert er bei synchroner Verarbeitung
 * (Tests, sync-Transport), würde die Kette still abreißen: Der vorige Schritt ist schon abgeschlossen, und kein Worker
 * meldet einen Fehler. Das Video geht deshalb mit Grund und Schritt auf "error" (VideoFailure).
 */
readonly class VideoProcessMessageDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private VideoFailure $videoFailure,
    ) {}

    /**
     * @param bool $failVideoOnError false, wenn der Aufrufer einen Fehlschlag selbst behandelt (Import-Warteschlange)
     */
    public function dispatch(VideoProcessStepMessageInterface $message, bool $failVideoOnError = true): bool
    {
        try {
            $this->logger->info('VideoProcessMessageDispatcher: Dispatching message ' . get_class($message));
            $this->bus->dispatch($message);
        } catch (ExceptionInterface $e) {
            $this->logger->error('VideoProcessMessageDispatcher: Error dispatching message ' . $e->getMessage());
            if ($failVideoOnError) {
                $this->failVideo($message, $e);
            }

            return false;
        }

        return true;
    }

    private function failVideo(VideoProcessStepMessageInterface $message, ExceptionInterface $e): void
    {
        if ($e instanceof HandlerFailedException) {
            $wrapped = $e->getWrappedExceptions();
            // Video gelöscht: nichts zu melden (die Middleware verwirft solche Messages sonst still)
            if ($wrapped !== [] && \count($wrapped) === \count($e->getWrappedExceptions(VideoGoneException::class))) {
                return;
            }
            $first = reset($wrapped);
            $reason = $first !== false ? $first->getMessage() : $e->getMessage();
        } else {
            $reason = 'Schritt konnte nicht gestartet werden (Warteschlange nicht erreichbar): ' . $e->getMessage();
        }

        try {
            $this->videoFailure->fail(
                $message->getVideoId(),
                $message->getVideoStatusForCurrentProcessStepEntity(),
                $reason,
            );
        } catch (\Throwable $failure) {
            $this->logger->error(
                'VideoProcessMessageDispatcher: Video nicht auf Fehler gesetzt: ' . $failure->getMessage(),
            );
        }
    }

    public function dispatchInitialProcessMessage(Video $video): bool
    {
        $videoId = $video->getId() ?? throw new \LogicException('Only persisted videos can be processed.');

        // Scheitert schon der Start, legt ImportQueueManager das Video zurück in die Warteschlange
        return $this->dispatch(new ConvertStepMessage($videoId, 0, 'initial'), failVideoOnError: false);
    }
}
