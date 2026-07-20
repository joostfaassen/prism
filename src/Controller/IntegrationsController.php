<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Integrations\IntegrationRegistry;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function list(): Response
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
            foreach ($servers as $server) {
                $accounts = $server->getAccountsByType($type);
                if ($accounts !== []) {
                    ++$serverCount;
                    $accountCount += count($accounts);
                }
            }

            $integrations[] = [
                'type' => $type,
                'label' => $integration->getLabel(),
                'description' => $integration->getDescription(),
                'toolCount' => $toolCount,
                'accountCount' => $accountCount,
                'serverCount' => $serverCount,
            ];
        }

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

        return $this->render('admin/integrations/list.html.twig', [
            'integrations' => $integrations,
            'unregisteredTypes' => $unregisteredTypes,
            'utilityTools' => $utilityTools,
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

            $accountsByServer[] = [
                'name' => $server->name,
                'label' => $server->label,
                'accounts' => $accountRows,
            ];
        }

        return $this->render('admin/integrations/detail.html.twig', [
            'integration' => $integration,
            'tools' => $tools,
            'accountsByServer' => $accountsByServer,
        ]);
    }
}
