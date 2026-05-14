# Conexão SSH via usuário `ncsaas-api` (modo hardened)

A partir da versão **v12.0** do `nextcloud-saas-manager`, o servidor passa a oferecer um **gateway SSH dedicado** para integrações de API (WHMCS incluído), em substituição ao acesso direto como `root`. Este documento descreve como o módulo WHMCS Nextcloud-SaaS **v3.2.0+** se integra a esse gateway.

## Por que migrar do `root`

O acesso como `root` continua funcionando (modo retrocompatível), porém apresenta três fragilidades operacionais conhecidas:

1. Qualquer comando arbitrário pode ser executado, ampliando o **raio de impacto** de um WHMCS comprometido.
2. Os logs de auditoria do `auth.log` não conseguem distinguir entre operações de manutenção humanas e chamadas de API automatizadas.
3. A rotação de credenciais exige coordenação manual entre infraestrutura e billing.

O usuário `ncsaas-api`, conforme descrito no **Apêndice A.6** do `ARCHITECTURE.md` do manager, resolve os três pontos: shell substituído por `/usr/local/bin/ncsaas-api-shim` que valida cada comando contra uma allowlist (`nextcloud-manage`, `job`, `health`, etc.), recusa metacaracteres (`;`, `|`, `&`, `$()`, `` ` ``, `>`, `<`) e registra cada chamada com `key_id` SHA-256 no `journald` sob a tag `ncsaas-api-ssh`.

## Pré-requisitos no servidor do manager

O usuário `ncsaas-api`, o shim, os drop-ins de `sshd_config.d` e o `sudoers` correspondente são instalados automaticamente pelo `scripts/setup-ssh-gateway.sh` do `nextcloud-saas-manager` em qualquer servidor que execute o `setup-server.sh` em release v12.0 ou superior. Para conferir se o gateway está ativo no seu servidor, execute como root:

```bash
getent passwd ncsaas-api
ls -l /usr/local/bin/ncsaas-api-shim
ls /etc/ssh/sshd_config.d/50-ncsaas-api.conf
```

Se algum desses elementos estiver ausente, basta rodar o setup do manager novamente — ele é idempotente.

## Geração da chave no WHMCS

O módulo WHMCS distribui `scripts/register-whmcs-key.sh`, que **executa no servidor do WHMCS** (não no manager) e produz um par Ed25519 dedicado, com proteção mínima de filesystem e comentário descritivo para auditoria. O script é seguro de rodar múltiplas vezes; se já houver um par em `~/.ssh/whmcs_ncsaas`, ele preserva e apenas reimprime a linha pública pronta para `authorized_keys`.

```bash
# Como o usuário do PHP do WHMCS (tipicamente www-data)
sudo -u www-data bash /caminho/para/modulo/scripts/register-whmcs-key.sh
```

A saída inclui a linha exata a ser instalada em `/home/ncsaas-api/.ssh/authorized_keys` no servidor do manager, contendo todos os flags obrigatórios do contrato:

```
command="/usr/local/bin/ncsaas-api-shim",no-pty,no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-user-rc ssh-ed25519 AAAA... whmcs-prod-2026
```

Se quiser instalar a chave automaticamente no servidor remoto (precisa de SSH root atual no manager), use a flag `--install`:

```bash
sudo -u www-data bash scripts/register-whmcs-key.sh --install=root@manager.example.com
```

Caso o `--install` falhe (por exemplo porque o WHMCS não tem SSH root no manager), faça o passo manualmente: cole a linha impressa em `/home/ncsaas-api/.ssh/authorized_keys` do servidor, mantendo `0600 ncsaas-api:ncsaas-api`.

## Configuração do Server Profile no WHMCS

Em **Setup → Products/Services → Servers**, edite o servidor utilizado pelo módulo Nextcloud-SaaS e ajuste três campos:

| Campo | Valor |
|---|---|
| **Username** | `ncsaas-api` |
| **Hostname / IP** | endereço público do servidor do manager |
| **Port** | `22` (ou o porto customizado do seu `sshd`) |

A senha pode ser deixada em branco; o módulo usará a chave privada gerada acima. No produto, em **Module Settings**, preencha o campo `Caminho da Chave SSH` com o caminho impresso pelo script (tipicamente `/var/www/.ssh/whmcs_ncsaas`). Após salvar, acione **Test Connection**: o resultado deve ser bem-sucedido sem qualquer prompt de senha.

## Comportamento do módulo após a configuração

O módulo detecta automaticamente o usuário `ncsaas-api` no Server Profile e ativa internamente o `shimMode`. Nesse modo, três alterações ocorrem em relação ao modo `root`:

1. Comandos deixam de receber prefixo `sudo -n` e o sufixo `2>&1`, porque o shim já encapsula essa lógica do lado do servidor.
2. Apenas chamadas a `/usr/local/bin/nextcloud-manage` e seus subcomandos da allowlist são emitidas; comandos arbitrários (`bash -c`, `docker exec`, `cat /opt/...`) deixam de ser usados, já que seriam rejeitados pelo shim.
3. As capabilities do servidor (`detectServerCapabilities()`) passam a refletir o **shim_user = ncsaas-api** no log, permitindo que o painel admin diferencie ambientes legacy (`root`) de ambientes hardened.

## Rotação de chave

A rotação segue o procedimento descrito no `authorized_keys.example` do manager: gerar nova chave no WHMCS (com comentário novo, ex. `whmcs-prod-2027`), adicionar uma **nova linha** em `authorized_keys` no manager, atualizar o campo `Caminho da Chave SSH` do produto WHMCS para apontar para a nova chave privada, validar com **Test Connection** e só depois remover a linha antiga. O `journald` do manager registra `key_id` SHA-256 em cada chamada (`journalctl -t ncsaas-api-ssh`), permitindo confirmar que a chave antiga deixou de ser usada antes da remoção.

## Kill-switch

Em situação de incidente, o operador do manager pode bloquear todo o acesso do WHMCS instantaneamente com:

```bash
sudo usermod -L ncsaas-api
```

O `tblhosting` no WHMCS continuará servido pela chave SSH bloqueada — qualquer chamada futura do módulo receberá `Permission denied` e cairá no fluxo de erro nativo, sem impacto em outros serviços do servidor.
