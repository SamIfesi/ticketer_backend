FROM php:8.2-apache

# System dependencies 
RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    git \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libiconv-hook-dev \
    # Chromium and all its shared library dependencies
    # NOTE: chromium-sandbox is bundled inside the chromium package on Debian;
    # listing it separately causes an apt "unable to locate package" error.
    chromium \
    fonts-liberation \
    fonts-noto \
    fonts-noto-color-emoji \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libxss1 \
    libxtst6 \
    xdg-utils \
    cron \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions UN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        xml \
        zip \
        gd \
        opcache \
        bcmath \
        iconv \
        fileinfo

# Redis extension (for the token-version cache)
# Not one of the bundled ext- packages, so it's installed via PECL.
RUN pecl install redis \
    && docker-php-ext-enable redis

# Node.js 20.x 
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Runtime env vars (must be set BEFORE installing global npm
# packages, otherwise `node -e "require('puppeteer')"` below can't
# resolve the module — NODE_PATH has to exist at install/verify time)
ENV CHROMIUM_PATH=/usr/bin/chromium \
    NODE_PATH=/usr/lib/node_modules \
    NPM_PATH=/usr/bin/npm \
    APACHE_DOCUMENT_ROOT=/var/www/html

# Tell Puppeteer to use system Chromium, not download its own
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# Install Puppeteer globally
# Pinned to 21.x to match what browsershot.js was written against.
# npm -g on the nodesource Node 20 image installs to /usr/lib/node_modules.
RUN npm install -g puppeteer@21.0.0 \
    && npm cache clean --force

# Verify Puppeteer resolves correctly at build time─
RUN node -e "require('puppeteer'); console.log('puppeteer OK');"

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Apache config
# Use a2dismod (not raw rm -f) so Apache's own module bookkeeping stays
# consistent — rm -f only deletes symlinks and can miss files depending
# on Debian version. The `; true` lets this succeed even if a given
# MPM was never enabled to begin with.
#
# The MPM_COUNT check makes the BUILD ITSELF fail if more than one MPM
# ends up enabled, instead of deploying successfully and crashing at
# runtime with AH00534. Catch it here, not in production logs.
RUN a2dismod mpm_event mpm_worker 2>/dev/null; true \
    && a2enmod mpm_prefork rewrite \
    && MPM_COUNT=$(apache2ctl -M 2>/dev/null | grep -c mpm_) \
    && echo "Enabled MPM count: $MPM_COUNT" \
    && if [ "$MPM_COUNT" -ne 1 ]; then \
         echo "FATAL: expected exactly 1 MPM, found $MPM_COUNT" >&2; \
         apache2ctl -M; \
         exit 1; \
       fi

RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# PHP config
RUN echo "upload_max_filesize = 32M"          >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 32M"             >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "memory_limit = 512M"             >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 120"        >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "opcache.enable=1"                >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "opcache.memory_consumption=128"  >> /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

# Cache-friendly Composer install
# Copy only the dependency manifest files first so that composer install
# is only re-run when composer.json or composer.lock actually changes.
# A CSS edit will no longer bust this layer.
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# Now copy the rest of the application
COPY . .

# Make browsershot.js executable
RUN chmod +x /var/www/html/browsershot.js

# Storage directories─
# Banners live on Cloudinary, so no local storage/banners needed.
RUN mkdir -p \
    storage/tickets \
    storage/qrcodes \
    storage/logs

# Set base permissions first, then storage permissions last
# Global 755 is applied to everything, then storage gets 775 overridden
# on top so Apache/worker processes can write files. Order matters.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 storage \
    && chown -R www-data:www-data storage

# Cron workers
# Copy the crontab into /etc/cron.d/ (system-wide cron drop-in location).
# The entrypoint script starts cron as a background daemon before
# handing control to apache2-foreground.
COPY docker/crontab /etc/cron.d/ticketer-workers
RUN chmod 0644 /etc/cron.d/ticketer-workers

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]