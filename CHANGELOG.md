# Changelog

## 1.1.0

- adiciona o domínio operacional V002 para contatos, tags, notas, mídia, campanhas, IA, automações, notificações, auditoria e jobs;
- conclui CRUD e ações server-side dos módulos habilitados no front;
- adiciona upload/envio/proxy seguro de mídia, áudio gravado e recuperação Evolution por `directPath`/base64;
- adiciona contatos reais com importação/exportação, filtros, conflitos e opt-out;
- integra campanhas, templates, público, IA e automações ao n8n por client centralizado;
- adiciona relatórios reais, CSV, busca global, notificações e preferências do navegador;
- adiciona permissões granulares, CSRF, HMAC, rate limit, auditoria e sanitização de segredos;
- adiciona retry/backoff, reconciliação, retenção, auditoria consultável e runner CLI para o pacote Rise sem `spark`;
- vincula conversas legadas aos contatos normalizados por migration de compatibilidade V003;
- corrige o envio/recebimento e a ordenação cronológica de mensagens Evolution;
- amplia testes unitários, migrations e integrações com providers falsos.

## 1.0.0

- base multi-instância Evolution para conversas e mensagens de texto;
- persistência local, polling, webhook autenticado e configurações globais/por instância.
