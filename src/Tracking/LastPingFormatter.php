<?php

namespace App\Tracking;

/**
 * Human-readable "since last ping" for admin UI and future stale-device alerts.
 */
final class LastPingFormatter
{
    /**
     * @param list<\App\Entity\TrackingDevice> $devices
     * @param array<int, \DateTimeImmutable>   $lastAtByDeviceId
     *
     * @return array<string, array{last_ping_at: ?string, since: string, seconds_ago: ?int, has_ping: bool}>
     */
    public function buildForDevices(array $devices, array $lastAtByDeviceId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable();
        $out = [];

        foreach ($devices as $device) {
            $id = $device->getId();
            $at = ($id !== null && isset($lastAtByDeviceId[$id])) ? $lastAtByDeviceId[$id] : null;

            if ($at === null) {
                $out[$device->getXuid()] = [
                    'last_ping_at' => null,
                    'since' => 'never',
                    'seconds_ago' => null,
                    'has_ping' => false,
                ];

                continue;
            }

            $secondsAgo = $now->getTimestamp() - $at->getTimestamp();
            if ($secondsAgo < 0) {
                $secondsAgo = 0;
            }

            $out[$device->getXuid()] = [
                'last_ping_at' => $at->format('c'),
                'since' => $this->formatSinceLabel($secondsAgo),
                'seconds_ago' => $secondsAgo,
                'has_ping' => true,
            ];
        }

        return $out;
    }

    public function formatSinceLabel(int $secondsAgo): string
    {
        if ($secondsAgo < 45) {
            return 'just now';
        }
        if ($secondsAgo < 90) {
            return 'about 1 minute ago';
        }
        if ($secondsAgo < 3600) {
            $m = intdiv($secondsAgo, 60);

            return $m === 1 ? '1 minute ago' : sprintf('%d minutes ago', $m);
        }
        if ($secondsAgo < 86400) {
            $h = intdiv($secondsAgo, 3600);

            return $h === 1 ? '1 hour ago' : sprintf('%d hours ago', $h);
        }
        if ($secondsAgo < 86400 * 14) {
            $d = intdiv($secondsAgo, 86400);

            return $d === 1 ? '1 day ago' : sprintf('%d days ago', $d);
        }

        $w = intdiv($secondsAgo, 86400 * 7);

        return $w === 1 ? '1 week ago' : sprintf('%d weeks ago', $w);
    }
}
