<?php
/**
 * Shared LLM client — usable from any API file or CLI script.
 *
 * api/ai.php dispatches routes at include time, so other modules must not
 * require it. This file holds the provider-agnostic (OpenAI-compatible)
 * config loading + HTTP call so ai.php, reports.php, mail.php and the CLI
 * worker all share one implementation.
 */

require_once __DIR__ . '/../config.php';

/** Load per-user AI config (lazily inserting defaults). */
function ai_load_config(PDO $db, int $uid): array {
    $stmt = $db->prepare('SELECT * FROM ai_config WHERE user_id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) {
        $db->prepare('INSERT INTO ai_config (user_id, provider, endpoint, api_key, model) VALUES (?, ?, ?, ?, ?)')
           ->execute([$uid, AI_DEFAULT_PROVIDER, AI_DEFAULT_ENDPOINT, AI_DEFAULT_API_KEY, AI_DEFAULT_MODEL]);
        return [
            'user_id'  => $uid,
            'provider' => AI_DEFAULT_PROVIDER,
            'endpoint' => AI_DEFAULT_ENDPOINT,
            'api_key'  => AI_DEFAULT_API_KEY,
            'model'    => AI_DEFAULT_MODEL,
        ];
    }
    return $row;
}

/** Heuristic: is the AI usable? Local Ollama needs no key; cloud providers do. */
function ai_is_configured(array $config): bool {
    $provider = strtolower((string)($config['provider'] ?? ''));
    if ($provider === 'ollama') return !empty($config['endpoint']) && !empty($config['model']);
    return !empty($config['api_key']) && !empty($config['endpoint']) && !empty($config['model']);
}

/**
 * Call an OpenAI-compatible /chat/completions endpoint (non-streaming).
 * Returns a normalized assistant message:
 *   role, content, reasoning_content?, tool_calls?, _usage, _model, _finish_reason
 */
function ai_call_llm(array $config, array $messages, int $timeout = 60, array $tools = []): array {
    $url = rtrim((string)$config['endpoint'], '/') . '/chat/completions';

    $body = [
        'model'       => $config['model'],
        'messages'    => $messages,
        'max_tokens'  => AI_MAX_TOKENS,
        'temperature' => AI_TEMPERATURE,
        'stream'      => false,
    ];
    if (!empty($tools)) $body['tools'] = $tools;

    $headers = ['Content-Type: application/json'];
    if (!empty($config['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeout)),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception('LLM connection failed: ' . $error);
    if ($httpCode !== 200) {
        $b = json_decode((string)$response, true);
        $msg = $b['error']['message'] ?? "HTTP $httpCode";
        throw new Exception("LLM error: $msg");
    }

    $data = json_decode((string)$response, true);
    $choice = $data['choices'][0]['message'] ?? [];
    $msg = [
        'role'           => 'assistant',
        'content'        => $choice['content'] ?? '',
        '_usage'         => $data['usage'] ?? [],
        '_model'         => $data['model'] ?? '',
        '_finish_reason' => $data['choices'][0]['finish_reason'] ?? '',
    ];
    // DeepSeek thinking mode: reasoning_content must be echoed back on later turns.
    if (!empty($choice['reasoning_content'])) {
        $msg['reasoning_content'] = $choice['reasoning_content'];
    }
    if (!empty($choice['tool_calls'])) {
        $msg['tool_calls'] = $choice['tool_calls'];
    }
    return $msg;
}

/** Convenience: plain text completion (no tools); throws on empty content. */
function ai_complete_text(array $config, array $messages, int $timeout = 90): string {
    $msg = ai_call_llm($config, $messages, $timeout);
    $content = trim((string)($msg['content'] ?? ''));
    if ($content === '') throw new Exception('AI 返回空内容');
    return $content;
}

/** Extract the first JSON object from a model reply (tolerates ```json fences and prose). */
function ai_extract_json(string $text): ?array {
    $text = trim($text);
    if ($text === '') return null;
    // Strip code fences
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $direct = json_decode($text, true);
    if (is_array($direct)) return $direct;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $slice = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : null;
}

/** Truncate text for prompt budgets (multibyte-safe). */
function ai_truncate(?string $text, int $maxChars): string {
    $text = trim((string)$text);
    if (mb_strlen($text, 'UTF-8') <= $maxChars) return $text;
    return mb_substr($text, 0, $maxChars, 'UTF-8') . "\n...[context truncated]";
}
