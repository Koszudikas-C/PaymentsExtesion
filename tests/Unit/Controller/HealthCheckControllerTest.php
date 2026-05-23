<?php

namespace Tests\Unit\Controller;

use App\Controllers\HealthCheckController;
use PHPUnit\Framework\TestCase;

class HealthCheckControllerTest extends TestCase
{
    public function testCheck()
    {
        $controller = new HealthCheckController();

        ob_start();
        $controller->check();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('ok', $data['status']);
    }
}
