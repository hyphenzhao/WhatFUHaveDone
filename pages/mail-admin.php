<?php
$page_title = '邮箱管理';
$current_page = 'mail-admin';
$page_content = <<<'HTML'
<div class="page-header">
    <h2>📮 邮箱管理</h2>
    <button class="btn btn-primary" onclick="showAccountModal()">＋ 添加邮箱</button>
</div>
<div id="mailAdminNotice"></div>
<table class="data-table" id="mailAccountsTable">
    <thead><tr><th>名称</th><th>邮箱</th><th>IMAP</th><th>SMTP</th><th>状态</th><th>最近同步</th><th>操作</th></tr></thead>
    <tbody><tr><td colspan="7" style="color:var(--color-text-secondary);">加载中...</td></tr></tbody>
</table>
<p style="margin-top:14px;font-size:0.8rem;color:var(--color-text-secondary);line-height:1.6;">
    说明：QQ / 163 / Gmail / Outlook 等邮箱需要在邮箱设置里开启 IMAP/SMTP 并使用<b>授权码 / 应用专用密码</b>，而不是登录密码。
    密码使用服务器密钥加密保存，不会回显。收取邮件由服务器每几分钟自动进行，也可在邮箱页面点击“立即收取”。
</p>
HTML;
require __DIR__ . '/../components/layout.php';
