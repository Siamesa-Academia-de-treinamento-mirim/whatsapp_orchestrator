# Impulso Hub Atendimento 2.0

Central especializada de atendimento WhatsApp para o Rise CRM. O produto mantém a experiência operacional de uma caixa de entrada moderna, mas limita o escopo ao que é necessário para WhatsApp: conversas, contatos, grupos, canais, campanhas, bots determinísticos, respostas rápidas, configurações e logs técnicos.

## Capacidades

- Evolution API para conversas individuais, grupos, mídia, histórico e recibos disponíveis pelo provedor.
- WhatsApp Cloud API para mensagens oficiais, templates aprovados e recibos de entrega/leitura.
- Identidade correta de contato: eventos enviados nunca sobrescrevem o destinatário com o `pushName` do proprietário da instância.
- Grupos com entidade, participante e remetente individual por mensagem.
- Campanhas oficiais e não oficiais em fila interna, com rate limit, retry, opt-out, idempotência e histórico por ocorrência.
- Bot sem IA, baseado em estados e regras publicadas, com fallback, handoff humano, limite de falhas, sessão e simulador.
- Credenciais protegidas, assinatura de webhook, payload técnico sanitizado e retenção configurável.

## Fora do escopo

Captain/IA generativa, relatórios gerenciais, SLA, live chat, SMS, portal, help center e automações n8n. As tabelas históricas legadas não são apagadas durante o upgrade, mas os módulos executáveis e as rotas públicas foram retirados.

## Requisitos

- Rise CRM 3.9.6 ou superior.
- PHP compatível com o Rise, com cURL, JSON, OpenSSL e mbstring.
- MySQL/MariaDB compatível com locks nomeados (`GET_LOCK`).
- HTTPS público para webhooks e mídia da Meta.
- Evolution API v2 para o canal não oficial e/ou conta configurada no WhatsApp Cloud API.

## Instalação e atualização

1. Faça backup do banco e da pasta do plugin atual.
2. Extraia a pasta `Chatwoot_plugin` em `plugins/Chatwoot_plugin` no Rise.
3. Instale, ative ou atualize pelo gerenciador de plugins do Rise.
4. O hook executará as migrações V001 a V009 de forma incremental.
5. Revise as permissões dos papéis e os canais cadastrados.
6. Agende o worker a cada minuto.

Instalações antigas que usavam n8n são migradas para `internal_queue`. Campanhas que estavam em execução são pausadas para revisão, evitando duplicidade de disparos.

## Worker

Em instalações sem `spark` na raiz:

```bash
php plugins/Chatwoot_plugin/cron.php 50
```

Em instalações que registram comandos CodeIgniter:

```bash
php spark impulso:chat-jobs 50
```

Agende uma execução por minuto. O banco impede processamento concorrente do mesmo lote.

## Testes rápidos

```bash
php Tests/run_unit.php
php Tests/run_product_static.php
php Tests/run_migration_smoke.php
php Tests/run_service_integration.php
php Tests/run_refinement_integration.php
```

Os três últimos exigem o runtime completo do Rise e banco de testes. Consulte [TESTING.md](TESTING.md).

## Documentação

- [Arquitetura](docs/ARCHITECTURE.md)
- [Configuração Evolution](EVOLUTION_INTEGRATION.md)
- [Configuração Meta Cloud API](docs/META_CLOUD_SETUP.md)
- [Bots determinísticos](docs/BOT_FLOW_SCHEMA.md)
- [Campanhas e worker](docs/CAMPAIGN_WORKER.md)
- [Upgrade 2.0](docs/UPGRADE_2_0.md)
- [Operação](OPERATIONS.md)
- [Continuidade pelo Codex](CODEX_HANDOFF.md)
