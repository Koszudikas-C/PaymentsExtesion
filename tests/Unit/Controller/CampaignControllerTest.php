<?php

namespace Tests\Unit\Controller;

use App\Controllers\CampaignController;
use App\Interfaces\Repositories\CustomerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Monolog\Logger;

class CampaignControllerTest extends TestCase
{
    private CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject $repository;
    private Logger|\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
    }

    public function testGetStatsSuccess()
    {
        $controller = new CampaignController($this->repository, $this->logger, 100);

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
        $controller = new CampaignController($this->repository, $this->logger, 0);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Campaign target not configured or invalid'));

        ob_start();
        $controller->getStats();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('error', $data['status']);
    }
}
