<?php

namespace App\Repository;

use App\Entity\NodeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NodeType>
 */
class NodeTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeType::class);
    }

    /**
     * @return list<NodeType>
     */
    public function findByServer(string $serverName): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.serverName = :server')
            ->setParameter('server', $serverName)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByServerAndName(string $serverName, string $name): ?NodeType
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.serverName = :server')
            ->andWhere('t.name = :name')
            ->setParameter('server', $serverName)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?NodeType
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.serverName = :server')
            ->andWhere('t.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByXuid(string $xuid): ?NodeType
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }
}
