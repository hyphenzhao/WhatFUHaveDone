<?php
$page_title = '日历管理';
$current_page = 'calendar-admin';
$page_content = <<<'HTML'
<div class="page-header">
    <h2>📅 日历数据管理</h2>
    <p style="color:var(--color-text-secondary);margin-top:4px;">更新农历日期、节气、节假日数据到日历</p>
</div>

<div class="calendar-admin-panel">
    <div class="form-row">
        <div class="form-group">
            <label>选择年份</label>
            <select class="form-select" id="adminYear">
                <option value="2024">2024</option>
                <option value="2025">2025</option>
                <option value="2026" selected>2026</option>
                <option value="2027">2027</option>
                <option value="2028">2028</option>
            </select>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button class="btn btn-primary" id="btnUpdate" onclick="updateCalendar()">
                🔄 更新日历数据
            </button>
        </div>
    </div>
    <div id="updateStatus" style="margin-top:12px;"></div>
</div>

<style>
.calendar-admin-panel {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 24px;
    max-width: 600px;
}
.form-row {
    display: flex;
    gap: 16px;
    align-items: flex-end;
}
#updateStatus .progress-bar {
    height: 6px;
    background: var(--color-border);
    border-radius: 3px;
    margin-top: 8px;
    overflow: hidden;
}
#updateStatus .progress-fill {
    height: 100%;
    background: var(--color-primary);
    border-radius: 3px;
    transition: width 0.3s ease;
}
</style>

<script src="/assets/js/lunar.js"></script>
<script>
async function updateCalendar() {
    const year = parseInt(document.getElementById('adminYear').value);
    const btn = document.getElementById('btnUpdate');
    const status = document.getElementById('updateStatus');

    btn.disabled = true;
    btn.textContent = '⏳ 计算中...';
    status.innerHTML = '<div class="progress-bar"><div class="progress-fill" style="width:0%"></div></div>';

    const dates = [];
    const isLeapYear = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
    const daysInYear = isLeapYear ? 366 : 365;
    const startDate = new Date(year, 0, 1);

    // Step 1: Compute lunar dates + solar terms using lunar-javascript
    for (let i = 0; i < daysInYear; i++) {
        const d = new Date(startDate);
        d.setDate(d.getDate() + i);
        const dateStr = d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');

        const lunar = Lunar.fromDate(d);
        const entry = {
            date: dateStr,
            lunar_month: lunar.getMonthInChinese(),
            lunar_day: lunar.getDayInChinese(),
            solar_term: '',
            holiday_name: '',
            is_holiday: 0,
            is_workday: 0,
        };

        // Solar term: check if this day is a jieQi
        const jq = lunar.getJieQi();
        if (jq) entry.solar_term = jq;

        // Traditional festivals from lunar
        const festivals = lunar.getFestivals();
        if (festivals.length > 0) {
            entry.holiday_name = festivals.join('、');
        }

        dates.push(entry);

        // Update progress every 30 days
        if (i % 30 === 0) {
            const pct = Math.round((i / daysInYear) * 60);
            status.innerHTML = `<div>计算农历日期... ${i}/${daysInYear}</div>
                <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>`;
            await new Promise(r => setTimeout(r, 0)); // allow UI update
        }
    }

    status.innerHTML = `<div>获取节假日数据...</div>
        <div class="progress-bar"><div class="progress-fill" style="width:65%"></div></div>`;

    // Step 2: Fetch official holidays from chinese-days
    try {
        const holidayRes = await fetch(`https://cdn.jsdelivr.net/npm/chinese-days/dist/years/${year}.json`);
        const holidayData = await holidayRes.json();

        // Apply holidays
        if (holidayData.holidays) {
            for (const [dateStr, desc] of Object.entries(holidayData.holidays)) {
                const parts = desc.split(',');
                const cnName = parts[1] || parts[0];
                const entry = dates.find(e => e.date === dateStr);
                if (entry) {
                    entry.is_holiday = 1;
                    // Don't overwrite traditional festival if already set
                    if (!entry.holiday_name) {
                        entry.holiday_name = cnName;
                    } else if (!entry.holiday_name.includes(cnName)) {
                        entry.holiday_name = cnName;
                    }
                }
            }
        }

        // Apply adjusted workdays (调休补班)
        if (holidayData.workdays) {
            for (const dateStr of Object.keys(holidayData.workdays)) {
                const entry = dates.find(e => e.date === dateStr);
                if (entry) entry.is_workday = 1;
            }
        }
    } catch (e) {
        console.warn('Failed to fetch holiday data:', e);
        status.innerHTML += '<div style="color:#e53e3e;">⚠️ 节假日数据获取失败，将只包含农历和节气</div>';
    }

    // Step 3: Save to backend in batches
    const BATCH_SIZE = 100;
    let saved = 0;
    for (let i = 0; i < dates.length; i += BATCH_SIZE) {
        const batch = dates.slice(i, i + BATCH_SIZE);
        try {
            await API.calendarMeta.save(batch);
            saved += batch.length;
        } catch (e) {
            status.innerHTML += `<div style="color:#e53e3e;">保存失败 (${i}-${i+BATCH_SIZE}): ${escapeHtml(e.message)}</div>`;
        }
        const pct = 65 + Math.round((saved / dates.length) * 35);
        status.innerHTML = `<div>保存数据中... ${saved}/${dates.length}</div>
            <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>`;
        await new Promise(r => setTimeout(r, 0));
    }

    status.innerHTML = `<div style="color:#38a169;font-weight:600;">✅ 更新完成！已保存 ${saved} 天数据（${year}年）</div>`;
    btn.disabled = false;
    btn.textContent = '🔄 更新日历数据';
}
</script>
HTML;

require __DIR__ . '/../components/layout.php';
