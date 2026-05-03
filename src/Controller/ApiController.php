<?php

namespace App\Controller;

use App\Entity\Person;
use App\Entity\VideoFace;
use App\Repository\PersonRepository;
use App\Repository\VideoFaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiController extends AbstractController
{
    #[Route('/known-faces', name: 'api_known_faces', methods: ['GET'])]
    public function knownFaces(VideoFaceRepository $videoFaceRepository, PersonRepository $personRepository): JsonResponse
    {
        /** @var array<VideoFace> $videoFaces */
        $videoFaces = $videoFaceRepository->createQueryBuilder('vf')
            ->join('vf.person', 'p')
            ->addSelect('p')
            ->where('vf.embedding IS NOT NULL')
            ->getQuery()
            ->getResult();

        $known = [];

        /** @var array<Person> $persons */
        $persons = $personRepository->findBy(['wasted' => false]);

        foreach ($persons as $person) {
            $personId = $person->getId();
            if (!isset($known[$personId])) {
                $embeddings = array_map(fn (VideoFace $face) => $face->getEmbedding(), $person->getDetectionFaces()->toArray());

                $known[$personId] = [
                    'label' => $person->getName(),
                    'embeddings' => $embeddings
                ];
            }
        }

        return $this->json(array_values($known));
    }

    #[Route('/current-unknown', name: 'api_current_unknown', methods: ['GET'])]
    public function currentUnknown(VideoFaceRepository $videoFaceRepository, PersonRepository $personRepository): JsonResponse
    {
        $qb = $personRepository->createQueryBuilder('p');
        $qb->select('p');
        $qb->where($qb->expr()->like('p.name', ':name'));
        $qb->setParameter('name', 'unknown%');
        $qb->setMaxResults(1);
        $qb->orderBy('p.id', 'DESC');

        $person = $qb->getQuery()->getResult();
        if (count($person) === 0) {
            return $this->json(['current' => 0]);
        }

        $person = $person[0];
        $index = (int) explode('_', $person->getName())[1];

        return $this->json(['current' => $index]);
    }
}
