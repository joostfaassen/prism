<?php

namespace App\Controller;

use App\Canva\CanvaConfigLoader;
use App\Canva\CanvaService;
use App\Canva\CanvaTokenStore;
use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Config\ServerContext;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CanvaAdminController extends AbstractController
{
    private const SESSION_KEY = 'canva_oauth';

    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly CanvaConfigLoader $canvaConfigLoader,
        private readonly CanvaService $canvaService,
        private readonly CanvaTokenStore $tokenStore,
        private readonly ServerContext $serverContext,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('/admin/server/{serverName}/canva', name: 'admin_server_canva', methods: ['GET'])]
    public function hub(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        $accounts = [];
        if ($serverConfig->hasAccountType('canva')) {
            $this->serverContext->setServer($serverConfig);
            try {
                foreach ($this->canvaConfigLoader->getAccounts() as $key => $account) {
                    $accounts[] = [
                        'key' => $key,
                        'label' => $account->label,
                        'hasCredentials' => $account->hasCredentials(),
                        'connected' => $account->isConnected(),
                        'tokenValid' => $account->isAccessTokenValid(),
                        'expiresAt' => $account->tokenExpiresAt,
                        'scopes' => $account->scopes,
                    ];
                }
            } finally {
                $this->serverContext->clear();
            }
        }

        return $this->render('admin/canva/hub.html.twig', [
            ...$this->nav($request, $serverName, $serverConfig),
            'activeSection' => 'canva',
            'serverHasCanva' => $serverConfig->hasAccountType('canva'),
            'canvaAccounts' => $accounts,
            'redirectUri' => $this->callbackUrl(),
            'configFile' => basename($this->tokenStore->getTargetFile($serverName)),
        ]);
    }

    #[Route('/admin/server/{serverName}/canva/connect/{accountKey}', name: 'admin_server_canva_connect', methods: ['POST'])]
    public function connect(Request $request, string $serverName, string $accountKey): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if (!$this->isCsrfTokenValid('canva_connect', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $account = $this->canvaConfigLoader->getAccount($accountKey);

            if (!$account->hasCredentials()) {
                $this->addFlash('error', sprintf('Account "%s" is missing client_id / client_secret in the config file.', $accountKey));

                return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
            }

            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->codeChallenge($codeVerifier);
            $state = bin2hex(random_bytes(16));
            $redirectUri = $this->callbackUrl();

            $authUrl = $this->canvaService->buildAuthorizationUrl(
                accountKey: $accountKey,
                redirectUri: $redirectUri,
                codeChallenge: $codeChallenge,
                state: $state,
                scopes: $account->scopes,
            );

            $request->getSession()->set(self::SESSION_KEY, [
                'server' => $serverName,
                'account' => $accountKey,
                'verifier' => $codeVerifier,
                'state' => $state,
                'redirect_uri' => $redirectUri,
            ]);

            return $this->redirect($authUrl);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not start Canva authorization: ' . $e->getMessage());

            return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
        } finally {
            $this->serverContext->clear();
        }
    }

    #[Route('/admin/canva/callback', name: 'admin_canva_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $pending = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if (!is_array($pending) || empty($pending['server']) || empty($pending['account'])) {
            $this->addFlash('error', 'No pending Canva authorization was found. Please start the connection again.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $serverName = (string) $pending['server'];
        $accountKey = (string) $pending['account'];

        $error = $request->query->get('error');
        if ($error !== null) {
            $description = (string) ($request->query->get('error_description') ?? $error);
            $this->addFlash('error', 'Canva authorization was declined: ' . $description);

            return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
        }

        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');

        if ($code === '' || !hash_equals((string) ($pending['state'] ?? ''), $state)) {
            $this->addFlash('error', 'Canva authorization failed: missing code or state mismatch.');

            return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
        }

        $serverConfig = $this->resolveServer($serverName);
        $this->serverContext->setServer($serverConfig);
        try {
            $result = $this->canvaService->exchangeAuthorizationCode(
                accountKey: $accountKey,
                code: $code,
                codeVerifier: (string) ($pending['verifier'] ?? ''),
                redirectUri: (string) ($pending['redirect_uri'] ?? $this->callbackUrl()),
            );

            $this->addFlash('success', sprintf(
                'Canva account "%s" connected. Tokens saved to the config file. Scopes: %s',
                $accountKey,
                $result['scope'] !== '' ? $result['scope'] : '(default)',
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Canva token exchange failed: ' . $e->getMessage());
        } finally {
            $this->serverContext->clear();
        }

        return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
    }

    #[Route('/admin/server/{serverName}/canva/disconnect/{accountKey}', name: 'admin_server_canva_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, string $serverName, string $accountKey): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if (!$this->isCsrfTokenValid('canva_disconnect', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
        }

        $this->serverContext->setServer($serverConfig);
        try {
            $this->canvaService->disconnect($accountKey);
            $this->addFlash('success', sprintf('Canva account "%s" disconnected. Tokens removed from the config file.', $accountKey));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not disconnect: ' . $e->getMessage());
        } finally {
            $this->serverContext->clear();
        }

        return $this->redirectToRoute('admin_server_canva', ['serverName' => $serverName]);
    }

    private function callbackUrl(): string
    {
        return $this->generateUrl('admin_canva_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function generateCodeVerifier(): string
    {
        // 64 random bytes → ~86 char base64url string (within the 43-128 range).
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
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
}
