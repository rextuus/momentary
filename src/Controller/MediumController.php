<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Medium;
use App\Entity\UserGroup;
use App\Repository\EventRepository;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Media\CameraClock;
use App\Service\Media\RecordingTimeCorrector;
use App\Service\Pool\Pools;
use App\Twig\Extension\MediaExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aktionen, die für Videos und Fotos gleich sind.
 */
final class MediumController extends AbstractController
{
    #[Route('/medium/{id}/recorded-at', name: 'app_medium_recorded_at', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'medium')]
    public function recordedAt(
        Medium $medium,
        Request $request,
        RecordingTimeCorrector $corrector,
        CameraClock $cameraClock,
        EventRepository $eventRepository,
        MediaExtension $media,
    ): Response {
        $back = $request->request->getString('back') ?: $media->mediumPath($medium);
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = $media->mediumPath($medium);
        }

        if (!$this->isCsrfTokenValid('recorded-at' . $medium->getId(), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');

            return $this->redirect($back);
        }

        $correct = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $request->request->getString('recorded_at'),
            new \DateTimeZone('Europe/Berlin'),
        );
        if ($correct === false) {
            $this->addFlash('warning', 'Bitte Datum und Uhrzeit angeben.');

            return $this->redirect($back);
        }

        $event = null;
        $eventId = (int) $request->request->getString('event');
        if ($eventId > 0) {
            $event = $eventRepository->find($eventId);
            if ($event === null || !$event->getMedia()->contains($medium)) {
                throw $this->createNotFoundException('Ereignis nicht gefunden.');
            }
            $user = $this->getUser();
            $ownsEvent = $user !== null && $event->getOwner()?->getUserIdentifier() === $user->getUserIdentifier();
            if (!$ownsEvent && !$this->isGranted('ROLE_ADMIN')) {
                throw $this->createAccessDeniedException(
                    'Nur der Ersteller oder ein Admin darf das ganze Ereignis verschieben.',
                );
            }
        }

        // Gilt für künftige Importe aller Benutzer, deshalb nur für Admins
        $remember = $request->request->getBoolean('remember') && $this->isGranted('ROLE_ADMIN');
        $media = $corrector->correct($medium, $correct, $event);
        if ($remember) {
            try {
                $cameraClock->remember($medium, $correct, $media, $event?->getTitle() ?? $medium->getTitle());
                $this->addFlash('info', 'Abweichung der Kamera-Uhr gemerkt (Verwalten → Kamera-Uhren).');
            } catch (\DomainException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }
        $this->addFlash(
            'success',
            $event !== null
                ? sprintf('Aufnahmezeit korrigiert: %d Medien von „%s“ verschoben.', \count($media), $event->getTitle())
                : 'Aufnahmezeit korrigiert.',
        );

        return $this->redirect($back);
    }

    #[Route('/medium/{id}/pools/{pool}', name: 'app_medium_pool', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'medium')]
    public function pool(
        Medium $medium,
        UserGroup $pool,
        Request $request,
        Pools $pools,
        MediaExtension $media,
    ): Response {
        if ($this->isCsrfTokenValid('medium-pool' . $medium->getId(), $request->request->getString('_token'))) {
            if ($request->request->getString('action') === 'remove') {
                $pools->remove($pool, $medium);
                $this->addFlash('info', sprintf('Aus „%s“ herausgenommen.', $pool->getName()));
            } else {
                $pools->add($pool, $medium);
                $this->addFlash('success', sprintf('In „%s“ aufgenommen.', $pool->getName()));
            }
        }

        return $this->redirect($media->mediumPath($medium) . '#pools');
    }
}
