<?php

namespace App\Controller;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Tag\UploadBatchTagService;
use App\Service\Video\VideoCreationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UploadController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v', 'mts', 'm2ts', 'mpg', 'mpeg', 'wmv', 'flv', '3gp'];

    public function __construct(
        private readonly VideoCreationService $videoCreationService,
        private readonly VideoRepository $videoRepository,
        private readonly UploadBatchTagService $batchTagService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/upload', name: 'app_upload', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('upload/index.html.twig');
    }

    /**
     * Vorab-Prüfung: Gibt es ein Video mit diesem SHA-256 schon? (spart den kompletten Upload)
     */
    #[Route('/upload/check', name: 'app_upload_check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('video_upload', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->error('Ungültiges CSRF-Token. Bitte Seite neu laden.', 403);
        }

        $hash = strtolower(trim((string) $request->request->get('hash', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return $this->error('Ungültiger Hash.', 400);
        }

        $existingVideo = $this->videoRepository->findOneBy(['fileHash' => $hash]);
        if (!$existingVideo) {
            return new JsonResponse(['exists' => false]);
        }

        $this->applyBatchTag($existingVideo, trim((string) $request->request->get('batch_name', '')));

        return new JsonResponse([
            'exists' => true,
            'title' => $existingVideo->getTitle(),
            'url' => $this->generateUrl('app_video_show', ['id' => $existingVideo->getId()]),
        ]);
    }

    /**
     * Nimmt genau EINE Videodatei entgegen (der Browser ruft das pro Datei auf).
     * Antwortet immer mit JSON: status = ok | duplicate | error
     */
    #[Route('/upload/file', name: 'app_upload_file', methods: ['POST'])]
    public function uploadFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('video_upload', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->error('Ungültiges CSRF-Token. Bitte Seite neu laden.', 403);
        }

        $videoFile = $request->files->get('video_file');
        if (!$videoFile instanceof UploadedFile) {
            return $this->error('Keine Datei empfangen (evtl. Server-Upload-Limit überschritten).', 400);
        }

        if (!$videoFile->isValid()) {
            return $this->error('Upload fehlgeschlagen: ' . $videoFile->getErrorMessage(), 400);
        }

        $extension = strtolower($videoFile->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->error('Dateityp ".' . $extension . '" wird nicht unterstützt.', 415);
        }

        $batchName = trim((string) $request->request->get('batch_name', ''));

        // Duplikatsprüfung per Hash (streamt die Datei, statt sie komplett in den RAM zu laden)
        $hash = hash_file('sha256', $videoFile->getRealPath());
        $existingVideo = $this->videoRepository->findOneBy(['fileHash' => $hash]);
        if ($existingVideo) {
            $this->applyBatchTag($existingVideo, $batchName);

            return new JsonResponse([
                'status' => 'duplicate',
                'message' => 'Dieses Video wurde bereits hochgeladen.',
                'title' => $existingVideo->getTitle(),
                'url' => $this->generateUrl('app_video_show', ['id' => $existingVideo->getId()]),
            ]);
        }

        $customName = trim((string) $request->request->get('custom_filename', ''));
        $originalName = pathinfo($videoFile->getClientOriginalName(), PATHINFO_FILENAME);

        $title = $customName !== '' ? $customName : $originalName;
        if ($this->videoRepository->findOneBy(['title' => $title])) {
            if ($batchName !== '') {
                $title = $batchName . ' – ' . $title;
            }
            if ($this->videoRepository->findOneBy(['title' => $title])) {
                $title .= ' (' . substr($hash, 0, 8) . ')';
            }
        }

        try {
            $video = new Video();
            $video->setTitle($title);
            $video->setFileHash($hash);

            $this->videoCreationService->createVideo($video, $videoFile, null, null, true);
        } catch (\Throwable $e) {
            $this->logger->error('UploadController: Fehler beim Erstellen des Videos: ' . $e->getMessage());

            return $this->error('Fehler bei der Videokreation: ' . $e->getMessage(), 500);
        }

        $tagName = $this->applyBatchTag($video, $batchName);

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Hochgeladen und in die Verarbeitung eingereiht.',
            'title' => $video->getTitle(),
            'tag' => $tagName,
            'url' => $this->generateUrl('app_video_show', ['id' => $video->getId()]),
        ]);
    }

    /**
     * Setzt das Gruppen-Tag. Fehler sind nicht kritisch: das Video existiert bereits.
     */
    private function applyBatchTag(Video $video, string $batchName): ?string
    {
        if ($batchName === '') {
            return null;
        }

        try {
            return $this->batchTagService->tagVideo($video, $batchName)->getName();
        } catch (\Throwable $e) {
            $this->logger->warning('UploadController: Gruppen-Tag konnte nicht gesetzt werden: ' . $e->getMessage());

            return null;
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}