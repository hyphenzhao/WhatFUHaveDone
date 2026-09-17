/**
 * Mail accounts admin page
 */
const MAIL_PRESETS = {
    qq:      { label: 'QQ 邮箱',   imap_host: 'imap.qq.com',            imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.qq.com',         smtp_port: 465, smtp_ssl: 'ssl', hint: '需在 QQ 邮箱设置 → 账户 → 开启 IMAP/SMTP 并生成授权码' },
    '163':   { label: '163 邮箱',  imap_host: 'imap.163.com',           imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.163.com',        smtp_port: 465, smtp_ssl: 'ssl', hint: '需在网易邮箱设置 → POP3/SMTP/IMAP 开启并获取授权码' },
    '126':   { label: '126 邮箱',  imap_host: 'imap.126.com',           imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.126.com',        smtp_port: 465, smtp_ssl: 'ssl', hint: '需开启 IMAP/SMTP 并获取授权码' },
    gmail:   { label: 'Gmail',     imap_host: 'imap.gmail.com',         imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.gmail.com',      smtp_port: 465, smtp_ssl: 'ssl', hint: '需开启两步验证并生成“应用专用密码”' },
    outlook: { label: 'Outlook / Microsoft 365', imap_host: 'outlook.office365.com', imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.office365.com', smtp_port: 587, smtp_ssl: 'tls', hint: '个人账户可用应用密码；组织账户若禁用了 IMAP 基本认证则暂不支持' },
    exmail:  { label: '腾讯企业邮', imap_host: 'imap.exmail.qq.com',     imap_port: 993, imap_ssl: 'ssl', smtp_host: 'smtp.exmail.qq.com',  smtp_port: 465, smtp_ssl: 'ssl', hint: '使用客户端专用密码' },
    sjtu:    { label: '上海交大邮箱', imap_host: 'mail.sjtu.edu.cn',      imap_port: 993, imap_ssl: 'ssl', smtp_host: 'mail.sjtu.edu.cn',    smtp_port: 465, smtp_ssl: 'ssl', hint: '用户名为完整邮箱地址；若开启了客户端专用密码请使用该密码' },
    custom:  { label: '自定义 / 学校邮箱', imap_host: '', imap_port: 993, imap_ssl: 'ssl', smtp_host: '', smtp_port: 465, smtp_ssl: 'ssl', hint: '向邮箱管理员索取 IMAP / SMTP 服务器地址与端口' },
};

let mailAccounts = [];
let mailStatus = null;

document.addEventListener('DOMContentLoaded', loadMailAccounts);

async function loadMailAccounts() {
    const tbody = document.querySelector('#mailAccountsTable tbody');
    try {
        const res = await API.mail.status();
        mailStatus = res.data;
        mailAccounts = mailStatus.accounts || [];
        const notice = document.getElementById('mailAdminNotice');
        notice.innerHTML = mailStatus.imap_ext ? '' :
            `<div class="mail-banner mail-banner-warn">⚠️ ${escapeHtml(mailStatus.imap_hint || 'PHP imap 扩展未安装')}</div>`;
    } catch (e) { tbody.innerHTML = `<tr><td colspan="7">加载失败: ${escapeHtml(e.message)}</td></tr>`; return; }
    if (!mailAccounts.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="color:var(--color-text-secondary);">还没有邮箱账户，点击右上角“添加邮箱”。</td></tr>';
        return;
    }
    tbody.innerHTML = mailAccounts.map(a => `
        <tr>
            <td><b>${escapeHtml(a.name)}</b></td>
            <td>${escapeHtml(a.email)}</td>
            <td class="mono">${escapeHtml(a.imap_host)}:${a.imap_port} <span class="mail-tag">${a.imap_ssl}</span></td>
            <td class="mono">${a.smtp_host ? escapeHtml(a.smtp_host) + ':' + a.smtp_port + ' <span class="mail-tag">' + a.smtp_ssl + '</span>' : '<span style="color:var(--color-text-secondary)">未配置</span>'}</td>
            <td>${a.enabled ? '<span class="mail-tag mail-tag-ok">启用</span>' : '<span class="mail-tag">停用</span>'}
                ${a.running ? '<span class="mail-tag mail-tag-info">同步中</span>' : ''}
                ${a.last_error ? `<div class="mail-err" title="${escapeHtml(a.last_error)}">⚠️ ${escapeHtml(a.last_error.substring(0, 60))}</div>` : ''}</td>
            <td style="white-space:nowrap;">${a.last_sync_at ? escapeHtml(a.last_sync_at) : '—'}</td>
            <td><div class="table-actions">
                <button class="btn btn-outline btn-sm" onclick="showAccountModal(${a.id})">编辑</button>
                <button class="btn btn-outline btn-sm" onclick="testAccount(${a.id})">测试</button>
                <button class="btn btn-outline btn-sm" onclick="syncAccount(${a.id})">同步</button>
                <button class="btn btn-ghost btn-sm" onclick="toggleAccount(${a.id}, ${a.enabled ? 0 : 1})">${a.enabled ? '停用' : '启用'}</button>
                <button class="btn btn-danger btn-sm" onclick="deleteAccount(${a.id})">删除</button>
            </div></td>
        </tr>`).join('');
}

function accountFormHtml(a) {
    a = a || {};
    const presetOpts = Object.entries(MAIL_PRESETS).map(([k, p]) => `<option value="${k}">${p.label}</option>`).join('');
    const sslOpts = (v) => ['ssl', 'tls', 'none'].map(s => `<option value="${s}" ${v === s ? 'selected' : ''}>${s === 'ssl' ? 'SSL/TLS' : s === 'tls' ? 'STARTTLS' : '不加密'}</option>`).join('');
    return `
    <div class="form-group"><label>预设</label>
        <select class="form-select" id="maPreset" onchange="applyPreset()"><option value="">— 选择邮箱类型自动填充 —</option>${presetOpts}</select>
        <div class="mail-hint" id="maPresetHint"></div></div>
    <div class="form-row-2">
        <div class="form-group"><label>显示名称</label><input class="form-input" id="maName" value="${escapeHtml(a.name || '')}" placeholder="如 工作邮箱"></div>
        <div class="form-group"><label>邮箱地址 *</label><input class="form-input" id="maEmail" value="${escapeHtml(a.email || '')}" placeholder="you@example.com" onblur="if(!document.getElementById('maUser').value) document.getElementById('maUser').value=this.value"></div>
    </div>
    <div class="form-row-3">
        <div class="form-group" style="flex:2"><label>IMAP 服务器 *</label><input class="form-input" id="maImapHost" value="${escapeHtml(a.imap_host || '')}" placeholder="imap.example.com"></div>
        <div class="form-group"><label>端口</label><input class="form-input" id="maImapPort" type="number" value="${a.imap_port || 993}"></div>
        <div class="form-group"><label>加密</label><select class="form-select" id="maImapSsl">${sslOpts(a.imap_ssl || 'ssl')}</select></div>
    </div>
    <div class="form-row-3">
        <div class="form-group" style="flex:2"><label>SMTP 服务器（发信）</label><input class="form-input" id="maSmtpHost" value="${escapeHtml(a.smtp_host || '')}" placeholder="smtp.example.com"></div>
        <div class="form-group"><label>端口</label><input class="form-input" id="maSmtpPort" type="number" value="${a.smtp_port || 465}"></div>
        <div class="form-group"><label>加密</label><select class="form-select" id="maSmtpSsl">${sslOpts(a.smtp_ssl || 'ssl')}</select></div>
    </div>
    <div class="form-row-2">
        <div class="form-group"><label>登录用户名</label><input class="form-input" id="maUser" value="${escapeHtml(a.username || '')}" placeholder="默认同邮箱地址"></div>
        <div class="form-group"><label>密码 / 授权码 ${a.id ? '' : '*'}</label><input class="form-input" id="maPass" type="password" autocomplete="new-password" placeholder="${a.id ? '留空则不修改' : '授权码或应用专用密码'}"></div>
    </div>
    <div class="form-row-2">
        <label class="mail-check"><input type="checkbox" id="maValidate" ${a.validate_cert === 0 ? '' : 'checked'}> 校验服务器证书</label>
        <label class="mail-check"><input type="checkbox" id="maEnabled" ${a.enabled === 0 ? '' : 'checked'}> 启用（参与自动收取）</label>
        <label class="mail-check"><input type="checkbox" id="maAllFolders" ${a.sync_all_folders === 0 ? '' : 'checked'}> 同步所有文件夹</label>
    </div>
    <div id="maTestResult" class="mail-test-result" style="display:none;"></div>`;
}

function applyPreset() {
    const k = document.getElementById('maPreset').value;
    const p = MAIL_PRESETS[k];
    document.getElementById('maPresetHint').textContent = p ? p.hint : '';
    if (!p) return;
    for (const [id, key] of [['maImapHost', 'imap_host'], ['maImapPort', 'imap_port'], ['maImapSsl', 'imap_ssl'], ['maSmtpHost', 'smtp_host'], ['maSmtpPort', 'smtp_port'], ['maSmtpSsl', 'smtp_ssl']]) {
        document.getElementById(id).value = p[key];
    }
}

function readAccountForm() {
    return {
        name: document.getElementById('maName').value.trim(),
        email: document.getElementById('maEmail').value.trim(),
        imap_host: document.getElementById('maImapHost').value.trim(),
        imap_port: parseInt(document.getElementById('maImapPort').value, 10) || 993,
        imap_ssl: document.getElementById('maImapSsl').value,
        smtp_host: document.getElementById('maSmtpHost').value.trim(),
        smtp_port: parseInt(document.getElementById('maSmtpPort').value, 10) || 465,
        smtp_ssl: document.getElementById('maSmtpSsl').value,
        username: document.getElementById('maUser').value.trim(),
        password: document.getElementById('maPass').value,
        validate_cert: document.getElementById('maValidate').checked ? 1 : 0,
        enabled: document.getElementById('maEnabled').checked ? 1 : 0,
        sync_all_folders: document.getElementById('maAllFolders').checked ? 1 : 0,
    };
}

function showAccountModal(id) {
    const a = id ? mailAccounts.find(x => x.id === id) : null;
    Modal.open({
        title: a ? '✏️ 编辑邮箱' : '＋ 添加邮箱',
        size: 'wide',
        body: accountFormHtml(a),
        footer: `<button class="btn btn-ghost" onclick="Modal.close()">取消</button>
                 <button class="btn btn-outline" id="maTestBtn">🔌 测试连接</button>
                 <button class="btn btn-primary" id="maSaveBtn">💾 保存</button>`,
    });
    document.getElementById('maTestBtn').addEventListener('click', async () => {
        const btn = document.getElementById('maTestBtn');
        btn.disabled = true; btn.textContent = '⏳ 测试中...';
        const box = document.getElementById('maTestResult');
        box.style.display = 'block'; box.textContent = '正在连接 IMAP / SMTP，最多约 30 秒...';
        try {
            const form = readAccountForm();
            if (a) form.id = a.id;
            const r = await API.mail.accounts.test(form);
            const d = r.data;
            box.innerHTML = `<div>${d.imap_ok ? '✅ IMAP 连接成功，文件夹 ' + d.folders + ' 个' : '❌ IMAP: ' + escapeHtml(d.imap_error)}</div>
                             <div>${d.smtp_ok ? '✅ SMTP 连接成功' : '⚠️ SMTP: ' + escapeHtml(d.smtp_error)}</div>`;
        } catch (e) { box.innerHTML = '❌ ' + escapeHtml(e.message); }
        finally { btn.disabled = false; btn.textContent = '🔌 测试连接'; }
    });
    document.getElementById('maSaveBtn').addEventListener('click', async () => {
        const form = readAccountForm();
        try {
            if (a) await API.mail.accounts.update(a.id, form);
            else await API.mail.accounts.create(form);
            Modal.close();
            Toast.success(a ? '已保存' : '邮箱已添加，正在首次收取...');
            await loadMailAccounts();
            if (!a) {
                const created = mailAccounts.find(x => x.email === form.email);
                if (created) syncAccount(created.id);
            }
        } catch (e) { Toast.error(e.message); }
    });
}

async function testAccount(id) {
    Toast.show('⏳ 正在测试连接...', 'info', 3000);
    try {
        const r = await API.mail.accounts.test({ id });
        const d = r.data;
        Modal.open({ title: '🔌 连接测试', body: `<div>${d.imap_ok ? '✅ IMAP 连接成功，文件夹 ' + d.folders + ' 个' : '❌ IMAP: ' + escapeHtml(d.imap_error)}</div>
            <div style="margin-top:6px;">${d.smtp_ok ? '✅ SMTP 连接成功' : '⚠️ SMTP: ' + escapeHtml(d.smtp_error)}</div>`,
            footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>' });
    } catch (e) { Toast.error(e.message); }
}

async function syncAccount(id) {
    Toast.show('⏳ 正在收取邮件...', 'info', 3000);
    let rounds = 0, totalNew = 0;
    try {
        while (rounds++ < 20) {
            const r = await API.mail.sync(id, 25);
            const res = (r.data.results || [])[0] || {};
            totalNew += (res.new || 0) + (res.backfilled || 0);
            if (res.error) { Toast.error('同步出错: ' + res.error); break; }
            if (res.skipped) { Toast.show('已有同步在进行中', 'info'); break; }
            if (!r.data.more) break;
        }
        Toast.success(`收取完成，新增 ${totalNew} 封`);
    } catch (e) { Toast.error('同步失败: ' + e.message); }
    loadMailAccounts();
}

async function toggleAccount(id, enabled) {
    const a = mailAccounts.find(x => x.id === id);
    if (!a) return;
    try { await API.mail.accounts.update(id, { ...a, enabled, password: '' }); await loadMailAccounts(); }
    catch (e) { Toast.error(e.message); }
}

async function deleteAccount(id) {
    const a = mailAccounts.find(x => x.id === id);
    if (!a) return;
    if (!confirm(`删除邮箱 ${a.email}？本地已同步的邮件、附件和 AI 分析都会一并删除（服务器上的邮件不受影响）。`)) return;
    try { await API.mail.accounts.remove(id); Toast.success('已删除'); await loadMailAccounts(); }
    catch (e) { Toast.error(e.message); }
}
