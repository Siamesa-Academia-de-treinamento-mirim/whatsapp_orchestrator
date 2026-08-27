<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;

/**
 * Provider-neutral media validation. The provider capability document is the
 * policy source; this class deliberately does not inspect browser MIME hints.
 */
final class Media_policy_service
{
    /** @var array<string,array{kind:string,extension:string}> */
    private const MIME_TYPES = [
        'image/jpeg' => ['kind' => 'image', 'extension' => 'jpg'],
        'image/png' => ['kind' => 'image', 'extension' => 'png'],
        'image/gif' => ['kind' => 'image', 'extension' => 'gif'],
        'image/webp' => ['kind' => 'image', 'extension' => 'webp'],
        'audio/ogg' => ['kind' => 'audio', 'extension' => 'ogg'],
        'audio/mpeg' => ['kind' => 'audio', 'extension' => 'mp3'],
        'audio/mp3' => ['kind' => 'audio', 'extension' => 'mp3'],
        'audio/mp4' => ['kind' => 'audio', 'extension' => 'm4a'],
        'audio/x-m4a' => ['kind' => 'audio', 'extension' => 'm4a'],
        'audio/aac' => ['kind' => 'audio', 'extension' => 'aac'],
        'audio/amr' => ['kind' => 'audio', 'extension' => 'amr'],
        'audio/wav' => ['kind' => 'audio', 'extension' => 'wav'],
        'audio/x-wav' => ['kind' => 'audio', 'extension' => 'wav'],
        'audio/webm' => ['kind' => 'audio', 'extension' => 'webm'],
        'video/webm' => ['kind' => 'video', 'extension' => 'webm'],
        'video/mp4' => ['kind' => 'video', 'extension' => 'mp4'],
        'video/3gpp' => ['kind' => 'video', 'extension' => '3gp'],
        'application/pdf' => ['kind' => 'document', 'extension' => 'pdf'],
        'text/plain' => ['kind' => 'document', 'extension' => 'txt'],
        'application/msword' => ['kind' => 'document', 'extension' => 'doc'],
        'application/vnd.ms-excel' => ['kind' => 'document', 'extension' => 'xls'],
        'application/vnd.ms-powerpoint' => ['kind' => 'document', 'extension' => 'ppt'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['kind' => 'document', 'extension' => 'docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['kind' => 'document', 'extension' => 'xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['kind' => 'document', 'extension' => 'pptx'],
    ];

    /** @return array<string,array{kind:string,extension:string}> */
    public static function mimeTypes(): array
    {
        return self::MIME_TYPES;
    }

    /**
     * @return array<string,mixed>
     */
    public function validateUploadedFile(
        UploadedFile $file,
        array $capabilities,
        string $caption = '',
        ?string $requestedKind = null,
        bool $voiceNote = false,
        bool $recording = false
    ): array {
        if (!$file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Arquivo de anexo invalido.', 422);
        }

        return $this->validatePath(
            $file->getTempName(),
            $file->getClientName(),
            $capabilities,
            $caption,
            $requestedKind,
            $voiceNote,
            $recording,
            true
        );
    }

    /**
     * Validate a converted/intermediate file. `sourceMime` is kept only for
     * diagnostics; the resulting MIME is detected again from the bytes.
     *
     * @return array<string,mixed>
     */
    public function validatePath(
        string $path,
        string $originalName,
        array $capabilities,
        string $caption = '',
        ?string $requestedKind = null,
        bool $voiceNote = false,
        bool $recording = false,
        bool $allowRecordingInput = true
    ): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Conteudo de midia indisponivel.', 422);
        }

        $size = (int) filesize($path);
        if ($size < 1) {
            throw new InvalidArgumentException('O arquivo de midia esta vazio.', 422);
        }

        $mime = self::detectMime($path);
        $kind = self::kindForMime($mime);
        $requestedKind = self::normalizeKind($requestedKind);
        $recordingInput = false;

        if ($requestedKind !== null && $kind !== $requestedKind) {
            // MediaRecorder implementations sometimes report audio-only WebM
            // as video/webm. A recording declaration is the only case in which
            // this container is accepted as audio input.
            if (!($recording && $requestedKind === 'audio' && in_array($mime, ['audio/webm', 'video/webm'], true))) {
                throw new InvalidArgumentException('O tipo de midia informado nao corresponde ao conteudo real.', 422);
            }
            $kind = 'audio';
        }

        if ($kind === null) {
            throw new InvalidArgumentException('Tipo de midia nao reconhecido pelo servidor.', 422);
        }

        $policy = $this->policyForKind($capabilities, $kind);
        if (empty($policy['enabled']) || empty($capabilities['actions']['send_media'])) {
            throw new InvalidArgumentException('O provedor nao permite o envio deste tipo de midia.', 422);
        }

        $accepted = array_map('strtolower', array_map('strval', (array) ($policy['accepted_mime_types'] ?? [])));
        $recordingInputs = array_map('strtolower', array_map('strval', (array) ($policy['recording_input_mime_types'] ?? [])));
        $mimeLower = strtolower($mime);
        $isAcceptedOutput = in_array($mimeLower, $accepted, true);
        $isAcceptedRecordingInput = $recording && $allowRecordingInput && in_array($mimeLower, $recordingInputs, true);
        if (!$isAcceptedOutput && !$isAcceptedRecordingInput) {
            throw new InvalidArgumentException('MIME real nao permitido pelo provedor: ' . ($mime ?: 'desconhecido') . '.', 422);
        }
        $recordingInput = $isAcceptedRecordingInput && !$isAcceptedOutput;

        $maxBytes = (int) ($policy['max_bytes'] ?? 0);
        if ($maxBytes < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException('O arquivo excede o limite de ' . self::formatBytes($maxBytes) . ' para ' . $kind . '.', 422);
        }

        $caption = trim($caption);
        $captionMaxChars = (int) ($policy['caption_max_chars'] ?? 0);
        if ($caption !== '' && (empty($policy['caption']) || $captionMaxChars < 1)) {
            throw new InvalidArgumentException('Este tipo de midia nao aceita legenda neste provedor.', 422);
        }
        if ($caption !== '' && mb_strlen($caption) > $captionMaxChars) {
            throw new InvalidArgumentException('A legenda excede o limite de ' . $captionMaxChars . ' caracteres.', 422);
        }

        if ($voiceNote && ($kind !== 'audio' || empty($policy['voice_note']))) {
            throw new InvalidArgumentException('Notas de voz nao sao suportadas para este tipo de midia/provedor.', 422);
        }

        $isOggOpus = $mimeLower === 'audio/ogg' && self::isOggOpus($path);
        if ($kind === 'audio' && $mimeLower === 'audio/ogg' && !empty($policy['requires_opus_codec']) && !$isOggOpus) {
            throw new InvalidArgumentException('Este provedor aceita OGG somente com codec Opus; o arquivo foi rejeitado.', 422);
        }
        if ($voiceNote && $isOggOpus && !empty($policy['voice_note_requires_mono']) && !self::isMonoOggOpus($path)) {
            throw new InvalidArgumentException('A nota de voz precisa ser OGG/Opus mono.', 422);
        }

        $extension = self::MIME_TYPES[$mimeLower]['extension'] ?? 'bin';
        $needsConversion = $recordingInput
            || ($voiceNote && !($isOggOpus && self::isMonoOggOpus($path)))
            || ($kind === 'audio' && $mimeLower === 'audio/ogg' && !empty($policy['requires_opus_codec']) && !$isOggOpus);
        if ($voiceNote && empty($policy['voice_note'])) {
            $needsConversion = false;
        }
        if ($needsConversion && empty($policy['requires_conversion']) && empty($policy['requires_recording_conversion'])) {
            throw new InvalidArgumentException('O provedor nao possui conversao configurada para esta midia.', 422);
        }

        $targetMime = trim((string) ($policy['recording_target'] ?? ''));
        $filename = self::safeName($originalName, $extension);
        $outputMime = $mimeLower;
        if ($mimeLower === 'audio/ogg' && $isOggOpus && !empty($policy['requires_opus_codec'])) {
            $outputMime = 'audio/ogg; codecs=opus';
        }

        return [
            'kind' => $kind,
            'mime_type' => $outputMime,
            'detected_mime_type' => $mimeLower,
            'size' => $size,
            'extension' => $extension,
            'filename' => $filename,
            'caption' => $caption,
            'voice_note' => $voiceNote,
            'recording' => $recording,
            'recording_input' => $recordingInput,
            'needs_conversion' => $needsConversion,
            'target_mime' => $targetMime !== '' ? $targetMime : $mimeLower,
            'policy' => $policy,
        ];
    }

