# Validación de remediación P0 para capacitación

Fecha: 10 de agosto de 2026. Autoridad: `training-readiness-visual-functional-audit-2026-08-14.md`, SHA-256 `88f19278a972600aeeeb0e47fb31b9af67575e740d5079e7aaff4eab6de45bd8` verificado.

## 1. Veredicto

**TRAINING P0 REMEDIATION APROBADA**

```text
TR-B01=PASS
TR-B02=PASS
TR-H01=PASS
TR-H03=PASS
```

Esto certifica exclusivamente la remediación P0. No declara `TRAINING READINESS APROBADO`; aún corresponde la auditoría runtime/manual final por roles.

## 2. Entorno HTTP y TR-B02

### Diagnóstico

```text
WORDPRESS_SITEURL=https://localhost/Minimarket
WORDPRESS_HOME=https://localhost/Minimarket
EXPECTED_HTTP_OR_HTTPS=https
EXPECTED_PORT=443
APACHE_STATUS=running (httpd parent/worker)
LISTENING_PORTS=configured 80 and 443; HTTP 80 redirects to HTTPS
DOCUMENT_ROOT=C:/xampp/htdocs
VIRTUAL_HOST=default SSL vhost; no custom VeciAhorra vhost
HTACCESS=valid WordPress rewrite base /Minimarket/
WORDPRESS_INDEX=present
PHP_INTEGRATION=Apache 2.4.58 + PHP 8.2.12 reported by log; WordPress rendered
PLUGIN_BOOTSTRAP=operational
PORT_CONFLICT=not observed
PHP_FATAL=none observed
```

La causa del `curl 000` de la auditoría fue doble: Apache no estaba alcanzable entonces; al reintentar, el `curl.exe` Schannel del sistema falla localmente con `SEC_E_NO_CREDENTIALS` frente al certificado XAMPP autofirmado/expirado. No es una respuesta de WordPress. El cliente HTTPS de PHP, configurado para aceptar el certificado local sólo durante el test, obtuvo respuestas reales.

| Superficie | HTTP | Bytes | Fatal/5xx |
|---|---:|---:|---|
| `/` | 200 | 75.253 | no |
| `/wp-login.php` | 200 | 9.239 | no |
| `/wp-json/` | 200 | 1.442.532 | no |
| `/registro-cliente/` | 200 | 65.136 | no |
| `/catalogo-veciahorra/` | 200 | 68.359 | no |
| `/mis-compras/` | 200 | 65.704 | no |
| `/panel-minimarket/` | 200 | 63.836 | no |
| `/panel-repartidor/` | 200 | 63.847 | no |
| `/panel-prestador/` | 200 | 65.260 | no |

```text
HTTP_ROOT=200
HTTP_LOGIN=200
HTTP_REGISTRATION=200
HTTP_CATALOG=200
HTTP_STORE_PANEL=200 anonymous shell; 200 authenticated shell
CURL_000_COUNT=0 in the authoritative PHP HTTP run (9/9 responses)
HTTP_5XX_COUNT=0
```

La limitación de Schannel queda como preflight de máquina: navegador/cliente debe aceptar o renovar el certificado localhost. No se cambió configuración externa ni se reinició Apache.

### Before/after TR-B02

- Before: doce URLs sin respuesta, `curl 000`.
- After: Apache activo y nueve superficies P0 responden 200 por HTTPS real; login store responde 302 y su destino 200.
- Evidence: runtime HTTP.

## 3. Arquitectura de identidad y TR-B01

Se reutiliza una sola autoridad: usuario WordPress creado por `wc_create_new_customer`; WooCommerce genera el username, WordPress gestiona hash/password y sesión, y el rol final se fuerza exclusivamente a `customer`. No se creó tabla, password ni identidad VeciAhorra paralela.

```text
PUBLIC_REGISTRATION_EXISTS=yes
REGISTRATION_URL=https://localhost/Minimarket/registro-cliente/
REGISTRATION_FORM=[veciahorra_customer_registration]
LOGIN_URL=https://localhost/Minimarket/wp-login.php
NEW_CUSTOMER_ROLE=customer
AUTO_LOGIN=yes
POST_REGISTRATION_REDIRECT=https://localhost/Minimarket/mis-compras/
CUSTOMER_EMPTY_STATE="Aún no tienes compras para mostrar."
CATALOG_CTA_VISIBLE=yes
LOGOUT_VISIBLE=yes
```

Campos: nombre, apellido, correo, contraseña y confirmación. No exige teléfono/dirección porque pertenecen al checkout, no a identidad mínima. El servidor exige nombre/apellido, email presente/válido/único, password de al menos ocho caracteres y confirmación idéntica. Usa nonce, sanitización, escaping, `email_exists`, API WooCommerce y mensajes públicos sin internos.

El harness HTTP creó una cuenta desechable, obtuvo 302 a `/mis-compras/`, comprobó cookie de auto-login y rol único `customer`, y eliminó inmediatamente el usuario; el cleanup fue verificado. No se tocó ningún usuario demo.

### Before/after TR-B01

- Before: WordPress y WooCommerce no exponían registro; no había formulario cliente.
- After: página publicada, formulario seguro, rol customer, auto-login, primer panel útil y CTA catálogo/logout.
- Evidence: runtime HTTP transaccional + código.

## 4. Definición literal y cierre de TR-H01

