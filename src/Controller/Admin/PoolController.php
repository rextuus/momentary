<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\PoolRule;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\EventRepository;
use App\Service\Pool\Pools;
use App\Service\User\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verwalten → Pools: wer welche Medien sieht (Regeln, einzelne Medien, Mitglieder).
 */
#[Route('/admin/pools')]
final class PoolController extends AbstractController
{
    public function __construct(
        private readonly Pools $pools,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_admin_pools', methods: ['GET'])]
    public function index(): Response
    {
        $pools = $this->pools->all();

        return $this->render('admin/pools/index.html.twig', [
            'pools' => $pools,
            'counts' => array_combine(
                array_map(static fn(UserGroup $pool): int => (int) $pool->getId(), $pools),
                array_map($this->pools->count(...), $pools),
            ) ?: [],
        ]);
    }

    #[Route('', name: 'app_admin_pools_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->checkToken($request);
        $user = $this->getUser();
        try {
            $pool = $this->pools->create(
                $request->request->getString('name'),
                $user instanceof User ? $user : throw $this->createAccessDeniedException(),
                $request->request->getString('description'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_admin_pools');
        }

        return $this->redirectToRoute('app_admin_pools_show', ['id' => $pool->getId()]);
    }

    #[Route('/{id}', name: 'app_admin_pools_show', methods: ['GET'])]
    public function show(UserGroup $pool, Users $users, EventRepository $events): Response
    {
        return $this->render('admin/pools/show.html.twig', [
            'pool' => $pool,
            'count' => $this->pools->count($pool),
            'media' => $this->pools->media($pool),
            'users' => $users->all(),
            'events' => $events->findBy([], ['startDate' => 'DESC', 'id' => 'DESC']),
            'tags' => $this->entityManager->getRepository(Tag::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_pools_update', methods: ['POST'])]
    public function update(UserGroup $pool, Request $request): Response
    {
        $this->checkToken($request);
        try {
            match ($request->request->getString('action')) {
                'details' => $this->details($pool, $request),
                'members' => $this->members($pool, $request),
                'add_rule' => $this->addRule($pool, $request),
                'remove_rule' => $this->removeRule($pool, (int) $request->request->getString('rule')),
                'unexclude' => $this->unexclude($pool, (int) $request->request->getString('medium')),
                'delete' => $this->pools->delete($pool),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return (
            $request->request->getString('action') === 'delete'
                ? $this->redirectToRoute('app_admin_pools')
                : $this->redirectToRoute('app_admin_pools_show', ['id' => $pool->getId()])
        );
    }

    private function details(UserGroup $pool, Request $request): void
    {
        $name = trim($request->request->getString('name'));
        if ($name === '') {
            throw new \InvalidArgumentException('Der Pool braucht einen Namen.');
        }
        $pool->setName($name)->setDescription($request->request->getString('description'));
        $this->pools->save();
        $this->addFlash('success', 'Gespeichert.');
    }

    private function members(UserGroup $pool, Request $request): void
    {
        $ids = array_map(intval(...), $request->request->all('members'));
        $this->pools->setMembers(
            $pool,
            $ids === [] ? [] : $this->entityManager->getRepository(User::class)->findBy(['id' => $ids]),
        );
        $this->addFlash('success', 'Mitglieder gespeichert.');
    }

    private function addRule(UserGroup $pool, Request $request): void
    {
        $rule = new PoolRule($pool);
        $eventId = (int) $request->request->getString('event');
        if ($eventId > 0) {
            $rule->setEvent($this->entityManager->find(Event::class, $eventId));
        }
        $tagName = trim($request->request->getString('tag'));
        if ($tagName !== '') {
            $tag = $this->entityManager
                ->getRepository(Tag::class)
                ->findOneBy(['name' => $tagName]) ?? throw new \InvalidArgumentException(sprintf(
                'Den Tag „%s“ gibt es nicht.',
                $tagName,
            ));
            $rule->setTag($tag);
        }
        $rule->setPeriod(
            self::date($request->request->getString('from')),
            self::date($request->request->getString('until')),
        );
        $this->pools->addRule($pool, $rule);
        $this->addFlash('success', 'Regel hinzugefügt: ' . $rule->describe());
    }

    private function removeRule(UserGroup $pool, int $ruleId): void
    {
        foreach ($pool->getRules() as $rule) {
            if ($rule->getId() === $ruleId) {
                $pool->removeRule($rule);
            }
        }
        $this->pools->save();
    }

    private function unexclude(UserGroup $pool, int $mediumId): void
    {
        foreach ($pool->getExcludedMedia() as $medium) {
            if ($medium->getId() === $mediumId) {
                $pool->unexclude($medium);
            }
        }
        $this->pools->save();
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date ?: throw new \InvalidArgumentException('Ungültiges Datum.');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('pools', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
