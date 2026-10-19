<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Service\Pool\Pools;
use App\Service\User\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verwalten → Benutzer: Konten für Familie und Bearbeiter, Rollen, Passwörter, Pools.
 */
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly Users $users,
        private readonly Pools $pools,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    #[Route('', name: 'app_admin_users_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->checkToken($request);
        $email = $request->request->getString('email');
        try {
            $password = $this->users->create(
                $email,
                $request->request->getString('role', 'family'),
                $request->request->getString('password') ?: null,
            );
            $user = $this->entityManager
                ->getRepository(User::class)
                ->findOneBy(['email' => mb_strtolower(trim($email))]);
            if ($user !== null) {
                $this->users->setPools($user, $this->selectedPools($request));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_admin_users');
        }

        // Passwort nicht über die Session transportieren, sondern einmal direkt anzeigen
        return $this->page($request, ['email' => mb_strtolower(trim($email)), 'password' => $password]);
    }

    #[Route('/{id}', name: 'app_admin_users_update', methods: ['POST'])]
    public function update(User $user, Request $request): Response
    {
        $this->checkToken($request);
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            switch ($request->request->getString('action')) {
                case 'save':
                    $this->users->setRole($user, $request->request->getString('role'), $actor);
                    $this->users->setPools($user, $this->selectedPools($request));
                    $this->pools->save();
                    $this->addFlash('success', sprintf('%s gespeichert.', $user->getEmail()));
                    break;
                case 'password':
                    return $this->page($request, [
                        'email' => (string) $user->getEmail(),
                        'password' => $this->users->resetPassword($user),
                    ]);
                case 'delete':
                    $this->users->delete($user, $actor);
                    $this->addFlash('success', sprintf('%s wurde gelöscht.', $user->getEmail()));
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * @param array{email: string, password: string}|null $created
     */
    private function page(Request $request, ?array $created = null): Response
    {
        $response = $this->render('admin/users/index.html.twig', [
            'users' => $this->users->all(),
            'pools' => $this->pools->all(),
            'roles' => Users::ROLES,
            'created' => $created,
            'loginUrl' => $request->getSchemeAndHttpHost() . $this->generateUrl('app_login'),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return list<UserGroup>
     */
    private function selectedPools(Request $request): array
    {
        $ids = array_map(intval(...), $request->request->all('pools'));

        return $ids === [] ? [] : $this->entityManager->getRepository(UserGroup::class)->findBy(['id' => $ids]);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('users', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
