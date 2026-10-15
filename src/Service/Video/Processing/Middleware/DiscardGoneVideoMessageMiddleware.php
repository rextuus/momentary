<?php

declare(strict_types=1);

namespace App\Service\Video\Processing\Middleware;

use App\Service\Video\Processing\Exception\VideoGoneException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class DiscardGoneVideoMessageMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $e) {
            $all = $e->getWrappedExceptions();
            $gone = $e->getWrappedExceptions(VideoGoneException::class);

            if ($all !== [] && count($all) === count($gone)) {
                $this->logger->info(sprintf(
                    'Verwerfe Message %s: %s',
                    get_class($envelope->getMessage()),
                    $gone[array_key_first($gone)]->getMessage()
                ));

                return $envelope; // gilt als erfolgreich verarbeitet -> ack
            }

            throw $e;
        }
    }
}