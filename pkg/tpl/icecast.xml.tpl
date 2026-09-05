<icecast>
    <location>Costa Rica</location>
    <admin>{{ADMIN_EMAIL}}</admin>
    <hostname>{{DOMAIN}}</hostname>

    <!-- Límites del Servidor -->
    <limits>
        <clients>1500</clients>
        <sources>50</sources>
        <queue-size>524288</queue-size>
        <client-timeout>30</client-timeout>
        <header-timeout>15</header-timeout>
        <source-timeout>10</source-timeout>
        <burst-on-connect>1</burst-on-connect>
        <burst-size>65535</burst-size>
    </limits>

    <!-- Credenciales Globales (generadas por install.sh) -->
    <authentication>
        <source-password>{{SOURCE_PASS}}</source-password>
        <relay-password>{{RELAY_PASS}}</relay-password>
        <admin-user>admin</admin-user>
        <admin-password>{{ADMIN_PASS}}</admin-password>
    </authentication>

    <!-- Autenticación Dinámica para todos los Mountpoints -->
    <mount type="default">
        <mount-name>/*</mount-name>
        <authentication type="url">
            <option name="stream_auth" value="http://127.0.0.1/auth.php"/>
            <option name="auth_header" value="icecast-auth-user: 1"/>
        </authentication>
    </mount>

    <!-- Configuración de Red -->
    <listen-socket>
        <port>8000</port>
        <bind-address>0.0.0.0</bind-address>
    </listen-socket>

    <http-headers>
        <header name="Access-Control-Allow-Origin" value="*" />
    </http-headers>

    <!-- Rutas del Sistema -->
    <paths>
        <basedir>/usr/share/icecast2</basedir>
        <logdir>/var/log/icecast2</logdir>
        <webroot>/usr/share/icecast2/web</webroot>
        <adminroot>/usr/share/icecast2/admin</adminroot>
        <alias source="/" destination="/status.xsl"/>
    </paths>

    <!-- Registro de Logs -->
    <logging>
        <accesslog>access.log</accesslog>
        <errorlog>error.log</errorlog>
        <loglevel>3</loglevel>
        <logsize>10000</logsize>
    </logging>

    <!-- Seguridad y Usuario de Sistema -->
    <security>
        <chroot>0</chroot>
        <changeowner>
            <user>icecast2</user>
            <group>icecast</group>
        </changeowner>
    </security>
</icecast>
