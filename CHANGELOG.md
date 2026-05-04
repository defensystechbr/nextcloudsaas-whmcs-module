# Changelog

Todas as mudanças notáveis deste módulo seguem [Keep a Changelog](https://keepachangelog.com/pt-BR/) e [Semantic Versioning](https://semver.org/lang/pt-BR/).

## v3.1.4 (2026-05-04)

### Removido
- **Admin Service Tab (`nextcloudsaas_AdminServicesTabFields`):** removidos os campos **URL do Collabora**, **URL do Signaling** e as duas linhas extras (`collabora-01.defensys.seg.br`, `signaling-01.defensys.seg.br`) do campo **DNS Necessários**. Apenas o domínio principal do cliente passa a aparecer no campo, agora rotulado como **DNS Necessário (Registro A)**, em consonância com o painel do assinante (já limpo na v3.1.3) e com a arquitetura compartilhada do manager v11.x.

### Corrigido
- **Estado da Instância:** a contagem agora considera **3 containers dedicados** (`<cliente>-app`, `<cliente>-cron`, `<cliente>-harp`) em vez de 10. A rótulo passa a indicar *"Ativo (3/3 containers dedicados)"*. Para verificar o estado dos serviços globais (`shared-*`) continue usando o botão **Serviços Compartilhados** (já disponível desde a v3.0.0).

## v3.1.3 (2026-05-04)

### Removido
- **Painel da área do cliente (`clientarea.tpl`):** removidas as seções **Collabora Online**, **Banco de Dados (MariaDB)**, **TURN Server** e **Signaling Server** do bloco de credenciais. Esses serviços passaram a ser globais (`shared-*`) na arquitetura v11.x do manager e não pertencem mais ao assinante; expor URLs/secrets confundia o cliente. A seção **HaRP (AppAPI)** continua exposta porque é dedicada por cliente (`<cliente>-harp`).
- **Painel da área do cliente:** removido o lembrete *"Os serviços auxiliares Collabora Online (...) e Talk HPB (...) são publicados em domínios globais da Defensys e não exigem nenhuma configuração DNS de sua parte"* logo abaixo da tabela de DNS — era útil na transição v2.x → v3.0.0, agora só polui a tela.
- **E-mail de provisionamento (`nextcloudsaas_hooks.php`):** removidas as linhas Collabora Online / Talk (HPB) do bloco *"Serviços Incluídos"* e o mesmo lembrete sobre domínios globais no bloco *"Registro DNS configurado"*.

## v3.1.2 (2026-05-04)

### Corrigido
- `nextcloudsaas_CreateAccount`: domínio agora é obtido via novo `Helper::getDomain($params)`, que prioriza `$params['domain']` mas faz fallback para `$params['customfields']` (chaves com/sem acento, em PT/EN) e, em último recurso, consulta diretamente `tblcustomfieldsvalues` pelo `serviceid`/`pid`. Resolve a falha **"Domínio inválido ou não fornecido"** em pedidos criados pelo admin via **Orders > Add New Order** (fluxo no qual o hook `AfterShoppingCartCheckout` não dispara).
- Mensagens de erro do `CreateAccount` agora distinguem **(a)** Custom Field ausente no produto, **(b)** Custom Field existente porém vazio no serviço, e **(c)** domínio com formato inválido — com instruções claras de onde corrigir.
- `tblhosting.domain` é sincronizado de forma idempotente quando o módulo recupera o domínio via fallback (corrige a tabela para que `ChangePassword`, `ChangePackage` e SSO funcionem nos pedidos do admin).

### Adicionado
- Hook `AcceptOrder` em `includes/hooks/nextcloudsaas_hooks.php`: espelha `AfterShoppingCartCheckout` para pedidos criados pelo admin. Ao aceitar um pedido, o valor do Custom Field **"Domínio da Instância"** é copiado para `tblhosting.domain` e `username` é definido como `admin`.
- `Helper::getDomain(array $params): string` — nova API pública e testada para resolver domínio em qualquer fluxo.

### Documentado
- README §2.4.1 **Custom Fields obrigatórios**: detalha o **Campo 0 “Domínio da Instância”** (Field Type, Validation regex, Required, Show on Order Form) e inclui nota explícita sobre como preencher o campo em pedidos criados pelo admin **antes** de clicar **Accept Order**.

## v3.1.1 (2026-05-04)

### Corrigido
- `SSHManager`: a porta `22` agora é fallback robusto sempre que o WHMCS envia `0`, string vazia ou um valor fora do intervalo `1–65535`. Antes, o admin precisava marcar **"Override with Custom Port"** com `22` manualmente.
- `SSHManager::testConnection()`: mensagem de erro passou a incluir **dicas específicas** baseadas no sintoma. Quando ocorre o clássico `Connection closed by server` (causado pelo padrão `PasswordAuthentication no` em `/etc/ssh/sshd_config.d/60-cloudimg-settings.conf` das imagens cloud do Ubuntu 24.04+), o módulo agora indica exatamente o ficheiro a editar e o `systemctl reload ssh` necessário. Também há dicas para `authentication failed` (credenciais) e `timeout` (firewall).

### Documentado
- README §2.1 (Pré-requisitos do Servidor): exigência explícita de `PasswordAuthentication yes` (ou autenticação por chave SSH) e da configuração **Override with Custom Port = 22** no WHMCS.

## v3.1.0 (2026-05-01)

### Adicionado
- Botão admin **"Listar Instâncias do Servidor"** (`listAllInstances`): dashboard HTML consolidado com todas as instâncias provisionadas no servidor, estado dos 3 containers dedicados (`app`, `cron`, `harp`) e uso de disco por instância (`du -sh`).
- Botão admin **"Ver Logs Talk Recording"** (`viewRecordingLogs`): mostra `docker logs --tail 100 shared-recording` no painel admin.
- **CI GitHub Actions** (`.github/workflows/ci.yml`): `php -l` em todos os ficheiros PHP em PRs/pushes para `main`/`develop` + execução de PHPUnit.
- **Release GitHub Actions** (`.github/workflows/release.yml`): a cada `git push origin vX.Y.Z` valida que `whmcs.json::version` bate com a tag, empacota o ZIP, cria o GitHub Release e anexa o artefato. Suporta corpo do release lido de `CHANGELOG.md`.
- **Suite PHPUnit inicial** em `tests/` (PHPUnit 10.5) validando o contrato público do `Helper`: 1 DNS por cliente, 3 sufixos de container dedicado, 8 serviços globais `shared-*`, hostnames globais independentes do domínio do cliente. 6 testes / 17 assertions.

### Alterado
- `AdminCustomButtonArray()` passa a expor 12 botões (eram 10).
- README e USAGE atualizados com a nova seção Changelog e a nova tabela de botões admin.

## v3.0.0 (2026-05-01) — BREAKING

### Alterado (BREAKING)
- Alinha o módulo à arquitetura compartilhada do Nextcloud SaaS Manager v11.x (`manage.sh` v11.3+).
- Cada cliente passa a ter **3 containers dedicados** (`<cliente>-app`, `<cliente>-cron`, `<cliente>-harp`) em vez de 10.
- **8 serviços globais `shared-*`** (db, redis, collabora, turn, nats, janus, signaling, recording) compartilhados entre todas as instâncias.
- Cada cliente passa a exigir **apenas 1 registro DNS A** (era 3): Collabora, Talk HPB e TURN agora ficam em hostnames globais.

### Adicionado
- `Helper::SHARED_HOSTNAMES_DEFAULT` e `Helper::getSharedHostnames()` (com override via `configoption7..9`).
- `Helper::SHARED_CONTAINERS` (lista dos 8 serviços globais).
- `SSHManager::verifySharedServices()` para checar os 8 `shared-*`.
- Botão admin **"Serviços Compartilhados"** (`checkSharedServices`).

### Removido (deprecado mas mantido como stub)
- `Helper::getCollaboraDomain()` e `Helper::getSignalingDomain()` agora devolvem o hostname global, ignorando o domínio do cliente.

## v2.6.1 (2026-04-07)
- Correção crítica: Fatal Error por redeclaração de funções (`hooks.php` do módulo agora é apenas referência).
- Hook alterado de `DailyCronJob` para `AfterCronJob` (executa a cada cron do WHMCS).
- Status do serviço muda automaticamente de `Pending` para `Active` após provisionamento bem-sucedido via cron.
- HTML escapado no botão "Verificar DNS".

## v2.6.0 (2026-04-04)
- Cron de provisionamento automático: verifica DNS a cada execução do cron WHMCS, cria a instância quando o registro principal estiver correto e envia e-mail com credenciais.

(Para o histórico completo das versões 2.0.0 → 2.5.4 ver o README.md)
