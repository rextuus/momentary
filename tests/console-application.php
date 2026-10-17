<?php

declare(strict_types=1);

// Liefert PHPStan (phpstan-symfony) die Console-Application, damit Command-Argumente und -Optionen geprüft werden.
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

return new Application(new Kernel('test', true));
