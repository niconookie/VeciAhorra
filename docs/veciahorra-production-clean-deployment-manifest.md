# Manifiesto de despliegue limpio de VeciAhorra

Fecha de auditoría: 26 de agosto de 2026. Destino: `https://veciahorra.cl`. Baseline auditado: rama `main`, commit `f4da155f2486db251867959ea319f0002efecc0b`, igual a `origin/main`, divergencia `0/0`.

## 1. Alcance, autoridad y criterio de salida

Este documento prescribe una instalación limpia. No autoriza clonar la base local ni copiar `wp-config.php`, usuarios, pedidos, pagos, comercios, productos de ensayo o archivos runtime. La fuente de código de VeciAhorra es el commit indicado; la fuente de contenido es el inventario selectivo de este documento; la fuente de secretos es exclusivamente el almacén productivo administrado fuera de Git.

El hosting informado es apto en principio: cPanel, LiteSpeed, PHP 8.2.33, MariaDB 10.11, `mysqli/mysqlnd`, GD con JPEG/PNG/WebP/AVIF, ZIP, 512 MiB de memoria y upload, 3600 s, `max_input_vars=10000`, SSL y respaldos. Antes del go-live se debe confirmar desde producción, no asumirlo a partir de la ficha.

Condiciones de go/no-go:

- WordPress, tema, plugins y contenidos se instalan según las listas cerradas siguientes.
- La base comienza vacía de negocio y termina con `veciahorra_db_version=0.32.0` y las 33 tablas `*_va_*` observadas en instalación limpia. La cifra histórica 32 era incorrecta; no se elimina una tabla productiva para satisfacerla.
- `WP_ENVIRONMENT_TYPE=production`; registro y comercio permanecen en `false` hasta la ventana autorizada.
- No queda ninguna URL `localhost`, ID E2E, credencial, fixture ni producto demo.
- HTTPS, REST, correo, cron, Action Scheduler, Webpay y las rutas por rol superan smoke tests.
- El catálogo se identifica mediante `body.veciahorra-catalog-page`, clase añadida por VeciAhorra cuando la página singular contiene el shortcode canónico de catálogo sin `product_id`. No depende de ID ni slug y una prueba adversarial cubre IDs alternativos.

## 2. WordPress observado y configuración objetivo

| Propiedad | Local observado | Producción requerida |
|---|---|---|
| WordPress | 7.1; DB version 61833 | Misma versión estable verificada y compatible antes de importar |
| Idioma | `es_CL` | `es_CL` |
| Zona horaria | `America/Santiago` | `America/Santiago`, única autoridad; no configurar ni validar contra un `gmt_offset` fijo |
| Enlaces permanentes | `/%postname%/` | `/%postname%/`; regenerar reglas una vez |
| Portada | página estática, ID local 88, slug `inicio` | página publicada slug `inicio`; asignarla por slug, no por ID |
| `home`/`siteurl` | `https://localhost/Minimarket` | ambos exactamente `https://veciahorra.cl`, sin subdirectorio |
| Multisite | no hay evidencia de multisite | instalación single-site; no activar multisite |
| Cron | WP-Cron y Action Scheduler presentes | cron real de cPanel recomendado; si se usa, definir `DISABLE_WP_CRON=true` sólo después de instalar el job |
| Escritura | Windows local, no portable | directorios 0755, archivos 0644; `wp-content/uploads` y caché escribibles por PHP; código no escribible por el usuario web cuando cPanel lo permita |

No se observaron `site_icon` configurado (`0`). Hay 22 acciones Action Scheduler pendientes locales; no se transfieren. Los hooks observados incluyen cola de Action Scheduler, WooCommerce, Elementor, ACF, WPForms, Duplicator y LiteSpeed; producción debe generar su propia cola.

## 3. Tema y presentación

