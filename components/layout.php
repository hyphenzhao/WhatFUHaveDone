<?php
/**
 * Base HTML layout shell
 * Usage: Set $page_title before including, then output $page_content in the main area.
 */
$page_title = $page_title ?? APP_NAME;
$current_page = $current_page ?? 'home';
$asset_ver = ASSET_VER;
$layout_user = current_user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $asset_ver ?>">
    <link rel="stylesheet" href="/assets/css/mail.css?v=<?= $asset_ver ?>">
    <!-- Loaded last: contains ONLY max-width media queries (+ one 900–1279 block),
         so it cannot affect the ≥1280px desktop. Delete this line to roll back. -->
    <link rel="stylesheet" href="/assets/css/mobile.css?v=<?= $asset_ver ?>">
</head>
<body data-page="<?= htmlspecialchars($current_page) ?>">
    <div class="app-layout<?= $current_page !== 'home' ? ' no-right-panel' : '' ?>">
        <!-- Left Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h1 class="sidebar-logo">📋 <?= APP_NAME ?></h1>
                <button class="sidebar-toggle" id="sidebarToggle" title="折叠/展开">◀</button>
            </div>
            <nav class="sidebar-nav">
                <a href="/" class="sidebar-link <?= $current_page === 'home' ? 'active' : '' ?>">
                    <span class="nav-icon">🏠</span> 主页
                </a>
                <a href="/reports" class="sidebar-link <?= $current_page === 'reports' ? 'active' : '' ?>">
                    <span class="nav-icon">📊</span> 周期报告
                </a>
                <a href="/mail" class="sidebar-link <?= $current_page === 'mail' ? 'active' : '' ?>">
                    <span class="nav-icon">📧</span> 邮箱
                </a>
                <div class="nav-group">
                    <div class="nav-group-title">内容管理</div>
                    <a href="/people" class="sidebar-link <?= $current_page === 'people' ? 'active' : '' ?>">
                        <span class="nav-icon">👤</span> 人物
                    </a>
                    <a href="/tasks" class="sidebar-link <?= $current_page === 'tasks' ? 'active' : '' ?>">
                        <span class="nav-icon">📝</span> 任务
                    </a>
                    <a href="/results" class="sidebar-link <?= $current_page === 'results' ? 'active' : '' ?>">
                        <span class="nav-icon">🏆</span> 成果
                    </a>
                    <a href="/tags" class="sidebar-link <?= $current_page === 'tags' ? 'active' : '' ?>">
                        <span class="nav-icon">🏷️</span> 标签
                    </a>
                    <a href="/relationships" class="sidebar-link <?= $current_page === 'relationships' ? 'active' : '' ?>">
                        <span class="nav-icon">🔗</span> 人际管理
                    </a>
                </div>
                <div class="nav-group">
                    <div class="nav-group-title">系统管理</div>
                    <a href="/calendar-admin" class="sidebar-link <?= $current_page === 'calendar-admin' ? 'active' : '' ?>">
                        <span class="nav-icon">📅</span> 日历管理
                    </a>
                    <a href="/ai-admin" class="sidebar-link <?= $current_page === 'ai-admin' ? 'active' : '' ?>">
                        <span class="nav-icon">🤖</span> AI 配置
                    </a>
                    <a href="/mail-admin" class="sidebar-link <?= $current_page === 'mail-admin' ? 'active' : '' ?>">
                        <span class="nav-icon">📮</span> 邮箱管理
                    </a>
                    <a href="/profile" class="sidebar-link <?= $current_page === 'profile' ? 'active' : '' ?>">
                        <span class="nav-icon">👤</span> 个人侧写
                    </a>
                    <a href="/skills" class="sidebar-link <?= $current_page === 'skills' ? 'active' : '' ?>">
                        <span class="nav-icon">🛠️</span> 技能管理
                    </a>
                    <?php if (is_admin()): ?>
                    <a href="/users" class="sidebar-link <?= $current_page === 'users' ? 'active' : '' ?>">
                        <span class="nav-icon">👥</span> 用户管理
                    </a>
                    <?php endif; ?>
                </div>
            </nav>
            <div class="sidebar-footer">
                <span class="sidebar-user" title="<?= htmlspecialchars($layout_user['username'] ?? '') ?>">
                    <span class="nav-icon">🙋</span>
                    <span class="sidebar-user-name"><?= htmlspecialchars(($layout_user['display_name'] ?? '') !== '' ? $layout_user['display_name'] : ($layout_user['username'] ?? '')) ?></span>
                </span>
                <button class="sidebar-logout" onclick="logout()" title="登出">⏻</button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content" id="mainContent">
            <?= $page_content ?? '' ?>
        </main>

        <?php
        /* Right panel. Rendered on EVERY page so the AI assistant is reachable
           everywhere on touch devices (it becomes a bottom sheet below 900px).
           Off home there is no task list — home.js/task-card.js aren't loaded —
           so only the assistant tab is emitted, and .app-layout keeps its
           no-right-panel class so the >=1280px desktop grid is unchanged
           (mobile.css hides the panel outright in that one case). */
        $is_home = ($current_page === 'home');
        ?>
        <?php if ($is_home): ?>
        <div class="panel-resize-handle" id="panelResizeHandle"></div>
        <?php endif; ?>
        <aside class="right-panel" id="rightPanel">
            <div class="right-panel-header">
                <div class="right-panel-tabs">
                    <?php if ($is_home): ?>
                    <button class="rp-tab rp-tab-active" data-tab="tasklist">📋 任务列表</button>
                    <button class="rp-refresh-btn" onclick="loadRightPanel()" title="刷新任务列表和备注">🔄</button>
                    <?php endif; ?>
                    <button class="rp-tab<?= $is_home ? '' : ' rp-tab-active' ?>" data-tab="ai-assistant">🤖 智能助手</button>
                </div>
                <button class="panel-toggle" id="rightPanelToggle">▶</button>
                <button class="m-sheet-close" id="mSheetClose" aria-label="关闭">&times;</button>
            </div>
            <?php if ($is_home): ?>
            <div class="right-panel-body" id="rightPanelBody">
                <!-- Task cards rendered by JS -->
            </div>
            <?php endif; ?>
            <div class="right-panel-body<?= $is_home ? ' rp-hidden' : '' ?>" id="rightPanelAi">
                <!-- AI Assistant rendered by JS -->
            </div>
        </aside>
    </div>

    <!-- Mobile chrome (≤899px only; display:none above that, so the desktop is untouched) -->
    <div class="m-scrim" id="mScrim"></div>
    <nav class="mobile-tabbar" id="mobileTabbar">
        <a class="mtab<?= $is_home ? ' active' : '' ?>" href="/"><span>🏠</span>主页</a>
        <button class="mtab" data-sheet="tasklist"<?= $is_home ? '' : ' data-href="/?panel=tasks"' ?>><span>📋</span>任务</button>
        <button class="mtab" data-sheet="ai-assistant"><span>🤖</span>助手</button>
        <a class="mtab<?= $current_page === 'mail' ? ' active' : '' ?>" href="/mail"><span>📧</span>邮箱</a>
        <button class="mtab" id="mNavBtn"><span>☰</span>更多</button>
    </nav>
    <!-- Tier B (900–1279px): edge tab that pulls the right panel out as an overlay -->
    <button class="m-edge-tab" id="mEdgeTab" aria-label="任务与助手">📋</button>

    <!-- Modal overlay -->
    <div class="modal-overlay" id="modalOverlay" style="display:none;">
        <div class="modal-container" id="modalContainer">
            <div class="modal-header">
                <h3 id="modalTitle"></h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>

    <!-- Toast notifications -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Scripts -->
    <script>window.CURRENT_USER = <?= json_encode($layout_user ? [
        'id' => (int)$layout_user['id'],
        'username' => $layout_user['username'],
        'display_name' => $layout_user['display_name'],
        'role' => $layout_user['role'],
    ] : null, JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="/assets/js/api.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/modal.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/app.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/attachments.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/ai-assistant.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/mail-modal.js?v=<?= $asset_ver ?>"></script>
    <?php if ($current_page === 'home'): ?>
    <script src="/assets/js/lunar.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/task-card.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/calendar.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/home.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/task-detail.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'mail'): ?>
    <script src="/assets/js/mail.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'mail-admin'): ?>
    <script src="/assets/js/mail-admin.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'people'): ?>
    <script src="/assets/js/people.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'tasks'): ?>
    <script src="/assets/js/tasks.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'results'): ?>
    <script src="/assets/js/results.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'reports'): ?>
    <script src="/assets/js/calendar.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/reports.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'tags'): ?>
    <script src="/assets/js/tags.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'relationships'): ?>
    <script src="/assets/js/graph.js?v=<?= $asset_ver ?>"></script>
    <script src="/assets/js/relationships.js?v=<?= $asset_ver ?>"></script>
    <?php elseif ($current_page === 'users'): ?>
    <script src="/assets/js/users.js?v=<?= $asset_ver ?>"></script>
    <?php endif; ?>
    <!-- Last: owns the panel tabs for every page and the mobile drawers/sheet -->
    <script src="/assets/js/mobile.js?v=<?= $asset_ver ?>"></script>
</body>
</html>
