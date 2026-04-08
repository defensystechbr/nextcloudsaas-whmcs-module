<?php
/**
 * Hooks do módulo Nextcloud-SaaS para WHMCS
 *
 * Este ficheiro é carregado automaticamente pelo WHMCS a partir do diretório do módulo.
 * Os hooks principais estão definidos em includes/hooks/nextcloudsaas_hooks.php,
 * que também é carregado automaticamente pelo WHMCS.
 *
 * Para evitar redeclaração de funções (Fatal Error), este ficheiro NÃO contém
 * implementações duplicadas. Apenas garante que o ficheiro principal de hooks
 * seja carregado caso ainda não tenha sido.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.6.1
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

// Os hooks estão definidos em includes/hooks/nextcloudsaas_hooks.php
// que é carregado automaticamente pelo WHMCS.
// Este ficheiro existe apenas como referência e para compatibilidade.

$mainHooksFile = ROOTDIR . '/includes/hooks/nextcloudsaas_hooks.php';
if (file_exists($mainHooksFile) && !function_exists('nextcloudsaas_cronProcessPendingService')) {
    require_once $mainHooksFile;
}
