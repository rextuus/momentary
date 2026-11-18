<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video\Queue;

use App\Service\Video\Queue\QueueHealthChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QueueHealthCheckerTest extends TestCase
{
    public function testStoppedFaceWorkerIsReported(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
        ): JsonMockResponse {
            $requests[] = $url;

            return new JsonMockResponse([
                ['name' => 'messages', 'messages' => 0, 'consumers' => 1],
                ['name' => 'face_recognition', 'messages' => 42, 'consumers' => 0],
                ['name' => 'transcription', 'messages' => 0, 'consumers' => 1],
                ['name' => 'fremd', 'messages' => 9, 'consumers' => 0],
            ]);
        });
        $checker = new QueueHealthChecker($client, new NullLogger(), 'http://rabbitmq:15672/', 'u', 'p');

        self::assertFalse($checker->isWorkerRunning());
        $queues = $checker->queues();
        self::assertNotNull($queues);
        self::assertSame(
            ['messages', 'photos', 'face_recognition', 'transcription'],
            array_map(static fn($q): string => $q->name, $queues),
        );
        self::assertSame('face-worker', $queues[2]->worker);
        self::assertTrue($queues[2]->isStuck());
        self::assertFalse($queues[1]->isStuck(), 'photos: noch nicht angelegt, nichts wartet');
        self::assertSame(['http://rabbitmq:15672/api/queues'], $requests, 'nur eine Anfrage je Seitenaufruf');
    }

    public function testUnreachableApiMeansUnknownNotStopped(): void
    {
        $checker = new QueueHealthChecker(new MockHttpClient(new MockResponse('', [
            'error' => 'Connection refused',
        ])), new NullLogger(), 'http://rabbitmq:15672', 'u', 'p');

        self::assertNull($checker->queues());
        self::assertTrue($checker->isWorkerRunning());
    }

    public function testDisabledWithoutUrl(): void
    {
        $client = new MockHttpClient(static fn() => throw new \LogicException('keine Anfrage erwartet'));

        self::assertNull(new QueueHealthChecker($client, new NullLogger(), '', 'u', 'p')->queues());
    }
}