Tema obligatorio: Blocksy 2.1.44, tema padre y activo (`template=stylesheet=blocksy`). No existe child theme activo. Blocksy debe instalarse desde su distribución oficial con esa versión exacta o una actualización probada previamente.

La configuración Blocksy no vive en el repositorio: está serializada en `theme_mods_blocksy`. Incluye menú desktop y móvil, logo local attachment 314 y CSS personalizado post local 523. Debe exportarse de forma selectiva, aplicar reemplazo serializado de origen y reasignar logo/menús por identidad; nunca copiar IDs a ciegas. El CSS personalizado debe revisarse, versionarse en el paquete de contenido o recrearse manualmente y comprobarse contra la cabecera aprobada.

Elementor es necesario: Inicio y Nosotros usan widgets core y Contacto usa su widget WPForms; Inicio usa plantilla `elementor_header_footer`. La sustitución focal del hero ya no depende del widget `df4acd5`: se limita a portada, widget `image` y URL legada conocida, que se sustituye por el activo oficial del plugin. El inventario vigente contiene sólo `text-editor`, `heading`, `shortcode`, `image` y `wpforms`; Premium Addons no es dependencia y no se instala.

No se detectó un child theme ni una autoridad de CSS/JS de aplicación fuera del plugin. Sí existen `theme_mods_blocksy`, CSS personalizado y datos Elementor en base: son contenido administrado y deben viajar por exportación selectiva.

## 4. Listas cerradas de plugins

### A. Obligatorios

| Plugin | Versión auditada | Origen/instalación | Dependencia y configuración |
|---|---:|---|---|
| VeciAhorra | header 0.3.0; Composer 1.0.0; schema 0.32.0 | checkout exacto del commit baseline; no ZIP local improvisado | requiere PHP 8.2, WP 6.8+, WooCommerce 10+ y `vendor/autoload.php`; flags, Webpay y correo fuera de Git |
| WooCommerce | 10.8.1 | distribución oficial | requerido por integración y Action Scheduler; completar wizard sin productos demo |
| Blocksy Companion | 2.1.44 | distribución oficial | acompaña al tema y su configuración; sin licencia secreta en DB exportada |
| Elementor | 4.1.2 | distribución oficial | requerido por páginas auditadas; importar datos selectivos y regenerar CSS |
| WPForms Lite | 1.10.1.1 | distribución oficial | requerido para reconstruir Contacto; importar definición, no HTML renderizado con nonce local |

El plugin VeciAhorra se despliega desde repositorio; `vendor/` está presente y contiene Composer, Guzzle, PSR, Symfony y Transbank. Ejecutar `composer install --no-dev --classmap-authoritative` en build o subir el artefacto resultante. No ejecutar `composer update` en producción.

### B. Opcionales, sólo con necesidad demostrada

| Plugin | Versión local | Decisión |
|---|---:|---|
| LiteSpeed Cache | 7.8.1 | recomendado por servidor LiteSpeed, activar tras smoke; excluir rutas privadas, REST mutante, carrito, checkout y retorno Webpay de caché |
| Really Simple Security | 9.5.11, inactivo local | no es necesario si cPanel/WordPress fuerzan HTTPS correctamente; instalar sólo por decisión de seguridad documentada |
| Akismet | versión no auditada | opcional sólo si se habilitan comentarios y existe configuración legítima |

### C. No transferir

- Duplicator 1.5.16.1: no forma parte del runtime limpio; conservar respaldos fuera del webroot.
- Code Snippets 3.9.6: no importar snippets. Cualquier lógica necesaria debe auditarse y entrar por código revisado en otro cambio.
- Premium Addons 4.11.79: no hay widgets Premium vigentes en las páginas autorizadas; no instalar.
- Advanced Custom Fields 6.8.4: el grupo legado “Proveedores” no es consumido por VeciAhorra; no instalar ni importar field groups.
- HUSKY Products Filter 1.3.9 y Filter Everything 1.9.2.2: el catálogo productivo usa filtros propios; no son autoridad.
- `woocommerce-products-filter`, integraciones/certificaciones VeciAhorra paralelas, `veciahorra-backup-*`, `shadow-*`, plugins de ensayo y copias locales: prohibidos.
- Formularios, filtros, SMTP, seguridad o caché distintos de los enumerados no se instalan por costumbre. SMTP se selecciona operativamente y se configura sin secretos exportados.

