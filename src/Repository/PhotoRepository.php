<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Photo;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /**
     * Sichtbare Fotos, neueste Aufnahme zuerst. Ohne Suche steht jede Serie nur mit ihrem besten Foto in der Liste.
     *
     * @return Paginator<Photo>
     */
    public function findVisiblePage(?User $user, bool $isAdmin, int $page, int $perPage, string $search = ''): Paginator
    {
        $qb = $this
            ->createQueryBuilder('p')
            ->addSelect('t')
            ->leftJoin('p.thumbnailFile', 't')
            ->addSelect('COALESCE(p.takenAt, p.createdAt) AS HIDDEN sortDate')
            ->orderBy('sortDate', \SortDirection::Descending)
            ->addOrderBy('p.id', \SortDirection::Descending)
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);
        if (trim($search) === '') {
            // Serienfotos: nur das beste Foto, die übrigen klappt die Seite darunter auf
            $qb->andWhere('p.burstCover IS NULL');
        } else {
            $qb->andWhere(sprintf(
                'p.title LIKE :search OR EXISTS (SELECT 1 FROM %s search_p JOIN search_p.tags search_tag '
                . 'WHERE search_p = p AND search_tag.name LIKE :search)',
                Photo::class,
            ))->setParameter('search', '%' . addcslashes(trim($search), '%_') . '%');
        }
        MediumRepository::restrictToVisible($qb, 'p', $user, $isAdmin);

        // Nur Einzelbeziehungen gejoint: einfaches LIMIT genügt (das Umschreiben für Sammlungen verträgt die Pool-Unterabfragen nicht)
        return new Paginator($qb, fetchJoinCollection: false);
    }
}
