<?php

declare(strict_types=1);

// Liefert PHPStan (phpstan-doctrine) den EntityManager, damit DQL und Repositories geprüft werden können.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
