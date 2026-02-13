<?php
/**
 * Módulo Nextcloud-SaaS para WHMCS
 *
 * Módulo de provisionamento que permite gerir instâncias Nextcloud
 * como produto SaaS dentro do WHMCS. Integra-se diretamente com o
 * manage.sh v10.0 existente no servidor de destino, que gere instâncias
 * com 10 containers cada (app, db, redis, collabora, turn, cron,
 * harp, nats, janus, signaling) atrás de um proxy Traefik com SSL
 * automático via Let's Encrypt.
 *
 * Cada instância requer 3 registros DNS:
 *   - dominio.com.br (Nextcloud)
 *   - collabora-dominio.com.br (Collabora Online)
 *   - signaling-dominio.com.br (HPB Signaling)
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.3.3
 * @license    Proprietary
 *
 * @see https://developers.whmcs.com/provisioning-modules/
 * @see https://docs.nextcloud.com/server/stable/admin_manual/
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

// Carregar as bibliotecas do módulo
require_once __DIR__ . '/lib/NextcloudAPI.php';
require_once __DIR__ . '/lib/SSHManager.php';
require_once __DIR__ . '/lib/Helper.php';

use NextcloudSaaS\NextcloudAPI;
use NextcloudSaaS\SSHManager;
use NextcloudSaaS\Helper;

// =============================================================================
// METADADOS DO MÓDULO
// =============================================================================

/**
 * Definir metadados do módulo.
 *
 * @return array
 */
function nextcloudsaas_MetaData()
{
    return [
        'DisplayName'              => 'Nextcloud SaaS',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => 'Aceder ao Nextcloud',
        'AdminSingleSignOnLabel'   => 'Aceder como Admin',
    ];
}

// =============================================================================
// OPÇÕES DE CONFIGURAÇÃO DO PRODUTO
// =============================================================================

/**
 * Definir opções de configuração do produto.
 *
 * Estas opções são apresentadas ao administrador ao configurar um produto
 * que utiliza este módulo. Os valores ficam disponíveis em todas as funções
 * do módulo como configoption1, configoption2, etc.
 *
 * @return array
 */
function nextcloudsaas_ConfigOptions()
{
    return [
        // configoption1
        'Quota de Armazenamento (GB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '10',
            'Description' => 'Quota de disco em GB para o utilizador admin da instância',
        ],
        // configoption2
        'Máximo de Utilizadores' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '5',
            'Description' => 'Número máximo de utilizadores permitidos na instância',
        ],
        // configoption3
        'Collabora Online' => [
            'Type'        => 'yesno',
            'Default'     => 'on',
            'Description' => 'Ativar Collabora Online (sempre ativo na arquitetura atual)',
        ],
        // configoption4
        'Nextcloud Talk (HPB)' => [
            'Type'        => 'yesno',
            'Default'     => 'on',
            'Description' => 'Ativar Talk com HPB (NATS + Janus + Signaling)',
        ],
        // configoption5
        'Caminho da Chave SSH' => [
            'Type'        => 'text',
            'Size'        => '60',
            'Default'     => '',
            'Description' => 'Caminho para chave SSH privada no servidor WHMCS (vazio = usar password do servidor)',
        ],
        // configoption6
        'Prefixo do Nome do Cliente' => [
            'Type'        => 'text',
            'Size'        => '20',
            'Default'     => '',
            'Description' => 'Prefixo opcional para o nome do cliente no manage.sh (ex: "nc-")',
        ],
    ];
}

// =============================================================================
// FUNÇÕES AUXILIARES INTERNAS
// =============================================================================

/**
 * Criar instância do SSHManager a partir dos parâmetros WHMCS
 *
 * @param array $params Parâmetros do módulo
 * @return SSHManager
 */
function nextcloudsaas_getSSHManager($params)
{
    return SSHManager::fromWhmcsParams($params);
}

/**
 * Criar instância do NextcloudAPI a partir dos parâmetros WHMCS
 *
 * Tenta obter a password real do admin a partir do ficheiro .credentials
 * via SSH. Se não conseguir, usa o $params['password'] como fallback.
 *
 * @param array  $params Parâmetros do módulo
 * @param string $domain Domínio da instância Nextcloud (opcional)
 * @return NextcloudAPI
 */
function nextcloudsaas_getNextcloudAPI($params, $domain = '')
{
    if (empty($domain)) {
        $domain = isset($params['domain']) ? $params['domain'] : '';
    }

    $baseUrl = 'https://' . $domain;

    // Utilizador admin é sempre 'admin'
    $adminUser = 'admin';
    $adminPass = isset($params['password']) ? $params['password'] : '';

    // Tentar obter a password real do .credentials via SSH
    try {
        $clientName = nextcloudsaas_getClientName($params);
        if (!empty($clientName)) {
            $ssh = nextcloudsaas_getSSHManager($params);
            $credsResult = $ssh->getCredentials($clientName);
            if ($credsResult['success'] && !empty($credsResult['credentials']['nextcloud_pass'])) {
                $adminPass = $credsResult['credentials']['nextcloud_pass'];
            }
        }
    } catch (\Exception $e) {
        // Silenciar — usar fallback $params['password']
    }

    return new NextcloudAPI($baseUrl, $adminUser, $adminPass);
}

