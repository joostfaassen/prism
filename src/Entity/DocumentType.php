<?php

namespace App\Entity;

use App\Repository\DocumentTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: DocumentTypeRepository::class)]
#[ORM\Table(name: 'document_type')]
#[ORM\Index(columns: ['server_name'], name: 'idx_document_type_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_document_type_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'name'])]
class DocumentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $schema = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'documentType')]
    private Collection $documents;

    public function __construct(string $serverName, string $name)
    {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->documents = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();
        return $this;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    public function setSchema(?string $schema): self
    {
        $this->schema = $schema;
        $this->touch();
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedSchema(): array
    {
        if ($this->schema === null || trim($this->schema) === '') {
            return [];
        }

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($this->schema);
            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'xuid' => $this->xuid,
            'server_name' => $this->serverName,
            'name' => $this->name,
            'description' => $this->description,
            'schema' => $this->getParsedSchema(),
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
