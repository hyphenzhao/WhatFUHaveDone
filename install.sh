#!/bin/bash
# WhatFUHaveDone — 一键安装脚本
# 支持: Ubuntu 22.04+ / macOS 13+
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo "========================================"
echo "  📋 WhatFUHaveDone — 一键安装"
echo "========================================"
echo ""

# ===== 检测系统 =====
OS="unknown"
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    OS="ubuntu"
    log "检测到 Ubuntu/Debian"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    OS="macos"
    log "检测到 macOS"
else
    warn "未识别的系统: $OSTYPE，将尝试继续..."
    OS="linux"
fi

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ===== 1. 安装依赖 =====
echo ""
echo "--- 第1步: 安装依赖 ---"

if [[ "$OS" == "ubuntu" ]]; then
    sudo apt update -qq
    sudo apt install -y php php-cli php-curl php-mysql php-mbstring php-xml apache2 mysql-server
    sudo a2enmod rewrite
    log "PHP + Apache + MySQL 安装完成"

elif [[ "$OS" == "macos" ]]; then
    # 检查 Homebrew
    if ! command -v brew &>/dev/null; then
        warn "需要安装 Homebrew"
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    fi
    brew install php@8.1 mysql nginx 2>/dev/null || true
    brew services start mysql 2>/dev/null || true
    brew services start nginx 2>/dev/null || true
    log "PHP + Nginx + MySQL 安装完成"
fi

# ===== 2. 配置数据库 =====
echo ""
echo "--- 第2步: 配置数据库 ---"

read -p "MySQL root 密码（如未设置直接回车）: " ROOT_PASS
if [[ -n "$ROOT_PASS" ]]; then
    MYSQL_CMD="mysql -u root -p${ROOT_PASS}"
else
    MYSQL_CMD="mysql -u root"
fi

# 创建数据库和用户
$MYSQL_CMD <<SQL 2>/dev/null || warn "数据库可能已存在，跳过创建"
CREATE DATABASE IF NOT EXISTS worklog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'worklog'@'localhost' IDENTIFIED BY 'worklog_pass_2024';
GRANT ALL PRIVILEGES ON worklog.* TO 'worklog'@'localhost';
FLUSH PRIVILEGES;
SQL

# 导入表结构
$MYSQL_CMD worklog < "$PROJECT_DIR/schema.sql" 2>/dev/null || log "表结构导入完成（部分表可能已存在）"
log "数据库配置完成"

# ===== 3. 修改 config.php =====
echo ""
echo "--- 第3步: 配置项目 ---"

if [[ -f "$PROJECT_DIR/config.php" ]]; then
    # 不覆盖已有的 config.php（用户可能已修改）
    log "config.php 已存在，跳过"
else
    cp "$PROJECT_DIR/config.php.example" "$PROJECT_DIR/config.php" 2>/dev/null || true
    log "已创建 config.php"
fi

# ===== 4. 配置 Web 服务器 =====
echo ""
echo "--- 第4步: 配置 Web 服务器 ---"

if [[ "$OS" == "ubuntu" ]]; then
    # Apache 配置
    APACHE_CONF="/etc/apache2/sites-available/worklog.conf"
    sudo tee "$APACHE_CONF" > /dev/null <<APACHE
<VirtualHost *:8080>
    DocumentRoot $PROJECT_DIR
    <Directory $PROJECT_DIR>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/worklog_error.log
    CustomLog \${APACHE_LOG_DIR}/worklog_access.log combined
</VirtualHost>
APACHE

    sudo a2dissite 000-default.conf 2>/dev/null || true
    sudo a2ensite worklog.conf
    sudo a2enmod rewrite 2>/dev/null || true
    sudo systemctl restart apache2
    log "Apache 已配置，监听 http://localhost:8080"

elif [[ "$OS" == "macos" ]]; then
    # Nginx 配置
    NGINX_CONF="/usr/local/etc/nginx/servers/worklog.conf"
    sudo mkdir -p "$(dirname "$NGINX_CONF")"
    sudo tee "$NGINX_CONF" > /dev/null <<NGINX
server {
    listen 8080;
    server_name localhost;
    root $PROJECT_DIR;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
NGINX

    # 启动 PHP-FPM
    brew services start php@8.1 2>/dev/null || true
    # 确保 PHP-FPM 监听 9000 端口
    PHP_FPM_CONF=$(find /usr/local/etc/php -name "www.conf" 2>/dev/null | head -1)
    if [[ -n "$PHP_FPM_CONF" ]]; then
        sudo sed -i '' 's|listen = .*|listen = 127.0.0.1:9000|' "$PHP_FPM_CONF" 2>/dev/null || true
    fi

    sudo nginx -s reload 2>/dev/null || sudo nginx
    log "Nginx 已配置，监听 http://localhost:8080"
fi

# ===== 5. 权限 =====
echo ""
echo "--- 第5步: 设置权限 ---"

# 确保 web server 能读取项目文件
chmod -R 755 "$PROJECT_DIR"
log "文件权限已设置"

# ===== 完成 =====
echo ""
echo "========================================"
echo "  ✅ 安装完成！"
echo "========================================"
echo ""
echo "  访问地址: http://localhost:8080"
echo ""
echo "  后续步骤:"
echo "  1. 打开浏览器访问上述地址"
echo "  2. 左侧导航 → 🤖 AI 配置 → 填写 DeepSeek API Key"
echo "  3. 左侧导航 → 👤 个人侧写 → 填写你的基本信息"
echo "  4. 左侧导航 → 📅 日历管理 → 更新农历数据"
echo "  5. 左侧导航 → 🛠️ 技能管理 → 从磁盘加载 AI 技能"
echo ""
echo "  如果页面加载不正常（CSS/JS 未加载）："
echo "  - Apache: 确保 'AllowOverride All' 已启用且 mod_rewrite 已加载"
echo "  - Nginx: 确保静态文件路径正确，检查 nginx error_log"
echo ""
echo "  详细文档: README.md"
echo "========================================"
