<?php

namespace App\Controller;

use App\Config\PrismConfigLoader;
use App\Config\ServerConfig;
use App\Entity\DocumentType;
use App\Mcp\McpHandler;
use App\Mcp\Tool\ToolInterface;
use App\Repository\DocumentTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

class DocumentTypeController extends AbstractController
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly EntityManagerInterface $em,
        private readonly McpHandler $mcpHandler,
    ) {
    }

    #[Route('/admin/server/{serverName}/document-types', name: 'admin_server_document_types', methods: ['GET'])]
    public function list(string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $types = $this->documentTypeRepository->findByServer($serverName);

        return $this->render('admin/document_type/list.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'types' => $types,
            'activeSection' => 'document_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/document-types/new', name: 'admin_document_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $serverName): Response
    {
        $serverConfig = $this->resolveServer($serverName);

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, null);
        }

        return $this->render('admin/document_type/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'documentType' => null,
            'errors' => [],
            'activeSection' => 'document_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/document-types/{xuid}/edit', name: 'admin_document_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $serverName, string $xuid): Response
    {
        $serverConfig = $this->resolveServer($serverName);
        $documentType = $this->documentTypeRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($documentType === null) {
            throw $this->createNotFoundException('Document type not found');
        }

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $serverName, $documentType);
        }

        return $this->render('admin/document_type/edit.html.twig', [
            ...$this->navContext($serverName, $serverConfig),
            'documentType' => $documentType,
            'errors' => [],
            'activeSection' => 'document_types',
        ]);
    }

    #[Route('/admin/server/{serverName}/document-types/{xuid}/delete', name: 'admin_document_type_delete', methods: ['POST'])]
    public function delete(string $serverName, string $xuid): Response
    {
        $documentType = $this->documentTypeRepository->findOneByServerAndXuid($serverName, $xuid);

        if ($documentType === null) {
            throw $this->createNotFoundException('Document type not found');
        }

        if ($documentType->getDocuments()->count() > 0) {
            $this->addFlash('error', 'Cannot delete a document type that still has documents. Remove all documents of this type first.');
            return $this->redirectToRoute('admin_document_type_edit', ['serverName' => $serverName, 'xuid' => $xuid]);
        }

        $this->em->remove($documentType);
        $this->em->flush();

        return $this->redirectToRoute('admin_server_document_types', ['serverName' => $serverName]);
    }

    private function handleForm(Request $request, string $serverName, ?DocumentType $documentType): Response
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
            return $this->render('admin/document_type/edit.html.twig', [
                ...$this->navContext($serverName, $serverConfig),
                'documentType' => $documentType,
                'errors' => $errors,
                'formData' => [
                    'name' => $name,
                    'description' => $description,
                    'schema' => $schema,
                ],
                'activeSection' => 'document_types',
            ]);
        }

        if ($documentType === null) {
            $documentType = new DocumentType($serverName, $name);
            $this->em->persist($documentType);
        } else {
            $documentType->setName($name);
        }

        $documentType->setDescription($description ?: null);
        $documentType->setSchema($schema ?: null);

        $this->em->flush();

        return $this->redirectToRoute('admin_document_type_edit', [
            'serverName' => $serverName,
            'xuid' => $documentType->getXuid(),
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
