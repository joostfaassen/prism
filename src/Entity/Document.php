<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(columns: ['server_name'], name: 'idx_document_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_document_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'name'])]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\ManyToOne(targetEntity: DocumentType::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false)]
    private DocumentType $documentType;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $config = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, DocumentNote> */
    #[ORM\OneToMany(targetEntity: DocumentNote::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['summary' => 'ASC'])]
    private Collection $notes;

    public function __construct(string $serverName, DocumentType $documentType, string $name)
    {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->documentType = $documentType;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getXuid(): string
    {
        return $this->xuid;
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentType $documentType): self
    {
        $this->documentType = $documentType;
        $this->touch();
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;
        $this->touch();
        return $this;
    }

    public function getConfig(): ?string
    {
        return $this->config;
    }

    public function setConfig(?string $config): self
    {
        $this->config = $config;
        $this->touch();
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedConfig(): array
    {
        if ($this->config === null || trim($this->config) === '') {
            return [];
        }

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($this->config);
            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, DocumentNote> */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(DocumentNote $note): self
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
        }
        $this->touch();
        return $this;
    }

    public function removeNote(DocumentNote $note): self
    {
        $this->notes->removeElement($note);
        $this->touch();
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeNotes = false): array
    {
        $data = [
            'xuid' => $this->xuid,
            'server_name' => $this->serverName,
            'type' => $this->documentType->getName(),
            'type_xuid' => $this->documentType->getXuid(),
            'name' => $this->name,
            'summary' => $this->summary,
            'config' => $this->getParsedConfig(),
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];

        if ($includeNotes) {
            $data['notes'] = array_map(
                fn(DocumentNote $n) => ['xuid' => $n->getXuid(), 'summary' => $n->getSummary()],
                $this->notes->toArray(),
            );
        }

        return $data;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