/**
 * Derivar o nome do cliente para o manage.sh a partir dos parâmetros WHMCS.
 * O manage.sh usa o nome do cliente como identificador de diretório.
 *
 * @param array $params Parâmetros do módulo
 * @return string Nome do cliente sanitizado
 */
function nextcloudsaas_getClientName($params)
{
    $prefix = isset($params['configoption6']) ? trim($params['configoption6']) : '';
    $domain = isset($params['domain']) ? $params['domain'] : '';

    // Usar o campo customfield 'Client Name' se existir
    if (!empty($params['customfields']['Client Name'])) {
        return $params['customfields']['Client Name'];
    }

    // Derivar do domínio: ex. nextcloud.cliente.com.br → cliente
    // Ou usar o serviceid como fallback
    if (!empty($domain)) {
        // Remover TLD e subdomínios comuns
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            // Pegar a parte mais significativa
            $name = $parts[0];
            if (in_array($name, ['nextcloud', 'cloud', 'nc', 'nuvem', 'www'])) {
                $name = isset($parts[1]) ? $parts[1] : $name;
            }
        } else {
            $name = $parts[0];
        }
        // Sanitizar: apenas letras minúsculas, números e hífens
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        if (!empty($name)) {
            return $prefix . $name;
        }
    }

    // Fallback: usar service ID
    return $prefix . 'whmcs-' . $params['serviceid'];
}

// =============================================================================
// FUNÇÕES PRINCIPAIS DO CICLO DE VIDA
// =============================================================================

