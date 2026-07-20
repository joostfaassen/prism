<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Integrations\IntegrationRegistry;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IntegrationsController extends AbstractController
{
    public function __construct(
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly McpHandler $mcpHandler,
        private readonly PrismConfigLoader $configLoader,
    ) {
    }

    /** @deprecated Use server-scoped integrations; kept as redirect for bookmarks */
    #[Route('/admin/integrations', name: 'admin_integrations', methods: ['GET'])]
    public function legacyList(): Response
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    /** @deprecated Use server-scoped integration detail; kept as redirect for bookmarks */
    #[Route('/admin/integrations/{type}', name: 'admin_integration_detail', methods: ['GET'])]
    public function legacyDetail(string $type): Response
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/server/{serverName}/integrations', name: 'admin_server_integrations', methods: ['GET'])]
    public function list(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $filter = $this->normalizeFilter($request->query->getString('filter', 'all'));
        $data = $this->buildServerIntegrationsIndex($serverConfig, $filter);

        return $this->render('admin/integrations/list.html.twig', $data + $this->serverShell($request, $serverName, $serverConfig) + [
            'activeSection' => 'integrations',
            'activeType' => null,
        ]);
    }

    #[Route('/admin/server/{serverName}/integrations/{type}', name: 'admin_server_integration_detail', methods: ['GET'])]
    public function detail(Request $request, string $serverName, string $type): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $integration = $this->integrationRegistry->get($type);
        if ($integration === null) {
            throw $this->createNotFoundException(sprintf('Unknown integration type: "%s"', $type));
        }

        $tools = array_values(array_filter(
            $this->mcpHandler->getTools(),
            fn(ToolInterface $tool) => $tool->getAccountType() === $type,
        ));
        usort($tools, fn(ToolInterface $a, ToolInterface $b) => $a->getName() <=> $b->getName());

        $accounts = [];
        foreach ($serverConfig->getAccountsByType($type) as $key => $cfg) {
            $accounts[] = [
                'key' => $key,
                'label' => $cfg['label'] ?? $key,
            ];
        }

        $index = $this->buildServerIntegrationsIndex($serverConfig, 'all');

        return $this->render('admin/integrations/detail.html.twig', $index + $this->serverShell($request, $serverName, $serverConfig) + [
            'activeSection' => 'integrations',
            'activeType' => $type,
            'integration' => $integration,
            'tools' => $tools,
            'accounts' => $accounts,
            'active' => $accounts !== [],
        ]);
    }

    private function normalizeFilter(string $filter): string
    {
        return in_array($filter, ['all', 'active', 'unused'], true) ? $filter : 'all';
    }

    /**
     * @return array{
     *     integrations: list<array<string, mixed>>,
     *     navIntegrations: list<array<string, mixed>>,
     *     filter: string,
     *     totalCount: int,
     *     activeCount: int,
     *     unusedCount: int,
     *     utilityTools: list<ToolInterface>
     * }
     */
    private function buildServerIntegrationsIndex(ServerConfig $server, string $filter): array
    {
        $tools = $this->mcpHandler->getTools();

        $integrations = [];
        foreach ($this->integrationRegistry->all() as $type => $integration) {
            $toolCount = 0;
            foreach ($tools as $tool) {
                if ($tool->getAccountType() === $type) {
                    ++$toolCount;
                }
            }

            $accounts = $server->getAccountsByType($type);
            $accountCount = count($accounts);
            $active = $accountCount > 0;

            $integrations[] = [
                'type' => $type,
                'label' => $integration->getLabel(),
                'description' => $integration->getDescription(),
                'toolCount' => $toolCount,
                'accountCount' => $accountCount,
                'active' => $active,
            ];
        }

        usort($integrations, static function (array $a, array $b): int {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }

            return strcasecmp($a['label'], $b['label']);
        });

        $activeCount = count(array_filter($integrations, static fn(array $i) => $i['active']));
        $unusedCount = count($integrations) - $activeCount;

        $filtered = match ($filter) {
            'active' => array_values(array_filter($integrations, static fn(array $i) => $i['active'])),
            'unused' => array_values(array_filter($integrations, static fn(array $i) => !$i['active'])),
            default => $integrations,
        };

        $utilityTools = array_values(array_filter(
            $tools,
            fn(ToolInterface $tool) => $tool->getAccountType() === null,
        ));
        usort($utilityTools, fn(ToolInterface $a, ToolInterface $b) => $a->getName() <=> $b->getName());

        return [
            'integrations' => $filtered,
            'navIntegrations' => $integrations,
            'filter' => $filter,
            'totalCount' => count($integrations),
            'activeCount' => $activeCount,
            'unusedCount' => $unusedCount,
            'utilityTools' => $utilityTools,
        ];
    }

    /**
     * @return array{server: array{name: string, label: string, mcpUrl: string, accountCount: int, toolCount: int}, serverHasHabits: bool, serverHasTracking: bool}
     */
    private function serverShell(Request $request, string $serverName, ServerConfig $serverConfig): array
    {
        $tools = $this->mcpHandler->getTools();
        $serverTools = array_values(array_filter(
            $tools,
            fn(ToolInterface $tool) => $tool->getAccountType() === null
                || $serverConfig->hasAccountType($tool->getAccountType()),
        ));

        return [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'mcpUrl' => $request->getSchemeAndHttpHost() . '/mcp/' . $serverName,
                'accountCount' => count($serverConfig->accounts),
                'toolCount' => count($serverTools),
            ],
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'serverHasTracking' => $serverConfig->hasAccountType('tracking'),
        ];
    }

    private function resolveServer(string $serverName): ServerConfig
    {
        try {
            return $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Server not found: ' . $serverName);
        }
    }
}
