<?php
/**
 * SSH Manager para comunicação com o servidor de destino
 *
 * Esta classe gere a conexão SSH ao servidor onde as instâncias
 * Nextcloud são hospedadas. Integra-se diretamente com o
 * Nextcloud SaaS Manager v11.x (`manage.sh` v11.3+) existente
 * no servidor, que é o script principal de gestão das instâncias
 * em arquitetura compartilhada (3 containers por cliente + 8
 * serviços globais `shared-*`).
 *
 * Utiliza a biblioteca phpseclib3 (PHP puro) como método principal
 * de conexão SSH, sem necessidade de extensões nativas como php-ssh2.
 * Mantém fallback para ssh2 e sshpass como alternativas.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    3.2.0
 */

namespace NextcloudSaaS;

// Carregar autoloader do composer (phpseclib3)
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SSHManager
{
    /**
     * @var string Endereço IP ou hostname do servidor
     */
    private $host;

    /**
     * @var int Porta SSH
     */
    private $port;

    /**
     * @var string Utilizador SSH
     */
    private $username;

    /**
     * @var string Password SSH (se aplicável)
     */
    private $password;

    /**
     * @var string Caminho para a chave SSH privada (se aplicável)
     */
    private $privateKeyPath;

    /**
     * @var string Password da chave SSH (se aplicável)
     */
    private $keyPassphrase;

    /**
     * @var int Timeout da conexão em segundos
     */
    private $timeout;

    /**
     * @var string Caminho base das instâncias no servidor remoto
     */
    private $basePath;

    /**
     * @var string Caminho do manage.sh no servidor remoto
     */
    private $manageScript;

    /**
     * @var string Caminho do nextcloud-manage no servidor (symlink v11.3.4+)
     */
    private $manageBin = '/usr/local/bin/nextcloud-manage';

    /**
     * @var array|null Cache em memória das capabilities detectadas
     *                 [manager_version, supports_async, supports_json,
     *                  supports_health_json, supports_backup_offsite, shim_user]
     */
    private $capabilities = null;

    /**
     * @var bool Se true, o SSH user é o ncsaas-api (shim hardened).
     *           Nesse modo, NÃO usar sudo nem bash -c; chamar nextcloud-manage
     *           direto com argumentos da allowlist.
     */
    private $useShimMode = false;

    /**
     * Construtor da classe SSHManager
     *
     * @param string $host           Endereço do servidor
     * @param string $username       Utilizador SSH
     * @param string $password       Password SSH (ou vazio para usar chave)
     * @param string $privateKeyPath Caminho para a chave privada SSH (ou vazio para usar password)
     * @param int    $port           Porta SSH (default: 22)
     * @param string $keyPassphrase  Passphrase da chave (default: vazio)
     * @param int    $timeout        Timeout em segundos (default: 300 para operações longas)
     * @param string $basePath       Caminho base no servidor (default: /opt/nextcloud-customers)
     */
    public function __construct(
        $host,
        $username,
        $password = '',
        $privateKeyPath = '',
        $port = 22,
        $keyPassphrase = '',
        $timeout = 300,
        $basePath = '/opt/nextcloud-customers'
    ) {
        $this->host = $host;
        // Garante porta válida (1–65535) mesmo que WHMCS passe 0/'' / valor inválido.
        $portInt = (int)$port;
        $this->port = ($portInt >= 1 && $portInt <= 65535) ? $portInt : 22;
        $this->username = $username;
        $this->password = $password;
        $this->privateKeyPath = $privateKeyPath;
        $this->keyPassphrase = $keyPassphrase;
        $this->timeout = $timeout;
        $this->basePath = rtrim($basePath, '/');
        $this->manageScript = $this->basePath . '/manage.sh';
    }

    /**
     * Criar instância de SSHManager a partir dos parâmetros WHMCS
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return self
     */
    public static function fromWhmcsParams($params)
    {
        $server = Helper::getServerConfig($params);
        $product = Helper::getProductConfig($params);

        $host = !empty($server['ip']) ? $server['ip'] : $server['hostname'];
        $username = $server['username'];

        $instance = new self(
            $host,
            $username,
            $server['password'],
            !empty($product['ssh_key_path']) ? $product['ssh_key_path'] : $server['accesshash'],
            (isset($server['port']) && (int)$server['port'] >= 1 && (int)$server['port'] <= 65535) ? (int)$server['port'] : 22,
            '',
            300,
            '/opt/nextcloud-customers'
        );

        // Autodetectar shim mode quando o SSH user é ncsaas-api.
        // Nesse modo, o servidor força comandos através do ncsaas-api-shim
        // que rejeita metacaracteres e exige allowlist de verbos.
        if ($username === 'ncsaas-api') {
            $instance->setShimMode(true);
        }

        return $instance;
    }

    /**
     * Encapsular um comando com sudo para execução não-interativa.
     *
     * Requer que o utilizador SSH tenha NOPASSWD configurado no sudoers
     * do servidor remoto (ficheiro /etc/sudoers.d/defensys-whmcs).
     *
     * Usa sudo -n (non-interactive) que nunca pede password — se o
     * NOPASSWD não estiver configurado, o comando falha imediatamente
     * em vez de ficar à espera de input (o que causaria timeout no phpseclib).
     *
     * @param string $command Comando a executar com sudo
     * @return string Comando encapsulado com sudo -n
     */
    private function wrapWithSudo($command)
    {
        // Modo shim: ncsaas-api NÃO usa sudo nem aceita comandos arbitrários
        // (o shim rejeita metacaracteres). O ForceCommand já faz sudo internamente.
        // Comandos aqui são apenas literais "nextcloud-manage <args>".
        if ($this->useShimMode || $this->username === 'ncsaas-api') {
            return $command;
        }

        // Se o utilizador é root, não precisa de sudo
        if ($this->username === 'root') {
            return $command;
        }

        // Usar sudo -n (non-interactive) — requer NOPASSWD no sudoers
        // Redirecionar stderr do sudo para /dev/null para evitar mensagens
        // como "[sudo] password for ..." no output
        return 'sudo -n ' . $command . ' 2>&1';
    }

    /**
     * Habilita explicitamente o modo shim (ncsaas-api hardened).
     *
     * Em modo shim, o servidor força execução através do
     * /usr/local/bin/ncsaas-api-shim com allowlist de verbos.
     * Comandos que usam bash -c, pipes, redirects, docker exec direto
     * ou cat de arquivos serão rejeitados pelo shim.
     *
     * @param bool $on
     * @return void
     */
    public function setShimMode($on)
    {
        $this->useShimMode = (bool) $on;
    }

    /**
     * Detectar versão e capabilities do nextcloud-saas-manager remoto.
     *
     * Estratégia (cada uma com fallback graceful):
     *   1. `nextcloud-manage health --json` → se retornar JSON válido com
     *      schema_version, então é v12.0+ (supports_async = true, etc.).
     *   2. Se health --json falhar, tenta `nextcloud-manage --help 2>&1 | head -50`
     *      e procura por strings como "--async", "v12", "backup-offsite".
     *   3. Fallback final: lê o cabeçalho do manage.sh procurando por
     *      "# Version:" ou similar.
     *
     * Resultado é cacheado por instância de SSHManager.
     *
     * @return array {
     *   manager_version: string,           // ex: "12.2.0" ou "11.3.4" ou "unknown"
     *   supports_async: bool,
     *   supports_json: bool,
     *   supports_health_json: bool,
     *   supports_backup_offsite: bool,
     *   supports_idempotency: bool,
     *   supports_callback: bool,
     *   shim_user: string,                 // "root" ou "ncsaas-api"
     *   detected_at: string,               // ISO 8601
     * }
     */
    public function detectServerCapabilities()
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $caps = [
            'manager_version'         => 'unknown',
            'supports_async'          => false,
            'supports_json'           => false,
            'supports_health_json'    => false,
            'supports_backup_offsite' => false,
            'supports_idempotency'    => false,
            'supports_callback'       => false,
            'shim_user'               => $this->username,
            'detected_at'             => gmdate('c'),
        ];

        // Estratégia 1: health --json (só v12+ tem)
        $healthCmd = $this->wrapWithSudo($this->manageBin . ' health --json 2>&1');
        $r = $this->executeCommand($healthCmd, 15);
        $output = is_array($r) && isset($r['output']) ? trim((string) $r['output']) : '';

        if ($output !== '' && strpos($output, '{') !== false) {
            // Extrair primeiro objeto JSON válido
            $jsonStart = strpos($output, '{');
            $jsonStr = substr($output, $jsonStart);
            $parsed = json_decode($jsonStr, true);
            if (is_array($parsed) && isset($parsed['schema_version'])) {
                $caps['supports_json']           = true;
                $caps['supports_health_json']    = true;
                $caps['supports_async']          = true;  // v12.0+ 
                $caps['supports_backup_offsite'] = true;  // v12.2+ (best-effort)
                $caps['supports_idempotency']    = true;
                $caps['supports_callback']       = true;
                // Tentar inferir versão exata pelo manager_version se exposto
                if (isset($parsed['manager_version'])) {
                    $caps['manager_version'] = (string) $parsed['manager_version'];
                } else {
                    $caps['manager_version'] = '12.x';
                }
            }
        }

        // Estratégia 2 (fallback v11): grep no --help do manage.sh
        if ($caps['manager_version'] === 'unknown') {
            $helpCmd = $this->wrapWithSudo(
                'bash ' . escapeshellarg($this->manageScript) . ' --help 2>&1 | head -80'
            );
            $r2 = $this->executeCommand($helpCmd, 15);
            $help = is_array($r2) && isset($r2['output']) ? (string) $r2['output'] : '';
            if ($help !== '') {
                // Heurística v12: presença de "--async" no help
                if (stripos($help, '--async') !== false) {
                    $caps['manager_version']      = '12.x';
                    $caps['supports_async']       = true;
                    $caps['supports_json']        = (stripos($help, '--json') !== false);
                    $caps['supports_idempotency'] = (stripos($help, 'idempotency') !== false);
                    $caps['supports_callback']    = (stripos($help, 'callback') !== false);
                } elseif (stripos($help, 'manage.sh') !== false || stripos($help, 'Nextcloud SaaS Manager') !== false) {
                    $caps['manager_version'] = '11.x';
                    // v11: tudo síncrono, sem JSON estável
                }
            }
        }

        // Estratégia 3 (último recurso): ler header do manage.sh
        if ($caps['manager_version'] === 'unknown') {
            $headCmd = $this->wrapWithSudo(
                'head -30 ' . escapeshellarg($this->manageScript) . ' 2>/dev/null'
            );
            $r3 = $this->executeCommand($headCmd, 10);
            $head = is_array($r3) && isset($r3['output']) ? (string) $r3['output'] : '';
            if (preg_match('/v(\d+\.\d+(?:\.\d+)?)/i', $head, $m)) {
                $caps['manager_version'] = $m[1];
                $major = (int) strtok($m[1], '.');
                if ($major >= 12) {
                    $caps['supports_async']       = true;
                    $caps['supports_json']        = true;
                    $caps['supports_health_json'] = true;
                    $caps['supports_idempotency'] = true;
                    $caps['supports_callback']    = true;
                }
            }
        }

        $this->capabilities = $caps;
        return $caps;
    }

    /**
     * Executa um comando remoto via SSH
     *
     * Tenta os seguintes métodos em ordem:
     * 1. phpseclib3 (PHP puro - funciona em qualquer servidor)
     * 2. Extensão ssh2 do PHP (se disponível)
     * 3. Comando ssh do sistema com sshpass (último recurso)
     *
     * @param string $command Comando a executar
     * @param int    $timeout Timeout específico para este comando (0 = usar default)
     * @return array ['success' => bool, 'output' => string, 'error' => string, 'exit_code' => int]
     */
    public function executeCommand($command, $timeout = 0)
    {
        $effectiveTimeout = $timeout > 0 ? $timeout : $this->timeout;

        // Método 1: phpseclib3 (preferido - PHP puro, sem dependências)
        if (class_exists('\\phpseclib3\\Net\\SSH2')) {
            return $this->executeViaPhpseclib($command, $effectiveTimeout);
        }

        // Método 2: Extensão ssh2 nativa do PHP
        if (function_exists('ssh2_connect')) {
            return $this->executeViaSsh2($command, $effectiveTimeout);
        }

        // Método 3: Comando ssh do sistema (último recurso)
        return $this->executeViaSystemSsh($command, $effectiveTimeout);
    }

    /**
     * Executa comando via phpseclib3 (PHP puro)
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaPhpseclib($command, $timeout)
    {
        try {
            $ssh = new SSH2($this->host, $this->port);
            $ssh->setTimeout($timeout);

            // Autenticar
            $authenticated = false;

            if (!empty($this->privateKeyPath) && file_exists($this->privateKeyPath)) {
                // Autenticação por chave
                try {
                    $keyContent = file_get_contents($this->privateKeyPath);
                    if (!empty($this->keyPassphrase)) {
                        $key = PublicKeyLoader::load($keyContent, $this->keyPassphrase);
                    } else {
                        $key = PublicKeyLoader::load($keyContent);
                    }
                    $authenticated = $ssh->login($this->username, $key);
                } catch (\Exception $e) {
                    // Se falhar com chave, tentar com password
                    if (!empty($this->password)) {
                        $authenticated = $ssh->login($this->username, $this->password);
                    }
                }
            } elseif (!empty($this->password)) {
                // Autenticação por password
                $authenticated = $ssh->login($this->username, $this->password);
            }

            if (!$authenticated) {
                return [
                    'success'   => false,
                    'output'    => '',
                    'error'     => "Falha na autenticação SSH com {$this->host}:{$this->port} (utilizador: {$this->username}). "
                                 . "Verifique as credenciais no WHMCS.",
                    'exit_code' => -1,
                ];
            }

            // Executar o comando e capturar o exit code
            $wrappedCommand = $command . '; echo "___EXITCODE___$?"';
            $output = $ssh->exec($wrappedCommand);
            $stderrOutput = $ssh->getStdError();

            // Extrair exit code
            $exitCode = -1;
            if (preg_match('/___EXITCODE___(\d+)/', $output, $matches)) {
                $exitCode = (int) $matches[1];
                $output = preg_replace('/___EXITCODE___\d+\s*$/', '', $output);
            }

            // Limpar a password do output (pode aparecer no stderr do sudo -S)
            $output = $this->cleanSudoOutput($output);
            $stderrOutput = $this->cleanSudoOutput($stderrOutput);

            $ssh->disconnect();

            return [
                'success'   => ($exitCode === 0),
                'output'    => trim($output),
                'error'     => trim($stderrOutput),
                'exit_code' => $exitCode,
            ];

        } catch (\Exception $e) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Erro phpseclib SSH ({$this->host}): " . $e->getMessage(),
                'exit_code' => -1,
            ];
        }
    }

    /**
     * Limpar o output de mensagens do sudo -S (como "[sudo] password for user:")
     *
     * @param string $output
     * @return string
     */
    private function cleanSudoOutput($output)
    {
        // Remover linhas "[sudo] password for ..."
        $output = preg_replace('/^\[sudo\] password for \S+:\s*$/m', '', $output);
        // Remover linhas vazias consecutivas resultantes
        $output = preg_replace('/\n{3,}/', "\n\n", $output);
        return $output;
    }

    /**
     * Executa comando via extensão PHP ssh2
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaSsh2($command, $timeout)
    {
        $connection = @ssh2_connect($this->host, $this->port);
        if (!$connection) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Não foi possível conectar ao servidor {$this->host}:{$this->port}",
                'exit_code' => -1,
            ];
        }

        // Autenticar por password ou chave
        $authResult = false;
        if (!empty($this->password)) {
            $authResult = @ssh2_auth_password($connection, $this->username, $this->password);
        } elseif (!empty($this->privateKeyPath) && file_exists($this->privateKeyPath)) {
            $pubKeyPath = $this->privateKeyPath . '.pub';
            if (!file_exists($pubKeyPath)) {
                $pubKeyPath = null;
            }
            $authResult = @ssh2_auth_pubkey_file(
                $connection,
                $this->username,
                $pubKeyPath,
                $this->privateKeyPath,
                $this->keyPassphrase
            );
        }

        if (!$authResult) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Falha na autenticação SSH com o servidor {$this->host}",
                'exit_code' => -1,
            ];
        }

        // Encapsular o comando para capturar exit code
        $wrappedCommand = $command . '; echo "___EXITCODE___$?"';
        $stream = ssh2_exec($connection, $wrappedCommand);
        if (!$stream) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Falha ao executar o comando no servidor {$this->host}",
                'exit_code' => -1,
            ];
        }

        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        stream_set_timeout($stream, $timeout);

        $output = stream_get_contents($stream);
        $error = stream_get_contents($errorStream);

        fclose($stream);
        fclose($errorStream);

        // Extrair exit code do output
        $exitCode = -1;
        if (preg_match('/___EXITCODE___(\d+)/', $output, $matches)) {
            $exitCode = (int) $matches[1];
            $output = preg_replace('/___EXITCODE___\d+\s*$/', '', $output);
        }

        // Limpar output do sudo
        $output = $this->cleanSudoOutput($output);
        $error = $this->cleanSudoOutput($error);

        return [
            'success'   => ($exitCode === 0),
            'output'    => trim($output),
            'error'     => trim($error),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Executa comando via ssh do sistema (fallback)
     * Suporta autenticação por password (via sshpass) ou por chave
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaSystemSsh($command, $timeout)
    {
        if (!empty($this->password)) {
            // Verificar se sshpass está disponível
            $sshpassCheck = '';
            exec('which sshpass 2>/dev/null', $sshpassOutput);
            if (empty($sshpassOutput)) {
                return [
                    'success'   => false,
                    'output'    => '',
                    'error'     => 'Nenhum método SSH disponível no servidor WHMCS. '
                                 . 'Instale phpseclib (incluído no módulo), a extensão php-ssh2, '
                                 . 'ou o pacote sshpass.',
                    'exit_code' => -1,
                ];
            }

            // Usar sshpass para autenticação por password
            $sshCommand = sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=%d -p %d %s@%s %s 2>&1',
                escapeshellarg($this->password),
                min($timeout, 30),
                $this->port,
                escapeshellarg($this->username),
                escapeshellarg($this->host),
                escapeshellarg($command)
            );
        } elseif (!empty($this->privateKeyPath)) {
            $sshCommand = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=%d -o BatchMode=yes -i %s -p %d %s@%s %s 2>&1',
                min($timeout, 30),
                escapeshellarg($this->privateKeyPath),
                $this->port,
                escapeshellarg($this->username),
                escapeshellarg($this->host),
                escapeshellarg($command)
            );
        } else {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => 'Nenhum método de autenticação SSH configurado (password ou chave)',
                'exit_code' => -1,
            ];
        }

        $output = '';
        $exitCode = -1;
        exec($sshCommand, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        return [
            'success'   => ($exitCode === 0),
            'output'    => trim($output),
            'error'     => ($exitCode !== 0) ? $output : '',
            'exit_code' => $exitCode,
        ];
    }

    // ================================================================
    // MÉTODOS DE INTEGRAÇÃO COM O MANAGE.SH
    // ================================================================

    /**
     * Executar o manage.sh com os argumentos fornecidos.
     *
     * Usa sudo -S para permitir execução com privilégios elevados
     * em sessões SSH não-interativas.
     *
     * @param string $clientName  Nome do cliente
     * @param string $domain      Domínio (ou '_' para comandos que não precisam)
     * @param string $command     Comando do manage.sh (create, stop, start, etc.)
     * @return array Resultado da execução
     */
    public function runManage($clientName, $domain, $command)
    {
        $innerCmd = sprintf(
            'bash %s %s %s %s',
            escapeshellarg($this->manageScript),
            escapeshellarg($clientName),
            escapeshellarg($domain),
            escapeshellarg($command)
        );

        $cmd = $this->wrapWithSudo($innerCmd);

        Helper::log('SSHManager::runManage', [
            'client' => $clientName,
            'domain' => $domain,
            'command' => $command,
        ]);

        $result = $this->executeCommand($cmd);

        Helper::log('SSHManager::runManage result', $result);

        return $result;
    }

    /**
     * Criar uma nova instância Nextcloud via manage.sh
     *
     * @param string $clientName Nome do cliente (identificador único)
     * @param string $domain     Domínio principal do Nextcloud
     * @return array
     */
    public function createInstance($clientName, $domain)
    {
        return $this->runManage($clientName, $domain, 'create');
    }

    /**
     * Parar (suspender) uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function stopInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'stop');
    }

    /**
     * Iniciar (reativar) uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function startInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'start');
    }

    /**
     * Reiniciar uma instância (stop + start)
     *
     * O manage.sh não tem comando 'restart', por isso
     * executamos stop seguido de start.
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function restartInstance($clientName)
    {
        $stopResult = $this->runManage($clientName, '_', 'stop');
        if (!$stopResult['success']) {
            return $stopResult;
        }
        sleep(3);
        return $this->runManage($clientName, '_', 'start');
    }

    /**
     * Obter o estado de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function statusInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'status');
    }

    /**
     * Fazer backup de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function backupInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'backup');
    }

    /**
     * Remover uma instância (com backup automático)
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function removeInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'remove');
    }

    /**
     * Atualizar uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function updateInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'update');
    }

    /**
     * Obter os logs de uma instância
     *
     * @param string $clientName Nome do cliente
     * @param int    $lines      Número de linhas (default: 100)
     * @return array
     */
    public function getInstanceLogs($clientName, $lines = 100)
    {
        $innerCmd = sprintf(
            'docker logs --tail %d %s 2>&1',
            (int) $lines,
            escapeshellarg($clientName . '-app')
        );
        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd, 30);
    }

    /**
     * Ler o ficheiro .credentials de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function getCredentials($clientName)
    {
        $credFile = $this->basePath . '/' . $clientName . '/.credentials';
        $cmd = $this->wrapWithSudo('cat ' . escapeshellarg($credFile));
        $result = $this->executeCommand($cmd, 10);

        if ($result['success']) {
            return [
                'success'     => true,
                'credentials' => Helper::parseCredentials($result['output']),
                'raw'         => $result['output'],
            ];
        }

        return [
            'success'     => false,
            'credentials' => [],
            'raw'         => $result['error'],
        ];
    }

    /**
     * Atualizar a password no ficheiro .credentials de uma instância
     *
     * Usa sed para substituir a linha "  Senha: <password_antiga>" na secção
     * Nextcloud do ficheiro .credentials pela nova password.
     * Apenas a primeira ocorrência de "  Senha:" é substituída (a do Nextcloud).
     *
     * @param string $clientName Nome do cliente
     * @param string $newPassword Nova password
     * @return array
     */
    public function updateCredentialsPassword($clientName, $newPassword)
    {
        $credFile = $this->basePath . '/' . $clientName . '/.credentials';

        // Escapar caracteres especiais do sed na password de substituição:
        // / & \ [ ] precisam de escape no replacement do sed
        $escapedPassword = str_replace(
            ['\\', '/', '&', '[', ']'],
            ['\\\\', '\\/', '\\&', '\\[', '\\]'],
            $newPassword
        );

        // Usar sed para substituir apenas a primeira ocorrência de "  Senha: ..."
        // que corresponde à password do Nextcloud (a primeira no ficheiro)
        $sedExpression = '0,/^  Senha: /{s/^  Senha: .*/  Senha: ' . $escapedPassword . '/}';
        $sedCmd = 'sed -i ' . escapeshellarg($sedExpression) . ' ' . escapeshellarg($credFile);

        $cmd = $this->wrapWithSudo($sedCmd);
        $result = $this->executeCommand($cmd, 10);

        if ($result['success']) {
            Helper::log('updateCredentialsPassword', [
                'clientName' => $clientName,
                'status'     => 'Password atualizada no .credentials',
            ]);
        } else {
            Helper::log('updateCredentialsPassword-FAIL', [
                'clientName' => $clientName,
                'error'      => $result['error'],
            ]);
        }

        return $result;
    }

    /**
     * Ler o ficheiro .env de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function getEnv($clientName)
    {
        $envFile = $this->basePath . '/' . $clientName . '/.env';
        $cmd = $this->wrapWithSudo('cat ' . escapeshellarg($envFile));
        $result = $this->executeCommand($cmd, 10);

        if ($result['success']) {
            $env = [];
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '#') !== 0 && strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
            return ['success' => true, 'env' => $env];
        }

        return ['success' => false, 'env' => []];
    }

    // ================================================================
    // MÉTODOS DE GESTÃO DE UTILIZADORES (via occ)
    // ================================================================

    /**
     * Executar um comando occ no container Nextcloud.
     *
     * Usa sudo -S para aceder ao Docker em sessões não-interativas.
     *
     * @param string $clientName Nome da instância
     * @param string $occCommand Comando occ (sem o prefixo 'php occ')
     * @return array
     */
    public function runOcc($clientName, $occCommand)
    {
        $innerCmd = sprintf(
            'docker exec -u www-data %s php occ %s',
            escapeshellarg($clientName . '-app'),
            $occCommand
        );
        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd, 60);
    }

    /**
     * Criar um novo utilizador no Nextcloud
     *
     * @param string $clientName  Nome da instância
     * @param string $ncUsername  Username
     * @param string $ncPassword  Password
     * @param string $email       Email (opcional)
     * @param string $displayName Nome de exibição (opcional)
     * @param string $quota       Quota (opcional, ex: "5 GB")
     * @return array
     */
    public function createUser($clientName, $ncUsername, $ncPassword, $email = '', $displayName = '', $quota = '')
    {
        $bashCmd = sprintf(
            'export OC_PASS=%s && docker exec -u www-data -e OC_PASS %s php occ user:add --password-from-env %s',
            escapeshellarg($ncPassword),
            escapeshellarg($clientName . '-app'),
            escapeshellarg($ncUsername)
        );

        if (!empty($displayName)) {
            $bashCmd .= ' --display-name=' . escapeshellarg($displayName);
        }

        $innerCmd = 'bash -c ' . escapeshellarg($bashCmd);
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd);

        if (!$result['success']) {
            return $result;
        }

        // Definir email
        if (!empty($email)) {
            $this->runOcc($clientName, sprintf(
                'user:setting %s settings email %s',
                escapeshellarg($ncUsername),
                escapeshellarg($email)
            ));
        }

        // Definir quota
        if (!empty($quota)) {
            $this->runOcc($clientName, sprintf(
                'user:setting %s files quota %s',
                escapeshellarg($ncUsername),
                escapeshellarg(Helper::formatQuotaForNextcloud($quota))
            ));
        }

        return $result;
    }

    /**
     * Alterar a password de um utilizador no Nextcloud
     *
     * @param string $clientName  Nome da instância
     * @param string $ncUsername  Username
     * @param string $newPassword Nova password
     * @return array
     */
    public function changeUserPassword($clientName, $ncUsername, $newPassword)
    {
        $bashCmd = sprintf(
            'export OC_PASS=%s && docker exec -u www-data -e OC_PASS %s php occ user:resetpassword --password-from-env %s',
            escapeshellarg($newPassword),
            escapeshellarg($clientName . '-app'),
            escapeshellarg($ncUsername)
        );

        $innerCmd = 'bash -c ' . escapeshellarg($bashCmd);
        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd);
    }

    /**
     * Desativar um utilizador no Nextcloud
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function disableUser($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:disable ' . escapeshellarg($ncUsername));
    }

    /**
     * Ativar um utilizador no Nextcloud
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function enableUser($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:enable ' . escapeshellarg($ncUsername));
    }

    /**
     * Alterar a quota de um utilizador
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @param string $quota      Nova quota (ex: "10 GB", "none")
     * @return array
     */
    public function setUserQuota($clientName, $ncUsername, $quota)
    {
        return $this->runOcc($clientName, sprintf(
            'user:setting %s files quota %s',
            escapeshellarg($ncUsername),
            escapeshellarg(Helper::formatQuotaForNextcloud($quota))
        ));
    }

    /**
     * Definir a quota padrão para todos os novos utilizadores da instância.
     *
     * Usa config:app:set files default_quota para que qualquer novo
     * utilizador criado na instância receba automaticamente esta quota.
     *
     * @param string $clientName Nome da instância
     * @param string $quota      Quota padrão (ex: "10 GB", "none")
     * @return array
     */
    public function setDefaultQuota($clientName, $quota)
    {
        $formattedQuota = Helper::formatQuotaForNextcloud($quota);
        return $this->runOcc($clientName, sprintf(
            'config:app:set files default_quota --value %s',
            escapeshellarg($formattedQuota)
        ));
    }

    /**
     * Listar utilizadores de uma instância
     *
     * @param string $clientName Nome da instância
     * @return array
     */
    public function listUsers($clientName)
    {
        return $this->runOcc($clientName, 'user:list --output=json');
    }

    /**
     * Obter informações de um utilizador
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function getUserInfo($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:info ' . escapeshellarg($ncUsername) . ' --output=json');
    }

    // ================================================================
    // MÉTODOS DE DIAGNÓSTICO
    // ================================================================

    /**
     * Testar a conexão SSH com o servidor
     *
     * @return array Resultado do teste
     */
