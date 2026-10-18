<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Search\MediaSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gemeinsame Suche über Videos und Fotos für angemeldete Benutzer.
 */
final class MediaSearchController extends AbstractController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function index(Request $request, MediaSearch $search): Response
    {
        $query = trim($request->query->getString('q'));
        $persons = self::strings($request->query->all('persons'));
        $tags = self::strings($request->query->all('tags'));
        $user = $this->getUser();

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'persons' => $persons,
            'tags' => $tags,
            'result' => $search->search(
                $query,
                $persons,
                $tags,
                $user instanceof User ? $user : null,
                $this->isGranted('ROLE_ADMIN'),
            ),
        ]);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn($v): bool => \is_string($v) && $v !== '')));
    }
}
