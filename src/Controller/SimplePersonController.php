<?php

namespace App\Controller;

use App\Repository\PersonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SimplePersonController extends AbstractController
{
    #[Route('/admin/simple-person-list', name: 'app_simple_person_list')]
    public function index(PersonRepository $personRepository): Response
    {
        return $this->render('simple_person/index.html.twig', [
            'persons' => $personRepository->findAll(),
        ]);
    }
}