La lista local de activos contiene Duplicator duplicado; es una anomalía local y no debe reproducirse.

## 5. Paquete VeciAhorra, activación y esquema

Incluir `veciahorra.php`, `app/`, `assets/`, `vendor/`, `composer.json`, `composer.lock` y documentación operativa necesaria. Excluir `.git`, `.agents`, `.codex`, `artifacts/`, capturas, ZIP de revisión, CSV/JSON locales, backups, logs, caches, sesiones, fixtures y todos los harnesses runtime no aprobados como parte del release.

La activación ejecuta instalación/migraciones mediante `dbDelta`; la autoridad es `Config::SCHEMA_VERSION=0.32.0` y la opción `veciahorra_db_version`. En local y en el ensayo limpio existen estas 33 tablas con prefijo WordPress variable más `va_`:

`business_completions`, `business_completion_orders`, `cart_items`, `checkouts`, `checkout_orders`, `couriers`, `deliveries`, `delivery_completions`, `delivery_tracking`, `durable_retry_schedules`, `fulfillment_completions`, `inventory`, `orders`, `order_items`, `payments`, `payment_confirmation_audits`, `payment_orders`, `payment_origin_contexts`, `payment_reconciliations`, `payment_sessions`, `products`, `reservations`, `service_providers`, `service_zones`, `stores`, `store_decision_history`, `store_onboarding_activation_sessions`, `store_onboarding_applications`, `store_onboarding_email_verifications`, `store_onboarding_rate_limit_buckets`, `store_service_zones`, `webpay_returns`, `zonal_admin_service_zones`.

La tabla número 33 que faltaba en la expectativa histórica de 32 es `zonal_admin_service_zones`. No es residuo: `CreateZonalAdminFoundationTables`, registrada por `MigrationManager`, la crea mediante la autoridad `ZonalAdminServiceZonesTable`; por tanto pertenece legítimamente al esquema vigente `Config::SCHEMA_VERSION=0.32.0`. El ensayo limpio registró exactamente las 33 tablas anteriores en `rehearsal-database-audit.json`, con `schema_version=0.32.0`. La comprobación de instalación debe comparar conjuntos completos, no sólo cantidades: falta o sobra cualquier nombre respecto de esta lista cerrada implica rechazo; en particular, una tabla `*_va_*` número 34 es inesperada y bloquea el despliegue.

Después de activar: verificar 33 tablas, engines/charset/índices, versión 0.32.0 y cero errores; no editar la versión manualmente. Action Scheduler lo aporta WooCommerce. VeciAhorra agenda acciones durables con `as_schedule_single_action`; probar runner y cron. El módulo frontend no crea páginas ni rewrite rules; las páginas son una tarea explícita. `LaunchGate` es fail-closed en `production`: flags ausentes o no booleanos nativos `true` cierran registro/comercio.

## 6. Inventario de páginas y menús

Crear/importar por slug, validar publicación y después asignar menús. Los IDs son sólo evidencia local, nunca autoridad portable.

