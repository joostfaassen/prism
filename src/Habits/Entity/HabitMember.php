<?php

namespace App\Habits\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit_member')]
#[ORM\UniqueConstraint(columns: ['habit_id', 'habit_user_id'])]
class HabitMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Habit::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Habit $habit;

    #[ORM\ManyToOne(targetEntity: HabitUser::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private HabitUser $habitUser;

    /** Per-user overrides: cue strength, preferred reminder time, etc. (YAML) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $configYaml = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    public function __construct(Habit $habit, HabitUser $habitUser)
    {
        $this->habit = $habit;
        $this->habitUser = $habitUser;
        $this->joinedAt = new \DateTimeImmutable();
        if (!$habit->getMembers()->contains($this)) {
            $habit->getMembers()->add($this);
        }
        if (!$habitUser->getMemberships()->contains($this)) {
            $habitUser->getMemberships()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHabit(): Habit
    {
        return $this->habit;
    }

    public function getHabitUser(): HabitUser
    {
        return $this->habitUser;
    }

    public function getConfigYaml(): ?string
    {
        return $this->configYaml;
    }

    public function setConfigYaml(?string $configYaml): self
    {
        $this->configYaml = $configYaml;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'habit_xuid' => $this->habit->getXuid(),
            'user_xuid' => $this->habitUser->getXuid(),
            'username' => $this->habitUser->getUsername(),
            'display_name' => $this->habitUser->getDisplayName(),
            'config_yaml' => $this->configYaml,
            'joined_at' => $this->joinedAt->format('c'),
        ];
    }
}
