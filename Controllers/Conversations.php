<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Models\Chat_conversations_model;
use Chatwoot_plugin\Services\Chat_service;
use Chatwoot_plugin\Services\Conversation_action_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Conversations extends Api_controller
{
    private Chat_service $chat;
    private Chat_conversations_model $conversations;
    private Conversation_action_service $actions;

    public function __construct()
    {
        parent::__construct();
        $this->conversations = new Chat_conversations_model();
        $this->chat = new Chat_service(null, $this->conversations);
        $this->actions = new Conversation_action_service($this->conversations);
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
        $status = strtolower(trim((string) $this->request->getGet('status')));
        if (!in_array($status, ['', 'all', 'open', 'pending', 'unassigned', 'resolved'], true)) {
            return $this->error('Filtro de status invalido.', 422);
        }

        $filters = [
            'archived' => false,
            'search' => substr(trim((string) $this->request->getGet('search')), 0, 191),
        ];
        if ($status !== '' && $status !== 'all') {
            $filters['status'] = $status;
        }
        $instanceId = (int) $this->request->getGet('instance_id');
        if ($instanceId > 0) {
            $filters['instance_id'] = $instanceId;
        }

        try {
            $result = $this->chat->list_conversations(
                $filters,
                max(1, (int) $this->request->getGet('page')),
                min(100, max(1, (int) ($this->request->getGet('limit') ?: 30)))
            );

            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel carregar as conversas.');
        }
    }

    public function sync(): ResponseInterface
    {
        $input = $this->input();
        $instanceId = (int) ($input['instance_id'] ?? 0);
        if ($instanceId < 0) {
            return $this->error('Instancia invalida.', 422);
        }

        try {
            return $this->success($this->chat->sync_chats($instanceId > 0 ? $instanceId : null));
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
                $beforeTimestamp > 0 ? $beforeTimestamp : null
            );

            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            $status = $this->conversations->get_by_id($id) ? 500 : 404;

            return $this->internalFailure($exception, $status === 404 ? 'Conversa nao encontrada.' : 'Nao foi possivel carregar o historico.', $status);
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

        if ($text === '' || strlen($text) > 4096) {
            return $this->error('A mensagem deve conter entre 1 e 4096 caracteres.', 422);
        }
        if ($clientId === '' || strlen($clientId) > 191 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientId)) {
            return $this->error('Identificador idempotente da mensagem invalido.', 422);
        }

        try {
            return $this->success($this->chat->send_text($id, $text, $clientId, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel enviar a mensagem.', 422);
        }
    }

    public function mark_read(int $id): ResponseInterface
    {
        if (!$this->conversations->get_by_id($id)) {
            return $this->error('Conversa nao encontrada.', 404);
        }

        try {
            $this->conversations->mark_read($id);

            return $this->success(['id' => $id, 'unread_count' => 0]);
        } catch (Throwable $exception) {
            return $this->internalFailure($exception, 'Nao foi possivel marcar a conversa como lida.');
        }
    }

    public function note(int $id): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try {
            return $this->success($this->actions->add_note($id, (string) ($this->input()['content'] ?? ''), $this->actorId()), [], 201);
        } catch (Throwable $exception) {
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
