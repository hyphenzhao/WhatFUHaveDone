<?php
/**
 * Users Management Page (admin only)
 */
if (!is_admin()) {
    header('Location: /');
    exit;
}
$page_title = '用户管理';
$current_page = 'users';
$page_content = <<<HTML
<div class="page-header">
    <h2>👥 用户管理</h2>
    <button class="btn btn-primary" onclick="showUserModal()">＋ 新建用户</button>
</div>

<table class="data-table" id="usersTable">
    <thead>
        <tr>
            <th>用户名</th>
            <th>显示名</th>
            <th>角色</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="usersTableBody">
        <tr><td colspan="5" style="text-align:center;color:var(--color-text-secondary);">加载中...</td></tr>
    </tbody>
</table>
HTML;

require __DIR__ . '/../components/layout.php';
