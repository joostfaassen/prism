<?php

namespace App\Tracking;

use App\Config\PrismConfigLoader;
use App\Entity\GpsSample;
use App\Entity\TrackingDevice;
use App\Entity\TrackingZone;
use App\Repository\GpsSampleRepository;
use App\Repository\TrackingDeviceRepository;
use App\Repository\TrackingZoneRepository;
use App\Repository\ZoneEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrackingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrackingDeviceRepository $deviceRepository,
        private readonly GpsSampleRepository $gpsSampleRepository,
        private readonly TrackingZoneRepository $zoneRepository,
        private readonly ZoneEventRepository $zoneEventRepository,
        private readonly GpsPayloadParser $parser,
        private readonly ZoneDetector $zoneDetector,
        private readonly PrismConfigLoader $prismConfigLoader,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listDevices(string $serverName): array
    {
        return array_map(
            static fn (TrackingDevice $d) => $d->toArray(false),
            $this->deviceRepository->findByServer($serverName),
        );
    }

    /** @return list<array<string, mixed>> */
    public function listZones(string $serverName): array
    {
        return array_map(
            static fn (TrackingZone $z) => $z->toArray(),
            $this->zoneRepository->findByServer($serverName),
        );
    }

    public function createDevice(
        string $serverName,
        string $slug,
        string $label,
        string $postFormatPreset,
        ?string $postFormatJson,
        string $mapColor,
    ): TrackingDevice {
        $slug = $this->normalizeSlug($slug);
        $this->assertUniqueDeviceSlug($serverName, $slug, null);

        $device = new TrackingDevice(
            $serverName,
            $slug,
            $label,
            TrackingDevice::generateIngestSecret(),
            $this->normalizePreset($postFormatPreset),
            $mapColor,
        );
        $device->setPostFormatJson($postFormatJson);

        $this->em->persist($device);
        $this->em->flush();

        return $device;
    }

    public function updateDevice(
        TrackingDevice $device,
        string $slug,
        string $label,
        string $postFormatPreset,
        ?string $postFormatJson,
        string $mapColor,
    ): void {
        $slug = $this->normalizeSlug($slug);
        $this->assertUniqueDeviceSlug($device->getServerName(), $slug, $device);

        $device->setSlug($slug);
        $device->setLabel($label);
        $device->setPostFormatPreset($this->normalizePreset($postFormatPreset));
        $device->setPostFormatJson($postFormatJson);
        $device->setMapColor($mapColor);

        $this->em->flush();
    }

    public function regenerateDeviceSecret(TrackingDevice $device): string
    {
        $secret = TrackingDevice::generateIngestSecret();
        $device->setIngestSecret($secret);
        $this->em->flush();

        return $secret;
    }

    public function deleteDevice(TrackingDevice $device): void
    {
        $this->em->remove($device);
        $this->em->flush();
    }

    public function createZone(
        string $serverName,
        string $slug,
        string $name,
        float $latitude,
        float $longitude,
        int $radiusMeters,
        ?string $notes,
    ): TrackingZone {
        $slug = $this->normalizeSlug($slug);
        $this->assertUniqueZoneSlug($serverName, $slug, null);

        $zone = new TrackingZone($serverName, $slug, $name, $latitude, $longitude, $radiusMeters);
        $zone->setNotes($notes);

        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    public function updateZone(
        TrackingZone $zone,
        string $slug,
        string $name,
        float $latitude,
        float $longitude,
        int $radiusMeters,
        ?string $notes,
    ): void {
        $slug = $this->normalizeSlug($slug);
        $this->assertUniqueZoneSlug($zone->getServerName(), $slug, $zone);

        $zone->setSlug($slug);
        $zone->setName($name);
        $zone->setLatitude($latitude);
        $zone->setLongitude($longitude);
        $zone->setRadiusMeters($radiusMeters);
        $zone->setNotes($notes);

        $this->em->flush();
    }

    public function deleteZone(TrackingZone $zone): void
    {
        $this->em->remove($zone);
        $this->em->flush();
    }

    /**
     * @return array{accepted: int, errors: list<string>}
     */
    public function ingestJson(string $serverName, string $deviceSlug, ?string $authorizationHeader, string $jsonBody): array
    {
        $server = $this->prismConfigLoader->getServer($serverName);
        $device = $this->deviceRepository->findOneByServerAndSlug($serverName, $deviceSlug);
        if ($device === null) {
            throw new NotFoundHttpException('Unknown device slug for this server.');
        }

        $token = $this->extractBearer($authorizationHeader);
        if ($token === '' || ($token !== $server->bearerToken && $token !== $device->getIngestSecret())) {
            throw new AccessDeniedHttpException('Invalid or missing bearer token.');
        }

        try {
            $data = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON root must be an object or array.');
        }

        $rows = $this->parser->extractSamples($data, $device->getPostFormatPreset(), $device->getPostFormatJson());
        if ($rows === []) {
            throw new \InvalidArgumentException('No GPS coordinates could be parsed from the payload.');
        }

        usort($rows, static fn (array $a, array $b) => $a['at'] <=> $b['at']);

        $accepted = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $this->validateCoords($row['lat'], $row['lon']);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            $rawFragment = json_encode($row, JSON_THROW_ON_ERROR);
            $sample = new GpsSample(
                $device,
                $row['at'],
                $row['lat'],
                $row['lon'],
                $row['accuracy'],
                $rawFragment,
            );
            $this->em->persist($sample);
            $this->em->flush();
            $this->zoneDetector->onNewSample($sample);
            $this->em->flush();
            ++$accepted;
        }

        return ['accepted' => $accepted, 'errors' => $errors];
    }

    /**
     * @param list<string>|null $deviceXuids null = all devices; empty list = none
     * @return list<array<string, mixed>>
     */
    public function getTraceForDay(string $serverName, string $dateYmd, \DateTimeZone $tz, ?array $deviceXuids = null): array
    {
        $start = new \DateTimeImmutable($dateYmd . ' 00:00:00', $tz);
        $end = $start->modify('+1 day');

        $devices = $this->deviceRepository->findByServer($serverName);
        if ($deviceXuids !== null) {
            if ($deviceXuids === []) {
                $devices = [];
            } else {
                $set = array_flip($deviceXuids);
                $devices = array_values(array_filter(
                    $devices,
                    static fn (TrackingDevice $d) => isset($set[$d->getXuid()]),
                ));
            }
        }

        $ids = [];
        foreach ($devices as $d) {
            $id = $d->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        $samples = $this->gpsSampleRepository->findForDevicesBetween($serverName, $ids, $start, $end);

        $out = [];
        foreach ($samples as $s) {
            $dev = $s->getDevice();
            $out[] = [
                'device_xuid' => $dev->getXuid(),
                'device_label' => $dev->getLabel(),
                'map_color' => $dev->getMapColor(),
                ...$s->toArray(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queryZoneEvents(
        string $serverName,
        string $zoneXuid,
        string $fromIso,
        string $toIso,
        ?string $deviceXuid = null,
    ): array {
        $zone = $this->zoneRepository->findOneByServerAndXuid($serverName, $zoneXuid);
        if ($zone === null) {
            throw new \InvalidArgumentException('Unknown zone xuid.');
        }

        $from = new \DateTimeImmutable($fromIso);
        $to = new \DateTimeImmutable($toIso);
        $device = null;
        if ($deviceXuid !== null && $deviceXuid !== '') {
            $device = $this->deviceRepository->findOneByServerAndXuid($serverName, $deviceXuid);
            if ($device === null) {
                throw new \InvalidArgumentException('Unknown device xuid.');
            }
        }

        $events = $this->zoneEventRepository->findByZoneBetween($serverName, $zone, $from, $to, $device);

        return array_map(static fn ($e) => $e->toArray(), $events);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queryAllZoneEventsForDay(string $serverName, string $dateYmd, \DateTimeZone $tz): array
    {
        $start = new \DateTimeImmutable($dateYmd . ' 00:00:00', $tz);
        $end = $start->modify('+1 day');
        $events = $this->zoneEventRepository->findByServerBetween($serverName, $start, $end);

        return array_map(static fn ($e) => $e->toArray(), $events);
    }

    public function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : 'device';
    }

    private function normalizePreset(string $preset): string
    {
        $allowed = ['latlng_flat', 'lonlat_pair', 'owntracks', 'apple_shortcuts', 'custom'];

        return in_array($preset, $allowed, true) ? $preset : 'latlng_flat';
    }

    private function assertUniqueDeviceSlug(string $serverName, string $slug, ?TrackingDevice $ignore): void
    {
        $existing = $this->deviceRepository->findOneByServerAndSlug($serverName, $slug);
        if ($existing !== null && ($ignore === null || $existing->getId() !== $ignore->getId())) {
            throw new \InvalidArgumentException(sprintf('Device slug "%s" is already in use.', $slug));
        }
    }

    private function assertUniqueZoneSlug(string $serverName, string $slug, ?TrackingZone $ignore): void
    {
        foreach ($this->zoneRepository->findByServer($serverName) as $z) {
            if ($z->getSlug() === $slug && ($ignore === null || $z->getId() !== $ignore->getId())) {
                throw new \InvalidArgumentException(sprintf('Location slug "%s" is already in use.', $slug));
            }
        }
    }

    private function extractBearer(?string $header): string
    {
        if ($header === null || $header === '') {
            return '';
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }

        return '';
    }

    private function validateCoords(float $lat, float $lon): void
    {
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw new \InvalidArgumentException('Latitude or longitude out of range.');
        }
    }
}
