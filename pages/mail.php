<?php
$page_title = '邮箱';
$current_page = 'mail';
$page_content = <<<'HTML'
<div id="mailBanner"></div>
<div class="mail-layout" id="mailLayout">
    <aside class="mail-nav">
        <div class="mail-nav-actions">
            <button class="btn btn-primary btn-sm" onclick="Mail.compose('new')">✏️ 写邮件</button>
            <button class="btn btn-outline btn-sm" id="mailSyncNow" onclick="Mail.syncNow()">🔄 立即收取</button>
            <a class="btn btn-ghost btn-sm" href="/mail-admin">➕ 添加邮箱</a>
        </div>
        <div class="mail-folder-tree" id="mailFolderTree"><div class="mail-muted">加载中...</div></div>
        <div class="mail-sync-status" id="mailSyncStatus"></div>
    </aside>
    <section class="mail-list-pane">
        <div class="mail-list-toolbar">
            <input class="form-input mail-search" id="mailSearch" placeholder="搜索主题 / 发件人…">
            <button class="mail-filter-btn" data-filter="unread" title="仅未读">未读</button>
            <button class="mail-filter-btn" data-filter="flagged" title="仅星标">⭐</button>
            <button class="mail-filter-btn" data-filter="highlighted" title="仅高亮（与助手共享的标记）">🔆</button>
            <button class="btn btn-ghost btn-sm" id="mailAnalyzePage" onclick="Mail.analyzeVisible()" title="AI 分析当前列表中尚未分析的邮件">🤖 分析本页</button>
        </div>
        <div class="mail-list" id="mailList"><div class="mail-muted" style="padding:16px;">选择左侧文件夹</div></div>
    </section>
    <section class="mail-read-pane" id="mailReadPane">
        <div class="mail-read-empty">选择一封邮件查看内容</div>
    </section>
    <aside class="mail-ai-drawer" id="mailAiDrawer">
        <div class="mail-ai-drawer-head">
            <span>🤖 智能助手</span>
            <button class="btn btn-ghost btn-sm" id="mailAiToggle" onclick="Mail.toggleDrawer()" title="折叠/展开">▶</button>
        </div>
        <div class="mail-ai-body" id="mailAiBody"></div>
    </aside>
</div>
HTML;
require __DIR__ . '/../components/layout.php';
