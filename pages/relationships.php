<?php
$page_title = '人际关系';
$current_page = 'relationships';
$page_content = <<<'HTML'
<div class="relationship-page">
    <header class="rg-heading">
        <div><div class="rg-eyebrow">合作与投入</div><h2>人际关系图</h2><p>从共同的工作记录，看看你与谁保持着联系。</p></div>
        <div class="rg-summary" id="graphSummary" aria-live="polite">正在读取关系数据…</div>
    </header>
    <section class="rg-toolbar" aria-label="关系图筛选与指标">
        <label class="rg-search"><span aria-hidden="true">⌕</span><input id="graphSearch" type="search" placeholder="搜索姓名或关系" aria-label="搜索姓名或关系" autocomplete="off"></label>
        <select id="graphRelation" aria-label="筛选关系"><option value="">全部关系</option></select>
        <div class="rg-modes" role="group" aria-label="节点大小指标">
            <button type="button" id="toggleWorkload" class="active" aria-pressed="true">💪 工作量</button>
            <button type="button" id="toggleResults" aria-pressed="false">🏆 成果</button>
        </div>
        <button type="button" class="rg-icon-button" id="graphRefresh" aria-label="刷新关系数据" title="刷新关系数据">↻</button>
    </section>
    <div class="rg-board">
        <section class="rg-canvas-card" aria-label="可交互的人际关系图">
            <div class="rg-stage" id="graphStage">
                <svg id="graphSvg" class="rg-svg" role="group" aria-label="关系网络，人物节点可用 Tab 选择，按 Enter 查看详情"></svg>
                <div id="graphEmpty" class="rg-empty" hidden></div>
                <div class="rg-canvas-top"><span class="rg-status" id="graphStatus" role="status">准备布局</span><button type="button" class="rg-canvas-button" id="graphMotion" aria-pressed="true">暂停动态</button></div>
                <div class="rg-canvas-controls" role="group" aria-label="关系图视图操作">
                    <button type="button" id="graphZoomOut" aria-label="缩小关系图" title="缩小">−</button>
                    <span id="graphZoomLabel" aria-live="polite">100%</span>
                    <button type="button" id="graphZoomIn" aria-label="放大关系图" title="放大">＋</button>
                    <button type="button" id="graphFit" title="适应画面">适应</button>
                    <button type="button" id="graphReset" title="重新展开节点">重排</button>
                </div>
                <div id="graphTooltip" class="rg-tooltip" hidden></div>
            </div>
            <div class="rg-legend" id="graphLegend"></div>
            <p class="rg-help">拖动人物调整位置，拖动空白处移动画面；滚轮或双指缩放，点击人物查看详情。节点大小表示所选指标，颜色区分关系标签。</p>
        </section>
        <aside class="rg-sidebar" aria-label="人物详情与列表">
            <section class="rg-detail-card" id="graphDetail" aria-live="polite"></section>
            <section class="rg-list-card"><div class="rg-list-heading"><h3>人物列表</h3><span id="graphListCount"></span></div><div id="graphPeopleList" class="rg-people-list"></div></section>
        </aside>
    </div>
    <p class="rg-data-note">连线来自任务中的人物关联；工作量按工作记录次数、成果按成果记录条数计算，包含已归档任务的历史记录。同一记录可能涉及多人，各人物的数量不可直接相加为个人总量。</p>
</div>
HTML;

require __DIR__ . '/../components/layout.php';
