<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Saved_view_service;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Saved_views extends Api_controller
{
    private Saved_view_service $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new Saved_view_service();
    }

    public function index(): ResponseInterface
    {
        try { return $this->success($this->service->listForUser($this->actorId())); }
        catch (Throwable $exception) { return $this->failure($exception, 'Nao foi possivel carregar as visualizacoes.'); }
    }

    public function create(): ResponseInterface
    {
        try { return $this->success($this->service->create($this->input(), $this->actorId()), [], 201); }
        catch (Throwable $exception) { return $this->failure($exception, 'Nao foi possivel salvar a visualizacao.'); }
    }

    public function update(int $id): ResponseInterface
    {
        try { return $this->success($this->service->update($id, $this->input(), $this->actorId())); }
        catch (Throwable $exception) { return $this->failure($exception, 'Nao foi possivel renomear a visualizacao.'); }
    }

    public function delete(int $id): ResponseInterface
    {
        try { return $this->success($this->service->delete($id, $this->actorId())); }
        catch (Throwable $exception) { return $this->failure($exception, 'Nao foi possivel excluir a visualizacao.'); }
    }

    private function failure(Throwable $exception, string $fallback): ResponseInterface
    {
        if ($exception instanceof InvalidArgumentException) return $this->error($exception->getMessage(), 422);
        if ($exception instanceof RuntimeException && (int) $exception->getCode() === 404) return $this->error($exception->getMessage(), 404);
        log_message('error', 'Chatwoot_plugin saved view request failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        return $this->error($fallback, 500);
    }
}
