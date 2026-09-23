<?php
/**
 * CSS sanity checker (CLI only).
 *
 *   php scripts/css_check.php [file...]      # defaults to assets/css/*.css
 *
 * This codebase has silently lost three rules to brace errors (a complete rule
 * written inside another rule's declaration block, and stray declarations at
 * top level). Browsers do not report those, so check them mechanically:
 *   1. braces must balance
 *   2. no declaration may sit at nesting depth 0
 *   3. no rule may open while a declaration block is already open (outside @media)
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

$files = array_slice($argv, 1);
if (!$files) $files = glob(__DIR__ . '/../assets/css/*.css');

$problems = 0;
foreach ($files as $file) {
    $src = @file_get_contents($file);
    if ($src === false) { echo "!! 无法读取 $file\n"; $problems++; continue; }

    // Strip comments and strings so their braces/semicolons don't confuse us.
    $clean = preg_replace('#/\*.*?\*/#s', '', $src);
    $clean = preg_replace('#"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'#s', '""', $clean);

    $depth = 0;          // brace depth
    $atRule = [];        // depth -> true when that level was opened by @media/@supports
    $buf = '';
    $line = 1;
    $len = strlen($clean);
    $issues = [];

    for ($i = 0; $i < $len; $i++) {
        $ch = $clean[$i];
        if ($ch === "\n") { $line++; $buf .= ' '; continue; }

        if ($ch === '{') {
            $selector = trim(preg_replace('/\s+/', ' ', $buf));
            $isAt = str_starts_with($selector, '@');
            // A rule opening inside a declaration block (and not inside an at-rule)
            // is the ".task-card-tag { .task-card-note { … } }" bug.
            if ($depth > 0 && empty($atRule[$depth])) {
                $issues[] = "第 {$line} 行: 规则「{$selector}」嵌在另一条规则的声明块内（会被解析器吞掉）";
            }
            $depth++;
            $atRule[$depth] = $isAt;
            $buf = '';
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth < 0) { $issues[] = "第 {$line} 行: 多余的 }"; $depth = 0; }
            $buf = '';
            continue;
        }

        if ($ch === ';') {
            $decl = trim(preg_replace('/\s+/', ' ', $buf));
            // A declaration at depth 0 is the stray "align-items: center;" bug.
            if ($depth === 0 && $decl !== '' && !str_starts_with($decl, '@')) {
                $issues[] = "第 {$line} 行: 顶层游离声明「{$decl};」（会吞掉紧随其后的规则）";
            }
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }

    if ($depth !== 0) $issues[] = "文件结束时括号未闭合（深度 {$depth}）";

    $name = basename($file);
    if ($issues) {
        $problems += count($issues);
        echo "✗ {$name}\n";
        foreach ($issues as $m) echo "    {$m}\n";
    } else {
        $rules = substr_count($clean, '{');
        echo "✓ {$name}（{$rules} 个块，括号配平，无游离声明）\n";
    }
}

if ($problems) { echo "\n发现 {$problems} 处问题\n"; exit(1); }
echo "\n全部通过\n";