/**
 * Provisionar uma nova instância Nextcloud.
 *
 * Executa o manage.sh com o comando 'create' no servidor de destino.
 * O manage.sh cria automaticamente 10 containers, configura SSL via
 * Traefik/Let's Encrypt, instala apps essenciais e executa 16 passos
 * de pós-instalação.
 *
 * Pré-requisitos (devem ser comunicados ao cliente):
 *   - 3 registros DNS A apontando para o IP do servidor:
 *     1. dominio.com.br
 *     2. collabora-dominio.com.br
 *     3. signaling-dominio.com.br
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_CreateAccount(array $params)
{
    try {
        $domain = isset($params['domain']) ? trim($params['domain']) : '';
        $productConfig = Helper::getProductConfig($params);

        // Validar domínio
        if (empty($domain) || !Helper::isValidDomain($domain)) {
            return "Domínio inválido ou não fornecido: {$domain}";
        }

        // Derivar nome do cliente para o manage.sh
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Não foi possível derivar o nome do cliente.";
        }

        Helper::log('CreateAccount', [
            'clientName' => $clientName,
            'domain'     => $domain,
            'quota'      => $productConfig['disk_quota_gb'],
            'maxUsers'   => $productConfig['max_users'],
        ]);

        // Conectar ao servidor via SSH e executar manage.sh create
        $ssh = nextcloudsaas_getSSHManager($params);

        // Verificar se o manage.sh existe
        $verify = $ssh->verifyManageScript();
        if (!$verify['exists']) {
            return "O script manage.sh não foi encontrado no servidor em {$verify['path']}. "
                 . "Verifique se o Nextcloud SaaS está instalado corretamente.";
        }

        // Criar a instância
        $result = $ssh->createInstance($clientName, $domain);

        Helper::log('CreateAccount_result', [
            'clientName' => $clientName,
            'domain'     => $domain,
        ], $result);

        if (!$result['success']) {
            return "Erro ao criar instância Nextcloud: " . $result['error'];
        }

        // Ler as credenciais geradas pelo manage.sh
        $credsResult = $ssh->getCredentials($clientName);
        $envResult = $ssh->getEnv($clientName);

        $adminUser = 'admin';
        $adminPass = '';
        $collaboraDomain = Helper::getCollaboraDomain($domain);
        $signalingDomain = Helper::getSignalingDomain($domain);

        if (isset($credsResult['credentials'])) {
            $creds = $credsResult['credentials'];
            $adminUser = !empty($creds['nextcloud_user']) ? $creds['nextcloud_user'] : 'admin';
            $adminPass = !empty($creds['nextcloud_pass']) ? $creds['nextcloud_pass'] : '';
        }

        if (isset($envResult['env'])) {
            $env = $envResult['env'];
            if (!empty($env['NEXTCLOUD_ADMIN_PASSWORD'])) {
                $adminPass = $env['NEXTCLOUD_ADMIN_PASSWORD'];
            }
        }

        // Atualizar os campos do serviço no WHMCS com as credenciais
        if (function_exists('localAPI')) {
            $serverConfig = Helper::getServerConfig($params);
            $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

            localAPI('UpdateClientProduct', [
                'serviceid'       => $params['serviceid'],
                'serviceusername' => $adminUser,
                'servicepassword' => $adminPass,
                'domain'          => $domain,
                'dedicatedip'     => $serverIp,
            ]);
        }

        // Guardar campos personalizados
        nextcloudsaas_saveCustomField($params['serviceid'], 'Client Name', $clientName);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Nextcloud URL', 'https://' . $domain);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Collabora URL', 'https://' . $collaboraDomain);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Signaling URL', 'https://' . $signalingDomain);

        // Aplicar quota ao utilizador admin se definida
        if (!empty($productConfig['disk_quota_gb']) && $productConfig['disk_quota_gb'] !== 'none') {
            $quota = Helper::formatQuotaForNextcloud($productConfig['disk_quota_gb']);
            // Aguardar um pouco para o Nextcloud estar totalmente pronto
            // A quota será aplicada via occ
            $ssh->setUserQuota($clientName, $adminUser, $quota);
        }

    } catch (\Exception $e) {
        Helper::log('CreateAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Suspender uma instância Nextcloud.
 *
 * Executa manage.sh stop para parar todos os 10 containers da instância.
 * Os dados são preservados nos volumes Docker.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_SuspendAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->stopInstance($clientName);

        Helper::log('SuspendAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao suspender instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('SuspendAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Reativar uma instância Nextcloud previamente suspensa.
 *
 * Executa manage.sh start para reiniciar todos os 10 containers.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_UnsuspendAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->startInstance($clientName);

        Helper::log('UnsuspendAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao reativar instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('UnsuspendAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminar uma instância Nextcloud.
 *
 * Executa manage.sh remove para remover todos os containers, volumes
 * e dados da instância. Esta ação é IRREVERSÍVEL.
 * Recomenda-se fazer backup antes via manage.sh backup.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_TerminateAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);

        // Fazer backup antes de remover
        Helper::log('TerminateAccount', ['clientName' => $clientName], 'Fazendo backup antes de remover...');
        $ssh->backupInstance($clientName);

        // Remover a instância
        $result = $ssh->removeInstance($clientName);

        Helper::log('TerminateAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao terminar instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('TerminateAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Renovar uma instância Nextcloud.
 *
 * Verifica se a instância está ativa e reinicia se necessário.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_Renew(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'success'; // Não é erro crítico
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->statusInstance($clientName);

        Helper::log('Renew', ['clientName' => $clientName], $result);

        // Se a instância não está a correr, tentar iniciar
        if ($result['success'] && strpos($result['output'], 'running') === false) {
            $ssh->startInstance($clientName);
        }

    } catch (\Exception $e) {
        Helper::log('Renew', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// GESTÃO DE PASSWORDS E PACOTES
// =============================================================================

/**
 * Alterar a password do utilizador admin da instância Nextcloud.
 *
 * Usa o comando occ via SSH para alterar a password diretamente
 * no container Nextcloud.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_ChangePassword(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $username = isset($params['username']) ? $params['username'] : 'admin';
        $newPassword = isset($params['password']) ? $params['password'] : '';

        if (empty($clientName) || empty($newPassword)) {
            return "Nome do cliente ou nova password não fornecidos.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);

        // Tentar via API OCS primeiro (mais rápido)
        $domain = isset($params['domain']) ? $params['domain'] : '';
        if (!empty($domain)) {
            try {
                $ncApi = nextcloudsaas_getNextcloudAPI($params);
                $result = $ncApi->changeUserPassword($username, $newPassword);
                if ($result['success']) {
                    Helper::log('ChangePassword', ['clientName' => $clientName, 'method' => 'API'], 'success');
                    return 'success';
                }
            } catch (\Exception $e) {
                // Fallback para SSH
            }
        }

        // Fallback: alterar via occ no container
        $result = $ssh->changeUserPassword($clientName, $username, $newPassword);

        Helper::log('ChangePassword', [
            'clientName' => $clientName,
            'username'   => $username,
            'method'     => 'SSH/occ',
        ], $result);

        if (!$result['success']) {
            return "Erro ao alterar password: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('ChangePassword', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Alterar o pacote/plano da instância (upgrade/downgrade).
 *
 * Ajusta a quota de armazenamento do utilizador admin via occ.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_ChangePackage(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $productConfig = Helper::getProductConfig($params);
        $username = isset($params['username']) ? $params['username'] : 'admin';

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $quota = Helper::formatQuotaForNextcloud($productConfig['disk_quota_gb']);

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->setUserQuota($clientName, $username, $quota);

        Helper::log('ChangePackage', [
            'clientName' => $clientName,
            'quota'      => $quota,
            'maxUsers'   => $productConfig['max_users'],
        ], $result);

        if (!$result['success']) {
            return "Erro ao alterar pacote: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('ChangePackage', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// TESTE DE CONEXÃO
// =============================================================================

/**
 * Testar a conexão com o servidor.
 *
 * Invocada quando o administrador clica em "Test Connection" na
 * página de configuração do servidor no WHMCS.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array ['success' => bool, 'error' => string]
 */
