<?php
$page_title = '个人侧写';
$current_page = 'profile';
$page_content = <<<'HTML'
<div class="page-header"><h2>👤 个人侧写</h2><p style="color:var(--color-text-secondary);margin-top:4px;">配置你的基本信息、生辰八字，AI 将据此提供个性化分析</p></div>

<div class="profile-layout">
<div class="profile-form-panel">
    <div class="form-group"><label>姓名</label><input class="form-input" id="pfName" placeholder="你的姓名"></div>
    <div class="form-row-3">
        <div class="form-group"><label>性别</label><select class="form-select" id="pfGender"><option value="">--</option><option value="男">男</option><option value="女">女</option></select></div>
        <div class="form-group"><label>阳历生日</label><input type="date" class="form-input" id="pfBirthDate"></div>
        <div class="form-group"><label>时辰</label><select class="form-select" id="pfBirthTime">
            <option value="">未知</option>
            <option value="0">子时 (23-01)</option><option value="1">丑时 (01-03)</option><option value="2">寅时 (03-05)</option><option value="3">卯时 (05-07)</option><option value="4">辰时 (07-09)</option><option value="5">巳时 (09-11)</option><option value="6">午时 (11-13)</option><option value="7">未时 (13-15)</option><option value="8">申时 (15-17)</option><option value="9">酉时 (17-19)</option><option value="10">戌时 (19-21)</option><option value="11">亥时 (21-23)</option>
        </select></div>
    </div>
    <div class="form-group"><label>出生地</label><input class="form-input" id="pfBirthPlace" placeholder="如: 北京"></div>
    <div class="form-group"><label>个人简历/背景</label><textarea class="form-textarea" id="pfResume" rows="4" placeholder="教育背景、工作经历、研究方向、兴趣爱好等"></textarea></div>
    <div class="form-group"><label>当前阶段目标</label><textarea class="form-textarea" id="pfGoals" rows="3" placeholder="近期目标、中期规划、远期愿景等"></textarea></div>
    <button class="btn btn-primary" id="pfSave">💾 保存侧写</button>
</div>

<div class="profile-bazi-panel" id="baziPanel">
    <h3>🔮 八字命盘</h3>
    <div id="baziDisplay"><div class="no-daily-data">请填写生日和时辰后自动计算</div></div>
</div>
</div>

<style>
.profile-layout { display: flex; gap: 20px; align-items: flex-start; }
.profile-form-panel { flex: 1; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 20px; }
.profile-bazi-panel { width: 340px; flex-shrink: 0; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 20px; position: sticky; top: 16px; }
.form-row-3 { display: flex; gap: 12px; }
.form-row-3 .form-group { flex: 1; }
.bazi-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.bazi-table th, .bazi-table td { border: 1px solid var(--color-border); padding: 6px 8px; text-align: center; font-size: 0.85rem; }
.bazi-table th { background: var(--color-bg); font-weight: 700; font-size: 0.78rem; }
.bazi-pillar { font-size: 1.1rem; font-weight: 700; letter-spacing: 2px; }
.bazi-label { font-size: 0.72rem; color: var(--color-text-secondary); }
@media (max-width: 768px) { .profile-layout { flex-direction: column; } .profile-bazi-panel { width: 100%; } }
</style>

<script src="/assets/js/lunar.js"></script>
<script>
// Five elements colors
const WX = { 木:'#38a169', 火:'#e53e3e', 土:'#d97706', 金:'#ddb100', 水:'#3182ce' };
const WX_GAN = { 甲:'木',乙:'木',丙:'火',丁:'火',戊:'土',己:'土',庚:'金',辛:'金',壬:'水',癸:'水' };
const WX_ZHI = { 子:'水',丑:'土',寅:'木',卯:'木',辰:'土',巳:'火',午:'火',未:'土',申:'金',酉:'金',戌:'土',亥:'水' };

function colorSpan(char, wx) { return `<span style="color:${WX[wx]||'#333'}">${char}</span>`; }

