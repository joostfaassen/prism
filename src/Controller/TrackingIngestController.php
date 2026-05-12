<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Tracking\TrackingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class TrackingIngestController extends AbstractController
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly PrismConfigLoader $prismConfigLoader,
    ) {
    }

    #[Route(
        path: '/api/track/{serverName}/{deviceSlug}',
        name: 'api_tracking_ingest',
        methods: ['POST'],
        requirements: ['deviceSlug' => '[a-z0-9][a-z0-9_-]{0,127}'],
    )]
    public function ingest(Request $request, string $serverName, string $deviceSlug): JsonResponse
    {
        $auth = $request->headers->get('Authorization');
        $body = $request->getContent();

        try {
            $this->prismConfigLoader->getServer($serverName);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Unknown server.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->trackingService->ingestJson($serverName, $deviceSlug, $auth, $body);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedHttpException) {
            return new JsonResponse(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Ingest failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'ok' => true,
            'accepted' => $result['accepted'],
            'errors' => $result['errors'],
        ], Response::HTTP_ACCEPTED);
    }
}
