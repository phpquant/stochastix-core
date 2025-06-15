FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libgmp-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
        bcmath \
        gmp \
    && pecl install \
		xdebug \
        trader \
        ds \
    && docker-php-ext-enable \
		xdebug \
        bcmath \
        gmp \
        trader \
        ds \
    && docker-php-source delete

RUN git config --global --add safe.directory /app

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD [ "php", "-v" ]
