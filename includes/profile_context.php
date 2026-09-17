<?php
/**
 * User identity context for AI prompts.
 *
 *   - profile_documents  : user-uploaded authoritative documents (CV etc.)
 *   - profile_impressions: AI-inferred (secondary) facts, editable by the user
 *
 * Shared by api/ai.php (system prompt), MailAnalyzer and the CLI worker.
 * All functions take an explicit $uid so they work outside a session.
 */

require_once __DIR__ . '/ai_client.php';

function profile_structured_fields(): array {
    return [
        'title'         => '职称',
        'position'      => '职位',
        'organization'  => '单位',
        'work_focus'    => '工作重心',
        'research_area' => '研究方向',
        'skills'        => '技能',
        'projects'      => '在研项目',
        'preferences'   => '偏好',
    ];
}

function profile_field_label(string $field): string {
    $map = profile_structured_fields();
    if (isset($map[$field])) return $map[$field];
    return $field === 'observation' ? '观察' : $field;
}

/** Primary document row (or latest one) for the user, or null. */
function profile_primary_document(PDO $db, int $uid): ?array {
    $st = $db->prepare('SELECT * FROM profile_documents WHERE user_id = ? ORDER BY is_primary DESC, updated_at DESC LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Structured fields (latest per field) + recent observations. */
function profile_impressions_snapshot(PDO $db, int $uid, int $maxObservations = 10): array {
    $st = $db->prepare('SELECT * FROM profile_impressions WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$uid]);
    $fields = [];
    $observations = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['field'] === 'observation') {
            if (count($observations) < $maxObservations) $observations[] = $r;
        } elseif (!isset($fields[$r['field']])) {
            $fields[$r['field']] = $r;
        }
    }
    return ['fields' => $fields, 'observations' => $observations];
}

/**
 * Build the identity block for a system prompt. Never throws (tables may be
 * missing before migration 002 is applied) — returns '' in that case.
 */
function profile_identity_block(PDO $db, int $uid, int $docBudget = 1500, int $impBudget = 800): string {
    $out = '';
    try {
        $doc = profile_primary_document($db, $uid);
        if ($doc && trim((string)$doc['extracted_text']) !== '') {
            $label = $doc['title'] !== '' ? $doc['title'] : $doc['file_name'];
            $out .= "=== USER IDENTITY (authoritative, from uploaded document: {$label}) ===\n";
            $out .= ai_truncate($doc['extracted_text'], $docBudget) . "\n\n";
        }

        $snap = profile_impressions_snapshot($db, $uid, 10);
        if ($snap['fields'] || $snap['observations']) {
            $block = "=== AI IMPRESSIONS (secondary; the document above wins on conflict) ===\n";
            foreach ($snap['fields'] as $field => $r) {
                $block .= profile_field_label($field) . "({$field}): " . trim($r['value'])
                       . " [conf {$r['confidence']}, {$r['source']}, " . substr($r['updated_at'], 0, 10) . "]\n";
            }
            foreach ($snap['observations'] as $r) {
                $block .= '- ' . trim($r['value']) . ' [' . substr($r['updated_at'], 0, 10) . "]\n";
            }
            $out .= ai_truncate($block, $impBudget) . "\n\n";
        }
    } catch (Throwable $e) {
        // Missing tables / DB hiccup: identity context is optional.
        return $out;
    }
    return $out;
}

/**
 * Record or update an impression.
 * Structured fields upsert by (user_id, field); observations append (deduped by value).
 * Returns ['action' => created|updated|unchanged, 'id' => int, 'field' => ..., 'value' => ...]
 */
function profile_remember(PDO $db, int $uid, string $field, string $value, int $confidence = 70,
                          string $evidence = '', ?int $conversationId = null, string $source = 'ai'): array {
    $field = trim($field);
    $value = trim($value);
    $allowed = array_keys(profile_structured_fields());
    $allowed[] = 'observation';
    if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException('未知字段: ' . $field . '，可选: ' . implode('/', $allowed));
    }
    if ($value === '') throw new InvalidArgumentException('value 不能为空');
    $confidence = max(0, min(100, $confidence));
    $evidence = mb_substr($evidence, 0, 500, 'UTF-8');
    $value = mb_substr($value, 0, 2000, 'UTF-8');

    if ($field === 'observation') {
        $st = $db->prepare('SELECT id FROM profile_impressions WHERE user_id = ? AND field = ? AND value = ? LIMIT 1');
        $st->execute([$uid, $field, $value]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $db->prepare('UPDATE profile_impressions SET confidence = GREATEST(confidence, ?), updated_at = NOW() WHERE id = ?')
               ->execute([$confidence, (int)$existing]);
            return ['action' => 'unchanged', 'id' => (int)$existing, 'field' => $field, 'value' => $value];
        }
        $db->prepare('INSERT INTO profile_impressions (user_id, field, value, confidence, source, conversation_id, evidence) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$uid, $field, $value, $confidence, $source, $conversationId, $evidence]);
        return ['action' => 'created', 'id' => (int)$db->lastInsertId(), 'field' => $field, 'value' => $value];
    }

    $st = $db->prepare('SELECT id, value, source FROM profile_impressions WHERE user_id = ? AND field = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$uid, $field]);
    $row = $st->fetch();
    if ($row) {
        if (trim($row['value']) === $value) {
            return ['action' => 'unchanged', 'id' => (int)$row['id'], 'field' => $field, 'value' => $value];
        }
        // A user-entered value is authoritative; AI may not silently overwrite it.
        if ($row['source'] === 'user' && $source === 'ai') {
            return ['action' => 'unchanged', 'id' => (int)$row['id'], 'field' => $field, 'value' => $row['value'], 'note' => '用户手动设置的值优先，未覆盖'];
        }
        $db->prepare('UPDATE profile_impressions SET value = ?, confidence = ?, source = ?, conversation_id = ?, evidence = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$value, $confidence, $source, $conversationId, $evidence, (int)$row['id']]);
        return ['action' => 'updated', 'id' => (int)$row['id'], 'field' => $field, 'value' => $value, 'previous' => $row['value']];
    }
    $db->prepare('INSERT INTO profile_impressions (user_id, field, value, confidence, source, conversation_id, evidence) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $field, $value, $confidence, $source, $conversationId, $evidence]);
    return ['action' => 'created', 'id' => (int)$db->lastInsertId(), 'field' => $field, 'value' => $value];
}
