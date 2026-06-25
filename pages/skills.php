<?php
$page_title = '技能管理';
$current_page = 'skills';
$page_content = <<<'HTML'
<div class="page-header"><h2>🛠️ 技能管理</h2><p style="color:var(--color-text-secondary);margin-top:4px;">管理 AI 智能体可用的知识技能，从 skills/ 目录导入</p></div>

<div style="margin-bottom:16px;">
    <button class="btn btn-primary" id="btnRefresh" onclick="refreshSkills()">🔄 从磁盘重新加载</button>
    <span id="refreshStatus" style="margin-left:12px;font-size:0.85rem;color:var(--color-text-secondary);"></span>
</div>

<table class="data-table" id="skillsTable">
    <thead><tr><th>技能名称</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>
    <tbody id="skillsBody"></tbody>
</table>

<style>
.data-table { width:100%; border-collapse:collapse; background:var(--color-surface); border-radius:var(--radius-lg); overflow:hidden; border:1px solid var(--color-border); }
.data-table th { background:var(--color-bg); padding:10px 12px; text-align:left; font-size:0.82rem; font-weight:700; border-bottom:2px solid var(--color-border); }
.data-table td { padding:10px 12px; border-bottom:1px solid var(--color-border); font-size:0.85rem; }
.data-table tr:last-child td { border-bottom:none; }
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:600; }
.badge-on { background:#dcfce7; color:#166534; }
.badge-off { background:#fef2f2; color:#991b1b; }
</style>

<script>
async function loadSkills() {
    try {
        const res = await API.get('/skills');
        const skills = res.data || [];
        document.getElementById('skillsBody').innerHTML = skills.map(s => `
            <tr>
                <td><strong>${escapeHtml(s.name)}</strong></td>
                <td style="font-size:0.82rem;color:var(--color-text-secondary);">${escapeHtml((s.description||'').substring(0,80))}${s.description&&s.description.length>80?'...':''}</td>
                <td><span class="badge ${s.enabled?'badge-on':'badge-off'}">${s.enabled?'已启用':'已禁用'}</span></td>
                <td>
                    <button class="btn btn-ghost btn-sm" onclick="toggleSkill(${s.id},${s.enabled?0:1})">${s.enabled?'禁用':'启用'}</button>
                    <button class="btn btn-ghost btn-sm" onclick="previewSkill(${s.id})">预览</button>
                </td>
            </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--color-text-secondary);">暂无技能，请点击"从磁盘重新加载"</td></tr>';
    } catch(e) { Toast.error('加载失败: '+e.message); }
}

async function refreshSkills() {
    const btn = document.getElementById('btnRefresh');
    const status = document.getElementById('refreshStatus');
    btn.disabled = true; btn.textContent = '⏳ 扫描中...';
    try {
        const res = await API.get('/skills/refresh');
        status.textContent = `✅ 已导入 ${res.data.imported} 个技能`;
        await loadSkills();
    } catch(e) { status.textContent = '❌ 失败: ' + e.message; }
    finally { btn.disabled = false; btn.textContent = '🔄 从磁盘重新加载'; }
}

async function toggleSkill(id, enabled) {
    try {
        await API.put('/skills/' + id, { enabled });
        Toast.success(enabled ? '已启用' : '已禁用');
        await loadSkills();
    } catch(e) { Toast.error('操作失败'); }
}

async function previewSkill(id) {
    try {
        const res = await API.get('/skills/' + id);
        const s = res.data;
        Modal.open({
            title: '📖 ' + escapeHtml(s.name),
            body: `<div style="font-size:0.78rem;color:var(--color-text-secondary);margin-bottom:8px;">${escapeHtml(s.description||'')}</div>
                <div style="max-height:500px;overflow-y:auto;white-space:pre-wrap;font-size:0.82rem;line-height:1.6;background:var(--color-bg);padding:12px;border-radius:var(--radius-sm);">${escapeHtml(s.content||'')}</div>`,
        });
    } catch(e) { Toast.error('加载失败'); }
}

document.addEventListener('DOMContentLoaded', loadSkills);
</script>
HTML;

require __DIR__ . '/../components/layout.php';
