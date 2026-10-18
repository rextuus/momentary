<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Media\MediaImporter;
use App\Twig\Extension\MediaExtension;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ein Upload für Videos und Fotos: der Browser schickt die Dateien einzeln, der Typ ergibt sich aus der Endung.
 */
final class UploadController extends AbstractController
{
    public function __construct(
        private readonly MediaImporter $importer,
        private readonly MediaExtension $media,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/upload', name: 'app_upload', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('upload/index.html.twig', [
            'videoExtensions' => MediaImporter::VIDEO_EXTENSIONS,
            'extensions' => MediaImporter::extensions(),
        ]);
    }

    /**
     * Vorab-Prüfung: Gibt es die Datei (SHA-256) schon? Spart den kompletten Upload.
     */
    #[Route('/upload/check', name: 'app_upload_check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('media_upload', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->error('Ungültiges CSRF-Token. Bitte Seite neu laden.', 403);
        }

        $hash = strtolower(trim($request->request->getString('hash')));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return $this->error('Ungültiger Hash.', 400);
        }

        $existing = $this->importer->findByHash($hash);
        if ($existing === null) {
            return new JsonResponse(['exists' => false]);
        }

        // Auch übersprungene Dateien gehören zur Gruppe des Uploads
        $this->importer->tag($existing, $request->request->getString('batch_name'));

        return new JsonResponse([
            'exists' => true,
            'title' => $existing->getTitle(),
            'url' => $this->media->mediumPath($existing),
        ]);
    }

    /**
     * Nimmt genau EINE Datei entgegen. Antwortet immer mit JSON: status = ok | duplicate | error
     */
    #[Route('/upload/file', name: 'app_upload_file', methods: ['POST'])]
    public function uploadFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('media_upload', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->error('Ungültiges CSRF-Token. Bitte Seite neu laden.', 403);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('Keine Datei empfangen (evtl. Server-Upload-Limit überschritten).', 400);
        }
        if (!$file->isValid()) {
            return $this->error('Upload fehlgeschlagen: ' . $file->getErrorMessage(), 400);
        }
        if (MediaImporter::typeOf($file->getClientOriginalName()) === null) {
            return $this->error(
                sprintf('Dateityp „.%s“ wird nicht unterstützt.', $file->getClientOriginalExtension()),
                415,
            );
        }

        $user = $this->getUser();
        try {
            $result = $this->importer->import(
                $file->getPathname(),
                $file->getClientOriginalName(),
                $user instanceof User ? $user : null,
                $request->request->getString('batch_name'),
                trim($request->request->getString('custom_filename')) ?: null,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Upload: ' . $e->getMessage());

            return $this->error('Fehler beim Übernehmen: ' . $e->getMessage(), 500);
        }

        return new JsonResponse([
            'status' => $result->duplicate ? 'duplicate' : 'ok',
            'type' => $result->medium->getType(),
            'message' => $result->duplicate
                ? 'Diese Datei ist schon vorhanden.'
                : 'Hochgeladen und zur Analyse eingeplant.',
            'title' => $result->medium->getTitle(),
            'tag' => $result->tag,
            'url' => $this->media->mediumPath($result->medium),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}
