<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Habits\Entity\Habit;
use App\Habits\HabitsService;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/server/{serverName}/habits')]
class HabitsAdminController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly HabitsService $habitsService,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('', name: 'admin_server_habits', methods: ['GET'])]
    public function hub(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $baseUrl = $request->getSchemeAndHttpHost();

        return $this->render('admin/habits/hub.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'restBaseUrl' => $baseUrl . '/api/habits/' . rawurlencode($serverName) . '/v1/',
        ]);
    }

    #[Route('/users', name: 'admin_server_habits_users', methods: ['GET'])]
    public function usersIndex(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $users = $this->habitsService->listUsers(true);
        } finally {
            $this->serverContext->clear();
        }

        return $this->render('admin/habits/user_index.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'users',
            'users' => $users,
        ]);
    }

    #[Route('/users/new', name: 'admin_server_habits_users_new', methods: ['GET', 'POST'])]
    public function userNew(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('habits_user_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');
            } else {
                $username = trim((string) $request->request->get('username', ''));
                $displayName = trim((string) $request->request->get('display_name', ''));
                $configYaml = trim((string) $request->request->get('config_yaml', ''));
                if ($username === '' || $displayName === '') {
                    $this->addFlash('error', 'Username and display name are required.');
                } else {
                    $this->serverContext->setServer($serverConfig);
                    try {
                        $this->habitsService->createUser($username, $displayName, $configYaml !== '' ? $configYaml : null);
                    } finally {
                        $this->serverContext->clear();
                    }
                    $this->addFlash('success', 'User created.');

                    return $this->redirectToRoute('admin_server_habits_users', ['serverName' => $serverName]);
                }
            }
        }

        return $this->render('admin/habits/user_form.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'users',
            'isNew' => true,
            'user' => null,
            'form' => [
                'username' => (string) $request->request->get('username', ''),
                'display_name' => (string) $request->request->get('display_name', ''),
                'config_yaml' => (string) $request->request->get('config_yaml', ''),
                'active' => true,
            ],
        ]);
    }

    #[Route('/users/{userXuid}/edit', name: 'admin_server_habits_users_edit', methods: ['GET', 'POST'], requirements: ['userXuid' => '[0-9a-zA-Z_-]{10,24}'])]
    public function userEdit(Request $request, string $serverName, string $userXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('habits_user_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');
            } else {
                $patch = [
                    'display_name' => (string) $request->request->get('display_name', ''),
                    'config_yaml' => (string) $request->request->get('config_yaml', ''),
                    'active' => $request->request->get('active') === '1',
                ];
                $this->serverContext->setServer($serverConfig);
                try {
                    $this->habitsService->updateUser($userXuid, $patch);
                } catch (\Throwable $e) {
                    $this->serverContext->clear();
                    $this->addFlash('error', $e->getMessage());
                    $form = $this->mergeUserFormOnError($request, $userXuid, $serverConfig);

                    return $this->render('admin/habits/user_form.html.twig', [
                        ...$this->nav($request, $serverName, $serverConfig),
                        'activeSection' => 'habits',
                        'habitsSubnav' => 'users',
                        'isNew' => false,
                        'user' => ['xuid' => $userXuid] + $form,
                        'form' => $form,
                    ]);
                }
                $this->serverContext->clear();
                $this->addFlash('success', 'User saved.');

                return $this->redirectToRoute('admin_server_habits_users', ['serverName' => $serverName]);
            }
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $user = $this->habitsService->userToPublicArray($userXuid);
        } finally {
            $this->serverContext->clear();
        }

        return $this->render('admin/habits/user_form.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'users',
            'isNew' => false,
            'user' => $user,
            'form' => [
                'username' => $user['username'],
                'display_name' => $user['display_name'],
                'config_yaml' => (string) ($user['config_yaml'] ?? ''),
                'active' => (bool) ($user['active'] ?? true),
            ],
        ]);
    }

    #[Route('/goals', name: 'admin_server_habits_goals', methods: ['GET'])]
    public function goalsIndex(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $habits = $this->habitsService->listHabits(true);
        } finally {
            $this->serverContext->clear();
        }

        return $this->render('admin/habits/habit_index.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'goals',
            'habits' => $habits,
        ]);
    }

    #[Route('/goals/new', name: 'admin_server_habits_goals_new', methods: ['GET', 'POST'])]
    public function goalNew(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('habits_habit_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');
            } else {
                $slug = trim((string) $request->request->get('slug', ''));
                $title = trim((string) $request->request->get('title', ''));
                $goalMode = trim((string) $request->request->get('goal_mode', Habit::GOAL_DAILY_TOTAL));
                if ($slug === '' || $title === '') {
                    $this->addFlash('error', 'Slug and title are required.');
                } else {
                    $fields = $this->readHabitFieldsFromRequest($request);
                    $this->serverContext->setServer($serverConfig);
                    try {
                        $created = $this->habitsService->createHabit($slug, $title, $goalMode, $fields);
                        $members = $this->parseUsernamesList((string) $request->request->get('members_usernames', ''));
                        if ($members !== []) {
                            $this->habitsService->setHabitMembers($created['xuid'], $members);
                        }
                    } catch (\Throwable $e) {
                        $this->serverContext->clear();
                        $this->addFlash('error', $e->getMessage());

                        return $this->render('admin/habits/habit_form.html.twig', [
                            ...$this->nav($request, $serverName, $serverConfig),
                            'activeSection' => 'habits',
                            'habitsSubnav' => 'goals',
                            'isNew' => true,
                            'habit' => null,
                            'goalModes' => Habit::GOAL_MODES,
                            'form' => $this->readHabitFormForTemplate($request),
                        ]);
                    }
                    $this->serverContext->clear();
                    $this->addFlash('success', 'Habit created.');

                    return $this->redirectToRoute('admin_server_habits_goals', ['serverName' => $serverName]);
                }
            }
        }

        return $this->render('admin/habits/habit_form.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'goals',
            'isNew' => true,
            'habit' => null,
            'goalModes' => Habit::GOAL_MODES,
            'form' => $this->defaultHabitForm($request),
        ]);
    }

    #[Route('/goals/{habitXuid}/edit', name: 'admin_server_habits_goals_edit', methods: ['GET', 'POST'], requirements: ['habitXuid' => '[0-9a-zA-Z_-]{10,24}'])]
    public function goalEdit(Request $request, string $serverName, string $habitXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        if (!$this->ensureHabits($serverConfig)) {
            return $this->redirectNoHabits($serverName);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('habits_habit_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token.');
            } else {
                $fields = $this->readHabitFieldsFromRequest($request);
                $fields['slug'] = trim((string) $request->request->get('slug', ''));
                $fields['title'] = trim((string) $request->request->get('title', ''));
                $fields['goal_mode'] = trim((string) $request->request->get('goal_mode', Habit::GOAL_DAILY_TOTAL));
                $this->serverContext->setServer($serverConfig);
                try {
                    $this->habitsService->updateHabit($habitXuid, $fields);
                    $members = $this->parseUsernamesList((string) $request->request->get('members_usernames', ''));
                    $this->habitsService->setHabitMembers($habitXuid, $members);
                } catch (\Throwable $e) {
                    $this->serverContext->clear();
                    $this->addFlash('error', $e->getMessage());

                    return $this->render('admin/habits/habit_form.html.twig', [
                        ...$this->nav($request, $serverName, $serverConfig),
                        'activeSection' => 'habits',
                        'habitsSubnav' => 'goals',
                        'isNew' => false,
                        'habit' => ['xuid' => $habitXuid],
                        'goalModes' => Habit::GOAL_MODES,
                        'form' => $this->readHabitFormForTemplate($request),
                    ]);
                }
                $this->serverContext->clear();
                $this->addFlash('success', 'Habit saved.');

                return $this->redirectToRoute('admin_server_habits_goals', ['serverName' => $serverName]);
            }
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $habit = $this->habitsService->habitToPublicArray($habitXuid);
        } finally {
            $this->serverContext->clear();
        }

        $membersLines = array_map(
            static fn (array $m) => $m['username'] ?? '',
            $habit['members'] ?? [],
        );

        return $this->render('admin/habits/habit_form.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'habits',
            'habitsSubnav' => 'goals',
            'isNew' => false,
            'habit' => $habit,
            'goalModes' => Habit::GOAL_MODES,
            'form' => $this->habitToFormRow($habit, implode("\n", $membersLines)),
        ]);
    }

    private function resolveServer(string $serverName): ServerConfig
    {
        try {
            return $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Server not found: ' . $serverName);
        }
    }

    private function ensureHabits(ServerConfig $serverConfig): bool
    {
        return $serverConfig->hasAccountType('habits');
    }

    private function redirectNoHabits(string $serverName): Response
    {
        $this->addFlash('error', 'Add a habits-type account to this server in prism.config.yaml first.');

        return $this->redirectToRoute('admin_server_tab', ['serverName' => $serverName, 'tab' => 'accounts']);
    }

    /**
     * @return array{server: array{name: string, label: string, accountCount: int, toolCount: int, mcpUrl: string}, serverHasHabits: bool, serverHasTracking: bool}
     */
    private function nav(Request $request, string $serverName, ServerConfig $serverConfig): array
    {
        $tools = $this->mcpHandler->getTools();
        $visible = array_values(array_filter(
            $tools,
            static fn (ToolInterface $tool) => $tool->getAccountType() === null
                || $serverConfig->hasAccountType($tool->getAccountType()),
        ));
        $baseUrl = $request->getSchemeAndHttpHost();

        return [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'mcpUrl' => $baseUrl . '/mcp/' . $serverName,
                'accountCount' => count($serverConfig->accounts),
                'toolCount' => count($visible),
            ],
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'serverHasTracking' => $serverConfig->hasAccountType('tracking'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readHabitFieldsFromRequest(Request $request): array
    {
        $pt = $request->request->get('period_target');
        $ps = $request->request->get('period_sessions');

        return [
            'description' => trim((string) $request->request->get('description', '')) ?: null,
            'unit' => trim((string) $request->request->get('unit', 'count')) ?: 'count',
            'period_target' => $pt !== null && $pt !== '' ? (float) $pt : null,
            'period_sessions' => $ps !== null && $ps !== '' ? (int) $ps : null,
            'config_yaml' => trim((string) $request->request->get('config_yaml', '')) ?: null,
            'points_per_unit' => (int) $request->request->get('points_per_unit', 0),
            'points_period_win' => (int) $request->request->get('points_period_win', 0),
            'points_period_miss' => (int) $request->request->get('points_period_miss', 0),
            'points_missed_checkin' => (int) $request->request->get('points_missed_checkin', 0),
            'points_relapse' => (int) $request->request->get('points_relapse', 0),
            'points_checkin_ack' => (int) $request->request->get('points_checkin_ack', 0),
            'checkins_enabled' => $request->request->get('checkins_enabled') === '1',
            'checkin_grace_hours' => max(1, (int) $request->request->get('checkin_grace_hours', 18)),
            'active' => $request->request->get('active') === '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultHabitForm(Request $request): array
    {
        if ($request->isMethod('POST')) {
            return $this->readHabitFormForTemplate($request);
        }

        return [
            'slug' => '',
            'title' => '',
            'goal_mode' => Habit::GOAL_DAILY_TOTAL,
            'description' => '',
            'unit' => 'ml',
            'period_target' => '',
            'period_sessions' => '',
            'config_yaml' => '',
            'points_per_unit' => 1,
            'points_period_win' => 10,
            'points_period_miss' => -5,
            'points_missed_checkin' => -8,
            'points_relapse' => -15,
            'points_checkin_ack' => 2,
            'checkins_enabled' => false,
            'checkin_grace_hours' => 18,
            'active' => true,
            'members_usernames' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readHabitFormForTemplate(Request $request): array
    {
        $f = $this->readHabitFieldsFromRequest($request);

        return [
            'slug' => trim((string) $request->request->get('slug', '')),
            'title' => trim((string) $request->request->get('title', '')),
            'goal_mode' => trim((string) $request->request->get('goal_mode', Habit::GOAL_DAILY_TOTAL)),
            'description' => (string) ($f['description'] ?? ''),
            'unit' => (string) ($f['unit'] ?? 'count'),
            'period_target' => $request->request->get('period_target'),
            'period_sessions' => $request->request->get('period_sessions'),
            'config_yaml' => (string) $request->request->get('config_yaml', ''),
            'points_per_unit' => $f['points_per_unit'],
            'points_period_win' => $f['points_period_win'],
            'points_period_miss' => $f['points_period_miss'],
            'points_missed_checkin' => $f['points_missed_checkin'],
            'points_relapse' => $f['points_relapse'],
            'points_checkin_ack' => $f['points_checkin_ack'],
            'checkins_enabled' => $f['checkins_enabled'],
            'checkin_grace_hours' => $f['checkin_grace_hours'],
            'active' => $f['active'],
            'members_usernames' => (string) $request->request->get('members_usernames', ''),
        ];
    }

    /**
     * @param array<string, mixed> $habit
     *
     * @return array<string, mixed>
     */
    private function habitToFormRow(array $habit, string $membersText): array
    {
        return [
            'slug' => (string) ($habit['slug'] ?? ''),
            'title' => (string) ($habit['title'] ?? ''),
            'goal_mode' => (string) ($habit['goal_mode'] ?? Habit::GOAL_DAILY_TOTAL),
            'description' => (string) ($habit['description'] ?? ''),
            'unit' => (string) ($habit['unit'] ?? 'count'),
            'period_target' => $habit['period_target'] ?? '',
            'period_sessions' => $habit['period_sessions'] ?? '',
            'config_yaml' => (string) ($habit['config_yaml'] ?? ''),
            'points_per_unit' => (int) ($habit['points_per_unit'] ?? 0),
            'points_period_win' => (int) ($habit['points_period_win'] ?? 0),
            'points_period_miss' => (int) ($habit['points_period_miss'] ?? 0),
            'points_missed_checkin' => (int) ($habit['points_missed_checkin'] ?? 0),
            'points_relapse' => (int) ($habit['points_relapse'] ?? 0),
            'points_checkin_ack' => (int) ($habit['points_checkin_ack'] ?? 0),
            'checkins_enabled' => (bool) ($habit['checkins_enabled'] ?? false),
            'checkin_grace_hours' => (int) ($habit['checkin_grace_hours'] ?? 18),
            'active' => (bool) ($habit['active'] ?? true),
            'members_usernames' => $membersText,
        ];
    }

    /** @return list<string> */
    private function parseUsernamesList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeUserFormOnError(Request $request, string $userXuid, ServerConfig $serverConfig): array
    {
        $this->serverContext->setServer($serverConfig);
        try {
            $existing = $this->habitsService->userToPublicArray($userXuid);
        } catch (\Throwable) {
            $existing = ['xuid' => $userXuid, 'username' => '', 'display_name' => '', 'config_yaml' => '', 'active' => true];
        } finally {
            $this->serverContext->clear();
        }

        return [
            'username' => $existing['username'],
            'display_name' => (string) $request->request->get('display_name', $existing['display_name']),
            'config_yaml' => (string) $request->request->get('config_yaml', (string) ($existing['config_yaml'] ?? '')),
            'active' => $request->request->get('active') === '1',
        ];
    }
}
