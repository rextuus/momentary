<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Video;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Video\VideoFiles;
use App\Service\Video\VideoStreamUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Videos abspielen ohne Jellyfin-API-Key: die API gibt eine signierte, befristete URL aus, die Datei kommt direkt aus
 * dem Storage (siehe documentation/clients/API.md, Abschnitt 6).
 */
final class VideoStreamController extends AbstractController
{
    public function __construct(
        private readonly VideoStreamUrls $streamUrls,
        private readonly VideoFiles $videoFiles,
    ) {}

    #[Route(
        '/api/videos/{id}/stream-url',
        name: 'api_video_stream_url',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function streamUrl(Video $video, Request $request): JsonResponse
    {
        if (!$this->isGranted(MediumAccessVoter::VIEW, $video)) {
            return new JsonResponse(['error' => 'Kein Zugriff auf dieses Video.'], Response::HTTP_FORBIDDEN);
        }
        $wanted = $request->query->getString('quality') === VideoFiles::QUALITY_ORIGINAL
            ? VideoFiles::QUALITY_ORIGINAL
            : VideoFiles::QUALITY_WEB;
        $file = $this->videoFiles->streamFile($video, $wanted);
        if ($file === null) {
            return new JsonResponse(['error' => 'Das Video ist (noch) nicht abspielbar.'], Response::HTTP_CONFLICT);
        }

        $stream = $this->streamUrls->create($video, $request, quality: $file['quality']);

        return new JsonResponse([
            'url' => $stream['url'],
            'expiresAt' => $stream['expiresAt']->format(\DATE_ATOM),
            'contentType' => 'video/mp4',
            'quality' => $file['quality'],
            'hasWebVersion' => $this->videoFiles->existing($video->getWebFile()) !== null,
            'duration' => $video->getDuration(),
        ]);
    }

    /**
     * Ohne Anmeldung (access_control ^/stream/), geschützt durch Signatur und Ablaufzeit. Range-Anfragen (Spulen)
     * beantwortet BinaryFileResponse selbst.
     */
    #[Route('/stream/video/{id}', name: 'app_video_stream', requirements: ['id' => '\d+'], methods: ['GET', 'HEAD'])]
    public function serve(Video $video, Request $request): Response
    {
        if (!$this->streamUrls->isValid($request)) {
            // Kein AccessDeniedException: die Firewall würde Anonyme sonst zum Login umleiten (Fernseher, Player)
            return new Response('Ungültiger oder abgelaufener Stream-Link.', Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }
        $file = $this->videoFiles->streamFile($video, $request->query->getString('quality', VideoFiles::QUALITY_WEB));
        if ($file === null) {
            throw $this->createNotFoundException();
        }

        $response = $this->file($file['path'], 'video.mp4', ResponseHeaderBag::DISPOSITION_INLINE);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // Chromecast und Browser anderer Herkunft (Hub) dürfen die Datei laden
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->setPrivate();

        return $response;
    }
}
