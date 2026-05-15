<?php

namespace App\Config;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Services\AsaasService;
use App\Services\PHPMailerService;
use App\Services\LicenseService;
use App\Handlers\WebhookHandler;

class Container
{
    public static function build(): ContainerInterface
    {
        $containerBuilder = new ContainerBuilder();

        $containerBuilder->addDefinitions([
            // Configurações (como o appsettings.json do .NET)
            'settings' => [
                'license_salt' => $_ENV['LICENSE_SALT'],
                'asaas' => [
                    'url' => $_ENV['BASE_URL_ASAAS'],
                    'token' => $_ENV['ASAAS_ACCESS_TOKEN'],
                    'app_name' => $_ENV['NAME_APP'],
                ],
                'mail' => [
                    'host' => $_ENV['MAIL_SMTP_HOST'],
                    'username' => $_ENV['MAIL_USER'],
                    'password' => $_ENV['MAIL_PASS'],
                    'port' => $_ENV['MAIL_SMTP_PORT'],
                ]
            ],

            // Registro de Serviços (equivalente ao ConfigureServices do .NET)
            
            Logger::class => function () {
                $log = new Logger('payments');
                $log->pushHandler(new RotatingFileHandler(__DIR__ . '/../../logs/app.log', 30, Logger::DEBUG));
                return $log;
            },

            PaymentGatewayInterface::class => function (ContainerInterface $c) {
                $settings = $c->get('settings')['asaas'];
                return new AsaasService(
                    $settings['url'],
                    $settings['token'],
                    $settings['app_name']
                );
            },

            EmailServiceInterface::class => function (ContainerInterface $c) {
                return new PHPMailerService($c->get('settings')['mail']);
            },

            LicenseServiceInterface::class => \DI\create(LicenseService::class),

            WebhookHandler::class => \DI\autowire()
                ->constructorParameter('licenseSalt', \DI\get('settings.license_salt')),
            
            // Atalho para o sal (opcional)
            'settings.license_salt' => \DI\get('settings.license_salt'),
        ]);

        return $containerBuilder->build();
    }
}
