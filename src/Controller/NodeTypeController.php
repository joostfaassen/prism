<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Entity\NodeType;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

class NodeTypeController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly NodeTypeRepository $nodeTypeRepository,
        private readonly EntityManagerInterface $em,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('/admin/server/{serverName}/node-types', name: 'admin_server_node_types', methods: ['GET'])]
    public function list(string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $types = $this->nodeTypeRepository->findByServer($serverName);

        return $this->render('admin/node_type/list.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'types' => $types,
            'activeSection' => 'node_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/node-types/new', name: 'admin_node_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, null);
        }

        return $this->render('admin/node_type/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'nodeType' => null,
            'errors' => [],
            'activeSection' => 'node_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/node-types/{xuid}/edit', name: 'admin_node_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $nodeType = $this->nodeTypeRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($nodeType === null) {
            throw $this->createNotFoundException('Node type not found');
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, $nodeType);
        }

        return $this->render('admin/node_type/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'nodeType' => $nodeType,
            'errors' => [],
            'activeSection' => 'node_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/node-types/{xuid}/delete', name: 'admin_node_type_delete', methods: ['POST'])]
    public function delete(string $serverName, string $xuid): Response
    {
        $nodeType = $this->nodeTypeRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($nodeType === null) {
            throw $this->createNotFoundException('Node type not found');
        }

        if ($nodeType->getNodes()->count() > 0) {
            $this->addFlash('error', 'Cannot delete a node type that still has nodes. Remove all nodes of this type first.');
            return $this->redirectToRoute('admin_node_type_edit', ['serverName' => $serverName, 'xuid' => $xuid]);
        }

        $this->em->remove($nodeType);
        $this->em->flush();

        return $this->redirectToRoute('admin_server_node_types', ['serverName' => $serverName]);
    }

    private function handleForm(Request $request, string $serverName, ?NodeType $nodeType): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $name = trim($request->request->getString('name'));
        $description = trim($request->request->getString('description'));
        $schema = trim($request->request->getString('schema'));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($schema !== '') {
            try {
                $parsed = Yaml::parse($schema);
                if (!is_array($parsed)) {
                    $errors[] = 'Schema must be a YAML mapping (key-value pairs).';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Invalid YAML in schema: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->render('admin/node_type/edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'nodeType' => $nodeType,
                'errors' => $errors,
                'formData' => [
                    'name' => $name,
                    'description' => $description,
                    'schema' => $schema,
                ],
                'activeSection' => 'node_types',
            ]);
        }

        if ($nodeType === null) {
            $nodeType = new NodeType($serverName, $name);
            $this->em->persist($nodeType);
        } else {
            $nodeType->setName($name);
        }

        $nodeType->setDescription($description ?: null);
        $nodeType->setSchema($schema ?: null);

        $this->em->flush();

        return $this->redirectToRoute('admin_node_type_edit', [
            'serverName' => $serverName,
            'xuid' => $nodeType->getXuid(),
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
     * @return array{server: array{name: string, label: string, accountCount: int, toolCount: int}, serverHasHabits: bool, serverHasTracking: bool}
     */
    private function navContext(string $serverName, ServerConfig $serverConfig): array
    {
        $tools = $this->mcpHandler->getTools();
        $visible = array_values(array_filter(
            $tools,
            static fn (ToolInterface $tool) => $tool->getAccountType() === null
                || $serverConfig->hasAccountType($tool->getAccountType()),
        ));

        return [
            'server' => [
                'name' => $serverName,
                'label' => $serverConfig->label,
                'accountCount' => count($serverConfig->accounts),
                'toolCount' => count($visible),
            ],
            'serverHasHabits' => $serverConfig->hasAccountType('habits'),
            'serverHasTracking' => $serverConfig->hasAccountType('tracking'),
        ];
    }
}
