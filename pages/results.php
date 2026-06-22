<?php
/**
 * Results Management Page
 */
$page_title = '成果管理';
$current_page = 'results';
$page_content = <<<HTML
<div class="page-header">
    <h2>🏆 成果管理</h2>
    <button class="btn btn-primary" onclick="showResultModal()">＋ 新建成果</button>
</div>

<table class="data-table" id="resultsTable">
    <thead>
        <tr>
            <th data-sort="name">成果名称</th>
            <th>标签</th>
            <th data-sort="quantity">数量</th>
            <th data-sort="level">等级</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="resultsTableBody">
        <tr><td colspan="5" style="text-align:center;color:var(--color-text-secondary);">加载中...</td></tr>
    </tbody>
</table>

<div class="archived-section" id="archivedResultsSection" style="display:none;">
    <h3>📦 已归档成果</h3>
    <table class="data-table">
        <thead>
            <tr><th>成果名称</th><th>数量</th><th>等级</th><th>操作</th></tr>
        </thead>
        <tbody id="archivedResultsBody"></tbody>
    </table>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
