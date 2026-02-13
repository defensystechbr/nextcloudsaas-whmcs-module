<?php
/**
 * Classe utilitária para o módulo Nextcloud-SaaS
 *
 * Contém funções auxiliares para geração de passwords, validação
 * de domínios, formatação de quotas e outras utilidades comuns.
 * Alinhado com a arquitetura real do manage.sh v10.0 que utiliza
 * Traefik, 10 containers por instância e 3 domínios DNS.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.0.0
 */

namespace NextcloudSaaS;

class Helper
{
    /**
     * Diretório base das instâncias no servidor
     */
    const BASE_DIR = '/opt/nextcloud-customers';

    /**
     * Caminho do script manage.sh no servidor
     */
    const MANAGE_SCRIPT = '/opt/nextcloud-customers/manage.sh';

    /**
     * Lista de sufixos dos 10 containers por instância
     */
    const CONTAINER_SUFFIXES = [
        'app', 'db', 'redis', 'collabora', 'turn',
        'cron', 'harp', 'nats', 'janus', 'signaling',
    ];

    /**
     * Gerar uma password segura aleatória
     * Usa o mesmo padrão do manage.sh (25 caracteres alfanuméricos)
     *
     * @param int $length Comprimento da password (default: 25, igual ao manage.sh)
     * @return string Password gerada
     */
    public static function generatePassword($length = 25)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * Validar formato de domínio
     *
     * @param string $domain Domínio a validar
     * @return bool
     */
    public static function isValidDomain($domain)
    {
        return (bool) preg_match(
            '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
            $domain
        );
    }

    /**
     * Derivar o domínio do Collabora a partir do domínio principal
     * Padrão do manage.sh: collabora-DOMAIN (ex: collabora-nextcloud.cliente.com.br)
     *
     * @param string $domain Domínio principal do Nextcloud
     * @return string Domínio do Collabora
     */
    public static function getCollaboraDomain($domain)
    {
        return 'collabora-' . $domain;
    }

    /**
     * Derivar o domínio do Signaling (HPB) a partir do domínio principal
     * Padrão do manage.sh: signaling-DOMAIN (ex: signaling-nextcloud.cliente.com.br)
     *
     * @param string $domain Domínio principal do Nextcloud
     * @return string Domínio do Signaling
     */
    public static function getSignalingDomain($domain)
    {
        return 'signaling-' . $domain;
    }

    /**
     * Obter os 3 domínios DNS necessários para uma instância
     *
     * @param string $domain Domínio principal
     * @return array Lista dos 3 domínios
     */
    public static function getRequiredDomains($domain)
    {
        return [
            'nextcloud'  => $domain,
            'collabora'  => self::getCollaboraDomain($domain),
            'signaling'  => self::getSignalingDomain($domain),
        ];
    }

