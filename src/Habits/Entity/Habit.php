<?php

namespace App\Habits\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity]
#[ORM\Table(name: 'habits_habit')]
#[ORM\Index(columns: ['server_name'], name: 'idx_habit_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_habit_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'slug'])]
class Habit
{
    /** Sum units (ml, minutes) vs period_target each calendar day */
    public const GOAL_DAILY_TOTAL = 'daily_total';

    /** Sum units across ISO week vs period_target */
    public const GOAL_WEEKLY_TOTAL = 'weekly_total';

    /** Count discrete sessions in week (e.g. exercise) vs period_sessions */
    public const GOAL_WEEKLY_SESSIONS = 'weekly_sessions';

    /** Break / de-learning: log relapses; optional resistance checkpoints */
    public const GOAL_ABSTAIN = 'abstain';

    public const GOAL_MODES = [
        self::GOAL_DAILY_TOTAL,
        self::GOAL_WEEKLY_TOTAL,
        self::GOAL_WEEKLY_SESSIONS,
        self::GOAL_ABSTAIN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 32)]
    private string $goalMode = self::GOAL_DAILY_TOTAL;

    #[ORM\Column(length: 32)]
    private string $unit = 'count';

    /** Target sum for daily_total / weekly_total (e.g. 2000 ml, 150 minutes) */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $periodTarget = null;

    /** Target count of sessions per week for weekly_sessions */
    #[ORM\Column(nullable: true)]
    private ?int $periodSessions = null;

    /**
     * Methodology & UX hints for coaches/agents: implementation intentions,
     * cues, replacement behaviors, friction, identity label, stacking, etc. (YAML)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $configYaml = null;

    /** Points per unit logged on increment events (can be negative for “cost” logging) */
    #[ORM\Column]
    private int $pointsPerUnit = 0;

    /** Bonus when a period is explicitly closed as successful (habits_evaluate_period) */
    #[ORM\Column]
    private int $pointsPeriodWin = 0;

    /** Applied when period closed as missed (typically negative) */
    #[ORM\Column]
    private int $pointsPeriodMiss = 0;

    /** When a scheduled check-in expires without response */
    #[ORM\Column]
    private int $pointsMissedCheckin = 0;

    /** Applied on relapse events (abstain / de-learning; usually negative) */
    #[ORM\Column]
    private int $pointsRelapse = 0;

    /** Small reward when user acknowledges a ping */
    #[ORM\Column]
    private int $pointsCheckinAck = 0;

    #[ORM\Column]
    private bool $checkinsEnabled = false;

    #[ORM\Column]
    private int $checkinGraceHours = 18;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, HabitMember> */
    #[ORM\OneToMany(targetEntity: HabitMember::class, mappedBy: 'habit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(string $serverName, string $slug, string $title, string $goalMode = self::GOAL_DAILY_TOTAL)
    {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->slug = self::normalizeSlug($slug);
        $this->title = $title;
        $this->goalMode = in_array($goalMode, self::GOAL_MODES, true) ? $goalMode : self::GOAL_DAILY_TOTAL;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
    }

    public static function normalizeSlug(string $slug): string
    {
        $s = strtolower(trim($slug));
        $s = preg_replace('/[^a-z0-9_-]/', '-', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return $s !== '' ? substr($s, 0, 80) : 'habit';
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
        $this->slug = self::normalizeSlug($slug);
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getGoalMode(): string
    {
        return $this->goalMode;
    }

    public function setGoalMode(string $goalMode): self
    {
        $this->goalMode = in_array($goalMode, self::GOAL_MODES, true) ? $goalMode : self::GOAL_DAILY_TOTAL;
        $this->touch();

        return $this;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit !== '' ? $unit : 'count';
        $this->touch();

        return $this;
    }

    public function getPeriodTarget(): ?float
    {
        return $this->periodTarget;
    }

    public function setPeriodTarget(?float $periodTarget): self
    {
        $this->periodTarget = $periodTarget;
        $this->touch();

        return $this;
    }

    public function getPeriodSessions(): ?int
    {
        return $this->periodSessions;
    }

    public function setPeriodSessions(?int $periodSessions): self
    {
        $this->periodSessions = $periodSessions;
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

    public function getPointsPerUnit(): int
    {
        return $this->pointsPerUnit;
    }

    public function setPointsPerUnit(int $pointsPerUnit): self
    {
        $this->pointsPerUnit = $pointsPerUnit;
        $this->touch();

        return $this;
    }

    public function getPointsPeriodWin(): int
    {
        return $this->pointsPeriodWin;
    }

    public function setPointsPeriodWin(int $pointsPeriodWin): self
    {
        $this->pointsPeriodWin = $pointsPeriodWin;
        $this->touch();

        return $this;
    }

    public function getPointsPeriodMiss(): int
    {
        return $this->pointsPeriodMiss;
    }

    public function setPointsPeriodMiss(int $pointsPeriodMiss): self
    {
        $this->pointsPeriodMiss = $pointsPeriodMiss;
        $this->touch();

        return $this;
    }

    public function getPointsMissedCheckin(): int
    {
        return $this->pointsMissedCheckin;
    }

    public function setPointsMissedCheckin(int $pointsMissedCheckin): self
    {
        $this->pointsMissedCheckin = $pointsMissedCheckin;
        $this->touch();

        return $this;
    }

    public function getPointsRelapse(): int
    {
        return $this->pointsRelapse;
    }

    public function setPointsRelapse(int $pointsRelapse): self
    {
        $this->pointsRelapse = $pointsRelapse;
        $this->touch();

        return $this;
    }

    public function getPointsCheckinAck(): int
    {
        return $this->pointsCheckinAck;
    }

    public function setPointsCheckinAck(int $pointsCheckinAck): self
    {
        $this->pointsCheckinAck = $pointsCheckinAck;
        $this->touch();

        return $this;
    }

    public function isCheckinsEnabled(): bool
    {
        return $this->checkinsEnabled;
    }

    public function setCheckinsEnabled(bool $checkinsEnabled): self
    {
        $this->checkinsEnabled = $checkinsEnabled;
        $this->touch();

        return $this;
    }

    public function getCheckinGraceHours(): int
    {
        return $this->checkinGraceHours;
    }

    public function setCheckinGraceHours(int $checkinGraceHours): self
    {
        $this->checkinGraceHours = max(1, $checkinGraceHours);
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
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'goal_mode' => $this->goalMode,
            'unit' => $this->unit,
            'period_target' => $this->periodTarget,
            'period_sessions' => $this->periodSessions,
            'config_yaml' => $this->configYaml,
            'points_per_unit' => $this->pointsPerUnit,
            'points_period_win' => $this->pointsPeriodWin,
            'points_period_miss' => $this->pointsPeriodMiss,
            'points_missed_checkin' => $this->pointsMissedCheckin,
            'points_relapse' => $this->pointsRelapse,
            'points_checkin_ack' => $this->pointsCheckinAck,
            'checkins_enabled' => $this->checkinsEnabled,
            'checkin_grace_hours' => $this->checkinGraceHours,
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
