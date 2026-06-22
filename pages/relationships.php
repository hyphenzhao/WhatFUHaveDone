<?php
/**
 * Relationship Visualization Page
 */
$page_title = '人际关系可视化';
$current_page = 'relationships';
$page_content = <<<HTML
<div class="graph-container">
    <div class="graph-header">
        <h2>🔗 人际关系图</h2>
        <div class="graph-toggle">
            <button id="toggleWorkload" class="active" onclick="Graph.switchMode('workload')">💪 按工作量</button>
            <button id="toggleResults" onclick="Graph.switchMode('results')">🏆 按成果</button>
        </div>
    </div>
    <svg class="graph-svg" id="graphSvg" viewBox="0 0 800 500"></svg>
</div>
<div id="graphTooltip" class="graph-tooltip" style="display:none;"></div>
HTML;

require __DIR__ . '/../components/layout.php';
