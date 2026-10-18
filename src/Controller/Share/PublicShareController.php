<?php

declare(strict_types=1);

namespace App\Controller\Share;

use App\Entity\ShareLink;
use App\Entity\Video;
use App\Service\Share\ShareLinks;
use App\Service\Share\TooManyShareAttemptsException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentliche Seiten eines Share-Links (ohne Anmeldung, siehe access_control ^/s/); Dateien liefert ShareFileController.
 */
#[Route('/s/{token<[A-Za-z0-9_-]+>}')]
final class PublicShareController extends AbstractController
{
    public function __construct(
        private readonly ShareLinks $shares,
    ) {}

    #[Route('', name: 'app_share_show', methods: ['GET', 'POST'])]
    public function show(#[\SensitiveParameter] string $token, Request $request): Response
    {
        $link = $this->shares->findActive($token);
        if ($link === null) {
            return $this->gone();
        }

        if (!$this->shares->isUnlocked($link, $request->getSession())) {
            return $this->unlock($link, $request);
        }

        $this->shares->recordView($link);
        $media = $this->shares->media($link);
        $event = $link->getEvent();
        if ($event !== null) {
            return $this->page('share/album.html.twig', ['link' => $link, 'event' => $event, 'media' => $media]);
        }
        $medium = $media[0] ?? null;
        if ($medium === null) {
            return $this->gone();
        }

        return $this->page('share/medium.html.twig', [
            'link' => $link,
            'medium' => $medium,
            'playable' => $medium instanceof Video && $this->shares->videoPath($medium) !== null,
        ]);
    }

    #[Route('/m/{id}', name: 'app_share_medium', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function medium(#[\SensitiveParameter] string $token, int $id, Request $request): Response
    {
        $link = $this->unlocked($token, $request);
        if ($link === null) {
            return $this->redirectToRoute('app_share_show', ['token' => $token]);
        }
        $media = $this->shares->media($link);
        $ids = array_map(static fn($m): ?int => $m->getId(), $media);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return $this->gone();
        }
        $medium = $media[$index];

        return $this->page('share/medium.html.twig', [
            'link' => $link,
            'medium' => $medium,
            'playable' => $medium instanceof Video && $this->shares->videoPath($medium) !== null,
            'previous' => $media[$index - 1] ?? null,
            'next' => $media[$index + 1] ?? null,
            'position' => $index + 1,
            'total' => \count($media),
        ]);
    }

    private function unlock(ShareLink $link, Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                if (!$this->isCsrfTokenValid('share-unlock', $request->request->getString('_token'))) {
                    $error = 'Bitte die Seite neu laden und noch einmal versuchen.';
                } elseif ($this->shares->unlock(
                    $link,
                    $request->request->getString('password'),
                    $request->getSession(),
                    (string) $request->getClientIp(),
                )) {
                    return $this->redirectToRoute('app_share_show', ['token' => $link->getToken()]);
                } else {
                    $error = 'Das Passwort stimmt nicht.';
                }
            } catch (TooManyShareAttemptsException $e) {
                $error = $e->getMessage();
            }
        }

        $response = $this->page('share/password.html.twig', ['error' => $error]);

        return $response->setStatusCode($error !== null ? Response::HTTP_FORBIDDEN : Response::HTTP_OK);
    }

    private function unlocked(#[\SensitiveParameter] string $token, Request $request): ?ShareLink
    {
        $link = $this->shares->findActive($token);

        return $link !== null && $this->shares->isUnlocked($link, $request->getSession()) ? $link : null;
    }

    private function gone(): Response
    {
        return $this->page('share/gone.html.twig', [])->setStatusCode(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters): Response
    {
        $response = $this->render($template, $parameters);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setPrivate();

        return $response;
    }
}
