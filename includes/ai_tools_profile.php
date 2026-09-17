<?php
/**
 * AI tools: user identity documents + AI impressions (memory about the user).
 * Included by api/ai.php; handlers rely on current_user_id().
 */

require_once __DIR__ . '/profile_context.php';

function ai_tools_profile_definitions(): array {
    $fields = array_keys(profile_structured_fields());
    $fields[] = 'observation';
    $fieldDesc = [];
    foreach (profile_structured_fields() as $k => $label) $fieldDesc[] = "$k($label)";
    $fieldDesc[] = 'observation(自由观察，追加记录)';

    return [
        // ===== USER IDENTITY DOCUMENTS =====
        [
            'name' => 'get_profile_documents',
            'description' => '列出用户上传的身份文档（简历/CV 等，权威来源）。返回 id、标题、文件名、是否主文档、字符数。',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_get_profile_documents',
        ],
        [
            'name' => 'get_profile_document_text',
            'description' => '读取某个身份文档的完整提取文本（用于核对用户背景、匹配邮件相关度等）。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => '文档ID（必填）'],
                    'max_chars' => ['type' => 'integer', 'description' => '最多返回字符数，默认 8000，上限 20000'],
                ],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_get_profile_document_text',
        ],
        // ===== AI IMPRESSIONS (memory) =====
        [
            'name' => 'list_impressions',
            'description' => '列出 AI 对用户的印象/侧写记录（职称、职位、单位、工作重心、研究方向、技能、项目、偏好，以及自由观察）。',
            'parameters' => ['type' => 'object', 'properties' => []],
            'requires_confirmation' => false,
            'handler' => 'handle_list_impressions',
        ],
        [
            'name' => 'remember_about_user',
            'description' => '静默记录/更新关于用户的稳定事实（不需要用户确认）。当用户在对话中透露职称、职位、单位、工作重心、研究方向、技能、长期项目或偏好时调用。结构化字段会覆盖旧值；observation 会追加。不要记录一次性琐事。字段: ' . implode('、', $fieldDesc),
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'field' => ['type' => 'string', 'enum' => $fields, 'description' => '字段名（必填）'],
                    'value' => ['type' => 'string', 'description' => '内容，简洁的中文短语或一句话（必填）'],
                    'confidence' => ['type' => 'integer', 'description' => '置信度 0-100，默认 70'],
                    'evidence' => ['type' => 'string', 'description' => '依据摘要（用户原话的简短引用）'],
                ],
                'required' => ['field', 'value'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_remember_about_user',
        ],
        [
            'name' => 'forget_impression',
            'description' => '删除一条错误或过时的印象记录。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => '印象记录ID（必填）']],
                'required' => ['id'],
            ],
            'requires_confirmation' => false,
            'handler' => 'handle_forget_impression',
        ],
    ];
}

function handle_get_profile_documents(PDO $db, array $args): array {
    $st = $db->prepare('SELECT id, kind, title, file_name, is_primary, CHAR_LENGTH(COALESCE(extracted_text, "")) AS chars, updated_at
                        FROM profile_documents WHERE user_id = ? ORDER BY is_primary DESC, updated_at DESC');
    $st->execute([current_user_id()]);
    return $st->fetchAll();
}

function handle_get_profile_document_text(PDO $db, array $args): array {
    $id = (int)($args['id'] ?? 0);
    $max = max(500, min(20000, (int)($args['max_chars'] ?? 8000)));
    $st = $db->prepare('SELECT id, title, file_name, extracted_text FROM profile_documents WHERE id = ? AND user_id = ?');
    $st->execute([$id, current_user_id()]);
    $row = $st->fetch();
    if (!$row) return ['error' => '文档不存在'];
    return ['id' => (int)$row['id'], 'title' => $row['title'], 'file_name' => $row['file_name'],
            'text' => ai_truncate($row['extracted_text'], $max)];
}

function handle_list_impressions(PDO $db, array $args): array {
    $snap = profile_impressions_snapshot($db, current_user_id(), 30);
    $fields = [];
    foreach ($snap['fields'] as $f => $r) {
        $fields[] = ['id' => (int)$r['id'], 'field' => $f, 'label' => profile_field_label($f), 'value' => $r['value'],
                     'confidence' => (int)$r['confidence'], 'source' => $r['source'], 'updated_at' => $r['updated_at']];
    }
    $obs = [];
    foreach ($snap['observations'] as $r) {
        $obs[] = ['id' => (int)$r['id'], 'value' => $r['value'], 'confidence' => (int)$r['confidence'], 'updated_at' => $r['updated_at']];
    }
    return ['fields' => $fields, 'observations' => $obs];
}

function handle_remember_about_user(PDO $db, array $args): array {
    try {
        $res = profile_remember($db, current_user_id(), (string)($args['field'] ?? 'observation'),
                                (string)($args['value'] ?? ''), (int)($args['confidence'] ?? 70),
                                (string)($args['evidence'] ?? ''), null, 'ai');
    } catch (InvalidArgumentException $e) {
        return ['error' => $e->getMessage()];
    }
    $label = profile_field_label($res['field']);
    $short = mb_strlen($res['value'], 'UTF-8') > 40 ? mb_substr($res['value'], 0, 40, 'UTF-8') . '…' : $res['value'];
    if ($res['action'] === 'created' || $res['action'] === 'updated') {
        $res['_notice'] = "🧠 已更新侧写：{$label} → {$short}";
    }
    return $res;
}

function handle_forget_impression(PDO $db, array $args): array {
    $id = (int)($args['id'] ?? 0);
    $st = $db->prepare('SELECT field, value FROM profile_impressions WHERE id = ? AND user_id = ?');
    $st->execute([$id, current_user_id()]);
    $row = $st->fetch();
    if (!$row) return ['error' => '记录不存在'];
    $db->prepare('DELETE FROM profile_impressions WHERE id = ? AND user_id = ?')->execute([$id, current_user_id()]);
    return ['deleted' => true, 'id' => $id, '_notice' => '🧠 已删除侧写条目：' . profile_field_label($row['field'])];
}
