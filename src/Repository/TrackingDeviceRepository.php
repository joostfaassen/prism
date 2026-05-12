<?php

namespace App\Repository;

use App\Entity\TrackingDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackingDevice>
 */
class TrackingDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackingDevice::class);
    }

    /** @return list<TrackingDevice> */
    public function findByServer(string $serverName): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.serverName = :server')
            ->setParameter('server', $serverName)
            ->orderBy('d.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByServerAndXuid(string $serverName, string $xuid): ?TrackingDevice
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.serverName = :server')
            ->andWhere('d.xuid = :xuid')
            ->setParameter('server', $serverName)
            ->setParameter('xuid', $xuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByServerAndSlug(string $serverName, string $slug): ?TrackingDevice
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.serverName = :server')
            ->andWhere('d.slug = :slug')
            ->setParameter('server', $serverName)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
