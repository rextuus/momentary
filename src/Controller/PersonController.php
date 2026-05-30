<?php
namespace App\Controller;

use App\Entity\Person;
use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/person')]
class PersonController extends AbstractController
{
    #[Route('/{id}/detail', name: 'person_detail')]
    public function details(Person $person): Response
    {
        // Get VideoFaces sorted by their associated timestamps
        $videoFaces = $person->getVideoFaces()->toArray();
        usort($videoFaces, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $this->render('person/detail.html.twig', [
            'person' => $person,
            'videoFaces' => $videoFaces,
        ]);
    }

    #[Route('/', name: 'person_index')]
    public function index(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $statuses = $request->query->all('statuses');
        if (empty($statuses)) {
            $statuses = ['identified'];
        }
        $search = $request->query->get('search', '');

        return $this->render('person/index.html.twig', [
            'statuses' => $statuses,
            'search' => $search,
        ]);
    }
}