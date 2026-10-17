<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Service\Video\Processing\Message\UploadToJellyfinStepMessage;
use App\Service\Video\Processing\VideoProcessMessageDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class JellyfinController extends AbstractController
{
    /**
     * Stößt den letzten Pipeline-Schritt (Upload nach Jellyfin) für ein bereits analysiertes Video erneut an.
     * Der Schritt ist idempotent: eine schon hochgeladene Datei wird nicht erneut übertragen.
     */
    #[Route('/jellyfin/export/{id}', name: 'app_jellyfin_export', methods: ['POST'])]
    #[IsGranted('VIDEO_EDIT', subject: 'video')]
    public function export(Video $video, Request $request, VideoProcessMessageDispatcher $dispatcher): Response
    {
        if (!$this->isCsrfTokenValid('jellyfin-export' . $video->getId(), $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
        }

        if ($video->getJellyfinItemId() !== null) {
            $this->addFlash('info', 'Das Video ist bereits in Jellyfin vorhanden.');

            return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
        }

        if ($dispatcher->dispatch(
            new UploadToJellyfinStepMessage($video->getId() ?? throw $this->createNotFoundException(), 0, self::class),
        )) {
            $this->addFlash('success', 'Der Export nach Jellyfin wurde gestartet.');
        } else {
            $this->addFlash('danger', 'Der Export nach Jellyfin konnte nicht gestartet werden.');
        }

        return $this->redirectToRoute('app_video_show', ['id' => $video->getId()]);
    }
}
