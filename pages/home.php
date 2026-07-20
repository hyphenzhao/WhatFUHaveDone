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
        <a href="/immersive" class="btn btn-ghost btn-sm" style="margin-left:12px;" title="移动端友好的暗色模式">🕶️ 沉浸模式</a>
    </div>
    <div class="daily-body">
        <div class="daily-left-col" id="dailyLeftCol">
            <div class="daily-weather" id="dailyWeather">
                <div class="no-daily-data">加载中...</div>
            </div>
            <div class="daily-almanac" id="dailyAlmanac">
                <div class="no-daily-data">加载中...</div>
            </div>
            <div class="daily-liuri" id="dailyLiuri"></div>
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

<!-- BaZi Time Pillars -->
<div class="bazi-pillars-section" id="baziPillarsSection">
    <div class="bazi-pillars-header" onclick="document.getElementById('baziPillarsBody').classList.toggle('collapsed'); this.querySelector('.toggle-arrow').classList.toggle('rotated')">
        <span class="toggle-arrow">▼</span>
        <h3>🔮 八字大运流年</h3>
        <span id="baziPillarsDate" style="font-size:0.85rem;color:var(--color-text-secondary);margin-left:12px;"></span>
    </div>
    <div class="bazi-pillars-body" id="baziPillarsBody">
        <div class="bazi-pillars-grid" id="baziPillarsGrid">
            <div class="no-daily-data">请先在个人侧写中设置生辰八字</div>
        </div>
    </div>
</div>

<!-- Leaderboards -->
<div class="leaderboards">
    <div class="leaderboard-panel" id="workloadLeaderboard">
        <div class="leaderboard-header">
            <h3>💪 工作量排行</h3>
            <div class="period-selector" data-board="workload">
                <button class="period-btn active" data-period="all">全部</button>
                <button class="period-btn" data-period="year">年度</button>
                <button class="period-btn" data-period="month">月度</button>
                <button class="period-btn" data-period="week">周度</button>
            </div>
        </div>
        <div class="no-daily-data">加载中...</div>
    </div>
    <div class="leaderboard-panel" id="resultsLeaderboard">
        <div class="leaderboard-header">
            <h3>🏆 成果排行</h3>
            <div class="period-selector" data-board="results">
                <button class="period-btn active" data-period="all">全部</button>
                <button class="period-btn" data-period="year">年度</button>
                <button class="period-btn" data-period="month">月度</button>
                <button class="period-btn" data-period="week">周度</button>
            </div>
        </div>
        <div class="no-daily-data">加载中...</div>
    </div>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
