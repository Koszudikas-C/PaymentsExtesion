<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;

class CampaignController
{
    private CustomerRepositoryInterface $customerRepository;
    private Logger $logger;
    private int $target;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Logger $logger,
        int $target
    ) {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->target = $target;
    }

    public function getStats(): void
    {
        header_remove('X-Powered-By');

        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            return;
        }

        try {
            if ($this->target <= 0) {
                $this->logger->error('Campaign target not configured or invalid', ['target' => $this->target]);
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Campaign target configuration error']);
                return;
            }

            $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
            $percentage = min(100, ($lifetimeCount / $this->target) * 100);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'count' => $lifetimeCount,
                    'target' => $this->target,
                    'percentage' => $percentage
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            $this->logger->error('Error in CampaignController::getStats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Internal server error'
            ]);
        }
    }
}

