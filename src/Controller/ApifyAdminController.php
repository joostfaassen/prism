<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Mcp\McpHandler;
use App\Mcp\Tool\Apify\AbstractApifyActorTool;
use App\Mcp\Tool\ToolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApifyAdminController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('/admin/server/{serverName}/apify', name: 'admin_server_apify', methods: ['GET'])]
    public function hub(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        $tools = [];
        foreach ($this->mcpHandler->getTools() as $tool) {
            if ($tool->getAccountType() !== 'apify') {
                continue;
            }

            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'isActor' => $tool instanceof AbstractApifyActorTool,
                'enabled' => $serverConfig->hasAccountType('apify')
                    && $serverConfig->isToolAllowed($tool->getName(), 'apify'),
            ];
        }

        usort($tools, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $this->render('admin/apify/hub.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'apify',
            'serverHasApify' => $serverConfig->hasAccountType('apify'),
            'apifyTools' => $tools,
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
     * @return array{server: array{name: string, label: string, mcpUrl: string, accountCount: int, toolCount: int}, serverHasHabits: bool, serverHasTracking: bool}
     */
    private function nav(Request $request, string $serverName, ServerConfig $serverConfig): array
    {
        $visible = array_values(array_filter(
            $this->mcpHandler->getTools(),
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
}
