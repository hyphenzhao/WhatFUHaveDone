<?php
/**
 * Base HTML layout shell
 * Usage: Set $page_title before including, then output $page_content in the main area.
 */
$page_title = $page_title ?? APP_NAME;
$current_page = $current_page ?? 'home';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
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
                <a href="/people" class="sidebar-link <?= $current_page === 'people' ? 'active' : '' ?>">
                    <span class="nav-icon">👤</span> 人物管理
                </a>
                <a href="/tasks" class="sidebar-link <?= $current_page === 'tasks' ? 'active' : '' ?>">
                    <span class="nav-icon">📝</span> 任务管理
                </a>
                <a href="/results" class="sidebar-link <?= $current_page === 'results' ? 'active' : '' ?>">
                    <span class="nav-icon">🏆</span> 成果管理
                </a>
                <a href="/tags" class="sidebar-link <?= $current_page === 'tags' ? 'active' : '' ?>">
                    <span class="nav-icon">🏷️</span> 标签管理
                </a>
                <a href="/relationships" class="sidebar-link <?= $current_page === 'relationships' ? 'active' : '' ?>">
                    <span class="nav-icon">🔗</span> 人际关系
                </a>
                <a href="/calendar-admin" class="sidebar-link <?= $current_page === 'calendar-admin' ? 'active' : '' ?>">
                    <span class="nav-icon">📅</span> 日历管理
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content" id="mainContent">
            <?= $page_content ?? '' ?>
        </main>

        <!-- Right Panel (task sidebar on home page) -->
        <?php if ($current_page === 'home'): ?>
        <aside class="right-panel" id="rightPanel">
            <div class="right-panel-header">
                <h2>📋 任务列表</h2>
                <button class="panel-toggle" id="rightPanelToggle">▶</button>
            </div>
            <div class="right-panel-body" id="rightPanelBody">
                <!-- Task cards rendered by JS -->
            </div>
        </aside>
        <?php endif; ?>
    </div>

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
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/modal.js"></script>
    <script src="/assets/js/app.js"></script>
    <?php if ($current_page === 'home'): ?>
    <script src="/assets/js/task-card.js"></script>
    <script src="/assets/js/calendar.js"></script>
    <script src="/assets/js/home.js"></script>
    <?php elseif ($current_page === 'people'): ?>
    <script src="/assets/js/people.js"></script>
    <?php elseif ($current_page === 'tasks'): ?>
    <script src="/assets/js/tasks.js"></script>
    <?php elseif ($current_page === 'results'): ?>
    <script src="/assets/js/results.js"></script>
    <?php elseif ($current_page === 'tags'): ?>
    <script src="/assets/js/tags.js"></script>
    <?php elseif ($current_page === 'relationships'): ?>
    <script src="/assets/js/graph.js"></script>
    <script src="/assets/js/relationships.js"></script>
    <?php endif; ?>
</body>
</html>
