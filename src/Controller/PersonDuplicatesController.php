<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Service\Person\DuplicatePersonFinder;
use App\Service\Person\PersonMerger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vermutlich doppelte Personen: zusammenführen oder als verschieden markieren.
 */
final class PersonDuplicatesController extends AbstractController
{
    #[Route('/admin/person-duplicates', name: 'app_identity_duplicates', methods: ['GET'])]
    public function index(DuplicatePersonFinder $finder): Response
    {
        return $this->render('identity/duplicates.html.twig', [
            'suggestions' => $finder->find(),
        ]);
    }

    #[Route('/admin/person-duplicates/{keep}/{merge}', name: 'app_identity_duplicates_decide', methods: ['POST'])]
    public function decide(
        Person $keep,
        Person $merge,
        Request $request,
        PersonMerger $merger,
        DuplicatePersonFinder $finder,
    ): Response {
        if (!$this->isCsrfTokenValid(
            'person-pair-' . $keep->getId() . '-' . $merge->getId(),
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
        if ($keep->getStatus() === PersonStatus::MERGED || $merge->getStatus() === PersonStatus::MERGED) {
            $this->addFlash('warning', 'Eine der beiden Personen wurde inzwischen schon zusammengeführt.');

            return $this->redirectToRoute('app_identity_duplicates');
        }

        if ($request->request->get('decision') === 'same') {
            $merger->merge($merge, $keep);
            $this->addFlash('success', sprintf(
                '%s wurde mit %s zusammengeführt.',
                $merge->getDisplayName(),
                $keep->getDisplayName(),
            ));
        } else {
            $finder->dismiss($keep, $merge);
            $this->addFlash('info', sprintf(
                '%s und %s werden nicht mehr vorgeschlagen.',
                $keep->getDisplayName(),
                $merge->getDisplayName(),
            ));
        }

        return $this->redirectToRoute('app_identity_duplicates');
    }
}
