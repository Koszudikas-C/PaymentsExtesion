<?php

namespace App\Config;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use App\Interfaces\PaymentGatewayInterface;
use App\Interfaces\EmailServiceInterface;
use App\Interfaces\LicenseServiceInterface;
use App\Interfaces\LandingPageSyncServiceInterface;
use App\Services\AsaasService;
use App\Services\PHPMailerService;
use App\Services\LicenseService;
use App\Services\LandingPageSyncService;
use App\Handlers\WebhookHandler;
use App\Handlers\Processors\PaymentReceivedProcessor;
use App\Factories\WebhookProcessorFactory;
use App\Services\DiscordService;
use App\Interfaces\DiscordServiceInterface;
use App\Command\SyncLogsCommand;

class Container
{
    public static function build(array $definitions = []): ContainerInterface
    {
        $containerBuilder = new ContainerBuilder();

        $baseDefinitions = [
            // Configurações (como o appsettings.json do .NET)
            'settings' => [
                'license_salt' => $_ENV['LICENSE_SALT'],
                'asaas' => [
                    'url' => $_ENV['BASE_URL_ASAAS'],
                    'token' => $_ENV['ASAAS_ACCESS_TOKEN'],
                    'app_name' => $_ENV['NAME_APP'],
                    'payment_link_id' => $_ENV['ASAAS_PAYMENT_LINK_ID'] ?? '',
                    'monthly_value' => (float) ($_ENV['MONTHLY_VALUE'] ?? 19.90),
                    'monthly_name' => $_ENV['MONTHLY_NAME'] ?? 'AIFreelas - Assinatura Mensal',
                ],
                'mail' => [
                    'host' => $_ENV['MAIL_SMTP_HOST'],
                    'username' => $_ENV['MAIL_USER'],
                    'password' => $_ENV['MAIL_PASS'],
                    'port' => $_ENV['MAIL_SMTP_PORT'],
                ],
                'db' => [
                    'driver'   => $_ENV['DB_DRIVER'],
                    'host'     => $_ENV['DB_HOST'],
                    'dbname'   => $_ENV['DB_NAME'],
                    'user'     => $_ENV['DB_USER'],
                    'password' => $_ENV['DB_PASS'],
                ],
                'landing_page' => [
                    'webhook_url' => $_ENV['LANDING_PAGE_WEBHOOK_URL'] ?? '',
                ],
                'campaign' => [
                    'target' => (int)($_ENV['CAMPAIGN_TARGET'] ?? 100),
                ],
                'discord' => [
                    'bot_token' => $_ENV['DISCORD_BOT_TOKEN'] ?? '',
                    'channel_id' => $_ENV['DISCORD_LOG_CHANNEL_ID'] ?? '',
                ]
            ],

            // Registro de Serviços
            \Doctrine\ORM\EntityManagerInterface::class => function (ContainerInterface $c) {
                $settings = $c->get('settings');
                $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(
                    [__DIR__ . '/../Entity'],
                    $_ENV['APP_ENV'] === 'development'
                );

                $connection = \Doctrine\DBAL\DriverManager::getConnection($settings['db'], $config);
                return new \Doctrine\ORM\EntityManager($connection, $config);
            },

            Logger::class => function (ContainerInterface $c) {
                $log = new Logger('payments');
                
                // Handler 1: Log Rotativo (Histórico para auditoria e Sync CLI)
                $fileHandler = new RotatingFileHandler(__DIR__ . '/../../logs/app.log', 30, Logger::DEBUG);
                $fileHandler->setFormatter(new \Monolog\Formatter\JsonFormatter());
                $log->pushHandler($fileHandler);

                // Handler 2: Discord (Alerta em tempo real para Erros Críticos)
                // Usamos Level::Error para disparar para ERROR, CRITICAL, ALERT e EMERGENCY
                $discordService = $c->get(DiscordServiceInterface::class);
                $discordHandler = new \App\Services\DiscordLogHandler($discordService, \Monolog\Level::Error);
                $log->pushHandler($discordHandler);

                return $log;
            },

            'logger.retry' => function (ContainerInterface $c) {
                $log = new Logger('retry_task');
                
                // Handler 1: Arquivo separado para Retry
                $fileHandler = new RotatingFileHandler(__DIR__ . '/../../logs/retry.log', 7, Logger::DEBUG);
                $fileHandler->setFormatter(new \Monolog\Formatter\JsonFormatter());
                $log->pushHandler($fileHandler);

                // Handler 2: Discord (Apenas Crítico para o Retry)
                $discordService = $c->get(DiscordServiceInterface::class);
                $discordHandler = new \App\Services\DiscordLogHandler($discordService, \Monolog\Level::Critical);
                $log->pushHandler($discordHandler);

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

            LandingPageSyncServiceInterface::class => \DI\autowire(LandingPageSyncService::class)
                ->constructorParameter('webhookUrl', \DI\get('settings.landing_page.webhook_url')),

            DiscordServiceInterface::class => \DI\autowire(DiscordService::class)
                ->constructorParameter('botToken', \DI\get('settings.discord.bot_token'))
                ->constructorParameter('channelId', \DI\get('settings.discord.channel_id')),

            // Repositories
            \App\Interfaces\Repositories\CustomerRepositoryInterface::class => function (ContainerInterface $c) {
                return $c->get(\Doctrine\ORM\EntityManagerInterface::class)->getRepository(\App\Entity\Customer::class);
            },

            \App\Interfaces\Repositories\AuditLogRepositoryInterface::class => function (ContainerInterface $c) {
                return $c->get(\Doctrine\ORM\EntityManagerInterface::class)->getRepository(\App\Entity\AuditLog::class);
            },

            \App\Interfaces\PromotionServiceInterface::class => \DI\autowire(\App\Services\PromotionService::class)
                ->constructorParameter('paymentLinkId', \DI\get('settings.asaas.payment_link_id'))
                ->constructorParameter('monthlyValue', \DI\get('settings.asaas.monthly_value'))
                ->constructorParameter('monthlyName', \DI\get('settings.asaas.monthly_name')),

            // Processadores de Webhook
            PaymentReceivedProcessor::class => \DI\autowire()
                ->constructorParameter('licenseSalt', \DI\get('settings.license_salt')),

            // Fábricas
            WebhookProcessorFactory::class => \DI\autowire(),

            // Handlers
            WebhookHandler::class => \DI\autowire(),

            // Controllers
            \App\Controllers\CheckoutController::class => \DI\autowire()
                ->constructorParameter('settings', \DI\get('settings')),

            \App\Controllers\WebhookController::class => \DI\autowire(),

            \App\Controllers\VerificationController::class => \DI\autowire(),

            \App\Controllers\CampaignController::class => \DI\autowire()
                ->constructorParameter('target', \DI\get('settings.campaign.target')),

            \App\Controllers\HealthCheckController::class => \DI\autowire(),

            // Comandos CLI
            SyncLogsCommand::class => \DI\autowire()
                ->constructorParameter('logDir', __DIR__ . '/../../logs'),

            // Atalhos (Shortcuts)
            'settings.license_salt' => function (ContainerInterface $c) {
                return $c->get('settings')['license_salt'];
            },
            'settings.landing_page.webhook_url' => function (ContainerInterface $c) {
                return $c->get('settings')['landing_page']['webhook_url'];
            },
            'settings.campaign.target' => function (ContainerInterface $c) {
                return $c->get('settings')['campaign']['target'];
            },
            'settings.discord.bot_token' => function (ContainerInterface $c) {
                return $c->get('settings')['discord']['bot_token'];
            },
            'settings.discord.channel_id' => function (ContainerInterface $c) {
                return $c->get('settings')['discord']['channel_id'];
            },
            'settings.asaas.payment_link_id' => function (ContainerInterface $c) {
                return $c->get('settings')['asaas']['payment_link_id'];
            },
            'settings.asaas.monthly_value' => function (ContainerInterface $c) {
                return $c->get('settings')['asaas']['monthly_value'];
            },
            'settings.asaas.monthly_name' => function (ContainerInterface $c) {
                return $c->get('settings')['asaas']['monthly_name'];
            },
        ];

        $containerBuilder->addDefinitions(array_merge($baseDefinitions, $definitions));

        return $containerBuilder->build();
    }
}
