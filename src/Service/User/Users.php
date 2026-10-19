<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Benutzerkonten anlegen und verwalten (Verwalten → Benutzer).
 *
 * Rollen: Familie (sieht öffentliche Medien und die ihrer Pools), Bearbeiter (pflegt das Archiv: hochladen, Personen
 * benennen) und Admin (alles).
 */
class Users
{
    public const array ROLES = [
        'family' => ['label' => 'Familie', 'roles' => []],
        'editor' => ['label' => 'Bearbeiter', 'roles' => ['ROLE_EDITOR']],
        'admin' => ['label' => 'Admin', 'roles' => ['ROLE_ADMIN']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * @return list<User> nach E-Mail
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(User::class)->findBy([], ['email' => 'ASC']);
    }

    public static function roleOf(User $user): string
    {
        return match (true) {
            \in_array('ROLE_ADMIN', $user->getRoles(), true) => 'admin',
            \in_array('ROLE_EDITOR', $user->getRoles(), true) => 'editor',
            default => 'family',
        };
    }

    /**
     * @return string das Passwort im Klartext (eigenes oder erzeugtes), nur jetzt verfügbar
     */
    public function create(string $email, string $role, #[\SensitiveParameter] ?string $password = null): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben (sie ist der Anmeldename).');
        }
        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            throw new \InvalidArgumentException(sprintf('Es gibt schon ein Konto für %s.', $email));
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(self::rolesFor($role));
        $plain = $this->password($password);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $plain;
    }

    public function setRole(User $user, string $role, User $actor): void
    {
        if ($user->getId() === $actor->getId() && $role !== 'admin') {
            throw new \InvalidArgumentException('Die eigene Admin-Rolle kannst du dir nicht selbst nehmen.');
        }
        $user->setRoles(self::rolesFor($role));
        $this->entityManager->flush();
    }

    /**
     * @return string das neue Passwort im Klartext
     */
    public function resetPassword(User $user, #[\SensitiveParameter] ?string $password = null): string
    {
        $plain = $this->password($password);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
        $this->entityManager->flush();

        return $plain;
    }

    /**
     * @param list<UserGroup> $pools
     */
    public function setPools(User $user, array $pools): void
    {
        $wanted = array_map(static fn(UserGroup $pool): ?int => $pool->getId(), $pools);
        foreach ($this->entityManager->getRepository(UserGroupMember::class)->findBy(['user' => $user]) as $member) {
            if (!\in_array($member->getUserGroup()?->getId(), $wanted, true)) {
                $member->getUserGroup()?->removeMember($member);
                $this->entityManager->remove($member);
            }
        }
        foreach ($pools as $pool) {
            if (!$pool->hasMember($user)) {
                $member = new UserGroupMember()->setUser($user);
                $pool->addMember($member);
                $this->entityManager->persist($member);
            }
        }
        $this->entityManager->flush();
    }

    /**
     * Nur Konten ohne eigene Medien, Ereignisse, Pools oder Share-Links: deren Daten sollen nicht mitverschwinden.
     */
    public function delete(User $user, User $actor): void
    {
        if ($user->getId() === $actor->getId()) {
            throw new \InvalidArgumentException('Das eigene Konto lässt sich hier nicht löschen.');
        }
        foreach ([
            Medium::class => 'owner',
            Event::class => 'owner',
            UserGroup::class => 'owner',
            ShareLink::class => 'createdBy',
        ] as $class => $field) {
            if ($this->entityManager->getRepository($class)->count([$field => $user]) > 0) {
                throw new \InvalidArgumentException(
                    'Das Konto besitzt noch Medien, Ereignisse, Pools oder Share-Links und kann nicht gelöscht werden.',
                );
            }
        }
        foreach ($this->entityManager->getRepository(UserGroupMember::class)->findBy(['user' => $user]) as $member) {
            $member->getUserGroup()?->removeMember($member);
            $this->entityManager->remove($member);
        }
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private static function rolesFor(string $role): array
    {
        return self::ROLES[$role]['roles'] ?? throw new \InvalidArgumentException('Unbekannte Rolle.');
    }

    private function password(#[\SensitiveParameter] ?string $password): string
    {
        $password = (string) $password;
        if ($password === '') {
            // Gut vorlesbar, z. B. für Oma am Telefon: ohne 0/O, 1/l, in Vierergruppen
            $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
            $groups = [];
            for ($group = 0; $group < 3; $group++) {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) {
                    $chunk .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
                }
                $groups[] = $chunk;
            }

            return implode('-', $groups);
        }
        if (mb_strlen($password) < 8) {
            throw new \InvalidArgumentException('Ein eigenes Passwort braucht mindestens 8 Zeichen.');
        }

        return $password;
    }
}
