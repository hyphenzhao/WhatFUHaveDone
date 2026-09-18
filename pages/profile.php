<?php
$page_title = '个人侧写';
$current_page = 'profile';
$page_content = <<<'HTML'
<div class="page-header"><h2>👤 个人侧写</h2><p style="color:var(--color-text-secondary);margin-top:4px;">配置你的基本信息和命盘文件，AI 将据此提供个性化分析</p></div>

<div class="profile-layout">
<!-- 左栏：基本信息 -->
<div class="profile-panel">
    <h3>📋 基本信息</h3>
    <div class="form-group"><label>姓名</label><input class="form-input" id="pfName" placeholder="你的姓名"></div>
    <div class="form-row-2">
        <div class="form-group"><label>性别</label><select class="form-select" id="pfGender"><option value="">--</option><option value="男">男</option><option value="女">女</option></select></div>
        <div class="form-group"><label>生肖</label><input class="form-input" id="pfShengXiao" placeholder="如: 马"></div>
    </div>
    <div class="form-row-2">
        <div class="form-group"><label>阳历生日</label><input type="date" class="form-input" id="pfBirthDate"></div>
        <div class="form-group"><label>时辰</label><select class="form-select" id="pfBirthTime">
            <option value="">未知</option>
            <option value="0">子时 (23-01)</option><option value="1">丑时 (01-03)</option><option value="2">寅时 (03-05)</option><option value="3">卯时 (05-07)</option><option value="4">辰时 (07-09)</option><option value="5">巳时 (09-11)</option><option value="6">午时 (11-13)</option><option value="7">未时 (13-15)</option><option value="8">申时 (15-17)</option><option value="9">酉时 (17-19)</option><option value="10">戌时 (19-21)</option><option value="11">亥时 (21-23)</option>
        </select></div>
    </div>
    <div class="form-group"><label>出生地</label><input class="form-input" id="pfBirthPlace" placeholder="如: 北京"></div>

    <h3 style="margin-top:16px;">📄 身份文档 <span class="pf-hint">权威来源 · AI 首先依据这里的文件了解你</span></h3>
    <div class="doc-list" id="docList"><div class="pf-empty">尚未上传。建议上传最新简历（PDF/DOCX），并保持更新。</div></div>
    <div class="doc-actions">
        <input type="file" accept=".pdf,.docx,.txt,.md" class="file-input" id="docFileInput" onchange="uploadDoc(this)">
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('docFileInput').click()">📤 上传文档</button>
        <span class="pf-hint">PDF / DOCX / TXT / MD，≤20MB。⭐ 标记主文档。</span>
    </div>

    <h3 style="margin-top:16px;">📝 个人背景</h3>
    <div class="form-group">
        <label>个人简介（补充说明，非权威） <button class="btn btn-ghost btn-sm" id="btnAiExtract" onclick="aiExtractResume()" style="margin-left:8px;" title="从主文档生成一段简介">🤖 从主文档提取</button></label>
        <textarea class="form-textarea" id="pfResume" rows="5" placeholder="教育背景、工作经历、研究方向、兴趣爱好等（上传主文档后可点“从主文档提取”自动生成）"></textarea>
    </div>
    <div class="form-group"><label>当前阶段目标</label><textarea class="form-textarea" id="pfGoals" rows="4" placeholder="近期目标、中期规划、远期愿景等"></textarea></div>
</div>

