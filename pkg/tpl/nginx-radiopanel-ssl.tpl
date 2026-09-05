# SuperRadio · radiopanel — sitio nginx (SSL / producción)
# Servidor interno exclusivo para peticiones de Icecast (auth.php) en 127.0.0.1:80
server {
    listen 127.0.0.1:80;
    server_name localhost 127.0.0.1;
    root {{APP_DIR}};

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{{PHP_SOCKET}};
        fastcgi_param PHP_VALUE "upload_max_filesize=500M \n post_max_size=500M \n memory_limit=512M \n max_execution_time=300 \n max_input_time=300";
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_connect_timeout 300;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}

# Servidor público HTTPS para el panel y los oyentes
server {
    server_name {{DOMAIN}};
    root {{APP_DIR}};
    index index.php index.html;

    # Bloquear lectura directa de archivos .json
    location ~* \.json$ {
        deny all;
        return 404;
    }

    # Proxy dinámico para todos los streams hacia Icecast
    location ~ ^/([a-zA-Z0-9_-]+)$ {
        proxy_pass http://127.0.0.1:8000/$1;
        proxy_set_header Host $host;
        proxy_buffering off;
        tcp_nodelay on;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{{PHP_SOCKET}};
    }

    listen 443 ssl;
    client_max_body_size 200M;
    ssl_certificate /etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{{DOMAIN}}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}

# Redirección HTTP -> HTTPS para usuarios en la web
server {
    listen 80;
    server_name {{DOMAIN}};
    return 301 https://$host$request_uri;
}
