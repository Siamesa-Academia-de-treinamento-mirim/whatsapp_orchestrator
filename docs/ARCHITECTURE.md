# Arquitetura

## Camadas

1. **Controllers**: autenticação, autorização, validação HTTP e serialização.
2. **Services**: regras de contato, grupo, conversa, bot, campanha e template.
3. **Providers**: diferenças entre Evolution e Meta Cloud API.
4. **Libraries**: clientes HTTP, criptografia, migração, permissões e utilitários.
5. **Models**: persistência no domínio `chat_*`.
6. **Views/Assets**: workspace operacional do Rise.

O restante do sistema depende de `WhatsAppProviderInterface`, não de endpoints específicos do provedor.

## Caixa de entrada e desempenho

- A interface lê primeiro as conversas e mensagens já normalizadas no banco local.
- Webhooks são o caminho principal para novas mensagens e alterações de status.
- A sincronização Evolution é um mecanismo de recuperação em segundo plano, com intervalo mínimo de 30 segundos e uma instância por vez.
- Listas remotas são paginadas em até 100 conversas e o histórico remoto em até 50 mensagens por conversa.
- Mensagens de texto guardam somente um envelope técnico compacto; mídia e mensagens estruturadas preservam o payload sanitizado necessário para recuperação segura.
- Envios aparecem de forma otimista e entram em uma fila sequencial no navegador, preservando a ordem sem bloquear o campo de resposta.

## Identidade

- Contato individual: telefone normalizado e JID remoto por instância.
- Grupo: `chat_groups`, identificado por `instance_id + remote_jid`.
- Participante: `chat_group_participants`, identificado por grupo e `participant_jid`.
- Mensagem: guarda a conversa e também `sender_jid`, `sender_phone`, `sender_name` e `sender_contact_id`.
- Mensagem `fromMe=true` nunca usa `pushName` para atualizar o contato destinatário.
- Nome manual possui precedência sobre nome recebido automaticamente.

## Provedores

### Evolution

Suporta grupos, sincronização de chats/histórico, texto e mídia conforme a versão instalada. O webhook é normalizado por `Webhook_normalizer`.

### Meta Cloud

Suporta templates oficiais, texto/mídia dentro da janela de atendimento e recibos. A assinatura `X-Hub-Signature-256` é validada antes da normalização. O `phone_number_id` recebido deve corresponder ao canal cadastrado.

## Campanhas

A campanha define público, conteúdo, agenda e limites. Cada execução cria um `chat_campaign_runs`; a audiência da execução é congelada em `chat_campaign_run_recipients`. Isso preserva histórico e impede que uma edição posterior altere uma ocorrência já iniciada.

## Bots

Fluxos publicados são imutáveis. Uma edição gera um novo rascunho/versão, enquanto sessões em andamento continuam na versão com que começaram. A avaliação aceita somente operadores declarados pelo validador; não há `eval`, regex fornecida pelo usuário ou chamada de IA.

## Segurança

- tokens e segredos não retornam nas APIs públicas;
- webhook Evolution usa segredo direto, Bearer ou HMAC;
- webhook Meta usa assinatura do App Secret;
- mídia pública usa URL assinada e expiração;
- payload técnico é sanitizado;
- fila e webhooks usam idempotência e locks;
- ações obedecem permissões do Rise.
