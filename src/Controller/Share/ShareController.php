<?php

declare(strict_types=1);

namespace App\Controller\Share;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Share\ShareLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Share-Links anlegen, widerrufen und auflisten (angemeldet).
 */
#[Route('/share')]
final class ShareController extends AbstractController
{
    public function __construct(
        private readonly ShareLinks $shares,
    ) {}

    #[Route('', name: 'app_share_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('share/index.html.twig', ['links' => $this->shares->list($this->user())]);
    }

    #[Route('/new', name: 'app_share_new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('share-new', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $id = (int) $request->request->getString('target_id');
        $target = $request->request->getString('target') === 'event'
            ? $entityManager->find(Event::class, $id)
            : $entityManager->find(Medium::class, $id);
        if ($target === null) {
            throw $this->createNotFoundException();
        }
        $this->denyUnlessCanShare($target);

        $link = $this->shares->create(
            $target,
            $this->user(),
            $request->request->getString('expiry'),
            $request->request->getString('password') ?: null,
            $request->request->getBoolean('allow_download'),
            $request->request->getString('label') ?: null,
        );
        $this->addFlash(
            'success',
            'Link erstellt: '
                . $this->generateUrl(
                    'app_share_show',
                    ['token' => $link->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
        );

        return $this->redirect($this->back($request, $target) . '#share');
    }

    #[Route('/{id}/revoke', name: 'app_share_revoke', methods: ['POST'])]
    public function revoke(ShareLink $link, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('share-revoke', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
        if ($link->getCreatedBy()->getId() !== $this->user()->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Nur wer den Link erstellt hat, kann ihn zurückziehen.');
        }

        $this->shares->revoke($link);
        $this->addFlash('info', sprintf('Der Link zu „%s“ funktioniert nicht mehr.', $link->getTitle()));

        $back = $request->request->getString('back');

        return $this->redirect(
            str_starts_with($back, '/') && !str_starts_with($back, '//')
                ? $back
                : $this->generateUrl('app_share_index'),
        );
    }

    /**
     * Teilen darf, wer das Ereignis bzw. Medium bearbeiten darf (Ersteller bzw. Eigentümer, Admin).
     */
    private function denyUnlessCanShare(Event|Medium $target): void
    {
        $allowed = $target instanceof Medium
            ? $this->isGranted(MediumAccessVoter::EDIT, $target)
            : $this->isGranted('ROLE_ADMIN') || $target->getOwner()?->getId() === $this->user()->getId();
        if (!$allowed) {
            throw $this->createAccessDeniedException('Teilen darf nur, wer das Ereignis bzw. Medium bearbeiten darf.');
        }
    }

    private function back(Request $request, Event|Medium $target): string
    {
        $back = $request->request->getString('back');
        if (str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $back;
        }

        return (
            $target instanceof Event
                ? $this->generateUrl('app_event_show', ['id' => $target->getId()])
                : $this->generateUrl('app_share_index')
        );
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
