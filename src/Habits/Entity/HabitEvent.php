<?php

namespace App\Habits\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit_event')]
#[ORM\Index(columns: ['server_name', 'occurred_at'], name: 'idx_habit_event_server_time')]
#[ORM\Index(columns: ['server_name', 'habit_id', 'occurred_at'], name: 'idx_habit_event_habit_time')]
#[ORM\Index(columns: ['xuid'], name: 'idx_habit_event_xuid')]
class HabitEvent
{
    public const KIND_INCREMENT = 'increment';

    public const KIND_RELAPSE = 'relapse';

    public const KIND_CHECKIN_ACK = 'checkin_ack';

    public const KIND_PERIOD_WIN = 'period_win';

    public const KIND_PERIOD_MISS = 'period_miss';

    public const KIND_CHECKIN_MISSED = 'checkin_missed';

    public const KINDS = [
        self::KIND_INCREMENT,
        self::KIND_RELAPSE,
        self::KIND_CHECKIN_ACK,
        self::KIND_PERIOD_WIN,
        self::KIND_PERIOD_MISS,
        self::KIND_CHECKIN_MISSED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\ManyToOne(targetEntity: Habit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Habit $habit;

    #[ORM\ManyToOne(targetEntity: HabitUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HabitUser $habitUser;

    #[ORM\Column(length: 32)]
    private string $kind;

    #[ORM\Column(type: Types::FLOAT)]
    private float $quantity = 1.0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $serverName,
        Habit $habit,
        HabitUser $habitUser,
        string $kind,
        float $quantity,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->habit = $habit;
        $this->habitUser = $habitUser;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->occurredAt = $occurredAt;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getHabit(): Habit
    {
        return $this->habit;
    }

    public function getHabitUser(): HabitUser
    {
        return $this->habitUser;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'habit_xuid' => $this->habit->getXuid(),
            'habit_slug' => $this->habit->getSlug(),
            'user_xuid' => $this->habitUser->getXuid(),
            'username' => $this->habitUser->getUsername(),
            'kind' => $this->kind,
            'quantity' => $this->quantity,
            'note' => $this->note,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->format('c'),
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}
