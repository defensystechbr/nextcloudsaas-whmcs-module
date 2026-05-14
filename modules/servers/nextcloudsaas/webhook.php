<?php
/**
 * Webhook endpoint para callbacks do nextcloud-saas-manager v12+.
 *
 * Recebe POST do servidor remoto ao concluir um job assíncrono e atualiza
 * o estado do serviço no WHMCS (Custom Fields + tblhosting.domainstatus
 * + tblorders.status quando aplicável).
 *
 * URL pública:
 *   https://<whmcs>/modules/servers/nextcloudsaas/webhook.php
 *     ?service=<id>&job=<uuid>&token=<hmac>
 *
 * Payload esperado (Content-Type: application/json):
 *   {
 *     "job_id":      "550e8400-...",
 *     "state":       "done" | "failed" | "cancelled",
 *     "cmd":         "create" | "backup" | "remove" | ...,
 *     "client":      "<client-slug>",
 *     "exit_code":   0,
 *     "finished_at": "2026-05-14T12:34:56Z"
 *   }
 *
 * Segurança:
 *   - Token HMAC obrigatório (HMAC-SHA256 sobre "service|job_id", secret
 *     em tblconfiguration `NextcloudSaaSWebhookSecret`).
 *   - Constant-time comparison (hash_equals).
 *   - Apenas método POST aceito.
 *   - Rejeita IPs RFC 1918 no Origin do callback? Não — o request vem do
 *     servidor remoto (público) e atinge o WHMCS público; basta validar token.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    3.2.0
 */

// Bootstrap do WHMCS — carrega Capsule, configuração e local API.
$initFile = __DIR__ . '/../../../init.php';
if (!file_exists($initFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'whmcs_init_missing']);
    exit;
}
require_once $initFile;

// Carregar autoloader do módulo (Helper, JobTracker)
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
require_once __DIR__ . '/lib/Helper.php';
require_once __DIR__ . '/lib/JobTracker.php';

use NextcloudSaaS\Helper;
use NextcloudSaaS\JobTracker;

header('Content-Type: application/json');

// 1. Aceitar apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// 2. Extrair query params
$serviceId = isset($_GET['service']) ? (int) $_GET['service'] : 0;
$jobIdQs   = isset($_GET['job']) ? trim((string) $_GET['job']) : '';
$token     = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($serviceId <= 0 || $jobIdQs === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_params']);
    exit;
}

if (!JobTracker::isValidUuidV4($jobIdQs)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_job_id']);
    exit;
}

// 3. Validar token HMAC
if (!JobTracker::verifyCallbackToken($serviceId, $jobIdQs, $token)) {
    Helper::log('webhook', [
        'service' => $serviceId,
        'job'     => $jobIdQs,
    ], ['error' => 'invalid_token']);
    http_response_code(403);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

// 4. Ler payload JSON
$body = file_get_contents('php://input');
$payload = $body === '' ? null : json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$jobIdBody = isset($payload['job_id']) ? trim((string) $payload['job_id']) : '';
$state     = isset($payload['state']) ? strtolower(trim((string) $payload['state'])) : '';
$cmd       = isset($payload['cmd']) ? trim((string) $payload['cmd']) : '';
$client    = isset($payload['client']) ? trim((string) $payload['client']) : '';
$exitCode  = isset($payload['exit_code']) ? (int) $payload['exit_code'] : null;
$finishedAt = isset($payload['finished_at']) ? trim((string) $payload['finished_at']) : gmdate('c');

// Job_id da query e do body devem casar
if ($jobIdBody !== '' && strcasecmp($jobIdBody, $jobIdQs) !== 0) {
    http_response_code(400);
    echo json_encode(['error' => 'job_id_mismatch']);
    exit;
}

$validStates = ['done', 'failed', 'cancelled', 'running'];
if (!in_array($state, $validStates, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_state']);
    exit;
}

// 5. Persistir tracking
JobTracker::persistJob($serviceId, [
    'job_id'      => $jobIdQs,
    'state'       => $state,
    'cmd'         => $cmd,
    'finished_at' => $finishedAt,
]);

// 6. Quando state == done, ativar serviço + Order se ainda Pending
if ($state === 'done' && $exitCode === 0) {
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->where('domainstatus', 'Pending')
                ->update(['domainstatus' => 'Active']);
        }

        // Aceitar Order pendente via local API (reuso da função do hook)
        if (function_exists('nextcloudsaas_acceptOrderForService')) {
            nextcloudsaas_acceptOrderForService($serviceId, [
                'reason' => 'webhook_job_done',
                'job_id' => $jobIdQs,
            ]);
        }
    } catch (\Exception $e) {
        Helper::log('webhook_activate_failed', [
            'service' => $serviceId,
            'job'     => $jobIdQs,
        ], ['error' => $e->getMessage()]);
    }
}

// 7. Logar e responder 200
Helper::log('webhook_received', [
    'service'   => $serviceId,
    'job'       => $jobIdQs,
    'state'     => $state,
    'cmd'       => $cmd,
    'exit_code' => $exitCode,
    'client'    => $client,
]);

http_response_code(200);
echo json_encode(['received' => true, 'service' => $serviceId, 'job' => $jobIdQs, 'state' => $state]);
