FROM kimbtechnologies/php_nginx:latest 

# php redis support
RUN apk add --update --no-cache $PHPIZE_DEPS \
	&& pecl install redis \
	&& docker-php-ext-enable redis

# SVG -> PNG convert
RUN apk add --update --no-cache rsvg-convert

# fork: ffmpeg for relay.php (HLS / WAV -> MP3), and more PHP workers since each relayed listener holds one
RUN apk add --update --no-cache ffmpeg \
	&& printf '[www]\npm.max_children = 12\npm.max_spare_servers = 4\n' > /usr/local/etc/php-fpm.d/zz-fork.conf

# copy php files, nginx conf and startup scripts
COPY --chown=www-data:www-data ./php/ /php-code/
COPY ./utils/nginx.conf /etc/nginx/more-server-conf.conf 
COPY ./utils/startup.php /utils/startup-before.sh ./utils/cron.php  /

# backup default data dir
RUN mkdir /data-dir-default/ \
	&& cp -r /php-code/data/* /data-dir-default \
	&& mkdir /media-dir-default/ \
	&& cp -r /php-code/media/* /media-dir-default

ENV DOCKER_MODE=true