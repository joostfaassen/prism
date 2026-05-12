<?php

namespace App\Habits\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit_check_in')]
#[ORM\Index(columns: ['server_name', 'status', 'due_at'], name: 'idx_habit_checkin_server_status')]
#[ORM\Index(columns: ['xuid'], name: 'idx_habit_checkin_xuid')]
class HabitCheckInRequest
{
    public const STATUS_OPEN = 'open';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_MISSED = 'missed';

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

    #[ORM\Column(type: Types::TEXT)]
    private string $messageText;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    #[ORM\ManyToOne(targetEntity: HabitEvent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HabitEvent $fulfillmentEvent = null;

    public function __construct(
        string $serverName,
        Habit $habit,
        HabitUser $habitUser,
        string $messageText,
        \DateTimeImmutable $requestedAt,
        \DateTimeImmutable $dueAt,
    ) {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->habit = $habit;
        $this->habitUser = $habitUser;
        $this->messageText = $messageText;
        $this->requestedAt = $requestedAt;
        $this->dueAt = $dueAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getXuid(): string
    {
        return $this->xuid;
    }

    public function getHabit(): Habit
    {
        return $this->habit;
    }

    public function getHabitUser(): HabitUser
    {
        return $this->habitUser;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function markFulfilled(?HabitEvent $event = null): void
    {
        $this->status = self::STATUS_FULFILLED;
        $this->fulfilledAt = new \DateTimeImmutable();
        $this->fulfillmentEvent = $event;
    }

    public function markMissed(): void
    {
        $this->status = self::STATUS_MISSED;
        $this->fulfilledAt = new \DateTimeImmutable();
    }

    public function getFulfillmentEvent(): ?HabitEvent
    {
        return $this->fulfillmentEvent;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'habit_xuid' => $this->habit->getXuid(),
            'user_xuid' => $this->habitUser->getXuid(),
            'username' => $this->habitUser->getUsername(),
            'message' => $this->messageText,
            'status' => $this->status,
            'requested_at' => $this->requestedAt->format('c'),
            'due_at' => $this->dueAt->format('c'),
            'fulfilled_at' => $this->fulfilledAt?->format('c'),
            'fulfillment_event_xuid' => $this->fulfillmentEvent?->getXuid(),
        ];
    }
}
