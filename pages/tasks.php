<?php
/**
 * Task Management Page
 */
$page_title = '任务管理';
$current_page = 'tasks';
$page_content = <<<HTML
<div class="page-header">
    <h2>📝 任务管理</h2>
    <button class="btn btn-primary" onclick="showTaskModal()">＋ 新建任务</button>
</div>

<table class="data-table" id="tasksTable">
    <thead>
        <tr>
            <th data-sort="name">任务名称</th>
            <th data-sort="stage">阶段</th>
            <th data-sort="stage_number">阶段#</th>
            <th>受益人</th>
            <th>标签</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="tasksTableBody">
        <tr><td colspan="6" style="text-align:center;color:var(--color-text-secondary);">加载中...</td></tr>
    </tbody>
</table>

<div class="archived-section" id="archivedTasksSection" style="display:none;">
    <h3>📦 已归档任务</h3>
    <table class="data-table">
        <thead>
            <tr><th>任务名称</th><th>阶段</th><th>阶段#</th><th>操作</th></tr>
        </thead>
        <tbody id="archivedTasksBody"></tbody>
    </table>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