```text
ID=TR-H01
SEVERITY=HIGH
ROLE=customer/store/courier/provider
SURFACE=login y paneles frontend
OBSERVED_BEHAVIOR=sólo enlaces contextuales aportaban redirect_to; menú no enlazaba paneles/login
EXPECTED_TRAINING_BEHAVIOR=login visible y destino automáticamente adecuado al rol
EVIDENCE=runtime + code
REMEDIATION_CATEGORY=redirect + frontend wiring
```

Ahora el menú desktop/mobile muestra `Registrarse` e `Iniciar sesión` al visitante. Con sesión muestra `Mi panel` y `Cerrar sesión`. `login_redirect` deriva destino por capability, sin confiar en parámetros del visitante:

| Rol probado | Destino |
|---|---|
| customer | `/mis-compras/` |
| veciahorra_minimarket | `/panel-minimarket/` |
| veciahorra_courier | `/panel-repartidor/` |
| veciahorra_service_provider | `/panel-prestador/` |
| administrator | `/wp-admin/admin.php?page=veciahorra` |

Los roles no administrativos que intentan `/wp-admin/` son enviados a su panel y no ven admin bar. Usuarios demo existentes no se modificaron.

## 5. Definición literal y cierre de TR-H03

```text
ID=TR-H03
SEVERITY=HIGH
ROLE=todos los roles autenticados
SURFACE=paneles frontend
OBSERVED_BEHAVIOR=no enlace VeciAhorra de cerrar sesión ni regla post-logout
EXPECTED_TRAINING_BEHAVIOR=cambio seguro y entendible entre roles demo
EVIDENCE=runtime + code
REMEDIATION_CATEGORY=frontend wiring + redirect
```

El menú autenticado expone `Cerrar sesión` con nonce de WordPress y retorno a `/`. El Customer Panel también muestra logout junto a `Ir al catálogo`. La sesión HTTP de minimarket confirmó el texto de logout en el panel renderizado.

## 6. Customer first view

El registro conduce a `Mis compras`. Antes de tener compras se mantiene el empty-state existente y la cabecera ahora ofrece `Ir al catálogo` y `Cerrar sesión`; no existe segundo dashboard. El recorrido es:

`Inicio → Registrarse → Crear cuenta → Mis compras → Ir al catálogo`.

## 7. Minimarket y no regresión

```text
STORE_PANEL=HTTP 200 autenticado
STORE_INVENTORY_ROWS=20
STORE_PRODUCT_IMAGES=4/4 official image_url present in REST; UI rendering remains TR-H02/P1
LOS_VECINOS_OFFICIAL_OFFERS=PASS
STORE_ISOLATION=PASS
```

La prueba HTTP autenticó `va_demo_minimarket_vecinos`, abrió el panel, obtuvo el nonce REST y consultó exclusivamente GET inventory. Confirmó:

| Producto | Precio | Stock | Resultado |
|---|---:|---:|---|
| Coca-Cola Original 1,5 L | 2.190 | 12 | PASS |
| Tallarines Carozzi 400 g | 1.050 | 17 | PASS |
| Salsa de tomates Carozzi | 750 | 18 | PASS |
| Super 8 | 500 | 11 | PASS |

Las 20 filas pertenecen a Los Vecinos; cero correspondían a otra tienda. No hubo PATCH/POST de inventory. Las imágenes no se agregaron a la tarjeta porque la auditoría las clasificó como TR-H02/P1, no TR-H01/TR-H03; ampliar ese scope habría infringido el encargo.

## 8. Validaciones

| Validación | Resultado |
|---|---|
| `php -l` CustomerAccessModule | PASS |
| `php -l` FrontendController | PASS |
| `php -l` customer-panel view | PASS |
| `php -l` plugin bootstrap | PASS |
| `php -l` registration runtime harness | PASS |
| `php -l` HTTP role runtime harness | PASS |
| `customer-panel-foundation-test.php` | PASS |
| `customer-panel-frontend-infrastructure-test.php` | PASS |
| registro HTTP + cleanup | PASS |
| home discoverability HTTP | PASS |
| store login/panel/inventory HTTP | PASS |
| role destinations | PASS (5/5) |

No se ejecutó Webpay, checkout, compra, edición de inventory ni harness de dataset que modifica temporalmente el carrito.

## 9. Matriz P0 final

| ID | BEFORE | AFTER | EVIDENCE | FILES |
|---|---|---|---|---|
| TR-B01 | no registro cliente | PASS: registro/role/auto-login/landing | runtime+code | CustomerAccessModule, page 881 |
| TR-B02 | curl 000 | PASS: HTTPS 200/302, cero 5xx | runtime HTTP | entorno, sin config modificada |
| TR-H01 | login sin destino/paneles ocultos | PASS: navegación y redirects por rol | runtime+code | CustomerAccessModule |
| TR-H03 | sin logout | PASS: menú y Customer Panel | runtime+code | CustomerAccessModule, customer panel |

## 10. Delta propio y Git

```text
WORKTREE_PREEXISTENTE=preservado; incluye numerosos cambios A11/dataset/módulos previos
DELTA_PROPIO=[
  app/Modules/CustomerAccess/CustomerAccessModule.php,
  app/Modules/Frontend/Controller/FrontendController.php,
  app/Modules/Frontend/Views/customer-panel.php,
  veciahorra.php,
  tests/manual/training-p0-customer-registration-runtime-test.php,
  tests/manual/training-p0-http-role-runtime-test.php,
  docs/training-readiness-p0-remediation-validation-2026-08-14.md
]
DATABASE_DELTA_PROPIO=[WordPress page 881 /registro-cliente/]
COMMIT=NO
PUSH=NO
```
