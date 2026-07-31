# WhatsApp Orchestrator para Rise CRM

Plugin independente de atendimento omnichannel para Rise CRM, identificado na aplicação como **Impulso Hub Atendimento**. O projeto integra o Rise à Evolution API e inclui conversas, contatos, instâncias, campanhas, automações, agentes de IA, relatórios, webhooks e processamento periódico.

## Requisitos

- Rise CRM 3.9.6 ou superior;
- uma instância do Rise com acesso ao gerenciador de plugins;
- Evolution API acessível pelo servidor do Rise;
- n8n opcional para campanhas e automações integradas.

## Instalação

O diretório instalado deve manter o nome `Chatwoot_plugin`, pois esse é o namespace e o identificador interno usados pelo plugin.

Na raiz do Rise:

```powershell
git clone https://github.com/Siamesa-Academia-de-treinamento-mirim/whatsapp_orchestrator.git plugins/Chatwoot_plugin
```

Depois, ative **Impulso Hub Atendimento** no gerenciador de plugins do Rise. A ativação executa as migrations idempotentes do banco de dados. Configure as permissões dos papéis e as credenciais da Evolution API pela tela de configurações do plugin; não grave credenciais no repositório.

Para atualizar uma instalação existente:

```powershell
git -C plugins/Chatwoot_plugin pull --ff-only
```

Em seguida, acesse o Rise como administrador para que eventuais migrations pendentes sejam aplicadas.

## Operação e integrações

- [Operação, cron, webhook e retenção](OPERATIONS.md)
- [Integração com Evolution API](EVOLUTION_INTEGRATION.md)
- [Integração com n8n](N8N_INTEGRATION.md)
- [Testes e validação](TESTING.md)
- [Histórico de versões](CHANGELOG.md)

O job periódico do plugin deve ser agendado conforme `OPERATIONS.md`. Ele pode chamar serviços reais da Evolution API e do n8n; valide as configurações antes de executá-lo em produção.

## Segurança

Chaves da Evolution API, segredos de webhook e tokens do n8n devem ser configurados na interface do plugin e nunca enviados ao Git. O endpoint de webhook aceita segredo direto, Bearer token ou assinatura HMAC SHA-256.