    public static function detectMime(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return strtolower(trim(is_string($mime) ? $mime : ''));
    }

    public static function kindForMime(string $mime): ?string
    {
        return self::MIME_TYPES[strtolower(trim($mime))]['kind'] ?? null;
    }

    /** @return array<string,mixed> */
    public function policyForKind(array $capabilities, string $kind): array
    {
        $policy = $capabilities['media'][$kind] ?? null;
        if (!is_array($policy)) {
            throw new InvalidArgumentException('Tipo de midia nao previsto nas capabilities do provedor.', 422);
        }

        return $policy;
    }

    /**
     * Defensive validation for provider adapters. The Media Engine performs
     * byte inspection first; adapters still reject malformed direct calls.
     *
     * @return array<string,mixed>
     */
    public function validatePayload(array $media, array $capabilities): array
    {
        $kind = strtolower(trim((string) ($media['type'] ?? '')));
        $policy = $this->policyForKind($capabilities, $kind);
        $mime = strtolower(trim((string) ($media['mime_type'] ?? $media['mimeType'] ?? '')));
        $baseMime = preg_replace('/\s*;.*$/', '', $mime) ?: $mime;
        $accepted = array_map(static fn ($value): string => strtolower((string) preg_replace('/\s*;.*$/', '', (string) $value)), (array) ($policy['accepted_mime_types'] ?? []));
        if (empty($policy['enabled']) || !in_array($baseMime, $accepted, true)) {
            throw new InvalidArgumentException('MIME da midia nao permitido pelo contrato do provedor.', 422);
        }
        $size = (int) ($media['file_size'] ?? 0);
        if ($size < 1 || $size > (int) ($policy['max_bytes'] ?? 0)) {
            throw new InvalidArgumentException('Tamanho da midia fora do limite do provedor.', 422);
        }
        $caption = trim((string) ($media['caption'] ?? ''));
        if ($caption !== '' && (empty($policy['caption']) || mb_strlen($caption) > (int) ($policy['caption_max_chars'] ?? 0))) {
            throw new InvalidArgumentException('Legenda nao permitida ou acima do limite do provedor.', 422);
        }
        if (!empty($media['voice_note']) && ($kind !== 'audio' || empty($policy['voice_note']))) {
            throw new InvalidArgumentException('Nota de voz nao suportada pelo provedor.', 422);
        }
        if (!empty($policy['requires_https_link']) && !str_starts_with(strtolower(trim((string) ($media['link'] ?? ''))), 'https://')) {
            throw new InvalidArgumentException('O provedor exige link HTTPS para a midia.', 422);
        }
        if (!empty($media['data'])) {
            $decoded = base64_decode((string) $media['data'], true);
            if ($decoded === false || $decoded === '') {
                throw new InvalidArgumentException('Payload base64 de midia invalido.', 422);
            }
            $detected = self::detectMimeBuffer($decoded);
            if ($detected !== '' && self::kindForMime($detected) !== $kind) {
                throw new InvalidArgumentException('O MIME real do payload nao corresponde ao tipo informado.', 422);
            }
        }

        return $media;
    }

