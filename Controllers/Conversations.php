<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Models\Chat_instances_model;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Conversation_action_service;
use Chatwoot_plugin\Services\Conversation_workflow_service;
use Chatwoot_plugin\Services\Audit_service;
use Chatwoot_plugin\Services\Group_service;
use Chatwoot_plugin\Services\Message_send_exception;
use Chatwoot_plugin\Services\Media_service;
use Chatwoot_plugin\Services\Official_template_service;
use Chatwoot_plugin\Services\Conversation_presence_service;
use Chatwoot_plugin\Services\Conversation_bulk_action_service;
use Chatwoot_plugin\Services\Conversation_filter_service;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

class Conversations extends Api_controller
{
    private Chat_service $chat;
    private Chat_conversations_model $conversations;
    private Chat_instances_model $instances;
    private Conversation_action_service $actions;
    private Conversation_workflow_service $workflow;
    private Conversation_filter_service $filterService;

    public function __construct()
    {
        parent::__construct();
        $this->conversations = new Chat_conversations_model();
        $this->instances = new Chat_instances_model();
        $this->chat = new Chat_service(null, $this->conversations);
        $this->actions = new Conversation_action_service($this->conversations);
        $this->workflow = new Conversation_workflow_service();
        $this->filterService = new Conversation_filter_service($this->instances, $this->workflow);
    }

    public function create(): ResponseInterface
    {
        $this->requireSendPermission();
        try {
            return $this->success($this->actions->create($this->input(), $this->actorId()), [], 201);
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel iniciar a conversa.', 422);
        }
    }

    public function index(): ResponseInterface
    {
        try {
            $filters = $this->conversationFilters();
            $result = $this->chat->list_conversations(
                $filters,
                max(1, (int) $this->request->getGet('page')),
                min(100, max(1, (int) ($this->request->getGet('limit') ?: 30)))
            );

            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar as conversas.');
        }
    }

    public function sync(): ResponseInterface
    {
        $input = $this->input();
        $instanceId = (int) ($input['instance_id'] ?? 0);
        $limit = min(100, max(10, (int) ($input['limit'] ?? 100)));
        if ($instanceId < 0) {
            return $this->error('Instancia invalida.', 422);
        }

        try {
            return $this->success($this->chat->sync_chats($instanceId > 0 ? $instanceId : null, $limit));
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel sincronizar as conversas.', 502);
        }
    }

    public function messages(int $id): ResponseInterface
    {
        $limit = min(100, max(1, (int) ($this->request->getGet('limit') ?: 50)));
        $beforeId = (int) $this->request->getGet('before_id');
        $beforeTimestamp = (int) $this->request->getGet('before_timestamp');
        $afterId = (int) $this->request->getGet('after_id');
        $reactionAfter = (int) $this->request->getGet('reaction_after');
        if ($beforeId > 0 && $afterId > 0) {
            return $this->error('Use apenas um cursor de mensagens por solicitacao.', 422);
        }

        try {
            $result = $this->chat->get_messages(
                $id,
                $limit,
                $beforeId > 0 ? $beforeId : null,
                $afterId > 0 ? $afterId : null,
                false,
                $beforeTimestamp > 0 ? $beforeTimestamp : null,
                $reactionAfter > 0 ? $reactionAfter : null
            );

            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            $status = $this->conversations->get_by_id($id) ? 500 : 404;

            return $this->internalFailure($exception, $status === 404 ? 'Conversa nao encontrada.' : 'Nao foi possivel carregar o historico.', $status);
        }
    }

    public function group(int $id): ResponseInterface
    {
        $conversation = $this->conversations->get_by_id($id);
        if (!$conversation) {
            return $this->error('Conversa nao encontrada.', 404);
        }
        if (($conversation['conversation_type'] ?? 'individual') !== 'group') {
            return $this->error('Esta conversa nao representa um grupo.', 422);
        }

        try {
            $group = (new Group_service())->get_group_for_conversation($id);
            if (!$group) return $this->error('Dados do grupo ainda nao foram sincronizados.', 404);

            $group['id'] = (int) ($group['id'] ?? 0);
            $group['participant_count'] = count($group['participants'] ?? []);
            $group['participants'] = array_map(static function (array $participant): array {
                return [
                    'id' => (int) ($participant['id'] ?? 0),
                    'contact_id' => !empty($participant['contact_id']) ? (int) $participant['contact_id'] : null,
                    'jid' => (string) ($participant['participant_jid'] ?? ''),
                    'phone' => (string) ($participant['phone_normalized'] ?? ''),
                    'name' => (string) ($participant['display_name'] ?? ''),
                    'role' => (string) ($participant['role'] ?? 'member'),
                    'is_self' => !empty($participant['is_self']),
                    'last_message_at' => $participant['last_message_at'] ?? null,
                ];
            }, is_array($group['participants'] ?? null) ? $group['participants'] : []);

            return $this->success($group);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar os participantes do grupo.');
        }
    }

