<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\VideoTranscript;
use App\Enum\TranscriptionStatus;
use App\Form\TranscriptionSettingsType;
use App\Service\Transcription\TranscriptionSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class TranscriptionSettingsController extends AbstractController
{
    #[Route('/admin/settings/transcription', name: 'app_admin_transcription_settings', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TranscriptionSettingsProvider $settingsProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $settings = $settingsProvider->get();
        $form = $this->createForm(TranscriptionSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settingsProvider->save($settings);
            $this->addFlash('success', 'Einstellungen gespeichert. Sie gelten ab der nächsten Transkription.');

            return $this->redirectToRoute('app_admin_transcription_settings');
        }

        $counts = [];
        foreach (TranscriptionStatus::cases() as $status) {
            $counts[$status->value] = $entityManager
                ->getRepository(VideoTranscript::class)
                ->count(['status' => $status]);
        }

        return $this->render(
            'admin/settings/transcription.html.twig',
            ['form' => $form, 'settings' => $settings, 'transcriptCounts' => $counts],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    #[Route('/admin/settings/transcription/reset', name: 'app_admin_transcription_settings_reset', methods: ['POST'])]
    public function reset(Request $request, TranscriptionSettingsProvider $settingsProvider): Response
    {
        if ($this->isCsrfTokenValid('transcription-reset', $request->request->getString('_token'))) {
            $settingsProvider->reset();
            $this->addFlash('success', 'Standardwerte wiederhergestellt.');
        }

        return $this->redirectToRoute('app_admin_transcription_settings');
    }
}
