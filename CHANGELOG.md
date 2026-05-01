# Changelog

Todas as mudanças notáveis deste módulo seguem [Keep a Changelog](https://keepachangelog.com/pt-BR/) e [Semantic Versioning](https://semver.org/lang/pt-BR/).

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
