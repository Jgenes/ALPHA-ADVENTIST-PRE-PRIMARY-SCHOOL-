#!/bin/sh
set -eu
# Render's disk overlays the directory created in the image.
if [ "${DATA_DIR:-/var/data}" = /var/data ]; then
  mkdir -p /var/data
  chown www-data:www-data /var/data
fi
# Validate configuration/schema as the same user that handles web requests.
runuser -u www-data -- php -r 'require "src/php/bootstrap.php"; $c=configuration(); $p=new Platform(new Store($c),$c,new Files($c)); $p->init();'
runuser -u www-data -- php scripts/worker.php &
exec apache2-foreground
