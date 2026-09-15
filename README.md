# Portales Go

Panel MVC para sincronizar inmuebles de WordPress con Proppit y Finca Raiz.

## Configuracion

1. Copia `.env.example` como `.env`.
2. Completa las credenciales de base de datos, Proppit, Finca Raiz y `API_SHARED_SECRET`.
3. En WordPress define las constantes antes de cargar el plugin:

```php
define('PG_MVC_URL', 'https://gocartagenarealestate.com/portales-go/public');
define('PG_MVC_SECRET', 'mismo-valor-de-API_SHARED_SECRET');
```

El archivo `.env` no se sube al repositorio por seguridad.

## Cron

Procesar todos los portales:

```bash
/usr/bin/php /home/u427850203/domains/gocartagenarealestate.com/public_html/portales-go/cron/portales-sync.php
```

Procesar solo Proppit:

```bash
/usr/bin/php /home/u427850203/domains/gocartagenarealestate.com/public_html/portales-go/cron/proppit-sync.php
```

Procesar solo Finca Raiz:

```bash
/usr/bin/php /home/u427850203/domains/gocartagenarealestate.com/public_html/portales-go/cron/fincaraiz-sync.php
```

Reencolar fotos de Finca Raiz:

```bash
/usr/bin/php /home/u427850203/domains/gocartagenarealestate.com/public_html/portales-go/tools/queue-fincaraiz-photo-refresh.php 250
```
