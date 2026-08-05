<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use InvalidArgumentException;

/** Strict schema for a deterministic bot. No model, script, regex or arbitrary code is accepted. */
class Bot_flow_validator
{
    private const MATCH_TYPES = ['exact', 'contains', 'starts_with', 'any_word'];

    /** @return array<string,mixed> */
    public function validate($definition): array
    {
        if (is_string($definition)) $definition = json_decode($definition, true);
        if (!is_array($definition)) throw new InvalidArgumentException('Definicao do bot deve ser um JSON valido.');

        $start = trim((string) ($definition['start'] ?? ''));
        $nodes = $definition['nodes'] ?? null;
        if (!$this->validKey($start) || !is_array($nodes) || $nodes === [] || count($nodes) > 100) {
            throw new InvalidArgumentException('O fluxo precisa de no inicial e entre 1 e 100 nos.');
        }
        if (!array_key_exists($start, $nodes)) throw new InvalidArgumentException('O no inicial nao existe.');

        $cleanNodes = [];
        foreach ($nodes as $key => $node) {
            $key = trim((string) $key);
            if (!$this->validKey($key) || !is_array($node)) throw new InvalidArgumentException('Chave de no invalida.');
            $message = trim((string) ($node['message'] ?? ''));
            if ($message === '' || mb_strlen($message) > 4096) {
                throw new InvalidArgumentException("O no {$key} precisa de uma mensagem de ate 4096 caracteres.");
            }
            $transitions = $node['transitions'] ?? [];
            if (!is_array($transitions) || count($transitions) > 30) throw new InvalidArgumentException("Transicoes invalidas no no {$key}.");

            $cleanTransitions = [];
            $seenIds = [];
            $seenValues = [];
            foreach ($transitions as $index => $transition) {
                if (!is_array($transition)) throw new InvalidArgumentException("Transicao invalida no no {$key}.");
                $id = trim((string) ($transition['id'] ?? 't' . ($index + 1)));
                $target = trim((string) ($transition['target'] ?? ''));
                $match = is_array($transition['match'] ?? null) ? $transition['match'] : [];
                $type = strtolower(trim((string) ($match['type'] ?? '')));
                $values = $match['values'] ?? [];
                if (!$this->validKey($id) || !in_array($type, self::MATCH_TYPES, true) || !is_array($values) || $values === [] || count($values) > 20) {
                    throw new InvalidArgumentException("Regra de transicao invalida no no {$key}.");
                }
                if (isset($seenIds[$id])) throw new InvalidArgumentException("ID de transicao duplicado no no {$key}: {$id}.");
                $seenIds[$id] = true;
                if ($target !== '__handoff__' && !$this->validKey($target)) throw new InvalidArgumentException("Destino invalido no no {$key}.");

                $cleanValues = [];
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '' || mb_strlen($value) > 191) throw new InvalidArgumentException("Valor de correspondencia invalido no no {$key}.");
                    $normalized = $this->normalize($value);
                    if ($normalized === '') throw new InvalidArgumentException("Valor de correspondencia invalido no no {$key}.");
                    if ($type === 'any_word' && str_contains($normalized, ' ')) {
                        throw new InvalidArgumentException("A regra any_word aceita uma palavra por valor no no {$key}.");
                    }
                    // A same term with two match modes is ambiguous for inputs equal to
                    // that term. Deterministic flows reject it instead of relying on order.
                    if (isset($seenValues[$normalized])) {
                        throw new InvalidArgumentException("Regra ambigua no no {$key}: {$value} tambem aparece em {$seenValues[$normalized]}.");
                    }
                    $seenValues[$normalized] = $id;
                    $cleanValues[] = $value;
                }
                $cleanTransitions[] = ['id' => $id, 'target' => $target, 'match' => ['type' => $type, 'values' => $cleanValues]];
            }