function computeBazi() {
    const date = document.getElementById('pfBirthDate').value;
    const timeIdx = document.getElementById('pfBirthTime').value;
    if (!date) return;

    const d = new Date(date + 'T12:00:00');
    const hour = timeIdx !== '' ? (parseInt(timeIdx) * 2 + 1) % 24 : 12;
    d.setHours(hour, 0, 0, 0);

    const lunar = Lunar.fromDate(d);
    const ec = lunar.getEightChar();

    const row = (label, gan, zhi, naYin, shiShenG, shiShenZ) => `
        <tr><td class="bazi-label">${label}</td>
        <td class="bazi-pillar">${colorSpan(gan, WX_GAN[gan])}${colorSpan(zhi, WX_ZHI[zhi])}</td>
        <td>${naYin||''}</td><td>${shiShenG||''}</td><td style="font-size:0.75rem">${shiShenZ||''}</td></tr>`;

    const shengxiao = lunar.getYearShengXiao ? lunar.getYearShengXiao() : '';
    const display = document.getElementById('baziDisplay');
    display.innerHTML = `
        <div style="margin-bottom:8px;font-size:0.9rem;"><strong>生肖:</strong> ${escapeHtml(shengxiao)} | <strong>日主:</strong> ${colorSpan(ec.getDayGan(), WX_GAN[ec.getDayGan()])}</div>
        <table class="bazi-table">
            <tr><th></th><th>干支</th><th>纳音</th><th>十神(干)</th><th>藏干十神</th></tr>
            ${row('年柱', ec.getYearGan(), ec.getYearZhi(), ec.getYearNaYin(), ec.getYearShiShenGan(), (ec.getYearShiShenZhi()||[]).map(s=>s||'').join(' '))}
            ${row('月柱', ec.getMonthGan(), ec.getMonthZhi(), ec.getMonthNaYin(), ec.getMonthShiShenGan(), (ec.getMonthShiShenZhi()||[]).map(s=>s||'').join(' '))}
            ${row('日柱', ec.getDayGan(), ec.getDayZhi(), ec.getDayNaYin(), ec.getDayShiShenGan(), (ec.getDayShiShenZhi()||[]).map(s=>s||'').join(' '))}
            ${row('时柱', ec.getTimeGan(), ec.getTimeZhi(), ec.getTimeNaYin(), ec.getTimeShiShenGan(), (ec.getTimeShiShenZhi()||[]).map(s=>s||'').join(' '))}
        </table>
        <div style="margin-top:8px;font-size:0.78rem;color:var(--color-text-secondary);">年五行: ${WX_GAN[ec.getYearGan()]}${WX_ZHI[ec.getYearZhi()]} · 胎元: ${ec.getTaiYuan()||''} · 命宫: ${ec.getMingGong()||''}</div>`;
}

document.getElementById('pfBirthDate').addEventListener('change', computeBazi);
document.getElementById('pfBirthTime').addEventListener('change', computeBazi);

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await API.get('/profile');
        const p = res.data || {};
        document.getElementById('pfName').value = p.name || '';
        document.getElementById('pfGender').value = p.gender || '';
        document.getElementById('pfBirthDate').value = p.birth_date || '';
        document.getElementById('pfBirthTime').value = p.birth_time || '';
        document.getElementById('pfBirthPlace').value = p.birth_place || '';
        document.getElementById('pfResume').value = p.resume || '';
        document.getElementById('pfGoals').value = p.goals || '';
        if (p.birth_date) computeBazi();
    } catch(e) {}

    document.getElementById('pfSave').addEventListener('click', async () => {
        try {
            const data = {
                name: document.getElementById('pfName').value.trim(),
                gender: document.getElementById('pfGender').value,
                birth_date: document.getElementById('pfBirthDate').value,
                birth_time: document.getElementById('pfBirthTime').value,
                birth_place: document.getElementById('pfBirthPlace').value.trim(),
                resume: document.getElementById('pfResume').value.trim(),
                goals: document.getElementById('pfGoals').value.trim(),
            };
            // Also save bazi data
            if (data.birth_date) {
                const d = new Date(data.birth_date + 'T12:00:00');
                const hour = data.birth_time !== '' ? (parseInt(data.birth_time) * 2 + 1) % 24 : 12;
                d.setHours(hour, 0, 0, 0);
                const lunar = Lunar.fromDate(d);
                const ec = lunar.getEightChar();
                data.bazi_year = ec.getYearGan() + ec.getYearZhi();
                data.bazi_month = ec.getMonthGan() + ec.getMonthZhi();
                data.bazi_day = ec.getDayGan() + ec.getDayZhi();
                data.bazi_time = ec.getTimeGan() + ec.getTimeZhi();
                data.shishen = JSON.stringify({ year:ec.getYearShiShenGan(), month:ec.getMonthShiShenGan(), day:ec.getDayShiShenGan(), time:ec.getTimeShiShenGan() });
                data.nayin = JSON.stringify({ year:ec.getYearNaYin(), month:ec.getMonthNaYin(), day:ec.getDayNaYin(), time:ec.getTimeNaYin() });
                data.shengxiao = lunar.getYearShengXiao ? lunar.getYearShengXiao() : '';
            }
            await API.put('/profile', data);
            Toast.success('侧写已保存');
        } catch(e) { Toast.error('保存失败: ' + e.message); }
    });
});
</script>
HTML;

require __DIR__ . '/../components/layout.php';
