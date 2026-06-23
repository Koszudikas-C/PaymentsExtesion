<?php

namespace App\Services;

use App\Entity\Customer;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use Monolog\Logger;

class CustomerValidationService
{
    private CustomerRepositoryInterface $customerRepository;
    private Logger $logger;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Logger $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Valida a requisição do usuário verificando o chrome_identity_id e/ou email.
     * Retorna o Customer se a validação for bem-sucedida, lança exceção ou retorna array com erro se falhar.
     * 
     * @return Customer|array
     */
    public function validateRequest(string $chromeIdentityId, ?string $email)
    {
        // Primeiro tenta localizar pelo chrome_identity_id
        $startChromeLookup = microtime(true);
        $customer = $this->customerRepository->findByChromeIdentityId($chromeIdentityId);
        $durationChromeLookup = round((microtime(true) - $startChromeLookup) * 1000, 2);
        $this->logPerformance('CustomerRepository', 'findByChromeIdentityId', $durationChromeLookup);

        // Se não encontrou e email foi fornecido, tenta buscar pelo email
        if (!$customer && !empty($email)) {
            $startEmailLookup = microtime(true);
            $customer = $this->customerRepository->findByEmail($email);
            $durationEmailLookup = round((microtime(true) - $startEmailLookup) * 1000, 2);
            $this->logPerformance('CustomerRepository', 'findByEmail', $durationEmailLookup, 'Fallback');
            
            if ($customer) {
                $savedChromeId = $customer->getChromeIdentityId();
                
                if (empty($savedChromeId)) {
                    // Se encontrou pelo email mas ainda não há chrome_identity_id associado, vincula agora
                    $startSave = microtime(true);
                    $customer->setChromeIdentityId($chromeIdentityId);
                    $this->customerRepository->save($customer);
                    $durationSave = round((microtime(true) - $startSave) * 1000, 2);
                    $this->logPerformance('CustomerRepository', 'save', $durationSave, 'Auto Link Chrome ID');
                } elseif ($savedChromeId !== $chromeIdentityId) {
                    // Conflito: O email existe mas está vinculado a outro chrome_identity_id.
                    return [
                        'status' => 'conflict',
                        'message' => 'Sua licença está ativada em outro perfil ou dispositivo. Faça a ativação novamente neste perfil para transferir o uso.'
                    ];
                }
            }
        }

        if (!$customer) {
            return [
                'status' => 'not_found',
                'message' => 'Nenhuma licença ativa vinculada a este perfil.'
            ];
        }

        return $customer;
    }

    private function logPerformance(string $class, string $method, float $durationMs, string $additionalInfo = ''): void
    {
        $threshold = (float)($_ENV['PERFORMANCE_THRESHOLD_MS'] ?? 1000.0);
        $isAlert = $durationMs > $threshold;
        $level = $isAlert ? 'error' : 'info';
        $tag = $isAlert ? '[PERFORMANCE_ALERT]' : '[PERFORMANCE]';
        
        $message = "{$tag} {$class}::{$method}" . ($additionalInfo !== '' ? " ({$additionalInfo})" : "") . " took {$durationMs}ms";
        
        $this->logger->$level($message, [
            'type' => 'performance',
            'class' => $class,
            'method' => $method,
            'duration_ms' => $durationMs,
            'alert' => $isAlert
        ]);
    }
}
