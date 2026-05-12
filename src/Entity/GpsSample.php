<?php

namespace App\Entity;

use App\Repository\GpsSampleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GpsSampleRepository::class)]
#[ORM\Table(name: 'gps_sample')]
#[ORM\Index(columns: ['recorded_at'], name: 'idx_gps_sample_recorded')]
#[ORM\Index(columns: ['device_id', 'recorded_at'], name: 'idx_gps_sample_device_time')]
class GpsSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackingDevice::class, inversedBy: 'samples')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackingDevice $device;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: Types::FLOAT)]
    private float $latitude;

    #[ORM\Column(type: Types::FLOAT)]
    private float $longitude;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $accuracyMeters = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawJson = null;

    public function __construct(
        TrackingDevice $device,
        \DateTimeImmutable $recordedAt,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters = null,
        ?string $rawJson = null,
    ) {
        $this->device = $device;
        $this->recordedAt = $recordedAt;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->accuracyMeters = $accuracyMeters;
        $this->rawJson = $rawJson;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): TrackingDevice
    {
        return $this->device;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getAccuracyMeters(): ?float
    {
        return $this->accuracyMeters;
    }

    public function getRawJson(): ?string
    {
        return $this->rawJson;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recorded_at' => $this->recordedAt->format('c'),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_meters' => $this->accuracyMeters,
        ];
    }
}
