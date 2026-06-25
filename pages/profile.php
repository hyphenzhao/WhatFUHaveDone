<?php
$page_title = '个人侧写';
$current_page = 'profile';
$page_content = <<<'HTML'
<div class="page-header"><h2>👤 个人侧写</h2><p style="color:var(--color-text-secondary);margin-top:4px;">配置你的基本信息和命盘，AI 将据此提供个性化分析</p></div>

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
    <div class="form-group"><label>个人简历/背景</label><textarea class="form-textarea" id="pfResume" rows="5" placeholder="教育背景、工作经历、研究方向、兴趣爱好等"></textarea></div>
    <div class="form-group"><label>当前阶段目标</label><textarea class="form-textarea" id="pfGoals" rows="4" placeholder="近期目标、中期规划、远期愿景等"></textarea></div>
</div>

<!-- 右栏：命盘 -->
<div class="profile-panel">
    <h3>🔮 八字命盘 <button class="btn btn-ghost btn-sm" onclick="autoCompute()" title="根据生日时辰自动推算">🧮 推算</button></h3>
    <div class="form-row-4">
        <div class="form-group"><label>年柱</label><input class="form-input bazi-input" id="pfYear" placeholder="丙午" maxlength="4"></div>
        <div class="form-group"><label>月柱</label><input class="form-input bazi-input" id="pfMonth" placeholder="甲午" maxlength="4"></div>
        <div class="form-group"><label>日柱</label><input class="form-input bazi-input" id="pfDay" placeholder="戊辰" maxlength="4"></div>
        <div class="form-group"><label>时柱</label><input class="form-input bazi-input" id="pfTime" placeholder="壬子" maxlength="4"></div>
    </div>
    <div class="form-row-2">
        <div class="form-group"><label>十神 (年/月/日/时)</label><input class="form-input" id="pfShiShen" placeholder="劫财 / 食神 / 日主 / 正财"></div>
        <div class="form-group"><label>纳音 (年/月/日/时)</label><input class="form-input" id="pfNaYin" placeholder="天河水 / 沙中金 / 大林木 / 桑柘木"></div>
    </div>
    <div class="form-group"><label>胎元 / 命宫</label><input class="form-input" id="pfTaiYuan" placeholder="如: 乙酉 / 丙子"></div>

    <h3 style="margin-top:16px;">🌟 紫微命盘</h3>
    <div class="form-group"><label>从排盘软件复制粘贴</label><textarea class="form-textarea" id="pfZiwei" rows="8" placeholder="粘贴紫微斗数命盘内容，如：&#10;命宫-天机[禄]  兄弟宫-天梁  夫妻宫-太阳&#10;子女宫-武曲[权]  财帛宫-天同  疾厄宫-廉贞&#10;迁移宫-破军  交友宫-天府  官禄宫-太阴&#10;田宅宫-紫微  福德宫-巨门  父母宫-贪狼"></textarea></div>
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
.form-row-4 { display: flex; gap: 10px; }
.form-row-4 .form-group { flex: 1; }
.bazi-input { font-size: 1.15rem; font-weight: 700; text-align: center; letter-spacing: 4px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: var(--color-text-secondary); }
@media (max-width: 768px) { .profile-layout { flex-direction: column; } }
</style>

<script src="/assets/js/lunar.js"></script>
<script>
function autoCompute() {
    const date = document.getElementById('pfBirthDate').value;
    if (!date) { Toast.error('请先在左侧填写阳历生日'); return; }
    const timeIdx = document.getElementById('pfBirthTime').value;
    const hour = timeIdx !== '' ? (parseInt(timeIdx) * 2 + 1) % 24 : 12;
    const d = new Date(date + 'T12:00:00'); d.setHours(hour, 0, 0, 0);
    const lunar = Lunar.fromDate(d);
    const ec = lunar.getEightChar();
    document.getElementById('pfYear').value = ec.getYearGan() + ec.getYearZhi();
    document.getElementById('pfMonth').value = ec.getMonthGan() + ec.getMonthZhi();
    document.getElementById('pfDay').value = ec.getDayGan() + ec.getDayZhi();
    document.getElementById('pfTime').value = ec.getTimeGan() + ec.getTimeZhi();
    document.getElementById('pfShiShen').value = [ec.getYearShiShenGan(), ec.getMonthShiShenGan(), ec.getDayShiShenGan(), ec.getTimeShiShenGan()].join(' / ');
    document.getElementById('pfNaYin').value = [ec.getYearNaYin(), ec.getMonthNaYin(), ec.getDayNaYin(), ec.getTimeNaYin()].join(' / ');
    if (!document.getElementById('pfShengXiao').value) document.getElementById('pfShengXiao').value = lunar.getYearShengXiao ? lunar.getYearShengXiao() : '';
    document.getElementById('pfTaiYuan').value = (ec.getTaiYuan()||'') + ' / ' + (ec.getMingGong()||'');
    Toast.success('已推算，请核对修正后保存');
}

function gv(id) { return document.getElementById(id).value.trim(); }

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await API.get('/profile'); const p = res.data || {};
        const m = { pfName:'name',pfGender:'gender',pfBirthDate:'birth_date',pfBirthTime:'birth_time',pfBirthPlace:'birth_place',pfShengXiao:'shengxiao',pfResume:'resume',pfGoals:'goals',pfYear:'bazi_year',pfMonth:'bazi_month',pfDay:'bazi_day',pfTime:'bazi_time',pfShiShen:'shishen',pfNaYin:'nayin',pfZiwei:'dayun',pfTaiYuan:'dayun' };
        Object.entries(m).forEach(([id,key]) => { if (p[key] && key !== 'dayun') document.getElementById(id).value = p[key]; });
        if (p.dayun && p.dayun.length < 200) document.getElementById('pfTaiYuan').value = p.dayun;
        else if (p.dayun) document.getElementById('pfZiwei').value = p.dayun;
    } catch(e) {}

    document.getElementById('pfSave').addEventListener('click', async () => {
        try {
            await API.put('/profile', {
                name: gv('pfName'), gender: gv('pfGender'), birth_date: gv('pfBirthDate'), birth_time: gv('pfBirthTime'), birth_place: gv('pfBirthPlace'),
                bazi_year: gv('pfYear'), bazi_month: gv('pfMonth'), bazi_day: gv('pfDay'), bazi_time: gv('pfTime'),
                shishen: gv('pfShiShen'), nayin: gv('pfNaYin'), dayun: gv('pfZiwei'), shengxiao: gv('pfShengXiao'),
                resume: gv('pfResume'), goals: gv('pfGoals'),
            });
            Toast.success('侧写已保存');
        } catch(e) { Toast.error('保存失败: ' + e.message); }
    });
});
</script>
HTML;

require __DIR__ . '/../components/layout.php';
