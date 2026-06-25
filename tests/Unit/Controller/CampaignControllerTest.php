<?php

namespace Tests\Unit\Controller;

use App\Controllers\CampaignController;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class CampaignControllerTest extends TestCase
{
    private CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $repository;
    private \App\Interfaces\Services\AuthTokenServiceInterface|\PHPUnit\Framework\MockObject\MockObject $authTokenService;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->authTokenService = $this->createMock(\App\Interfaces\Services\AuthTokenServiceInterface::class);
        $this->authTokenService->method('isOriginAllowedForBypass')->willReturn(true);
        $this->logger = $this->createMock(Logger::class);
    }

    public function testGetStatsSuccess()
    {
        $controller = new CampaignController($this->repository, $this->authTokenService, $this->logger, 100);

        $this->repository->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willReturn(25);

        ob_start();
        $controller->getStats();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals(25, $data['data']['count']);
        $this->assertEquals(100, $data['data']['target']);
        $this->assertEquals(25, $data['data']['percentage']);
    }

    public function testGetStatsErrorInvalidTarget()
    {
        $controller = new CampaignController($this->repository, $this->authTokenService, $this->logger, 0);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Campaign target not configured or invalid'));

        ob_start();
        $controller->getStats();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('error', $data['status']);
    }

    public function testGetStatsOptionsRequest()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller = new CampaignController($this->repository, $this->authTokenService, $this->logger, 100);

        ob_start();
        $controller->getStats();
        $output = ob_get_clean();

        $this->assertEmpty($output);
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testGetStatsException()
    {
        $controller = new CampaignController($this->repository, $this->authTokenService, $this->logger, 100);

        $this->repository->expects($this->once())
            ->method('countPaidLifetimeCustomers')
            ->willThrowException(new \Exception('DB Error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error in CampaignController::getStats'));

        ob_start();
        $controller->getStats();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals(500, http_response_code());
    }
}