public function testConnection()
    {
        $result = $this->executeCommand('echo "SSH_CONNECTION_OK" && hostname && uptime', 15);

        if ($result['success'] && strpos($result['output'], 'SSH_CONNECTION_OK') !== false) {
            return [
                'success' => true,
                'message' => "Conexão SSH bem-sucedida com {$this->host}",
                'output'  => $result['output'],
            ];
        }

        // v3.1.1 — enriquecer mensagem de erro com sugestões úteis quando o
        // sintoma for o clássico "Connection closed by server" do phpseclib3,
        // que normalmente indica `PasswordAuthentication no` no sshd_config.
        $errorRaw = isset($result['error']) ? (string)$result['error'] : '';
        $hint = '';
        if (stripos($errorRaw, 'Connection closed') !== false
            || stripos($errorRaw, 'closed by server') !== false
            || stripos($errorRaw, 'no supported authentication methods') !== false) {
            $hint = "\n\nDica: este erro normalmente indica que o servidor SSH não aceita autenticação por password.\n"
                  . "Confirme no servidor:\n"
                  . "  - /etc/ssh/sshd_config.d/60-cloudimg-settings.conf  (Ubuntu cloud)\n"
                  . "  - /etc/ssh/sshd_config\n"
                  . "e garanta 'PasswordAuthentication yes' (ou configure autenticação por chave SSH).\n"
                  . "Após alterar: sudo systemctl reload ssh";
        } elseif (stripos($errorRaw, 'authentica') !== false) {
            $hint = "\n\nDica: o servidor aceitou a conexão mas rejeitou as credenciais.\n"
                  . "Verifique o utilizador/password no WHMCS ou o caminho da chave SSH.";
        } elseif (stripos($errorRaw, 'timed out') !== false || stripos($errorRaw, 'timeout') !== false) {
            $hint = "\n\nDica: timeout de conexão. Verifique se a porta {$this->port} está aberta no firewall do servidor.";
        }

        return [
            'success' => false,
            'message' => "Falha na conexão SSH com {$this->host}:{$this->port}: " . $errorRaw . $hint,
        ];
    }

    /**
     * Verificar se o manage.sh existe no servidor
     *
     * @return array
     */
    public function verifyManageScript()
    {
        $result = $this->executeCommand(
            'test -f ' . escapeshellarg($this->manageScript) . ' && echo "EXISTS" || echo "MISSING"',
            10
        );

        return [
            'exists'  => (strpos($result['output'], 'EXISTS') !== false),
            'path'    => $this->manageScript,
            'output'  => $result['output'],
        ];
    }

    /**
     * Verificar se o Docker está a correr no servidor.
     * Usa sudo -S para aceder ao Docker.
     *
     * @return array
     */
    public function verifyDocker()
    {
        $innerCmd = 'docker info --format "{{.ServerVersion}}" 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 10);

        return [
            'running' => $result['success'],
            'version' => $result['success'] ? trim($result['output']) : 'N/A',
        ];
    }

    /**
     * Verificar se o Traefik está a correr.
     * Usa sudo -S para aceder ao Docker.
     *
     * @return array
     */
    public function verifyTraefik()
    {
        $innerCmd = 'docker ps --filter "name=traefik" --format "{{.Status}}" 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 10);

        return [
            'running' => !empty($result['output']) && strpos($result['output'], 'Up') !== false,
            'status'  => trim($result['output']),
        ];
    }

    /**
     * Verificar o estado dos serviços globais compartilhados (v3.0.0).
     *
     * Na arquitetura compartilhada do manager v11.x, todas as instâncias
     * dependem de 8 containers globais `shared-*` rodando no host:
     *   shared-db, shared-redis, shared-collabora, shared-turn,
     *   shared-nats, shared-janus, shared-signaling, shared-recording.
     *
     * Este método consulta o Docker via `docker ps` e devolve o
     * estado individual + um sumário agregado.
     *
     * @return array {
     *     all_ok: bool, total: int, up: int, missing: string[],
     *     services: array<string,array{running:bool,status:string}>
     * }
     */
    public function verifySharedServices()
    {
        $expected = [
            'shared-db',
            'shared-redis',
            'shared-collabora',
            'shared-turn',
            'shared-nats',
            'shared-janus',
            'shared-signaling',
            'shared-recording',
        ];

        // Listar todos os containers (nome + status) numa só chamada
        $innerCmd = "docker ps -a --filter 'name=shared-' --format '{{.Names}}|{{.Status}}' 2>/dev/null";
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 15);

        $byName = [];
        if (!empty($result['output'])) {
            foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
                if (strpos($line, '|') === false) {
                    continue;
                }
                list($name, $status) = explode('|', $line, 2);
                $byName[trim($name)] = trim($status);
            }
        }

        $services = [];
        $missing = [];
        $up = 0;

        foreach ($expected as $name) {
            if (isset($byName[$name])) {
                $running = (strpos($byName[$name], 'Up') === 0);
                $services[$name] = [
                    'running' => $running,
                    'status'  => $byName[$name],
                ];
                if ($running) {
                    $up++;
                }
            } else {
                $services[$name] = [
                    'running' => false,
                    'status'  => 'Não encontrado',
                ];
                $missing[] = $name;
            }
        }

        return [
            'all_ok'   => ($up === count($expected)),
            'total'    => count($expected),
            'up'       => $up,
            'missing'  => $missing,
            'services' => $services,
        ];
    }

    /**
     * Obter uso de disco de uma instância
     *
     * @param string $clientName Nome da instância
     * @return array
     */
    public function getDiskUsage($clientName)
    {
        $instancePath = $this->basePath . '/' . $clientName;
        $innerCmd = 'du -sh ' . escapeshellarg($instancePath) . ' 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 30);

        $usage = 'N/A';
        if ($result['success'] && preg_match('/^([\d.]+[KMGT]?)/', $result['output'], $m)) {
            $usage = $m[1];
        }

        return [
            'success' => $result['success'],
            'usage'   => $usage,
            'raw'     => $result['output'],
        ];
    }

    /**
     * Obter a HaRP Shared Key (HP_SHARED_KEY) de uma instância (v3.1.6).
     *
     * No manage.sh v11.x, a `HP_SHARED_KEY` não é mais escrita no
     * ficheiro `.credentials` (ele só lista Nextcloud, Collabora,
     * MariaDB, Redis, TURN, Signaling e DNS). Por isso o parser do
     * Helper devolvia string vazia e o painel do cliente exibia
     * "Não disponível" mesmo com o container `<cliente>-harp` ativo.
     *
     * Estratégia em ordem de preferência (parar na primeira que retornar
     * valor não-vazio):
     *   1. `grep` em `/opt/nextcloud-customers/<cliente>/docker-compose.yml`
     *      pela linha `- HP_SHARED_KEY=...` (rapidíssimo, ~10ms).
     *   2. `docker exec <cliente>-harp printenv HP_SHARED_KEY` (robusto:
     *      funciona mesmo se o `docker-compose.yml` for editado/regenerado).
     *
     * @param string $clientName Nome da instância
     * @return array {
     *     success: bool,
     *     key:     string,    // valor encontrado (ou vazio)
     *     source:  string,    // 'docker-compose.yml' | 'docker-exec' | ''
     *     raw:     string,    // saída crua útil para diagnóstico
     * }
     */
    public function getHarpSharedKey($clientName)
    {
        $compose = $this->basePath . '/' . $clientName . '/docker-compose.yml';

        // Tentativa 1: docker-compose.yml
        // Procuramos por uma linha (com prefixo `-` opcional) `HP_SHARED_KEY=...`
        // e devolvemos somente o valor entre aspas/whitespace.
        $cmd1 = $this->wrapWithSudo(
            'grep -E "HP_SHARED_KEY[[:space:]]*=" ' . escapeshellarg($compose) . ' 2>/dev/null | head -n1'
        );
        $r1 = $this->executeCommand($cmd1, 10);
        $line = is_array($r1) && isset($r1['output']) ? trim((string) $r1['output']) : '';

        if ($line !== '') {
            // Extrair valor depois do primeiro `=`
            $eqPos = strpos($line, '=');
            if ($eqPos !== false) {
                $value = trim(substr($line, $eqPos + 1));
                // Remover aspas e vírgulas/colchetes residuais (`,`, `]`, `}`)
                $value = trim($value, "\"' \t\r\n,]\u007d");
                if ($value !== '') {
                    return [
                        'success' => true,
                        'key'     => $value,
                        'source'  => 'docker-compose.yml',
                        'raw'     => $line,
                    ];
                }
            }
        }

        // Tentativa 2: docker exec
        $containerName = $clientName . '-harp';
        $innerCmd = sprintf(
            'docker exec %s printenv HP_SHARED_KEY 2>/dev/null',
            escapeshellarg($containerName)
        );
        $cmd2 = $this->wrapWithSudo($innerCmd);
        $r2 = $this->executeCommand($cmd2, 15);
        $val = is_array($r2) && isset($r2['output']) ? trim((string) $r2['output']) : '';

        if ($val !== '') {
            return [
                'success' => true,
                'key'     => $val,
                'source'  => 'docker-exec',
                'raw'     => $val,
            ];
        }

        return [
            'success' => false,
            'key'     => '',
            'source'  => '',
            'raw'     => '',
        ];
    }

    /**
     * Verificar se uma instância já existe no servidor (v3.1.5+).
     *
     * Critério v3.1.7 (endurecido): consideramos uma instância
     * **realmente provisionada** apenas quando:
     *   - O diretório /opt/nextcloud-customers/<cliente>/ existe, E
     *   - O ficheiro `.credentials` está presente (única fonte de
     *     verdade que contém URL/usuário/senha utilizáveis), E
     *   - O container Docker `<cliente>-app` existe (criado/parado/rodando).
     *
     * Antes (v3.1.5/v3.1.6) era considerado existente quando havia
     * `.credentials` *OU* `.env`. Isso gerava falso positivo em casos
     * de tentativa parcial anterior (diretório com apenas `.env` mas
     * sem `.credentials`), levando o `CreateAccount` ao fast-path e
     * tentando ler credenciais inexistentes.
     *
     * O retorno traz também as flags individuais para diagnóstico.
     *
     * @param string $clientName Nome da instância
     * @return array {
     *     exists: bool,            // true SOMENTE se totalmente provisionada
     *     has_credentials: bool,   // .credentials presente
     *     has_env: bool,           // .env presente
     *     has_container: bool,     // container <cliente>-app existe (qualquer estado)
     *     partial: bool,           // diretório existe mas sem .credentials => provisionamento incompleto
     *     path: string,
     * }
     */
    /**
     * Executa nextcloud-manage em modo JSON estruturado (v12.0+).
     *
     * Constrói o comando ssh-safe (sem bash -c, sem pipes) compatível com
     * o shim ncsaas-api. Anexa --json e flags extras (--async, --idempotency-key,
     * --callback, --force, --dry-run, --confirm).
     *
     * Tenta decodificar a saída como JSON. Se a primeira tentativa falhar e
     * o servidor for v11, devolve o resultado bruto em 'output' com 'json' = null.
     *
     * @param string $clientName Nome do cliente ("_" se top-level)
     * @param string $domainOrArg Domínio FQDN para create/restore; "_" para outros
     * @param string $cmd Verbo: create|remove|backup|restore|status|credentials|...
     * @param array  $flags Lista de flags adicionais (cada string já prefixada com --).
     *                     Ex: ["--async", "--idempotency-key=abc-...", "--callback=https://..."]
     * @param int    $timeout Timeout SSH em segundos (default 60)
     * @return array {
     *   success: bool,
     *   exit_code: int,
     *   raw_output: string,
     *   json: array|null,      // payload decodificado quando disponível
     *   error: string,         // código de erro do contrato v12 quando presente
     *   error_message: string, // mensagem legível
     * }
     */
    public function runManageJson($clientName, $domainOrArg, $cmd, array $flags = [], $timeout = 60)
    {
        // Higienizar flags — cada uma deve começar com --, sem espaços
        $clean = [];
        $hasJson = false;
        foreach ($flags as $f) {
            $f = trim((string) $f);
            if ($f === '' || strpos($f, '--') !== 0) {
                continue;
            }
            if ($f === '--json') {
                $hasJson = true;
            }
            $clean[] = $f;
        }
        if (!$hasJson) {
            $clean[] = '--json';
        }

        $args = [
            escapeshellarg($this->manageBin),
            escapeshellarg((string) $clientName),
            escapeshellarg((string) $domainOrArg),
            escapeshellarg((string) $cmd),
        ];
        foreach ($clean as $f) {
            // Flags são literais conhecidos (--async, --json, --idempotency-key=UUID,
            // --callback=URL). Não usar escapeshellarg para preservar o = quando o
            // shim valida o formato. Caracteres perigosos já foram filtrados.
            $args[] = $f;
        }
        $cmdStr = $this->wrapWithSudo(implode(' ', $args));
        $result = $this->executeCommand($cmdStr, $timeout);

        $output   = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';
        $exitCode = is_array($result) && isset($result['exit_code']) ? (int) $result['exit_code'] : 0;

        $jsonObj = null;
        $error = '';
        $errorMessage = '';

        // Procurar primeiro objeto JSON no output (manage.sh pode imprimir
        // mensagens livres antes do JSON, embora o contrato v12 prometa
        // apenas JSON no stdout quando --json está ativo).
        if ($output !== '') {
            $start = strpos($output, '{');
            $end   = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($output, $start, $end - $start + 1);
                $parsed = json_decode($candidate, true);
                if (is_array($parsed)) {
                    $jsonObj = $parsed;
                    if (isset($parsed['error'])) {
                        $error = (string) $parsed['error'];
                    }
                    if (isset($parsed['message'])) {
                        $errorMessage = (string) $parsed['message'];
                    }
                }
            }
        }

        return [
            'success'       => ($exitCode === 0 && $error === ''),
            'exit_code'     => $exitCode,
            'raw_output'    => $output,
            'json'          => $jsonObj,
            'error'         => $error,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Health check consolidado (v12.0+).
     *
     * Retorna {schema_version, checks: [{name, status, message, duration_ms}], summary: {ok, warn, fail}}.
     *
     * @return array {
     *   success: bool, summary: array, checks: array, raw: array|null, error: string
     * }
     */
    public function healthCheck()
    {
        $cmd = $this->wrapWithSudo($this->manageBin . ' health --json');
        $result = $this->executeCommand($cmd, 20);
        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';

        $payload = null;
        if ($output !== '') {
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $payload = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }

        if (!is_array($payload)) {
            return [
                'success' => false,
                'summary' => ['ok' => 0, 'warn' => 0, 'fail' => 0],
                'checks'  => [],
                'raw'     => null,
                'error'   => 'health_unavailable',
            ];
        }

        return [
            'success' => isset($payload['summary']['fail']) && (int) $payload['summary']['fail'] === 0,
            'summary' => isset($payload['summary']) ? $payload['summary'] : ['ok' => 0, 'warn' => 0, 'fail' => 0],
            'checks'  => isset($payload['checks']) ? $payload['checks'] : [],
            'raw'     => $payload,
            'error'   => '',
        ];
    }

    /**
     * Consultar status de um job assíncrono (v12.0+).
     *
     * @param string $jobId UUID v4 do job
     * @return array Payload do servidor + flags
     */
    public function jobStatus($jobId)
    {
        $cmd = $this->wrapWithSudo($this->manageBin . ' job ' . escapeshellarg($jobId) . ' status --json');
        $result = $this->executeCommand($cmd, 15);
        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';

        $payload = null;
        if ($output !== '') {
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $payload = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }

        if (!is_array($payload)) {
            return [
                'success'   => false,
                'state'     => 'unknown',
                'exit_code' => null,
                'raw'       => null,
                'error'     => 'job_status_unavailable',
            ];
        }

        return [
            'success'   => (isset($payload['state']) && in_array($payload['state'], ['done', 'queued', 'running', 'cancelled'], true)),
            'state'     => isset($payload['state']) ? (string) $payload['state'] : 'unknown',
            'exit_code' => isset($payload['exit_code']) ? (int) $payload['exit_code'] : null,
            'cmd'       => isset($payload['cmd']) ? (string) $payload['cmd'] : '',
            'client'    => isset($payload['client']) ? (string) $payload['client'] : '',
            'raw'       => $payload,
            'error'     => isset($payload['error']) ? (string) $payload['error'] : '',
        ];
    }

    /**
     * Consultar logs de um job (v12.0+).
     *
     * @param string $jobId
     * @param int    $maxLines
     * @return array {success, lines, raw_output}
     */
    public function jobLogs($jobId, $maxLines = 200)
    {
        $cmd = $this->wrapWithSudo($this->manageBin . ' job ' . escapeshellarg($jobId) . ' logs');
        $result = $this->executeCommand($cmd, 30);
        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';
        $lines = $output === '' ? [] : preg_split('/\r?\n/', $output);
        if (is_array($lines) && $maxLines > 0 && count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }
        return [
            'success'    => is_array($result) && !empty($result['success']),
            'lines'      => $lines,
            'raw_output' => $output,
        ];
    }

    /**
     * Cancelar um job enfileirado (só funciona se state == queued).
     *
     * @param string $jobId
     * @return array {success, state, raw}
     */
    public function jobCancel($jobId)
    {
        $cmd = $this->wrapWithSudo($this->manageBin . ' job ' . escapeshellarg($jobId) . ' cancel --json');
        $result = $this->executeCommand($cmd, 15);
        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';
        $payload = null;
        if ($output !== '') {
            $start = strpos($output, '{');
            $end = strrpos($output, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $payload = json_decode(substr($output, $start, $end - $start + 1), true);
            }
        }
        return [
            'success' => (is_array($payload) && isset($payload['state']) && $payload['state'] === 'cancelled'),
            'state'   => is_array($payload) && isset($payload['state']) ? (string) $payload['state'] : 'unknown',
            'raw'     => $payload,
        ];
    }

    /**
     * Listar jobs do servidor com filtros opcionais.
     *
     * @param array $filters {state?, client?, cmd?, limit?, offset?}
     * @return array Lista de jobs (cada um é um array assoc).
     */
    public function jobList(array $filters = [])
    {
        $args = [$this->manageBin, 'job', 'list', '--json'];
        foreach (['state', 'client', 'cmd', 'limit', 'offset'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $args[] = '--' . $key . '=' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $filters[$key]);
            }
        }
        $cmd = $this->wrapWithSudo(implode(' ', array_map('escapeshellarg', $args)));
        // o '=' nas flags precisa sobreviver — escapeshellarg quebra. Reconstruir:
        $cmdParts = array_map(function ($v) {
            // se for flag --foo=bar, não escapar
            if (preg_match('/^--[a-z\-]+=[\w\-]+$/', $v)) {
                return $v;
            }
            return escapeshellarg($v);
        }, $args);
        $cmd = $this->wrapWithSudo(implode(' ', $cmdParts));

        $result = $this->executeCommand($cmd, 15);
        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';
        $start = strpos($output, '[');
        $end = strrpos($output, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $arr = json_decode(substr($output, $start, $end - $start + 1), true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        return [];
    }

    public function instanceExists($clientName)
    {
        $instancePath = $this->basePath . '/' . $clientName;
        $credFile     = $instancePath . '/.credentials';
        $envFile      = $instancePath . '/.env';
        $containerNm  = $clientName . '-app';

        // Em uma única chamada SSH testamos os quatro sinais e devolvemos
        // marcadores no stdout. Isto reduz latência (1 chamada vs 4).
        $innerCmd = sprintf(
            '( [ -d %s ] && echo DIR_OK || echo DIR_MISS ) ; '
            . '( [ -f %s ] && echo CRED_OK || echo CRED_MISS ) ; '
            . '( [ -f %s ] && echo ENV_OK || echo ENV_MISS ) ; '
            . '( docker inspect %s >/dev/null 2>&1 && echo CTR_OK || echo CTR_MISS )',
            escapeshellarg($instancePath),
            escapeshellarg($credFile),
            escapeshellarg($envFile),
            escapeshellarg($containerNm)
        );
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 15);

        $output = is_array($result) && isset($result['output']) ? (string) $result['output'] : '';

        $dirOk  = (strpos($output, 'DIR_OK')  !== false);
        $credOk = (strpos($output, 'CRED_OK') !== false);
        $envOk  = (strpos($output, 'ENV_OK')  !== false);
        $ctrOk  = (strpos($output, 'CTR_OK')  !== false);

        // Instância totalmente provisionada: dir + .credentials + container
        // (.env por si só NÃO conta mais; era a fonte de falso positivo).
        $exists  = ($dirOk && $credOk && $ctrOk);
        // Sinaliza um estado de provisionamento incompleto onde o
        // diretório existe mas sem .credentials — chamador pode decidir
        // se limpa e refaz, ou se segue tentando o `manage.sh create`.
        $partial = ($dirOk && !$credOk);

        return [
            'exists'          => $exists,
            'has_credentials' => $credOk,
            'has_env'         => $envOk,
            'has_container'   => $ctrOk,
            'partial'         => $partial,
            'path'            => $instancePath,
        ];
    }
}
