<?php
$page_title = '沉浸模式';
$current_page = 'immersive';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>🕶️ 沉浸模式 — WorkLog</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= ASSET_VER ?>">
    <link rel="stylesheet" href="/assets/css/mail.css?v=<?= ASSET_VER ?>">
    <link rel="stylesheet" href="/assets/css/immersive.css?v=<?= ASSET_VER ?>">
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

    <script src="/assets/js/lunar.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/api.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/modal.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/app.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/calendar.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/ai-assistant.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/mail-modal.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/home.js?v=<?= ASSET_VER ?>"></script>
    <script src="/assets/js/immersive.js?v=<?= ASSET_VER ?>"></script>
</body>
</html>
