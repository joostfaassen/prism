<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Entity\Document;
use App\Entity\DocumentNote;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use App\Repository\DocumentNoteRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

class DocumentController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly DocumentNoteRepository $noteRepository,
        private readonly EntityManagerInterface $em,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    // ── Document CRUD ──────────────────────────────────────────────────

    #[Route('/admin/server/{serverName}/documents', name: 'admin_server_documents', methods: ['GET'])]
    public function list(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $typeFilter = $request->query->get('type');
        $documents = $this->documentRepository->findByServer($serverName, $typeFilter ?: null);
        $types = $this->documentTypeRepository->findByServer($serverName);

        return $this->render('admin/document/list.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'documents' => $documents,
            'types' => $types,
            'typeFilter' => $typeFilter,
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/new', name: 'admin_document_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $types = $this->documentTypeRepository->findByServer($serverName);

        if ($types === []) {
            $this->addFlash('error', 'Create at least one document type before adding documents.');
            return $this->redirectToRoute('admin_server_document_types', ['serverName' => $serverName]);
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, null, $types);
        }

        return $this->render('admin/document/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'document' => null,
            'types' => $types,
            'errors' => [],
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/{xuid}', name: 'admin_document_show', methods: ['GET'])]
    public function show(string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $document = $this->resolveDocument($serverName, $xuid);

        return $this->render('admin/document/show.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'document' => $document,
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/{xuid}/edit', name: 'admin_document_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $document = $this->resolveDocument($serverName, $xuid);
        $types = $this->documentTypeRepository->findByServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, $document, $types);
        }

        return $this->render('admin/document/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'document' => $document,
            'types' => $types,
            'errors' => [],
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/{xuid}/delete', name: 'admin_document_delete', methods: ['POST'])]
    public function delete(string $serverName, string $xuid): Response
    {
        $document = $this->resolveDocument($serverName, $xuid);

        $this->em->remove($document);
        $this->em->flush();

        return $this->redirectToRoute('admin_server_documents', ['serverName' => $serverName]);
    }

    // ── Note CRUD ──────────────────────────────────────────────────

    #[Route('/admin/server/{serverName}/documents/{documentXuid}/notes/new', name: 'admin_document_note_new', methods: ['GET', 'POST'])]
    public function newNote(Request $request, string $serverName, string $documentXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $document = $this->resolveDocument($serverName, $documentXuid);

        if ($request->isMethod('POST')) {
            return $this->handleNoteForm($request, $serverName, $document, null);
        }

        return $this->render('admin/document/note_edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'document' => $document,
            'note' => null,
            'errors' => [],
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/{documentXuid}/notes/{noteXuid}/edit', name: 'admin_document_note_edit', methods: ['GET', 'POST'])]
    public function editNote(Request $request, string $serverName, string $documentXuid, string $noteXuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $document = $this->resolveDocument($serverName, $documentXuid);
        $note = $this->noteRepository->findOneByXuid($noteXuid);

        if ($note === null || $note->getDocument()->getXuid() !== $documentXuid) {
            throw $this->createNotFoundException('Note not found');
        }

        if ($request->isMethod('POST')) {
            return $this->handleNoteForm($request, $serverName, $document, $note);
        }

        return $this->render('admin/document/note_edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'document' => $document,
            'note' => $note,
            'errors' => [],
            'activeSection' => 'documents',
        ]);
    }

    #[Route('/admin/server/{serverName}/documents/{documentXuid}/notes/{noteXuid}/delete', name: 'admin_document_note_delete', methods: ['POST'])]
    public function deleteNote(string $serverName, string $documentXuid, string $noteXuid): Response
    {
        $document = $this->resolveDocument($serverName, $documentXuid);
        $note = $this->noteRepository->findOneByXuid($noteXuid);

        if ($note === null || $note->getDocument()->getXuid() !== $documentXuid) {
            throw $this->createNotFoundException('Note not found');
        }

        $document->removeNote($note);
        $this->em->remove($note);
        $this->em->flush();

        return $this->redirectToRoute('admin_document_show', [
            'serverName' => $serverName,
            'xuid' => $documentXuid,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * @param list<\App\Entity\DocumentType> $types
     */
    private function handleForm(Request $request, string $serverName, ?Document $document, array $types): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $name = trim($request->request->getString('name'));
        $summary = trim($request->request->getString('summary'));
        $config = trim($request->request->getString('config'));
        $typeXuid = trim($request->request->getString('document_type'));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($typeXuid === '') {
            $errors[] = 'Document type is required.';
        }

        $selectedType = null;
        foreach ($types as $t) {
            if ($t->getXuid() === $typeXuid) {
                $selectedType = $t;
                break;
            }
        }

        if ($typeXuid !== '' && $selectedType === null) {
            $errors[] = 'Invalid document type selected.';
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
            return $this->render('admin/document/edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'document' => $document,
                'types' => $types,
                'errors' => $errors,
                'formData' => [
                    'name' => $name,
                    'summary' => $summary,
                    'config' => $config,
                    'document_type' => $typeXuid,
                ],
                'activeSection' => 'documents',
            ]);
        }

        if ($document === null) {
            $document = new Document($serverName, $selectedType, $name);
            $this->em->persist($document);
        } else {
            $document->setName($name);
            $document->setDocumentType($selectedType);
        }

        $document->setSummary($summary ?: null);
        $document->setConfig($config ?: null);

        $this->em->flush();

        return $this->redirectToRoute('admin_document_show', [
            'serverName' => $serverName,
            'xuid' => $document->getXuid(),
        ]);
    }

    private function handleNoteForm(Request $request, string $serverName, Document $document, ?DocumentNote $note): Response
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
            return $this->render('admin/document/note_edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'document' => $document,
                'note' => $note,
                'errors' => $errors,
                'formData' => [
                    'summary' => $summary,
                    'content' => $content,
                ],
                'activeSection' => 'documents',
            ]);
        }

        if ($note === null) {
            $note = new DocumentNote($document, $summary, $content);
            $this->em->persist($note);
        } else {
            $note->setSummary($summary);
            $note->setContent($content);
        }

        $this->em->flush();

        return $this->redirectToRoute('admin_document_show', [
            'serverName' => $serverName,
            'xuid' => $document->getXuid(),
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

    private function resolveDocument(string $serverName, string $xuid): Document
    {
        $document = $this->documentRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($document === null) {
            throw $this->createNotFoundException('Document not found');
        }

        return $document;
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
