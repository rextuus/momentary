<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Medium;
use App\Entity\Person;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoSceneTag;
use App\Enum\PersonStatus;
use App\Enum\VideoStatus;
use App\Repository\MediumRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * API: nur fertige Videos, nur benannte Personen, und alles nur aus Medien, die der angemeldete Benutzer sehen darf
 * (Pools, Gruppen, öffentlich, Eigentümer; siehe MediumRepository::visibleCondition()).
 */
final class HubExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $alias = $queryBuilder->getRootAliases()[0];
        $user = $this->security->getUser();
        $user = $user instanceof User ? $user : null;
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        if (Person::class === $resourceClass) {
            $queryBuilder->andWhere(sprintf('%s.status = :status', $alias));
            $queryBuilder->setParameter('status', PersonStatus::IDENTIFIED);
            // Nur Personen, die in einem sichtbaren Medium vorkommen
            $this->requireVisible(
                $queryBuilder,
                sprintf(
                    'EXISTS (SELECT 1 FROM %s api_pf JOIN api_pf.medium api_pm WHERE api_pf.person = %s AND %%s)',
                    VideoFace::class,
                    $alias,
                ),
                'api_pm',
                $user,
                $isAdmin,
            );
        }
        if (Video::class === $resourceClass) {
            $queryBuilder->andWhere(sprintf('%s.status = :status', $alias));
            $queryBuilder->setParameter('status', VideoStatus::COMPLETED);
            MediumRepository::restrictToVisible($queryBuilder, $alias, $user, $isAdmin);
        }
        if (VideoFace::class === $resourceClass) {
            $this->requireVisible(
                $queryBuilder,
                sprintf('EXISTS (SELECT 1 FROM %s api_fm WHERE api_fm = %s.medium AND %%s)', Medium::class, $alias),
                'api_fm',
                $user,
                $isAdmin,
            );
        }
        if (Tag::class === $resourceClass) {
            // Tags sichtbarer Medien oder sichtbarer Video-Szenen; „Erwähnte Personen“ verraten sonst Namen
            $this->requireVisible(
                $queryBuilder,
                sprintf(
                    '(EXISTS (SELECT 1 FROM %1$s api_tm JOIN api_tm.tags api_tt WHERE api_tt = %3$s AND %%1$s) '
                    . 'OR EXISTS (SELECT 1 FROM %2$s api_st JOIN api_st.videoScene api_ss JOIN api_ss.video api_sv '
                    . 'WHERE api_st.tag = %3$s AND %%2$s))',
                    Medium::class,
                    VideoSceneTag::class,
                    $alias,
                ),
                ['api_tm', 'api_sv'],
                $user,
                $isAdmin,
            );
        }
    }

    /**
     * @param string|list<string> $mediumAliases Platzhalter %s bzw. %1$s, %2$s im Template je Alias
     */
    private function requireVisible(
        QueryBuilder $queryBuilder,
        string $template,
        string|array $mediumAliases,
        ?User $user,
        bool $isAdmin,
    ): void {
        if ($isAdmin) {
            return;
        }
        $conditions = array_map(
            static fn(string $mediumAlias): string => (string) MediumRepository::visibleCondition(
                $mediumAlias,
                $user,
                false,
            ),
            (array) $mediumAliases,
        );
        $queryBuilder->andWhere(vsprintf($template, $conditions));
        if ($user !== null) {
            $queryBuilder->setParameter('visibleFor', $user);
        }
    }
}