function nextcloudsaas_TestConnection(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);

        // Testar conexão SSH
        $sshTest = $ssh->testConnection();
        if (!$sshTest['success']) {
            return [
                'success' => false,
                'error'   => $sshTest['message'],
            ];
        }

        // Verificar se o manage.sh existe
        $manageTest = $ssh->verifyManageScript();
        if (!$manageTest['exists']) {
            return [
                'success' => false,
                'error'   => "Conexão SSH OK, mas manage.sh não encontrado em {$manageTest['path']}",
            ];
        }

        // Verificar Docker
        $dockerTest = $ssh->verifyDocker();
        if (!$dockerTest['running']) {
            return [
                'success' => false,
                'error'   => 'Conexão SSH OK, mas Docker não está a correr no servidor.',
            ];
        }

        // Verificar Traefik
        $traefikTest = $ssh->verifyTraefik();
        if (!$traefikTest['running']) {
            return [
                'success' => false,
                'error'   => 'Conexão SSH OK, Docker OK, mas Traefik não está a correr.',
            ];
        }

        return ['success' => true, 'error' => ''];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

// =============================================================================
// SINGLE SIGN-ON (SSO)
// =============================================================================

/**
 * SSO para o painel do utilizador (cliente).
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_ServiceSingleSignOn(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($domain)) {
            return [
                'success'  => false,
                'errorMsg' => 'Domínio não encontrado para este serviço.',
            ];
        }

        return [
            'success'    => true,
            'redirectTo' => 'https://' . $domain . '/login',
        ];

    } catch (\Exception $e) {
        return [
            'success'  => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

/**
 * SSO para o painel de administração.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_AdminSingleSignOn(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($domain)) {
            return [
                'success'  => false,
                'errorMsg' => 'Domínio não encontrado para este serviço.',
            ];
        }

        return [
            'success'    => true,
            'redirectTo' => 'https://' . $domain . '/settings/admin',
        ];

    } catch (\Exception $e) {
        return [
            'success'  => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

// =============================================================================
// BOTÕES PERSONALIZADOS (ADMIN E CLIENTE)
// =============================================================================

/**
 * Definir botões personalizados para a área de administração.
 *
 * @return array
 */
function nextcloudsaas_AdminCustomButtonArray()
{
    return [
        'Verificar Estado'       => 'checkStatus',
        'Reiniciar Instância'    => 'restartInstance',
        'Fazer Backup'           => 'backupInstance',
        'Atualizar Instância'    => 'updateInstance',
        'Testar Conexão SSH'     => 'testSSH',
        'Testar API Nextcloud'   => 'testAPI',
        'Ver Credenciais'        => 'viewCredentials',
        'Ver Logs'               => 'viewLogs',
    ];
}

/**
 * Definir botões personalizados para a área de cliente.
 *
 * @return array
 */
function nextcloudsaas_ClientAreaCustomButtonArray()
{
    return [
        'Verificar Estado'    => 'checkStatus',
        'Reiniciar Instância' => 'restartInstance',
    ];
}

