<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'name' => 'prism',
            'description' => 'Prism — MCP Tool Server',
            'version' => '1.0.0',
        ]);
    }
}
