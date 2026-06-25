<?php
$page_title = '个人侧写';
$current_page = 'profile';
$page_content = <<<'HTML'
<div class="page-header"><h2>👤 个人侧写</h2><p style="color:var(--color-text-secondary);margin-top:4px;">配置你的基本信息和命盘，AI 将据此提供个性化分析</p></div>

<div class="profile-layout">
<div class="profile-form-panel">
    <h3 style="margin-bottom:12px;">📋 基本信息</h3>
    <div class="form-row-2">
        <div class="form-group"><label>姓名</label><input class="form-input" id="pfName" placeholder="你的姓名"></div>
        <div class="form-group"><label>性别</label><select class="form-select" id="pfGender"><option value="">--</option><option value="男">男</option><option value="女">女</option></select></div>
    </div>
    <div class="form-row-3">
        <div class="form-group"><label>阳历生日</label><input type="date" class="form-input" id="pfBirthDate"></div>
        <div class="form-group"><label>时辰</label><select class="form-select" id="pfBirthTime">
            <option value="">未知</option>
            <option value="0">子时 (23-01)</option><option value="1">丑时 (01-03)</option><option value="2">寅时 (03-05)</option><option value="3">卯时 (05-07)</option><option value="4">辰时 (07-09)</option><option value="5">巳时 (09-11)</option><option value="6">午时 (11-13)</option><option value="7">未时 (13-15)</option><option value="8">申时 (15-17)</option><option value="9">酉时 (17-19)</option><option value="10">戌时 (19-21)</option><option value="11">亥时 (21-23)</option>
        </select></div>
        <div class="form-group"><label>出生地</label><input class="form-input" id="pfBirthPlace" placeholder="如: 北京"></div>
    </div>

    <h3 style="margin:16px 0 12px;">🔮 八字命盘 <button class="btn btn-ghost btn-sm" onclick="autoCompute()" title="根据生日时辰自动推算">🧮 自动推算</button></h3>
    <div class="form-row-4" style="margin-bottom:50px;">
        <div class="form-group"><label>年柱</label><input class="form-input bazi-input" id="pfYear" placeholder="如: 丙午" maxlength="4"></div>
        <div class="form-group"><label>月柱</label><input class="form-input bazi-input" id="pfMonth" placeholder="如: 甲午" maxlength="4"></div>
        <div class="form-group"><label>日柱</label><input class="form-input bazi-input" id="pfDay" placeholder="如: 戊辰" maxlength="4"></div>
        <div class="form-group"><label>时柱</label><input class="form-input bazi-input" id="pfTime" placeholder="如: 壬子" maxlength="4"></div>
    </div>

    <div class="form-row-2">
        <div class="form-group"><label>十神 (年/月/日/时)</label><input class="form-input" id="pfShiShen" placeholder="如: 劫财/食神/日主/正财"></div>
        <div class="form-group"><label>纳音 (年/月/日/时)</label><input class="form-input" id="pfNaYin" placeholder="如: 天河水/沙中金/大林木/桑柘木"></div>
    </div>
    <div class="form-row-2">
        <div class="form-group"><label>生肖</label><input class="form-input" id="pfShengXiao" placeholder="如: 马" maxlength="4"></div>
        <div class="form-group"><label>胎元 / 命宫</label><input class="form-input" id="pfTaiYuan" placeholder="如: 乙酉 / 丙子"></div>
    </div>

    <h3 style="margin:16px 0 12px;">🌟 紫微命盘</h3>
    <div class="form-group"><label>紫微命盘文本（从排盘软件复制粘贴）</label><textarea class="form-textarea" id="pfZiwei" rows="6" placeholder="粘贴紫微斗数命盘内容，如：命宫-天机[禄] 兄弟宫-天梁 夫妻宫-太阳..."></textarea></div>

    <h3 style="margin:16px 0 12px;">📝 个人背景</h3>
    <div class="form-group"><label>个人简历/背景</label><textarea class="form-textarea" id="pfResume" rows="4" placeholder="教育背景、工作经历、研究方向、兴趣爱好等"></textarea></div>
    <div class="form-group"><label>当前阶段目标</label><textarea class="form-textarea" id="pfGoals" rows="3" placeholder="近期目标、中期规划、远期愿景等"></textarea></div>
    <button class="btn btn-primary" id="pfSave">💾 保存侧写</button>
</div>
</div>