/**
 * Verificar o estado da instância Nextcloud.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_checkStatus(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->statusInstance($clientName);

        Helper::log('checkStatus', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return 'Erro ao verificar estado: ' . $result['error'];
        }

        $output = trim($result['output']);
        $runningCount = substr_count(strtolower($output), 'running');
        $totalContainers = 10;

        $header = "=== ESTADO DA INSTÂNCIA: {$clientName} ===";
        $header .= "\nContainers ativos: {$runningCount}/{$totalContainers}\n\n";

        // Retornar estado detalhado — o WHMCS exibe como mensagem ao admin
        return $header . $output;

    } catch (\Exception $e) {
        return 'Exceção ao verificar estado: ' . $e->getMessage();
    }
}

/**
 * Reiniciar a instância Nextcloud (stop + start).
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_restartInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);

        // Parar
        $ssh->stopInstance($clientName);
        sleep(3);
        // Iniciar
        $result = $ssh->startInstance($clientName);

        Helper::log('restartInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao reiniciar: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Fazer backup da instância via manage.sh.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_backupInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->backupInstance($clientName);

        Helper::log('backupInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao fazer backup: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Atualizar a instância (pull + upgrade) via manage.sh.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_updateInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->updateInstance($clientName);

        Helper::log('updateInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao atualizar: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Testar a conexão SSH com o servidor.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_testSSH(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->testConnection();

        Helper::log('testSSH', [], $result);

        if (!$result['success']) {
            return $result['message'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Testar a conexão com a API do Nextcloud.
 *
 * Obtém a password real do admin a partir do .credentials via SSH
 * e testa a comunicação com a API OCS da instância Nextcloud.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_testAPI(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';
        if (empty($domain)) {
            return 'Domínio não configurado para este serviço.';
        }

        $ncApi = nextcloudsaas_getNextcloudAPI($params);
        $result = $ncApi->testConnection();

        Helper::log('testAPI', ['domain' => $domain], $result);

        if (!$result['success']) {
            return 'Erro API Nextcloud (' . $domain . '): ' . $result['message'];
        }

    } catch (\Exception $e) {
        return 'Exceção ao testar API: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Ver credenciais da instância.
 *
 * Obtém as credenciais do ficheiro .credentials via SSH e exibe-as
 * ao administrador. No WHMCS, botões personalizados só exibem output
 * quando retornam uma string diferente de "success", por isso os dados
 * são retornados como string formatada.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string Credenciais formatadas ou mensagem de erro
 */
function nextcloudsaas_viewCredentials(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);

        // Obter credenciais do .credentials via SSH
        $credsResult = $ssh->getCredentials($clientName);

        Helper::log('viewCredentials', ['clientName' => $clientName], $credsResult);

        if (!$credsResult['success']) {
            return 'Erro ao obter credenciais: ' . (isset($credsResult['raw']) ? $credsResult['raw'] : 'Ficheiro .credentials não encontrado');
        }

        $creds = $credsResult['credentials'];
        $serverConfig = Helper::getServerConfig($params);
        $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];
        $collaboraDomain = Helper::getCollaboraDomain($domain);
        $signalingDomain = Helper::getSignalingDomain($domain);

        // Formatar credenciais para exibição
        $output = "=== CREDENCIAIS DA INSTÂNCIA: {$clientName} ===\n\n";

        $output .= "--- Nextcloud ---\n";
        $output .= "URL: https://{$domain}\n";
        $output .= "Utilizador: " . (!empty($creds['nextcloud_user']) ? $creds['nextcloud_user'] : 'admin') . "\n";
        $output .= "Password: " . (!empty($creds['nextcloud_pass']) ? $creds['nextcloud_pass'] : '(não disponível)') . "\n\n";

        $output .= "--- Collabora Online ---\n";
        $output .= "URL: https://{$collaboraDomain}\n";
        $output .= "Admin: admin\n";
        $output .= "Password: " . (!empty($creds['collabora_pass']) ? $creds['collabora_pass'] : '(não disponível)') . "\n\n";

        $output .= "--- Base de Dados (MariaDB) ---\n";
        $output .= "Host: " . (!empty($creds['db_host']) ? $creds['db_host'] : $clientName . '-db') . "\n";
        $output .= "Database: " . (!empty($creds['db_name']) ? $creds['db_name'] : 'nextcloud') . "\n";
        $output .= "Utilizador: " . (!empty($creds['db_user']) ? $creds['db_user'] : 'nextcloud') . "\n";
        $output .= "Password: " . (!empty($creds['db_password']) ? $creds['db_password'] : '(não disponível)') . "\n";
        $output .= "Root Password: " . (!empty($creds['db_root_password']) ? $creds['db_root_password'] : '(não disponível)') . "\n\n";

        $output .= "--- TURN Server ---\n";
        $output .= "Secret: " . (!empty($creds['turn_secret']) ? $creds['turn_secret'] : '(não disponível)') . "\n";
        $output .= "Porta: " . (!empty($creds['turn_port']) ? $creds['turn_port'] : '(não disponível)') . "\n";
        $output .= "Endereço: turn:{$serverIp}:" . (!empty($creds['turn_port']) ? $creds['turn_port'] : '?') . "\n\n";

        $output .= "--- Signaling Server ---\n";
        $output .= "URL: https://{$signalingDomain}\n";
        $output .= "Secret: " . (!empty($creds['signaling_secret']) ? $creds['signaling_secret'] : '(não disponível)') . "\n\n";

        $output .= "--- HaRP (AppAPI) ---\n";
        $output .= "Shared Key: " . (!empty($creds['harp_shared_key']) ? $creds['harp_shared_key'] : '(não disponível)') . "\n\n";

        $output .= "--- DNS Necessários ---\n";
        $output .= "{$domain} → {$serverIp}\n";
        $output .= "{$collaboraDomain} → {$serverIp}\n";
        $output .= "{$signalingDomain} → {$serverIp}\n";

        // Retornar como string — o WHMCS exibe como mensagem ao admin
        return $output;

    } catch (\Exception $e) {
        return 'Exceção ao obter credenciais: ' . $e->getMessage();
    }
}