    public function sync_messages(int $id): ResponseInterface
    {
        $input = $this->input();
        $limit = min(100, max(1, (int) ($input['limit'] ?? 50)));

        try {
            $result = $this->chat->get_messages($id, $limit, null, null, true);

            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            $status = $this->conversations->get_by_id($id) ? 500 : 404;

            return $this->internalFailure($exception, $status === 404 ? 'Conversa nao encontrada.' : 'Nao foi possivel sincronizar o historico.', $status);
        }
    }

    public function send(int $id): ResponseInterface
    {
        $this->requireSendPermission();
        $input = $this->input();
        $text = trim((string) ($input['text'] ?? ''));
        $clientId = trim((string) ($input['client_message_id'] ?? ''));
        $replyToRaw = $input['reply_to_message_id'] ?? null;
        $replyToMessageId = null;
        if ($replyToRaw !== null && $replyToRaw !== '') {
            if (!is_numeric($replyToRaw) || (int) $replyToRaw < 1) {
                return $this->error('Identificador local da mensagem de resposta invalido.', 422);
            }
            $replyToMessageId = (int) $replyToRaw;
        }

        if ($text === '' || strlen($text) > 4096) {
            return $this->error('A mensagem deve conter entre 1 e 4096 caracteres.', 422);
        }
        if ($clientId === '' || strlen($clientId) > 191 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientId)) {
            return $this->error('Identificador idempotente da mensagem invalido.', 422);
        }

