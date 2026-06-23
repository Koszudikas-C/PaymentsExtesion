<?php

namespace App\Controllers;

use App\Services\CustomerValidationService;
use App\Services\NotepadQueueService;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Notepad;
use Monolog\Logger;

class NotepadController
{
    private CustomerValidationService $validationService;
    private NotepadQueueService $queueService;
    private Logger $logger;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CustomerValidationService $validationService,
        NotepadQueueService $queueService,
        Logger $logger,
        EntityManagerInterface $entityManager
    ) {
        $this->validationService = $validationService;
        $this->queueService = $queueService;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
    }

    public function handleRequest(): void
    {
        header_remove('X-Powered-By');
        $this->setupCorsHeaders();

        if ($this->isPreflightRequest()) {
            return;
        }

        try {
            $params = $this->captureAndSanitizeInputs();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            $chromeId = $params['chrome_identity_id'] ?? null;
            if (empty($chromeId)) {
                $this->respondWithError(400, 'Parameter chrome_identity_id is required.');
                return;
            }

            // Validação de Identidade usando o Serviço
            $customerResult = $this->validationService->validateRequest($chromeId, $params['email'] ?? null);

            // Retorno de erro mapeado (Falha de Autenticação / Licença não encontrada)
            if (is_array($customerResult)) {
                $this->respondWithJson(403, $customerResult);
                return;
            }

            $customer = $customerResult;
            
            if ($customer->getPlan() !== 'CO-CREATOR') {
                $this->respondWithError(403, 'Note synchronization is exclusive to the CO-CREATOR plan.');
                return;
            }

            $ownerJid = $params['owner_jid'] ?? null;
            if (empty($ownerJid)) {
                $this->respondWithError(400, 'Parameter owner_jid is required.');
                return;
            }

            $jid = $params['jid'] ?? '';

            if ($method === 'GET') {
                $requestedJids = null;
                if (!empty($params['jids'])) {
                    $requestedJids = is_string($params['jids']) ? explode(',', $params['jids']) : (array)$params['jids'];
                }
                $this->handleGet($customer, $ownerJid, $jid, !empty($params['metadata_only']), $requestedJids, $params['global_hash'] ?? null);
            } elseif ($method === 'POST') {
                if (empty($jid)) {
                    $this->respondWithError(400, 'The jid parameter is required to save.');
                    return;
                }
                $this->handlePost($customer, $ownerJid, $jid, $params['note'] ?? null, $params['updated_at'] ?? null);
            } else {
                $this->respondWithError(405, 'Method not allowed.');
            }

        } catch (\Throwable $e) {
            $this->logger->error('Error in NotepadController', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->respondWithError(500, 'Internal server error while processing notes.');
        }
    }

    private function handleGet($customer, string $ownerJid, string $jid, bool $metadataOnly = false, ?array $requestedJids = null, ?string $clientGlobalHash = null): void
    {
        if (empty($jid)) {
            $notepadRepository = $this->entityManager->getRepository(Notepad::class);
            
            $criteria = ['customer' => $customer, 'ownerJid' => $ownerJid];
            if (!empty($requestedJids)) {
                $criteria['jid'] = $requestedJids;
            }
            
            $notepads = $notepadRepository->findBy($criteria);
            
            $results = [];
            foreach ($notepads as $n) {
                $noteContent = $n->getNote();
                $results[$n->getJid()] = [
                    'note' => $noteContent,
                    'hash' => hash('sha256', (string)$noteContent),
                    'updated_at' => $n->getDateUpdated() ? ($n->getDateUpdated()->getTimestamp() * 1000) : null
                ];
            }
            
            if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
                $prefix = 'notepad_latest_' . $customer->getId() . '_' . $ownerJid . '_';
                
                if (!empty($requestedJids)) {
                    // O(1) fetch para JIDs específicos
                    foreach ($requestedJids as $reqJid) {
                        $key = $prefix . $reqJid;
                        $data = apcu_fetch($key);
                        if ($data !== false && is_array($data)) {
                            $cachedTs = $data['updated_at'] ?? 0;
                            $existingTs = $results[$reqJid]['updated_at'] ?? 0;
                            if ($cachedTs >= $existingTs) {
                                $results[$reqJid] = [
                                    'note' => $data['note'],
                                    'hash' => hash('sha256', (string)$data['note']),
                                    'updated_at' => $cachedTs
                                ];
                            }
                        }
                    }
                } elseif (function_exists('apcu_cache_info')) {
                    // O(N) sweep fallback
                    $info = apcu_cache_info();
                    if (isset($info['cache_list'])) {
                        foreach ($info['cache_list'] as $entry) {
                            $key = $entry['info'] ?? $entry['key'];
                            if (strpos($key, $prefix) === 0) {
                                $cachedJid = substr($key, strlen($prefix));
                                $data = apcu_fetch($key);
                                if ($data !== false && is_array($data)) {
                                    $cachedTs = $data['updated_at'] ?? 0;
                                    $existingTs = $results[$cachedJid]['updated_at'] ?? 0;
                                    if ($cachedTs >= $existingTs) {
                                        $results[$cachedJid] = [
                                            'note' => $data['note'],
                                            'hash' => hash('sha256', (string)$data['note']),
                                            'updated_at' => $cachedTs
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            $hashesToHash = [];
            foreach ($results as $j => $r) {
                if (empty($requestedJids)) {
                    $hashesToHash[$j] = $r['hash'];
                }
                if ($metadataOnly && (empty($requestedJids) || !in_array($j, $requestedJids))) {
                    unset($results[$j]['note']);
                }
            }
            
            $globalHash = null;
            if (empty($requestedJids)) {
                ksort($hashesToHash);
                $globalHash = hash('sha256', implode('', $hashesToHash));
                
                if ($clientGlobalHash === $globalHash) {
                    $this->respondWithJson(200, [
                        'status' => 'success',
                        'message' => 'Up to date',
                        'global_hash' => $globalHash,
                        'notes' => []
                    ]);
                    return;
                }
            }
            
            $this->respondWithJson(200, [
                'status' => 'success',
                'global_hash' => $globalHash,
                'notes' => $results
            ]);
            return;
        }

        // 1. Tenta recuperar direto da memória RAM (Super Rápido) para não onerar o BD
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $fastKey = 'notepad_latest_' . $customer->getId() . '_' . $ownerJid . '_' . $jid;
            $data = apcu_fetch($fastKey);
            
            if ($data !== false && is_array($data)) {
                $this->respondWithJson(200, [
                    'status' => 'success',
                    'note' => $metadataOnly ? null : ($data['note'] ?? null),
                    'hash' => hash('sha256', (string)($data['note'] ?? '')),
                    'updated_at' => $data['updated_at'] ?? null
                ]);
                return;
            } elseif ($data !== false && is_string($data)) {
                // Backward compatibility
                $this->respondWithJson(200, [
                    'status' => 'success',
                    'note' => $metadataOnly ? null : $data,
                    'hash' => hash('sha256', (string)$data),
                    'updated_at' => null
                ]);
                return;
            }
        }

        // 2. Se não está na memória fresca, puxa do BD
        $notepadRepository = $this->entityManager->getRepository(Notepad::class);
        $notepad = $notepadRepository->findOneBy(['customer' => $customer, 'ownerJid' => $ownerJid, 'jid' => $jid]);

        $noteContent = $notepad ? $notepad->getNote() : null;
        $this->respondWithJson(200, [
            'status' => 'success',
            'note' => $metadataOnly ? null : $noteContent,
            'hash' => hash('sha256', (string)$noteContent),
            'updated_at' => $notepad ? ($notepad->getDateUpdated()->getTimestamp() * 1000) : null
        ]);
    }

    private function handlePost($customer, string $ownerJid, string $jid, ?string $note, ?int $updatedAt): void
    {
        // Enfileira usando o Load Shedding
        $this->queueService->enqueue($customer->getId(), $ownerJid, $jid, $note, $updatedAt, 0);

        $this->respondWithJson(200, [
            'status' => 'success',
            'message' => 'Note saved in the synchronization queue.',
            'hash' => hash('sha256', (string)$note)
        ]);
    }

    private function isPreflightRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';
    }

    private function captureAndSanitizeInputs(): array
    {
        $rawInput = file_get_contents('php://input') ?: '';
        if ($rawInput !== '' && !mb_check_encoding($rawInput, 'UTF-8')) {
            $rawInput = mb_convert_encoding($rawInput, 'UTF-8', 'auto');
        }

        $input = json_decode($rawInput, true) ?: [];

        $rawChromeId = $_REQUEST['chrome_identity_id'] ?? $input['chrome_identity_id'] ?? null;
        $rawEmail = $_REQUEST['email'] ?? $input['email'] ?? null;
        $jid = $_REQUEST['jid'] ?? $input['jid'] ?? null;
        $jids = $_REQUEST['jids'] ?? $input['jids'] ?? null;
        $globalHash = $_REQUEST['global_hash'] ?? $input['global_hash'] ?? null;
        $ownerJid = $_REQUEST['owner_jid'] ?? $input['owner_jid'] ?? null;
        $note = $_REQUEST['note'] ?? $input['note'] ?? null;
        $updatedAt = $_REQUEST['updated_at'] ?? $input['updated_at'] ?? null;
        $metadataOnly = $_REQUEST['metadata_only'] ?? $input['metadata_only'] ?? false;

        return [
            'chrome_identity_id' => $rawChromeId ? substr(preg_replace('/[^a-zA-Z0-9@\.\-_]/', '', $rawChromeId), 0, 255) : null,
            'email' => $rawEmail ? filter_var($rawEmail, FILTER_SANITIZE_EMAIL) : null,
            'jid' => is_string($jid) ? substr(trim($jid), 0, 100) : null,
            'owner_jid' => is_string($ownerJid) ? substr(trim($ownerJid), 0, 100) : null,
            'jids' => is_string($jids) ? $jids : (is_array($jids) ? implode(',', $jids) : null),
            'global_hash' => is_string($globalHash) ? trim($globalHash) : null,
            'note' => is_string($note) ? $note : (is_array($note) ? json_encode($note) : null),
            'updated_at' => $updatedAt ? (int)$updatedAt : null,
            'metadata_only' => filter_var($metadataOnly, FILTER_VALIDATE_BOOLEAN)
        ];
    }

    private function setupCorsHeaders(): void
    {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    }

    private function respondWithJson(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function respondWithError(int $statusCode, string $message): void
    {
        $this->respondWithJson($statusCode, [
            'status' => 'error',
            'message' => $message
        ]);
    }
}