<style>
.profile-layout { display: flex; gap: 20px; }
.profile-form-panel { flex: 1; max-width: 720px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 24px; }
.form-row-2 { display: flex; gap: 12px; }
.form-row-2 .form-group { flex: 1; }
.form-row-3 { display: flex; gap: 12px; }
.form-row-3 .form-group { flex: 1; }
.form-row-4 { display: flex; gap: 12px; }
.form-row-4 .form-group { flex: 1; }
.bazi-input { font-size: 1.1rem; font-weight: 700; text-align: center; letter-spacing: 4px; }
.form-group label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; color: var(--color-text-secondary); }
@media (max-width: 600px) { .form-row-2,.form-row-3,.form-row-4 { flex-direction: column; } }
</style>

<script src="/assets/js/lunar.js"></script>
<script>
function autoCompute() {
    const date = document.getElementById('pfBirthDate').value;
    if (!date) { Toast.error('请先填写阳历生日'); return; }
    const timeIdx = document.getElementById('pfBirthTime').value;
    const hour = timeIdx !== '' ? (parseInt(timeIdx) * 2 + 1) % 24 : 12;
    const d = new Date(date + 'T12:00:00'); d.setHours(hour, 0, 0, 0);
    const lunar = Lunar.fromDate(d);
    const ec = lunar.getEightChar();
    document.getElementById('pfYear').value = ec.getYearGan() + ec.getYearZhi();
    document.getElementById('pfMonth').value = ec.getMonthGan() + ec.getMonthZhi();
    document.getElementById('pfDay').value = ec.getDayGan() + ec.getDayZhi();
    document.getElementById('pfTime').value = ec.getTimeGan() + ec.getTimeZhi();
    document.getElementById('pfShiShen').value = [ec.getYearShiShenGan(), ec.getMonthShiShenGan(), ec.getDayShiShenGan(), ec.getTimeShiShenGan()].join('/');
    document.getElementById('pfNaYin').value = [ec.getYearNaYin(), ec.getMonthNaYin(), ec.getDayNaYin(), ec.getTimeNaYin()].join('/');
    document.getElementById('pfShengXiao').value = lunar.getYearShengXiao ? lunar.getYearShengXiao() : '';
    document.getElementById('pfTaiYuan').value = (ec.getTaiYuan()||'') + ' / ' + (ec.getMingGong()||'');
    Toast.success('已自动推算，请核对修正后保存');
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await API.get('/profile');
        const p = res.data || {};
        ['pfName','pfGender','pfBirthTime','pfBirthPlace','pfResume','pfGoals','pfShengXiao','pfShiShen','pfNaYin','pfZiwei','pfTaiYuan','pfYear','pfMonth','pfDay','pfTime'].forEach(id => {
            document.getElementById(id).value = p[id.replace('pf','').toLowerCase()] || p[id.slice(2).toLowerCase()] || '';
        });
        document.getElementById('pfBirthDate').value = p.birth_date || '';
        // Map bazi columns to fields
        if (p.bazi_year && !document.getElementById('pfYear').value) document.getElementById('pfYear').value = p.bazi_year;
        if (p.bazi_month && !document.getElementById('pfMonth').value) document.getElementById('pfMonth').value = p.bazi_month;
        if (p.bazi_day && !document.getElementById('pfDay').value) document.getElementById('pfDay').value = p.bazi_day;
        if (p.bazi_time && !document.getElementById('pfTime').value) document.getElementById('pfTime').value = p.bazi_time;
    } catch(e) {}

    document.getElementById('pfSave').addEventListener('click', async () => {
        try {
            await API.put('/profile', {
                name: document.getElementById('pfName').value.trim(),
                gender: document.getElementById('pfGender').value,
                birth_date: document.getElementById('pfBirthDate').value,
                birth_time: document.getElementById('pfBirthTime').value,
                birth_place: document.getElementById('pfBirthPlace').value.trim(),
                bazi_year: document.getElementById('pfYear').value.trim(),
                bazi_month: document.getElementById('pfMonth').value.trim(),
                bazi_day: document.getElementById('pfDay').value.trim(),
                bazi_time: document.getElementById('pfTime').value.trim(),
                shishen: document.getElementById('pfShiShen').value.trim(),
                nayin: document.getElementById('pfNaYin').value.trim(),
                dayun: document.getElementById('pfZiwei').value.trim(),
                shengxiao: document.getElementById('pfShengXiao').value.trim(),
                resume: document.getElementById('pfResume').value.trim(),
                goals: document.getElementById('pfGoals').value.trim(),
            });
            Toast.success('侧写已保存');
        } catch(e) { Toast.error('保存失败: ' + e.message); }
    });
});
</script>
HTML;

require __DIR__ . '/../components/layout.php';
