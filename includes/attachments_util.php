<?php
/**
 * Shared attachment cleanup helper.
 *
 * The `attachments` table has no DB foreign key (entity_id is a plain int), so
 * deleting an entity (result / worklog_note / report / task-cascaded notes) would
 * otherwise orphan attachment rows AND their files on disk. Delete handlers call
 * delete_attachments() to clean both.
 */

function attachments_dir(): string {
    return __DIR__ . '/../uploads/attachments';
}

/**
 * Delete all attachments (DB rows + files on disk) for the given entity ids.
 */
function delete_attachments(PDO $db, string $entity_type, array $entity_ids): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $entity_ids))));
    if (empty($ids)) return;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$entity_type], $ids);

    $sel = $db->prepare("SELECT stored_name FROM attachments WHERE entity_type = ? AND entity_id IN ($ph)");
    $sel->execute($params);
    $dir = attachments_dir();
    foreach ($sel->fetchAll() as $a) {
        $p = $dir . '/' . $a['stored_name'];
        if (is_file($p)) @unlink($p);
    }

    $db->prepare("DELETE FROM attachments WHERE entity_type = ? AND entity_id IN ($ph)")->execute($params);
}
