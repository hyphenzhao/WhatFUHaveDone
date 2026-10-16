<?php
$page_title = 'AI 配置';
$current_page = 'ai-admin';
$page_content = <<<'HTML'
<div class="page-header">
    <h2>🤖 智能助手配置</h2>
    <p style="color:var(--color-text-secondary);margin-top:4px;">统一用于系统对话、邮件分析、个人侧写和周期报告。</p>
</div>

<div class="ai-admin-panel">
    <div class="form-group">
        <label>AI 提供者</label>
        <select class="form-select" id="aiProvider">
            <option value="ollama">Ollama (本地)</option>
            <option value="deepseek">DeepSeek (云端)</option>
            <option value="openai">OpenAI</option>
            <option value="anthropic">Anthropic（Claude）</option>
            <option value="openai_compatible">OpenAI 兼容接口（自定义）</option>
        </select>
        <div id="aiProviderHint" style="font-size:0.8rem;color:var(--color-text-secondary);margin-top:4px;"></div>
    </div>
    <div class="form-group">
        <label>API 地址</label>
        <input type="text" class="form-input" id="aiEndpoint" placeholder="http://localhost:11434/v1">
        <div style="font-size:0.75rem;color:var(--color-text-secondary);margin-top:2px;">
            可填基础地址（如 …/v1）或完整请求地址，也可使用服务商提供的代理地址。
        </div>
    </div>
    <div class="form-group" id="apiKeyGroup">
        <label>API Key</label>
        <input type="password" class="form-input" id="aiApiKey" autocomplete="new-password" placeholder="读取配置中…">
        <div style="font-size:0.8rem;color:var(--color-text-secondary);margin-top:4px;">同一提供者、同一地址留空保留已保存的密钥；切换提供者或地址后请重新填写。</div>
    </div>
    <div class="form-group">
        <label>模型名称</label>
        <input type="text" class="form-input" id="aiModel" placeholder="填写账户可用的模型 ID">
    </div>
    <div style="display:flex;gap:8px;">
        <button class="btn btn-outline" id="testConnection" disabled>🔍 测试连接</button>
        <button class="btn btn-primary" id="saveAiConfig" disabled>💾 保存配置</button>
    </div>
    <p style="font-size:0.8rem;color:var(--color-text-secondary);margin-top:10px;">测试不会更换当前使用的 AI，点击“保存配置”后才生效。</p>
    <div id="testResult"></div>
</div>

<style>
.ai-admin-panel {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 24px;
    max-width: 600px;
}
.ai-admin-panel .form-group { margin-bottom: 16px; }
.ai-test-result {
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    margin-top: 12px;
    font-size: 0.85rem;
}
.ai-test-result.success { background: #dcfce7; color: #166534; }
.ai-test-result.error { background: #fef2f2; color: #991b1b; }
</style>

HTML;

require __DIR__ . '/../components/layout.php';