| Slug | Título / montaje | ID local | Tratamiento |
|---|---|---:|---|
| `inicio` | Inicio; Elementor + `[veciahorra_public_route_link ...]` + `[veciahorra_homepage_products]` | 88 | importar selectivamente; portada |
| `catalogo-veciahorra` | Catálogo VeciAhorra; `[veciahorra_frontend]` | 702 | obligatorio; el ID es evidencia local, el diseño usa la clase portable del plugin |
| `carrito-veciahorra` | Carrito VeciAhorra; `[veciahorra_cart]` | 698 | obligatorio; no confundir con carrito Woo ID 144 |
| `checkout` | Checkout VeciAhorra; `[veciahorra_checkout]` | 695 | obligatorio; no confundir con checkout Woo ID 145 |
| `mis-compras` | Mis compras; `[veciahorra_customer_panel]` | 713 | obligatorio |
| `registro-cliente` | Registro cliente; `[veciahorra_customer_registration]` | 881 | obligatorio, cerrado por LaunchGate |
| `panel-minimarket` | Panel Minimarket; `[veciahorra_minimarket_panel]` | 859 | obligatorio por rol |
| `registro-minimarket` | Registro de minimarket; `[veciahorra_minimarket_onboarding]` | 985 | obligatorio, cerrado por LaunchGate |
| `panel-repartidor` | Panel Repartidor; `[veciahorra_courier_panel]` | 860 | obligatorio por rol |
| `prestadores` | Prestadores de Servicios; `[veciahorra_service_provider_registration]` | 861 | obligatorio, cerrado por LaunchGate |
| `panel-prestador` | Panel Prestador; `[veciahorra_service_provider_panel]` | 862 | obligatorio por rol |
| `servicios` | Servicios; `[veciahorra_services]` | 863 | obligatorio |
| `nosotros` | Nosotros; Elementor | 15 | importar contenido y medios oficiales aprobados |
| `contacto` | Contacto; reconstruir con WPForms | 17 | no importar HTML renderizado, nonce ni URLs locales |
| `terminos-y-condiciones` | documento legal publicado | 983 | importar tras aprobación legal |
| política de privacidad | página local asignada en opción, ID 984 | 984 | importar tras aprobación legal y reasignar opción por nuevo ID |

No transferir las páginas producto E2E 700, 701 y 715 ni sus `product_id` 111, 112 y 999999995. Revisar si las páginas Woo `tienda`, `carrito`, `finalizar-compra` y `mi-cuenta` serán usadas; no enlazarlas en lugar de las rutas VeciAhorra.

Menú local único: `Menu principal` (term local 3), asignado a desktop `menu_1` y móvil `menu_mobile`. Contiene Inicio, Catálogo, Carrito, Mis compras, Nosotros, Contacto y Servicios. En producción reconstruir por slugs/roles; validar además footer y enlaces legales. Los destinos dinámicos de cuenta, carrito y paneles por rol los gobierna la cabecera del plugin, no IDs de menú.

## 7. Medios oficiales

Activos canónicos dentro del plugin:

| Ruta | Bytes | SHA-256 |
|---|---:|---|
| `assets/frontend/images/veciahorra-logo-oficial.png` | 1,012,444 | `710a77fbeac87beff9394725532671e823aa20917e1c740770181d442c7c6280` |
| `assets/frontend/images/veciahorra-logo-horizontal.png` | 576,123 | `cf6210127bfca138137f175778a06359f607c77569cc2039f97e3b33d099091b` |

La cabecera y el hero deben usar estos archivos del plugin; no requieren attachment ID. Los attachments locales 314/315 (`Logo_Veciahorra.png`/`Logo-Veciahorra.jpg`) son legado, no fuente oficial. `site_icon=0`: preparar favicon derivado autorizado como tarea de contenido independiente o dejarlo sin configurar; no inventar uno. Migrar sólo imágenes editoriales aprobadas de Nosotros/Inicio y sus metadatos; excluir `va-e2e-*`, productos demo, iconos/categorías de ensayo y thumbnails huérfanos. Verificar hashes tras transferencia.

## 8. Datos iniciales y datos prohibidos

Datos estructurales permitidos al activar: tablas vacías, opciones de versión, roles/capabilities, páginas/menús aprobados, zonas de servicio reales aprobadas, configuración de tema, formularios y documentos legales. Cualquier seed técnico debe ser idempotente, sin identidad comercial ficticia.

