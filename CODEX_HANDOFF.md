# Continuidade pelo Codex

## Estado entregue

A versão 2.0 implementa o núcleo no plugin PHP/CodeIgniter do Rise: identidade de contatos, grupos/participantes, provedores Evolution/Meta, janela oficial, templates, bot determinístico, fila interna, ocorrências de campanha, histórico operacional, migrações V004–V009 e limpeza dos módulos fora do escopo.

O repositório informado pelo proprietário (`Siamesa-Academia-de-treinamento-mirim/chatwoot`) contém um fork Rails/Vue do Chatwoot e **não é esta base**. Não copie este pacote sobre aquele repositório. Crie um repositório específico para o plugin ou confirme explicitamente uma estratégia de migração antes de publicar.

## Validações ainda dependentes do ambiente do cliente

Estas tarefas não podem ser concluídas sem uma instalação Rise, banco e credenciais reais:

1. executar `run_migration_smoke.php` em MySQL/MariaDB descartável com o prefixo real do Rise;
2. executar `run_service_integration.php` dentro do bootstrap do Rise;
3. testar o workspace em navegador com Bootstrap/tema/idioma reais;
4. validar Evolution real, sobretudo payload de grupos da versão instalada;
5. validar Meta real: verify token, assinatura, templates, mídia HTTPS e recibos;
6. executar campanha de homologação com poucos números autorizados;
7. configurar cron/Task Scheduler e confirmar lock, throughput e retry;
8. revisar logs e índices com volume representativo;
9. realizar teste de upgrade sobre uma cópia do banco de produção;
10. publicar em repositório próprio e pipeline CI.

## Comandos de qualidade

```bash
php Tests/run_unit.php
php Tests/run_product_static.php
php Tests/run_migration_smoke.php
php Tests/run_service_integration.php
php Tests/run_refinement_integration.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check Assets/js/chatwoot.js
node --check Assets/js/hub-workspace.js
git diff --check
```

## Critérios de aceite de homologação

- mensagem enviada não altera o nome do contato;
- dois participantes de grupo aparecem como autores distintos;
- webhook duplicado não duplica mensagem;
- texto Meta fora da janela é bloqueado e template é permitido;
- bot desconhece pergunta fora do escopo, usa fallback e encaminha;
- resposta humana pausa o bot após envio confirmado;
- recorrência cria nova ocorrência e novos snapshots;
- opt-out posterior à criação da campanha impede o envio;
- recibos atualizam o destinatário e a ocorrência corretos;
- operador sem permissão não acessa configurações, campanhas ou bots.

## Arquivos centrais

- `Contracts/WhatsAppProviderInterface.php`
- `Services/Webhook_normalizer.php`
- `Services/Meta_webhook_normalizer.php`
- `Services/Chat_service.php`
- `Services/Group_service.php`
- `Services/Bot_service.php`
- `Services/Campaign_dispatch_service.php`
- `Libraries/Migration_runner.php`
- `Database/Migrations/V004_...` a `V009_...`
