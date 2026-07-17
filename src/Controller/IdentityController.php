<?php

namespace App\Controller;

use App\Repository\PersonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

class IdentityController extends AbstractController
{
    #[Route('/admin/mass-face-resolve/{personId}', name: 'app_identity_mass_face_resolve')]
    public function massFaceResolve(int $personId): Response
    {
        return $this->render('identity/mass_face_resolve.html.twig', [
            'personId' => $personId,
        ]);
    }

    #[Route('/admin/mass-face-resolve', name: 'app_identity_random_mass_face_resolve')]
    public function randomMassFaceResolve(PersonRepository $personRepository): Response
    {
        $people = $personRepository->findIdentifiedWithUnverifiedFaces();
        if (empty($people)) {
            return $this->redirectToRoute('app_dashboard');
        }
        $person = $people[array_rand($people)];

        return $this->redirectToRoute('app_identity_mass_face_resolve', ['personId' => $person->getId()]);
    }

    #[Route('/admin/quick-resolve', name: 'app_identity_quick_resolve')]
    public function quickResolve(): Response
    {
        return $this->render('identity/quick_resolve.html.twig');
    }

    #[Route('/admin/tinder-resolve', name: 'app_identity_tinder_resolve')]
    public function tinderResolve(): Response
    {
        return $this->render('identity/tinder_resolve.html.twig');
    }

    #[Route('/admin/reassign-faces/{faceId}', name: 'app_identity_reassign_faces', defaults: ['faceId' => null])]
    public function reassignFaces(?int $faceId = null): Response
    {
        return $this->render('identity/reassign_faces.html.twig', [
            'faceId' => $faceId,
        ]);
    }

    #[Route('/admin/resolve-identities/{currentPersonId}', name: 'app_identity_resolve', defaults: ['currentPersonId' => null])]
    public function resolve(
        ?int $currentPersonId = null,
        #[MapQueryParameter] bool $fromQuickResolve = false,
        #[MapQueryParameter] bool $fromTinder = false,
        #[MapQueryParameter] ?string $mergeIds = null
    ): Response
    {
        return $this->render('identity/resolve.html.twig', [
            'currentPersonId' => $currentPersonId,
            'fromQuickResolve' => $fromQuickResolve,
            'fromTinder' => $fromTinder,
            'mergeIds' => $mergeIds,
        ]);
    }
}