Datos reales que se cargan después mediante proceso operativo validado: microzonas/comunas, minimarkets aprobados, propietarios, catálogo maestro, inventarios/precios/stock, couriers y prestadores aprobados. Requieren responsable, trazabilidad y evidencia de consentimiento/contrato.

Deben comenzar en cero: carritos, checkouts, pedidos, reservas, pagos, sesiones Webpay, retornos, reconciliaciones, entregas, tracking, completions, retries, solicitudes/rate limits de ensayo y acciones programadas heredadas.

Prohibido transferir: usuarios locales, contraseñas/hashes, cookies/sesiones, clientes E2E, productos 111/112/999999995, inventarios A/B/C, tiendas/couriers/prestadores ficticios, pedidos/pagos/retornos Webpay, tokens, nonces, logs, correos de prueba, analytics, snippets, opciones transitorias, transients, cron local, colas Action Scheduler, Duplicator packages, backups, `wp-config.php`, CSV/JSON locales y cualquier R1D-C-A.

## 9. Plantilla sanitizada de constantes

Valores secretos se inyectan desde cPanel/secret store. Los marcadores siguientes no son valores utilizables:

```php
define('WP_ENVIRONMENT_TYPE', 'production');
define('WP_HOME', 'https://veciahorra.cl');
define('WP_SITEURL', 'https://veciahorra.cl');
define('FORCE_SSL_ADMIN', true);
define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', false);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false);

define('VECIAHORRA_PAYMENT_GATEWAY', 'webpay');
define('VECIAHORRA_WEBPAY_ENVIRONMENT', 'production');
define('VECIAHORRA_WEBPAY_PRODUCTION_ENABLED', '0'); // habilitar sólo en ceremonia Webpay
define('VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE', getenv('VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE'));
define('VECIAHORRA_WEBPAY_PRODUCTION_API_KEY', getenv('VECIAHORRA_WEBPAY_PRODUCTION_API_KEY'));
define('VECIAHORRA_PUBLIC_ORIGIN', 'https://veciahorra.cl');

define('DISALLOW_FILE_EDIT', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
```

No definir cookies/domain manualmente salvo necesidad demostrada; usar cookies `Secure`, `HttpOnly` y `SameSite` compatibles con WordPress/Webpay. Si cPanel cron invoca `wp-cron.php` por HTTPS/CLI, entonces definir `DISABLE_WP_CRON=true`; si no, dejarlo `false`. SMTP usa variables/secret store y remitente del dominio con SPF/DKIM/DMARC; nunca constantes con password en repositorio.

Callback/retorno Webpay debe pertenecer a `https://veciahorra.cl` y apuntar al endpoint productivo REST definido por el plugin; confirmar la URL exacta generada en smoke, no escribir una ruta inventada. Excluir de LiteSpeed: `/wp-json/veciahorra/v1/*` mutante/privado, carrito, checkout, cuenta, paneles y retorno Webpay; respetar `private, no-store` del modo cerrado.

## 10. Procedimiento cerrado con rollback

