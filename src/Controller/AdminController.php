<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Mcp\McpHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Yaml\Yaml;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly McpHandler $mcpHandler,
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the security system.');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $tools = $this->mcpHandler->getTools();
        $servers = $this->configLoader->getServers();
        $baseUrl = $request->getSchemeAndHttpHost();

        $serverData = [];
        foreach ($servers as $name => $server) {
            $typeCounts = $this->getTypeCounts($server);
            $toolCount = $this->countToolsForServer($tools, $server);

            $serverData[] = [
                'name' => $name,
                'label' => $server->label,
                'mcpUrl' => $baseUrl . '/mcp/' . $name,
                'accountCount' => count($server->accounts),
                'typeCounts' => $typeCounts,
                'toolCount' => $toolCount,
            ];
        }

        return $this->render('admin/dashboard.html.twig', [
            'servers' => $serverData,
            'totalTools' => count($tools),
        ]);
    }

    #[Route('/admin/server/{serverName}', name: 'admin_server', methods: ['GET'])]
    public function serverRedirect(string $serverName): Response
    {
        return $this->redirectToRoute('admin_server_tab', [
            'serverName' => $serverName,
            'tab' => 'configuration',
        ]);
    }

    #[Route('/admin/server/{serverName}/{tab}', name: 'admin_server_tab', methods: ['GET'], requirements: ['tab' => 'configuration|accounts|tools|audit'])]
    public function serverTab(Request $request, string $serverName, string $tab): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $tools = $this->mcpHandler->getTools();
        $baseUrl = $request->getSchemeAndHttpHost();

        $accountsByType = [];
        foreach ($serverConfig->accounts as $accountName => $accountCfg) {
            $type = $accountCfg['type'] ?? 'unknown';
            $label = $accountCfg['label'] ?? $accountName;
            $accountsByType[$type][] = [
                'name' => $accountName,
                'label' => $label,
            ];
        }

        $serverTools = $this->getToolsForServer($tools, $serverConfig);

        $toolsData = [];
        foreach ($serverTools as $tool) {
            $toolsData[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'accountType' => $tool->getAccountType(),
            ];
        }

        return $this->render('admin/server.html.twig', [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'mcpUrl' => $baseUrl . '/mcp/' . $serverName,
                'accountsByType' => $accountsByType,
                'tools' => $toolsData,
                'toolCount' => count($toolsData),
                'accountCount' => count($serverConfig->accounts),
            ],
            'activeTab' => $tab,
        ]);
    }

    #[Route('/admin/server/{serverName}/tool/{toolName}', name: 'admin_tool_detail', methods: ['GET'])]
    public function toolDetail(Request $request, string $serverName, string $toolName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $tool = $this->mcpHandler->getTool($toolName);

        if ($tool === null) {
            throw $this->createNotFoundException('Tool not found: ' . $toolName);
        }

        if ($tool->getAccountType() !== null && !$serverConfig->hasAccountType($tool->getAccountType())) {
            throw $this->createNotFoundException('Tool not available on this server: ' . $toolName);
        }

        $schema = $tool->getInputSchema();

        return $this->render('admin/tool.html.twig', [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'mcpUrl' => $request->getSchemeAndHttpHost() . '/mcp/' . $serverName,
            ],
            'tool' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'accountType' => $tool->getAccountType(),
                'inputSchema' => $schema,
                'inputSchemaYaml' => Yaml::dump($schema, 10, 2),
                'sampleArgs' => $this->buildSampleArgs($schema),
            ],
        ]);
    }

    #[Route('/admin/server/{serverName}/tool/{toolName}/execute', name: 'admin_tool_execute', methods: ['POST'])]
    public function executeTool(Request $request, string $serverName, string $toolName): JsonResponse
    {
        $serverConfig = $this->resolveServer($serverName);
        $this->serverContext->setServer($serverConfig);

        $yamlInput = $request->getContent();

        try {
            $arguments = $yamlInput !== '' ? (Yaml::parse($yamlInput) ?? []) : [];
        } catch (\Throwable $e) {
            return new JsonResponse([
                'yaml' => Yaml::dump(['error' => 'Invalid YAML: ' . $e->getMessage()], 10, 2),
                'isError' => true,
            ]);
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }

        $result = $this->mcpHandler->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ]);

        $payload = $result['result'] ?? $result['error'] ?? $result;

        return new JsonResponse([
            'yaml' => Yaml::dump($payload, 10, 2),
            'isError' => !empty($payload['isError']),
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

    /**
     * @return list<\App\Mcp\Tool\ToolInterface>
     */
    private function getToolsForServer(array $tools, ServerConfig $server): array
    {
        return array_values(array_filter(
            $tools,
            fn($tool) => $tool->getAccountType() === null
                || $server->hasAccountType($tool->getAccountType()),
        ));
    }

    private function buildSampleArgs(array $schema): string
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        if (empty($properties)) {
            return "# No parameters\n{}";
        }

        $sample = [];
        foreach ($properties as $name => $prop) {
            $isRequired = in_array($name, $required, true);
            $type = $prop['type'] ?? 'string';
            $description = $prop['description'] ?? '';
            $placeholder = match ($type) {
                'integer', 'number' => '0',
                'boolean' => 'true',
                'array' => '[]',
                'object' => '{}',
                default => '"..."',
            };

            $commentParts = [];
            if ($isRequired) {
                $commentParts[] = 'required';
            }
            if ($type !== 'string') {
                $commentParts[] = $type;
            }
            if ($description !== '') {
                $commentParts[] = $description;
            }

            $comment = $commentParts !== [] ? '  # ' . implode(' — ', $commentParts) : '';
            $sample[] = $name . ': ' . $placeholder . $comment;
        }

        return implode("\n", $sample) . "\n";
    }

    /**
     * @return array<string, int>
     */
    private function getTypeCounts(ServerConfig $server): array
    {
        $typeCounts = [];
        foreach ($server->accounts as $account) {
            $type = $account['type'] ?? 'unknown';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        return $typeCounts;
    }

    /**
     * @param list<\App\Mcp\Tool\ToolInterface> $tools
     */
    private function countToolsForServer(array $tools, ServerConfig $server): int
    {
        return count(array_filter(
            $tools,
            fn($tool) => $tool->getAccountType() === null
                || $server->hasAccountType($tool->getAccountType()),
        ));
    }
}
