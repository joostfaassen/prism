<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return list<Document>
     */
    public function findByServer(string $serverName, ?string $typeName = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->setParameter('server', $serverName)
            ->orderBy('n.name', 'ASC');

        if ($typeName !== null) {
            $qb->join('n.documentType', 't')
                ->andWhere('t.name = :typeName')
                ->setParameter('typeName', $typeName);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByServerAndName(string $serverName, string $name): ?Document
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->andWhere('n.name = :name')
            ->setParameter('server', $serverName)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?Document
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.serverName = :server')
            ->andWhere('n.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByXuid(string $xuid): ?Document
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }
}
