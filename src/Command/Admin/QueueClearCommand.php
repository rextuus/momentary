<?php

namespace App\Command\Admin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:queue:clear',
    description: 'Leert RabbitMQ Queues über die Management API',
)]
class QueueClearCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Alle Queues leeren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Fetch queues via HttpClient
        $user = $_ENV['RABBITMQ_USER'] ?? 'guest';
        $pass = $_ENV['RABBITMQ_PASS'] ?? 'guest';

        try {
            $response = $this->httpClient->request('GET', 'http://rabbitmq:15672/api/queues/', [
                'auth_basic' => [$user, $pass],
            ]);

            if ($response->getStatusCode() !== 200) {
                $io->error('Fehler beim Abrufen der Queues. HTTP Status: ' . $response->getStatusCode());
                $io->text('Content: ' . $response->getContent(false));
                return Command::FAILURE;
            }

            $queuesData = $response->toArray();
        } catch (\Exception $e) {
            $io->error('Fehler beim Abrufen der Queues: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($queuesData)) {
            $io->note('Keine Queues gefunden.');
            return Command::SUCCESS;
        }

        $queues = [];
        foreach ($queuesData as $q) {
            $queues[] = $q['name'];
        }

        if ($input->getOption('all')) {
            if (!$io->confirm("Sind Sie sicher, dass Sie ALLE Queues (" . implode(', ', $queues) . ") leeren möchten?", true)) {
                $io->warning('Abgebrochen.');
                return Command::FAILURE;
            }

            foreach ($queues as $choice) {
                $this->purgeQueue($io, $choice, $user, $pass);
            }
            return Command::SUCCESS;
        }

        $choice = $io->choice('Welche Queue möchten Sie leeren?', $queues, $queues[0]);

        if (!$io->confirm("Sind Sie sicher, dass Sie die Queue \"$choice\" leeren möchten? Das kann nicht rückgängig gemacht werden.", true)) {
            $io->warning('Abgebrochen.');
            return Command::FAILURE;
        }

        $this->purgeQueue($io, $choice, $user, $pass);
        return Command::SUCCESS;
    }

    private function purgeQueue(SymfonyStyle $io, string $choice, string $user, string $pass): void
    {
        try {
            $this->httpClient->request('DELETE', "http://rabbitmq:15672/api/queues/%2f/$choice/contents", [
                'auth_basic' => [$user, $pass],
            ]);
            $io->success("Queue $choice erfolgreich geleert.");
        } catch (\Exception $e) {
            $io->error("Fehler beim Leeren der Queue $choice: " . $e->getMessage());
        }
    }
}
