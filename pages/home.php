<?php
/**
 * Home Page — Main Dashboard
 */
$page_title = '主页';
$current_page = 'home';
$page_content = <<<HTML
<!-- Daily Status: 黄历 + task list -->
<div class="daily-status">
    <div class="daily-status-header">
        <h2>📋 当日状态</h2>
        <span class="date-display" id="dailyDateDisplay"></span>
    </div>
    <div class="daily-body">
        <div class="daily-almanac" id="dailyAlmanac">
            <div class="no-daily-data">加载中...</div>
        </div>
        <div class="daily-cards" id="dailyStatusCards">
            <div class="no-daily-data">加载中...</div>
        </div>
    </div>
</div>

<!-- Calendar -->
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

<!-- Leaderboards -->
<div class="leaderboards">
    <div class="leaderboard-panel" id="workloadLeaderboard">
        <h3>💪 工作量排行</h3>
        <div class="no-daily-data">加载中...</div>
    </div>
    <div class="leaderboard-panel" id="resultsLeaderboard">
        <h3>🏆 成果排行</h3>
        <div class="no-daily-data">加载中...</div>
    </div>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