    /**
     * Converter quota em formato legível
     *
     * @param int $bytes Quota em bytes
     * @return string Quota formatada (ex: "5.2 GB")
     */
    public static function formatQuota($bytes)
    {
        if ($bytes <= 0) {
            return 'Ilimitado';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }

    /**
     * Converter quota de texto para formato Nextcloud
     *
     * @param string $quota Quota em formato legível (ex: "5", "10")
     * @return string Quota no formato Nextcloud (ex: "5 GB")
     */
    public static function formatQuotaForNextcloud($quota)
    {
        $quota = trim($quota);

        if (preg_match('/\d+\s*(GB|MB|TB|KB)/i', $quota)) {
            return $quota;
        }

        if (is_numeric($quota)) {
            return $quota . ' GB';
        }

        if (in_array(strtolower($quota), ['none', 'unlimited', 'ilimitado', '0'])) {
            return 'none';
        }

        return $quota;
    }

    /**
     * Registar uma mensagem no log do módulo WHMCS
     *
     * @param string $action  Ação realizada
     * @param mixed  $request Dados do pedido
     * @param mixed  $response Dados da resposta
     * @param mixed  $data    Dados adicionais
     */
    public static function log($action, $request = '', $response = '', $data = '')
    {
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'nextcloudsaas',
                $action,
                is_array($request) ? json_encode($request) : $request,
                is_array($response) ? json_encode($response) : $response,
                is_array($data) ? json_encode($data) : $data
            );
        }
    }

    /**
     * Obter a configuração do servidor a partir dos parâmetros WHMCS
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return array Configuração do servidor
     */
    public static function getServerConfig($params)
    {
        return [
            'hostname'   => isset($params['serverhostname']) ? $params['serverhostname'] : '',
            'ip'         => isset($params['serverip']) ? $params['serverip'] : '',
            'username'   => isset($params['serverusername']) ? $params['serverusername'] : 'defensys',
            'password'   => isset($params['serverpassword']) ? $params['serverpassword'] : '',
            'accesshash' => isset($params['serveraccesshash']) ? $params['serveraccesshash'] : '',
            'secure'     => isset($params['serversecure']) ? $params['serversecure'] : false,
            'port'       => isset($params['serverport']) ? (int) $params['serverport'] : 22,
        ];
    }

    /**
     * Obter a configuração do produto a partir dos parâmetros WHMCS
     *
     * Alinhado com as ConfigOptions definidas em nextcloudsaas_ConfigOptions():
     *   configoption1 = Quota de Armazenamento (GB)
     *   configoption2 = Máximo de Utilizadores
     *   configoption3 = Collabora Online (yesno)
     *   configoption4 = Nextcloud Talk HPB (yesno)
     *   configoption5 = Caminho da Chave SSH
     *   configoption6 = Prefixo do Nome do Cliente
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return array Configuração do produto
     */
    public static function getProductConfig($params)
    {
        return [
            'disk_quota_gb'     => isset($params['configoption1']) ? $params['configoption1'] : '10',
            'max_users'         => isset($params['configoption2']) ? $params['configoption2'] : '5',
            'enable_collabora'  => isset($params['configoption3']) ? $params['configoption3'] : 'on',
            'enable_talk'       => isset($params['configoption4']) ? $params['configoption4'] : 'on',
            'ssh_key_path'      => isset($params['configoption5']) ? $params['configoption5'] : '',
            'client_prefix'     => isset($params['configoption6']) ? $params['configoption6'] : '',
        ];
    }

    /**
     * Parsear a saída do manage.sh para extrair credenciais
     * O manage.sh gera um ficheiro .credentials com formato textual
     *
     * @param string $output Saída do script
     * @return array Dados parseados
     */
    public static function parseManageOutput($output)
    {
        $data = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, '[') === 0) {
                continue;
            }
            // Capturar linhas com formato "Senha:   VALOR" ou "URL: VALOR"
            if (preg_match('/^\s*(Senha|Usuário|URL|Porta|Secret):\s*(.+)$/i', $line, $matches)) {
                $key = strtolower(trim($matches[1]));
                $data[$key] = trim($matches[2]);
            }
            // Capturar linhas KEY=VALUE
            if (strpos($line, '=') !== false && !preg_match('/^\[/', $line)) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $data[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        return $data;
    }

    /**
     * Parsear o ficheiro .credentials de uma instância
     * O manage.sh gera este ficheiro com informações de todas as credenciais
     *
     * @param string $credentialsContent Conteúdo do ficheiro .credentials
     * @return array Credenciais parseadas
     */
    public static function parseCredentials($credentialsContent)
    {
        $creds = [
            'nextcloud_url'      => '',
            'nextcloud_user'     => '',
            'nextcloud_pass'     => '',
            'collabora_url'      => '',
            'collabora_pass'     => '',
            'db_host'            => '',
            'db_name'            => 'nextcloud',
            'db_user'            => 'nextcloud',
            'db_password'        => '',
            'db_root_password'   => '',
            'turn_secret'        => '',
            'turn_port'          => '',
            'turn_address'       => '',
            'signaling_url'      => '',
            'signaling_secret'   => '',
            'harp_shared_key'    => '',
        ];

        $lines = explode("\n", $credentialsContent);
        $section = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Detetar secções
            if (strpos($line, 'Nextcloud:') === 0) { $section = 'nextcloud'; continue; }
            if (strpos($line, 'Collabora') === 0) { $section = 'collabora'; continue; }
            if (strpos($line, 'Banco de Dados') === 0) { $section = 'db'; continue; }
            if (strpos($line, 'TURN') === 0) { $section = 'turn'; continue; }
            if (strpos($line, 'Signaling') === 0) { $section = 'signaling'; continue; }
            if (strpos($line, 'HaRP') === 0) { $section = 'harp'; continue; }

            // Parsear campos — usar strpos para evitar problemas com UTF-8/acentos
            // Extrair valor após ":" em cada linha
            $colonPos = strpos($line, ':');
            if ($colonPos === false) continue;

            $fieldName = trim(substr($line, 0, $colonPos));
            $fieldValue = trim(substr($line, $colonPos + 1));

            // Mapear campos por secção
            switch ($fieldName) {
                case 'URL':
                    if ($section === 'nextcloud') $creds['nextcloud_url'] = $fieldValue;
                    if ($section === 'collabora') $creds['collabora_url'] = $fieldValue;
                    if ($section === 'signaling') $creds['signaling_url'] = $fieldValue;
                    break;
                case 'Admin':
                    // Collabora admin user — ignorar (sempre 'admin')
                    break;
                case 'Host':
                    if ($section === 'db') $creds['db_host'] = $fieldValue;
                    break;
                case 'Database':
                    if ($section === 'db') $creds['db_name'] = $fieldValue;
                    break;
                case 'Root Password':
                    if ($section === 'db') $creds['db_root_password'] = $fieldValue;
                    break;
                case 'Secret':
                    if ($section === 'turn') $creds['turn_secret'] = $fieldValue;
                    if ($section === 'signaling') $creds['signaling_secret'] = $fieldValue;
                    break;
                case 'Porta':
                    if ($section === 'turn') $creds['turn_port'] = $fieldValue;
                    break;
                case 'Shared Key':
                    $creds['harp_shared_key'] = $fieldValue;
                    break;
                default:
                    // Campos com acentos UTF-8: Usuário, Senha, Endereço
                    // Usar mb_stripos se disponível, senão stripos como fallback
                    $striposFunc = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
                    if ($striposFunc($fieldName, 'usu') === 0 || $fieldName === 'Usuario') {
                        if ($section === 'nextcloud') $creds['nextcloud_user'] = $fieldValue;
                        if ($section === 'db') $creds['db_user'] = $fieldValue;
                    } elseif ($striposFunc($fieldName, 'Senha') === 0 || $fieldName === 'Senha') {
                        if ($section === 'nextcloud') $creds['nextcloud_pass'] = $fieldValue;
                        if ($section === 'collabora') $creds['collabora_pass'] = $fieldValue;
                        if ($section === 'db') $creds['db_password'] = $fieldValue;
                    } elseif ($striposFunc($fieldName, 'Endere') === 0 || $fieldName === 'Endereco') {
                        if ($section === 'turn') $creds['turn_address'] = $fieldValue;
                    }
                    break;
            }
        }

        return $creds;
    }

    /**
     * Obter os nomes dos 10 containers de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array Lista de nomes de containers
     */
    public static function getContainerNames($clientName)
    {
        $containers = [];
        foreach (self::CONTAINER_SUFFIXES as $suffix) {
            $containers[$suffix] = $clientName . '-' . $suffix;
        }
        return $containers;
    }
}
