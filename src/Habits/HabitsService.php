<?php

namespace App\Habits;

use App\AgentNotify\AgentNotifyPayload;
use App\AgentNotify\AgentNotifyService;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Habits\Entity\Habit;
use App\Habits\Entity\HabitCheckInRequest;
use App\Habits\Entity\HabitEvent;
use App\Habits\Entity\HabitMember;
use App\Habits\Entity\HabitPointLedger;
use App\Habits\Entity\HabitUser;
use Doctrine\ORM\EntityManagerInterface;

class HabitsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerContext $serverContext,
        private readonly HabitsConfigLoader $configLoader,
        private readonly AgentNotifyService $agentNotifyService,
    ) {
    }

    public static function restTokenMatchesServer(ServerConfig $server, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        foreach ($server->getAccountsByType('habits') as $cfg) {
            $t = $cfg['rest_ingest_token'] ?? $cfg['rest_token'] ?? null;
            if (is_string($t) && $t !== '' && hash_equals($t, $token)) {
                return true;
            }
        }

        return false;
    }

    // ─── Users ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function listUsers(bool $includeInactive = false): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(HabitUser::class, 'u')
            ->where('u.serverName = :s')
            ->setParameter('s', $this->serverName())
            ->orderBy('u.username', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('u.active = true');
        }

        return array_map(fn (HabitUser $u) => $u->toPublicArray(), $qb->getQuery()->getResult());
    }

    public function createUser(string $username, string $displayName, ?string $configYaml = null): array
    {
        $u = new HabitUser($this->serverName(), $username, $displayName);
        if ($configYaml !== null && $configYaml !== '') {
            $u->setConfigYaml($configYaml);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u->toPublicArray();
    }

    public function upsertUserByUsername(string $username, string $displayName, ?string $configYaml = null): array
    {
        $n = HabitUser::normalizeUsername($username);
        $existing = $this->em->getRepository(HabitUser::class)->findOneBy([
            'username' => $n,
            'serverName' => $this->serverName(),
        ]);
        if ($existing instanceof HabitUser) {
            $patch = ['display_name' => $displayName];
            if ($configYaml !== null) {
                $patch['config_yaml'] = $configYaml;
            }

            return $this->updateUser($existing->getXuid(), $patch);
        }

        return $this->createUser($username, $displayName, $configYaml);
    }

    /**
     * @param array{display_name?: string, config_yaml?: string|null, active?: bool} $patch
     */
    public function updateUser(string $userXuid, array $patch): array
    {
        $u = $this->findUserByXuid($userXuid);
        if (array_key_exists('display_name', $patch) && is_string($patch['display_name'])) {
            $u->setDisplayName($patch['display_name']);
        }
        if (array_key_exists('config_yaml', $patch)) {
            $cv = $patch['config_yaml'];
            $u->setConfigYaml(is_string($cv) && $cv !== '' ? $cv : null);
        }
        if (array_key_exists('active', $patch)) {
            $u->setActive((bool) $patch['active']);
        }
        $this->em->flush();

        return $u->toPublicArray();
    }

    // ─── Habits ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function listHabits(bool $includeInactive = false): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('h')
            ->from(Habit::class, 'h')
            ->where('h.serverName = :s')
            ->setParameter('s', $this->serverName())
            ->orderBy('h.slug', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('h.active = true');
        }

        $out = [];
        foreach ($qb->getQuery()->getResult() as $h) {
            /** @var Habit $h */
            $row = $h->toPublicArray();
            $row['members'] = $this->habitMembersPublic($h);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields Keys: slug, title, description, goal_mode, unit, period_target,
     *                                      period_sessions, config_yaml, points_per_unit, points_period_win,
     *                                      points_period_miss, points_missed_checkin, points_relapse,
     *                                      points_checkin_ack, checkins_enabled, checkin_grace_hours, active
     */
    public function createHabit(string $slug, string $title, string $goalMode, array $fields = []): array
    {
        $h = new Habit($this->serverName(), $slug, $title, $goalMode);
        $this->applyHabitFields($h, $fields);
        $this->em->persist($h);
        $this->em->flush();

        $row = $h->toPublicArray();
        $row['members'] = [];

        return $row;
    }

    /**
     * @param array<string, mixed> $fields Same as createHabit
     */
    public function updateHabit(string $habitXuid, array $fields): array
    {
        $h = $this->findHabitByXuid($habitXuid);
        if (isset($fields['slug'])) {
            $h->setSlug((string) $fields['slug']);
        }
        if (isset($fields['title'])) {
            $h->setTitle((string) $fields['title']);
        }
        $this->applyHabitFields($h, $fields);
        $this->em->flush();

        $row = $h->toPublicArray();
        $row['members'] = $this->habitMembersPublic($h);

        return $row;
    }

    /**
     * @param list<string> $usernames Normalized usernames to attach; others removed
     */
    public function setHabitMembers(string $habitXuid, array $usernames): array
    {
        $habit = $this->findHabitByXuid($habitXuid);
        $want = [];
        foreach ($usernames as $u) {
            $n = HabitUser::normalizeUsername((string) $u);
            if ($n !== '') {
                $want[$n] = true;
            }
        }

        foreach ($habit->getMembers()->toArray() as $m) {
            /** @var HabitMember $m */
            if (!isset($want[$m->getHabitUser()->getUsername()])) {
                $habit->getMembers()->removeElement($m);
                $m->getHabitUser()->getMemberships()->removeElement($m);
                $this->em->remove($m);
            }
        }

        foreach (array_keys($want) as $username) {
            $user = $this->findUserByUsername($username);
            $exists = false;
            foreach ($habit->getMembers() as $m) {
                if ($m->getHabitUser()->getId() === $user->getId()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $member = new HabitMember($habit, $user);
                $this->em->persist($member);
            }
        }

        $this->em->flush();

        return [
            'habit_xuid' => $habit->getXuid(),
            'members' => $this->habitMembersPublic($habit),
        ];
    }

    // ─── Events & scoring ────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function recordEvent(
        string $habitXuid,
        string $userXuid,
        string $kind,
        float $quantity = 1.0,
        ?string $note = null,
        ?string $occurredAtIso = null,
        ?array $metadata = null,
    ): array {
        if (!in_array($kind, HabitEvent::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid event kind: ' . $kind);
        }

        $habit = $this->findHabitByXuid($habitXuid);
        $user = $this->findUserByXuid($userXuid);
        $this->assertMember($habit, $user);

        $tz = $this->configLoader->getTimezone();
        $occurred = $occurredAtIso
            ? new \DateTimeImmutable($occurredAtIso, $tz)
            : new \DateTimeImmutable('now', $tz);

        $event = new HabitEvent($this->serverName(), $habit, $user, $kind, $quantity, $occurred);
        if ($note !== null) {
            $event->setNote($note);
        }
        if ($metadata !== null) {
            $event->setMetadata($metadata);
        }

        $this->em->persist($event);
        $delta = $this->computePointsForEvent($habit, $kind, $quantity);
        if ($delta !== 0) {
            $this->em->persist(new HabitPointLedger(
                $this->serverName(),
                $user,
                $delta,
                'event:' . $kind,
                $habit,
                $event,
            ));
        }

        $this->em->flush();

        return [
            'event' => $event->toPublicArray(),
            'points_delta' => $delta,
        ];
    }

    /**
     * @return array{outcome: string, event: array<string, mixed>, points_delta: int}
     */
    public function evaluatePeriod(
        string $habitXuid,
        string $userXuid,
        string $granularity,
        string $periodAnchorDate,
    ): array {
        if (!in_array($granularity, ['day', 'week'], true)) {
            throw new \InvalidArgumentException('granularity must be day or week');
        }

        $habit = $this->findHabitByXuid($habitXuid);
        $user = $this->findUserByXuid($userXuid);
        $this->assertMember($habit, $user);
        $tz = $this->configLoader->getTimezone();

        $anchor = new \DateTimeImmutable($periodAnchorDate, $tz);
        [$start, $end, $periodKey] = $this->periodBounds($granularity, $anchor, $tz);

        if ($this->hasPeriodEvaluation($habit, $user, $periodKey)) {
            throw new \InvalidArgumentException('Period already evaluated: ' . $periodKey);
        }

        $met = $this->computePeriodOutcome($habit, $user, $start, $end);
        $kind = $met ? HabitEvent::KIND_PERIOD_WIN : HabitEvent::KIND_PERIOD_MISS;
        $delta = $met ? $habit->getPointsPeriodWin() : $habit->getPointsPeriodMiss();

        $event = new HabitEvent($this->serverName(), $habit, $user, $kind, 1.0, $end);
        $event->setMetadata([
            'period_key' => $periodKey,
            'granularity' => $granularity,
            'outcome' => $met ? 'met' : 'missed',
        ]);
        $this->em->persist($event);

        if ($delta !== 0) {
            $this->em->persist(new HabitPointLedger(
                $this->serverName(),
                $user,
                $delta,
                $kind,
                $habit,
                $event,
            ));
        }

        $this->em->flush();

        return [
            'outcome' => $met ? 'met' : 'missed',
            'period_key' => $periodKey,
            'event' => $event->toPublicArray(),
            'points_delta' => $delta,
        ];
    }

    // ─── Scoreboard ────────────────────────────────────────────────────

    /**
     * @return array{period: string, start: string, end: string, habit_xuid: string, rankings: list<array<string, mixed>>}
     */
    public function scoreboard(string $habitXuid, string $period, ?string $anchorDate = null): array
    {
        if (!in_array($period, ['day', 'week'], true)) {
            throw new \InvalidArgumentException('period must be day or week');
        }

        $habit = $this->findHabitByXuid($habitXuid);
        $tz = $this->configLoader->getTimezone();
        $anchor = $anchorDate
            ? new \DateTimeImmutable($anchorDate, $tz)
            : new \DateTimeImmutable('now', $tz);
        [$start, $end] = $this->periodBounds($period, $anchor, $tz);

        $qb = $this->em->createQueryBuilder();
        $qb->select(
            'u.xuid AS user_xuid',
            'u.username AS username',
            'u.displayName AS display_name',
            'COALESCE(SUM(l.delta), 0) AS points',
        )
            ->from(HabitPointLedger::class, 'l')
            ->join('l.habitUser', 'u')
            ->where('l.serverName = :srv')
            ->andWhere('l.habit = :habit')
            ->andWhere('l.createdAt >= :start')
            ->andWhere('l.createdAt < :end')
            ->setParameter('srv', $this->serverName())
            ->setParameter('habit', $habit)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('u.id', 'u.xuid', 'u.username', 'u.displayName')
            ->orderBy('points', 'DESC');

        $pointsRows = $qb->getQuery()->getArrayResult();

        $incQb = $this->em->createQueryBuilder();
        $incQb->select(
            'u.xuid AS user_xuid',
            'COALESCE(SUM(e.quantity), 0) AS increment_total',
            'COUNT(e.id) AS increment_count',
        )
            ->from(HabitEvent::class, 'e')
            ->join('e.habitUser', 'u')
            ->where('e.serverName = :srv')
            ->andWhere('e.habit = :habit')
            ->andWhere('e.kind = :kinc')
            ->andWhere('e.occurredAt >= :start')
            ->andWhere('e.occurredAt < :end')
            ->setParameter('srv', $this->serverName())
            ->setParameter('habit', $habit)
            ->setParameter('kinc', HabitEvent::KIND_INCREMENT)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('u.id', 'u.xuid');

        $incMap = [];
        foreach ($incQb->getQuery()->getArrayResult() as $row) {
            $incMap[(string) $row['user_xuid']] = [
                'increment_total' => (float) $row['increment_total'],
                'increment_count' => (int) $row['increment_count'],
            ];
        }

        $rank = 1;
        $rankings = [];
        foreach ($pointsRows as $row) {
            $ux = (string) $row['user_xuid'];
            $extra = $incMap[$ux] ?? ['increment_total' => 0.0, 'increment_count' => 0];
            $rankings[] = [
                'rank' => $rank++,
                'user_xuid' => $row['user_xuid'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'points' => (int) $row['points'],
                'increment_total' => $extra['increment_total'],
                'increment_events' => $extra['increment_count'],
            ];
        }

        return [
            'period' => $period,
            'start' => $start->format('c'),
            'end' => $end->format('c'),
            'habit_xuid' => $habit->getXuid(),
            'rankings' => $rankings,
        ];
    }

    // ─── Check-ins & agent channel ─────────────────────────────────────

    /**
     * @param list<string> $userXuids
     *
     * @return list<array<string, mixed>>
     */
    public function requestCheckIn(
        string $habitXuid,
        array $userXuids,
        string $message,
        ?int $graceHours = null,
        string $triggeredBy = 'mcp',
    ): array {
        $habit = $this->findHabitByXuid($habitXuid);
        if (!$habit->isCheckinsEnabled()) {
            throw new \InvalidArgumentException('Check-ins are disabled for this habit; enable checkins_enabled first.');
        }

        $serverConfig = $this->serverContext->getServer();
        $tz = $this->configLoader->getTimezone();
        $now = new \DateTimeImmutable('now', $tz);
        $hours = $graceHours ?? $habit->getCheckinGraceHours();
        $due = $now->modify('+' . max(1, $hours) . ' hours');

        $results = [];
        foreach ($userXuids as $xuid) {
            $user = $this->findUserByXuid((string) $xuid);
            $this->assertMember($habit, $user);
            $req = new HabitCheckInRequest(
                $this->serverName(),
                $habit,
                $user,
                $message,
                $now,
                $due,
            );
            $this->em->persist($req);
            $this->em->flush();

            $notify = $this->agentNotifyService->notify(
                $serverConfig,
                new AgentNotifyPayload(
                    message: $this->buildCheckInAgentMessage($habit, $user, $req, $message),
                    serverName: $this->serverName(),
                    triggeredBy: $triggeredBy,
                    context: [
                        'habits' => [
                            'type' => 'check_in_request',
                            'check_in_xuid' => $req->getXuid(),
                            'habit_xuid' => $habit->getXuid(),
                            'habit_slug' => $habit->getSlug(),
                            'habit_title' => $habit->getTitle(),
                            'user_xuid' => $user->getXuid(),
                            'username' => $user->getUsername(),
                            'display_name' => $user->getDisplayName(),
                            'due_at' => $due->format('c'),
                            'instructions' => 'Record progress via MCP habits_record_event or REST POST .../events. '
                                . 'When the participant replies, call habits_fulfill_checkin with check_in_xuid.',
                        ],
                    ],
                ),
            );

            $results[] = [
                'check_in' => $req->toPublicArray(),
                'agent_notify' => $notify->toArray(),
            ];
        }

        return $results;
    }

    /** @return list<array<string, mixed>> */
    public function listOpenCheckIns(?string $habitXuid = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(HabitCheckInRequest::class, 'c')
            ->where('c.serverName = :s')
            ->andWhere('c.status = :st')
            ->setParameter('s', $this->serverName())
            ->setParameter('st', HabitCheckInRequest::STATUS_OPEN)
            ->orderBy('c.dueAt', 'ASC');

        if ($habitXuid !== null) {
            $h = $this->findHabitByXuid($habitXuid);
            $qb->andWhere('c.habit = :h')->setParameter('h', $h);
        }

        return array_map(fn (HabitCheckInRequest $c) => $c->toPublicArray(), $qb->getQuery()->getResult());
    }

    public function fulfillCheckIn(string $checkInXuid, ?string $note = null): array
    {
        $req = $this->findCheckInByXuid($checkInXuid);
        if ($req->getStatus() !== HabitCheckInRequest::STATUS_OPEN) {
            throw new \InvalidArgumentException('Check-in is not open');
        }

        $habit = $req->getHabit();
        $user = $req->getHabitUser();
        $tz = $this->configLoader->getTimezone();
        $now = new \DateTimeImmutable('now', $tz);

        $event = new HabitEvent($this->serverName(), $habit, $user, HabitEvent::KIND_CHECKIN_ACK, 1.0, $now);
        $event->setNote($note);
        $event->setMetadata(['check_in_xuid' => $req->getXuid()]);
        $this->em->persist($event);

        $delta = $habit->getPointsCheckinAck();
        if ($delta !== 0) {
            $this->em->persist(new HabitPointLedger(
                $this->serverName(),
                $user,
                $delta,
                HabitEvent::KIND_CHECKIN_ACK,
                $habit,
                $event,
            ));
        }

        $req->markFulfilled($event);
        $this->em->flush();

        return [
            'check_in' => $req->toPublicArray(),
            'event' => $event->toPublicArray(),
            'points_delta' => $delta,
        ];
    }

    /** Expired open check-ins → missed + ledger + event (idempotent per request) */
    public function processExpiredCheckIns(): int
    {
        $now = new \DateTimeImmutable('now', $this->configLoader->getTimezone());
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(HabitCheckInRequest::class, 'c')
            ->where('c.serverName = :srv')
            ->andWhere('c.status = :st')
            ->andWhere('c.dueAt < :now')
            ->setParameter('srv', $this->serverName())
            ->setParameter('st', HabitCheckInRequest::STATUS_OPEN)
            ->setParameter('now', $now);

        $count = 0;
        /** @var list<HabitCheckInRequest> $list */
        $list = $qb->getQuery()->getResult();
        foreach ($list as $req) {
            $habit = $req->getHabit();
            $user = $req->getHabitUser();
            $event = new HabitEvent(
                $this->serverName(),
                $habit,
                $user,
                HabitEvent::KIND_CHECKIN_MISSED,
                1.0,
                $now,
            );
            $event->setMetadata(['check_in_xuid' => $req->getXuid()]);
            $this->em->persist($event);

            $delta = $habit->getPointsMissedCheckin();
            if ($delta !== 0) {
                $this->em->persist(new HabitPointLedger(
                    $this->serverName(),
                    $user,
                    $delta,
                    HabitEvent::KIND_CHECKIN_MISSED,
                    $habit,
                    $event,
                ));
            }

            $req->markMissed();
            ++$count;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    // ─── Profile / summary ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function userProfile(string $userXuid): array
    {
        $user = $this->findUserByXuid($userXuid);
        $balance = $this->pointBalanceForUser($user);

        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(HabitEvent::class, 'e')
            ->where('e.serverName = :s')
            ->andWhere('e.habitUser = :u')
            ->setParameter('s', $this->serverName())
            ->setParameter('u', $user)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        $habits = [];
        foreach ($user->getMemberships() as $m) {
            $habits[] = [
                'habit' => $m->getHabit()->toPublicArray(),
                'member_config_yaml' => $m->getConfigYaml(),
            ];
        }

        return [
            'user' => $user->toPublicArray(),
            'points_balance' => $balance,
            'habits' => $habits,
            'recent_events' => array_map(fn (HabitEvent $e) => $e->toPublicArray(), $events),
        ];
    }

    /** @return array<string, mixed> */
    public function userToPublicArray(string $userXuid): array
    {
        return $this->findUserByXuid($userXuid)->toPublicArray();
    }

    /** @return array<string, mixed> */
    public function habitToPublicArray(string $habitXuid): array
    {
        $h = $this->findHabitByXuid($habitXuid);
        $row = $h->toPublicArray();
        $row['members'] = $this->habitMembersPublic($h);

        return $row;
    }

    public function pointBalanceForUser(HabitUser $user): int
    {
        $q = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(l.delta), 0)')
            ->from(HabitPointLedger::class, 'l')
            ->where('l.serverName = :s')
            ->andWhere('l.habitUser = :u')
            ->setParameter('s', $this->serverName())
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $q;
    }

    // ─── Internals ─────────────────────────────────────────────────────

    private function serverName(): string
    {
        return $this->serverContext->getServerName();
    }

    private function findUserByXuid(string $xuid): HabitUser
    {
        $u = $this->em->getRepository(HabitUser::class)->findOneBy([
            'xuid' => $xuid,
            'serverName' => $this->serverName(),
        ]);
        if (!$u instanceof HabitUser) {
            throw new \InvalidArgumentException('Unknown habit user xuid: ' . $xuid);
        }

        return $u;
    }

    private function findUserByUsername(string $username): HabitUser
    {
        $n = HabitUser::normalizeUsername($username);
        $u = $this->em->getRepository(HabitUser::class)->findOneBy([
            'username' => $n,
            'serverName' => $this->serverName(),
        ]);
        if (!$u instanceof HabitUser) {
            throw new \InvalidArgumentException('Unknown habit username: ' . $n);
        }

        return $u;
    }

    private function findHabitByXuid(string $xuid): Habit
    {
        $h = $this->em->getRepository(Habit::class)->findOneBy([
            'xuid' => $xuid,
            'serverName' => $this->serverName(),
        ]);
        if (!$h instanceof Habit) {
            throw new \InvalidArgumentException('Unknown habit xuid: ' . $xuid);
        }

        return $h;
    }

    private function findCheckInByXuid(string $xuid): HabitCheckInRequest
    {
        $c = $this->em->getRepository(HabitCheckInRequest::class)->findOneBy([
            'xuid' => $xuid,
            'serverName' => $this->serverName(),
        ]);
        if (!$c instanceof HabitCheckInRequest) {
            throw new \InvalidArgumentException('Unknown check-in xuid: ' . $xuid);
        }

        return $c;
    }

    private function assertMember(Habit $habit, HabitUser $user): void
    {
        foreach ($habit->getMembers() as $m) {
            if ($m->getHabitUser()->getId() === $user->getId()) {
                return;
            }
        }
        throw new \InvalidArgumentException(sprintf(
            'User "%s" is not a member of habit "%s"',
            $user->getUsername(),
            $habit->getSlug(),
        ));
    }

    private function habitMembersPublic(Habit $habit): array
    {
        $out = [];
        foreach ($habit->getMembers() as $m) {
            $out[] = $m->toPublicArray();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyHabitFields(Habit $h, array $fields): void
    {
        $map = [
            'description' => fn ($v) => $h->setDescription($v !== null ? (string) $v : null),
            'goal_mode' => fn ($v) => $h->setGoalMode((string) $v),
            'unit' => fn ($v) => $h->setUnit((string) $v),
            'period_target' => fn ($v) => $h->setPeriodTarget(is_numeric($v) ? (float) $v : null),
            'period_sessions' => fn ($v) => $h->setPeriodSessions(is_int($v) || ctype_digit((string) $v) ? (int) $v : null),
            'config_yaml' => fn ($v) => $h->setConfigYaml($v !== null && $v !== '' ? (string) $v : null),
            'points_per_unit' => fn ($v) => $h->setPointsPerUnit((int) $v),
            'points_period_win' => fn ($v) => $h->setPointsPeriodWin((int) $v),
            'points_period_miss' => fn ($v) => $h->setPointsPeriodMiss((int) $v),
            'points_missed_checkin' => fn ($v) => $h->setPointsMissedCheckin((int) $v),
            'points_relapse' => fn ($v) => $h->setPointsRelapse((int) $v),
            'points_checkin_ack' => fn ($v) => $h->setPointsCheckinAck((int) $v),
            'checkins_enabled' => fn ($v) => $h->setCheckinsEnabled((bool) $v),
            'checkin_grace_hours' => fn ($v) => $h->setCheckinGraceHours((int) $v),
            'active' => fn ($v) => $h->setActive((bool) $v),
        ];
        foreach ($map as $key => $fn) {
            if (array_key_exists($key, $fields)) {
                $fn($fields[$key]);
            }
        }
    }

    private function computePointsForEvent(Habit $habit, string $kind, float $quantity): int
    {
        return match ($kind) {
            HabitEvent::KIND_INCREMENT => (int) round($quantity * $habit->getPointsPerUnit()),
            HabitEvent::KIND_RELAPSE => $habit->getPointsRelapse(),
            HabitEvent::KIND_CHECKIN_ACK => $habit->getPointsCheckinAck(),
            HabitEvent::KIND_PERIOD_WIN => $habit->getPointsPeriodWin(),
            HabitEvent::KIND_PERIOD_MISS => $habit->getPointsPeriodMiss(),
            HabitEvent::KIND_CHECKIN_MISSED => $habit->getPointsMissedCheckin(),
            default => 0,
        };
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function periodBounds(string $granularity, \DateTimeImmutable $anchor, \DateTimeZone $tz): array
    {
        if ($granularity === 'day') {
            $start = $anchor->setTime(0, 0, 0);
            $end = $start->modify('+1 day');
            $key = $start->format('Y-m-d');

            return [$start, $end, 'day:' . $key];
        }

        $dow = (int) $anchor->format('N');
        $monday = $anchor->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $end = $monday->modify('+7 days');
        $key = 'week:' . $monday->format('Y-m-d');

        return [$monday, $end, $key];
    }

    private function hasPeriodEvaluation(Habit $habit, HabitUser $user, string $periodKey): bool
    {
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(HabitEvent::class, 'e')
            ->where('e.habit = :h')
            ->andWhere('e.habitUser = :u')
            ->andWhere('e.kind IN (:kwin, :kmiss)')
            ->setParameter('h', $habit)
            ->setParameter('u', $user)
            ->setParameter('kwin', HabitEvent::KIND_PERIOD_WIN)
            ->setParameter('kmiss', HabitEvent::KIND_PERIOD_MISS)
            ->getQuery()
            ->getResult();

        foreach ($events as $e) {
            /** @var HabitEvent $e */
            $md = $e->getMetadata();
            if (is_array($md) && ($md['period_key'] ?? '') === $periodKey) {
                return true;
            }
        }

        return false;
    }

    private function computePeriodOutcome(Habit $habit, HabitUser $user, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e.kind', 'COALESCE(SUM(e.quantity), 0) AS qty', 'COUNT(e.id) AS cnt')
            ->from(HabitEvent::class, 'e')
            ->where('e.habit = :h')
            ->andWhere('e.habitUser = :u')
            ->andWhere('e.occurredAt >= :start')
            ->andWhere('e.occurredAt < :end')
            ->setParameter('h', $habit)
            ->setParameter('u', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('e.kind');

        $byKind = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $byKind[(string) $row['kind']] = [
                'qty' => (float) $row['qty'],
                'cnt' => (int) $row['cnt'],
            ];
        }

        return match ($habit->getGoalMode()) {
            Habit::GOAL_DAILY_TOTAL, Habit::GOAL_WEEKLY_TOTAL => $this->metTotal($habit, $byKind),
            Habit::GOAL_WEEKLY_SESSIONS => $this->metSessions($habit, $byKind),
            Habit::GOAL_ABSTAIN => $this->metAbstain($byKind),
            default => false,
        };
    }

    /**
     * @param array<string, array{qty: float, cnt: int}> $byKind
     */
    private function metTotal(Habit $habit, array $byKind): bool
    {
        $target = $habit->getPeriodTarget();
        if ($target === null || $target <= 0) {
            return false;
        }
        $inc = $byKind[HabitEvent::KIND_INCREMENT]['qty'] ?? 0.0;

        return $inc >= $target;
    }

    /**
     * @param array<string, array{qty: float, cnt: int}> $byKind
     */
    private function metSessions(Habit $habit, array $byKind): bool
    {
        $n = $habit->getPeriodSessions();
        if ($n === null || $n <= 0) {
            return false;
        }
        $sessions = (int) ($byKind[HabitEvent::KIND_INCREMENT]['cnt'] ?? 0);

        return $sessions >= $n;
    }

    /**
     * @param array<string, array{qty: float, cnt: int}> $byKind
     */
    private function metAbstain(array $byKind): bool
    {
        $relapses = (int) ($byKind[HabitEvent::KIND_RELAPSE]['cnt'] ?? 0);

        return $relapses === 0;
    }

    private function buildCheckInAgentMessage(Habit $habit, HabitUser $user, HabitCheckInRequest $req, string $message): string
    {
        return sprintf(
            "[Habits check-in | server=%s | habit=%s | user=@%s]\n%s\n\nCheck-in id: %s — due: %s",
            $this->serverName(),
            $habit->getTitle(),
            $user->getUsername(),
            $message,
            $req->getXuid(),
            $req->getDueAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
