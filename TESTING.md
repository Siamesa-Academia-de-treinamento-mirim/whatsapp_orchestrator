# Testes

## Sem runtime do Rise

```bash
php Tests/run_unit.php
php Tests/run_product_static.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check Assets/js/chatwoot.js
node --check Assets/js/hub-workspace.js
```

## Com Rise e banco de testes

A partir da raiz do Rise:

```bash
php plugins/Chatwoot_plugin/Tests/run_migration_smoke.php
php plugins/Chatwoot_plugin/Tests/run_service_integration.php
php plugins/Chatwoot_plugin/Tests/run_refinement_integration.php
```

Use banco descartável. Os testes de migração validam V001–V009, segunda execução idempotente, índices e preservação de dados. Os testes de serviço usam clientes falsos e não devem enviar mensagens reais.

## Homologação manual mínima

1. receber e responder contato individual pela Evolution;
2. enviar mensagem e confirmar que o nome do cliente não muda;
3. receber mensagens de dois participantes no mesmo grupo;
4. verificar, receber e responder pelo canal Meta;
5. testar bloqueio de texto Meta fora da janela e envio de template;
6. publicar bot, testar fallback, handoff e pausa humana;
7. executar campanha pequena, conferir retry e recibos;
8. editar campanha recorrente e confirmar histórico imutável;
9. validar opt-out no momento do envio;
10. testar permissões de operador sem acesso administrativo.
