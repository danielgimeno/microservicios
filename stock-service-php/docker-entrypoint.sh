#!/bin/sh
set -eu

# El healthcheck de Docker pega a /health desde 127.0.0.1 cada pocos segundos.
# El servidor embebido de PHP lo registra como Accepted/Closing y ensucia el log.
php -S 0.0.0.0:8081 -t public public/router.php 2>&1 \
  | grep --line-buffered -vE '127\.0\.0\.1:[0-9]+ (Accepted|Closing)'
