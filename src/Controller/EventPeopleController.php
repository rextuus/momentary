<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Service\Person\PersonMerger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * „Wer ist dabei?“ auf der Ereignisseite: unbenannte Personen benennen oder als fremd markieren.
 * Personen gelten für alle Medien, deshalb nur für Admins (wie die übrigen Identifizieren-Werkzeuge).
 */
final class EventPeopleController extends AbstractController
{
    #[Route('/event/{id}/person/{person}', name: 'app_event_name_person', methods: ['POST'])]
    public function name(
        Event $event,
        Person $person,
        Request $request,
        PersonMerger $merger,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('name-person-' . $person->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        if ($request->request->get('decision') === 'unknown') {
            $person->setStatus(PersonStatus::UNKNOWN);
            $person->setIdentified(true);
            $entityManager->flush();
            $this->addFlash('info', sprintf('%s ist als unbekannt markiert.', $person->getDisplayName()));
        } else {
            // Hinweis-Knöpfe schicken den Namen als „hint“, das Textfeld als „name“
            $name = trim((string) ($request->request->get('hint') ?: $request->request->get('name')));
            if ($name === '') {
                $this->addFlash('warning', 'Bitte einen Namen eingeben.');
            } else {
                $previous = $person->getDisplayName();
                $named = $merger->name($person, $name);
                $this->addFlash(
                    'success',
                    $named === $person
                        ? sprintf('%s heißt jetzt %s.', $previous, $name)
                        : sprintf(
                            '%s wurde mit der vorhandenen Person %s zusammengeführt.',
                            $previous,
                            $named->getDisplayName(),
                        ),
                );
            }
        }

        return $this->redirect($this->generateUrl('app_event_show', ['id' => $event->getId()]) . '#people');
    }
}
