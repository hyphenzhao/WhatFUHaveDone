#!/bin/zsh

cd -- "${0:A:h}" || exit 1

PHP_BIN="/Applications/MAMP/bin/php/php/bin/php"
UPLOAD_TMP="/private/tmp/whatfuhavedone-uploads"

if [[ ! -x "$PHP_BIN" ]]; then
  echo "找不到 MAMP PHP，请确认 MAMP 已安装并选择了 PHP 8.x。"
  read -r "?按回车键关闭..."
  exit 1
fi

echo "WhatFUHaveDone 已启动："
echo "http://127.0.0.1:8080"
echo
echo "保持此窗口开启；按 Control-C 停止网站。"

mkdir -p "$UPLOAD_TMP"
export PHP_CLI_SERVER_WORKERS=4
exec "$PHP_BIN" \
  -d "upload_tmp_dir=$UPLOAD_TMP" \
  -d display_errors=0 \
  -S 127.0.0.1:8080 router.php
