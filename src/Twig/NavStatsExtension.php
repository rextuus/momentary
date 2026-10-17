<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\PersonStatus;
use App\Repository\PersonRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Stellt dem Header die Zähler für offene Arbeit bereit: {{ nav_counts().new }}
 * (Ergebnis wird pro Request gecacht, Fehler dürfen nie das Layout zerstören.)
 */
final class NavStatsExtension extends AbstractExtension
{
    /** @var array{new: int, to_reassign: int}|null */
    private ?array $cache = null;

    public function __construct(
        private readonly PersonRepository $personRepository,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_counts', $this->navCounts(...)),
        ];
    }

    /**
     * @return array{new: int, to_reassign: int}
     */
    public function navCounts(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            return $this->cache = [
                'new' => $this->personRepository->count(['status' => PersonStatus::NEW->value]),
                'to_reassign' => $this->personRepository->count(['status' => PersonStatus::TO_REASSIGN->value]),
            ];
        } catch (\Throwable) {
            return $this->cache = ['new' => 0, 'to_reassign' => 0];
        }
    }
}
