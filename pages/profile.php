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

    <h3 style="margin-top:16px;">📝 个人背景</h3>
    <div class="form-group">
        <label>简历 PDF <button class="btn btn-ghost btn-sm" id="btnAiExtract" onclick="aiExtractResume()" style="margin-left:8px;">🤖 AI 提取</button></label>
        <div class="file-card" id="resumeCard">
            <div class="file-placeholder">📄 上传简历 PDF（自动提取文字）</div>
            <div class="file-info" style="display:none">
                <span class="file-name" id="resumeFileName"></span>
                <span class="file-size" id="resumeFileSize"></span>
                <button class="btn btn-ghost btn-sm" onclick="deleteFile('resume')">🗑️</button>
            </div>
            <input type="file" accept=".pdf" class="file-input" id="resumeFileInput" onchange="uploadFile('resume', this)">
            <button class="btn btn-ghost btn-sm upload-btn" onclick="document.getElementById('resumeFileInput').click()">📤 上传</button>
        </div>
    </div>
    <div class="form-group"><label>个人简历/背景</label><textarea class="form-textarea" id="pfResume" rows="5" placeholder="教育背景、工作经历、研究方向、兴趣爱好等（上传PDF后点 AI 提取 可自动填充）"></textarea></div>
    <div class="form-group"><label>当前阶段目标</label><textarea class="form-textarea" id="pfGoals" rows="4" placeholder="近期目标、中期规划、远期愿景等"></textarea></div>
</div>

<!-- 右栏：命盘文件 -->
<div class="profile-panel">
    <h3>🔮 八字命盘</h3>
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
.upload-btn { flex-shrink: 0; }
.file-preview { max-height: 200px; overflow-y: auto; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 8px 12px; font-size: 0.78rem; font-family: monospace; white-space: pre-wrap; line-height: 1.5; margin-bottom: 20px; }
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
        else if (type === 'resume') { resumeContent = d.content; resumeFileName = d.name; showFile('resume', d.name, d.size, d.content.substring(0,500)); Toast.success('PDF已解析，点击"AI 提取"自动填充简历'); }
    } catch(e) { Toast.error('上传失败'); }
    input.value = '';
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
    else if (type === 'resume') { resumeContent = ''; resumeFileName = ''; }
    Toast.success('已删除');
}

function formatSize(bytes) { return bytes < 1024 ? bytes+'B' : bytes < 1048576 ? (bytes/1024).toFixed(1)+'KB' : (bytes/1048576).toFixed(1)+'MB'; }

async function aiExtractResume() {
    if (!resumeContent) { Toast.error('请先上传简历 PDF'); return; }
    const btn = document.getElementById('btnAiExtract');
    btn.disabled = true; btn.textContent = '⏳ 提取中...';
    try {
        const res = await API.post('/ai/chat', { messages: [
            { role: 'user', content: '请从以下简历文本中提取关键信息，用中文简要总结：姓名、性别、出生日期、学历、工作经历、研究方向、技能特长。用 2-3 段简洁文字总结。\n\n' + resumeContent }
        ]});
        if (res.data.type === 'text') {
            document.getElementById('pfResume').value = res.data.content;
            Toast.success('简历信息已提取');
        }
    } catch(e) { Toast.error('提取失败: ' + e.message); }
    finally { btn.disabled = false; btn.textContent = '🤖 AI 提取'; }
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
        // Restore files: bazi stored in shishen key, ziwei in dayun key
        if (p.shishen && p.shishen.length > 10) { baziContent = p.shishen; baziFileName = '八字命盘.txt'; showFile('bazi', baziFileName, new Blob([baziContent]).size, baziContent); }
        if (p.dayun && p.dayun.length > 10) { ziweiContent = p.dayun; ziweiFileName = '紫微命盘.txt'; showFile('ziwei', ziweiFileName, new Blob([ziweiContent]).size, ziweiContent); }
    } catch(e) {}

    document.getElementById('pfSave').addEventListener('click', async () => {
        try {
            await API.put('/profile', {
                name: document.getElementById('pfName').value.trim(),
                gender: document.getElementById('pfGender').value,
                birth_date: document.getElementById('pfBirthDate').value,
                birth_time: document.getElementById('pfBirthTime').value,
                birth_place: document.getElementById('pfBirthPlace').value.trim(),
                shengxiao: document.getElementById('pfShengXiao').value.trim(),
                resume: document.getElementById('pfResume').value.trim() || resumeContent,
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
