# Testes

Execute a partir da raiz do Rise com o PHP do XAMPP disponível no `PATH`:

```powershell
php plugins\Chatwoot_plugin\Tests\run_unit.php
php plugins\Chatwoot_plugin\Tests\run_migration_smoke.php
php plugins\Chatwoot_plugin\Tests\run_service_integration.php
php plugins\Chatwoot_plugin\Tests\run_refinement_integration.php
```

Cobertura principal:

- normalização Evolution, mensagens, ordenação e idempotência;
- migrations V001/V002/V003, índices, colunas, backfill legado e segunda execução sem perda de dados;
- envio otimista, locks, status e sincronização com clients falsos;
- contatos, conflitos, tags, busca server-side, resumo e opt-out;
- campanhas, duplicação, toggle e resumo com transporte n8n falso;
- agentes de IA, estado humano, automações e dry-run;
- respostas rápidas, notificações e deduplicação;
- relatórios reais e exportação CSV segura;
- proteção SSRF e sanitização do client n8n;
- mídia `directPath`/base64;
- presença das rotas do contrato 1.1.0.

O teste HTTP opcional exige Apache ativo e uma instalação local acessível:

```powershell
php plugins\Chatwoot_plugin\Tests\run_webhook_http.php
```

Os testes de integração criam registros com identificadores aleatórios e os removem no bloco de limpeza. O transporte n8n e os clients de envio usados na suíte são falsos; eles não disparam campanhas nem mensagens reais.

Antes de publicar, execute também:

```powershell
Get-ChildItem plugins\Chatwoot_plugin -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
node --check plugins\Chatwoot_plugin\Assets\js\chatwoot.js
node --check plugins\Chatwoot_plugin\Assets\js\hub-workspace.js
```
