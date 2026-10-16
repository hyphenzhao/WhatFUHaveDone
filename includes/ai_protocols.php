<?php
/** Wire-format adapters. The rest of WorkLog uses normalized chat messages. */
function ai_provider_presets(): array {
    return [
        'ollama'=>['label'=>'Ollama（本地）','endpoint'=>'http://localhost:11434/v1','model'=>'qwen2.5:7b','hint'=>'使用本机已安装的模型；通常无需密钥。'],
        'deepseek'=>['label'=>'DeepSeek','endpoint'=>'https://api.deepseek.com/v1','model'=>'deepseek-chat','hint'=>'使用现有 DeepSeek API Key 和模型名称。'],
        'openai'=>['label'=>'OpenAI','endpoint'=>'https://api.openai.com/v1','model'=>'','hint'=>'填写账户可用的模型 ID；使用 Chat Completions 接口。'],
        'anthropic'=>['label'=>'Anthropic（Claude）','endpoint'=>'https://api.anthropic.com/v1','model'=>'','hint'=>'填写账户可用的 Claude 模型 ID；使用原生 Messages 接口。'],
        'openai_compatible'=>['label'=>'OpenAI 兼容接口（自定义）','endpoint'=>'','model'=>'','hint'=>'填写服务商提供的基础地址和模型 ID；使用兼容的 Chat Completions 接口。'],
    ];
}

/** Accept a base URL or the complete endpoint, without appending its path twice. */
function ai_endpoint_url(array $config): string {
    $url = rtrim(trim((string)($config['endpoint'] ?? '')), '/');
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('API 地址必须是有效的 HTTP/HTTPS 地址，不包含账号、查询参数或片段');
    }
    $provider = strtolower((string)($config['provider'] ?? ''));
    $suffix = $provider === 'anthropic' ? '/messages' : '/chat/completions';
    if (str_ends_with($url, $suffix)) return $url;
    if (preg_match('~/(?:messages|chat/completions|responses)$~', $url)) {
        throw new InvalidArgumentException('请求地址与所选提供者的接口格式不匹配');
    }
    if (empty($parts['path']) && in_array($provider, ['openai','anthropic'], true)) $url .= '/v1';
    return $url . $suffix;
}