            $fallbackTarget = trim((string) ($node['fallback_target'] ?? ''));
            if ($fallbackTarget !== '' && $fallbackTarget !== '__handoff__' && !$this->validKey($fallbackTarget)) {
                throw new InvalidArgumentException("Destino de fallback invalido no no {$key}.");
            }
            $cleanNodes[$key] = [
                'message' => $message,
                'transitions' => $cleanTransitions,
                'terminal' => !empty($node['terminal']),
                'handoff' => !empty($node['handoff']),
                'fallback_target' => $fallbackTarget !== '' ? $fallbackTarget : null,
            ];
        }

        foreach ($cleanNodes as $key => $node) {
            foreach ($node['transitions'] as $transition) {
                if ($transition['target'] !== '__handoff__' && !isset($cleanNodes[$transition['target']])) {
                    throw new InvalidArgumentException("O no {$key} aponta para um destino inexistente.");
                }
            }
            $fallbackTarget = $node['fallback_target'];
            if ($fallbackTarget && $fallbackTarget !== '__handoff__' && !isset($cleanNodes[$fallbackTarget])) {
                throw new InvalidArgumentException("O fallback do no {$key} aponta para um destino inexistente.");
            }
        }

        return ['start' => $start, 'nodes' => $cleanNodes];
    }

    /** @return array<string,mixed> */
    public function validateTrigger(string $type, $config): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['first_message', 'keyword', 'always'], true)) throw new InvalidArgumentException('Gatilho do bot invalido.');
        if (is_string($config)) $config = json_decode($config, true);
        $config = is_array($config) ? $config : [];
        if ($type === 'keyword') {
            $values = $config['values'] ?? [];
            if (!is_array($values) || $values === [] || count($values) > 50) throw new InvalidArgumentException('O gatilho por palavra precisa de termos definidos.');
            $normalized = [];
            foreach ($values as $value) {
                $value = trim((string) $value);
                $key = $this->normalize($value);
                if ($key !== '') $normalized[$key] = $value;
            }
            $config = ['values' => array_values($normalized)];
            if ($config['values'] === []) throw new InvalidArgumentException('O gatilho por palavra precisa de termos validos.');
        }
        return $config;
    }

    /** @return array<string,mixed> */
    public function validateBusinessHours($config): array
    {
        if ($config === null || $config === '' || $config === []) return [];
        if (is_string($config)) $config = json_decode($config, true);
        if (!is_array($config)) throw new InvalidArgumentException('Horario de atendimento invalido.');
        $timezone = trim((string) ($config['timezone'] ?? 'America/Sao_Paulo'));
        try { new \DateTimeZone($timezone); } catch (\Throwable $e) { throw new InvalidArgumentException('Fuso horario invalido.'); }
        $weekdays = is_array($config['weekdays'] ?? null) ? $config['weekdays'] : [];
        $clean = [];
        foreach ($weekdays as $day => $ranges) {
            $day = strtolower(trim((string) $day));
            if (!in_array($day, ['mon','tue','wed','thu','fri','sat','sun'], true) || !is_array($ranges)) continue;
            $clean[$day] = [];
            foreach ($ranges as $range) {
                if (!is_array($range) || count($range) !== 2) throw new InvalidArgumentException('Faixa de horario invalida.');
                [$from, $to] = array_values($range);
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $from) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $to) || $from >= $to) {
                    throw new InvalidArgumentException('Faixa de horario invalida.');
                }
                $clean[$day][] = [(string) $from, (string) $to];
            }
        }
        return [
            'timezone' => $timezone,
            'weekdays' => $clean,
            'outside_message' => mb_substr(trim((string) ($config['outside_message'] ?? '')), 0, 4096),
            'handoff_outside' => !array_key_exists('handoff_outside', $config) || !empty($config['handoff_outside']),
        ];
    }

    /** @param array<int,array<string,mixed>> $transitions
     *  @return array<string,mixed>|null
     */
    public function matchTransition(array $transitions, string $input): ?array
    {
        $normalized = $this->normalize($input);
        if ($normalized === '') return null;
        $words = array_flip(preg_split('/\s+/u', $normalized) ?: []);
        foreach ($transitions as $transition) {
            $match = is_array($transition['match'] ?? null) ? $transition['match'] : [];
            $type = (string) ($match['type'] ?? '');
            foreach ((array) ($match['values'] ?? []) as $value) {
                $needle = $this->normalize((string) $value);
                if ($needle === '') continue;
                $matched = match ($type) {
                    'exact' => hash_equals($needle, $normalized),
                    'contains' => str_contains($normalized, $needle),
                    'starts_with' => str_starts_with($normalized, $needle),
                    'any_word' => isset($words[$needle]),
                    default => false,
                };
                if ($matched) return $transition;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function simulate($definition, array $inputs): array
    {
        $flow = $this->validate($definition);
        $current = (string) $flow['start'];
        $fallbacks = 0;
        $transcript = [['direction' => 'outgoing', 'node' => $current, 'text' => (string) $flow['nodes'][$current]['message']]];
        foreach (array_slice($inputs, 0, 50) as $rawInput) {
            $input = trim((string) $rawInput);
            $transcript[] = ['direction' => 'incoming', 'text' => $input];
            $node = $flow['nodes'][$current];
            $transition = $this->matchTransition($node['transitions'], $input);
            if (!$transition) {
                $fallbacks++;
                $target = (string) ($node['fallback_target'] ?? '');
                if ($target === '__handoff__') {
                    $transcript[] = ['direction' => 'system', 'node' => $current, 'result' => 'handoff'];
                    return ['valid' => true, 'current_node' => $current, 'result' => 'handoff', 'fallbacks' => $fallbacks, 'transcript' => $transcript];
                }
                if ($target !== '' && isset($flow['nodes'][$target])) {
                    $current = $target;
                    $transcript[] = ['direction' => 'outgoing', 'node' => $current, 'result' => 'fallback_transition', 'text' => (string) $flow['nodes'][$current]['message']];
                } else {
                    $transcript[] = ['direction' => 'system', 'node' => $current, 'result' => 'fallback'];
                }
                continue;
            }
            if ($transition['target'] === '__handoff__') {
                $transcript[] = ['direction' => 'system', 'node' => $current, 'transition' => $transition['id'], 'result' => 'handoff'];
                return ['valid' => true, 'current_node' => $current, 'result' => 'handoff', 'fallbacks' => $fallbacks, 'transcript' => $transcript];
            }
            $current = (string) $transition['target'];
            $node = $flow['nodes'][$current];
            $transcript[] = ['direction' => 'outgoing', 'node' => $current, 'transition' => $transition['id'], 'text' => (string) $node['message']];
            if (!empty($node['handoff'])) return ['valid' => true, 'current_node' => $current, 'result' => 'handoff', 'fallbacks' => $fallbacks, 'transcript' => $transcript];
            if (!empty($node['terminal'])) return ['valid' => true, 'current_node' => $current, 'result' => 'completed', 'fallbacks' => $fallbacks, 'transcript' => $transcript];
        }
        return ['valid' => true, 'current_node' => $current, 'result' => 'active', 'fallbacks' => $fallbacks, 'transcript' => $transcript];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (class_exists('Transliterator')) {
            $trans = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($trans) $value = $trans->transliterate($value);
        } else {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii)) $value = $ascii;
        }
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?: '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function validKey(string $value): bool
    {
        return $value !== '' && strlen($value) <= 100 && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
