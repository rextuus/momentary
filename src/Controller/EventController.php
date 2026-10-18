<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Medium;
use App\Entity\User;
use App\Form\EventType;
use App\Repository\EventRepository;
use App\Security\Voter\MediumAccessVoter;
use App\Service\Event\EventMediaCollector;
use App\Service\Event\EventSuggester;
use App\Service\Event\EventSuggestion;
use App\Service\Gemini\GeminiService;
use App\Twig\Extension\MediaExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ereignisse/Alben: fassen Videos und Fotos zusammen (z. B. "Mallorca 2025").
 */
final class EventController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventMediaCollector $collector,
    ) {}

    #[Route('/event/', name: 'app_event_index', methods: ['GET'])]
    public function index(Request $request, EventRepository $eventRepository, EventSuggester $suggester): Response
    {
        $gap = self::gapHours($request);

        return $this->render('event/index.html.twig', [
            'events' => $eventRepository->findBy([], ['startDate' => 'DESC', 'id' => 'DESC']),
            'suggestions' => $suggester->suggest($this->currentUser(), $this->isGranted('ROLE_ADMIN'), $gap),
            'gap' => $gap,
            'gapOptions' => EventSuggester::GAP_OPTIONS,
        ]);
    }

    /**
     * Vorschlag als Ereignis übernehmen: Formular mit Zeitraum und (von Gemini) vorgeschlagenem Titel.
     */
    #[Route(
        '/event/suggestion/{key}',
        name: 'app_event_suggestion',
        requirements: ['key' => '[a-f0-9]{64}'],
        methods: ['GET', 'POST'],
    )]
    public function acceptSuggestion(
        string $key,
        Request $request,
        EventSuggester $suggester,
        GeminiService $gemini,
    ): Response {
        $gap = self::gapHours($request);
        $suggestion = $suggester->find($this->currentUser(), $this->isGranted('ROLE_ADMIN'), $key, $gap);
        if ($suggestion === null) {
            $this->addFlash(
                'warning',
                'Der Vorschlag ist nicht mehr aktuell, inzwischen haben sich die Medien geändert.',
            );

            return $this->redirectToRoute('app_event_index', ['gap' => $gap]);
        }

        $event = new Event()
            ->setOwner($this->currentUser())
            ->setStartDate(new \DateTimeImmutable($suggestion->getStart()->format('Y-m-d')))
            ->setEndDate(new \DateTimeImmutable($suggestion->getEnd()->format('Y-m-d')));
        $form = $this->createForm(EventType::class, $event);
        if (!$request->isMethod('POST')) {
            $form->get('title')->setData($this->suggestTitle($suggestion, $gemini));
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($suggestion->media as $medium) {
                $event->addMedium($medium);
            }
            $this->entityManager->persist($event);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf(
                'Ereignis „%s“ mit %d Medien angelegt.',
                $event->getTitle(),
                \count($suggestion->media),
            ));

            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        return $this->render(
            'event/suggestion.html.twig',
            [
                'form' => $form,
                'suggestion' => $suggestion,
                'gap' => $gap,
            ],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    #[Route(
        '/event/suggestion/{key}/dismiss',
        name: 'app_event_suggestion_dismiss',
        requirements: ['key' => '[a-f0-9]{64}'],
        methods: ['POST'],
    )]
    public function dismissSuggestion(string $key, Request $request, EventSuggester $suggester): Response
    {
        $user = $this->currentUser();
        if ($user !== null && $this->isCsrfTokenValid('dismiss-' . $key, $request->request->getString('_token'))) {
            $suggester->dismiss($user, $key);
        }

        return $this->redirectToRoute('app_event_index', ['gap' => self::gapHours($request)]);
    }

    private function suggestTitle(EventSuggestion $suggestion, GeminiService $gemini): string
    {
        $period = $suggestion->getStart()->format('d.m.Y') === $suggestion->getEnd()->format('d.m.Y')
            ? $suggestion->getStart()->format('d.m.Y')
            : $suggestion->getStart()->format('d.m.Y') . ' – ' . $suggestion->getEnd()->format('d.m.Y');

        try {
            $title = $gemini->suggestEventTitle(
                $suggestion->getTopTags(),
                array_map(static fn(Medium $m): string => (string) $m->getTitle(), $suggestion->media),
                $suggestion->getPlaces() !== []
                    ? $period . ', Orte: ' . implode(', ', $suggestion->getPlaces())
                    : $period,
            );
        } catch (\Throwable) {
            $title = '';
        }

        return $title !== '' ? mb_substr($title, 0, 120) : 'Ereignis ' . $period;
    }

    private static function gapHours(Request $request): int
    {
        $gap = $request->query->getInt('gap', EventSuggester::DEFAULT_GAP_HOURS);

        return \in_array($gap, EventSuggester::GAP_OPTIONS, true) ? $gap : EventSuggester::DEFAULT_GAP_HOURS;
    }

    #[Route('/event/new', name: 'app_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $event = new Event();
        $user = $this->currentUser();
        $event->setOwner($user);
        $form = $this->createForm(EventType::class, $event, ['collect_option' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($event);
            $this->entityManager->flush();
            $added = $form->get('collectMedia')->getData() === true
                ? $this->collector->collect($event, $user, $this->isGranted('ROLE_ADMIN'))
                : 0;
            $this->addFlash('success', sprintf(
                'Ereignis angelegt%s.',
                $added > 0 ? sprintf(', %d Medien hinzugefügt', $added) : '',
            ));

            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        return $this->render(
            'event/form.html.twig',
            ['form' => $form, 'event' => null],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    #[Route('/event/{id}', name: 'app_event_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Event $event): Response
    {
        $media = array_values(array_filter($event->getSortedMedia(), fn(Medium $medium): bool => $this->isGranted(
            MediumAccessVoter::VIEW,
            $medium,
        )));

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'media' => $media,
            'canEdit' => $this->canEdit($event),
        ]);
    }

    #[Route('/event/{id}/edit', name: 'app_event_edit', methods: ['GET', 'POST'])]
    public function edit(Event $event, Request $request): Response
    {
        $this->denyUnlessCanEdit($event);
        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Ereignis gespeichert.');

            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        return $this->render(
            'event/form.html.twig',
            ['form' => $form, 'event' => $event],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }

    #[Route('/event/{id}/collect', name: 'app_event_collect', methods: ['POST'])]
    public function collect(Event $event, Request $request): Response
    {
        $this->denyUnlessCanEdit($event);
        if ($this->isCsrfTokenValid('collect' . $event->getId(), $request->request->getString('_token'))) {
            $added = $this->collector->collect($event, $this->currentUser(), $this->isGranted('ROLE_ADMIN'));
            $this->addFlash('success', sprintf('%d Medien aus dem Zeitraum hinzugefügt.', $added));
        }

        return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
    }

    #[Route('/event/{id}/remove/{medium}', name: 'app_event_remove_medium', methods: ['POST'])]
    public function removeMedium(Event $event, Medium $medium, Request $request): Response
    {
        $this->denyUnlessCanEdit($event);
        if (
            $this->isCsrfTokenValid(
                'remove' . $event->getId() . '-' . $medium->getId(),
                $request->request->getString('_token'),
            )
        ) {
            $event->removeMedium($medium);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
    }

    #[Route('/event/{id}/delete', name: 'app_event_delete', methods: ['POST'])]
    public function delete(Event $event, Request $request): Response
    {
        $this->denyUnlessCanEdit($event);
        if ($this->isCsrfTokenValid('delete-event' . $event->getId(), $request->request->getString('_token'))) {
            $this->entityManager->remove($event);
            $this->entityManager->flush();
            $this->addFlash('success', 'Ereignis gelöscht. Die Videos und Fotos bleiben erhalten.');
        }

        return $this->redirectToRoute('app_event_index');
    }

    /**
     * Von der Video- oder Fotoseite aus: Medium einem Ereignis zuordnen.
     */
    #[Route('/medium/{id}/event', name: 'app_medium_add_event', methods: ['POST'])]
    #[IsGranted(MediumAccessVoter::EDIT, subject: 'medium')]
    public function addToEvent(
        Medium $medium,
        Request $request,
        EventRepository $eventRepository,
        MediaExtension $media,
    ): Response {
        $event = $eventRepository->find($request->request->getInt('event'));
        if (
            $event !== null
            && $this->isCsrfTokenValid('add-event' . $medium->getId(), $request->request->getString('_token'))
        ) {
            $this->denyUnlessCanEdit($event);
            $event->addMedium($medium);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Zu „%s“ hinzugefügt.', $event->getTitle()));
        }

        return $this->redirect($media->mediumPath($medium));
    }

    private function canEdit(Event $event): bool
    {
        $user = $this->currentUser();

        return $this->isGranted('ROLE_ADMIN') || $user !== null && $event->getOwner()?->getId() === $user->getId();
    }

    private function denyUnlessCanEdit(Event $event): void
    {
        if (!$this->canEdit($event)) {
            throw $this->createAccessDeniedException('Nur der Ersteller oder ein Admin darf dieses Ereignis ändern.');
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
