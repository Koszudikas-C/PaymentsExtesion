<?php

namespace Tests\Unit\Handler\Processors;

use App\Handlers\Processors\PaymentReceivedProcessor;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\PromotionServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\AuditLogRepositoryInterface;
use App\Entity\Customer;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class PaymentReceivedProcessorTest extends TestCase
{
    private PaymentGatewayInterface|\PHPUnit\Framework\MockObject\MockObject $gateway;
    private EmailServiceInterface|\PHPUnit\Framework\MockObject\MockObject $emailService;
    private LicenseServiceInterface|\PHPUnit\Framework\MockObject\MockObject $licenseService;
    private CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $customerRepo;
    private AuditLogRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $auditRepo;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private PromotionServiceInterface|\PHPUnit\Framework\MockObject\MockObject $promotionService;
    private LandingPageSyncServiceInterface|\PHPUnit\Framework\MockObject\MockObject $syncService;
    private PaymentReceivedProcessor $processor;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->emailService = $this->createMock(EmailServiceInterface::class);
        $this->licenseService = $this->createMock(LicenseServiceInterface::class);
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->promotionService = $this->createMock(PromotionServiceInterface::class);
        $this->syncService = $this->createMock(LandingPageSyncServiceInterface::class);

        $this->processor = new PaymentReceivedProcessor(
            $this->gateway,
            $this->emailService,
            $this->licenseService,
            $this->customerRepo,
            $this->auditRepo,
            $this->logger,
            'test-salt',
            $this->promotionService,
            $this->syncService
        );
    }

    public function testProcessSuccess()
    {
        $data = [
            'payment' => ['customer' => 'cus_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'user@example.com',
                'name' => 'User Test',
                'mobilePhone' => '5511999999999'
            ]);


        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('ABCD-1234');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('user@example.com', 'ABCD-1234', $this->logger, 'User Test')
            ->willReturn(true);

        $this->customerRepo->expects($this->once())
            ->method('save');
            
        $this->customerRepo->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willReturn(10);
            
        $this->syncService->expects($this->once())
            ->method('notifySale');

        $this->processor->process($data);
    }

    public function testProcessGatewayFailure()
    {
        $data = [
            'payment' => ['customer' => 'cus_error']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Customer info not found'));

        $this->customerRepo->expects($this->never())
            ->method('save');

        $this->processor->process($data);
    }

    public function testProcessSubscriptionMonthly()
    {
        $data = [
            'payment' => [
                'customer' => 'cus_123',
                'subscription' => 'sub_987654',
                'dueDate' => '2026-06-16'
            ]
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'subscriber@example.com',
                'name' => 'Monthly Subscriber',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findBySubscriptionId')
            ->with('sub_987654')
            ->willReturn(null);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('SUB-1010');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertInstanceOf(Customer::class, $savedCustomer);
        $this->assertEquals('MONTHLY', $savedCustomer->getPlan());
        $this->assertEquals('sub_987654', $savedCustomer->getSubscriptionId());
        $this->assertNotNull($savedCustomer->getLicenseExpiresAt());
        $this->assertEquals('2026-07-19', $savedCustomer->getLicenseExpiresAt()->format('Y-m-d'));
    }

    public function testProcessMultipleLifetimePurchasesGenerateDifferentLicenses()
    {
        $data = [
            'payment' => [
                'id' => 'pay_second_123',
                'customer' => 'cus_123'
            ]
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'lifetime@example.com',
                'name' => 'Lifetime User',
                'mobilePhone' => '5511999999999'
            ]);

        // E-mail já existe, mas como é um pagamento Lifetime (sem subscriptionId),
        // findBySubscriptionId não é chamado e gera uma nova licença para o mesmo e-mail.
        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('NEW-LIC-123');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('lifetime@example.com', 'NEW-LIC-123', $this->logger, 'Lifetime User')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Customer $customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('NEW-LIC-123', $savedCustomer->getLicenseKey());
        $this->assertEquals('lifetime@example.com', $savedCustomer->getEmail());
        $this->assertEquals('LIFETIME', $savedCustomer->getPlan());
    }

    public function testProcessMonthlyExtension()
    {
        $data = [
            'payment' => [
                'id' => 'pay_renew_123',
                'customer' => 'cus_123',
                'subscription' => 'sub_monthly_123'
            ]
        ];

        $existingCustomer = new Customer('Monthly User', 'monthly@example.com', '5511999999999');
        $existingCustomer->markAsPaid('pay_initial_123');
        $existingCustomer->setPlan('MONTHLY');
        $existingCustomer->setSubscriptionId('sub_monthly_123');

        $initialExpiration = new \DateTime('2026-06-01 12:00:00');
        $existingCustomer->setLicenseExpiresAt($initialExpiration);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'monthly@example.com',
                'name' => 'Monthly User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findBySubscriptionId')
            ->with('sub_monthly_123')
            ->willReturn($existingCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($existingCustomer);

        $this->logger->expects($this->any())
            ->method('info')
            ->with($this->logicalOr(
                $this->equalTo('MONTHLY subscription extended for customer'),
                $this->stringContains('[PERFORMANCE]'),
                $this->stringContains('[PERFORMANCE_ALERT]')
            ));

        $this->processor->process($data);

        $this->assertEquals('2026-07-01 12:00:00', $existingCustomer->getLicenseExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testProcessLicenseCollisionResolvesWithUniqueKey()
    {
        $data = [
            'payment' => ['customer' => 'cus_new_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'kain_1517@hotmail.com',
                'name' => 'Kain Test',
                'mobilePhone' => '5511999999999'
            ]);

        // A primeira chave gerada colide, a segunda é única
        $this->licenseService->expects($this->exactly(2))
            ->method('generateLicense')
            ->willReturnMap([
                ['5511999999999', 'test-salt', 'DUPLICATE-KEY'],
                ['5511999999999_1', 'test-salt', 'UNIQUE-KEY']
            ]);

        // Simula que 'DUPLICATE-KEY' já está com outro cliente
        $collidingCustomer = new Customer('Other User', 'other@example.com', '5511999999999');
        
        $this->customerRepo->expects($this->exactly(2))
            ->method('findByLicenseKey')
            ->willReturnMap([
                ['DUPLICATE-KEY', $collidingCustomer],
                ['UNIQUE-KEY', null]
            ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Security Alert: WhatsApp reuse or license collision attempt'));

        // Verifica que o e-mail recebe a licença correta (única)
        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('kain_1517@hotmail.com', 'UNIQUE-KEY', $this->logger, 'Kain Test')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('UNIQUE-KEY', $savedCustomer->getLicenseKey());
        $this->assertEquals('kain_1517@hotmail.com', $savedCustomer->getEmail());
    }

    public function testProcessLicenseCollisionWithSameEmailResolvesWithUniqueKey()
    {
        $data = [
            'payment' => ['customer' => 'cus_new_123']
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'kain_1517@hotmail.com',
                'name' => 'Kain Test',
                'mobilePhone' => '5511999999999'
            ]);

        // A primeira chave gerada colide, a segunda é única
        $this->licenseService->expects($this->exactly(2))
            ->method('generateLicense')
            ->willReturnMap([
                ['5511999999999', 'test-salt', 'DUPLICATE-KEY'],
                ['5511999999999_1', 'test-salt', 'UNIQUE-KEY']
            ]);

        // Simula que 'DUPLICATE-KEY' já está com outro cliente que possui o MESMO e-mail
        $collidingCustomer = new Customer('Other User with Same Email', 'kain_1517@hotmail.com', '5511999999999');
        
        $this->customerRepo->expects($this->exactly(2))
            ->method('findByLicenseKey')
            ->willReturnMap([
                ['DUPLICATE-KEY', $collidingCustomer],
                ['UNIQUE-KEY', null]
            ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Security Alert: WhatsApp reuse or license collision attempt'));

        // Verifica que o e-mail recebe a licença correta (única)
        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('kain_1517@hotmail.com', 'UNIQUE-KEY', $this->logger, 'Kain Test')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('UNIQUE-KEY', $savedCustomer->getLicenseKey());
        $this->assertEquals('kain_1517@hotmail.com', $savedCustomer->getEmail());
    }

    public function testProcessSuccessCoCreatorPlan()
    {
        $data = [
            'payment' => [
                'customer' => 'cus_123',
                'subscription' => 'sub_cocreator_123',
                'value' => 29.99
            ]
        ];

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'cocreator@example.com',
                'name' => 'Co Creator User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->licenseService->expects($this->once())
            ->method('generateLicense')
            ->willReturn('CO-CREATOR-KEY');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('cocreator@example.com', 'CO-CREATOR-KEY', $this->logger, 'Co Creator User')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->customerRepo->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willReturn(5);

        $this->syncService->expects($this->once())
            ->method('notifySale');

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertEquals('CO-CREATOR', $savedCustomer->getPlan());
        $this->assertEquals('CO-CREATOR-KEY', $savedCustomer->getLicenseKey());
    }

    public function testProcessMonthlyExtensionCoCreatorPlan()
    {
        $data = [
            'payment' => [
                'id' => 'pay_renew_123',
                'customer' => 'cus_123',
                'subscription' => 'sub_cocreator_123',
                'value' => 29.99
            ]
        ];

        $existingCustomer = new Customer('Co Creator User', 'cocreator@example.com', '5511999999999');
        $existingCustomer->markAsPaid('pay_initial_123');
        $existingCustomer->setPlan('CO-CREATOR');
        $existingCustomer->setSubscriptionId('sub_cocreator_123');

        $initialExpiration = new \DateTime('2026-06-01 12:00:00');
        $existingCustomer->setLicenseExpiresAt($initialExpiration);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'cocreator@example.com',
                'name' => 'Co Creator User',
                'mobilePhone' => '5511999999999'
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findBySubscriptionId')
            ->with('sub_cocreator_123')
            ->willReturn($existingCustomer);

        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($existingCustomer);

        $this->logger->expects($this->any())
            ->method('info')
            ->with($this->logicalOr(
                $this->equalTo('CO-CREATOR subscription extended for customer'),
                $this->stringContains('[PERFORMANCE]'),
                $this->stringContains('[PERFORMANCE_ALERT]')
            ));

        $this->processor->process($data);

        $this->assertEquals('2026-07-01 12:00:00', $existingCustomer->getLicenseExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testProcessLifetimeUserTransitionToCoCreatorPlan()
    {
        $data = [
            'payment' => [
                'id' => 'pay_sub_123',
                'customer' => 'cus_123',
                'subscription' => 'sub_transition_123',
                'value' => 29.99
            ]
        ];

        // Cliente existente que já possui o plano LIFETIME e uma chave de licença associada
        $existingCustomer = new Customer('Matheus Gomes', 'matheusprgc@gmail.com', '11982468847');
        $existingCustomer->markAsPaid('pay_lifetime_123');
        $existingCustomer->assignLicense('17F7-937C-AB8B-66DA');
        $existingCustomer->setPlan('LIFETIME');

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email' => 'matheusprgc@gmail.com',
                'name' => 'Matheus Gomes',
                'mobilePhone' => '11982468847'
            ]);

        // Simula que a busca por subscriptionId falha (não existe ainda no banco de dados)
        $this->customerRepo->expects($this->once())
            ->method('findBySubscriptionId')
            ->with('sub_transition_123')
            ->willReturn(null);

        // Mas a busca por e-mail retorna o cliente vitalício existente
        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('matheusprgc@gmail.com')
            ->willReturn($existingCustomer);

        // A licença NÃO deve ser regerada, portanto o emailService enviará o e-mail usando a chave já existente
        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('matheusprgc@gmail.com', '17F7-937C-AB8B-66DA', $this->logger, 'Matheus Gomes')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($customer) use (&$savedCustomer) {
                $savedCustomer = $customer;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer);
        $this->assertSame($existingCustomer, $savedCustomer);
        $this->assertEquals('CO-CREATOR', $savedCustomer->getPlan());
        $this->assertEquals('LIFETIME', $savedCustomer->getFallbackPlan());
        $this->assertEquals('sub_transition_123', $savedCustomer->getSubscriptionId());
        $this->assertEquals('17F7-937C-AB8B-66DA', $savedCustomer->getLicenseKey());
    }

    public function testProcessDuplicatePaymentSkipped()
    {
        $data = [
            'payment' => [
                'id' => 'pay_duplicate_123',
                'customer' => 'cus_123'
            ]
        ];

        // Configura o mock do repositório de auditoria para indicar que este pagamento já foi processado
        $this->auditRepo->expects($this->once())
            ->method('hasPaymentBeenProcessed')
            ->with('pay_duplicate_123')
            ->willReturn(true);

        // O gateway NÃO deve ser chamado, pois o processamento deve ser interrompido imediatamente
        $this->gateway->expects($this->never())
            ->method('getCustomerInfo');

        // O repositório de clientes NÃO deve salvar nada
        $this->customerRepo->expects($this->never())
            ->method('save');

        $this->logger->expects($this->any())
            ->method('info');

        $this->processor->process($data);
    }

    /**
     * Cenário real do payload de produção:
     * Usuário LIFETIME compra o plano CO-CREATOR via link de pagamento Pix.
     * O webhook da Asaas NÃO inclui campo 'subscription' neste caso.
     * O sistema deve detectar que é uma transição de plano (valor = 29.99),
     * e NÃO bloquear como pagamento duplicado.
     *
     * @see payload: {"event":"PAYMENT_RECEIVED","payment":{"value":29.99,"billingType":"PIX","paymentLink":"klrl3t1zt1g37pow"}}
     */
    public function testProcessLifetimeUserBuysCoCreatorViaPixPaymentLink(): void
    {
        // Payload real — sem campo 'subscription', valor 29.99
        $data = [
            'payment' => [
                'id'          => 'pay_llojnn4uth61ulju',
                'customer'    => 'cus_000008067151',
                'value'       => 29.99,
                'billingType' => 'PIX',
                'paymentLink' => 'klrl3t1zt1g37pow',
                'dueDate'     => '2026-06-02',
                // SEM campo 'subscription' — este é o cenário do bug
            ],
        ];

        $existingCustomer = new Customer('MATHEUS SANTANA GOMES', 'matheusprgc@gmail.com', '11982468847');
        $existingCustomer->markAsPaid('pay_lifetime_original');
        $existingCustomer->assignLicense('17F7-937C-AB8B-66DA');
        $existingCustomer->setPlan('LIFETIME');

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email'       => 'matheusprgc@gmail.com',
                'name'        => 'MATHEUS SANTANA GOMES',
                'mobilePhone' => '11982468847',
            ]);

        // Sem subscriptionId → busca só por email
        $this->customerRepo->expects($this->never())
            ->method('findBySubscriptionId');

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->with('matheusprgc@gmail.com')
            ->willReturn($existingCustomer);

        // E-mail deve ser enviado com a licença existente (não gera nova)
        $this->licenseService->expects($this->never())
            ->method('generateLicense');

        $this->emailService->expects($this->once())
            ->method('sendLicenseEmail')
            ->with('matheusprgc@gmail.com', '17F7-937C-AB8B-66DA', $this->logger, 'MATHEUS SANTANA GOMES')
            ->willReturn(true);

        $savedCustomer = null;
        $this->customerRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($c) use (&$savedCustomer) {
                $savedCustomer = $c;
                return true;
            }));

        $this->processor->process($data);

        $this->assertNotNull($savedCustomer, 'Cliente deve ser salvo após transição de plano.');
        $this->assertSame($existingCustomer, $savedCustomer);
        $this->assertEquals('CO-CREATOR', $savedCustomer->getPlan(), 'Plano deve ser CO-CREATOR após transição.');
        $this->assertEquals('LIFETIME', $savedCustomer->getFallbackPlan(), 'FallbackPlan deve preservar LIFETIME.');
        $this->assertEquals('17F7-937C-AB8B-66DA', $savedCustomer->getLicenseKey(), 'Licença existente deve ser mantida.');
        $this->assertNull($savedCustomer->getSubscriptionId(), 'Sem subscriptionId no payload, campo deve ser null.');
        $this->assertNotNull($savedCustomer->getLicenseExpiresAt(), 'Deve ter data de expiração (dueDate + 1 mês + 3 dias).');
        $this->assertEquals('2026-07-05', $savedCustomer->getLicenseExpiresAt()->format('Y-m-d'));
    }

    /**
     * Um usuário LIFETIME que paga o valor vitalício novamente (49.99)
     * sem subscriptionId deve ser bloqueado como pagamento duplicado.
     */
    public function testProcessLifetimeDoublePaymentIsBlocked(): void
    {
        $data = [
            'payment' => [
                'id'       => 'pay_duplicate_lifetime',
                'customer' => 'cus_000008066988',
                'value'    => 49.99,
                // SEM subscription — pagamento avulso do mesmo plano LIFETIME
            ],
        ];

        $existingCustomer = new Customer('MATHEUS SANTANA GOMES', 'matheusprgc@gmail.com', '11982468847');
        $existingCustomer->markAsPaid('pay_original_lifetime');
        $existingCustomer->assignLicense('17F7-937C-AB8B-66DA');
        $existingCustomer->setPlan('LIFETIME');

        $this->auditRepo->expects($this->once())
            ->method('hasPaymentBeenProcessed')
            ->with('pay_duplicate_lifetime')
            ->willReturn(false);

        $this->gateway->expects($this->once())
            ->method('getCustomerInfo')
            ->willReturn([
                'email'       => 'matheusprgc@gmail.com',
                'name'        => 'MATHEUS SANTANA GOMES',
                'mobilePhone' => '11982468847',
            ]);

        $this->customerRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn($existingCustomer);

        // Em caso de pagamento duplicado, salva auditoria e retorna imediatamente
        $this->customerRepo->expects($this->once())
            ->method('save');

        // Gateway de e-mail NÃO deve ser acionado
        $this->emailService->expects($this->never())
            ->method('sendLicenseEmail');

        $this->processor->process($data);

        // Plano permanece LIFETIME — nenhuma transição ocorre
        $this->assertEquals('LIFETIME', $existingCustomer->getPlan());
    }
}


