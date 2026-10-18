<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\FaceRecognitionSettingsType;
use App\Repository\VideoRepository;
use App\Service\Aws\AmazonRekognitionService;
use App\Service\Face\FaceRecognitionSettingsProvider;
use App\Service\Face\VideoFaceConsolidator;
use Aws\Exception\AwsException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class FaceRecognitionSettingsController extends AbstractController
{
    #[Route('/admin/settings/face-recognition', name: 'app_admin_face_recognition_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, FaceRecognitionSettingsProvider $settingsProvider): Response
    {
        $settings = $settingsProvider->get();
        $form = $this->createForm(FaceRecognitionSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settingsProvider->save($settings);
            $this->addFlash('success', 'Einstellungen gespeichert. Sie gelten für alle ab jetzt analysierten Frames.');

            return $this->redirectToRoute('app_admin_face_recognition_settings');
        }

        return $this->render(
            'admin/settings/face_recognition.html.twig',
            [
                'form' => $form,
                'settings' => $settings,
            ],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    #[Route(
        '/admin/settings/face-recognition/reset',
        name: 'app_admin_face_recognition_settings_reset',
        methods: ['POST'],
    )]
    public function reset(Request $request, FaceRecognitionSettingsProvider $settingsProvider): Response
    {
        if ($this->isCsrfTokenValid('face-recognition-reset', $request->request->getString('_token'))) {
            $settingsProvider->reset();
            $this->addFlash('success', 'Standardwerte wiederhergestellt.');
        }

        return $this->redirectToRoute('app_admin_face_recognition_settings');
    }

    /**
     * Wird von der Einstellungsseite nachgeladen, damit die Seite nicht auf AWS warten muss.
     */
    #[Route(
        '/admin/settings/face-recognition/aws/status',
        name: 'app_admin_face_recognition_aws_status',
        methods: ['GET'],
    )]
    public function awsStatus(AmazonRekognitionService $rekognition): Response
    {
        try {
            $faceCount = $rekognition->countIndexedFaces();
            $error = null;
        } catch (AwsException $e) {
            $faceCount = null;
            $error = $e->getAwsErrorMessage() ?? $e->getMessage();
        }

        return $this->render('admin/settings/_aws_status.html.twig', [
            'collectionId' => $rekognition->getCollectionId(),
            'faceCount' => $faceCount,
            'error' => $error,
        ]);
    }

    #[Route(
        '/admin/settings/face-recognition/aws/reset',
        name: 'app_admin_face_recognition_aws_reset',
        methods: ['POST'],
    )]
    public function resetCollection(Request $request, AmazonRekognitionService $rekognition): Response
    {
        if (!$this->isCsrfTokenValid('aws-collection-reset', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_admin_face_recognition_settings');
        }

        try {
            $rekognition->resetCollection();
            $this->addFlash('success', sprintf(
                'AWS-Collection „%s“ wurde geleert und neu angelegt.',
                $rekognition->getCollectionId(),
            ));
        } catch (AwsException $e) {
            $this->addFlash(
                'danger',
                'AWS-Fehler beim Zurücksetzen: ' . ($e->getAwsErrorMessage() ?? $e->getMessage()),
            );
        }

        return $this->redirectToRoute('app_admin_face_recognition_settings');
    }

    #[Route(
        '/admin/settings/face-recognition/consolidate',
        name: 'app_admin_face_recognition_consolidate',
        methods: ['POST'],
    )]
    public function consolidateAll(
        Request $request,
        VideoRepository $videoRepository,
        VideoFaceConsolidator $consolidator,
    ): Response {
        if (!$this->isCsrfTokenValid('face-consolidate-all', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');

            return $this->redirectToRoute('app_admin_face_recognition_settings');
        }

        $videos = 0;
        $before = 0;
        $after = 0;
        foreach ($videoRepository->findAll() as $video) {
            $result = $consolidator->consolidate($video);
            $videos++;
            $before += $result->personsBefore;
            $after += $result->personsAfter;
        }

        $this->addFlash('success', sprintf(
            '%d Videos neu gruppiert: %d Personen vorher, %d nachher.',
            $videos,
            $before,
            $after,
        ));

        return $this->redirectToRoute('app_admin_face_recognition_settings');
    }
}
