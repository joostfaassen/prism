<?php

namespace App\Entity;

use App\Repository\TrackingDeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: TrackingDeviceRepository::class)]
#[ORM\Table(name: 'tracking_device')]
#[ORM\Index(columns: ['server_name'], name: 'idx_tracking_device_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_tracking_device_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'slug'])]
class TrackingDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\Column(length: 128)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 16)]
    private string $mapColor;

    #[ORM\Column(length: 64)]
    private string $ingestSecret;

    /** Preset: latlng_flat | lonlat_pair | owntracks | apple_shortcuts | custom */
    #[ORM\Column(length: 32)]
    private string $postFormatPreset;

    /** JSON: custom paths when preset is custom */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $postFormatJson = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, GpsSample> */
    #[ORM\OneToMany(targetEntity: GpsSample::class, mappedBy: 'device', cascade: ['remove'], orphanRemoval: true)]
    private Collection $samples;

    public function __construct(
        string $serverName,
        string $slug,
        string $label,
        string $ingestSecret,
        string $postFormatPreset = 'latlng_flat',
        string $mapColor = '#22d3ee',
    ) {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->slug = $slug;
        $this->label = $label;
        $this->ingestSecret = $ingestSecret;
        $this->postFormatPreset = $postFormatPreset;
        $this->mapColor = $mapColor;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->samples = new ArrayCollection();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getMapColor(): string
    {
        return $this->mapColor;
    }

    public function setMapColor(string $mapColor): self
    {
        $this->mapColor = $mapColor;
        $this->touch();

        return $this;
    }

    public function getIngestSecret(): string
    {
        return $this->ingestSecret;
    }

    public function setIngestSecret(string $ingestSecret): self
    {
        $this->ingestSecret = $ingestSecret;
        $this->touch();

        return $this;
    }

    public function getPostFormatPreset(): string
    {
        return $this->postFormatPreset;
    }

    public function setPostFormatPreset(string $postFormatPreset): self
    {
        $this->postFormatPreset = $postFormatPreset;
        $this->touch();

        return $this;
    }

    public function getPostFormatJson(): ?string
    {
        return $this->postFormatJson;
    }

    public function setPostFormatJson(?string $postFormatJson): self
    {
        $this->postFormatJson = $postFormatJson;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, GpsSample> */
    public function getSamples(): Collection
    {
        return $this->samples;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeSecret = false): array
    {
        $data = [
            'xuid' => $this->xuid,
            'slug' => $this->slug,
            'label' => $this->label,
            'map_color' => $this->mapColor,
            'post_format_preset' => $this->postFormatPreset,
            'post_format_json' => $this->postFormatJson,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
        if ($includeSecret) {
            $data['ingest_secret'] = $this->ingestSecret;
        }

        return $data;
    }

    public static function generateIngestSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