/**
 * Ver logs do container Docker principal (app).
 *
 * Obtém as últimas 50 linhas de logs do container principal
 * da instância Nextcloud via docker logs e exibe-as ao administrador.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string Logs formatados ou mensagem de erro
 */
function nextcloudsaas_viewLogs(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $containerName = $clientName . '-app';
        $result = $ssh->executeCommand(
            "docker logs --tail 50 " . escapeshellarg($containerName) . " 2>&1"
        );

        Helper::log('viewLogs', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return 'Erro ao obter logs: ' . $result['error'];
        }

        $logs = trim($result['output']);

        if (empty($logs)) {
            return "=== LOGS: {$containerName} ===\n\n(Sem logs disponíveis)";
        }

        // Retornar logs formatados — o WHMCS exibe como mensagem ao admin
        return "=== LOGS: {$containerName} (últimas 50 linhas) ===\n\n" . $logs;

    } catch (\Exception $e) {
        return 'Exceção ao obter logs: ' . $e->getMessage();
    }
}

// =============================================================================
// CAMPOS EXTRA NO ADMIN (SERVICES TAB)
// =============================================================================

/**
 * Campos adicionais na aba de serviços do admin.
 *
 * Exibe informações detalhadas sobre a instância Nextcloud na página
 * de gestão do serviço no painel de administração do WHMCS.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_AdminServicesTabFields(array $params)
{
    $fields = [];

    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';
        $clientName = nextcloudsaas_getClientName($params);

        if (!empty($domain)) {
            $collaboraDomain = Helper::getCollaboraDomain($domain);
            $signalingDomain = Helper::getSignalingDomain($domain);

            $fields['Nome da Instância'] = '<strong>' . htmlspecialchars($clientName) . '</strong>';

            $fields['URL do Nextcloud'] = '<a href="https://' . htmlspecialchars($domain)
                . '" target="_blank">https://' . htmlspecialchars($domain) . '</a>';

            $fields['URL do Collabora'] = '<a href="https://' . htmlspecialchars($collaboraDomain)
                . '" target="_blank">https://' . htmlspecialchars($collaboraDomain) . '</a>';

            $fields['URL do Signaling'] = '<a href="https://' . htmlspecialchars($signalingDomain)
                . '" target="_blank">https://' . htmlspecialchars($signalingDomain) . '</a>';

            $fields['DNS Necessários'] = '<code>' . htmlspecialchars($domain) . '</code><br>'
                . '<code>' . htmlspecialchars($collaboraDomain) . '</code><br>'
                . '<code>' . htmlspecialchars($signalingDomain) . '</code>';

            // Tentar obter estado dos containers
            try {
                $ssh = nextcloudsaas_getSSHManager($params);
                $statusResult = $ssh->statusInstance($clientName);

                if ($statusResult['success']) {
                    $output = $statusResult['output'];
                    // Contar containers running
                    $runningCount = substr_count(strtolower($output), 'running');
                    $totalContainers = 10;

                    if ($runningCount >= $totalContainers) {
                        $statusHtml = '<span style="color:green;font-weight:bold;">Ativo ('
                            . $runningCount . '/' . $totalContainers . ' containers)</span>';
                    } elseif ($runningCount > 0) {
                        $statusHtml = '<span style="color:orange;font-weight:bold;">Parcial ('
                            . $runningCount . '/' . $totalContainers . ' containers)</span>';
                    } else {
                        $statusHtml = '<span style="color:red;font-weight:bold;">Parado</span>';
                    }
                    $fields['Estado da Instância'] = $statusHtml;
                }

                // Obter uso de disco
                $diskResult = $ssh->getDiskUsage($clientName);
                if ($diskResult['success']) {
                    $fields['Uso de Disco'] = htmlspecialchars($diskResult['usage']);
                }
            } catch (\Exception $e) {
                $fields['Estado da Instância'] = '<span style="color:orange;">Não disponível</span>';
            }

            // Tentar obter informações via API
            try {
                $ncApi = nextcloudsaas_getNextcloudAPI($params);
                $username = isset($params['username']) ? $params['username'] : 'admin';
                if (!empty($username)) {
                    $storageInfo = $ncApi->getUserStorageInfo($username);
                    if ($storageInfo['success']) {
                        $fields['Armazenamento Usado'] = Helper::formatQuota($storageInfo['data']['used']);
                        $fields['Quota Total'] = Helper::formatQuota($storageInfo['data']['quota']);
                        $fields['Uso (%)'] = $storageInfo['data']['relative'] . '%';
                    }
                }
            } catch (\Exception $e) {
                // Silenciar
            }
        }

    } catch (\Exception $e) {
        $fields['Erro'] = htmlspecialchars($e->getMessage());
    }

    return $fields;
}

// =============================================================================
// ATUALIZAÇÃO DE USO (CRON)
// =============================================================================

/**
 * Atualização diária de uso de disco.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_UsageUpdate(array $params)
{
    try {
        if (!function_exists('localAPI')) {
            return 'success';
        }

        $services = localAPI('GetClientsProducts', [
            'serverid' => $params['serverid'],
            'status'   => 'Active',
        ]);

        if (!isset($services['products']['product'])) {
            return 'success';
        }

        foreach ($services['products']['product'] as $service) {
            try {
                $domain = $service['domain'];
                $username = $service['username'];

                if (empty($domain) || empty($username)) {
                    continue;
                }

                $ncApi = nextcloudsaas_getNextcloudAPI($params, $domain);
                $storageInfo = $ncApi->getUserStorageInfo($username);

                if ($storageInfo['success']) {
                    $usedMB = round($storageInfo['data']['used'] / (1024 * 1024));
                    $quotaMB = round($storageInfo['data']['quota'] / (1024 * 1024));

                    localAPI('UpdateClientProduct', [
                        'serviceid' => $service['id'],
                        'diskusage' => $usedMB,
                        'disklimit' => $quotaMB,
                    ]);
                }
            } catch (\Exception $e) {
                Helper::log('UsageUpdate', ['domain' => $domain], $e->getMessage());
                continue;
            }
        }

    } catch (\Exception $e) {
        Helper::log('UsageUpdate', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// ÁREA DE CLIENTE
// =============================================================================

/**
 * Output da área de cliente.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_ClientArea(array $params)
{
    $domain = isset($params['domain']) ? $params['domain'] : '';
    $username = isset($params['username']) ? $params['username'] : '';
    $clientName = nextcloudsaas_getClientName($params);

    $collaboraDomain = Helper::getCollaboraDomain($domain);
    $signalingDomain = Helper::getSignalingDomain($domain);

    // Obter IP do servidor
    $serverConfig = Helper::getServerConfig($params);
    $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

    // Dados base para o template
    $templateVars = [
        'domain'           => $domain,
        'username'         => $username,
        'clientName'       => $clientName,
        'nextcloudUrl'     => !empty($domain) ? 'https://' . $domain : '',
        'collaboraUrl'     => !empty($domain) ? 'https://' . $collaboraDomain : '',
        'signalingUrl'     => !empty($domain) ? 'https://' . $signalingDomain : '',
        'collaboraDomain'  => $collaboraDomain,
        'signalingDomain'  => $signalingDomain,
        'serverIp'         => $serverIp,
        'serviceid'        => $params['serviceid'],
        'storageUsed'      => 'N/A',
        'storageQuota'     => 'N/A',
        'storagePercent'   => 0,
        'instanceStatus'   => 'Desconhecido',
        'statusColor'      => 'warning',
        'containersTotal'  => 10,
        'containersUp'     => 0,
        // Credenciais do .credentials (preenchidas via SSH)
        'credsRaw'              => '',
        'credsDate'             => '',
        'ncUser'                => 'admin',
        'ncPass'                => '',
        'collaboraAdmin'        => '',
        'collaboraPass'         => '',
        'dbHost'                => '',
        'dbName'                => 'nextcloud',
        'dbUser'                => 'nextcloud',
        'dbPass'                => '',
        'dbRootPass'            => '',
        'turnSecret'            => '',
        'turnPort'              => '',
        'turnAddress'           => '',
        'signalingSecret'       => '',
        'harpSharedKey'         => '',
    ];

    // Tentar obter credenciais completas do .credentials via SSH
    if (!empty($clientName)) {
        try {
            $ssh = nextcloudsaas_getSSHManager($params);

            // Buscar ficheiro .credentials completo
            $credsResult = $ssh->getCredentials($clientName);
            if ($credsResult['success']) {
                $creds = $credsResult['credentials'];
                $templateVars['credsRaw']        = $credsResult['raw'];
                $templateVars['ncUser']          = !empty($creds['nextcloud_user']) ? $creds['nextcloud_user'] : 'admin';
                $templateVars['ncPass']          = !empty($creds['nextcloud_pass']) ? $creds['nextcloud_pass'] : '';
                $templateVars['collaboraAdmin']   = 'admin';
                $templateVars['collaboraPass']    = !empty($creds['collabora_pass']) ? $creds['collabora_pass'] : '';
                $templateVars['dbHost']           = !empty($creds['db_host']) ? $creds['db_host'] : '';
                $templateVars['dbPass']           = !empty($creds['db_password']) ? $creds['db_password'] : '';
                $templateVars['dbRootPass']       = !empty($creds['db_root_password']) ? $creds['db_root_password'] : '';
                $templateVars['turnSecret']       = !empty($creds['turn_secret']) ? $creds['turn_secret'] : '';
                $templateVars['turnPort']         = !empty($creds['turn_port']) ? $creds['turn_port'] : '';
                $templateVars['turnAddress']      = !empty($creds['turn_address']) ? $creds['turn_address'] : (!empty($creds['turn_port']) ? 'turn:' . $serverIp . ':' . $creds['turn_port'] : '');
                $templateVars['signalingSecret']  = !empty($creds['signaling_secret']) ? $creds['signaling_secret'] : '';
                $templateVars['harpSharedKey']    = !empty($creds['harp_shared_key']) ? $creds['harp_shared_key'] : '';

                // Extrair data de criação do raw (usar strpos para evitar problemas UTF-8)
                $striposFunc = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
                foreach (explode("\n", $credsResult['raw']) as $rawLine) {
                    if ($striposFunc($rawLine, 'Data de cria') !== false && strpos($rawLine, ':') !== false) {
                        $colonPos = strpos($rawLine, ':');
                        $templateVars['credsDate'] = trim(substr($rawLine, $colonPos + 1));
                        break;
                    }
                }

                // Usar db_name e db_user do .credentials se disponíveis
                $templateVars['dbName']           = !empty($creds['db_name']) ? $creds['db_name'] : 'nextcloud';
                $templateVars['dbUser']           = !empty($creds['db_user']) ? $creds['db_user'] : 'nextcloud';
            }

            // Obter estado dos containers
            $statusResult = $ssh->statusInstance($clientName);
            if ($statusResult['success']) {
                $output = strtolower($statusResult['output']);
                $runningCount = substr_count($output, 'running');
                $templateVars['containersUp'] = $runningCount;

                if ($runningCount >= 10) {
                    $templateVars['instanceStatus'] = 'Ativo';
                    $templateVars['statusColor'] = 'success';
                } elseif ($runningCount > 0) {
                    $templateVars['instanceStatus'] = 'Parcial';
                    $templateVars['statusColor'] = 'warning';
                } else {
                    $templateVars['instanceStatus'] = 'Parado';
                    $templateVars['statusColor'] = 'danger';
                }
            }

            // Obter uso de disco via SSH (du -sh)
            $diskResult = $ssh->getDiskUsage($clientName);
            if ($diskResult['success'] && $diskResult['usage'] !== 'N/A') {
                $templateVars['storageUsed'] = $diskResult['usage'];
            }
        } catch (\Exception $e) {
            // Silenciar — as credenciais ficam vazias
        }
    }

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables'              => $templateVars,
    ];
}

// =============================================================================
// FUNÇÕES AUXILIARES
// =============================================================================

/**
 * Guardar um valor num campo personalizado do serviço.
 *
 * @param int    $serviceId ID do serviço
 * @param string $fieldName Nome do campo
 * @param string $value     Valor a guardar
 */
