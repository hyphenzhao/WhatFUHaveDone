FROM php:8.1-apache

# 安装依赖
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    poppler-utils \
    && docker-php-ext-install curl mysqli pdo pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# 设置工作目录
WORKDIR /var/www/html

# 复制代码
COPY . /var/www/html/

# 创建数据目录
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html

# Apache 配置：允许 .htaccess override + 改端口为 8081
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && sed -i 's/Listen 80/Listen 8081/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8081>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 8081
