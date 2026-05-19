<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IdentityController extends AbstractController
{
    #[Route('/admin/resolve-identities', name: 'app_identity_resolve')]
    public function resolve(): Response
    {
        return $this->render('identity/resolve.html.twig');
    }
}