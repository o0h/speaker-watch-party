FROM dunglas/frankenphp:1.12.1-php8.5

# dataディレクトリを作成（PDFの保存先）
RUN mkdir -p /app/data && \
    echo "upload_max_filesize=64M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size=64M" >> /usr/local/etc/php/conf.d/uploads.ini

COPY . /app
WORKDIR /app

# Caddyfileをデフォルト位置に配置
COPY .runtime/Caddyfile /etc/frankenphp/Caddyfile
