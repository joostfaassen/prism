<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Habits\Entity\Habit;
use App\Habits\Entity\HabitEvent;
use App\Habits\HabitsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST ingest for habit events and read-only reporting. Auth: Bearer token from any
 * habits account’s rest_ingest_token on prism.config.yaml for this server.
 */
class HabitsApiController extends AbstractController
{
    public function __construct(
        private readonly HabitsService $habitsService,
        private readonly PrismConfigLoader $prismConfigLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    #[Route('/api/habits/{serverName}/v1/users', name: 'api_habits_users_list', methods: ['GET'])]
    public function listUsers(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () {
            return new JsonResponse(['users' => $this->habitsService->listUsers(true)]);
        });
    }

    #[Route('/api/habits/{serverName}/v1/users', name: 'api_habits_users_create', methods: ['POST'])]
    public function createUser(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request) {
            $data = $this->jsonBody($request);
            $username = trim((string) ($data['username'] ?? ''));
            $display = trim((string) ($data['display_name'] ?? ''));
            if ($username === '' || $display === '') {
                return new JsonResponse(['error' => 'username and display_name required'], Response::HTTP_BAD_REQUEST);
            }

            try {
                $row = $this->habitsService->createUser(
                    $username,
                    $display,
                    isset($data['config_yaml']) ? (string) $data['config_yaml'] : null,
                );
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($row, Response::HTTP_CREATED);
        });
    }

    #[Route('/api/habits/{serverName}/v1/users/{userXuid}', name: 'api_habits_users_patch', methods: ['PATCH'])]
    public function patchUser(Request $request, string $serverName, string $userXuid): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request, $userXuid) {
            $data = $this->jsonBody($request);
            try {
                $patch = array_intersect_key($data, array_flip(['display_name', 'config_yaml', 'active']));
                $row = $this->habitsService->updateUser($userXuid, $patch);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($row);
        });
    }

    #[Route('/api/habits/{serverName}/v1/habits', name: 'api_habits_habits_list', methods: ['GET'])]
    public function listHabits(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () {
            return new JsonResponse(['habits' => $this->habitsService->listHabits(true)]);
        });
    }

    #[Route('/api/habits/{serverName}/v1/habits', name: 'api_habits_habits_create', methods: ['POST'])]
    public function createHabit(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request) {
            $data = $this->jsonBody($request);
            $slug = trim((string) ($data['slug'] ?? ''));
            $title = trim((string) ($data['title'] ?? ''));
            $goalMode = trim((string) ($data['goal_mode'] ?? Habit::GOAL_DAILY_TOTAL));
            if ($slug === '' || $title === '') {
                return new JsonResponse(['error' => 'slug and title required'], Response::HTTP_BAD_REQUEST);
            }
            $fields = $this->extractHabitFields($data);
            try {
                $row = $this->habitsService->createHabit($slug, $title, $goalMode, $fields);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($row, Response::HTTP_CREATED);
        });
    }

    #[Route('/api/habits/{serverName}/v1/habits/{habitXuid}', name: 'api_habits_habits_patch', methods: ['PATCH'])]
    public function patchHabit(Request $request, string $serverName, string $habitXuid): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request, $habitXuid) {
            $data = $this->jsonBody($request);
            $fields = $this->extractHabitFields($data);
            try {
                $row = $this->habitsService->updateHabit($habitXuid, $fields);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($row);
        });
    }

    #[Route('/api/habits/{serverName}/v1/habits/{habitXuid}/members', name: 'api_habits_members_put', methods: ['PUT'])]
    public function putMembers(Request $request, string $serverName, string $habitXuid): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request, $habitXuid) {
            $data = $this->jsonBody($request);
            $usernames = $data['usernames'] ?? [];
            if (!is_array($usernames)) {
                return new JsonResponse(['error' => 'usernames must be an array'], Response::HTTP_BAD_REQUEST);
            }
            try {
                $row = $this->habitsService->setHabitMembers($habitXuid, array_map('strval', $usernames));
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($row);
        });
    }

    #[Route('/api/habits/{serverName}/v1/events', name: 'api_habits_events_post', methods: ['POST'])]
    public function postEvent(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request) {
            $data = $this->jsonBody($request);
            $habitXuid = (string) ($data['habit_xuid'] ?? '');
            $userXuid = (string) ($data['user_xuid'] ?? '');
            $kind = (string) ($data['kind'] ?? HabitEvent::KIND_INCREMENT);
            if ($habitXuid === '' || $userXuid === '') {
                return new JsonResponse(['error' => 'habit_xuid and user_xuid required'], Response::HTTP_BAD_REQUEST);
            }
            $qty = isset($data['quantity']) ? (float) $data['quantity'] : 1.0;
            $note = isset($data['note']) ? (string) $data['note'] : null;
            $occ = isset($data['occurred_at']) ? (string) $data['occurred_at'] : null;
            $meta = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null;
            try {
                $out = $this->habitsService->recordEvent($habitXuid, $userXuid, $kind, $qty, $note, $occ, $meta);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($out, Response::HTTP_CREATED);
        });
    }

    #[Route('/api/habits/{serverName}/v1/scoreboard', name: 'api_habits_scoreboard', methods: ['GET'])]
    public function scoreboard(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request) {
            $habitXuid = (string) $request->query->get('habit_xuid', '');
            $period = (string) $request->query->get('period', 'day');
            $anchor = $request->query->get('anchor_date');
            if ($habitXuid === '') {
                return new JsonResponse(['error' => 'habit_xuid query parameter required'], Response::HTTP_BAD_REQUEST);
            }
            try {
                $out = $this->habitsService->scoreboard(
                    $habitXuid,
                    $period,
                    is_string($anchor) && $anchor !== '' ? $anchor : null,
                );
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($out);
        });
    }

    #[Route('/api/habits/{serverName}/v1/users/{userXuid}/profile', name: 'api_habits_user_profile', methods: ['GET'])]
    public function userProfile(Request $request, string $serverName, string $userXuid): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($userXuid) {
            try {
                return new JsonResponse($this->habitsService->userProfile($userXuid));
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        });
    }

    #[Route('/api/habits/{serverName}/v1/check-ins', name: 'api_habits_checkins_list', methods: ['GET'])]
    public function listCheckins(Request $request, string $serverName): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request) {
            $hx = $request->query->get('habit_xuid');

            return new JsonResponse([
                'check_ins' => $this->habitsService->listOpenCheckIns(is_string($hx) && $hx !== '' ? $hx : null),
            ]);
        });
    }

    #[Route('/api/habits/{serverName}/v1/check-ins/{checkInXuid}/fulfill', name: 'api_habits_checkins_fulfill', methods: ['POST'])]
    public function fulfillCheckin(Request $request, string $serverName, string $checkInXuid): JsonResponse
    {
        return $this->withServer($request, $serverName, function () use ($request, $checkInXuid) {
            $data = $this->jsonBody($request);
            $note = isset($data['note']) ? (string) $data['note'] : null;
            try {
                return new JsonResponse($this->habitsService->fulfillCheckIn($checkInXuid, $note));
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function extractHabitFields(array $data): array
    {
        $keys = [
            'description', 'goal_mode', 'unit', 'period_target', 'period_sessions', 'config_yaml',
            'points_per_unit', 'points_period_win', 'points_period_miss', 'points_missed_checkin',
            'points_relapse', 'points_checkin_ack', 'checkins_enabled', 'checkin_grace_hours', 'active', 'slug', 'title',
        ];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        return $out;
    }

    /**
     * @param callable(): JsonResponse $fn
     */
    private function withServer(Request $request, string $serverName, callable $fn): JsonResponse
    {
        try {
            $server = $this->prismConfigLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Unknown server'], Response::HTTP_NOT_FOUND);
        }

        if (!$server->hasAccountType('habits')) {
            return new JsonResponse(['error' => 'Server has no habits account'], Response::HTTP_NOT_FOUND);
        }

        $auth = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if (!HabitsService::restTokenMatchesServer($server, $token)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $this->serverContext->setServer($server);
        try {
            return $fn();
        } finally {
            $this->serverContext->clear();
        }
    }
}
