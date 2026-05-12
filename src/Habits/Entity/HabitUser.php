<?php

namespace App\Habits\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit_user')]
#[ORM\Index(columns: ['server_name'], name: 'idx_habit_user_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_habit_user_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'username'])]
class HabitUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    /** URL-safe handle, unique per server */
    #[ORM\Column(length: 64)]
    private string $username;

    #[ORM\Column(length: 255)]
    private string $displayName;

    /** Participant-specific preferences, cues, identity framing (YAML) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $configYaml = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, HabitMember> */
    #[ORM\OneToMany(targetEntity: HabitMember::class, mappedBy: 'habitUser', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $serverName, string $username, string $displayName)
    {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->username = self::normalizeUsername($username);
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public static function normalizeUsername(string $username): string
    {
        $u = strtolower(trim($username));
        $u = preg_replace('/[^a-z0-9_-]/', '', $u) ?? '';

        return $u !== '' ? $u : 'user';
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = self::normalizeUsername($username);
        $this->touch();

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;
        $this->touch();

        return $this;
    }

    public function getConfigYaml(): ?string
    {
        return $this->configYaml;
    }

    public function setConfigYaml(?string $configYaml): self
    {
        $this->configYaml = $configYaml;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
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

    /** @return Collection<int, HabitMember> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'config_yaml' => $this->configYaml,
            'active' => $this->active,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
