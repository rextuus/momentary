<?php

namespace App\Controller;

use App\Repository\PersonRepository;
use App\Repository\TagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchTestController extends AbstractController
{
    #[Route('/search-test', name: 'app_search_test')]
    public function index(TagRepository $tagRepository, PersonRepository $personRepository): Response
    {
        $tags = $tagRepository->findAll();
        $persons = $personRepository->findAll();

        return $this->render('search/test.html.twig', [
            'tags' => $tags,
            'persons' => $persons,
            'user' => $this->getUser(),
        ]);
    }
}
