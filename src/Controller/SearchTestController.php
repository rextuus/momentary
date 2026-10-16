<?php

namespace App\Controller;

use App\Repository\TagRepository;
use App\Service\VideoSearchIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchTestController extends AbstractController
{
    #[Route('/search-test', name: 'app_search_test')]
    public function index(TagRepository $tagRepository, VideoSearchIndex $searchIndex): Response
    {
        // Nur Tags und Personen anbieten, die im Suchindex vorkommen (mit Trefferzahl)
        $facets = $searchIndex->facets();

        $categoryByTag = [];
        foreach ($tagRepository->findAll() as $tag) {
            $categoryByTag[(string) $tag->getName()] = $tag->getCategory()?->getName() ?? 'Sonstige';
        }

        $tagsByCategory = [];
        foreach ($facets['tags'] as $name => $count) {
            $name = (string) $name;
            $tagsByCategory[$categoryByTag[$name] ?? 'Sonstige'][] = ['name' => $name, 'count' => (int) $count];
        }
        ksort($tagsByCategory, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($tagsByCategory as $category => $items) {
            usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            $tagsByCategory[$category] = $items;
        }

        $persons = [];
        foreach ($facets['persons'] as $name => $count) {
            $persons[] = ['name' => (string) $name, 'count' => (int) $count];
        }
        usort($persons, fn ($a, $b) => [$b['count'], strtolower($a['name'])] <=> [$a['count'], strtolower($b['name'])]);

        return $this->render('search/test.html.twig', [
            'tagsByCategory' => $tagsByCategory,
            'persons' => $persons,
            'indexHasFilters' => $facets['tags'] !== [] || $facets['persons'] !== [],
            'user' => $this->getUser(),
        ]);
    }
}