<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Entity\TrackingDevice;
use App\Entity\TrackingZone;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use App\Repository\GpsSampleRepository;
use App\Repository\TrackingDeviceRepository;
use App\Repository\TrackingZoneRepository;
use App\Tracking\LastPingFormatter;
use App\Tracking\TrackingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TrackingAdminController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly TrackingDeviceRepository $deviceRepository,
        private readonly TrackingZoneRepository $zoneRepository,
        private readonly GpsSampleRepository $gpsSampleRepository,
        private readonly LastPingFormatter $lastPingFormatter,
        private readonly TrackingService $trackingService,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('/admin/server/{serverName}/tracking', name: 'admin_tracking_home', methods: ['GET'])]
    public function home(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $baseUrl = $request->getSchemeAndHttpHost();
        $devices = $this->deviceRepository->findByServer($serverName);
        $zones = $this->zoneRepository->findByServer($serverName);

        return $this->render('admin/tracking/hub.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'activeSection' => 'tracking',
            'deviceCount' => count($devices),
            'zoneCount' => count($zones),
            'ingestBaseUrl' => $baseUrl . '/api/track/' . rawurlencode($serverName) . '/',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/devices', name: 'admin_tracking_devices', methods: ['GET'])]
    public function devices(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $devices = $this->deviceRepository->findByServer($serverName);
        $baseUrl = $request->getSchemeAndHttpHost();
        $lastAtById = $this->gpsSampleRepository->findLastRecordedAtByServer($serverName);
        $devicePingInfo = $this->lastPingFormatter->buildForDevices($devices, $lastAtById);

        return $this->render('admin/tracking/devices_list.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'devices' => $devices,
            'devicePingInfo' => $devicePingInfo,
            'ingestBaseUrl' => $baseUrl . '/api/track/' . rawurlencode($serverName) . '/',
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/devices/new', name: 'admin_tracking_device_new', methods: ['GET', 'POST'])]
    public function deviceNew(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleDeviceForm($request, $serverName, $serverConfig, null);
        }

        return $this->render('admin/tracking/device_edit.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'device' => null,
            'formData' => $this->defaultDeviceFormData(),
            'errors' => [],
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/devices/{xuid}/edit', name: 'admin_tracking_device_edit', methods: ['GET', 'POST'])]
    public function deviceEdit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $device = $this->resolveDevice($serverName, $xuid);

        if ($request->isMethod('POST')) {
            return $this->handleDeviceForm($request, $serverName, $serverConfig, $device);
        }

        return $this->render('admin/tracking/device_edit.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'device' => $device,
            'formData' => [
                'slug' => $device->getSlug(),
                'label' => $device->getLabel(),
                'map_color' => $device->getMapColor(),
                'post_format_preset' => $device->getPostFormatPreset(),
                'post_format_json' => (string) ($device->getPostFormatJson() ?? ''),
            ],
            'errors' => [],
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/devices/{xuid}/delete', name: 'admin_tracking_device_delete', methods: ['POST'])]
    public function deviceDelete(Request $request, string $serverName, string $xuid): Response
    {
        $device = $this->resolveDevice($serverName, $xuid);
        if (!$this->isCsrfTokenValid('tracking_device_delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
        } else {
            $this->trackingService->deleteDevice($device);
            $this->addFlash('success', 'Device deleted.');
        }

        return $this->redirectToRoute('admin_tracking_devices', ['serverName' => $serverName]);
    }

    #[Route('/admin/server/{serverName}/tracking/devices/{xuid}/regenerate-secret', name: 'admin_tracking_device_regenerate', methods: ['POST'])]
    public function deviceRegenerateSecret(Request $request, string $serverName, string $xuid): Response
    {
        $device = $this->resolveDevice($serverName, $xuid);
        if (!$this->isCsrfTokenValid('tracking_device_regenerate', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
        } else {
            $this->trackingService->regenerateDeviceSecret($device);
            $this->addFlash('success', 'Ingest secret was regenerated. Update your clients.');
        }

        return $this->redirectToRoute('admin_tracking_device_edit', ['serverName' => $serverName, 'xuid' => $xuid]);
    }

    #[Route('/admin/server/{serverName}/tracking/zones', name: 'admin_tracking_zones', methods: ['GET'])]
    public function zones(string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $zones = $this->zoneRepository->findByServer($serverName);

        return $this->render('admin/tracking/zones_list.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'zones' => $zones,
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/zones/new', name: 'admin_tracking_zone_new', methods: ['GET', 'POST'])]
    public function zoneNew(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleZoneForm($request, $serverName, $serverConfig, null);
        }

        return $this->render('admin/tracking/zone_edit.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'zone' => null,
            'formData' => [
                'slug' => '',
                'name' => '',
                'latitude' => '',
                'longitude' => '',
                'radius_meters' => '100',
                'notes' => '',
            ],
            'errors' => [],
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/zones/{xuid}/edit', name: 'admin_tracking_zone_edit', methods: ['GET', 'POST'])]
    public function zoneEdit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $zone = $this->resolveZone($serverName, $xuid);

        if ($request->isMethod('POST')) {
            return $this->handleZoneForm($request, $serverName, $serverConfig, $zone);
        }

        return $this->render('admin/tracking/zone_edit.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'zone' => $zone,
            'formData' => [
                'slug' => $zone->getSlug(),
                'name' => $zone->getName(),
                'latitude' => (string) $zone->getLatitude(),
                'longitude' => (string) $zone->getLongitude(),
                'radius_meters' => (string) $zone->getRadiusMeters(),
                'notes' => (string) ($zone->getNotes() ?? ''),
            ],
            'errors' => [],
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/zones/{xuid}/delete', name: 'admin_tracking_zone_delete', methods: ['POST'])]
    public function zoneDelete(Request $request, string $serverName, string $xuid): Response
    {
        $zone = $this->resolveZone($serverName, $xuid);
        if (!$this->isCsrfTokenValid('tracking_zone_delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
        } else {
            $this->trackingService->deleteZone($zone);
            $this->addFlash('success', 'Location deleted.');
        }

        return $this->redirectToRoute('admin_tracking_zones', ['serverName' => $serverName]);
    }

    #[Route('/admin/server/{serverName}/tracking/map', name: 'admin_tracking_map', methods: ['GET'])]
    public function map(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $tz = $this->trackingTimeZone($serverConfig);
        $date = $request->query->getString('date', (new \DateTimeImmutable('now', $tz))->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        }

        $queryAll = $request->query->all();
        $hasDeviceParam = array_key_exists('devices', $queryAll);
        $rawDevices = $queryAll['devices'] ?? [];
        if (!is_array($rawDevices)) {
            $rawDevices = $rawDevices !== null && $rawDevices !== '' ? [(string) $rawDevices] : [];
        }
        /** @var list<string>|null $deviceXuids */
        $deviceXuids = null;
        if ($hasDeviceParam) {
            $deviceXuids = array_values(array_filter(array_map('strval', $rawDevices)));
        }

        $devices = $this->deviceRepository->findByServer($serverName);
        $points = $this->trackingService->getTraceForDay($serverName, $date, $tz, $deviceXuids);
        $selectedForTemplate = $hasDeviceParam ? $deviceXuids : null;
        $zones = $this->zoneRepository->findByServer($serverName);
        $zonesPayload = array_map(static fn (TrackingZone $z) => [
            'name' => $z->getName(),
            'lat' => $z->getLatitude(),
            'lon' => $z->getLongitude(),
            'radius_m' => $z->getRadiusMeters(),
        ], $zones);

        return $this->render('admin/tracking/map.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'traceDate' => $date,
            'timezoneName' => $tz->getName(),
            'devices' => $devices,
            'selectedDeviceXuids' => $selectedForTemplate,
            'tracePointsJson' => json_encode($points, JSON_THROW_ON_ERROR),
            'zonesJson' => json_encode($zonesPayload, JSON_THROW_ON_ERROR),
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/zone-events', name: 'admin_tracking_zone_events', methods: ['GET'])]
    public function zoneEvents(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $tz = $this->trackingTimeZone($serverConfig);
        $zones = $this->zoneRepository->findByServer($serverName);
        $devices = $this->deviceRepository->findByServer($serverName);

        $zoneXuid = $request->query->getString('zone_xuid');
        $deviceXuid = $request->query->getString('device_xuid');
        $from = $request->query->getString('from');
        $to = $request->query->getString('to');

        if ($from === '') {
            $from = (new \DateTimeImmutable('now', $tz))->modify('-7 days')->format('Y-m-d\T00:00:00');
        }
        if ($to === '') {
            $to = (new \DateTimeImmutable('now', $tz))->format('Y-m-d\T23:59:59');
        }

        $events = [];
        $error = null;
        if ($zoneXuid !== '') {
            try {
                $events = $this->trackingService->queryZoneEvents(
                    $serverName,
                    $zoneXuid,
                    $from,
                    $to,
                    $deviceXuid !== '' ? $deviceXuid : null,
                );
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/tracking/zone_report.html.twig', [
            ...$this->nav($serverName, $serverConfig),
            'zones' => $zones,
            'devices' => $devices,
            'zoneXuid' => $zoneXuid,
            'deviceXuid' => $deviceXuid,
            'from' => $from,
            'to' => $to,
            'events' => $events,
            'error' => $error,
            'timezoneName' => $tz->getName(),
            'activeSection' => 'tracking',
        ]);
    }

    #[Route('/admin/server/{serverName}/tracking/api/day-events', name: 'admin_tracking_api_day_events', methods: ['GET'])]
    public function apiDayZoneEvents(Request $request, string $serverName): JsonResponse
    {
        $serverConfig = $this->resolveServer($serverName);
        $tz = $this->trackingTimeZone($serverConfig);
        $date = $request->query->getString('date', (new \DateTimeImmutable('now', $tz))->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new JsonResponse(['error' => 'Invalid date'], 400);
        }

        try {
            $events = $this->trackingService->queryAllZoneEventsForDay($serverName, $date, $tz);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['date' => $date, 'timezone' => $tz->getName(), 'events' => $events]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultDeviceFormData(): array
    {
        return [
            'slug' => '',
            'label' => '',
            'map_color' => '#22d3ee',
            'post_format_preset' => 'latlng_flat',
            'post_format_json' => '{"latitude_path":"lat","longitude_path":"lon","timestamp_path":"ts","accuracy_path":"acc"}',
        ];
    }

    private function handleDeviceForm(Request $request, string $serverName, ServerConfig $serverConfig, ?TrackingDevice $device): Response
    {
        $slug = trim($request->request->getString('slug'));
        $label = trim($request->request->getString('label'));
        $mapColor = trim($request->request->getString('map_color')) ?: '#22d3ee';
        $preset = trim($request->request->getString('post_format_preset')) ?: 'latlng_flat';
        $json = trim($request->request->getString('post_format_json'));
        $json = $json !== '' ? $json : null;

        $errors = [];
        if ($slug === '') {
            $errors[] = 'Slug is required (used in the ingest URL).';
        }
        if ($label === '') {
            $errors[] = 'Label is required.';
        }
        if ($preset === 'custom' && ($json === null || $json === '')) {
            $errors[] = 'Custom preset requires JSON path configuration.';
        }
        if ($json !== null) {
            try {
                json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $errors[] = 'Post format JSON must be valid JSON.';
            }
        }

        if ($errors !== []) {
            return $this->render('admin/tracking/device_edit.html.twig', [
                ...$this->nav($serverName, $serverConfig),
                'device' => $device,
                'formData' => [
                    'slug' => $slug,
                    'label' => $label,
                    'map_color' => $mapColor,
                    'post_format_preset' => $preset,
                    'post_format_json' => (string) ($json ?? ''),
                ],
                'errors' => $errors,
                'activeSection' => 'tracking',
            ]);
        }

        $wasNew = $device === null;

        try {
            if ($device === null) {
                $device = $this->trackingService->createDevice($serverName, $slug, $label, $preset, $json, $mapColor);
            } else {
                $this->trackingService->updateDevice($device, $slug, $label, $preset, $json, $mapColor);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->render('admin/tracking/device_edit.html.twig', [
                ...$this->nav($serverName, $serverConfig),
                'device' => $device,
                'formData' => [
                    'slug' => $slug,
                    'label' => $label,
                    'map_color' => $mapColor,
                    'post_format_preset' => $preset,
                    'post_format_json' => (string) ($json ?? ''),
                ],
                'errors' => [$e->getMessage()],
                'activeSection' => 'tracking',
            ]);
        }

        $this->addFlash('success', $wasNew ? 'Device created.' : 'Device updated.');

        return $this->redirectToRoute('admin_tracking_device_edit', [
            'serverName' => $serverName,
            'xuid' => $device->getXuid(),
        ]);
    }

    private function handleZoneForm(Request $request, string $serverName, ServerConfig $serverConfig, ?TrackingZone $zone): Response
    {
        $slug = trim($request->request->getString('slug'));
        $name = trim($request->request->getString('name'));
        $latStr = trim($request->request->getString('latitude'));
        $lonStr = trim($request->request->getString('longitude'));
        $radiusStr = trim($request->request->getString('radius_meters'));
        $notes = trim($request->request->getString('notes'));
        $notes = $notes !== '' ? $notes : null;

        $errors = [];
        if ($slug === '') {
            $errors[] = 'Slug is required.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!is_numeric($latStr) || !is_numeric($lonStr)) {
            $errors[] = 'Latitude and longitude must be numbers.';
        }
        if (!is_numeric($radiusStr) || (int) $radiusStr <= 0) {
            $errors[] = 'Radius must be a positive integer (meters).';
        }

        $latitude = (float) $latStr;
        $longitude = (float) $lonStr;
        $radiusMeters = (int) $radiusStr;

        if ($errors !== []) {
            return $this->render('admin/tracking/zone_edit.html.twig', [
                ...$this->nav($serverName, $serverConfig),
                'zone' => $zone,
                'formData' => [
                    'slug' => $slug,
                    'name' => $name,
                    'latitude' => $latStr,
                    'longitude' => $lonStr,
                    'radius_meters' => $radiusStr,
                    'notes' => (string) ($notes ?? ''),
                ],
                'errors' => $errors,
                'activeSection' => 'tracking',
            ]);
        }

        try {
            if ($zone === null) {
                $this->trackingService->createZone($serverName, $slug, $name, $latitude, $longitude, $radiusMeters, $notes);
            } else {
                $this->trackingService->updateZone($zone, $slug, $name, $latitude, $longitude, $radiusMeters, $notes);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->render('admin/tracking/zone_edit.html.twig', [
                ...$this->nav($serverName, $serverConfig),
                'zone' => $zone,
                'formData' => [
                    'slug' => $slug,
                    'name' => $name,
                    'latitude' => $latStr,
                    'longitude' => $lonStr,
                    'radius_meters' => $radiusStr,
                    'notes' => (string) ($notes ?? ''),
                ],
                'errors' => [$e->getMessage()],
                'activeSection' => 'tracking',
            ]);
        }

        $this->addFlash('success', 'Location saved.');

        return $this->redirectToRoute('admin_tracking_zones', ['serverName' => $serverName]);
    }

    private function resolveServer(string $serverName): ServerConfig
    {
        try {
            return $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Server not found');
        }
    }

    private function resolveDevice(string $serverName, string $xuid): TrackingDevice
    {
        $d = $this->deviceRepository->findOneByServerAndXuid($serverName, $xuid);
        if ($d === null) {
            throw $this->createNotFoundException('Device not found');
        }

        return $d;
    }

    private function resolveZone(string $serverName, string $xuid): TrackingZone
    {
        $z = $this->zoneRepository->findOneByServerAndXuid($serverName, $xuid);
        if ($z === null) {
            throw $this->createNotFoundException('Location not found');
        }

        return $z;
    }

    /**
     * @return array{server: array{name: string, label: string, accountCount: int, toolCount: int}, serverHasHabits: bool, serverHasTracking: bool}
     */
    private function nav(string $serverName, ServerConfig $serverConfig): array
    {
        $tools = $this->mcpHandler->getTools();
        $visible = array_values(array_filter(
            $tools,
            static fn (ToolInterface $tool) => $tool->getAccountType() === null
                || $serverConfig->hasAccountType($tool->getAccountType()),
        ));

        return [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'accountCount' => count($serverConfig->accounts),
                'toolCount' => count($visible),
            ],
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'serverHasTracking' => $serverConfig->hasAccountType('tracking'),
        ];
    }

    private function trackingTimeZone(ServerConfig $serverConfig): \DateTimeZone
    {
        foreach ($serverConfig->accounts as $acc) {
            if (($acc['type'] ?? '') === 'tracking') {
                return new \DateTimeZone($acc['timezone'] ?? 'UTC');
            }
        }

        return new \DateTimeZone('UTC');
    }
}
