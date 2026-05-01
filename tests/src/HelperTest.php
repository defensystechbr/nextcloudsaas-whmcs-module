<?php
declare(strict_types=1);

namespace NextcloudSaaS\Tests;

use NextcloudSaaS\Helper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NextcloudSaaS\Helper
 */
final class HelperTest extends TestCase
{
    public function test_getRequiredDomains_returns_only_main_domain_in_shared_architecture(): void
    {
        $domain = 'cliente.example.com';
        $result = Helper::getRequiredDomains($domain);

        $this->assertIsArray($result);
        $this->assertCount(
            1,
            $result,
            'Na arquitetura compartilhada v3.0.0 cada cliente exige apenas 1 registro DNS.'
        );
        $this->assertSame($domain, $result['nextcloud']);
    }

    public function test_getCollaboraDomain_returns_global_hostname_independent_of_client(): void
    {
        $hostnameA = Helper::getCollaboraDomain('clienteA.example.com');
        $hostnameB = Helper::getCollaboraDomain('clienteB.outra.com.br');

        $this->assertNotEmpty($hostnameA);
        $this->assertSame(
            $hostnameA,
            $hostnameB,
            'A v3.0.0 deve devolver o MESMO hostname global de Collabora independentemente do dominio do cliente.'
        );
        $this->assertStringNotContainsString(
            'clienteA',
            $hostnameA,
            'O hostname global nao pode conter o subdominio do cliente.'
        );
    }

    public function test_getSignalingDomain_returns_global_hostname_independent_of_client(): void
    {
        $hostnameA = Helper::getSignalingDomain('clienteA.example.com');
        $hostnameB = Helper::getSignalingDomain('clienteB.outra.com.br');

        $this->assertNotEmpty($hostnameA);
        $this->assertSame(
            $hostnameA,
            $hostnameB,
            'A v3.0.0 deve devolver o MESMO hostname global de Signaling independentemente do dominio do cliente.'
        );
        $this->assertStringNotContainsString(
            'clienteA',
            $hostnameA,
            'O hostname global nao pode conter o subdominio do cliente.'
        );
    }

public function test_getSharedHostnames_returns_three_public_endpoints(): void
    {
        $hostnames = Helper::getSharedHostnames();

        // São exatamente os 3 hostnames públicos expostos pelo Traefik.
        foreach (['collabora', 'signaling', 'turn'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $hostnames,
                "getSharedHostnames() deve incluir o endpoint público $key (manager v11.x)."
            );
            $this->assertNotEmpty($hostnames[$key]);
        }
    }

    public function test_SHARED_CONTAINERS_lists_eight_global_services(): void
    {
        $expected = [
            'shared-db', 'shared-redis', 'shared-collabora', 'shared-turn',
            'shared-nats', 'shared-janus', 'shared-signaling', 'shared-recording',
        ];
        $this->assertSame(
            $expected,
            Helper::SHARED_CONTAINERS,
            'A lista SHARED_CONTAINERS deve listar os 8 servicos globais shared-* introduzidos pelo manager v11.x.'
        );
    }

    public function test_CONTAINER_SUFFIXES_lists_three_per_client(): void
    {
        $this->assertSame(
            ['app', 'cron', 'harp'],
            Helper::CONTAINER_SUFFIXES,
            'Cada cliente deve ter exatamente 3 containers dedicados (app, cron, harp).'
        );
    }
}
