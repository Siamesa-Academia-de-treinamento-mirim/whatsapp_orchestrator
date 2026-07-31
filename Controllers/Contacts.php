<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use Chatwoot_plugin\Services\Contact_service;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Contacts extends Api_controller
{
    private Contact_service $contacts;

    public function __construct()
    {
        parent::__construct();
        $this->contacts = new Contact_service();
    }

    public function index(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try {
            $filters = [
                'q' => mb_substr(trim((string) $this->request->getGet('q')), 0, 191),
                'instance_id' => max(0, (int) $this->request->getGet('instance_id')) ?: null,
                'tag' => mb_substr(trim((string) $this->request->getGet('tag')), 0, 100),
                'sort' => (string) $this->request->getGet('sort'),
            ];
            $status = strtolower(trim((string) $this->request->getGet('status')));
            if ($status === 'opt_out') {
                $filters['opt_out'] = true;
            } elseif ($status === 'active') {
                $filters['opt_out'] = false;
            } elseif ($status === 'identified') {
                $filters['identified'] = true;
            } elseif ($status === 'unidentified') {
                $filters['identified'] = false;
            }
            $result = $this->contacts->list($filters, max(1, (int) $this->request->getGet('page')), min(200, max(1, (int) ($this->request->getGet('limit') ?: 30))));
            return $this->success($result['data'], ['meta' => $result['meta']]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel carregar os contatos.');
        }
    }

    public function show(int $id): ResponseInterface
    {
        $this->requireManageContactsPermission();
        $contact = $this->contacts->get($id);
        return $contact ? $this->success($contact) : $this->error('Contato nao encontrado.', 404);
    }

    public function create(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try {
            return $this->success($this->contacts->save($this->input(), $this->actorId()), [], 201);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel criar o contato.', 422);
        }
    }

    public function update(int $id): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try {
            return $this->success($this->contacts->save($this->input(), $this->actorId(), $id));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel atualizar o contato.', 422);
        }
    }

    public function delete(int $id): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try {
            $this->contacts->delete($id, $this->actorId());
            return $this->success(['id' => $id, 'deleted' => true]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel remover o contato.');
        }
    }

    public function opt_out(int $id): ResponseInterface
    {
        $this->requireManageContactsPermission();
        try {
            $value = filter_var($this->input()['opt_out'] ?? true, FILTER_VALIDATE_BOOLEAN);
            return $this->success($this->contacts->set_opt_out($id, $value, $this->actorId()));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel atualizar o opt-out.');
        }
    }

    public function bulk_tags(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        $input = $this->input();
        try {
            $count = $this->contacts->bulk_tags(is_array($input['ids'] ?? null) ? $input['ids'] : [], is_array($input['tags'] ?? null) ? $input['tags'] : [], $this->actorId());
            return $this->success(['updated' => $count]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel aplicar as tags.', 422);
        }
    }

    public function import(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->error('Envie um arquivo CSV valido no campo file.', 422);
        }
        try {
            $dryRun = filter_var($this->request->getPost('dry_run') ?? false, FILTER_VALIDATE_BOOLEAN);
            return $this->success($this->contacts->import_csv($file->getTempName(), $this->actorId(), $dryRun));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Nao foi possivel importar o CSV.', 422);
        }
    }

    public function export(): ResponseInterface
    {
        $this->requireManageContactsPermission();
        $rows = $this->contacts->export_rows([
            'q' => mb_substr(trim((string) $this->request->getGet('q')), 0, 191),
            'instance_id' => max(0, (int) $this->request->getGet('instance_id')) ?: null,
            'tag' => mb_substr(trim((string) $this->request->getGet('tag')), 0, 100),
            'ids' => preg_split('/,/', (string) $this->request->getGet('ids'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ]);
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['name', 'phone', 'email', 'company', 'city', 'tags', 'opt_out', 'last_activity_at']);
        foreach ($rows as $row) {
            $safe = static fn ($value): string => preg_match('/^[=+\-@]/', (string) $value) ? "'" . (string) $value : (string) $value;
            fputcsv($handle, [$safe($row['name']), $safe($row['phone']), $safe($row['email']), $safe($row['company']), $safe($row['city']), $safe(implode('|', $row['tags'])), $row['opt_out'] ? '1' : '0', $safe($row['last_activity_at'] ?? '')]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8')->setHeader('Content-Disposition', 'attachment; filename="impulso-contatos-' . gmdate('Ymd-His') . '.csv"')->setBody("\xEF\xBB\xBF" . $csv);
    }

    private function failure(Throwable $exception, string $fallback, int $default = 500): ResponseInterface
    {
        $code = (int) $exception->getCode();
        $status = in_array($code, [404, 409, 422], true) ? $code : $default;
        log_message('error', 'Chatwoot_plugin contacts API failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        return $this->error($status === 422 || $status === 404 ? $exception->getMessage() : $fallback, $status);
    }
}
