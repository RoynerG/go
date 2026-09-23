# Mercado Libre Colombia

## Activacion

1. Ejecutar `git pull` en `portales-go` e incorporar al `.env` del servidor las
   variables `MERCADOLIBRE_*` de `.env.example`. No reemplazar las credenciales
   existentes de los otros portales.
2. En la aplicacion de Mercado Libre agregar este Redirect URI exacto:
   `https://gocartagenarealestate.com/portales-go/public/panel/mercadolibre/callback`.
   Se puede conservar el URI del panel anterior si todavia se utiliza; esta
   integracion utiliza el nuevo. No modifica los redirects de WordPress.
3. Activar Authorization Code, Refresh Token y PKCE (S256). Para esta integracion
   habilitar Mercado Libre / VIS, Usuarios y Publicacion y sincronizacion con
   lectura y escritura. No necesita Client Credentials, ventas, envios ni mensajes.
4. Completar `MERCADOLIBRE_CLIENT_ID`, `MERCADOLIBRE_CLIENT_SECRET`, el redirect,
   nombre, correo, telefono y WhatsApp. Los telefonos deben tener los 10 digitos
   nacionales colombianos; tambien se acepta el prefijo +57.
5. Generar `MERCADOLIBRE_TOKEN_KEY` una sola vez con PHP:

   ```bash
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```

   Guardar ese valor en `.env` y conservarlo. Cifra los access/refresh tokens en
   la base de datos. No se muestran en el panel ni se guardan en Git o logs.
6. Abrir Panel de propiedades, seleccionar Mercado Libre y pulsar Conectar
   cuenta. Autorizar con el administrador de la cuenta colombiana.
7. Consultar cupos en el panel. Revisar el paquete activo real antes de habilitar
   `MERCADOLIBRE_ENABLED=true`. La primera ejecucion encolara los inmuebles
   disponibles. Se puede ejecutar primero el modo de inspeccion:

   ```bash
   php cron/mercadolibre-sync.php --dry-run
   ```

El modo de inspeccion muestra cuantos se publicarian, actualizarian o pausarian.
No llama a Mercado Libre ni modifica anuncios o colas; crea las tablas de soporte
si todavia no existen, igual que el panel.

## Cron de Hostinger

Cada minuto (`* * * * *`), usar una de estas opciones:

```bash
/usr/bin/php /home/u427850203/domains/gocartagenarealestate.com/public_html/portales-go/cron/mercadolibre-sync.php
```

O mantener `cron/portales-sync.php`, que ahora incluye Mercado Libre cuando esta
habilitado. No hace falta programar ambos. Tambien existe
`POST /cron/mercadolibre-sync` con el header `X-Api-Key: API_SHARED_SECRET`.

El worker tiene un bloqueo de base de datos: dos cron simultaneos no procesan el
mismo lote. El refresh token tambien se renueva bajo bloqueo porque es de un uso.

## Comportamiento

- Disponible y sin anuncio: publicar, enviando fotos, contacto y atributos.
- Datos modificados: actualizar solo si cambia el contenido relevante. Una
  actualizacion del estado de otro portal no vuelve a publicar el inmueble.
- No disponible o registro fuente retirado: pausar el anuncio. Un estado
  desconocido no se considera publicable.
- Disponible nuevamente: actualizar datos y reactivar el anuncio pausado.
- Despublicar manualmente: mantener pausado aunque el inmueble siga disponible.
  Publicar manualmente vuelve a habilitar el seguimiento automatico.
- Eliminar definitivamente: cerrar y eliminar en Mercado Libre, con confirmacion
  en el panel. El cron conserva esta decision y no lo vuelve a publicar.
- Un anuncio ya cerrado requiere revisar una republicacion; no se crea otro
  automaticamente.

El estado publicado se confirma consultando el item remoto, no solo por el envio
de la solicitud. Los errores se muestran por inmueble, en Monitor, Estados y
errores y Logs. El panel de Mercado Libre refresca sus estados cada 15 segundos.
Hay hasta cinco intentos con espera progresiva; una accion manual permite
reintentar. Una creacion de resultado incierto se busca por codigo antes de
continuar y nunca se repite ciegamente.

## Paquete mixto

El usuario reporto un plan de 150 inmuebles: 105 Plata, 30 Oro y 15 Acelerador Plus,
vigente del 21/08/2026 al 19/11/2026. Estos datos no reemplazan la respuesta de la
API: se consultan paquetes inmobiliarios activos y `listing_details` para conocer
el `listing_type_id` y los cupos disponibles. No se asume que un nombre comercial
como Acelerador Plus equivale a un tipo especifico.

Los anuncios nuevos usan primero `silver`, luego `gold` y luego `gold_premium`,
solo si la API devuelve cupo para ese tipo. Las actualizaciones conservan el
tipo del anuncio existente. No se contratan paquetes ni se hacen upgrades.
Cuando se agotan los cupos, las publicaciones esperan 15 minutos antes de volver
a comprobar; la falta de cupo no consume los cinco intentos de error.

## Homologacion

La integracion recorre las categorias oficiales MCO por tipo de inmueble y
venta/arriendo, consulta atributos y utiliza ubicaciones de `classified_locations`.
Las coincidencias deben ser exactas; no se inventan barrios o categorias.
Si falta un cruce, se muestra el nombre faltante en el error del inmueble.

Se pueden configurar excepciones como JSON en `.env`:

```dotenv
MERCADOLIBRE_CATEGORY_MAP={"apartamento:sale":"ID_MCO_REAL","apartamento:rent":"ID_MCO_REAL"}
MERCADOLIBRE_LOCATION_MAP={"morros":"ID_BARRIO_REAL_DE_ZONA_NORTE"}
```

Los IDs del ejemplo son marcadores, no valores para copiar a produccion. Verificar
los IDs en la API de Mercado Libre. Un ID de Finca Raiz no sirve aqui. Morros puede
homologarse al barrio oficial de Zona Norte solo si pertenece a la ciudad correcta.
No se deduce el numero de ambientes a partir de habitaciones; los atributos
obligatorios sin un campo fuente generan un error para completar los datos.
El payload de WordPress puede incluir `ambientes` (o `rooms`) y un objeto
`mercadolibre_attributes` con valores por ID, por ejemplo `{"ROOMS":3}`.
Solo se envian los IDs que existan en el catalogo de atributos de esa categoria.

## Verificacion sin publicar

```bash
php tests/mercadolibre.php
php cron/mercadolibre-sync.php --dry-run
```

La prueba final real requiere credenciales, autorizacion y un inmueble con todos
los datos homologados. Tras conectar, probar un anuncio desde el panel y comprobar
sus fotos, ubicacion y estado en Mercado Libre antes de ejecutar el lote completo.

Documentacion consultada:

- https://developers.mercadolibre.com.co/es_ar/autenticacion-y-autorizacion
- https://developers.mercadolibre.com.co/es_ar/publica-inmueble
- https://developers.mercadolibre.com.co/es_ar/actualiza-tus-publicaciones
- https://developers.mercadolibre.com.co/es_ar/categorias-inmuebles
- https://developers.mercadolibre.com.co/es_ar/localizar-inmuebles
- https://developers.mercadolibre.com.co/es_ar/gestionar-paquetes-de-inmuebles
