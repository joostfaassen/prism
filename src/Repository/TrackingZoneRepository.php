<?php

namespace App\Repository;

use App\Entity\TrackingZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackingZone>
 */
class TrackingZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackingZone::class);
    }

    /** @return list<TrackingZone> */
    public function findByServer(string $serverName): array
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.serverName = :server')
            ->setParameter('server', $serverName)
            ->orderBy('z.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?TrackingZone
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.serverName = :server')
            ->andWhere('z.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
