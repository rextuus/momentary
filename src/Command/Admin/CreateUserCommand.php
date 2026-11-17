<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\User\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konten von der Konsole anlegen, z. B. Testkonten für den Hub; dieselben Regeln wie unter Verwalten → Benutzer.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Legt ein Benutzerkonto an (Familie, Bearbeiter oder Admin) und nimmt es in Pools auf',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly Users $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse (Anmeldename)')
            ->addOption(
                'role',
                null,
                InputOption::VALUE_REQUIRED,
                'Rolle: ' . implode(', ', array_keys(Users::ROLES)),
                'family',
            )
            ->addOption(
                'pool',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Pool (Name oder ID), mehrfach möglich',
            )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Passwort für Skripte; sonst verdeckt abgefragt (leer = gut lesbares Passwort erzeugen)',
            )
            ->addOption(
                'reset-password',
                null,
                InputOption::VALUE_NONE,
                'Gibt es das Konto schon, nur dessen Passwort neu setzen',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $role = (string) $input->getOption('role');

        try {
            $pools = $this->pools((array) $input->getOption('pool'));
            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing !== null && !$input->getOption('reset-password')) {
                throw new \InvalidArgumentException(sprintf(
                    'Es gibt schon ein Konto für %s (mit --reset-password nur das Passwort neu setzen).',
                    $email,
                ));
            }

            $password = $input->getOption('password');
            if ($password === null && $input->isInteractive()) {
                $password = $io->askHidden('Passwort (leer lassen = erzeugen)');
            }

            if ($existing !== null) {
                $plain = $this->users->resetPassword($existing, $password);
                $io->success(sprintf('Neues Passwort für %s gesetzt.', $email));
            } else {
                $plain = $this->users->create($email, $role, $password);
                $user = $this->entityManager
                    ->getRepository(User::class)
                    ->findOneBy(['email' => $email]) ?? throw new \LogicException('Das eben angelegte Konto fehlt.');
                $this->users->setPools($user, $pools);
                $io->success(sprintf(
                    'Benutzer %s angelegt (%s, Pools: %s).',
                    $email,
                    Users::ROLES[$role]['label'],
                    $pools === []
                        ? 'keine'
                        : implode(', ', array_map(
                            static fn(UserGroup $pool): string => (string) $pool->getName(),
                            $pools,
                        )),
                ));
            }
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!hash_equals($plain, (string) $password)) {
            $io->writeln(sprintf('Passwort (wird nur jetzt angezeigt): <info>%s</info>', $plain));
        }

        return Command::SUCCESS;
    }

    /**
     * Erst alle Pools auflösen, damit bei einem Tippfehler kein halbes Konto entsteht.
     *
     * @param array<mixed> $names
     *
     * @return list<UserGroup>
     */
    private function pools(array $names): array
    {
        $repository = $this->entityManager->getRepository(UserGroup::class);
        $pools = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $found = ctype_digit($name) ? $repository->findBy(['id' => (int) $name]) : [];
            if ($found === []) {
                $found = $repository->findBy(['name' => $name]);
            }
            if (\count($found) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    $found === [] ? 'Pool „%s“ gibt es nicht.' : 'Mehrere Pools heißen „%s“, bitte die ID angeben.',
                    $name,
                ));
            }
            $pools[(int) $found[0]->getId()] = $found[0];
        }

        return array_values($pools);
    }
}
