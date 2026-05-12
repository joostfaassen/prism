<?php

namespace App\Entity;

use App\Repository\ZoneEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: ZoneEventRepository::class)]
#[ORM\Table(name: 'zone_event')]
#[ORM\Index(columns: ['server_name', 'occurred_at'], name: 'idx_zone_event_server_time')]
#[ORM\Index(columns: ['zone_id', 'occurred_at'], name: 'idx_zone_event_zone_time')]
#[ORM\Index(columns: ['device_id', 'occurred_at'], name: 'idx_zone_event_device_time')]
#[ORM\Index(columns: ['xuid'], name: 'idx_zone_event_xuid')]
class ZoneEvent
{
    public const TYPE_ENTER = 'enter';

    public const TYPE_EXIT = 'exit';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\ManyToOne(targetEntity: TrackingZone::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackingZone $zone;

    #[ORM\ManyToOne(targetEntity: TrackingDevice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackingDevice $device;

    #[ORM\Column(length: 16)]
    private string $eventType;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        string $serverName,
        TrackingZone $zone,
        TrackingDevice $device,
        string $eventType,
        \DateTimeImmutable $occurredAt,
    ) {
        if (!in_array($eventType, [self::TYPE_ENTER, self::TYPE_EXIT], true)) {
            throw new \InvalidArgumentException('Invalid zone event type: ' . $eventType);
        }

        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->zone = $zone;
        $this->device = $device;
        $this->eventType = $eventType;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getXuid(): string
    {
        return $this->xuid;
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getZone(): TrackingZone
    {
        return $this->zone;
    }

    public function getDevice(): TrackingDevice
    {
        return $this->device;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'zone_xuid' => $this->zone->getXuid(),
            'zone_name' => $this->zone->getName(),
            'device_xuid' => $this->device->getXuid(),
            'device_label' => $this->device->getLabel(),
            'event_type' => $this->eventType,
            'occurred_at' => $this->occurredAt->format('c'),
        ];
    }
}
