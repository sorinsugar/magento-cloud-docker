FROM nginx:1.9

COPY etc/nginx.conf /etc/nginx/
COPY etc/vhost.conf /etc/nginx/conf.d/default.conf
COPY etc/certs/ /etc/nginx/ssl/
COPY bin/* /usr/local/bin/

RUN touch /var/run/nginx.pid

EXPOSE 80 443

ENV UPLOAD_MAX_FILESIZE 64M
ENV FPM_HOST fpm
ENV FPM_PORT 9000
ENV MAGENTO_ROOT /app
ENV MAGENTO_RUN_MODE production
ENV DEBUG false

RUN ["chmod", "+x", "/usr/local/bin/docker-environment"]

RUN groupadd -g 1000 www && useradd -g 1000 -u 1000 -d ${MAGENTO_ROOT} -s /bin/bash www
RUN chown -R www:www /etc/nginx /var/cache/nginx /var/run/nginx.pid

ENTRYPOINT ["/usr/local/bin/docker-environment"]

WORKDIR ${MAGENTO_ROOT}

CMD ["nginx", "-g", "daemon off;"]
