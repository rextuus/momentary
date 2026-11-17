<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Für Uptime-Checks und `make prod-status`: ohne Anmeldung, verrät nichts außer „läuft / Datenbank erreichbar“.
 */
final class HealthController
{
    #[Route(path: '/healthz', name: 'app_healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'error', 'database' => 'error'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'ok']);
    }
}
