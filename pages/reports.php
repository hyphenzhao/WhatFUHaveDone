<?php
/**
 * Periodic Reports Page — calendar-centric review hub.
 * Report buttons (日/周/月/年) pop up the AI report for the calendar-selected date's period.
 * Below the calendar: workload + results statistics at a chosen granularity.
 */
$page_title = '周期报告';
$current_page = 'reports';
$page_content = <<<HTML
<div class="page-header">
    <h2>📊 周期报告</h2>
    <span class="date-display" id="reportSelDate"></span>
</div>

<div class="report-actions-card">
    <div class="report-actions-title">📄 生成 / 查看报告</div>
    <div class="report-actions">
        <button type="button" class="rbtn rbtn-primary" onclick="Reports.openReport('daily')">日报</button>
        <button type="button" class="rbtn rbtn-success" onclick="Reports.openReport('weekly')">周报</button>
        <button type="button" class="rbtn rbtn-warning" onclick="Reports.openReport('monthly')">月报</button>
        <button type="button" class="rbtn rbtn-info" onclick="Reports.openReport('yearly')">年报</button>
    </div>
    <div class="report-actions-hint">📌 点击按钮，弹出「日历所选日期」对应周期的报告（无则可一键生成）</div>
</div>

<!-- Calendar (same structure/ids as home so calendar.js works unchanged) -->
<div class="calendar-container" id="calendarContainer">
    <div class="calendar-header">
        <h3>📅 日历</h3>
        <div class="calendar-nav">
            <button id="calPrev">◀</button>
            <span class="cal-month-label" id="calMonthLabel"></span>
            <button id="calNext">▶</button>
            <button id="calToday" style="margin-left:8px;">今天</button>
        </div>
    </div>
    <div class="calendar-grid" id="calendarGrid"></div>
</div>

<!-- Statistics -->
<div class="report-stats-section">
    <div class="report-stats-header">
        <h3>📈 工作统计 <span class="report-range-label" id="reportRangeLabel"></span></h3>
        <div class="period-selector" id="reportGranTabs">
            <button class="period-btn" data-gran="daily">日</button>
            <button class="period-btn" data-gran="weekly">周</button>
            <button class="period-btn active" data-gran="monthly">月</button>
            <button class="period-btn" data-gran="yearly">年</button>
        </div>
    </div>
    <div id="reportStatsBody"><div class="no-daily-data">加载中...</div></div>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