<!-- 右栏：命盘文件 -->
<div class="profile-panel">
    <h3>🎂 生辰八字</h3>
    <div class="bazi-row">
        <div class="bazi-col"><label>年柱</label><input class="form-input bazi-input" id="pfYear" placeholder="丙午" maxlength="2"></div>
        <div class="bazi-col"><label>月柱</label><input class="form-input bazi-input" id="pfMonth" placeholder="甲午" maxlength="2"></div>
        <div class="bazi-col"><label>日柱</label><input class="form-input bazi-input" id="pfDay" placeholder="戊辰" maxlength="2"></div>
        <div class="bazi-col"><label>时柱</label><input class="form-input bazi-input" id="pfTime" placeholder="壬子" maxlength="2"></div>
    </div>

    <h3 style="margin-top:16px;">🔮 八字命盘</h3>
    <div class="file-card" id="baziCard">
        <div class="file-placeholder" id="baziPlaceholder">📄 上传八字命盘文件 (.txt)</div>
        <div class="file-info" id="baziInfo" style="display:none">
            <span class="file-name" id="baziFileName"></span>
            <span class="file-size" id="baziFileSize"></span>
            <button class="btn btn-ghost btn-sm" onclick="deleteFile('bazi')">🗑️ 删除</button>
        </div>
        <input type="file" accept=".txt,.md,.json" class="file-input" id="baziFileInput" onchange="uploadFile('bazi', this)">
        <button class="btn btn-ghost btn-sm upload-btn" onclick="document.getElementById('baziFileInput').click()">📤 上传</button>
    </div>
    <div class="file-preview" id="baziPreview" style="display:none"></div>

    <h3 style="margin-top:16px;">🌟 紫微命盘</h3>
    <div class="file-card" id="ziweiCard">
        <div class="file-placeholder" id="ziweiPlaceholder">📄 上传紫微命盘文件 (.txt)</div>
        <div class="file-info" id="ziweiInfo" style="display:none">
            <span class="file-name" id="ziweiFileName"></span>
            <span class="file-size" id="ziweiFileSize"></span>
            <button class="btn btn-ghost btn-sm" onclick="deleteFile('ziwei')">🗑️ 删除</button>
        </div>
        <input type="file" accept=".txt,.md,.json" class="file-input" id="ziweiFileInput" onchange="uploadFile('ziwei', this)">
        <button class="btn btn-ghost btn-sm upload-btn" onclick="document.getElementById('ziweiFileInput').click()">📤 上传</button>
    </div>
    <div class="file-preview" id="ziweiPreview" style="display:none"></div>
</div>
</div>

<!-- AI 印象总结（账本式：基础 → 阶段性压缩 → 增量） -->
<div class="profile-panel" style="max-width:1160px;margin-top:20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h3 style="margin:0;">🧾 AI 印象总结 <span class="pf-hint">账本式：基础印象 → 定期压缩为阶段性印象 → 平时追加增量印象；对话中 AI 参考的是最新一份</span></h3>
        <span style="flex:1"></span>
        <button class="btn btn-ghost btn-sm" onclick="toggleSnapshotHistory()">🕘 历史</button>
    </div>
    <div class="snap-grid" id="snapGrid" style="margin-top:12px;"><div class="pf-empty">加载中...</div></div>
    <div id="snapHistory" style="display:none;margin-top:12px;"></div>
</div>

<!-- AI 印象（次级来源） -->
<div class="profile-panel" style="max-width:1160px;margin-top:20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h3 style="margin:0;">🧠 AI 印象记录 <span class="pf-hint">次级来源 · AI 在对话中自动记录，与身份文档冲突时以文档为准</span></h3>
        <span style="flex:1"></span>
        <button class="btn btn-outline btn-sm" onclick="addImpression()">＋ 手动添加</button>
        <button class="btn btn-ghost btn-sm" onclick="clearImpressions()">🗑️ 清空</button>
    </div>
    <div id="impFields" style="margin-top:12px;"></div>
    <h4 style="font-size:0.85rem;margin:14px 0 6px;color:var(--color-text-secondary);">观察记录</h4>
    <div id="impObservations"></div>
</div>

<div style="max-width:1160px;margin-top:16px;text-align:right;">
    <button class="btn btn-primary" id="pfSave" style="padding:10px 32px;font-size:0.95rem;">💾 保存侧写</button>
</div>

