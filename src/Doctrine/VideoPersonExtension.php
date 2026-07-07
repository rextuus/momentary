<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use App\Entity\Video;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

final class VideoPersonExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private RequestStack $requestStack
    ) {}

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Video::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $filterPersonId = $request->query->get('videoFaces_person') ?? $request->query->get('person');
        $filterPersonName = $request->query->get('videoFaces_person_name');

        if ($filterPersonId === null && $filterPersonName === null) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $facesAlias = $queryNameGenerator->generateJoinAlias('videoFaces');
        $personAlias = $queryNameGenerator->generateJoinAlias('person');

        $queryBuilder
            ->join(sprintf('%s.videoFaces', $rootAlias), $facesAlias)
            ->join(sprintf('%s.person', $facesAlias), $personAlias);

        if ($filterPersonId !== null) {
            $parameterName = $queryNameGenerator->generateParameterName('personId');
            $queryBuilder
                ->andWhere(sprintf('%s.id = :%s', $personAlias, $parameterName))
                ->setParameter($parameterName, $filterPersonId);
        }

        if ($filterPersonName !== null) {
            $parameterName = $queryNameGenerator->generateParameterName('personName');
            $queryBuilder
                ->andWhere(sprintf('%s.name LIKE :%s', $personAlias, $parameterName))
                ->setParameter($parameterName, '%' . $filterPersonName . '%');
        }
    }
}
