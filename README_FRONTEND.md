# Impulso Hub Atendimento 1.1.0

Plugin nativo do Rise CRM para atendimento WhatsApp multi-instância com Evolution API e integração progressiva com n8n.

## Estado atual

### Já funcional no backend existente

- múltiplas instâncias Evolution;
- sincronização de conversas;
- histórico;
- envio de texto;
- recebimento por webhook;
- polling;
- deduplicação;
- configurações básicas da Evolution;
- permissões básicas.

### Front refinado nesta versão

- navegação consistente entre todos os módulos;
- caixa de entrada operacional com busca, prioridade, resolução, tags, atribuição e estado da IA;
- seletor completo de emojis;
- respostas rápidas;
- anexo de imagem, áudio e documento;
- gravação de voz pelo navegador;
- viewer de imagem, áudio e PDF/documento;
- busca no histórico carregado;
- notas internas;
- nova conversa;
- contatos com CRUD, filtros, seleção em massa, importação e exportação;
- campanhas com filtros, métricas, wizard em quatro etapas, público, mensagem, mídia e agendamento;
- agentes de IA, automações e controle por instância/conversa;
- relatórios com filtros, gráficos e exportação;
- configurações completas de Evolution, n8n, campanhas, IA, segurança e retenção;
- command palette (`Ctrl/Cmd + K`);
- central de notificações;
- estados vazios, loaders, erros e responsividade;
- tema herdado do Rise, sem fundos brancos rígidos.

## Backend ainda necessário

O front refinado expõe contratos para funcionalidades que precisam ser implementadas pelo Codex. A especificação completa está em:

- [`BACKEND_CONTRACT_REFINED.md`](BACKEND_CONTRACT_REFINED.md)
- [`PROMPT_CODEX_REFINAMENTO_TOTAL.md`](PROMPT_CODEX_REFINAMENTO_TOTAL.md)

Enquanto esses endpoints não forem implementados, o front trata `404/405` de forma controlada e informa que o módulo depende do backend, sem quebrar o chat Evolution existente.

## Arquivos principais do front

```text
Views/index.php
Views/partials/conversations.php
Views/partials/contacts.php
Views/partials/campaigns.php
Views/partials/ai.php
Views/partials/reports.php
Views/partials/settings.php
Views/modals/common.php
Views/partials/styles.php
Assets/js/chatwoot.js
Assets/js/hub-workspace.js
```

## Regra para implementação

O front atual é a fonte de verdade. O backend deve implementar os endpoints existentes sem redesenhar as telas ou substituir IDs/classes utilizados pelo JavaScript.

## Validação desta entrega

- PHP lint em todos os arquivos PHP;
- `node --check` em `chatwoot.js`;
- `node --check` em `hub-workspace.js`;
- integração Evolution já existente preservada por design.

O teste funcional completo de campanhas, IA, anexos, contatos e relatórios depende da implementação backend descrita no contrato.
