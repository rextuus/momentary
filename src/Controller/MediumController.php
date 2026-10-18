<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Medium;
use App\Repository\EventRepository;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Media\RecordingTimeCorrector;
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
        $eventId = $request->request->getInt('event');
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

        $count = $corrector->correct($medium, $correct, $event);
        $this->addFlash(
            'success',
            $event !== null
                ? sprintf('Aufnahmezeit korrigiert: %d Medien von „%s“ verschoben.', $count, $event->getTitle())
                : 'Aufnahmezeit korrigiert.',
        );

        return $this->redirect($back);
    }
}
