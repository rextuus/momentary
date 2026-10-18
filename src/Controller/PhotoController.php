<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Photo;
use App\Entity\User;
use App\Enum\PhotoStatus;
use App\Message\AnalyzePhotoMessage;
use App\Repository\PhotoRepository;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Photo\PhotoBursts;
use App\Service\Photo\PhotoDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/photo')]
final class PhotoController extends AbstractController
{
    private const int PER_PAGE = 60;

    #[Route('/', name: 'app_photo_index', methods: ['GET'])]
    public function index(Request $request, PhotoRepository $photoRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $search = trim($request->query->getString('q'));
        $user = $this->getUser();
        $photos = $photoRepository->findVisiblePage(
            $user instanceof User ? $user : null,
            $this->isGranted('ROLE_ADMIN'),
            $page,
            self::PER_PAGE,
            $search,
        );

        // Nach Aufnahmetag gruppieren
        $days = [];
        foreach ($photos as $photo) {
            $day = $photo->getDate()?->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d') ?? 'unbekannt';
            $days[$day][] = $photo;
        }

        return $this->render('photo/index.html.twig', [
            'days' => $days,
            'total' => \count($photos),
            'page' => $page,
            'pages' => max(1, (int) ceil(\count($photos) / self::PER_PAGE)),
            'search' => $search,
        ]);
    }

    /**
     * Alte Adresse: Videos und Fotos haben einen gemeinsamen Upload.
     */
    #[Route('/upload', name: 'app_photo_upload', methods: ['GET'])]
    public function upload(): Response
    {
        return $this->redirectToRoute('app_upload');
    }

    #[Route('/{id}', name: 'app_photo_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(MediumAccessVoter::VIEW, subject: 'photo')]
    public function show(Photo $photo): Response
    {
        return $this->render('photo/show.html.twig', ['photo' => $photo]);
    }

    /**
     * Serienfotos: dieses als bestes verwenden, aus der Serie lösen oder wieder zulassen.
     */
    #[Route('/{id}/burst', name: 'app_photo_burst', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'photo')]
    public function burst(Photo $photo, Request $request, PhotoBursts $bursts): Response
    {
        if ($this->isCsrfTokenValid('burst' . $photo->getId(), $request->request->getString('_token'))) {
            match ($request->request->getString('action')) {
                'pin' => $bursts->pin($photo),
                'exclude' => $bursts->exclude($photo),
                'include' => $bursts->exclude($photo, false),
                default => null,
            };
        }

        return $this->redirect($this->generateUrl('app_photo_show', ['id' => $photo->getId()]) . '#burst');
    }

    #[Route('/{id}/reanalyze', name: 'app_photo_reanalyze', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'photo')]
    public function reanalyze(
        Photo $photo,
        Request $request,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ): Response {
        if ($this->isCsrfTokenValid('reanalyze' . $photo->getId(), $request->request->getString('_token'))) {
            $photo->setStatus(PhotoStatus::QUEUED);
            $entityManager->flush();
            $bus->dispatch(new AnalyzePhotoMessage((int) $photo->getId()));
            $this->addFlash('success', 'Das Foto wird neu analysiert.');
        }

        return $this->redirectToRoute('app_photo_show', ['id' => $photo->getId()]);
    }

    #[Route('/{id}/visibility', name: 'app_photo_visibility', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'photo')]
    public function visibility(Photo $photo, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('visibility' . $photo->getId(), $request->request->getString('_token'))) {
            $photo->setIsPublic($request->request->getBoolean('public'));
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_photo_show', ['id' => $photo->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_photo_delete', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'photo')]
    public function delete(Photo $photo, Request $request, PhotoDeleter $deleter): Response
    {
        if ($this->isCsrfTokenValid('delete' . $photo->getId(), $request->request->getString('_token'))) {
            $deleter->delete($photo);
            $this->addFlash('success', 'Foto gelöscht.');
        }

        return $this->redirectToRoute('app_photo_index');
    }
}
