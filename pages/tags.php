<?php
/**
 * Tags Management Page
 */
$page_title = '标签管理';
$current_page = 'tags';
$page_content = <<<HTML
<div class="page-header">
    <h2>🏷️ 标签管理</h2>
    <button class="btn btn-primary" onclick="showTagModal()">＋ 新建标签</button>
</div>

<table class="data-table" id="tagsTable">
    <thead>
        <tr>
            <th>颜色</th>
            <th data-sort="name">名称</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="tagsTableBody">
        <tr><td colspan="3" style="text-align:center;color:var(--color-text-secondary);">加载中...</td></tr>
    </tbody>
</table>

<div class="archived-section" id="archivedTagsSection" style="display:none;">
    <h3>📦 已归档标签</h3>
    <table class="data-table">
        <thead>
            <tr><th>颜色</th><th>名称</th><th>操作</th></tr>
        </thead>
        <tbody id="archivedTagsBody"></tbody>
    </table>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
