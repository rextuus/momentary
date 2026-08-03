<?php

namespace App\Controller;

use App\Entity\UserGroup;
use App\Repository\UserGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/groups')]
#[IsGranted('ROLE_ADMIN')]
class GroupAdminController extends AbstractController
{
    public function __construct(
        private UserGroupRepository $repository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_admin_groups', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/groups/index.html.twig', [
            'groups' => $this->repository->findAll(),
        ]);
    }

    #[Route('/create', name: 'app_admin_groups_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $group = new UserGroup();
        $group->setOwner($this->getUser()); // Admin as owner for now
        $form = $this->createForm(\App\Form\UserGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($group);
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_groups');
        }

        return $this->render('admin/groups/form.html.twig', [
            'form' => $form,
            'title' => 'Gruppe erstellen'
        ]);
    }

    #[Route('/{id}/members', name: 'app_admin_groups_members', methods: ['GET', 'POST'])]
    public function members(int $id, Request $request): Response
    {
        $group = $this->repository->find($id);
        if (!$group) {
            throw $this->createNotFoundException('Gruppe nicht gefunden');
        }

        $member = new \App\Entity\UserGroupMember();
        $member->setUserGroup($group);
        
        $form = $this->createForm(\App\Form\UserGroupMemberType::class, $member);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_groups_members', ['id' => $id]);
        }
        
        return $this->render('admin/groups/members.html.twig', [
            'group' => $group,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/members/{memberId}/remove', name: 'app_admin_groups_remove_member', methods: ['POST'])]
    public function removeMember(int $id, int $memberId, Request $request): Response
    {
        $member = $this->entityManager->getRepository(\App\Entity\UserGroupMember::class)->find($memberId);
        if ($member && $member->getUserGroup()->getId() === $id) {
            $this->entityManager->remove($member);
            $this->entityManager->flush();
        }
        
        return $this->redirectToRoute('app_admin_groups_members', ['id' => $id]);
    }
}
