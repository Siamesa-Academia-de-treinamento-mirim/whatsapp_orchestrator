# Prompt inicial para o Codex — backend do plugin

> **Documento histórico e superado.** Este prompt descrevia uma etapa anterior limitada a instâncias. O MVP atual inclui conversas, histórico, envio de texto, webhook e polling conforme [`EVOLUTION_INTEGRATION.md`](EVOLUTION_INTEGRATION.md).

Você trabalhará em dois repositórios:

1. Este plugin Rise, cujo front já está pronto.
2. O repositório ImpulsoHub/Chatwoot, usado como referência funcional para conversas, mensagens, contato lateral, estados, atribuição, equipes, inboxes e eventos.

## Objetivo desta tarefa

Implemente somente a fundação do backend do plugin `Chatwoot_plugin` no Rise CRM.

Nesta etapa:

- preserve integralmente o front existente;
- não redesenhe telas;
- não remova dados simulados até existir uma resposta real equivalente;
- crie migrations, models, permissões e configurações;
- implemente o cadastro e teste de conexão da Evolution API;
- implemente o CRUD de instâncias Evolution;
- implemente logs técnicos e auditoria básicos;
- conecte somente a tela “Instâncias” aos endpoints reais;
- mantenha as demais telas operando com mocks;
- documente todas as alterações;
- adicione testes para migrations, validações e cliente HTTP;
- não implemente campanhas, IA ou mensagens nesta etapa.

Leia `BACKEND_CONTRACT.md` antes de alterar o código.

## Critérios de conclusão

1. O plugin instala e atualiza sem erro.
2. As tabelas usam o prefixo do Rise.
3. Credenciais ficam criptografadas em repouso.
4. O usuário pode cadastrar, editar, testar, conectar, reiniciar e desconectar uma instância.
5. Falhas externas são tratadas sem quebrar a página.
6. A tela de instâncias não contém dados mockados quando o backend estiver configurado.
7. Nenhuma outra tela ou CSS é alterada.
8. Há instruções de configuração e execução dos testes.


## Regra adicional — canais

O trilho de “Canais” não é decorativo. Implemente cada item como uma instância Evolution real. O filtro deve consultar o backend por `instance_id`, atualizar contadores e impedir envio quando a instância estiver desconectada. Não remova nem substitua esse componente por um select global no desktop.


## Regra visual obrigatória

O plugin herda o tema do Rise e segue o padrão do `Siamesa_gerencial_plugin`. Não reintroduza detecção de tema por JavaScript, fundos brancos, cores globais de texto, estilos próprios para `.form-control`, `.btn`, `.modal-content`, `.dropdown-menu`, `.card` ou `.page-title`. CSS próprio deve permanecer restrito à estrutura do chat, responsividade, balões de mensagem e estados funcionais.
