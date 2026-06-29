<?php
$page_title = '沉浸模式';
$current_page = 'immersive';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>🕶️ 沉浸模式 — WorkLog</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/immersive.css">
</head>
<body>
    <div id="imApp"></div>

    <!-- Modal overlay -->
    <div class="modal-overlay" id="modalOverlay" style="display:none;">
        <div class="modal-container" id="modalContainer">
            <div class="modal-header"><h3 id="modalTitle"></h3><button class="modal-close" id="modalClose">&times;</button></div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>
    <div class="toast-container" id="toastContainer"></div>

    <script src="/assets/js/lunar.js"></script>
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/modal.js"></script>
    <script src="/assets/js/app.js"></script>
    <script src="/assets/js/calendar.js"></script>
    <script src="/assets/js/ai-assistant.js"></script>
    <script src="/assets/js/home.js"></script>
    <script src="/assets/js/immersive.js"></script>
</body>
</html>
