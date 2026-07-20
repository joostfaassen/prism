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

    #[Route('/admin/integrations', name: 'admin_integrations', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = $this->normalizeFilter($request->query->getString('filter', 'all'));
        $data = $this->buildIntegrationsIndex($filter);

        return $this->render('admin/integrations/list.html.twig', $data + [
            'activeType' => null,
            'crumb' => null,
        ]);
    }

    #[Route('/admin/server/{serverName}/integrations', name: 'admin_server_integrations', methods: ['GET'])]
    public function serverList(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $filter = $this->normalizeFilter($request->query->getString('filter', 'all'));
        $data = $this->buildIntegrationsIndex($filter);

        // Annotate which integrations are active on this server
        $integrations = [];
        foreach ($data['integrations'] as $item) {
            $onServer = $serverConfig->getAccountsByType($item['type']);
            $item['onThisServer'] = $onServer !== [];
            $item['thisServerAccountCount'] = count($onServer);
            $integrations[] = $item;
        }
        $data['integrations'] = $integrations;

        $tools = $this->mcpHandler->getTools();
        $serverTools = array_values(array_filter(
            $tools,
            fn(ToolInterface $tool) => $tool->getAccountType() === null
                || $serverConfig->hasAccountType($tool->getAccountType()),
        ));

        return $this->render('admin/integrations/server_list.html.twig', $data + [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'mcpUrl' => $request->getSchemeAndHttpHost() . '/mcp/' . $serverName,
                'accountCount' => count($serverConfig->accounts),
                'toolCount' => count($serverTools),
            ],
            'activeSection' => 'integrations',
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'serverHasTracking' => $serverConfig->hasAccountType('tracking'),
        ]);
    }

    #[Route('/admin/integrations/{type}', name: 'admin_integration_detail', methods: ['GET'])]
    public function detail(string $type): Response
    {
        $integration = $this->integrationRegistry->get($type);
        if ($integration === null) {
            throw $this->createNotFoundException(sprintf('Unknown integration type: "%s"', $type));
        }

        $tools = array_values(array_filter(
            $this->mcpHandler->getTools(),
            fn(ToolInterface $tool) => $tool->getAccountType() === $type,
        ));
        usort($tools, fn(ToolInterface $a, ToolInterface $b) => $a->getName() <=> $b->getName());

        $accountsByServer = [];
        $totalAccounts = 0;
        foreach ($this->configLoader->getServers() as $server) {
            $accounts = $server->getAccountsByType($type);
            if ($accounts === []) {
                continue;
            }

            $accountRows = [];
            foreach ($accounts as $key => $cfg) {
                $accountRows[] = [
                    'key' => $key,
                    'label' => $cfg['label'] ?? $key,
                ];
            }
            $totalAccounts += count($accountRows);

            $accountsByServer[] = [
                'name' => $server->name,
                'label' => $server->label,
                'accounts' => $accountRows,
            ];
        }

        $index = $this->buildIntegrationsIndex('all');

        return $this->render('admin/integrations/detail.html.twig', [
            'integration' => $integration,
            'tools' => $tools,
            'accountsByServer' => $accountsByServer,
            'totalAccounts' => $totalAccounts,
            'active' => $totalAccounts > 0,
            'navIntegrations' => $index['navIntegrations'],
            'filter' => 'all',
            'activeType' => $type,
            'crumb' => $integration->getLabel(),
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
     *     unregisteredTypes: list<string>,
     *     utilityTools: list<ToolInterface>,
     *     serverCount: int
     * }
     */
    private function buildIntegrationsIndex(string $filter): array
    {
        $tools = $this->mcpHandler->getTools();
        $servers = $this->configLoader->getServers();

        $integrations = [];
        foreach ($this->integrationRegistry->all() as $type => $integration) {
            $toolCount = 0;
            foreach ($tools as $tool) {
                if ($tool->getAccountType() === $type) {
                    ++$toolCount;
                }
            }

            $accountCount = 0;
            $serverCount = 0;
            $serverSummaries = [];
            foreach ($servers as $server) {
                $accounts = $server->getAccountsByType($type);
                if ($accounts === []) {
                    continue;
                }
                ++$serverCount;
                $accountCount += count($accounts);
                $serverSummaries[] = [
                    'name' => $server->name,
                    'label' => $server->label,
                    'accountCount' => count($accounts),
                ];
            }

            $integrations[] = [
                'type' => $type,
                'label' => $integration->getLabel(),
                'description' => $integration->getDescription(),
                'toolCount' => $toolCount,
                'accountCount' => $accountCount,
                'serverCount' => $serverCount,
                'active' => $accountCount > 0,
                'servers' => $serverSummaries,
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

        $registeredTypes = array_keys($this->integrationRegistry->all());
        $seenTypes = [];
        foreach ($tools as $tool) {
            $accountType = $tool->getAccountType();
            if ($accountType !== null) {
                $seenTypes[$accountType] = true;
            }
        }
        foreach ($servers as $server) {
            foreach ($server->getAccountTypes() as $type) {
                $seenTypes[$type] = true;
            }
        }
        $unregisteredTypes = array_values(array_diff(array_keys($seenTypes), $registeredTypes));
        sort($unregisteredTypes);

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
            'unregisteredTypes' => $unregisteredTypes,
            'utilityTools' => $utilityTools,
            'serverCount' => count($servers),
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
