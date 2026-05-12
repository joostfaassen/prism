<?php

namespace App\Repository;

use App\Entity\GpsSample;
use App\Entity\TrackingDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GpsSample>
 */
class GpsSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GpsSample::class);
    }

    public function findPreviousBefore(TrackingDevice $device, \DateTimeImmutable $before): ?GpsSample
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.device = :device')
            ->andWhere('s.recordedAt < :before')
            ->setParameter('device', $device)
            ->setParameter('before', $before)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $deviceIds
     * @return list<GpsSample>
     */
    public function findForDevicesBetween(
        string $serverName,
        array $deviceIds,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): array {
        if ($deviceIds === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.device', 'd')
            ->andWhere('d.serverName = :server')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('s.recordedAt >= :start')
            ->andWhere('s.recordedAt < :end')
            ->setParameter('server', $serverName)
            ->setParameter('ids', $deviceIds)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('s.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Latest GPS sample timestamp per device for a server (for last-seen / stale checks).
     *
     * @return array<int, \DateTimeImmutable> device id => last recorded_at
     */
    public function findLastRecordedAtByServer(string $serverName): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT d.id AS device_id, MAX(s.recorded_at) AS last_ping
            FROM gps_sample s
            INNER JOIN tracking_device d ON s.device_id = d.id
            WHERE d.server_name = :server
            GROUP BY d.id
            SQL;

        $rows = $conn->executeQuery($sql, ['server' => $serverName])->fetchAllAssociative();
        $map = [];

        foreach ($rows as $row) {
            $id = (int) ($row['device_id'] ?? 0);
            $raw = $row['last_ping'] ?? null;
            if ($id <= 0 || $raw === null || $raw === '') {
                continue;
            }
            if ($raw instanceof \DateTimeImmutable) {
                $map[$id] = $raw;
            } elseif ($raw instanceof \DateTimeInterface) {
                $map[$id] = \DateTimeImmutable::createFromInterface($raw);
            } else {
                $map[$id] = new \DateTimeImmutable((string) $raw);
            }
        }

        return $map;
    }
}
