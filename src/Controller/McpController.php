<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Mcp\McpHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class McpController extends AbstractController
{
    public function __construct(
        private readonly McpHandler $mcpHandler,
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    #[Route('/mcp/{serverName}', name: 'mcp_endpoint', methods: ['POST'])]
    public function handle(Request $request, string $serverName): Response
    {
        try {
            $server = $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Unknown server: ' . $serverName],
            ], 404);
        }

        if (!$this->serverContext->hasServer()) {
            $this->serverContext->setServer($server);
        }

        if ($this->serverContext->getServerName() !== $serverName) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Token does not match server'],
            ], 403);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        if (isset($body[0])) {
            $responses = [];
            foreach ($body as $req) {
                $result = $this->mcpHandler->handleRequest($req);
                if (!empty($result)) {
                    $responses[] = $result;
                }
            }

            return new JsonResponse($responses);
        }

        $result = $this->mcpHandler->handleRequest($body);
        if (empty($result)) {
            return new Response('', 204);
        }

        return new JsonResponse($result);
    }
}
