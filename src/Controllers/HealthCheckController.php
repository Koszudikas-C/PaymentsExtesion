<?php

declare(strict_types=1);

namespace App\Controllers;

class HealthCheckController
{
    public function check(): void
    {
        header_remove('X-Powered-By');

        header('Content-Type: application/json; charset=utf-8');
        
        // Retorna apenas 200 OK com corpo mínimo
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    }
}

