<?php

namespace App\Repository;

use App\Entity\DocumentNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentNote>
 */
class DocumentNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentNote::class);
    }

    public function findOneByXuid(string $xuid): ?DocumentNote
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }

    /**
     * @return list<DocumentNote>
     */
    public function findByDocument(int $documentId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.document = :documentId')
            ->setParameter('documentId', $documentId)
            ->orderBy('n.summary', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
