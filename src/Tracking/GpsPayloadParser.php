<?php

namespace App\Tracking;

final class GpsPayloadParser
{
    /**
     * @param array<string, mixed> $body
     * @return list<array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}>
     */
    public function extractSamples(array $body, string $preset, ?string $postFormatJson): array
    {
        if (array_is_list($body) && $body !== [] && isset($body[0]) && is_array($body[0])) {
            $out = [];
            foreach ($body as $row) {
                if (is_array($row)) {
                    $one = $this->extractOne($row, $preset, $postFormatJson);
                    if ($one !== null) {
                        $out[] = $one;
                    }
                }
            }

            return $out;
        }

        $one = $this->extractOne($body, $preset, $postFormatJson);

        return $one !== null ? [$one] : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function extractOne(array $row, string $preset, ?string $postFormatJson): ?array
    {
        return match ($preset) {
            'latlng_flat' => $this->parseLatLngFlat($row),
            'lonlat_pair' => $this->parseLonLatPair($row),
            'owntracks' => $this->parseOwntracks($row),
            'apple_shortcuts' => $this->parseAppleShortcuts($row),
            'custom' => $this->parseCustom($row, $postFormatJson),
            default => $this->parseLatLngFlat($row),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function parseLatLngFlat(array $row): ?array
    {
        $lat = $this->firstFloat($row, ['lat', 'latitude', 'Lat', 'Latitude']);
        $lon = $this->firstFloat($row, ['lon', 'lng', 'longitude', 'Lon', 'Longitude']);
        if ($lat === null || $lon === null) {
            return null;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'at' => $this->parseTime($row),
            'accuracy' => $this->firstFloat($row, ['acc', 'accuracy', 'horizontal_accuracy', 'hAcc']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function parseLonLatPair(array $row): ?array
    {
        if (($row['type'] ?? '') === 'Point' && isset($row['coordinates']) && is_array($row['coordinates'])) {
            $c = $row['coordinates'];
            $lon = isset($c[0]) ? $this->toFloat($c[0]) : null;
            $lat = isset($c[1]) ? $this->toFloat($c[1]) : null;
            if ($lat !== null && $lon !== null) {
                return [
                    'lat' => $lat,
                    'lon' => $lon,
                    'at' => $this->parseTime($row),
                    'accuracy' => $this->firstFloat($row, ['accuracy', 'acc']),
                ];
            }
        }

        if (isset($row['coordinates']) && is_array($row['coordinates'])) {
            $c = $row['coordinates'];
            $lon = isset($c[0]) ? $this->toFloat($c[0]) : null;
            $lat = isset($c[1]) ? $this->toFloat($c[1]) : null;
            if ($lat !== null && $lon !== null) {
                return [
                    'lat' => $lat,
                    'lon' => $lon,
                    'at' => $this->parseTime($row),
                    'accuracy' => $this->firstFloat($row, ['accuracy', 'acc']),
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function parseOwntracks(array $row): ?array
    {
        if (($row['_type'] ?? '') === 'encrypted') {
            return null;
        }

        $lat = $this->toFloat($row['lat'] ?? null);
        $lon = $this->toFloat($row['lon'] ?? $row['lng'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        $at = new \DateTimeImmutable();
        if (isset($row['tst']) && is_numeric($row['tst'])) {
            $at = (new \DateTimeImmutable('@' . (int) $row['tst']))->setTimezone(new \DateTimeZone('UTC'));
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'at' => $at,
            'accuracy' => $this->toFloat($row['acc'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function parseAppleShortcuts(array $row): ?array
    {
        $lat = $this->toFloat($row['latitude'] ?? null);
        $lon = $this->toFloat($row['longitude'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'at' => $this->parseTime($row),
            'accuracy' => $this->toFloat($row['horizontalAccuracy'] ?? $row['accuracy'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lat: float, lon: float, at: \DateTimeImmutable, accuracy: ?float}|null
     */
    private function parseCustom(array $row, ?string $postFormatJson): ?array
    {
        if ($postFormatJson === null || trim($postFormatJson) === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $cfg */
            $cfg = json_decode($postFormatJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $latPath = (string) ($cfg['latitude_path'] ?? 'lat');
        $lonPath = (string) ($cfg['longitude_path'] ?? 'lon');
        $lat = $this->toFloat($this->getByPath($row, $latPath));
        $lon = $this->toFloat($this->getByPath($row, $lonPath));
        if ($lat === null || $lon === null) {
            return null;
        }

        $at = new \DateTimeImmutable();
        if (!empty($cfg['timestamp_path'])) {
            $raw = $this->getByPath($row, (string) $cfg['timestamp_path']);
            $parsed = $this->parseTimestampValue($raw);
            if ($parsed !== null) {
                $at = $parsed;
            }
        }

        $accuracy = null;
        if (!empty($cfg['accuracy_path'])) {
            $accuracy = $this->toFloat($this->getByPath($row, (string) $cfg['accuracy_path']));
        }

        return ['lat' => $lat, 'lon' => $lon, 'at' => $at, 'accuracy' => $accuracy];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function parseTime(array $row): \DateTimeImmutable
    {
        foreach (['timestamp', 'time', 'tst', 'ts', 'recorded_at', 'at', 'datetime'] as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $parsed = $this->parseTimestampValue($row[$k]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return new \DateTimeImmutable();
    }

    private function parseTimestampValue(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $n = (int) $raw;
            if ($n > 1_000_000_000_000) {
                $n = intdiv($n, 1000);
            }

            return (new \DateTimeImmutable('@' . $n))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_float($raw)) {
            $n = (int) $raw;

            return (new \DateTimeImmutable('@' . $n))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_string($raw) && $raw !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function firstFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $v = $this->toFloat($row[$k]);
                if ($v !== null) {
                    return $v;
                }
            }
        }

        return null;
    }

    private function toFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getByPath(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            if ($seg === '') {
                return null;
            }
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }
}
