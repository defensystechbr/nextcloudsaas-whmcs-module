<?php
/**
 * JobTracker — Gestão de jobs assíncronos do nextcloud-saas-manager v12+
 *
 * Esta classe encapsula a lógica de gerar e validar UUIDs v4 (para
 * idempotency keys e HMAC tokens), construir URLs de callback HTTPS
 * autodetectadas a partir do WHMCS, e persistir o `job_id` corrente
 * de cada serviço em Custom Fields lidos pelo cron `AfterCronJob`.
 *
 * Convenções de Custom Field (criados automaticamente quando ausentes):
 *   - nextcloud_job_id              UUID do job ativo (vazio quando idle)
 *   - nextcloud_job_state           queued | running | done | failed | cancelled | unknown
 *   - nextcloud_job_cmd             cmd do job (create, backup, remove, ...)
 *   - nextcloud_job_idem_key        idempotency-key usada
 *   - nextcloud_job_started_at      ISO 8601
 *   - nextcloud_job_finished_at     ISO 8601 (preenchido pelo webhook ou cron)
 *
 * Segurança do callback:
 *   - URL inclui ?service=<id>&token=<hmac> onde token = HMAC-SHA256(service|job_id, secret).
 *   - Secret armazenado em tblconfiguration sob key `NextcloudSaaSWebhookSecret`
 *     (gerado automaticamente na primeira chamada e nunca exposto em logs).
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    3.2.0
 */

namespace NextcloudSaaS;

class JobTracker
{
    const CFG_WEBHOOK_SECRET = 'NextcloudSaaSWebhookSecret';
    const CFG_FIELD_JOB_ID         = 'nextcloud_job_id';
    const CFG_FIELD_JOB_STATE      = 'nextcloud_job_state';
    const CFG_FIELD_JOB_CMD        = 'nextcloud_job_cmd';
    const CFG_FIELD_JOB_IDEM       = 'nextcloud_job_idem_key';
    const CFG_FIELD_JOB_STARTED    = 'nextcloud_job_started_at';
    const CFG_FIELD_JOB_FINISHED   = 'nextcloud_job_finished_at';

    /**
     * Gerar um UUID v4 lowercase (compatível com regex do contrato v12).
     *
     * @return string Ex: "550e8400-e29b-41d4-a716-446655440000"
     */
    public static function generateUuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 10
        return strtolower(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
    }

