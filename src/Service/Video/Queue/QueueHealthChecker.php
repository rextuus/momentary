<?php

declare(strict_types=1);

namespace App\Service\Video\Queue;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Laufen die Worker? Fragt die RabbitMQ-Management-API nach wartenden Nachrichten und Consumern je Queue.
 *
 * Ein Problem ist es, wenn Nachrichten warten und kein Worker sie abholt (z. B. face-worker gestoppt). Ist die API
 * nicht erreichbar oder abgeschaltet (RABBITMQ_MANAGEMENT_URL leer, z. B. in Tests), ist der Zustand unbekannt; das
 * gilt nicht als Fehler.
 */
class QueueHealthChecker
{
    /** Queue (wie in messenger.yaml) => Compose-Dienst, der sie abarbeitet */
    public const array WORKERS = [
        'messages' => 'messenger-worker',
        'photos' => 'messenger-worker',
        'face_recognition' => 'face-worker',
        'transcription' => 'transcription-worker',
    ];

    /** @var list<QueueStatus>|false|null false = schon versucht, nicht erreichbar */
    private array|false|null $cache = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(RABBITMQ_MANAGEMENT_URL)%')]
        private readonly string $managementUrl,
        #[Autowire('%env(RABBITMQ_USER)%')]
        private readonly string $user,
        #[\SensitiveParameter]
        #[Autowire('%env(RABBITMQ_PASS)%')]
        private readonly string $password,
    ) {}

    /**
     * @return list<QueueStatus>|null null, wenn der Zustand unbekannt ist
     */
    public function queues(): ?array
    {
        if ($this->cache === null) {
            $this->cache = $this->fetch() ?? false;
        }

        return $this->cache === false ? null : $this->cache;
    }

    /**
     * false nur, wenn sicher ist, dass Nachrichten liegen bleiben.
     */
    public function isWorkerRunning(): bool
    {
        foreach ($this->queues() ?? [] as $queue) {
            if ($queue->isStuck()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<QueueStatus>|null
     */
    private function fetch(): ?array
    {
        if ($this->managementUrl === '') {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', rtrim($this->managementUrl, '/') . '/api/queues', [
                'auth_basic' => [$this->user, $this->password],
                // Das Dashboard soll nicht auf RabbitMQ warten
                'timeout' => 2,
                'max_duration' => 3,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->info('QueueHealthChecker: RabbitMQ-Management nicht erreichbar: ' . $e->getMessage());

            return null;
        }

        $byName = [];
        foreach ($data as $queue) {
            if (\is_array($queue) && \is_string($queue['name'] ?? null)) {
                $byName[$queue['name']] = $queue;
            }
        }

        $statuses = [];
        foreach (self::WORKERS as $name => $worker) {
            $queue = $byName[$name] ?? [];
            $statuses[] = new QueueStatus(
                $name,
                $worker,
                is_numeric($queue['messages'] ?? null) ? (int) $queue['messages'] : 0,
                is_numeric($queue['consumers'] ?? null) ? (int) $queue['consumers'] : 0,
            );
        }

        return $statuses;
    }
}