1. **Congelar y respaldar.** Registrar hashes del release, exportar estado cPanel y probar restauración. Rollback: restaurar backup de hosting y DNS previo.
2. **Crear instalación limpia.** WordPress 7.1/es_CL en `https://veciahorra.cl`, DB y usuario nuevos con privilegios mínimos. Rollback: eliminar sólo la instalación nueva identificada y restaurar virtual host previo.
3. **Configurar HTTPS y constantes cerradas.** Aplicar plantilla con secretos desde store, flags `false/false`, Webpay producción deshabilitado. Rollback: restaurar copia previa de configuración desde ubicación segura.
4. **Instalar tema y lista A.** Versiones exactas, sin activar listas B/C. Activar WooCommerce antes de VeciAhorra. Rollback: desactivar el último componente y restaurar archivos de release anterior.
5. **Desplegar VeciAhorra.** Checkout del commit, build Composer reproducible, hashes y permisos. Rollback: cambiar atómicamente al directorio de release anterior; no mezclar archivos.
6. **Activar y verificar esquema.** Ejecutar activación una vez; comprobar 33 tablas y versión 0.32.0. Rollback ante fallo: detener tráfico, conservar evidencia y restaurar snapshot DB completo; no reparar tablas manualmente.
7. **Importar presentación selectiva.** Usar el paquete no versionado `artifacts/production-clean-deployment-package/`: páginas, Elementor, Blocksy, CSS, WPForms y medios aprobados; sustituir el origen serializado y reasignar por slugs/mapa de medios. Rollback: restaurar snapshot post-schema/pre-contenido.
8. **Resolver identidades y navegación.** Confirmar `veciahorra-catalog-page` con un ID distinto del local, sustitución del hero sin widget ID, portada, privacidad, desktop/móvil/footer y roles. Rollback: revertir importación de contenido; no crear fixtures.
9. **Configurar cron, correo y caché.** Probar ejecución única, cola, emails; activar LiteSpeed al final con exclusiones. Rollback: desactivar caché/cron externo y volver a WP-Cron temporalmente.
10. **Smoke cerrado.** Registro 503/no-store, catálogo visible anónimo, comercio 503 `commerce_disabled`, cero escrituras; REST, roles y páginas sin 5xx/mixed content/JS/overflow. Rollback: mantener flags cerrados y retirar tráfico.
11. **Ceremonia de apertura.** Primero habilitar y probar credenciales Webpay bajo control; luego cambiar únicamente los dos flags a booleano `true` en ventana aprobada. Rollback: `false/false`, deshabilitar Webpay y purgar caché.
12. **Observación.** Monitorizar 5xx, correo, pagos, callbacks, Action Scheduler, pedidos e integridad territorial; guardar evidencia sanitizada. Rollback: cerrar comercio/registro y restaurar release/DB según el punto de falla.

## 11. Verificación final obligatoria

- `home/siteurl`, canonical, REST y assets sólo `https://veciahorra.cl`; cero `localhost`, mixed content o redirects de subdirectorio.
- Frontend 1920/1440/1024/768/504, cabecera aprobada intacta, catálogo, selector de microzona, Productos/Servicios y páginas por rol.
- Registro y comercio `false/false`: catálogo termina de cargar, headers `private, no-store`, POST bloqueado con 503 y cero escrituras.
- Ensayo controlado `true/true` sólo en ventana: registro, selección mediante token opaco de oferta, revalidación interna de microzona/stock/precio/sesión e identidad de inventario, carrito, mínimo, checkout, Webpay, pedido por tienda, estados y paneles; nunca exponer ni aceptar `inventory_id` como autoridad pública.
- Jobs: cron real, Action Scheduler sin backlog anómalo, retries propios observables; no importar las 22 acciones locales.
- Mail: registro, recuperación, onboarding y pedido con SPF/DKIM/DMARC; logs sin PII/secretos.
- Seguridad: permisos, file editor deshabilitado, backups fuera del webroot, secretos ausentes de Git/DB exportada y logs.
- Datos: cero E2E/demo; sólo autoridades estructurales y registros reales aprobados.

Los archivos de código, pruebas y este documento son candidatos versionados. `artifacts/production-clean-deployment-package/`, sus capturas, resultados de navegador y runtime de ensayo son package artifacts deliberadamente no versionados.

Resultado actualizado: el release elimina las dependencias productivas `page-id-702` y `df4acd5`; el despliegue sigue siendo una instalación limpia con importación selectiva del paquete sanitizado, nunca una copia completa del sitio local.

`VERDICT=VECIAHORRA_PRODUCTION_CLEAN_DEPLOYMENT_MANIFEST_READY_FOR_REVIEW`
