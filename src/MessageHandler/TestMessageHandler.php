<?php

namespace App\MessageHandler;

use App\Message\TestMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        // Simple log to verify
        echo 'TestMessage received: ' . $message->content . PHP_EOL;
    }
}
