# Operação

## Rotina diária

- verifique canais desconectados;
- acompanhe falhas persistentes na fila;
- confirme que o worker executou recentemente;
- revise transferências do bot para humano;
- trate opt-outs antes de reativar campanhas.

## Diagnóstico

- **Contato virou nome do proprietário**: execute primeiro a prévia de reparação, revise e aplique; novos eventos enviados já não renomeiam o contato.
- **Grupo mistura pessoas**: confirme recebimento do participante/JID no webhook Evolution e se a migração V004 foi aplicada.
- **Meta bloqueia texto**: confira se a janela de atendimento está aberta; fora dela use template oficial.
- **Campanha parada**: confira status do canal, janela de horário, limite por minuto, worker e erro por destinatário.
- **Bot não responde**: confirme configuração global, fluxo publicado/ativo, canal, grupo permitido e sessão não pausada.
- **Webhook 401/403**: revise segredo/assinatura e identificador do canal.
- **Webhook 503**: mantenha o retry do provedor e verifique banco/worker.

## Retenção

Os prazos ficam em **Configurações > Segurança**. Logs são técnicos e sanitizados. Credenciais, tokens e corpos brutos sensíveis não devem ser copiados para observações ou prints.

Para limitar crescimento, configure um prazo para **Conversas resolvidas** e mantenha o cache de mídia com prazo finito. A limpeza é executada pelo worker; alterar esses prazos não apaga imediatamente o histórico ativo.
