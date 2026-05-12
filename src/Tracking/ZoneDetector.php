<?php

namespace App\Tracking;

use App\Entity\GpsSample;
use App\Entity\ZoneEvent;
use App\Repository\GpsSampleRepository;
use App\Repository\TrackingZoneRepository;
use Doctrine\ORM\EntityManagerInterface;

class ZoneDetector
{
    public function __construct(
        private readonly GpsSampleRepository $gpsSampleRepository,
        private readonly TrackingZoneRepository $zoneRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function onNewSample(GpsSample $sample): void
    {
        $device = $sample->getDevice();
        $serverName = $device->getServerName();
        $prev = $this->gpsSampleRepository->findPreviousBefore($device, $sample->getRecordedAt());

        $zones = $this->zoneRepository->findByServer($serverName);

        foreach ($zones as $zone) {
            $nowInside = GeoUtils::isInsideRadius(
                $sample->getLatitude(),
                $sample->getLongitude(),
                $zone->getLatitude(),
                $zone->getLongitude(),
                $zone->getRadiusMeters(),
            );

            $prevInside = false;
            if ($prev !== null) {
                $prevInside = GeoUtils::isInsideRadius(
                    $prev->getLatitude(),
                    $prev->getLongitude(),
                    $zone->getLatitude(),
                    $zone->getLongitude(),
                    $zone->getRadiusMeters(),
                );
            }

            if ($nowInside && !$prevInside) {
                $this->em->persist(new ZoneEvent(
                    $serverName,
                    $zone,
                    $device,
                    ZoneEvent::TYPE_ENTER,
                    $sample->getRecordedAt(),
                ));
            } elseif (!$nowInside && $prevInside) {
                $this->em->persist(new ZoneEvent(
                    $serverName,
                    $zone,
                    $device,
                    ZoneEvent::TYPE_EXIT,
                    $sample->getRecordedAt(),
                ));
            }
        }
    }
}