function nextcloudsaas_saveCustomField($serviceId, $fieldName, $value)
{
    if (!function_exists('localAPI')) {
        return;
    }

    try {
        $result = localAPI('GetClientsProducts', [
            'serviceid' => $serviceId,
        ]);

        if (isset($result['products']['product'][0]['customfields']['customfield'])) {
            foreach ($result['products']['product'][0]['customfields']['customfield'] as $field) {
                if ($field['name'] === $fieldName) {
                    localAPI('UpdateClientProduct', [
                        'serviceid'    => $serviceId,
                        'customfields' => base64_encode(serialize([$field['id'] => $value])),
                    ]);
                    return;
                }
            }
        }
    } catch (\Exception $e) {
        Helper::log('saveCustomField', [
            'serviceId' => $serviceId,
            'fieldName' => $fieldName,
        ], $e->getMessage());
    }
}

// =============================================================================
// LINKS DE ADMINISTRAÇÃO
// =============================================================================

/**
 * Link de administração do servidor.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string HTML do link
 */
function nextcloudsaas_AdminLink(array $params)
{
    $serverConfig = Helper::getServerConfig($params);
    $host = !empty($serverConfig['hostname']) ? $serverConfig['hostname'] : $serverConfig['ip'];

    return '<a href="https://' . htmlspecialchars($host) . '" target="_blank">'
        . 'Aceder ao Servidor Nextcloud SaaS</a>';
}

/**
 * Link de login do serviço.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string HTML do link
 */
function nextcloudsaas_LoginLink(array $params)
{
    $domain = isset($params['domain']) ? $params['domain'] : '';

    if (empty($domain)) {
        return '';
    }

    return '<a href="https://' . htmlspecialchars($domain) . '/login" target="_blank">'
        . 'Aceder ao Nextcloud do Cliente</a>';
}
