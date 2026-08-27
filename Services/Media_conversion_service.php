<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use RuntimeException;

require_once __DIR__ . '/Media_engine_exception.php';

/** Converts browser recordings into an actually encoded OGG/Opus file. */
final class Media_conversion_service
{
    /** @var callable|null */
    private $runner;

    public function __construct(?callable $runner = null, private ?string $binary = null, private ?string $probeBinary = null)
    {
        $this->runner = $runner;
        $this->binary = $binary !== null && trim($binary) !== '' ? trim($binary) : null;
        $this->probeBinary = $probeBinary !== null && trim($probeBinary) !== '' ? trim($probeBinary) : null;
    }

    /** @param array<string,mixed> $policy */
    public function assertProviderVideoCompatible(string $inputPath, array $policy): void
    {
        if (empty($policy['requires_video_codec_validation'])) return;
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new Media_engine_exception('MEDIA_INPUT_UNAVAILABLE', 'Video is not available for validation.');
        }
        $videoCodec = strtolower($this->probeCodec($inputPath, 'v:0'));
        $allowedVideo = array_map('strtolower', array_map('strval', (array) ($policy['video_codecs'] ?? [])));
        if ($videoCodec === '' || !in_array($videoCodec, $allowedVideo, true)) {
            throw new Media_engine_exception('MEDIA_VIDEO_CODEC_INCOMPATIBLE', 'The video codec is not accepted by the provider.');
        }
        $audioCodec = strtolower($this->probeCodec($inputPath, 'a:0'));
        if ($audioCodec === '') return;
        $allowedAudio = array_map('strtolower', array_map('strval', (array) ($policy['video_audio_codecs'] ?? [])));
        if (!in_array($audioCodec, $allowedAudio, true)) {
            throw new Media_engine_exception('MEDIA_VIDEO_AUDIO_CODEC_INCOMPATIBLE', 'The video audio codec is not accepted by the provider.');
        }
    }

    /** @return array{path:string,mime_type:string,extension:string,cleanup_path:string} */
    public function toVoiceCompatible(string $inputPath): array
    {
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new Media_engine_exception('MEDIA_INPUT_UNAVAILABLE', 'The audio recording is not available for conversion.');
        }
        $outputPath = tempnam(sys_get_temp_dir(), 'rise-opus-');
        if ($outputPath === false) {
            throw new Media_engine_exception('MEDIA_TEMPORARY_FILE_FAILED', 'Could not prepare a temporary audio file.');
        }
        @unlink($outputPath);
        $outputPath .= '.ogg';
        try {
            if ($this->runner !== null) {
                $result = call_user_func($this->runner, $inputPath, $outputPath);
                if ($result === false || !is_file($outputPath)) {
                    throw new Media_engine_exception('MEDIA_CONVERSION_FAILED', 'Audio conversion did not produce a file.');
                }
            } else {
                $this->runFfmpeg($inputPath, $outputPath);
            }
            $mime = Media_policy_service::detectMime($outputPath);
            if ($mime !== 'audio/ogg' || !Media_policy_service::isOggOpus($outputPath) || !Media_policy_service::isMonoOggOpus($outputPath)) {
                throw new Media_engine_exception('MEDIA_CONVERSION_OUTPUT_INVALID', 'Conversion did not produce valid mono OGG/Opus audio.');
            }
            return ['path' => $outputPath, 'mime_type' => 'audio/ogg; codecs=opus', 'extension' => 'ogg', 'cleanup_path' => $outputPath];
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            if ($exception instanceof RuntimeException) throw $exception;
            throw new Media_engine_exception('MEDIA_CONVERSION_FAILED', 'Audio conversion failed.', 422, $exception);
        }
    }

    public function isAvailable(): bool { return $this->binaryAvailable($this->binary ?: 'ffmpeg'); }
    public function isProbeAvailable(): bool { return $this->binaryAvailable($this->probeBinary ?: 'ffprobe'); }

    /** @return array{ffmpeg_available:bool,ffprobe_available:bool} */
    public function diagnostics(): array
    {
        return ['ffmpeg_available' => $this->isAvailable(), 'ffprobe_available' => $this->isProbeAvailable()];
    }

    private function runFfmpeg(string $inputPath, string $outputPath): void
    {
        if (!$this->isAvailable()) {
            throw new Media_engine_exception('MEDIA_FFMPEG_MISSING', 'Audio conversion is unavailable: configure FFmpeg.');
        }
        $binary = $this->binary ?: 'ffmpeg';
        $command = implode(' ', [escapeshellarg($binary), '-y -v error', '-i', escapeshellarg($inputPath), '-vn -ac 1 -c:a libopus -b:a 64k -f ogg', escapeshellarg($outputPath)]);
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new Media_engine_exception('MEDIA_CONVERSION_FAILED', 'Could not start audio conversion.');
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_file($outputPath) || filesize($outputPath) < 1) {
            throw new Media_engine_exception('MEDIA_CONVERSION_FAILED', 'FFmpeg rejected the audio recording: ' . mb_substr(trim((string) $error), 0, 300));
        }
    }

    private function probeCodec(string $inputPath, string $selector): string
    {
        if (!$this->isProbeAvailable()) throw new Media_engine_exception('MEDIA_FFPROBE_MISSING', 'Codec validation is unavailable: configure FFprobe.');
        $binary = $this->probeBinary ?: 'ffprobe';
        $command = implode(' ', [escapeshellarg($binary), '-v error -select_streams', escapeshellarg($selector), '-show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1', escapeshellarg($inputPath)]);
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new Media_engine_exception('MEDIA_CODEC_VALIDATION_FAILED', 'Codec validation could not start.');
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) throw new Media_engine_exception('MEDIA_CODEC_VALIDATION_FAILED', 'Codec validation failed for the video.');
        return trim(strtok($output, "\r\n") ?: '');
    }

    private function binaryAvailable(string $binary): bool
    {
        $process = @proc_open(escapeshellarg($binary) . ' -version', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return false;
        foreach ($pipes as $pipe) fclose($pipe);
        return proc_close($process) === 0;
    }
}
