#!/usr/bin/env bash
# scripts/register-whmcs-key.sh
#
# Gera (se ausente) um par de chaves Ed25519 dedicado ao WHMCS para conexão
# SSH como usuário `ncsaas-api` no servidor nextcloud-saas-manager v12+.
# Imprime a linha exata a ser adicionada em
# /home/ncsaas-api/.ssh/authorized_keys do servidor manager, com todos os
# flags obrigatórios do contrato (ARCHITECTURE A.6).
#
# Execute este script NO SERVIDOR DO WHMCS, como o usuário que roda o PHP
# do WHMCS (tipicamente `www-data` ou `nobody`).
#
# Uso:
#   sudo -u www-data bash scripts/register-whmcs-key.sh [--key-path=PATH] [--comment=STR] [--install=USER@HOST]
#
# Exemplos:
#   sudo -u www-data bash scripts/register-whmcs-key.sh
#   sudo -u www-data bash scripts/register-whmcs-key.sh --install=root@manager.example.com
#
# Opcionais:
#   --key-path=PATH    Diretório onde gerar (default: ~/.ssh/whmcs_ncsaas)
#   --comment=STR      Comentário na chave (default: whmcs-prod-YYYY)
#   --install=USER@H   Tenta scp+append remoto na authorized_keys (precisa de root SSH no manager)
#   --print-only       Não escreve nada, só imprime se já existir
#   --help             Mostra esta ajuda
#
# Saída:
#   - Caminho da chave privada (informe no Server Profile do WHMCS em "Caminho da Chave SSH")
#   - A linha completa para colar em /home/ncsaas-api/.ssh/authorized_keys
#
# @package    NextcloudSaaS
# @author     Manus AI / Defensys
# @copyright  2026
# @version    3.2.0

set -euo pipefail

# ───────────────────────────────────────────────────────────── Defaults
KEY_PATH="${HOME}/.ssh/whmcs_ncsaas"
YEAR="$(date +%Y)"
COMMENT="whmcs-prod-${YEAR}"
INSTALL_TARGET=""
PRINT_ONLY=false

# ───────────────────────────────────────────────────────────── Parse args
for arg in "$@"; do
    case "$arg" in
        --key-path=*) KEY_PATH="${arg#--key-path=}" ;;
        --comment=*)  COMMENT="${arg#--comment=}" ;;
        --install=*)  INSTALL_TARGET="${arg#--install=}" ;;
        --print-only) PRINT_ONLY=true ;;
        --help|-h)
            sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Argumento desconhecido: $arg" >&2
            echo "Tente: $0 --help" >&2
            exit 2
            ;;
    esac
done

# ───────────────────────────────────────────────────────────── Helpers
log()   { printf '[register-whmcs-key] %s\n' "$1"; }
fatal() { printf '[register-whmcs-key] ERRO: %s\n' "$1" >&2; exit 1; }

# ───────────────────────────────────────────────────────────── 1. Verificar deps
command -v ssh-keygen >/dev/null 2>&1 || fatal "ssh-keygen não encontrado. Instale openssh-client."

# ───────────────────────────────────────────────────────────── 2. Gerar par se ausente
SSH_DIR="$(dirname "$KEY_PATH")"
mkdir -p "$SSH_DIR"
chmod 0700 "$SSH_DIR"

if [ -f "$KEY_PATH" ]; then
    log "Chave já existe em $KEY_PATH (preservando)."
    if [ ! -f "${KEY_PATH}.pub" ]; then
        fatal "Chave privada existe mas chave pública (.pub) está ausente. Remova $KEY_PATH e rode novamente."
    fi
else
    if [ "$PRINT_ONLY" = "true" ]; then
        fatal "--print-only: nenhuma chave encontrada em $KEY_PATH e não foi solicitada criação."
    fi
    log "Gerando par Ed25519 em $KEY_PATH ..."
    ssh-keygen -t ed25519 -N '' -C "$COMMENT" -f "$KEY_PATH" >/dev/null
    chmod 0600 "$KEY_PATH"
    chmod 0644 "${KEY_PATH}.pub"
    log "Chave gerada."
fi

# ───────────────────────────────────────────────────────────── 3. Montar linha authorized_keys
PUBKEY_RAW="$(cat "${KEY_PATH}.pub")"
# Tirar o comentário original e usar o nosso
PUBKEY_FIELDS="$(awk '{print $1, $2}' <<< "$PUBKEY_RAW")"
AUTH_LINE='command="/usr/local/bin/ncsaas-api-shim",no-pty,no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-user-rc '"$PUBKEY_FIELDS"' '"$COMMENT"

# ───────────────────────────────────────────────────────────── 4. Print + opcional install remoto
echo
echo "========================================================================"
echo "Caminho da chave privada (use no Server Profile do WHMCS):"
echo "  $KEY_PATH"
echo
echo "Linha a adicionar em /home/ncsaas-api/.ssh/authorized_keys do servidor"
echo "nextcloud-saas-manager:"
echo
echo "$AUTH_LINE"
echo
echo "========================================================================"

if [ -n "$INSTALL_TARGET" ]; then
    log "Instalando remotamente em $INSTALL_TARGET (precisa de SSH root no manager)..."

    REMOTE_CMD='
        set -e
        mkdir -p /home/ncsaas-api/.ssh
        chown ncsaas-api:ncsaas-api /home/ncsaas-api/.ssh
        chmod 0700 /home/ncsaas-api/.ssh
        touch /home/ncsaas-api/.ssh/authorized_keys
        chown ncsaas-api:ncsaas-api /home/ncsaas-api/.ssh/authorized_keys
        chmod 0600 /home/ncsaas-api/.ssh/authorized_keys
        # idempotente: não duplicar se a chave já estiver presente
        if ! grep -qF "$1" /home/ncsaas-api/.ssh/authorized_keys 2>/dev/null; then
            printf "%s\n" "$1" >> /home/ncsaas-api/.ssh/authorized_keys
            echo "OK: chave adicionada."
        else
            echo "OK: chave já presente — não duplicada."
        fi
    '

    # Passar a linha como arg posicional via heredoc é frágil — usar stdin com sed-safe
    if printf '%s' "$AUTH_LINE" | ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$INSTALL_TARGET" \
        "sudo bash -s -- '$AUTH_LINE'" <<<""; then
        log "Instalação remota concluída."
    else
        log "Instalação remota falhou. Faça manualmente:"
        echo "  ssh $INSTALL_TARGET"
        echo "  sudo -u ncsaas-api tee -a /home/ncsaas-api/.ssh/authorized_keys <<'EOF'"
        echo "  $AUTH_LINE"
        echo "  EOF"
        exit 1
    fi
fi

echo
echo "========================================================================"
echo "Próximos passos:"
echo "  1. No WHMCS, Setup > Products/Services > Servers, edite o servidor"
echo "     do Nextcloud SaaS e configure:"
echo "       Username = ncsaas-api"
echo "       Hostname = <ip-ou-hostname-do-manager>"
echo "       Port     = 22"
echo "  2. No Product Config, em 'Caminho da Chave SSH', informe:"
echo "       $KEY_PATH"
echo "  3. Teste a conexão pelo botão 'Test Connection' do módulo."
echo "  4. O módulo autodetecta o modo shim quando o user é 'ncsaas-api' e"
echo "     deixa de usar sudo / bash -c automaticamente."
echo "========================================================================"
