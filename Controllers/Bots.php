<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Bot_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Bots extends Api_controller
{
    private Bot_service $service;
    public function __construct() { parent::__construct(); $this->service = new Bot_service(); }

    public function index(): ResponseInterface
    {
        $this->requireManageBotsPermission();
        try {
            $filters = [
                'instance_id' => (int) $this->request->getGet('instance_id') ?: null,
                'status' => trim((string) $this->request->getGet('status')) ?: null,
                'active' => $this->request->getGet('active') !== null ? (filter_var($this->request->getGet('active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null,
            ];
            $result = $this->service->list($filters, max(1, (int) $this->request->getGet('page')), min(100, max(1, (int) ($this->request->getGet('limit') ?: 30))));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $e) { return $this->internalFailure($e, 'Nao foi possivel carregar os bots.'); }
    }

    public function show(int $id): ResponseInterface
    {
        $this->requireManageBotsPermission();
        $row = $this->service->get($id);
        return $row ? $this->success($row) : $this->error('Bot nao encontrado.', 404);
    }

    public function create(): ResponseInterface { $this->requireManageBotsPermission(); return $this->save(null); }
    public function update(int $id): ResponseInterface { $this->requireManageBotsPermission(); return $this->save($id); }

    public function publish(int $id): ResponseInterface
    {
        $this->requireManageBotsPermission();
        try { return $this->success($this->service->publish($id, $this->actorId())); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    public function toggle(int $id): ResponseInterface
    {
        $this->requireManageBotsPermission();
        try { return $this->success($this->service->toggle($id, $this->actorId())); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    public function simulate(): ResponseInterface
    {
        $this->requireManageBotsPermission();
        $input = $this->input();
        try { return $this->success($this->service->simulate($input['definition'] ?? [], is_array($input['inputs'] ?? null) ? $input['inputs'] : [])); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    public function delete(int $id): ResponseInterface
    {
        $this->requireManageBotsPermission();
        try { $this->service->delete($id, $this->actorId()); return $this->success(['id' => $id, 'deleted' => true]); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    public function pause_conversation(int $conversationId): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        $reason = trim((string) ($this->input()['reason'] ?? 'manual_pause'));
        try { return $this->success($this->service->pauseConversation($conversationId, $this->actorId(), $reason)); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    public function resume_conversation(int $conversationId): ResponseInterface
    {
        $this->requireManageConversationsPermission();
        try { return $this->success($this->service->resumeConversation($conversationId, $this->actorId())); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    private function save(?int $id): ResponseInterface
    {
        try { return $this->success($this->service->save($this->input(), $this->actorId(), $id), [], $id ? 200 : 201); }
        catch (Throwable $e) { return $this->failure($e); }
    }

    private function failure(Throwable $e): ResponseInterface
    {
        $code = (int) $e->getCode();
        return $this->error($e->getMessage(), in_array($code, [404,409,422,502,503], true) ? $code : 422);
    }
}