    /**
     * Validar formato UUID v4 lowercase.
     */
    public static function isValidUuidV4($uuid)
    {
        return is_string($uuid) && (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    /**
     * Obter (ou inicializar) o secret de webhook armazenado no WHMCS.
     *
     * Persiste em tblconfiguration. Na primeira chamada, gera um secret
     * de 64 bytes (base64 url-safe) e grava. Nunca retorna vazio.
     *
     * @return string
     */
    public static function getWebhookSecret()
    {
        // Hook para testes: usa um secret fixo determinístico quando a env var
        // NEXTCLOUDSAAS_TEST_SECRET está definida (evita dependência do DB do WHMCS).
        $envSecret = getenv('NEXTCLOUDSAAS_TEST_SECRET');
        if ($envSecret !== false && $envSecret !== '') {
            return (string) $envSecret;
        }

        if (!self::canUseCapsule()) {
            // Fallback: secret derivado do hostname WHMCS (não-ideal mas funcional)
            return hash('sha256', 'nextcloudsaas-fallback-' . php_uname('n'));
        }

        try {
            $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                ->where('setting', self::CFG_WEBHOOK_SECRET)
                ->first();

            if ($row && !empty($row->value)) {
                return (string) $row->value;
            }

            $newSecret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            \WHMCS\Database\Capsule::table('tblconfiguration')->insert([
                'setting' => self::CFG_WEBHOOK_SECRET,
                'value'   => $newSecret,
            ]);
            return $newSecret;
        } catch (\Exception $e) {
            // Em caso de falha, gerar volátil (não persiste, mas o webhook ainda funciona durante a request)
            return hash('sha256', 'nextcloudsaas-' . microtime(true) . '-' . random_bytes(16));
        }
    }

    /**
     * Gerar um token HMAC-SHA256 para validação do callback do servidor.
     *
     * @param int    $serviceId WHMCS service id
     * @param string $jobId     UUID v4 do job
     * @return string Hex 64 chars
     */
    public static function makeCallbackToken($serviceId, $jobId)
    {
        $payload = ((int) $serviceId) . '|' . trim((string) $jobId);
        return hash_hmac('sha256', $payload, self::getWebhookSecret());
    }

    /**
     * Verificar token HMAC contra service+job (constant-time).
     */
    public static function verifyCallbackToken($serviceId, $jobId, $token)
    {
        $expected = self::makeCallbackToken($serviceId, $jobId);
        return is_string($token) && hash_equals($expected, (string) $token);
    }

    /**
     * Construir URL de callback HTTPS para o webhook do módulo.
     *
     * Ordem de precedência para a base URL:
     *   1. Override explícito em $overrideBaseUrl (vindo de configoption7).
     *   2. Setting `SystemURL` em tblconfiguration.
     *   3. Setting `Domain`    em tblconfiguration (com prefixo https://).
     *
     * Retorna string vazia se não conseguir HTTPS público (manager v12
     * rejeita IPs RFC 1918, então não vale a pena enviar URL inválida).
     *
     * @param int    $serviceId
     * @param string $jobId
     * @param string $overrideBaseUrl Opcional (configoption7)
     * @return string URL completa ou '' se inviável
     */
    public static function buildCallbackUrl($serviceId, $jobId, $overrideBaseUrl = '')
    {
        $base = trim((string) $overrideBaseUrl);

        if ($base === '' && self::canUseCapsule()) {
            try {
                $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->whereIn('setting', ['SystemURL', 'Domain'])
                    ->get();
                $byKey = [];
                foreach ($row as $r) {
                    $byKey[$r->setting] = (string) $r->value;
                }
                if (!empty($byKey['SystemURL'])) {
                    $base = $byKey['SystemURL'];
                } elseif (!empty($byKey['Domain'])) {
                    $base = 'https://' . $byKey['Domain'];
                }
            } catch (\Exception $e) {
                // ignore
            }
        }

        if ($base === '') {
            return '';
        }

        // Forçar https
        $base = preg_replace('#^http://#i', 'https://', $base);
        if (strpos($base, 'https://') !== 0) {
            $base = 'https://' . ltrim($base, '/');
        }
        $base = rtrim($base, '/');

        // Rejeitar IPs RFC 1918 (mesmo critério do manager) para evitar
        // que o servidor responda "callback_rejected_private_ip".
        $host = parse_url($base, PHP_URL_HOST);
        if ($host !== null && self::isPrivateOrLocal($host)) {
            return '';
        }

        $token = self::makeCallbackToken($serviceId, $jobId);
        $path  = '/modules/servers/nextcloudsaas/webhook.php';
        return $base . $path
            . '?service=' . (int) $serviceId
            . '&job=' . rawurlencode($jobId)
            . '&token=' . rawurlencode($token);
    }

    /**
     * Helpers ------------------------------------------------------------
     */

    private static function isPrivateOrLocal($host)
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $long = ip2long($host);
            if ($long === false) {
                return false;
            }
            $privateRanges = [
                ['10.0.0.0',    '10.255.255.255'],
                ['172.16.0.0',  '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['127.0.0.0',   '127.255.255.255'],
            ];
            foreach ($privateRanges as $r) {
                if ($long >= ip2long($r[0]) && $long <= ip2long($r[1])) {
                    return true;
                }
            }
            return false;
        }
        // Hostnames sem TLD (".local", "internal") são suspeitos
        if (strpos($host, '.') === false) {
            return true;
        }
        return false;
    }

    private static function canUseCapsule()
    {
        return class_exists('\\WHMCS\\Database\\Capsule');
    }

    /**
     * Persistir tracking de job em Custom Fields do serviço.
     *
     * @param int   $serviceId
     * @param array $fields ['job_id' => ..., 'state' => ..., 'cmd' => ..., 'idem' => ...]
     */
    public static function persistJob($serviceId, array $fields)
    {
        if (!function_exists('nextcloudsaas_saveCustomField')) {
            return;
        }
        $map = [
            'job_id'     => self::CFG_FIELD_JOB_ID,
            'state'      => self::CFG_FIELD_JOB_STATE,
            'cmd'        => self::CFG_FIELD_JOB_CMD,
            'idem'       => self::CFG_FIELD_JOB_IDEM,
            'started_at' => self::CFG_FIELD_JOB_STARTED,
            'finished_at'=> self::CFG_FIELD_JOB_FINISHED,
        ];
        foreach ($fields as $key => $value) {
            if (!isset($map[$key]) || $value === null) {
                continue;
            }
            \nextcloudsaas_saveCustomField($serviceId, $map[$key], (string) $value);
        }
    }
}
