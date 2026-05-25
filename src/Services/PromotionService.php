<?php

namespace App\Services;

use App\Entity\Customer;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;

class PromotionService implements PromotionServiceInterface
{
    private CustomerRepositoryInterface $customerRepository;
    private PaymentGatewayInterface $paymentGateway;
    private string $paymentLinkId;
    private float $monthlyValue;
    private string $monthlyName;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        PaymentGatewayInterface $paymentGateway,
        string $paymentLinkId = '',
        float $monthlyValue = 19.90,
        string $monthlyName = 'AIFreelas - Assinatura Mensal'
    ) {
        $this->customerRepository = $customerRepository;
        $this->paymentGateway = $paymentGateway;
        $this->paymentLinkId = $paymentLinkId;
        $this->monthlyValue = $monthlyValue;
        $this->monthlyName = $monthlyName;
    }

    public function handlePromotionGoal(Customer $customer, Logger $log): void
    {
        if ($customer->getPlan() !== 'LIFETIME' || $customer->getPaymentStatus() !== 'RECEIVED') {
            return;
        }

        if (empty($this->paymentLinkId)) {
            $log->warning('Payment Link ID is not configured. Skipping launch goal check.');
            return;
        }

        try {
            $lifetimeCount = $this->customerRepository->countPaidLifetimeCustomers();
            if ($lifetimeCount >= 100) {
                $log->info("Launch campaign goal reached ({$lifetimeCount} lifetime users). Triggering Asaas payment link conversion to Monthly Subscription.", ['link_id' => $this->paymentLinkId]);
                
                $success = $this->paymentGateway->updatePaymentLinkToMonthly(
                    $this->paymentLinkId,
                    $this->monthlyValue,
                    $this->monthlyName,
                    $log
                );
                
                if ($success) {
                    $log->info("Asaas payment link successfully converted to Monthly Subscription!");
                } else {
                    $log->error("Failed to convert Asaas payment link to Monthly Subscription.");
                }
            }
        } catch (\Throwable $e) {
            $log->error('Error processing launch promotion campaign goal check', ['error' => $e->getMessage()]);
        }
    }
}