    public static function isOggOpus(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $bytes = (string) fread($handle, 262144);
        fclose($handle);

        return str_contains($bytes, 'OpusHead');
    }

    public static function isMonoOggOpus(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $bytes = (string) fread($handle, 262144);
        fclose($handle);
        $offset = strpos($bytes, 'OpusHead');

        return $offset !== false && isset($bytes[$offset + 9]) && ord($bytes[$offset + 9]) === 1;
    }

    private static function detectMimeBuffer(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rise-mime-');
        if ($path === false) {
            return '';
        }
        try {
            file_put_contents($path, $bytes, LOCK_EX);
            return self::detectMime($path);
        } finally {
            @unlink($path);
        }
    }

    private static function normalizeKind(?string $kind): ?string
    {
        $kind = strtolower(trim((string) $kind));
        if ($kind === '' || $kind === 'voice') {
            return $kind === 'voice' ? 'audio' : null;
        }

        return in_array($kind, ['image', 'audio', 'video', 'document', 'sticker'], true) ? $kind : $kind;
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / 1024 / 1024, 2, '.', ''), '0'), '.') . ' MB'
            : rtrim(rtrim(number_format($bytes / 1024, 2, '.', ''), '0'), '.') . ' KB';
    }

    private static function safeName(string $name, string $extension): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo.' . $extension;
        $name = mb_substr($name, 0, 180);
        $currentExtension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($currentExtension === '' || !in_array($currentExtension, [$extension, 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ogg', 'mp3', 'm4a', 'aac', 'amr', 'wav', 'mp4', '3gp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
            $name .= '.' . $extension;
        }

        return $name;
    }
}
