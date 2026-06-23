<?php

namespace App\Controllers;

use App\Entity\Feedback;
use App\Interfaces\Repositories\FeedbackRepositoryInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\DiscordServiceInterface;
use Exception;

class FeedbackController
{
    private FeedbackRepositoryInterface $feedbackRepository;
    private CustomerRepositoryInterface $customerRepository;
    private DiscordServiceInterface $discordService;

    public function __construct(
        FeedbackRepositoryInterface $feedbackRepository,
        CustomerRepositoryInterface $customerRepository,
        DiscordServiceInterface $discordService
    ) {
        $this->feedbackRepository = $feedbackRepository;
        $this->customerRepository = $customerRepository;
        $this->discordService = $discordService;
    }

    public function handleRequest(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
            return;
        }

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
            return;
        }

        $type = $data['type'] ?? 'EVALUATION';
        $message = $data['message'] ?? '';
        $rating = isset($data['rating']) ? (int) $data['rating'] : null;
        $customerId = $data['customer_id'] ?? null;
        $identifier = $data['identifier'] ?? null; // Email or LicenseKey fallback

        if (empty(trim($message))) {
            $message = 'Empty';
        }

        try {
            $customer = null;
            if ($customerId) {
                $customer = $this->customerRepository->findById($customerId);
            }

            if (!$customer && $identifier) {
                $customer = $this->customerRepository->findByEmail($identifier);
                if (!$customer) {
                    $customer = $this->customerRepository->findByLicenseKey($identifier);
                }
            }

            $feedback = new Feedback($type, $message, $rating, $customer);
            $this->feedbackRepository->save($feedback);

            $this->sendDiscordNotification($feedback, $customer, $identifier ?? $customerId);

            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => 'Feedback received successfully.',
                'data' => [
                    'id' => $feedback->getId(),
                    'customer_id' => $customer ? $customer->getId() : null,
                    'type' => $feedback->getType(),
                    'rating' => $feedback->getRating(),
                    'message' => $feedback->getMessage(),
                    'createdAt' => $feedback->getDateCreated()->format('Y-m-d\TH:i:sP'),
                    'updatedAt' => $feedback->getDateUpdated()->format('Y-m-d\TH:i:sP')
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
            error_log('Error saving feedback: ' . $e->getMessage());
        }
    }

    private function sendDiscordNotification(Feedback $feedback, $customer, ?string $identifier): void
    {
        $title = $feedback->getType() === 'FEATURE_REQUEST'
            ? '🚀 New Idea / Feature Proposal'
            : '⭐ New User Review';

        $color = $feedback->getType() === 'FEATURE_REQUEST' ? 0x3498db : 0xf1c40f; // Azul ou Amarelo

        $desc = "**Message:**\n" . $feedback->getMessage();

        $fields = [];

        if ($feedback->getRating() !== null) {
            $stars = str_repeat('⭐', max(1, min(5, $feedback->getRating())));
            $fields[] = ['name' => 'Rating', 'value' => $stars, 'inline' => true];
        }

        if ($customer) {
            $fields[] = ['name' => 'Customer', 'value' => $customer->getName(), 'inline' => true];
            $fields[] = ['name' => 'Email', 'value' => $customer->getEmail(), 'inline' => true];
        } elseif ($identifier) {
            $fields[] = ['name' => 'Provided Identifier', 'value' => $identifier, 'inline' => true];
        } else {
            $fields[] = ['name' => 'User', 'value' => 'Anonymous', 'inline' => true];
        }

        $this->discordService->sendEmbed($title, $desc, $color, $fields);
    }
}
