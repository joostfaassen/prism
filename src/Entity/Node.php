<?php

namespace App\Entity;

use App\Repository\NodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Xuid\Xuid;

#[ORM\Entity(repositoryClass: NodeRepository::class)]
#[ORM\Table(name: 'node')]
#[ORM\Index(columns: ['server_name'], name: 'idx_node_server')]
#[ORM\Index(columns: ['xuid'], name: 'idx_node_xuid')]
#[ORM\UniqueConstraint(columns: ['server_name', 'name'])]
class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 22, unique: true)]
    private string $xuid;

    #[ORM\Column(length: 64)]
    private string $serverName;

    #[ORM\ManyToOne(targetEntity: NodeType::class, inversedBy: 'nodes')]
    #[ORM\JoinColumn(nullable: false)]
    private NodeType $nodeType;

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

    /** @var Collection<int, NodeNote> */
    #[ORM\OneToMany(targetEntity: NodeNote::class, mappedBy: 'node', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['summary' => 'ASC'])]
    private Collection $notes;

    public function __construct(string $serverName, NodeType $nodeType, string $name)
    {
        $this->xuid = Xuid::getXuid();
        $this->serverName = $serverName;
        $this->nodeType = $nodeType;
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

    public function getNodeType(): NodeType
    {
        return $this->nodeType;
    }

    public function setNodeType(NodeType $nodeType): self
    {
        $this->nodeType = $nodeType;
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

    /** @return Collection<int, NodeNote> */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function addNote(NodeNote $note): self
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
        }
        $this->touch();
        return $this;
    }

    public function removeNote(NodeNote $note): self
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
            'type' => $this->nodeType->getName(),
            'type_xuid' => $this->nodeType->getXuid(),
            'name' => $this->name,
            'summary' => $this->summary,
            'config' => $this->getParsedConfig(),
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];

        if ($includeNotes) {
            $data['notes'] = array_map(
                fn(NodeNote $n) => ['xuid' => $n->getXuid(), 'summary' => $n->getSummary()],
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