/** Preserve an existing key only for the same provider AND the same destination. */
function ai_config_candidate(array $saved, array $input): array {
    $config = [];
    foreach (['provider','endpoint','model'] as $key) {
        $value = $input[$key] ?? $saved[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('AI 配置字段格式无效');
        $config[$key] = trim($value);
    }
    $config['provider'] = strtolower($config['provider']);
    if (!isset(ai_provider_presets()[$config['provider']])) throw new InvalidArgumentException('AI 提供者无效');
    $destination = ai_endpoint_url($config);
    if ($config['model'] === '' || strlen($config['model']) > 128) throw new InvalidArgumentException('请填写有效的模型 ID（最多 128 字节）');
    if (strlen($config['endpoint']) > 512) throw new InvalidArgumentException('API 地址过长');
    $reuse = false;
    if (($saved['provider'] ?? '') === $config['provider']) {
        try { $reuse = ai_endpoint_url($saved) === $destination; } catch (InvalidArgumentException $e) { /* incomplete old config */ }
    }
    $key = $input['api_key'] ?? '';
    if (!is_string($key) || preg_match('/[\r\n\x00]/', $key)) throw new InvalidArgumentException('API Key 格式无效');
    $key = trim($key);
    $masked = !empty($saved['api_key']) ? '***' . substr($saved['api_key'], -4) : '';
    if ($reuse && ($key === '' || $key === $masked)) $key = (string)($saved['api_key'] ?? '');
    elseif (str_starts_with($key, '***')) throw new InvalidArgumentException('请填写完整的 API Key');
    if (strlen($key) > 512) throw new InvalidArgumentException('API Key 过长');
    if ($config['provider'] !== 'ollama' && $key === '') throw new InvalidArgumentException('请填写此提供者的 API Key；切换提供者或地址后需重新填写');
    $config['api_key'] = $key;
    return $config;
}

function ai_content_text($content): string {
    if (is_string($content)) return $content;
    $text = [];
    foreach (is_array($content) ? $content : [] as $block) {
        if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $text[] = $block['text'];
    }
    return implode("\n", $text);
}

function ai_anthropic_append(array &$messages, string $role, array $blocks): void {
    if (!$blocks) return;
    $last = count($messages)-1;
    if ($last >= 0 && $messages[$last]['role'] === $role) {
        $messages[$last]['content'] = array_merge($messages[$last]['content'], $blocks);
    } else $messages[] = ['role'=>$role,'content'=>$blocks];
}

function ai_anthropic_messages(array $messages): array {
    $system = []; $turns = [];
    foreach ($messages as $message) {
        $role = $message['role'] ?? '';
        $text = ai_content_text($message['content'] ?? '');
        if (in_array($role, ['system','developer'], true)) { if ($text !== '') $system[] = $text; continue; }
        if ($role === 'tool') {
            $decoded = json_decode($text, true);
            $block = ['type'=>'tool_result','tool_use_id'=>$message['tool_call_id'],'content'=>$text];
            if (is_array($decoded) && array_key_exists('error', $decoded)) $block['is_error'] = true;
            ai_anthropic_append($turns, 'user', [$block]);
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') continue;
        // Preserve the provider's original block order and signed thinking on tool turns.
        $blocks = $role === 'assistant' && is_array($message['_anthropic_content'] ?? null) ? $message['_anthropic_content'] : [];
        if ($blocks) {
            foreach ($blocks as &$block) {
                if (($block['type'] ?? '') !== 'tool_use') continue;
                // Arguments are kept as JSON strings in the normalized transcript;
                // rebuild them after a browser round trip to retain nested {} vs [].
                foreach ($message['tool_calls'] ?? [] as $call) {
                    if ($call['id'] === $block['id']) {
                        $block['input'] = json_decode($call['function']['arguments'], false, 512, JSON_THROW_ON_ERROR);
                        break;
                    }
                }
                if (($block['input'] ?? null) === []) $block['input'] = new stdClass();
            }
            unset($block);
        } else {
            if ($text !== '') $blocks[] = ['type'=>'text','text'=>$text];
            if ($role === 'assistant') foreach ($message['tool_calls'] ?? [] as $call) {
                $arguments = json_decode($call['function']['arguments'], false, 512, JSON_THROW_ON_ERROR);
                if (!is_object($arguments)) throw new InvalidArgumentException('AI 工具参数必须是 JSON 对象');
                $blocks[] = ['type'=>'tool_use','id'=>$call['id'],'name'=>$call['function']['name'],'input'=>$arguments];
            }
        }
        ai_anthropic_append($turns, $role, $blocks);
    }
    return ['messages'=>$turns,'system'=>implode("\n\n", $system)];
}

function ai_build_request(array $config, array $messages, array $tools = [], ?int $maxTokens = null): array {
    $provider = strtolower((string)($config['provider'] ?? ''));
    $body = ['model'=>$config['model'],'stream'=>false];
    $headers = ['Content-Type: application/json'];
    $limit = $maxTokens ?? AI_MAX_TOKENS;
    if ($limit < 1) throw new InvalidArgumentException('输出长度必须大于零');
    $key = (string)($config['api_key'] ?? '');
    if (preg_match('/[\r\n\x00]/', $key)) throw new InvalidArgumentException('API Key 格式无效');
    if ($provider === 'anthropic') {
        $converted = ai_anthropic_messages($messages);
        $body['messages'] = $converted['messages'];
        if ($converted['system'] !== '') $body['system'] = $converted['system'];
        $body['max_tokens'] = $limit;
        $headers[] = 'anthropic-version: 2023-06-01';
        if ($key !== '') $headers[] = 'x-api-key: '.$key;
        if ($tools) $body['tools'] = array_map(static function($tool) {
            $f = $tool['function'];
            return ['name'=>$f['name'],'description'=>$f['description'] ?? '', 'input_schema'=>$f['parameters']];
        }, $tools);
    } else {
        $body['messages'] = array_map(static function($message) use ($provider, $config) {
            $allowed = ['role','content','name','tool_call_id','tool_calls'];
            if ($provider !== 'openai') $allowed[] = 'reasoning_content';
            $message = array_intersect_key($message, array_flip($allowed));
            if ($provider === 'openai' && ($message['role'] ?? '') === 'system' && preg_match('/^(?:o\d|gpt-(?:[5-9]|\d{2,}))/', $config['model'])) $message['role'] = 'developer';
            return $message;
        }, $messages);
        if ($provider === 'openai') $body['max_completion_tokens'] = $limit;
        else { $body['max_tokens'] = $limit; $body['temperature'] = AI_TEMPERATURE; }
        if ($tools) $body['tools'] = $tools;
        if ($key !== '') $headers[] = 'Authorization: Bearer '.$key;
    }
    return ['url'=>ai_endpoint_url($config),'body'=>$body,'headers'=>$headers];
}

/** Normalize both protocols, including tool calls, token usage and truncation. */
function ai_parse_response(array $config, array $data): array {
    $message = ['role'=>'assistant','content'=>'','_usage'=>$data['usage'] ?? [],'_model'=>$data['model'] ?? $config['model']];
    if (strtolower((string)($config['provider'] ?? '')) === 'anthropic') {
        if (($data['type'] ?? '') !== 'message' || !is_array($data['content'] ?? null)) throw new RuntimeException('Anthropic 返回的消息格式无效');
        $blocks = $data['content']; $texts = []; $calls = [];
        foreach ($blocks as &$block) {
            if (($block['type'] ?? '') === 'text') $texts[] = $block['text'];
            elseif (($block['type'] ?? '') === 'tool_use') {
                if (empty($block['id']) || empty($block['name']) || !isset($block['input']) || (!is_array($block['input']) && !is_object($block['input']))) throw new RuntimeException('Anthropic 返回了无效的工具调用');
                if (is_array($block['input']) && $block['input'] !== [] && array_is_list($block['input'])) throw new RuntimeException('Anthropic 工具参数必须是 JSON 对象');
                if (($block['input'] ?? null) === []) $block['input'] = new stdClass();
                $calls[] = ['id'=>$block['id'],'type'=>'function','function'=>['name'=>$block['name'],'arguments'=>json_encode($block['input'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]];
            }
        }
        unset($block);
        $message['content'] = implode("\n", $texts);
        if ($calls) { $message['tool_calls'] = $calls; $message['_anthropic_content'] = $blocks; }
        $reason = $data['stop_reason'] ?? '';
        $message['_finish_reason'] = ['end_turn'=>'stop','stop_sequence'=>'stop','tool_use'=>'tool_calls','max_tokens'=>'length'][$reason] ?? $reason;
        $usage = $data['usage'] ?? [];
        $input = (int)($usage['input_tokens'] ?? 0)+(int)($usage['cache_read_input_tokens'] ?? 0)+(int)($usage['cache_creation_input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        $message['_usage'] = ['prompt_tokens'=>$input,'completion_tokens'=>$output,'total_tokens'=>$input+$output];
    } else {
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) throw new RuntimeException('OpenAI 兼容接口返回的消息格式无效');
        $wire = $choice['message'];
        $message['content'] = ai_content_text($wire['content'] ?? $wire['refusal'] ?? '');
        $message['_finish_reason'] = $choice['finish_reason'] ?? '';
        if (!empty($wire['tool_calls'])) $message['tool_calls'] = $wire['tool_calls'];
        if (!empty($wire['reasoning_content'])) $message['reasoning_content'] = $wire['reasoning_content'];
    }
    return $message;
}
