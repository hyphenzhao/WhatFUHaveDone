<?php
/**
 * Task Management Page
 */
$page_title = '任务管理';
$current_page = 'tasks';
$page_content = <<<HTML
<div class="page-header">
    <h2>📝 任务管理</h2>
    <div style="display:flex;gap:8px;align-items:center;">
        <select class="form-select" id="stageFilter" style="width:auto;">
            <option value="all">全部阶段</option>
            <option value="in_progress">🔄 进行中</option>
            <option value="stage_complete">✅ 阶段性完成</option>
            <option value="completed">🎉 已完成</option>
        </select>
        <select class="form-select" id="sortSelect" style="width:auto;">
            <option value="priority">按优先级</option>
            <option value="updated_at">按更新时间</option>
        </select>
        <button class="btn btn-ghost btn-sm" id="sortDir" title="升序/降序">▲</button>
        <button class="btn btn-primary" onclick="showTaskModal()">＋ 新建任务</button>
    </div>
</div>

<table class="data-table" id="tasksTable">
    <thead>
        <tr>
            <th>任务名称</th>
            <th>优先级</th>
            <th>阶段</th>
            <th>阶段#</th>
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
