/** Draft settings never affect the active provider until the user saves. */
const AIAdmin = {
    presets: {}, saved: null, current: '', drafts: new Map(), busy: false,
    fields() {
        return {provider: document.getElementById('aiProvider').value,
            endpoint: document.getElementById('aiEndpoint').value.trim(),
            model: document.getElementById('aiModel').value.trim(),
            api_key: document.getElementById('aiApiKey').value};
    },
    setBusy(busy) {
        this.busy = busy;
        ['saveAiConfig','testConnection','aiProvider','aiEndpoint','aiModel','aiApiKey'].forEach(id => document.getElementById(id).disabled = busy);
    },
    updateHints() {
        const data = this.fields(), preset = this.presets[data.provider] || {};
        document.getElementById('aiProviderHint').textContent = preset.hint || '';
        document.getElementById('aiEndpoint').placeholder = preset.endpoint || '服务商提供的 API 基础地址';
        document.getElementById('aiModel').placeholder = preset.model || '填写账户可用的模型 ID';
        const same = this.saved && this.saved.provider === data.provider && this.saved.endpoint.replace(/\/+$/, '') === data.endpoint.replace(/\/+$/, '');
        document.getElementById('aiApiKey').placeholder = same && this.saved.has_api_key ? '已保存密钥，留空保留' : data.provider === 'ollama' ? '本地通常无需填写；代理有要求时可填写' : '填写此提供者的 API Key';
    },
    switchProvider() {
        const values = this.fields();
        this.drafts.set(this.current, {...values, provider: this.current});
        this.current = values.provider;
        const preset = this.presets[this.current] || {};
        const draft = this.drafts.get(this.current) || {endpoint: preset.endpoint || '', model: preset.model || '', api_key: ''};
        document.getElementById('aiEndpoint').value = draft.endpoint;
        document.getElementById('aiModel').value = draft.model;
        document.getElementById('aiApiKey').value = draft.api_key;
        document.getElementById('testResult').textContent = '';
        this.updateHints();
    },
    async init() {
        this.setBusy(true);
        try {
            const data = (await API.get('/ai/config')).data;
            this.presets = data.providers;
            this.saved = {provider:data.provider,endpoint:data.endpoint,model:data.model,has_api_key:data.has_api_key};
            this.current = data.provider;
            const select = document.getElementById('aiProvider');
            select.replaceChildren(...Object.entries(this.presets).map(([value,preset]) => new Option(preset.label, value)));
            select.value = data.provider;
            document.getElementById('aiEndpoint').value = data.endpoint;
            document.getElementById('aiModel').value = data.model;
            this.updateHints();
            select.addEventListener('change', () => this.switchProvider());
            document.getElementById('aiEndpoint').addEventListener('input', () => this.updateHints());
            document.getElementById('saveAiConfig').addEventListener('click', () => this.save());
            document.getElementById('testConnection').addEventListener('click', () => this.test());
            this.setBusy(false);
        } catch (e) { Toast.error('读取 AI 配置失败，请刷新后重试：'+e.message); }
    },
    async save() {
        if (this.busy) return;
        this.setBusy(true);
        try {
            const data = (await API.post('/ai/config', this.fields())).data;
            this.saved = data;
            document.getElementById('aiApiKey').value = '';
            this.drafts.set(data.provider, {...data, api_key:''});
            this.updateHints();
            Toast.success('AI 配置已保存，系统各处的助手将使用此配置');
        } catch (e) { Toast.error('保存失败：'+e.message); }
        finally { this.setBusy(false); }
    },
    async test() {
        if (this.busy) return;
        this.setBusy(true);
        const button = document.getElementById('testConnection'), result = document.getElementById('testResult');
        button.textContent = '⏳ 测试中…'; result.textContent = '';
        try {
            const res = await API.post('/ai/config/test', this.fields());
            result.innerHTML = `<div class="ai-test-result success">✅ ${escapeHtml(res.message)}</div>`;
        } catch (e) { result.innerHTML = `<div class="ai-test-result error">❌ ${escapeHtml(e.message)}</div>`; }
        finally { this.setBusy(false); button.textContent = '🔍 测试连接'; }
    }
};
document.addEventListener('DOMContentLoaded', () => AIAdmin.init());
