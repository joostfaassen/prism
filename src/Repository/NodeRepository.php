<?php

namespace App\Repository;

use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Node>
 */
class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    /**
     * @return list<Node>
     */
    public function findByServer(string $serverName, ?string $typeName = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->setParameter('server', $serverName)
            ->orderBy('n.name', 'ASC');

        if ($typeName !== null) {
            $qb->join('n.nodeType', 't')
                ->andWhere('t.name = :typeName')
                ->setParameter('typeName', $typeName);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByServerAndName(string $serverName, string $name): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->andWhere('n.name = :name')
            ->setParameter('server', $serverName)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->andWhere('n.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByXuid(string $xuid): ?Node
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }
}
