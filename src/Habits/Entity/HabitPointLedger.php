<?php

namespace App\Habits\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit_point_ledger')]
#[ORM\Index(columns: ['server_name', 'created_at'], name: 'idx_habit_points_server_time')]
#[ORM\Index(columns: ['server_name', 'habit_id', 'created_at'], name: 'idx_habit_points_habit_time')]
class HabitPointLedger
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\ManyToOne(targetEntity: HabitUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HabitUser $habitUser;

    #[ORM\ManyToOne(targetEntity: Habit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Habit $habit = null;

    #[ORM\ManyToOne(targetEntity: HabitEvent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HabitEvent $sourceEvent = null;

    #[ORM\Column]
    private int $delta = 0;

    #[ORM\Column(length: 64)]
    private string $reasonCode;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $serverName,
        HabitUser $habitUser,
        int $delta,
        string $reasonCode,
        ?Habit $habit = null,
        ?HabitEvent $sourceEvent = null,
    ) {
        $this->serverName = $serverName;
        $this->habitUser = $habitUser;
        $this->delta = $delta;
        $this->reasonCode = $reasonCode;
        $this->habit = $habit;
        $this->sourceEvent = $sourceEvent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHabitUser(): HabitUser
    {
        return $this->habitUser;
    }

    public function getHabit(): ?Habit
    {
        return $this->habit;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
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
            'user_xuid' => $this->habitUser->getXuid(),
            'habit_xuid' => $this->habit?->getXuid(),
            'event_xuid' => $this->sourceEvent?->getXuid(),
            'delta' => $this->delta,
            'reason_code' => $this->reasonCode,
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}
