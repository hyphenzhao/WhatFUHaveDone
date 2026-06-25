<?php
/**
 * Skills API
 *
 * GET    /api/skills          — list all skills
 * GET    /api/skills/{id}     — get single skill
 * POST   /api/skills/refresh  — scan skills/ dir and import
 * PUT    /api/skills/{id}     — update skill (toggle enabled)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$action = $parts[2] ?? '';

if ($method === 'GET' && $action === 'refresh') {
    // Scan skills/ directory for SKILL.md files
    $skillsDir = __DIR__ . '/../skills';
    if (!is_dir($skillsDir)) {
        json_error('Skills directory not found. Place skills in skills/ directory.', 404);
    }

    $imported = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skillsDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getFilename() !== 'SKILL.md') continue;

        $path = $file->getPathname();
        $content = file_get_contents($path);
        $relPath = str_replace(__DIR__ . '/../', '', $path);

        // Parse frontmatter (YAML-like between --- markers)
        $name = basename(dirname($path));
        $description = '';
        $body = $content;
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $m)) {
            $front = $m[1];
            $body = $m[2];
            if (preg_match('/^name:\s*(.+)$/m', $front, $nm)) $name = trim($nm[1]);
            if (preg_match('/^description:\s*(.+)$/m', $front, $dm)) {
                $desc = trim($dm[1]);
                if ($desc && $desc[0] === '>') {
                    $desc = trim(preg_replace('/^>\s*/m', '', substr($desc, 1)));
                }
                if ($desc && $desc[0] === '|') {
                    $desc = trim(substr($desc, 1));
                }
                $description = $desc;
            }
        }

        // Upsert
        $stmt = $db->prepare('INSERT INTO ai_skills (name, description, file_path, content, enabled) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE description=VALUES(description), file_path=VALUES(file_path), content=VALUES(content)');
        $stmt->execute([$name, $description, $relPath, $body]);
        $imported++;
    }

    json_success(['imported' => $imported], "Imported $imported skills");
}

if ($method === 'GET' && !$action) {
    $stmt = $db->query('SELECT id, name, description, file_path, enabled, updated_at FROM ai_skills ORDER BY name');
    json_success($stmt->fetchAll());
}

if ($method === 'GET' && is_numeric($action)) {
    $stmt = $db->prepare('SELECT * FROM ai_skills WHERE id = ?');
    $stmt->execute([(int)$action]);
    $skill = $stmt->fetch();
    if (!$skill) json_error('Not found', 404);
    json_success($skill);
}

if ($method === 'PUT' && is_numeric($action)) {
    $data = get_json_input();
    $enabled = isset($data['enabled']) ? (int)$data['enabled'] : null;
    if ($enabled !== null) {
        $db->prepare('UPDATE ai_skills SET enabled = ? WHERE id = ?')->execute([$enabled, (int)$action]);
        json_success(null, $enabled ? 'Skill enabled' : 'Skill disabled');
    }
    json_error('No fields to update');
}

json_error('Method not allowed', 405);
