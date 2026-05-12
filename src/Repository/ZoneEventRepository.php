<?php

namespace App\Repository;

use App\Entity\TrackingDevice;
use App\Entity\TrackingZone;
use App\Entity\ZoneEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZoneEvent>
 */
class ZoneEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZoneEvent::class);
    }

    /**
     * @return list<ZoneEvent>
     */
    public function findByZoneBetween(
        string $serverName,
        TrackingZone $zone,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?TrackingDevice $device = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.serverName = :server')
            ->andWhere('e.zone = :zone')
            ->andWhere('e.occurredAt >= :from')
            ->andWhere('e.occurredAt <= :to')
            ->setParameter('server', $serverName)
            ->setParameter('zone', $zone)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.occurredAt', 'ASC');

        if ($device !== null) {
            $qb->andWhere('e.device = :device')
                ->setParameter('device', $device);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<ZoneEvent>
     */
    public function findByServerBetween(
        string $serverName,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('e')
            ->andWhere('e.serverName = :server')
            ->andWhere('e.occurredAt >= :from')
            ->andWhere('e.occurredAt <= :to')
            ->setParameter('server', $serverName)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
