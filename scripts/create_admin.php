<?php
/**
 * CLI: create the first admin user (for fresh installs).
 *
 * Usage: php scripts/create_admin.php <username> <password> [display_name]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$display_name = $argv[3] ?? $username;

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/create_admin.php <username> <password> [display_name]\n");
    exit(1);
}

$db = get_db();

$stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    fwrite(STDERR, "User '{$username}' already exists\n");
    exit(1);
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $display_name, password_hash($password, PASSWORD_DEFAULT), 'admin']);
    $uid = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO user_profile (user_id, name) VALUES (?, ?)')->execute([$uid, $display_name]);
    $db->prepare('INSERT INTO ai_config (user_id, provider, endpoint, api_key, model) VALUES (?, ?, ?, ?, ?)')
       ->execute([$uid, AI_DEFAULT_PROVIDER, AI_DEFAULT_ENDPOINT, AI_DEFAULT_API_KEY, AI_DEFAULT_MODEL]);
    $db->prepare('INSERT INTO people (user_id, name, relationship, is_me) VALUES (?, ?, ?, 1)')
       ->execute([$uid, $display_name, '本人']);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Admin user '{$username}' created (id={$uid})\n";
