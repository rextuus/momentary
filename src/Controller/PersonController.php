<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Person;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/person')]
final class PersonController extends AbstractController
{
    #[Route('/{id}/detail', name: 'person_detail')]
    public function details(Person $person): Response
    {
        // Get VideoFaces sorted by their associated timestamps
        $videoFaces = $person->getVideoFaces()->toArray();
        usort($videoFaces, static fn($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $this->render('person/detail.html.twig', [
            'person' => $person,
            'videoFaces' => $videoFaces,
        ]);
    }

    #[Route('/', name: 'person_index')]
    public function index(): Response
    {
        // Filter, Suche und Sortierung stehen als Query-Parameter in der URL und werden von der Live Component gelesen
        return $this->render('person/index.html.twig');
    }

    #[Route('/cleanup', name: 'person_cleanup', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cleanup(
        PersonRepository $personRepository,
        EntityManagerInterface $entityManager,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('person_cleanup', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('app_dashboard');
        }

        $allPersons = $personRepository->findAll();
        $removedCount = 0;

        foreach ($allPersons as $person) {
            if ($person->getVideoFaces()->isEmpty()) {
                $entityManager->remove($person);
                $removedCount++;
            }
        }

        $entityManager->flush();

        if ($removedCount > 0) {
            $this->addFlash('success', sprintf('%d Person(en) ohne Sichtungen wurden gelöscht.', $removedCount));
        } else {
            $this->addFlash('info', 'Keine verwaisten Personen gefunden.');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
