<?php
declare(strict_types=1);

namespace NextcloudSaaS\Tests;

use NextcloudSaaS\JobTracker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NextcloudSaaS\JobTracker
 */
final class JobTrackerTest extends TestCase
{
    public function test_generateUuidV4_returns_valid_uuid_v4(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $uuid = JobTracker::generateUuidV4();
            $this->assertTrue(
                JobTracker::isValidUuidV4($uuid),
                "UUID gerado deve ser v4 lowercase válido: $uuid"
            );
        }
    }

    public function test_isValidUuidV4_rejects_uppercase(): void
    {
        $this->assertFalse(
            JobTracker::isValidUuidV4('550E8400-E29B-41D4-A716-446655440000'),
            'UUIDs em uppercase devem ser rejeitados (contrato v12 exige lowercase).'
        );
    }

    public function test_isValidUuidV4_rejects_invalid_version(): void
    {
        $this->assertFalse(
            JobTracker::isValidUuidV4('550e8400-e29b-31d4-a716-446655440000'),
            'UUID v3 deve ser rejeitado (manager v12 só aceita v4).'
        );
    }

    public function test_isValidUuidV4_rejects_missing_hyphens(): void
    {
        $this->assertFalse(
            JobTracker::isValidUuidV4('550e8400e29b41d4a716446655440000'),
            'UUID sem hífens deve ser rejeitado.'
        );
    }

    public function test_makeCallbackToken_is_deterministic_for_same_inputs(): void
    {
        $a = JobTracker::makeCallbackToken(42, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $b = JobTracker::makeCallbackToken(42, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $this->assertSame($a, $b, 'HMAC com mesmas entradas deve produzir o mesmo token.');
    }

    public function test_makeCallbackToken_differs_for_different_services(): void
    {
        $a = JobTracker::makeCallbackToken(42, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $b = JobTracker::makeCallbackToken(43, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $this->assertNotSame($a, $b);
    }

    public function test_verifyCallbackToken_constant_time(): void
    {
        $token = JobTracker::makeCallbackToken(7, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
        $this->assertTrue(JobTracker::verifyCallbackToken(7, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $token));
        $this->assertFalse(JobTracker::verifyCallbackToken(7, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $token . 'x'));
        $this->assertFalse(JobTracker::verifyCallbackToken(7, 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', $token));
        $this->assertFalse(JobTracker::verifyCallbackToken(8, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $token));
    }

    public function test_buildCallbackUrl_returns_empty_for_private_ip(): void
    {
        $url = JobTracker::buildCallbackUrl(
            1,
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'https://192.168.1.10/whmcs'
        );
        $this->assertSame('', $url, 'URLs com IPs RFC 1918 devem ser rejeitadas (manager rejeita também).');
    }

    public function test_buildCallbackUrl_returns_empty_for_localhost(): void
    {
        $url = JobTracker::buildCallbackUrl(
            1,
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'https://localhost/whmcs'
        );
        $this->assertSame('', $url);
    }

    public function test_buildCallbackUrl_returns_https_with_token_for_public_host(): void
    {
        $url = JobTracker::buildCallbackUrl(
            7,
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'https://billing.example.com'
        );
        $this->assertStringStartsWith('https://billing.example.com/modules/servers/nextcloudsaas/webhook.php', $url);
        $this->assertStringContainsString('service=7', $url);
        $this->assertStringContainsString('job=aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $url);
        $this->assertStringContainsString('token=', $url);
    }

    public function test_buildCallbackUrl_upgrades_http_to_https(): void
    {
        $url = JobTracker::buildCallbackUrl(
            1,
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            'http://billing.example.com'
        );
        $this->assertStringStartsWith('https://billing.example.com/', $url);
    }
}
