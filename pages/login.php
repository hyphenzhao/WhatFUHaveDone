<?php
/**
 * Login page — standalone skeleton (does not use layout.php)
 */
if (!empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        .login-card {
            width: 360px;
            max-width: calc(100vw - 32px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 32px 28px;
        }
        .login-logo { text-align: center; font-size: 1.4rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .login-sub { text-align: center; font-size: 0.85rem; color: #64748b; margin-bottom: 24px; }
        .login-field { margin-bottom: 14px; }
        .login-field label { display: block; font-size: 0.85rem; color: #475569; margin-bottom: 4px; }
        .login-field input[type=text], .login-field input[type=password] {
            width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 0.95rem; outline: none; transition: border-color 0.2s;
        }
        .login-field input:focus { border-color: #3B82F6; }
        .login-remember { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #475569; margin: 14px 0 18px; cursor: pointer; }
        .login-btn {
            width: 100%; padding: 11px; border: none; border-radius: 8px;
            background: #3B82F6; color: #fff; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: background 0.2s;
        }
        .login-btn:hover { background: #2563eb; }
        .login-btn:disabled { background: #93c5fd; cursor: default; }
        .login-error { color: #ef4444; font-size: 0.85rem; text-align: center; min-height: 20px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <form class="login-card" id="loginForm">
        <div class="login-logo">📋 <?= APP_NAME ?></div>
        <div class="login-sub">请登录后使用</div>
        <div class="login-field">
            <label for="username">用户名</label>
            <input type="text" id="username" name="username" autocomplete="username" autofocus required>
        </div>
        <div class="login-field">
            <label for="password">密码</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <label class="login-remember">
            <input type="checkbox" id="remember" checked> 30 天内免登录
        </label>
        <div class="login-error" id="loginError"></div>
        <button type="submit" class="login-btn" id="loginBtn">登 录</button>
    </form>
    <script>
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const errEl = document.getElementById('loginError');
        errEl.textContent = '';
        btn.disabled = true;
        try {
            const res = await fetch('/api/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: document.getElementById('username').value.trim(),
                    password: document.getElementById('password').value,
                    remember: document.getElementById('remember').checked,
                }),
            });
            const data = await res.json();
            if (!res.ok || data.error) {
                throw new Error(data.message || '登录失败');
            }
            location.href = '/';
        } catch (err) {
            errEl.textContent = err.message || '登录失败';
            btn.disabled = false;
        }
    });
    </script>
</body>
</html>
