<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Entity\Video;
use App\Security\VisibleMedia;
use App\Service\Video\VideoFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Share-Links anlegen, prüfen und ausliefern (siehe documentation/sharing.md).
 */
class ShareLinks
{
    /** Gültigkeit beim Anlegen: Wert für DateTimeImmutable::modify() => Anzeige */
    public const array EXPIRY = [
        '1 day' => '1 Tag',
        '7 days' => '7 Tage',
        '30 days' => '30 Tage',
        '90 days' => '90 Tage',
        'never' => 'unbegrenzt',
    ];
    public const string DEFAULT_EXPIRY = '30 days';

    private const string SESSION_KEY = 'share_unlocked';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoFiles $videoFiles,
        private readonly VisibleMedia $visibleMedia,
        private readonly RateLimiterFactoryInterface $sharePasswordLimiter,
    ) {}

    public function create(
        Event|Medium $target,
        User $creator,
        string $expiry = self::DEFAULT_EXPIRY,
        #[\SensitiveParameter]
        ?string $password = null,
        bool $allowDownload = false,
        ?string $label = null,
    ): ShareLink {
        if (!\array_key_exists($expiry, self::EXPIRY)) {
            $expiry = self::DEFAULT_EXPIRY;
        }
        $link = new ShareLink($target, $creator, $expiry === 'never' ? null : new \DateTimeImmutable('+' . $expiry));
        $link->setPassword($password)->setAllowDownload($allowDownload)->setLabel($label);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link;
    }

    public function revoke(ShareLink $link): void
    {
        $link->revoke();
        $this->entityManager->flush();
    }

    /**
     * Gültiger Link zum Token, sonst null (unbekannt, abgelaufen, widerrufen).
     */
    public function findActive(#[\SensitiveParameter] string $token): ?ShareLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $link = $this->entityManager->getRepository(ShareLink::class)->findOneBy(['token' => $token]);

        return $link?->isActive() ? $link : null;
    }

    /**
     * Links eines Ereignisses bzw. Mediums oder alle (Admins sehen alle, sonst nur eigene), neueste zuerst.
     *
     * @return list<ShareLink>
     */
    public function list(User $user, Event|Medium|null $target = null): array
    {
        $criteria = [];
        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $criteria['createdBy'] = $user;
        }
        if ($target instanceof Event) {
            $criteria['event'] = $target;
        } elseif ($target instanceof Medium) {
            $criteria['medium'] = $target;
        }

        return $this->entityManager->getRepository(ShareLink::class)->findBy($criteria, ['id' => 'DESC']);
    }

    /**
     * Was der Link zeigt: nur Medien, die der Ersteller selbst sehen darf, nach Aufnahmezeit.
     *
     * @return list<Medium>
     */
    public function media(ShareLink $link): array
    {
        $media = $link->getEvent()?->getSortedMedia() ?? array_filter([$link->getMedium()]);

        return array_values(array_filter($media, fn(Medium $medium): bool => $this->visibleMedia->isVisible(
            $link->getCreatedBy(),
            $medium,
        )));
    }

    public function find(ShareLink $link, int $mediumId): ?Medium
    {
        foreach ($this->media($link) as $medium) {
            if ($medium->getId() === $mediumId) {
                return $medium;
            }
        }

        return null;
    }

    public function isUnlocked(ShareLink $link, SessionInterface $session): bool
    {
        return !$link->hasPassword() || \in_array($link->getId(), (array) $session->get(self::SESSION_KEY, []), true);
    }

    /**
     * @throws TooManyShareAttemptsException
     */
    public function unlock(
        ShareLink $link,
        #[\SensitiveParameter]
        string $password,
        SessionInterface $session,
        string $clientIp,
    ): bool {
        $limiter = $this->sharePasswordLimiter->create($link->getId() . '|' . $clientIp);
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyShareAttemptsException();
        }
        if (!$link->checkPassword($password)) {
            return false;
        }
        $limiter->reset();
        $session->set(self::SESSION_KEY, [...((array) $session->get(self::SESSION_KEY, [])), $link->getId()]);

        return true;
    }

    public function recordView(ShareLink $link): void
    {
        $link->recordView();
        $this->entityManager->flush();
    }

    /**
     * Zum Abspielen im Link: die Web-Fassung (Empfänger sind meist unterwegs), sonst das Original.
     */
    public function videoPath(Video $video): ?string
    {
        return $this->videoFiles->streamFile($video)['path'] ?? null;
    }

    /**
     * Originaldatei zum Herunterladen.
     */
    public function originalPath(Medium $medium): ?string
    {
        return match (true) {
            $medium instanceof Photo => $this->videoFiles->existing($medium->getSourceFile()),
            $medium instanceof Video => $this->videoFiles->existing(
                $medium->getSourceFile(),
            ) ?? $this->videoFiles->playablePath($medium),
            default => null,
        };
    }
}
