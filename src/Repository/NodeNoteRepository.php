<?php

namespace App\Repository;

use App\Entity\NodeNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NodeNote>
 */
class NodeNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeNote::class);
    }

    public function findOneByXuid(string $xuid): ?NodeNote
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }

    /**
     * @return list<NodeNote>
     */
    public function findByNode(int $nodeId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.node = :nodeId')
            ->setParameter('nodeId', $nodeId)
            ->orderBy('n.summary', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
