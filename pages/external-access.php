<?php
$page_title = '外部 AI 接入';
$current_page = 'external-access';
$page_content = <<<'HTML'
<div class="page-header"><h2>🔌 外部 AI 接入</h2></div>
<div class="external-access">
    <p>让 Codex 等助手直接读取你的科研记录。只需三个工具：工作概览、统一检索、批量读取详情。先读短摘要，再按需展开正文和附件，复用系统已有的 AI 分析。</p>
    <section class="ea-card">
        <h3>创建只读密钥</h3>
        <p>每个密钥只访问你授权的资料，不可修改任务、发送邮件或操作系统设置。</p>
        <form id="externalKeyForm">
            <div class="ea-fields">
                <label>名称<input id="externalKeyName" class="form-input" required maxlength="100" value="Codex" autocomplete="off"></label>
                <label>有效期<select id="externalKeyDays" class="form-input"><option value="7">7 天</option><option value="30">30 天</option><option value="90" selected>90 天</option><option value="365">365 天</option></select></label>
            </div>
            <fieldset class="ea-scopes"><legend>允许读取的资料</legend><div id="externalScopes">加载中…</div></fieldset>
            <button class="btn btn-primary" id="externalCreate" type="submit" disabled>创建密钥</button>
        </form>
        <div id="externalNewKey" hidden role="status">
            <p><strong>密钥只显示这一次。</strong>保存到 Codex 使用的环境变量中，勿粘贴到 AI 对话。</p>
            <label>新密钥<input id="externalToken" class="form-input" readonly autocomplete="off" spellcheck="false"></label>
            <div class="ea-actions"><button class="btn btn-primary btn-sm" id="externalCopy" type="button">复制密钥</button><button class="btn btn-ghost btn-sm" id="externalDismiss" type="button">已保存，关闭显示</button></div>
        </div>
        <p id="externalKeyNotice" role="status"></p>
    </section>
    <section class="ea-card">
        <h3>连接 Codex</h3>
        <ol><li>将密钥存入运行 Codex 的环境变量 <code>WORKLOG_API_TOKEN</code>。</li><li>在 Codex 的 MCP 配置中加入下方内容，重新连接 MCP。</li><li>告诉助手：“读取 WorkLog，帮我分析今天的工作。”</li></ol>
        <pre id="externalMcpConfig"></pre>
        <p>Codex 所在机器需要能访问此网站；跨公网使用时请通过 HTTPS 或 VPN 连接。密钥不会写入上面的配置。</p>
        <p><a href="https://learn.chatgpt.com/docs/extend/mcp?surface=cli" target="_blank" rel="noopener noreferrer">Codex MCP 官方配置说明</a> · <a href="/docs/external-api.md" target="_blank" rel="noopener">接口与接入文档</a></p>
    </section>
    <section class="ea-card"><h3>已有密钥</h3><div id="externalKeys">加载中…</div></section>
</div>
<style>
.external-access { max-width: 1000px; line-height: 1.65; }
.ea-card { padding: 20px; margin: 18px 0; border: 1px solid var(--color-border, #ddd); border-radius: 12px; background: var(--color-surface, #fff); }
.ea-card h3 { margin: 0 0 8px; }
.ea-card p { margin: 10px 0; }
.ea-fields { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
.ea-fields label, #externalNewKey label { display: block; }
.ea-fields .form-input, #externalToken { width: 100%; }
.ea-scopes { border: 0; padding: 12px 0; }
.ea-scopes label { display: block; padding: 5px 0; cursor: pointer; }
.ea-scopes input { margin-right: 8px; }
.ea-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
#externalNewKey { margin-top: 18px; padding: 14px; border: 1px solid var(--color-primary, #3b82f6); border-radius: 8px; }
#externalMcpConfig { padding: 14px; background: var(--color-bg, #f5f7fa); white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.85rem; }
.ea-key { border-top: 1px solid var(--color-border, #ddd); padding: 12px 0; overflow-wrap: anywhere; }
.ea-key small { color: var(--color-text-secondary, #666); }
@media (max-width: 560px) { .ea-fields { grid-template-columns: 1fr; } .ea-card { padding: 14px; } }
</style>
HTML;
require __DIR__ . '/../components/layout.php';
