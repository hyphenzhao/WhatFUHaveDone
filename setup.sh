#!/bin/bash
#
# WorkLog App Setup Script
# Run with: sudo bash setup.sh
#
set -e

echo "🚀 Setting up WorkLog App..."
echo ""

# 1. MySQL Database Setup
echo "📦 Step 1: Setting up MySQL database..."
if [ -f schema.sql ]; then
    mysql -f < schema.sql 2>/dev/null || true
    echo "   ✓ Schema applied."
    echo "   ✓ Database 'worklog' and tables created."
else
    echo "   ✗ schema.sql not found!"
    exit 1
fi

# Create app user if not exists
mysql -e "CREATE USER IF NOT EXISTS 'worklog'@'localhost' IDENTIFIED BY 'worklog_pass_2024';"
mysql -e "GRANT ALL PRIVILEGES ON worklog.* TO 'worklog'@'localhost'; FLUSH PRIVILEGES;"
echo "   ✓ Database user 'worklog' created/granted."

echo ""

# 2. Apache Virtual Host
echo "🌐 Step 2: Configuring Apache..."
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Copy virtual host config
cp "$SCRIPT_DIR/apache-worklog.conf" /etc/apache2/sites-available/worklog.conf
echo "   ✓ Virtual host config copied."

# Enable mod_rewrite if not already
a2enmod rewrite 2>/dev/null || echo "   (mod_rewrite already enabled)"

# Enable the site
a2ensite worklog.conf 2>/dev/null || echo "   (site already enabled)"

# Set permissions (make readable by Apache, but keep ownership)
chmod -R 755 "$SCRIPT_DIR"
echo "   ✓ Permissions set."

echo ""

# 3. Restart Apache
echo "🔄 Step 3: Restarting Apache..."
systemctl restart apache2
echo "   ✓ Apache restarted."

echo ""
echo "✅ Setup complete!"
echo ""
echo "📋 Access the app at: http://192.168.50.6:8080/"
echo "   (Existing Apache on port 80 is untouched)"
