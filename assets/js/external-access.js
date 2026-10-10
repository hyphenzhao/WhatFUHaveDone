/* Plaintext keys never enter AiChat or browser storage. */
const ExternalAccess = {
    csrf: '',
    async request(method = 'GET', path = '', data) {
        const response = await fetch('/api/external_keys' + path, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
            body: data === undefined ? undefined : JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.message || '请求失败');
        return result.data;
    },
    async load() {
        const data = await this.request();
        this.csrf = data.csrf;
        const scopes = document.getElementById('externalScopes');
        if (!scopes.querySelector('input')) scopes.innerHTML = Object.entries(data.scopes).map(([key, label]) =>
            `<label><input type="checkbox" name="scope" value="${escapeHtml(key)}" ${['work', 'mail', 'reports', 'profile'].includes(key) ? 'checked' : ''}>${escapeHtml(label)}</label>`).join('');
        document.getElementById('externalCreate').disabled = false;
        document.getElementById('externalKeys').innerHTML = data.keys.length ? data.keys.map(k => {
            const state = k.revoked_at ? '已撤销' : (Number(k.expired) ? '已过期' : '有效');
            const permissions = JSON.parse(k.scopes_json).map(s => data.scopes[s] || s).join('、');
            return `<div class="ea-key"><strong>${escapeHtml(k.name)}</strong> · ${state} · <code>${escapeHtml(k.token_prefix)}…</code>
                <div>${escapeHtml(permissions)}</div><small>到期：${escapeHtml(k.expires_at)}（服务器时间） · 最近使用：${escapeHtml(k.last_used_at || '尚未使用')}</small>
                ${state === '有效' ? `<div><button type="button" class="btn btn-ghost btn-sm" data-revoke="${Number(k.id)}">撤销密钥</button></div>` : ''}</div>`;
        }).join('') : '<p>尚无外部访问密钥。</p>';
    },
    notice(message) { document.getElementById('externalKeyNotice').textContent = message; },
    dismiss() { document.getElementById('externalToken').value = ''; document.getElementById('externalNewKey').hidden = true; },
    async init() {
        document.getElementById('externalMcpConfig').textContent = `[mcp_servers.worklog]\nurl = "${location.origin}/api/external/mcp"\nbearer_token_env_var = "WORKLOG_API_TOKEN"`;
        document.getElementById('externalDismiss').addEventListener('click', () => this.dismiss());
        window.addEventListener('pagehide', () => this.dismiss());
        document.getElementById('externalCopy').addEventListener('click', async () => {
            const input = document.getElementById('externalToken');
            try { await navigator.clipboard.writeText(input.value); this.notice('已复制密钥。'); }
            catch (_) { input.focus(); input.select(); this.notice('已选中密钥，请按 Ctrl+C 复制。'); }
        });
        document.getElementById('externalKeyForm').addEventListener('submit', async e => {
            e.preventDefault();
            if (!document.getElementById('externalNewKey').hidden) { this.notice('请先保存当前密钥并关闭显示。'); return; }
            const button = document.getElementById('externalCreate'); button.disabled = true;
            this.notice('');
            try {
                const result = await this.request('POST', '', {
                    name: document.getElementById('externalKeyName').value,
                    days: Number(document.getElementById('externalKeyDays').value),
                    scopes: [...document.querySelectorAll('#externalScopes input:checked')].map(i => i.value)
                });
                document.getElementById('externalToken').value = result.token;
                document.getElementById('externalNewKey').hidden = false;
                await this.load();
            } catch (err) { this.notice(err.message); }
            finally { button.disabled = false; }
        });
        document.getElementById('externalKeys').addEventListener('click', async e => {
            const button = e.target.closest('[data-revoke]'); if (!button) return;
            if (!confirm('撤销后，使用此密钥的助手将无法继续读取资料。确定撤销？')) return;
            button.disabled = true;
            try { await this.request('DELETE', '/' + button.dataset.revoke); this.notice('密钥已撤销。'); await this.load(); }
            catch (err) { this.notice(err.message); button.disabled = false; }
        });
        try { await this.load(); } catch (err) { this.notice(err.message); }
    }
};
document.addEventListener('DOMContentLoaded', () => ExternalAccess.init());