        try {
            return $this->success($this->chat->send_text($id, $text, $clientId, $this->actorId(), $replyToMessageId));
        } catch (Throwable $exception) {
            if ($exception instanceof Message_send_exception) {
                return $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details());
            }
            return $this->internalFailure($exception, 'Nao foi possivel enviar a mensagem.', 422);
        }
    }

    public function show(int $id): ResponseInterface
    {
        try {
            $conversation = $this->chat->get_conversation($id);
            if ($conversation === null) {
                return $this->error('Conversa nao encontrada.', 404);
            }

            return $this->success($conversation);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar a conversa.');
        }
    }

    public function reaction(int $conversationId, int $messageId): ResponseInterface
    {
        $this->requireSendPermission();
        $input = $this->input();
        $emoji = (string) ($input['emoji'] ?? '');
        $remove = !empty($input['remove']);
        $clientId = trim((string) ($input['client_message_id'] ?? ''));
        if ($clientId === '') return $this->error('Identificador idempotente da reacao obrigatorio.', 422);
        try {
            return $this->success($this->chat->send_reaction($conversationId, $messageId, $emoji, $clientId, $this->actorId(), $remove));
        } catch (Throwable $exception) {
            if ($exception instanceof Message_send_exception) return $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details());
            return $this->actionFailure($exception, 'Nao foi possivel enviar a reacao.', 422);
        }
    }

    public function send_template(int $id): ResponseInterface
    {
        $this->requireSendPermission();
        $input = $this->input();
        $templateId = (int) ($input['template_id'] ?? 0);
        $clientId = trim((string) ($input['client_message_id'] ?? ''));
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        if ($templateId < 1) return $this->error('Identificador local de template invalido.', 422);
        if ($clientId === '' || strlen($clientId) > 191 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientId)) {
            return $this->error('Identificador idempotente invalido.', 422);
        }
        try {
            return $this->success($this->chat->send_template_by_id($id, $templateId, $values, $clientId, $this->actorId()));
        } catch (Throwable $exception) {
            if ($exception instanceof Message_send_exception) {
                return $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details());
            }
            return $this->internalFailure($exception, 'Nao foi possivel enviar o template oficial.', 422);
        }
    }

    public function template_media(int $id): ResponseInterface
    {
        $this->requireSendPermission();
        $file = $this->request->getFile('file');
        $kind = strtolower(trim((string) $this->request->getPost('kind')));
        if (!$file || !in_array($kind, ['image', 'video', 'document'], true)) return $this->error('Header de template e arquivo valido sao obrigatorios.', 422);
        try {
            return $this->success((new Media_service())->uploadTemplateMedia($file, $id, $this->actorId(), $kind), [], 201);
        } catch (Throwable $exception) {
            return $exception instanceof Message_send_exception
                ? $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details())
                : $this->internalFailure($exception, 'Nao foi possivel armazenar a midia do template.', 422);
        }
    }

    public function templates(int $id): ResponseInterface
    {
        $this->requireSendPermission();
        try {
            return $this->success((new Official_template_service())->listForConversation($id, [
                'search' => (string) $this->request->getGet('search'),
                'language' => (string) $this->request->getGet('language'),
                'category' => (string) $this->request->getGet('category'),
            ]));
        } catch (Throwable $exception) {
            return $exception instanceof Message_send_exception
                ? $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details())
                : $this->internalFailure($exception, 'Nao foi possivel carregar os templates oficiais.', 422);
        }
    }

    public function sync_templates(int $id): ResponseInterface
    {
        $this->requireManageInstancesPermission();
        try {
            $conversation = $this->conversations->get_by_id($id);
            if (!$conversation) return $this->error('Conversa nao encontrada.', 404);
            $service = new Official_template_service();
            $service->sync((int) ($conversation['instance_id'] ?? 0));
            return $this->success($service->listForConversation($id));
        } catch (Throwable $exception) {
            return $exception instanceof Message_send_exception
                ? $this->error($exception->getMessage(), $exception->getCode() ?: 422, $exception->details())
                : $this->internalFailure($exception, 'Nao foi possivel sincronizar os templates oficiais.', 502);
        }
    }

    public function mark_read(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->mark_read($id, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel marcar a conversa como lida.', 422);
        }
    }

    public function mark_unread(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->mark_unread($id, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel marcar a conversa como nao lida.', 422);
        }
    }

    public function note(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        $input = $this->input();
        try {
            return $this->success($this->actions->add_note($id, (string) ($input['content'] ?? ''), $this->actorId(), (string) ($input['client_message_id'] ?? ''), is_array($input['mention_user_ids'] ?? null) ? $input['mention_user_ids'] : []), [], 201);
        } catch (Throwable $exception) {
            if ($exception instanceof Message_send_exception) return $this->error($exception->getMessage(), $exception->getCode() ?: 409, $exception->details());
            return $this->actionFailure($exception, 'Nao foi possivel adicionar a nota.', 422);
        }
    }

    public function priority(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        $input = $this->input();
        try {
            return $this->success($this->actions->set_priority($id, $input['priority'] ?? 'normal', $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel alterar a prioridade.', 422);
        }
    }

    public function resolve(int $id): ResponseInterface
    {
        return $this->changeStatus($id, 'resolved');
    }

    public function reopen(int $id): ResponseInterface
    {
        return $this->changeStatus($id, 'open');
    }

    public function status(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        $status = strtolower(trim((string) ($this->input()['status'] ?? '')));
        try {
            return $this->success($this->actions->set_status($id, $status, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel alterar o status.', 422);
        }
    }

    public function snooze(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            $input = $this->input();
            return $this->success($this->actions->snooze($id, (string) ($input['snoozed_until'] ?? $input['until'] ?? ''), $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel adiar a conversa.', 422);
        }
    }

    public function unsnooze(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->unsnooze($id, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel reabrir a conversa.', 422);
        }
    }

    public function assignment_options(): ResponseInterface
    {
        try {
            return $this->success($this->workflow->assignmentOptions());
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar as opcoes de atribuicao.');
        }
    }

    public function previous(int $id): ResponseInterface
    {
        try {
            $result = $this->chat->previous_conversations($id, max(1, (int) $this->request->getGet('page')), min(50, max(1, (int) ($this->request->getGet('limit') ?: 20))));
            $result['data'] = array_map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? $row['contact_name'] ?? 'Contato'),
                'status' => (string) ($row['status'] ?? 'open'),
                'last_message_preview' => (string) ($row['last_message_preview'] ?? ''),
                'last_message_at' => $row['last_message_at'] ?? null,
                'last_activity_at' => $row['last_activity_at'] ?? $row['last_message_at'] ?? null,
                'instance' => $row['instance'] ?? null,
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'assignee' => is_array($row['assignee'] ?? null) ? $row['assignee'] : null,
                'team' => is_array($row['team'] ?? null) ? $row['team'] : null,
                'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : [],
            ], $result['data']);
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar conversas anteriores.', 422);
        }
    }

    public function activity(int $id): ResponseInterface
    {
        try {
            $result = (new Audit_service())->conversationActivity($id, max(1, (int) $this->request->getGet('page')), min(100, max(1, (int) ($this->request->getGet('limit') ?: 30))));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar a atividade da conversa.');
        }
    }

    public function presence(int $id): ResponseInterface
    {
        try {
            $input = $this->input();
            return $this->success((new Conversation_presence_service())->touch($id, $this->actorId(), (string) ($input['state'] ?? '')));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel atualizar a presenca.', 422);
        }
    }

    public function presence_show(int $id): ResponseInterface
    {
        try {
            return $this->success((new Conversation_presence_service())->list($id));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel carregar a presenca.', 422);
        }
    }

    public function bulk_action(): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success((new Conversation_bulk_action_service())->execute($this->input(), $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel executar a operacao em massa.', 422);
        }
    }

    public function tags(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        $input = $this->input();
        try {
            return $this->success($this->actions->set_tags($id, is_array($input['tags'] ?? null) ? $input['tags'] : [], $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel alterar as tags.', 422);
        }
    }

    public function assignment(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->assign($id, $this->input(), $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel atribuir a conversa.', 422);
        }
    }

    private function changeStatus(int $id, string $status): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->set_status($id, $status, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->actionFailure($exception, 'Nao foi possivel alterar o status.', 422);
        }
    }

    /** @return array<string,mixed> */
    private function conversationFilters(): array
    {
        $input = [];
        foreach (['status', 'instance', 'instance_id', 'channel', 'assignee', 'assignee_id', 'team', 'team_id', 'tags', 'tag', 'priority', 'unread', 'conversation_type', 'bot_status', 'last_activity_from', 'last_activity_to', 'search'] as $key) {
            $input[$key] = $this->request->getGet($key);
        }
        return $this->filterService->fromQuery($input, $this->actorId());
    }

    private function positiveFilter(array &$filters, string $field, ?string $alias = null): void
    {
        $value = trim((string) ($this->request->getGet($field) ?: ($alias ? $this->request->getGet($alias) : '')));
        if ($value === '') return;
        if (!ctype_digit($value) || (int) $value < 1) throw new \InvalidArgumentException('Filtro numerico invalido.');
        $id = (int) $value;
        if ($field === 'instance_id') {
            $instance = $this->instances->get_by_id($id);
            if (!$instance || empty($instance['active'])) throw new \InvalidArgumentException('Filtro de instancia invalido.');
        }
        if ($field === 'team_id' && !$this->workflow->teamExists($id)) throw new \InvalidArgumentException('Filtro de equipe invalido.');
        $filters[$field] = $id;
    }

    private function safeDate(string $value, bool $endOfDay = false): string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('Filtro de data invalido.', 0, $exception);
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $date = $date->setTime(23, 59, 59);
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function actionFailure(Throwable $exception, string $fallback, int $default): ResponseInterface
    {
        $code = (int) $exception->getCode();
        $status = in_array($code, [404, 409, 422], true) ? $code : $default;
        log_message('error', 'Chatwoot_plugin conversation action failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        return $this->error(in_array($status, [404, 422], true) ? $exception->getMessage() : $fallback, $status);
    }

    private function internalFailure(
        Throwable $exception,
        string $message,
        int $status = 500
    ): ResponseInterface {
        log_message('error', 'Chatwoot_plugin conversations API failed ({exception_type}).', [
            'exception_type' => get_class($exception),
        ]);

        return $this->error($message, $status);
    }
}
