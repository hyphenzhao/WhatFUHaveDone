<?php
$page_title = 'AI 配置';
$current_page = 'ai-admin';
$page_content = <<<'HTML'
<div class="page-header">
    <h2>🤖 智能助手配置</h2>
    <p style="color:var(--color-text-secondary);margin-top:4px;">配置 AI 提供者和模型参数，用于右侧面板的智能助手</p>
</div>

<div class="ai-admin-panel">
    <div class="form-group">
        <label>AI 提供者</label>
        <select class="form-select" id="aiProvider">
            <option value="ollama">Ollama (本地)</option>
            <option value="deepseek">DeepSeek (云端)</option>
        </select>
    </div>
    <div class="form-group">
        <label>API 地址</label>
        <input type="text" class="form-input" id="aiEndpoint" placeholder="http://localhost:11434/v1">
        <div style="font-size:0.75rem;color:var(--color-text-secondary);margin-top:2px;">
            Ollama 默认: http://localhost:11434/v1 | DeepSeek: https://api.deepseek.com/v1
        </div>
    </div>
    <div class="form-group" id="apiKeyGroup">
        <label>API Key</label>
        <input type="password" class="form-input" id="aiApiKey" placeholder="DeepSeek API Key（Ollama 本地无需填写）">
    </div>
    <div class="form-group">
        <label>模型名称</label>
        <input type="text" class="form-input" id="aiModel" placeholder="qwen2.5:7b">
        <div style="font-size:0.75rem;color:var(--color-text-secondary);margin-top:2px;">
            Ollama 推荐: qwen2.5:7b / llama3.1:8b | DeepSeek: deepseek-chat
        </div>
    </div>
    <div style="display:flex;gap:8px;">
        <button class="btn btn-outline" id="testConnection">🔍 测试连接</button>
        <button class="btn btn-primary" id="saveAiConfig">💾 保存配置</button>
    </div>
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

<script>
document.addEventListener('DOMContentLoaded', async () => {
    // Load current config
    try {
        const res = await API.get('/ai/config');
        if (res.data) {
            document.getElementById('aiProvider').value = res.data.provider || 'ollama';
            document.getElementById('aiEndpoint').value = res.data.endpoint || '';
            document.getElementById('aiModel').value = res.data.model || '';
        }
    } catch (e) { /* use defaults */ }

    // Toggle API key visibility
    document.getElementById('aiProvider').addEventListener('change', () => {
        const isOllama = document.getElementById('aiProvider').value === 'ollama';
        document.getElementById('apiKeyGroup').style.display = isOllama ? 'none' : 'block';
    });
    document.getElementById('aiProvider').dispatchEvent(new Event('change'));

    // Save
    document.getElementById('saveAiConfig').addEventListener('click', async () => {
        try {
            await API.post('/ai/config', {
                provider: document.getElementById('aiProvider').value,
                endpoint: document.getElementById('aiEndpoint').value.trim(),
                api_key: document.getElementById('aiApiKey').value,
                model: document.getElementById('aiModel').value.trim(),
            });
            Toast.success('AI 配置已保存');
        } catch (e) { Toast.error('保存失败: ' + e.message); }
    });

    // Test connection
    document.getElementById('testConnection').addEventListener('click', async () => {
        const btn = document.getElementById('testConnection');
        const result = document.getElementById('testResult');
        btn.disabled = true; btn.textContent = '⏳ 测试中...';
        result.innerHTML = '';

        try {
            const res = await API.post('/ai/config/test', {
                endpoint: document.getElementById('aiEndpoint').value.trim(),
                api_key: document.getElementById('aiApiKey').value,
                model: document.getElementById('aiModel').value.trim(),
            });
            result.innerHTML = `<div class="ai-test-result success">✅ ${res.message}</div>`;
        } catch (e) {
            result.innerHTML = `<div class="ai-test-result error">❌ 连接失败: ${escapeHtml(e.message)}</div>`;
        } finally {
            btn.disabled = false; btn.textContent = '🔍 测试连接';
        }
    });
});
</script>
HTML;

require __DIR__ . '/../components/layout.php';
