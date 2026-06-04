<?php

namespace App\Repository;

use App\Entity\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentType>
 */
class DocumentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentType::class);
    }

    /**
     * @return list<DocumentType>
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

    public function findOneByServerAndName(string $serverName, string $name): ?DocumentType
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.serverName = :server')
            ->andWhere('t.name = :name')
            ->setParameter('server', $serverName)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?DocumentType
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.serverName = :server')
            ->andWhere('t.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByXuid(string $xuid): ?DocumentType
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }
}
