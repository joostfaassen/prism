<?php

namespace App\Controller;

use App\Mcp\McpHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly McpHandler $mcpHandler,
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

        $mcpUrl = $request->getSchemeAndHttpHost() . '/mcp';

        return $this->render('admin/dashboard.html.twig', [
            'tools' => $tools,
            'mcpUrl' => $mcpUrl,
        ]);
    }
}
