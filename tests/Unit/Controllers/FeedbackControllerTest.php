<?php

namespace Tests\Unit\Controllers;

use App\Controllers\FeedbackController;
use App\Entity\Customer;
use App\Entity\Feedback;
use App\Interfaces\DiscordServiceInterface;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use App\Interfaces\Repositories\FeedbackRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class FeedbackControllerTest extends TestCase
{
    private FeedbackRepositoryInterface|MockObject $feedbackRepository;
    private CustomerRepositoryInterface|MockObject $customerRepository;
    private DiscordServiceInterface|MockObject $discordService;
    private FeedbackController $controller;

    protected function setUp(): void
    {
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = '';
        $this->feedbackRepository = $this->createMock(FeedbackRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->discordService = $this->createMock(DiscordServiceInterface::class);

        $this->controller = new FeedbackController(
            $this->feedbackRepository,
            $this->customerRepository,
            $this->discordService
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        @stream_wrapper_restore('php');
    }

    public function testHandleRequestInvalidMethod()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEquals(405, http_response_code());
        $this->assertStringContainsString('Method not allowed', $output);
    }

    public function testHandleRequestInvalidJson()
    {
        // Ao rodar pelo CLI, php://input é vazio por padrão
        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        $this->assertEquals(400, http_response_code());
        $this->assertStringContainsString('Invalid JSON payload', $output);
    }

    public function testHandleRequestSuccess()
    {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        file_put_contents('php://input', json_encode([
            'type' => 'FEATURE_REQUEST',
            'message' => 'Add dark mode',
            'rating' => 5,
            'customer_id' => 1
        ]));

        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn('1');
        $customer->method('getName')->willReturn('Test User');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->customerRepository->method('findById')->willReturn($customer);

        $this->feedbackRepository->expects($this->once())->method('save');
        $this->discordService->expects($this->once())->method('sendEmbed');

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        stream_wrapper_restore('php');

        $this->assertEquals(201, http_response_code());
        $this->assertStringContainsString('Feedback received', $output);
    }

    public function testHandleRequestSuccessWithIdentifierEmail()
    {
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode([
            'message' => 'Email feedback',
            'identifier' => 'test@example.com'
        ]);

        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn('1');

        $this->customerRepository->method('findById')->willReturn(null);
        $this->customerRepository->method('findByEmail')->with('test@example.com')->willReturn($customer);
        $this->customerRepository->expects($this->never())->method('findByLicenseKey');

        $this->feedbackRepository->expects($this->once())->method('save');

        ob_start();
        $this->controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(201, http_response_code());
    }

    public function testHandleRequestSuccessWithIdentifierLicenseKey()
    {
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode([
            'message' => 'License feedback',
            'identifier' => 'lic_123'
        ]);

        $customer = $this->createMock(Customer::class);

        $this->customerRepository->method('findById')->willReturn(null);
        $this->customerRepository->method('findByEmail')->with('lic_123')->willReturn(null);
        $this->customerRepository->method('findByLicenseKey')->with('lic_123')->willReturn($customer);

        $this->feedbackRepository->expects($this->once())->method('save');

        ob_start();
        $this->controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(201, http_response_code());
    }

    public function testHandleRequestAnonymous()
    {
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode([
            'message' => '  ', // Should fallback to "Empty"
        ]);

        $this->customerRepository->method('findById')->willReturn(null);
        $this->customerRepository->method('findByEmail')->willReturn(null);

        $this->feedbackRepository->expects($this->once())->method('save');
        // Discord service should still send embed for anonymous
        $this->discordService->expects($this->once())->method('sendEmbed');

        ob_start();
        $this->controller->handleRequest();
        ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(201, http_response_code());
    }

    public function testHandleRequestException()
    {
        @stream_wrapper_unregister('php');
        stream_wrapper_register('php', \Tests\Unit\Helpers\PhpStreamWrapperMock::class);
        \Tests\Unit\Helpers\PhpStreamWrapperMock::$buffer = json_encode([
            'message' => 'Error trigger'
        ]);

        $this->feedbackRepository->method('save')->willThrowException(new \Exception('DB Error'));

        ob_start();
        $this->controller->handleRequest();
        $output = ob_get_clean();

        @stream_wrapper_restore('php');
        $this->assertEquals(500, http_response_code());
        $this->assertStringContainsString('Internal server error', $output);
    }
}
