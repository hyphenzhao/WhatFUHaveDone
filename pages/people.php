<?php
/**
 * People Management Page
 */
$page_title = '人物管理';
$current_page = 'people';
$page_content = <<<HTML
<div class="page-header">
    <h2>👤 人物管理</h2>
    <button class="btn btn-primary" onclick="showPersonModal()">＋ 新建人物</button>
</div>

<table class="data-table" id="peopleTable">
    <thead>
        <tr>
            <th data-sort="name">姓名</th>
            <th data-sort="relationship">关系</th>
            <th data-sort="importance">重要度</th>
            <th data-sort="usefulness">有用度</th>
            <th data-sort="closeness">亲密度</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="peopleTableBody">
        <tr><td colspan="5" style="text-align:center;color:var(--color-text-secondary);">加载中...</td></tr>
    </tbody>
</table>

<div class="archived-section" id="archivedPeopleSection" style="display:none;">
    <h3>📦 已归档人物</h3>
    <table class="data-table">
        <thead>
            <tr><th>姓名</th><th>关系</th><th>重要度</th><th>有用度</th><th>操作</th></tr>
        </thead>
        <tbody id="archivedPeopleBody"></tbody>
    </table>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
