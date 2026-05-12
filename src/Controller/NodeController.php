<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Entity\Node;
use App\Entity\NodeNote;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use App\Repository\NodeNoteRepository;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

class NodeController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeTypeRepository $nodeTypeRepository,
        private readonly NodeNoteRepository $noteRepository,
        private readonly EntityManagerInterface $em,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    // ── Node CRUD ──────────────────────────────────────────────────

    #[Route('/admin/server/{serverName}/nodes', name: 'admin_server_nodes', methods: ['GET'])]
    public function list(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $typeFilter = $request->query->get('type');
        $nodes = $this->nodeRepository->findByServer($serverName, $typeFilter ?: null);
        $types = $this->nodeTypeRepository->findByServer($serverName);

        return $this->render('admin/node/list.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'nodes' => $nodes,
            'types' => $types,
            'typeFilter' => $typeFilter,
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/new', name: 'admin_node_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $types = $this->nodeTypeRepository->findByServer($serverName);

        if ($types === []) {
            $this->addFlash('error', 'Create at least one node type before adding nodes.');
            return $this->redirectToRoute('admin_server_node_types', ['serverName' => $serverName]);
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, null, $types);
        }

        return $this->render('admin/node/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'node' => null,
            'types' => $types,
            'errors' => [],
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/{xuid}', name: 'admin_node_show', methods: ['GET'])]
    public function show(string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $node = $this->resolveNode($serverName, $xuid);

        return $this->render('admin/node/show.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'node' => $node,
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/{xuid}/edit', name: 'admin_node_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $node = $this->resolveNode($serverName, $xuid);
        $types = $this->nodeTypeRepository->findByServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, $node, $types);
        }

        return $this->render('admin/node/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'node' => $node,
            'types' => $types,
            'errors' => [],
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/{xuid}/delete', name: 'admin_node_delete', methods: ['POST'])]
    public function delete(string $serverName, string $xuid): Response
    {
        $node = $this->resolveNode($serverName, $xuid);

        $this->em->remove($node);
        $this->em->flush();

        return $this->redirectToRoute('admin_server_nodes', ['serverName' => $serverName]);
    }

    // ── Note CRUD ──────────────────────────────────────────────────

    #[Route('/admin/server/{serverName}/nodes/{nodeXuid}/notes/new', name: 'admin_node_note_new', methods: ['GET', 'POST'])]
    public function newNote(Request $request, string $serverName, string $nodeXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $node = $this->resolveNode($serverName, $nodeXuid);

        if ($request->isMethod('POST')) {
            return $this->handleNoteForm($request, $serverName, $node, null);
        }

        return $this->render('admin/node/note_edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'node' => $node,
            'note' => null,
            'errors' => [],
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/{nodeXuid}/notes/{noteXuid}/edit', name: 'admin_node_note_edit', methods: ['GET', 'POST'])]
    public function editNote(Request $request, string $serverName, string $nodeXuid, string $noteXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $node = $this->resolveNode($serverName, $nodeXuid);
        $note = $this->noteRepository->findOneByXuid($noteXuid);

        if ($note === null || $note->getNode()->getXuid() !== $nodeXuid) {
            throw $this->createNotFoundException('Note not found');
        }

        if ($request->isMethod('POST')) {
            return $this->handleNoteForm($request, $serverName, $node, $note);
        }

        return $this->render('admin/node/note_edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'node' => $node,
            'note' => $note,
            'errors' => [],
            'activeSection' => 'nodes',
        ]);
    }

    #[Route('/admin/server/{serverName}/nodes/{nodeXuid}/notes/{noteXuid}/delete', name: 'admin_node_note_delete', methods: ['POST'])]
    public function deleteNote(string $serverName, string $nodeXuid, string $noteXuid): Response
    {
        $node = $this->resolveNode($serverName, $nodeXuid);
        $note = $this->noteRepository->findOneByXuid($noteXuid);

        if ($note === null || $note->getNode()->getXuid() !== $nodeXuid) {
            throw $this->createNotFoundException('Note not found');
        }

        $node->removeNote($note);
        $this->em->remove($note);
        $this->em->flush();

        return $this->redirectToRoute('admin_node_show', [
            'serverName' => $serverName,
            'xuid' => $nodeXuid,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * @param list<\App\Entity\NodeType> $types
     */
    private function handleForm(Request $request, string $serverName, ?Node $node, array $types): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $name = trim($request->request->getString('name'));
        $summary = trim($request->request->getString('summary'));
        $config = trim($request->request->getString('config'));
        $typeXuid = trim($request->request->getString('node_type'));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($typeXuid === '') {
            $errors[] = 'Node type is required.';
        }

        $selectedType = null;
        foreach ($types as $t) {
            if ($t->getXuid() === $typeXuid) {
                $selectedType = $t;
                break;
            }
        }

        if ($typeXuid !== '' && $selectedType === null) {
            $errors[] = 'Invalid node type selected.';
        }

        if ($config !== '') {
            try {
                $parsed = Yaml::parse($config);
                if (!is_array($parsed)) {
                    $errors[] = 'Config must be a YAML mapping (key-value pairs).';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Invalid YAML in config: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->render('admin/node/edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'node' => $node,
                'types' => $types,
                'errors' => $errors,
                'formData' => [
                    'name' => $name,
                    'summary' => $summary,
                    'config' => $config,
                    'node_type' => $typeXuid,
                ],
                'activeSection' => 'nodes',
            ]);
        }

        if ($node === null) {
            $node = new Node($serverName, $selectedType, $name);
            $this->em->persist($node);
        } else {
            $node->setName($name);
            $node->setNodeType($selectedType);
        }

        $node->setSummary($summary ?: null);
        $node->setConfig($config ?: null);

        $this->em->flush();

        return $this->redirectToRoute('admin_node_show', [
            'serverName' => $serverName,
            'xuid' => $node->getXuid(),
        ]);
    }

    private function handleNoteForm(Request $request, string $serverName, Node $node, ?NodeNote $note): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $summary = trim($request->request->getString('summary'));
        $content = trim($request->request->getString('content'));

        $errors = [];

        if ($summary === '') {
            $errors[] = 'Summary is required.';
        }
        if ($content === '') {
            $errors[] = 'Content is required.';
        }

        if ($errors !== []) {
            return $this->render('admin/node/note_edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'node' => $node,
                'note' => $note,
                'errors' => $errors,
                'formData' => [
                    'summary' => $summary,
                    'content' => $content,
                ],
                'activeSection' => 'nodes',
            ]);
        }

        if ($note === null) {
            $note = new NodeNote($node, $summary, $content);
            $this->em->persist($note);
        } else {
            $note->setSummary($summary);
            $note->setContent($content);
        }

        $this->em->flush();

        return $this->redirectToRoute('admin_node_show', [
            'serverName' => $serverName,
            'xuid' => $node->getXuid(),
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

    private function resolveNode(string $serverName, string $xuid): Node
    {
        $node = $this->nodeRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($node === null) {
            throw $this->createNotFoundException('Node not found');
        }

        return $node;
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
