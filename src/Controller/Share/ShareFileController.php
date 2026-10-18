<?php

declare(strict_types=1);

namespace App\Controller\Share;

use App\Entity\Medium;
use App\Entity\Photo;
use App\Entity\ShareLink;
use App\Entity\Video;
use App\Service\Share\ShareLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Dateien eines Share-Links: Video-Stream und Download der Originale. Nur für gültige, entsperrte Links und nur
 * Medien, die der Link zeigt.
 */
#[Route('/s/{token<[A-Za-z0-9_-]+>}')]
final class ShareFileController extends AbstractController
{
    public function __construct(
        private readonly ShareLinks $shares,
    ) {}

    /**
     * Video abspielen (mit Range-Anfragen zum Spulen); ersetzt den Jellyfin-Stream, dessen API-Key nicht nach außen darf.
     */
    #[Route('/video/{id}', name: 'app_share_video', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function video(#[\SensitiveParameter] string $token, int $id, Request $request): Response
    {
        [, $medium] = $this->shared($token, $id, $request);
        $path = $medium instanceof Video ? $this->shares->videoPath($medium) : null;
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return $this->send($path, 'video.mp4', ResponseHeaderBag::DISPOSITION_INLINE);
    }

    #[Route('/download/{id}', name: 'app_share_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(#[\SensitiveParameter] string $token, int $id, Request $request): Response
    {
        [$link, $medium] = $this->shared($token, $id, $request);
        $path = $medium !== null && $link?->isAllowDownload() ? $this->shares->originalPath($medium) : null;
        if ($medium === null || $path === null) {
            throw $this->createNotFoundException();
        }
        $name = new AsciiSlugger('de')
            ->slug((string) $medium->getTitle())
            ->toString() ?: ($medium instanceof Photo ? 'foto' : 'video');

        return $this->send($path, $name . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    /**
     * @return array{?ShareLink, ?Medium} Link und Medium, wenn der Link gültig, entsperrt und das Medium darin ist
     */
    private function shared(#[\SensitiveParameter] string $token, int $id, Request $request): array
    {
        $link = $this->unlocked($token, $request);

        return [$link, $link !== null ? $this->shares->find($link, $id) : null];
    }

    private function unlocked(#[\SensitiveParameter] string $token, Request $request): ?ShareLink
    {
        $link = $this->shares->findActive($token);

        return $link !== null && $this->shares->isUnlocked($link, $request->getSession()) ? $link : null;
    }

    /**
     * BinaryFileResponse beantwortet Range-Anfragen selbst (Spulen im Video).
     */
    private function send(
        string $path,
        string $fileName,
        string $disposition = ResponseHeaderBag::DISPOSITION_ATTACHMENT,
    ): BinaryFileResponse {
        $response = $this->file($path, $fileName, $disposition);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();

        return $response;
    }
}
