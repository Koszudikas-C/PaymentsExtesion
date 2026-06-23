<?php

namespace App\Interfaces\Services;

interface NotepadQueueServiceInterface
{
    /**
     * Adiciona um bloco de notas à fila.
     *
     * @param string $customerId
     * @param string $ownerJid
     * @param string $jid
     * @param string|null $note
     * @param int|null $updatedAt
     * @param int $retryCount
     */
    public function enqueue(
        string $customerId,
        string $ownerJid,
        string $jid,
        ?string $note,
        ?int $updatedAt = null,
        int $retryCount = 0
    ): void;
}
