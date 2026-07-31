# Refinamento de front — 1.1.0

## Diagnóstico da versão recebida

A versão anterior possuía um núcleo funcional para mensagens de texto, mas as demais áreas eram majoritariamente protótipos:

- campanhas e IA estavam desabilitadas no HTML;
- contatos eram derivados das conversas e filtrados localmente;
- vários botões não tinham handler;
- emoji inseria apenas um caractere fixo;
- mídia usava visualização básica;
- anexos, áudio gravado, notas, prioridade, resolução, tags e atribuição não tinham contrato completo;
- busca global, notificações e nova conversa estavam inativas;
- relatórios usavam estados vazios/estáticos.

## Mudanças realizadas

1. Ativação visual e comportamental de todos os módulos.
2. Nova camada `hub-workspace.js`, isolada do runtime Evolution existente.
3. Contratos server-side para cada ação nova.
4. Upload com `FormData` preparado no client.
5. Viewer de mídia e player de áudio.
6. Gravação por `MediaRecorder`.
7. Emoji picker e respostas rápidas.
8. Wizard completo de campanhas.
9. Contatos com ações em massa.
10. Painéis de IA e automações.
11. Relatórios com atualização/exportação.
12. Settings completos, incluindo n8n e retenção.
13. Command palette e notificações.
14. CSS responsivo e compatível com os tokens do Rise.

## Princípio de compatibilidade

A implementação não remove nem substitui o backend Evolution existente. Funções novas usam endpoints adicionais. Quando um endpoint ainda não existe, o erro é isolado ao módulo e o envio/recebimento de texto continua operando.