<style>
.profile-layout { display: flex; gap: 20px; align-items: flex-start; max-width: 1160px; }
.profile-panel { flex: 1; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 20px; min-width: 0; }
.profile-panel h3 { font-size: 0.95rem; font-weight: 700; margin-bottom: 12px; }
.form-row-2 { display: flex; gap: 12px; }
.form-row-2 .form-group { flex: 1; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: var(--color-text-secondary); }
.file-card { display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--color-bg); border: 2px dashed var(--color-border); border-radius: var(--radius); margin-bottom: 8px; transition: border-color var(--transition); }
.file-card:hover { border-color: var(--color-primary); }
.file-card.has-file { border-style: solid; border-color: var(--color-border); background: #f0fdf4; }
.file-placeholder { flex: 1; font-size: 0.82rem; color: var(--color-text-secondary); }
.file-info { flex: 1; display: none; align-items: center; gap: 8px; }
.file-card.has-file .file-placeholder { display: none; }
.file-card.has-file .file-info { display: flex; }
.file-name { font-weight: 600; font-size: 0.85rem; }
.file-size { font-size: 0.75rem; color: var(--color-text-secondary); }
.file-input { display: none; }
.bazi-row { display: flex; gap: 8px; }
.bazi-col { flex: 1; text-align: center; }
.bazi-col label { display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 4px; color: var(--color-text-secondary); }
.bazi-input { font-size: 1.15rem; font-weight: 700; text-align: center; letter-spacing: 2px; width: 100%; box-sizing: border-box; }
.upload-btn { flex-shrink: 0; }
.file-preview { max-height: 200px; overflow-y: auto; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 8px 12px; font-size: 0.78rem; font-family: monospace; white-space: pre-wrap; line-height: 1.5; margin-bottom: 20px; }
.pf-hint { font-weight: 400; font-size: 0.75rem; color: var(--color-text-secondary); margin-left: 6px; }
.pf-empty { font-size: 0.82rem; color: var(--color-text-secondary); padding: 10px 12px; background: var(--color-bg); border: 1px dashed var(--color-border); border-radius: var(--radius); }
.doc-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
.doc-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius); font-size: 0.82rem; }
.doc-item.primary { border-color: var(--color-primary); background: #eff6ff; }
.doc-item .doc-title { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.doc-item .doc-meta { color: var(--color-text-secondary); font-size: 0.74rem; white-space: nowrap; }
.doc-item .doc-btn { background: none; border: none; cursor: pointer; font-size: 0.9rem; padding: 2px 4px; }
.doc-item .doc-btn:hover { opacity: 0.7; }
.doc-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.imp-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.imp-table th, .imp-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.imp-table th { font-size: 0.75rem; color: var(--color-text-secondary); font-weight: 600; }
.imp-table td.imp-val { white-space: pre-wrap; }
.imp-src { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 0.7rem; background: #ede9fe; color: #5b21b6; }
.imp-src.user { background: #dcfce7; color: #166534; }
.imp-obs { display: flex; gap: 8px; align-items: flex-start; padding: 6px 8px; border-bottom: 1px solid var(--color-border); font-size: 0.82rem; }
.imp-obs .imp-obs-text { flex: 1; white-space: pre-wrap; }
.imp-obs .doc-meta { color: var(--color-text-secondary); font-size: 0.72rem; white-space: nowrap; }
.snap-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.snap-card { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 12px; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.snap-card.base { border-top: 3px solid #2563eb; }
.snap-card.stage { border-top: 3px solid #7c3aed; }
.snap-card.incremental { border-top: 3px solid #059669; }
.snap-head { display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.9rem; }
.snap-head .doc-meta { margin-left: auto; }
.snap-desc { font-size: 0.74rem; color: var(--color-text-secondary); line-height: 1.5; }
.snap-preview { font-size: 0.8rem; line-height: 1.55; max-height: 180px; overflow: hidden; position: relative; cursor: pointer; }
.snap-preview::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 40px; background: linear-gradient(transparent, var(--color-bg)); }
.snap-preview h1, .snap-preview h2, .snap-preview h3, .snap-preview h4 { font-size: 0.82rem; margin: 6px 0 2px; }
.snap-preview ul { margin: 2px 0 2px 16px; }
.snap-pending { font-size: 0.72rem; color: var(--color-text-secondary); }
.snap-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.snap-history-item { display: flex; gap: 8px; align-items: center; padding: 6px 8px; border-bottom: 1px solid var(--color-border); font-size: 0.8rem; }
.snap-kind { display: inline-block; padding: 1px 7px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; }
.snap-kind.base { background: #dbeafe; color: #1e40af; } .snap-kind.stage { background: #ede9fe; color: #5b21b6; } .snap-kind.incremental { background: #d1fae5; color: #065f46; }
.snap-full { font-size: 0.86rem; line-height: 1.65; max-height: 65vh; overflow-y: auto; }
@media (max-width: 900px) { .snap-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) { .profile-layout { flex-direction: column; } }
</style>

<script>
// State
let baziContent = '', baziFileName = '', ziweiContent = '', ziweiFileName = '', resumeContent = '', resumeFileName = '';

async function uploadFile(type, input) {
    const file = input.files[0];
    if (!file) return;
    const form = new FormData(); form.append('file', file);
    try {
        const res = await fetch('/api/upload', { method: 'POST', body: form });
        const data = await res.json();
        if (data.error) { Toast.error(data.message); return; }
        const d = data.data;
        if (type === 'bazi') { baziContent = d.content; baziFileName = d.name; showFile('bazi', d.name, d.size, d.content); }
        else if (type === 'ziwei') { ziweiContent = d.content; ziweiFileName = d.name; showFile('ziwei', d.name, d.size, d.content); }
    } catch(e) { Toast.error('上传失败: ' + e.message); }
    input.value = '';
}

// ===== 身份文档（权威来源） =====
let profileDocs = [];
async function loadDocs() {
    const box = document.getElementById('docList');
    try {
        const res = await API.profileDocs.list();
        profileDocs = res.data || [];
    } catch(e) { box.innerHTML = '<div class="pf-empty">加载失败: ' + escapeHtml(e.message) + '</div>'; return; }
    if (!profileDocs.length) { box.innerHTML = '<div class="pf-empty">尚未上传。建议上传最新简历（PDF/DOCX），并保持更新。</div>'; return; }
    box.innerHTML = profileDocs.map(d => `
        <div class="doc-item ${d.is_primary ? 'primary' : ''}">
            <button class="doc-btn" title="${d.is_primary ? '主文档' : '设为主文档'}" onclick="setPrimaryDoc(${d.id})">${d.is_primary ? '⭐' : '☆'}</button>
            <span class="doc-title" title="${escapeHtml(d.file_name)}">${escapeHtml(d.title || d.file_name)}</span>
            <span class="doc-meta">${escapeHtml(d.file_name)} · ${formatSize(d.size)} · ${d.chars ? d.chars + ' 字' : '⚠️ 无文本'} · ${(d.updated_at || '').substring(0,10)}</span>
            <button class="doc-btn" title="预览文本" onclick="previewDoc(${d.id})">👁️</button>
            <a class="doc-btn" title="下载" href="${API.profileDocs.downloadUrl(d.id)}">⬇️</a>
            <button class="doc-btn" title="重新提取文本" onclick="reextractDoc(${d.id})">🔄</button>
            <button class="doc-btn" title="删除" onclick="deleteDoc(${d.id})">🗑️</button>
        </div>`).join('');
}
async function uploadDoc(input) {
    const file = input.files[0];
    if (!file) return;
    Toast.show('⏳ 上传并提取文本中...', 'info', 4000);
    try {
        const res = await API.profileDocs.upload(file, 'cv', '');
        Toast.success(res.message || '已上传');
        await loadDocs();
    } catch(e) { Toast.error('上传失败: ' + e.message); }
    input.value = '';
}
async function setPrimaryDoc(id) {
    try { await API.profileDocs.update(id, { is_primary: 1 }); await loadDocs(); Toast.success('已设为主文档'); }
    catch(e) { Toast.error(e.message); }
}
async function reextractDoc(id) {
    try { const r = await API.profileDocs.reextract(id); Toast.success(r.message); await loadDocs(); }
    catch(e) { Toast.error(e.message); }
}
async function deleteDoc(id) {
    if (!confirm('确定删除该文档？')) return;
    try { await API.profileDocs.remove(id); await loadDocs(); Toast.success('已删除'); }
    catch(e) { Toast.error(e.message); }
}
async function previewDoc(id) {
    try {
        const r = await API.profileDocs.text(id);
        const t = r.data.text || '(未提取到文本)';
        Modal.open({ title: '📄 ' + (r.data.title || r.data.file_name),
            body: `<pre style="white-space:pre-wrap;font-size:0.8rem;max-height:60vh;overflow:auto;margin:0;">${escapeHtml(t)}</pre>`,
            footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>' });
    } catch(e) { Toast.error(e.message); }
}

// ===== AI 印象（次级来源） =====
let impLabels = {};
async function loadImpressions() {
    const fEl = document.getElementById('impFields');
    const oEl = document.getElementById('impObservations');
    try {
        const res = await API.impressions.list();
        const d = res.data || {};
        impLabels = d.labels || {};
        const fields = d.fields || {};
        const keys = Object.keys(impLabels);
        const rows = keys.filter(k => fields[k]).map(k => {
            const r = fields[k];
            return `<tr><td style="white-space:nowrap;font-weight:600;">${escapeHtml(impLabels[k])}</td>
                <td class="imp-val">${escapeHtml(r.value)}</td>
                <td><span class="imp-src ${r.source}">${r.source === 'user' ? '手动' : 'AI'}</span> ${r.confidence}%</td>
                <td class="doc-meta">${(r.updated_at || '').substring(0,10)}</td>
                <td style="white-space:nowrap;"><button class="doc-btn" onclick="editImpression(${r.id}, '${k}')">✏️</button><button class="doc-btn" onclick="deleteImpression(${r.id})">🗑️</button></td></tr>`;
        });
        fEl.innerHTML = rows.length
            ? `<table class="imp-table"><thead><tr><th>字段</th><th>内容</th><th>来源/置信</th><th>更新</th><th></th></tr></thead><tbody>${rows.join('')}</tbody></table>`
            : '<div class="pf-empty">还没有结构化印象。与 AI 聊聊你的职称、职位、工作重心，它会自动记录。</div>';
        const obs = d.observations || [];
        oEl.innerHTML = obs.length ? obs.map(r => `
            <div class="imp-obs"><span class="imp-obs-text">${escapeHtml(r.value)}</span>
                <span class="doc-meta">${(r.updated_at || '').substring(0,10)}</span>
                <button class="doc-btn" onclick="editImpression(${r.id}, 'observation')">✏️</button>
                <button class="doc-btn" onclick="deleteImpression(${r.id})">🗑️</button></div>`).join('')
            : '<div class="pf-empty">暂无观察记录。</div>';
    } catch(e) { fEl.innerHTML = '<div class="pf-empty">加载失败: ' + escapeHtml(e.message) + '</div>'; }
}
// ===== AI 印象总结（快照账本） =====
let snapStatus = null;
const SNAP_META = {
    base: { icon: '📘', desc: '全面的初始侧写：身份文档 + 全部印象记录 + 近 90 天活动。可随时重新生成。' },
    stage: { icon: '📚', desc: '定期压缩：基础印象 + 上一阶段印象 + 本阶段增量 + 新更新 → 一份最新的完整侧写。' },
    incremental: { icon: '📝', desc: '平时追加：当前阶段印象 + 上一增量 + 最近更新 → 只写变化与新信息。' },
};
function snapMd(text) { return (typeof AiChat !== 'undefined' && AiChat._md) ? AiChat._md(text || '') : escapeHtml(text || ''); }
function fmtPending(c) {
    if (!c) return '';
    const parts = [];
    if (c.impressions) parts.push(`${c.impressions} 条印象`);
    if (c.tasks) parts.push(`${c.tasks} 个任务变动`);
    if (c.worklogs) parts.push(`${c.worklogs} 天工作量`);
    if (c.mails) parts.push(`${c.mails} 封相关邮件`);
    return parts.length ? '自上次以来：' + parts.join('、') : '自上次以来没有新记录';
}
function daysSince(ts) { if (!ts) return null; return Math.floor((Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 86400000); }
async function loadSnapshots() {
    const grid = document.getElementById('snapGrid');
    try { snapStatus = (await API.impressions.snapshots()).data; }
    catch (e) { grid.innerHTML = `<div class="pf-empty">加载失败: ${escapeHtml(e.message)}</div>`; return; }
    const L = snapStatus.latest || {};
    const hasBase = !!L.base;
    grid.innerHTML = ['base', 'stage', 'incremental'].map(k => {
        const s = L[k];
        const meta = SNAP_META[k];
        const label = snapStatus.kinds[k];
        let btn;
        if (k === 'base') btn = `<button class="btn btn-primary btn-sm" onclick="generateSnapshot('base')">${hasBase ? '🔄 重新生成' : '✨ 生成基础印象'}</button>`;
        else btn = `<button class="btn btn-primary btn-sm" onclick="generateSnapshot('${k}')" ${hasBase ? '' : 'disabled title="请先生成基础印象"'}>✨ 生成${label}</button>`;
        const pend = k === 'base' ? '' : `<div class="snap-pending">${hasBase ? fmtPending(snapStatus.pending[k]) : '需要先有基础印象'}</div>`;
        const d = s ? daysSince(s.created_at) : null;
        return `<div class="snap-card ${k}">
            <div class="snap-head">${meta.icon} ${label}<span class="doc-meta">${s ? escapeHtml(s.created_at.substring(0, 16)) + (d !== null ? `（${d} 天前）` : '') : '尚未生成'}</span></div>
            <div class="snap-desc">${meta.desc}</div>
            ${s ? `<div class="snap-preview" onclick="viewSnapshot(${s.id})" title="点击查看全文">${snapMd(s.content_md)}</div>` : '<div class="pf-empty">暂无</div>'}
            ${pend}
            <div class="snap-actions">${btn}${s ? `<button class="btn btn-outline btn-sm" onclick="viewSnapshot(${s.id})">查看全文</button>` : ''}</div>
        </div>`;
    }).join('');
    renderSnapshotHistory();
}
function renderSnapshotHistory() {
    const box = document.getElementById('snapHistory');
    const h = (snapStatus && snapStatus.history) || [];
    box.innerHTML = h.length ? h.map(r => `<div class="snap-history-item">
        <span class="snap-kind ${r.kind}">${snapStatus.kinds[r.kind] || r.kind}</span>
        <span>${escapeHtml(r.created_at)}</span><span class="doc-meta">${r.chars} 字 · ${escapeHtml(r.model || '')}</span>
        <span style="flex:1"></span>
        <button class="doc-btn" onclick="viewSnapshot(${r.id})">👁️</button><button class="doc-btn" onclick="deleteSnapshot(${r.id})">🗑️</button></div>`).join('')
        : '<div class="pf-empty">还没有任何印象快照</div>';
}
function toggleSnapshotHistory() { const b = document.getElementById('snapHistory'); b.style.display = b.style.display === 'none' ? '' : 'none'; }
async function generateSnapshot(kind) {
    const label = (snapStatus && snapStatus.kinds[kind]) || kind;
    if (kind === 'base' && snapStatus && snapStatus.latest.base && !confirm('已有基础印象，重新生成将作为新的基础（旧版本保留在历史中）。继续？')) return;
    document.querySelectorAll('.snap-actions button').forEach(b => b.disabled = true);
    Toast.show(`⏳ 正在生成${label}，通常需要 20-60 秒...`, 'info', 6000);
    try {
        await API.impressions.generate(kind);
        Toast.success(`${label}已生成`);
    } catch (e) { Toast.error(e.message); }
    await loadSnapshots();
}
async function viewSnapshot(id) {
    try {
        const s = (await API.impressions.snapshot(id)).data;
        const src = s.sources || {};
        const srcText = [src.since ? `统计起点 ${src.since}` : '', src.counts ? fmtPending(src.counts).replace('自上次以来：', '纳入：') : ''].filter(Boolean).join(' · ');
        Modal.open({ title: `${SNAP_META[s.kind]?.icon || '🧾'} ${snapStatus.kinds[s.kind] || s.kind} · ${s.created_at.substring(0, 16)}`, size: 'wide',
            body: `<div class="snap-full markdown-body">${snapMd(s.content_md)}</div><div class="doc-meta" style="margin-top:10px;">${escapeHtml(s.model || '')}${srcText ? ' · ' + escapeHtml(srcText) : ''}</div>`,
            footer: '<button class="btn btn-ghost" onclick="Modal.close()">关闭</button>' });
    } catch (e) { Toast.error(e.message); }
}
async function deleteSnapshot(id) {
    if (!confirm('删除这份印象快照？')) return;
    try { await API.impressions.removeSnapshot(id); await loadSnapshots(); } catch (e) { Toast.error(e.message); }
}

function impressionForm(field, value) {
    const opts = Object.entries(impLabels).map(([k, v]) => `<option value="${k}" ${k === field ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')
        + `<option value="observation" ${field === 'observation' ? 'selected' : ''}>观察</option>`;
    return `<div class="form-group"><label>字段</label><select class="form-select" id="impField">${opts}</select></div>
            <div class="form-group"><label>内容</label><textarea class="form-textarea" id="impValue" rows="3">${escapeHtml(value || '')}</textarea></div>`;
}
function addImpression() {
    Modal.open({ title: '＋ 添加印象', body: impressionForm('title', ''),
        footer: '<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="impSave">保存</button>' });
    document.getElementById('impSave').addEventListener('click', async () => {
        try {
            await API.impressions.create({ field: document.getElementById('impField').value, value: document.getElementById('impValue').value.trim() });
            Modal.close(); await loadImpressions(); Toast.success('已记录');
        } catch(e) { Toast.error(e.message); }
    });
}
function editImpression(id, field) {
    const cur = field === 'observation'
        ? (Array.from(document.querySelectorAll('.imp-obs')).find(el => el.innerHTML.includes(`editImpression(${id},`))?.querySelector('.imp-obs-text')?.textContent || '')
        : (document.querySelector(`.imp-table button[onclick="editImpression(${id}, '${field}')"]`)?.closest('tr')?.querySelector('.imp-val')?.textContent || '');
    Modal.open({ title: '✏️ 编辑印象', body: impressionForm(field, cur),
        footer: '<button class="btn btn-ghost" onclick="Modal.close()">取消</button><button class="btn btn-primary" id="impSave">保存</button>' });
    document.getElementById('impSave').addEventListener('click', async () => {
        try {
            await API.impressions.update(id, { field: document.getElementById('impField').value, value: document.getElementById('impValue').value.trim() });
            Modal.close(); await loadImpressions(); Toast.success('已更新');
        } catch(e) { Toast.error(e.message); }
    });
}
async function deleteImpression(id) {
    if (!confirm('删除这条印象？')) return;
    try { await API.impressions.remove(id); await loadImpressions(); } catch(e) { Toast.error(e.message); }
}
async function clearImpressions() {
    if (!confirm('清空全部 AI 印象？此操作不可恢复。')) return;
    try { await API.impressions.clear(); await loadImpressions(); Toast.success('已清空'); } catch(e) { Toast.error(e.message); }
}

function showFile(type, name, size, content) {
    const card = document.getElementById(type + 'Card');
    card.querySelector('.file-name').textContent = name;
    card.querySelector('.file-size').textContent = formatSize(size);
    card.classList.add('has-file');
    const preview = document.getElementById(type + 'Preview');
    preview.textContent = content.length > 2000 ? content.substring(0,2000)+'\n...(truncated)' : content;
    preview.style.display = 'block';
}

function deleteFile(type) {
    const card = document.getElementById(type + 'Card');
    card.classList.remove('has-file');
    document.getElementById(type + 'Preview').style.display = 'none';
    if (type === 'bazi') { baziContent = ''; baziFileName = ''; }
    else if (type === 'ziwei') { ziweiContent = ''; ziweiFileName = ''; }
    Toast.success('已删除');
}

function formatSize(bytes) { return bytes < 1024 ? bytes+'B' : bytes < 1048576 ? (bytes/1024).toFixed(1)+'KB' : (bytes/1048576).toFixed(1)+'MB'; }

async function aiExtractResume() {
    const primary = profileDocs.find(d => d.is_primary) || profileDocs[0];
    if (!primary) { Toast.error('请先上传身份文档（简历）'); return; }
    const btn = document.getElementById('btnAiExtract');
    btn.disabled = true; btn.textContent = '⏳ 提取中...';
    try {
        const t = await API.profileDocs.text(primary.id);
        const text = (t.data.text || '').substring(0, 12000);
        if (!text.trim()) throw new Error('主文档没有可用文本，请尝试重新提取');
        const res = await API.post('/ai/chat', { messages: [
            { role: 'user', content: '请从以下简历文本中提取关键信息，用中文简要总结：姓名、学历、工作经历、研究方向、技能特长。用 2-3 段简洁文字总结，不要使用工具。\n\n' + text }
        ]});
        if ((res.data.type === 'steps' || res.data.type === 'text') && res.data.content) {
            document.getElementById('pfResume').value = res.data.content;
            Toast.success('简介已生成，记得点“保存侧写”');
        } else { Toast.error('AI 未返回文本'); }
    } catch(e) { Toast.error('提取失败: ' + e.message); }
    finally { btn.disabled = false; btn.textContent = '🤖 从主文档提取'; }
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await API.get('/profile'); const p = res.data || {};
        document.getElementById('pfName').value = p.name || '';
        document.getElementById('pfGender').value = p.gender || '';
        document.getElementById('pfBirthDate').value = p.birth_date || '';
        document.getElementById('pfBirthTime').value = p.birth_time || '';
        document.getElementById('pfBirthPlace').value = p.birth_place || '';
        document.getElementById('pfShengXiao').value = p.shengxiao || '';
        document.getElementById('pfResume').value = p.resume || '';
        document.getElementById('pfGoals').value = p.goals || '';
        // Restore BaZi pillars
        if (p.bazi_year) document.getElementById('pfYear').value = p.bazi_year;
        if (p.bazi_month) document.getElementById('pfMonth').value = p.bazi_month;
        if (p.bazi_day) document.getElementById('pfDay').value = p.bazi_day;
        if (p.bazi_time) document.getElementById('pfTime').value = p.bazi_time;
        // Restore files: bazi stored in shishen key, ziwei in dayun key
        if (p.shishen && p.shishen.length > 10) { baziContent = p.shishen; baziFileName = '八字命盘.txt'; showFile('bazi', baziFileName, new Blob([baziContent]).size, baziContent); }
        if (p.dayun && p.dayun.length > 10) { ziweiContent = p.dayun; ziweiFileName = '紫微命盘.txt'; showFile('ziwei', ziweiFileName, new Blob([ziweiContent]).size, ziweiContent); }
    } catch(e) {}
    loadDocs();
    loadImpressions();
    loadSnapshots();

    document.getElementById('pfSave').addEventListener('click', async () => {
        try {
            await API.put('/profile', {
                name: document.getElementById('pfName').value.trim(),
                gender: document.getElementById('pfGender').value,
                birth_date: document.getElementById('pfBirthDate').value,
                birth_time: document.getElementById('pfBirthTime').value,
                birth_place: document.getElementById('pfBirthPlace').value.trim(),
                shengxiao: document.getElementById('pfShengXiao').value.trim(),
                bazi_year: document.getElementById('pfYear').value.trim(),
                bazi_month: document.getElementById('pfMonth').value.trim(),
                bazi_day: document.getElementById('pfDay').value.trim(),
                bazi_time: document.getElementById('pfTime').value.trim(),
                resume: document.getElementById('pfResume').value.trim(),
                goals: document.getElementById('pfGoals').value.trim(),
                shishen: baziContent,
                dayun: ziweiContent,
            });
            Toast.success('侧写已保存');
        } catch(e) { Toast.error('保存失败: ' + e.message); }
    });
});
</script>
HTML;

require __DIR__ . '/../components/layout.php';
