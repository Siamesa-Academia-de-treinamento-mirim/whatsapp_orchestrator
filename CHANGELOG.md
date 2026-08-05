# Changelog

## 2.0.0 — 2026-07-30

- corrige sobrescrita de contatos por `pushName` em mensagens enviadas;
- adiciona grupos, participantes e autoria individual por mensagem;
- introduz contrato neutro de provedor, Evolution e Meta Cloud API;
- adiciona verificação/assinatura Meta, templates oficiais e janela de atendimento;
- substitui campanhas n8n por fila interna com runs, snapshots, idempotência, retry, rate limit, opt-out e recibos;
- adiciona bot determinístico versionado, simulador, fallback, handoff e pausa humana;
- adiciona reparação assistida de contatos contaminados;
- remove rotas e código executável de IA, relatórios gerenciais, SLA e n8n;
- preserva dados históricos legados sem expor os módulos retirados;
- simplifica navegação, configurações e permissões;
- adiciona migrações V004–V009 e suíte de contrato do produto;
- torna a caixa de entrada local-first, limita sincronizações remotas, mantém envios otimistas em ordem e compacta payloads de texto.

## 1.1.0

- domínio operacional ampliado para contatos, mídia, campanhas e integrações;
- envio/recebimento Evolution, polling, webhook autenticado e runner CLI.

## 1.0.0

- base multi-instância Evolution para conversas e mensagens de texto.
