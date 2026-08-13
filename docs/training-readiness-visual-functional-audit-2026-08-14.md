# Auditoría integral de preparación visual y funcional para capacitación

Fecha objetivo: viernes 14 de agosto de 2026. Fecha de auditoría: 10 de agosto de 2026.

## 1. Veredicto ejecutivo

**TRAINING READINESS BLOQUEADO**

La demostración con cuentas demo tiene superficies implementadas, páginas publicadas y datos certificados, pero hoy no existe registro público de cliente y el servidor HTTPS local no respondió durante la auditoría. Una persona nueva tampoco encuentra enlaces visibles de login o registro en la portada o menú. Los paneles de minimarket, repartidor y prestador no tienen redirección post-login por rol: sólo llegan correctamente si el login se inicia desde el enlace de cada panel, que aporta `redirect_to`.

```text
NEW_CUSTOMER_REGISTRATION=BLOCKED
CUSTOMER_FIRST_LOGIN=BLOCKED
CUSTOMER_DASHBOARD=CONDITIONAL
CUSTOMER_PURCHASE_FLOW=NOT_RUNTIME_VERIFIED

STORE_LOGIN=CONDITIONAL
STORE_DASHBOARD=NOT_RUNTIME_VERIFIED
STORE_INVENTORY_VISIBILITY=CONDITIONAL
STORE_ISOLATION=PASS

COURIER_PANEL=NOT_RUNTIME_VERIFIED
PROVIDER_PANEL=NOT_RUNTIME_VERIFIED

ADMIN_PANEL=CONDITIONAL

TRAINING_DEMO_DATASET=PASS
```

Conclusiones inequívocas:

1. Una persona nueva no encuentra cómo registrarse y no puede registrarse como cliente.
2. El login directo es el formulario core; no hay destino VeciAhorra específico por rol.
3. `/mis-compras/` muestra un empty-state comprensible para una cuenta sin compras, pero no ofrece CTA al catálogo ni logout.
4. El minimarket entra por `/panel-minimarket/`; el panel muestra resumen, ofertas y pedidos.
5. Los Vecinos tiene 20 ofertas; precio, stock y estado de inventory son editables. La UI no muestra imagen, unidad, estado de Product ni fecha de actualización.
6. El aislamiento está impuesto por identidad en REST y consultas; no hay selector de tienda ni `store_id` aceptado.
7. Repartidor y prestador tienen paneles publicados; no fueron observables en navegador por indisponibilidad HTTP.
8. Administración existe bajo `/wp-admin/admin.php?page=veciahorra`, pero su dashboard es sólo bienvenida; la operación está en submenús.

## 2. Entorno auditado

```text
SITE_URL=https://localhost/Minimarket
HOME_URL=https://localhost/Minimarket
FRONT_PAGE_ID=88
FRONT_PAGE_TITLE=Inicio
THEME=Blocksy
PUBLIC_MENU=Menu principal (desktop y mobile)
WORDPRESS_PUBLIC_REGISTRATION=disabled
WOOCOMMERCE_MY_ACCOUNT_REGISTRATION=disabled
WOOCOMMERCE_CHECKOUT_REGISTRATION=disabled
WOOCOMMERCE_GUEST_CHECKOUT=enabled
```

Se inspeccionaron configuración y base real cargando `wp-load.php`, páginas publicadas, opciones, roles/capabilities, menús y tablas `wp_va_*`. No se modificó ninguna opción, usuario, carrito, pedido, fila o página.

## 3. Evidencia y limitaciones

- **Runtime:** consultas de WordPress/DB de sólo lectura confirmaron URL, opciones, páginas, menú, usuarios, ownership, estados, 20 productos demo, 42 inventories demo, imágenes y valores de Los Vecinos.
- **Runtime HTTP:** GET seguro contra 12 superficies devolvió `curl` 000; Apache/HTTPS no estaba disponible. No hubo navegador, screenshots, consola JS ni evaluación visual efectiva.
- **Código:** shortcodes, templates, JS, controladores, REST, permisos, redirects y empty-states.
- El harness `training-demo-dataset-validation.php` **no se ejecutó**: aunque restaura el carrito en `finally`, agrega cuatro ítems y luego lo elimina; viola el alcance estrictamente read-only. Se conservó su resultado certificado aportado como baseline.
- Pago, registro, checkout, edición y transiciones no se ejecutaron porque persisten estado.
- `BACKEND_EXISTS` y `VISIBLE_AND_USABLE` se tratan por separado. Todo lo no visto vía HTTP queda `NOT_RUNTIME_VERIFIED`.

## 4. Visitor journey

### Inventario de páginas VeciAhorra publicadas

| ID | Título / URL | Shortcode o contenido | Auth / roles | Propósito | Estado |
|---:|---|---|---|---|---|
| 88 | Inicio `/` | contenido Elementor + enlaces de ruta | pública | presentación/entrada | partial |
| 702 | Catálogo `/catalogo-veciahorra/` | `[veciahorra_frontend]` | pública | buscar productos | usable, no runtime HTTP |
| 715 | Coca-Cola `/producto-coca-cola-350-cc/` | producto fijo `999999995` | pública | ficha/ofertas | usable, no runtime HTTP |
| 700/701 | productos E2E | producto fijo 111/112 | pública | fixtures históricas | partial |
| 698 | Carrito `/carrito-veciahorra/` | `[veciahorra_cart]` | pública/sesión | carrito VeciAhorra | usable, no runtime HTTP |
| 695 | Checkout `/checkout/` | `[veciahorra_checkout]` | pública; guest habilitado | checkout | usable por código; pago no ejecutado |
| 713 | Mis compras `/mis-compras/` | `[veciahorra_customer_panel]` | login; datos por usuario | compras/detalle | usable, no runtime HTTP |
| 859 | Panel Minimarket `/panel-minimarket/` | `[veciahorra_minimarket_panel]` | login + rol REST | ofertas/pedidos | usable por código |
| 860 | Panel Repartidor `/panel-repartidor/` | `[veciahorra_courier_panel]` | login + rol REST | entregas | usable por código |
| 861 | Prestadores `/prestadores/` | registro de prestador | pública, pero login para guardar | landing/solicitud | partial |
| 862 | Panel Prestador `/panel-prestador/` | panel prestador | login + ownership REST | perfil | usable por código |
| 863 | Servicios `/servicios/` | catálogo servicios | pública | buscar prestadores | usable por código |
| 143 | Tienda `/tienda/` | vacío | pública | Woo shop | empty |
| 144/145/146 | Woo carrito/checkout/mi cuenta | shortcodes WooCommerce | mixta | superficies Woo paralelas | partial/confusing |

No hay rewrites de panel: las rutas visibles dependen de páginas con shortcode. Las APIs están bajo `/wp-json/veciahorra/v1/` y no sustituyen una página navegable.

### Portada y navegación

```text
HOME_VISIBLE_CONTENT="UNA NUEVA FORMA DE COMPRAR", qué es VeciAhorra, Productos, misión breve
PRIMARY_CTA=Ver productos / Explorar catálogo
LOGIN_LINK_VISIBLE=no
REGISTER_LINK_VISIBLE=no
CATALOG_LINK_VISIBLE=yes
```

El menú público real es idéntico en desktop/mobile y no cambia por rol: Inicio, Catálogo, Carrito, Mis compras, Nosotros, Contacto y Servicios. No incluye Checkout, Login, Registro, Panel Minimarket, Panel Repartidor, Prestadores ni Panel Prestador. No se encontró logout visible propio.

### Recorrido anónimo reconstruido

| Paso | Accesible | CTA / límite | Redirect esperado/actual | Evidencia |
|---|---|---|---|---|
| Home | sí | Explorar catálogo | ninguno | DB + código |
| Catálogo | sí | tarjeta a ficha de producto | ninguno | código |
| Producto/ofertas | sí | seleccionar minimarket y agregar | ninguno | código |
| Carrito | sí | actualizar/eliminar/checkout | ninguno | código |
| Checkout | sí | datos, fulfillment y pago | guest permitido; pago persiste | opción runtime + código |
| Mis compras | página sí, datos no | botón Iniciar sesión | core login con retorno a `/mis-compras/` | código |

La ruta es encontrable hasta el carrito. La coexistencia de páginas Woo y VeciAhorra duplica “Carrito/Checkout/Mi cuenta”, aunque sólo el carrito VeciAhorra está en el menú.

## 5. New customer registration

```text
PUBLIC_REGISTRATION_EXISTS=no
REGISTRATION_URL=none usable
REGISTRATION_OWNER=none
REGISTRATION_FORM=none
DEFAULT_REGISTERED_ROLE=subscriber (option; unreachable publicly)
POST_REGISTRATION_REDIRECT=not applicable
```

WordPress core tiene `users_can_register=0`; WooCommerce tiene deshabilitados el registro en Mi cuenta y desde checkout. No existe shortcode, endpoint o formulario VeciAhorra para cliente. Por tanto no existen campos reales de nombre, apellido, email, username, password, confirmación, teléfono o dirección que auditar, ni mensajes visibles para email vacío/inválido/duplicado, username duplicado, password inválida o faltantes.

Registro exitoso, auto-login, customer record y redirección son `NOT_EXPOSED`. No se puede asegurar creación de rol `customer` ni registro WooCommerce/VeciAhorra porque el flujo no existe.

## 6. Customer dashboard

```text
CUSTOMER_PANEL_URL=https://localhost/Minimarket/mis-compras/
CUSTOMER_PURCHASES_URL=https://localhost/Minimarket/mis-compras/
CUSTOMER_ORDER_DETAIL_ROUTE=misma página; detalle dinámico por REST
```

Anónimo: título “Mis compras”, explicación y botón “Iniciar sesión”. Autenticado: lista y detalle de compras del usuario. Sin compras: “Aún no tienes compras para mostrar.” Es entendible, pero carece de CTA al catálogo, perfil y logout. La navegación global aporta catálogo y carrito.

El shortcode sólo pregunta si hay sesión; la API aplica ownership. Una cuenta autenticada con rol no cliente puede montar el shell y recibir el error REST correspondiente.

## 7. Customer purchase journey

`Catálogo → ficha → oferta/store → carrito → checkout → pago → confirmación → Mis compras` existe por código. Catálogo, oferta y carrito son públicos; Woo guest checkout está habilitado. El pago y la creación de pedido no se ejecutaron (`not safely executable`). El baseline aportado certifica `OFFICIAL_CART=PASS`, pero no equivale a validación visual ni confirma pago/confirmación en esta ejecución.

Clasificación: entrada al catálogo **OK por exposición**; compra completa **NOT_RUNTIME_VERIFIED**. No hay alta de cliente, de modo que el recorrido “cliente nuevo registrado” está bloqueado aunque un visitante pueda intentar compra guest.

## 8. Minimarket panel

```text
STORE_PANEL_URL=https://localhost/Minimarket/panel-minimarket/
POST_LOGIN_REDIRECT=panel sólo si login fue abierto desde esa página; login directo usa destino core
VISIBLE_SECTIONS=Resumen, Mis productos, Incorporar producto, Mis pedidos
```

El panel carga nombre, status y cantidad de ofertas activas; lista ofertas; busca productos globales aún no incorporados; lista/detalla pedidos. La tarjeta de oferta muestra nombre, marca, precio, stock, estado de inventory y Guardar.

| Campo solicitado | Visible realmente |
|---|---|
| imagen | no, aunque API entrega `image_url` |
| nombre | sí |
| marca | sí, si existe |
| unidad | no |
| precio | sí/editable |
| stock | sí/editable |
| estado inventory | sí/editable |
| estado Product | no |
| store | resumen, no por tarjeta |
| updated_at | no |
| acciones | Guardar; Incorporar en catálogo global |

```text
CAN_EDIT_PRICE=yes
CAN_EDIT_STOCK=yes
CAN_EDIT_INVENTORY_STATUS=yes
CAN_EDIT_PRODUCT_NAME=no
CAN_EDIT_PRODUCT_IMAGE=no
CAN_CREATE_PRODUCT=no
CAN_DELETE_PRODUCT=no
```

La UI respeta Product global frente a Inventory de tienda: “Incorporar producto” crea una oferta sobre un producto activo existente. El rótulo “Mis productos” puede sugerir ownership del Product y debería decir “Mis ofertas”. Para tienda sin inventario, el contenedor queda vacío: no hay mensaje ni CTA contextual, aunque la pestaña Incorporar producto permanece disponible.

Estados de store: sólo `active + complete + approved_at` carga. Cualquier draft/in_review/rejected/approved_inactive/invalid recibe el mensaje genérico REST “El Store no está aprobado y activo”; no distingue causa ni siguiente paso.

## 9. Los Vecinos inventory

```text
STORE_ID=899847288
STORE_NAME=Minimarket Los Vecinos
USER_ID=206
USERNAME=va_demo_minimarket_vecinos
ROLE=veciahorra_minimarket
STATUS=active / complete / approved
STORE_INVENTORY_COUNT=20
VISIBLE_STORE_INVENTORY_COUNT=20 derived by unfiltered owned GET and renderer
```

| Oferta oficial | Visible | Imagen | Precio | Stock | Status visible | Controles editables |
|---|---|---|---|---|---|---|
| Coca-Cola Original 1,5 L | sí por datos+código | no | $2.190 correcto | 12 correcto | sí | sí |
| Tallarines Carozzi 400 g | sí por datos+código | no | $1.050 correcto | 17 correcto | sí | sí |
| Salsa de tomates Carozzi | sí por datos+código | no | $750 correcto | 18 correcto | sí | sí |
| Super 8 | sí por datos+código | no | $500 correcto | 11 correcto | sí | sí |

“Visible” aquí significa que la consulta de runtime devuelve la fila y el JS la renderiza; no fue observado en navegador. Los cuatro tienen Product e Inventory activos e `image_id`, pero el JS no dibuja la imagen.

Las otras stores demo son: Central (ID 899847289, user 207, 12 ofertas) y Plaza Sur (ID 899847290, user 208, 10 ofertas), ambas activas/completas/aprobadas. Esas tres suman las 42 filas del dataset demo. La tabla física contiene además 7 filas históricas ajenas al dataset demo; el listado admin sin scope podría mostrar 49.

## 10. Store isolation

```text
STORE_LIST_ISOLATION=PASS (code + certified runtime baseline)
STORE_DETAIL_ISOLATION=PASS (code)
STORE_API_ISOLATION=PASS (code + certified runtime baseline)
```

`StoreContext::current()` deriva `_veciahorra_store_id` del usuario. Listado, resumen, pedidos y detalle filtran por ese ID. PATCH exige simultáneamente inventory ID y store ID; un ID ajeno resulta not found. POST/PATCH rechazan `store_id` y `minimarket_id`. No existe selector de tienda. No se enviaron mutaciones ni intentos directos en esta auditoría.

## 11. Store registration

```text
PUBLIC_STORE_REGISTRATION_EXISTS=no
STORE_REGISTRATION_URL=none
FIELDS=none exposed
INITIAL_STATE=not applicable
ADMIN_APPROVAL_REQUIRED=backend lifecycle exists, public application does not
POST_SUBMISSION_VIEW=none
```

Hay CRUD/lifecycle administrativo, no UI pública para solicitar minimarket.

## 12. Courier

Usuario demo: ID 209, `va_demo_diego`, Diego Morales, rol `veciahorra_courier`; entidad aprobada según baseline.

```text
COURIER_LOGIN_DESTINATION=panel sólo mediante redirect_to desde su página
COURIER_PANEL_URL=https://localhost/Minimarket/panel-repartidor/
COURIER_VISIBLE_SECTIONS=Resumen; Entregas disponibles; Mis entregas
COURIER_EMPTY_STATE="Sin entregas disponibles" / "Sin entregas asignadas"
```

Ve retiro (minimarket/dirección/comuna/teléfono), entrega (destinatario/dirección/comuna/teléfono) y puede aceptar, marcar retirada y entregada. Esas acciones mutan y no se ejecutaron. API exige identidad courier aprobada y ownership en entregas propias.

## 13. Courier registration

```text
PUBLIC_COURIER_REGISTRATION_EXISTS=no
URL=none
FIELDS=none
INITIAL_STATE=not applicable
APPROVAL_FLOW=admin-only create/link/approve
```

## 14. Service provider

Seis usuarios demo enlazados: José Martínez (210), Carolina Rojas (211), Luis González (212), Daniela Pérez (213), Pedro Rojas (214) y Camila Silva (215), rol `veciahorra_service_provider`. El baseline certifica seis perfiles publicados.

```text
PROVIDER_PANEL_URL=https://localhost/Minimarket/panel-prestador/
VISIBLE_PROVIDER_DATA=plan, cuenta, servicio, verificación, estado/observación y revisión
EDITABLE_PROVIDER_DATA=perfil sólo en draft/observed
STATUS=published para los seis demo
```

El catálogo público `/servicios/` ofrece filtros y fichas. El panel usa ownership por user meta. No se observó visualmente.

## 15. Provider registration

```text
PUBLIC_PROVIDER_REGISTRATION_EXISTS=partial
URL=https://localhost/Minimarket/prestadores/
FIELDS=plan, nombre, RUT, email, teléfono, negocio, categoría/subcategoría, comuna,
       experiencia, horario, descripción, cobertura, especialidades, WhatsApp,
       email de contacto, Attachment ID foto, emergencia, términos
INITIAL_STATE=draft
APPROVAL_FLOW=draft -> in_review -> observed/approved -> published/inactive
```

La landing es pública, pero el CTA para usuario anónimo lleva al login core: no crea una cuenta nueva. Por ello es formulario de perfil para una cuenta previamente provisionada, no registro público completo. “Attachment ID foto” es texto técnico inadecuado para capacitación.

## 16. Administrator

Acceso sólo con `manage_options`. Menús reales:

| Menú | URL | Vista |
|---|---|---|
| Dashboard | `/wp-admin/admin.php?page=veciahorra` | sólo título y “Bienvenido al Marketplace” |
| Minimarkets | `?page=veciahorra-stores` | listado, filtros, detalle/lifecycle; CRUD interno oculto |
| Productos | `?page=veciahorra-products` | listado/filtros, imagen, detalle, relación a inventory |
| Inventario | `?page=veciahorra-inventory` | producto/store, precio, stock, status, filtros |
| Pedidos | `?page=veciahorra-orders` | listado |
| Pedido detalle | `?page=veciahorra-orders&action=view&order_id={id}` | detalle y retorno |
| Repartidores | `?page=veciahorra-couriers` | listado, vínculo user, aprobar/desactivar |
| Prestadores | `?page=veciahorra-service-providers` | filtros, datos, observación/transiciones |

Admin Stores/Products/Inventory/Orders existen y consumen REST o vistas admin protegidas. No fueron vistos por HTTP. Runtime consultó 20 productos demo y 42 inventories demo; el listado general puede incluir 49 inventories por 7 fixtures/históricos adicionales. El dashboard raíz no resume nada y explica la percepción de vacío.

## 17. Role/menu/access matrix

| Superficie | Visitor | Customer | Store | Courier | Provider | Admin |
|---|---|---|---|---|---|---|
| Home/catálogo/carrito/servicios | visible | visible | visible | visible | visible | visible |
| Mis compras shell | visible/login | visible | visible pero API puede prohibir | igual | igual | shell; API según ownership |
| Panel minimarket shell | visible/login | visible + forbidden REST | visible | forbidden REST | forbidden REST | forbidden REST sin vínculo |
| Panel courier shell | visible/login | forbidden REST | forbidden REST | visible | forbidden REST | forbidden REST sin vínculo |
| Panel provider shell | visible/login | forbidden/no profile | forbidden | forbidden | visible | sin profile |
| Admin VeciAhorra | login/forbidden | forbidden | forbidden | forbidden | forbidden | visible |

El menú global no cambia por rol. Paneles especializados no están enlazados. `/wp-admin/`: los cinco roles tienen capability `read`, por lo que core puede admitirlos a un dashboard/perfil WordPress; no existe restricción/redirect del plugin ni ocultación de admin bar encontrada. Admin entra normalmente. En cuentas no admin:

```text
WP_ADMIN_ACCESS=core read access, no VeciAhorra menus
WP_ADMIN_REDIRECT=none custom
ADMIN_BAR_VISIBLE=core default when theme calls wp_footer
```

```text
LOGIN_URL=https://localhost/Minimarket/wp-login.php
LOGIN_FORM_OWNER=WordPress core
POST_LOGIN_REDIRECT_CUSTOMER=core default unless entered from /mis-compras/
POST_LOGIN_REDIRECT_STORE=core default unless entered from /panel-minimarket/
POST_LOGIN_REDIRECT_COURIER=core default unless entered from /panel-repartidor/
POST_LOGIN_REDIRECT_PROVIDER=core default unless entered from /panel-prestador/
POST_LOGIN_REDIRECT_ADMIN=/wp-admin/ core
LOGOUT_LINK_VISIBLE=no VeciAhorra link found
POST_LOGOUT_REDIRECT=core login page/default; no custom rule
```

## 18. Dashboard diagnosis

La pantalla llamada “dashboard” es `/wp-admin/admin.php?page=veciahorra`, método `VeciAhorra\Admin\Menu::dashboard()`, sólo para `manage_options`. Produce título “Dashboard VeciAhorra” y “Bienvenido al Marketplace.” Diagnóstico: **EMPTY_BY_DESIGN** (contenido mínimo), no falta shortcode, página, permiso, routing o datos.

Para roles de negocio, el dashboard correcto es una página frontend especializada, pero no hay redirect ni menú que la descubra. El dashboard WordPress al que puede conducir el login directo es **NOT_ACTUALLY_VECIAHORRA_DASHBOARD**.

## 19. Blockers

### TR-B01 — Registro de cliente no expuesto

```text
FILE=WordPress options runtime; app/ (sin formulario/endpoint cliente)
CLASS/FUNCTION/SHORTCODE=none
URL=/wp-login.php?action=register y /mi-cuenta/
OBSERVED_OR_DERIVED_BEHAVIOR=users_can_register=0; Woo registration=no; no formulario VeciAhorra
EXPECTED_TRAINING_BEHAVIOR=visitante encuentra formulario, crea customer y recibe destino útil
EVIDENCE=runtime + code
```

### TR-B02 — Sitio local no disponible por HTTP durante auditoría

```text
FILE=environment/runtime
CLASS/FUNCTION/SHORTCODE=all public/admin surfaces
URL=https://localhost/Minimarket/*
OBSERVED_OR_DERIVED_BEHAVIOR=12 GET seguros devolvieron curl 000; no hubo render ni JS verificable
EXPECTED_TRAINING_BEHAVIOR=WordPress responde y permite recorrido completo
EVIDENCE=runtime
```

## 20. High findings

### TR-H01 — Login sin destino por rol y paneles no descubribles

```text
FILE=app/Modules/Frontend/Controller/FrontendController.php; MinimarketModule.php;
     CourierModule.php; ServiceProviderModule.php; menú WordPress runtime
CLASS/FUNCTION/SHORTCODE=renderCustomerPanel/render/registrationLanding
URL=/wp-login.php, /mis-compras/, /panel-minimarket/, /panel-repartidor/, /panel-prestador/
OBSERVED_OR_DERIVED_BEHAVIOR=sólo enlaces contextuales aportan redirect_to; menú no enlaza paneles/login
EXPECTED_TRAINING_BEHAVIOR=login visible y destino automáticamente adecuado al rol
EVIDENCE=runtime + code
```

### TR-H02 — Inventario minimarket incompleto visualmente

```text
FILE=assets/frontend/js/minimarket-panel.js
CLASS/FUNCTION/SHORTCODE=loadInventory / [veciahorra_minimarket_panel]
URL=/panel-minimarket/
OBSERVED_OR_DERIVED_BEHAVIOR=API entrega imagen/unidad/updated_at, tarjeta no los renderiza; Product status ausente
EXPECTED_TRAINING_BEHAVIOR=identificación visual completa de la oferta y vigencia
EVIDENCE=code
```

### TR-H03 — Sin logout visible

```text
FILE=templates y JS frontend inspeccionados; menú WordPress runtime
CLASS/FUNCTION/SHORTCODE=paneles frontend
URL=todos los paneles
OBSERVED_OR_DERIVED_BEHAVIOR=no enlace VeciAhorra de cerrar sesión ni regla post-logout
EXPECTED_TRAINING_BEHAVIOR=cambio seguro y entendible entre roles demo
EVIDENCE=runtime + code
```

## 21. Medium/low findings

- **MEDIUM TR-M01:** dashboard admin es una bienvenida mínima sin accesos/indicadores.
- **MEDIUM TR-M02:** tienda sin ofertas recibe contenedor vacío, no empty-state ni CTA explícito.
- **MEDIUM TR-M03:** estados no operacionales de store comparten mensaje genérico y no orientan.
- **MEDIUM TR-M04:** páginas WooCommerce y VeciAhorra duplicadas pueden confundir; `/tienda/` está vacía.
- **MEDIUM TR-M05:** Customer Panel vacío no ofrece CTA de compra ni logout.
- **MEDIUM TR-M06:** admin Inventory puede mostrar 49 filas totales aunque el dataset demo certificado tenga 42; se requiere explicar o filtrar fixtures históricos.
- **LOW TR-L01:** “Mis productos” debería comunicar “Mis ofertas”; Product sigue siendo global.
- **LOW TR-L02:** el formulario de prestador expone “Attachment ID foto”.
- **LOW TR-L03:** Inventory/store/order IDs y timestamps se muestran en superficies operativas (pedidos/courier/admin); son útiles para soporte, pero técnicos para audiencia inicial.
- **MEDIUM TR-M07 responsive:** CSS de prestadores sí incluye layouts responsivos; las tarjetas de minimarket evitan tabla ancha. Admin usa tablas WordPress susceptibles a scroll en móvil. Sin viewport/browser no se certifica responsive de catálogo, carrito, registro o dashboard.
- **OK:** catálogo, carrito y checkout tienen empty-states textuales; courier tiene empty-states; Customer Panel anuncia carga/error.
- **Errores:** no se observaron PHP errors al cargar WordPress por CLI. JS/REST/403/404/500 no verificables vía HTTP; el 000 es indisponibilidad de transporte, no respuesta 500.

## 22. Training-ready matrix

| ROLE | REGISTRATION | LOGIN | DASHBOARD | CORE VIEW | ISOLATION | TRAINING_READY |
|---|---|---|---|---|---|---|
| Visitor | N/A | N/A | home partial | catálogo/carrito expuestos | pública | YES_WITH_WORKAROUND |
| New Customer | BLOCKED | no cuenta creable | no alcanzable | guest purchase parcial | no evaluable | NO |
| Existing Customer | N/A | core + URL guiada | Mis compras usable por código | compras/checkout | ownership por API | YES_WITH_WORKAROUND |
| Minimarket | no público | core + URL guiada | panel implementado | 20 ofertas Los Vecinos | PASS | YES_WITH_WORKAROUND |
| Courier | no público | core + URL guiada | panel implementado | entregas | ownership por API | YES_WITH_WORKAROUND |
| Service Provider | perfil partial, cuenta no | core + URL guiada | panel implementado | perfil/servicios | ownership por API | YES_WITH_WORKAROUND |
| Administrator | admin provisionado | core | demasiado vacío | submenús completos | manage_options | YES_WITH_WORKAROUND |

Ningún `YES_WITH_WORKAROUND` significa validación visual: exige encender Apache, usar URLs directas y hacer preflight manual.

## 23. Recommended training flow

| STEP | ROLE | URL/SCREEN | ACTION | EXPECTED VISIBLE RESULT | CURRENT STATUS |
|---:|---|---|---|---|---|
| 1 | Visitor | `/` | explicar propuesta y abrir Catálogo | portada + CTA | conditional |
| 2 | Visitor | `/catalogo-veciahorra/` | buscar producto | tarjetas demo | no runtime HTTP |
| 3 | Visitor | ficha desde catálogo | elegir Los Vecinos | ofertas, precio/stock | no runtime HTTP |
| 4 | Visitor | `/carrito-veciahorra/` | revisar carrito preparado | cuatro oficiales | baseline PASS, visual pendiente |
| 5 | Existing Customer | `/mis-compras/` | iniciar sesión desde esta URL | retorno a compras | code verified |
| 6 | Existing Customer | Mis compras | mostrar vacío/lista/detalle | datos propios | no runtime HTTP |
| 7 | Minimarket | `/panel-minimarket/` | login desde el panel | retorno al panel | code verified |
| 8 | Minimarket | Mis productos | revisar cuatro oficiales | nombre/precio/stock/status | data+code, visual pendiente |
| 9 | Courier | `/panel-repartidor/` | login desde el panel | resumen/entregas | code verified |
| 10 | Provider | `/panel-prestador/` | login desde el panel | perfil propio publicado | code verified |
| 11 | Admin | `/wp-admin/admin.php?page=veciahorra-inventory` | filtrar Los Vecinos | inventario administrativo | data+code |

No incluir “crear cliente” hasta resolver TR-B01. No guardar ofertas, aceptar entregas, enviar perfiles ni completar pago durante un ensayo read-only.

## 24. Manual preflight checklist

- [ ] Iniciar Apache/MySQL y confirmar en incógnito que `/` responde sin error TLS/HTTP.
- [ ] Visitante incógnito: portada → catálogo → producto → carrito → checkout.
- [ ] Confirmar manualmente si se resolvió el registro de cliente nuevo; registrar una cuenta sólo en un entorno autorizado.
- [ ] Probar primer login del cliente y destino; validar empty-state y entrada al catálogo.
- [ ] Entrar como cliente existente desde `/mis-compras/`; abrir lista y detalle propios.
- [ ] Entrar como Los Vecinos desde `/panel-minimarket/`.
- [ ] Confirmar 20 ofertas y los cuatro oficiales: $2.190/12, $1.050/17, $750/18, $500/11.
- [ ] Confirmar que Central/Plaza no aparecen y que un ID ajeno devuelve acceso inexistente sin editar nada.
- [ ] Admin: abrir Minimarkets, Productos, Inventario y Pedidos; aclarar 42 demo frente a 49 totales.
- [ ] Verificar panel repartidor y prestador con sus cuentas demo.
- [ ] Verificar logout y retorno antes de alternar cuentas.
- [ ] Repetir catálogo, carrito, panel minimarket y formulario prestador a ancho móvil.
- [ ] Revisar consola/red: cero PHP/JS errors y cero REST 403/404/500 inesperados.

## 25. Remediation priorities

| ID | Prioridad | Problema | Rol/superficie | Resultado mínimo esperado | Scope |
|---|---|---|---|---|---|
| TR-B01 | P0 | no existe alta pública | cliente/registro | formulario accesible, rol customer y destino claro | missing feature |
| TR-B02 | P0 | servidor no responde | todos/sitio | WordPress accesible y estable | environment |
| TR-H01 | P0 | login/panel no descubrible ni role-aware | customer/store/courier/provider | enlace visible y redirect correcto por rol | redirect + frontend wiring |
| TR-H03 | P0 | logout no visible | todos los roles autenticados | acción visible y retorno público | frontend wiring + redirect |
| TR-H02 | P1 | oferta visual incompleta | store/inventory | imagen, unidad, status Product y actualización comprensibles | frontend wiring |
| TR-M02 | P1 | vacío de store mudo | store/inventory | empty-state con CTA Incorporar | empty-state |
| TR-M03 | P1 | estado store genérico | store/panel | mensaje específico y siguiente paso | empty-state |
| TR-M05 | P1 | vacío customer sin CTA | customer/Mis compras | botón al catálogo y logout | empty-state |
| TR-M01 | P1 | dashboard admin mínimo | admin | accesos claros a operación | frontend wiring |
| TR-M04 | P1 | superficies Woo duplicadas/vacías | visitor/navigation | recorrido único no ambiguo | page/shortcode |
| TR-M06 | P1 | 49 filas globales vs 42 demo | admin/inventory | scope/filtro comprensible para capacitación | data + frontend wiring |
| TR-L01 | P2 | texto ownership confuso | store | “Mis ofertas”/“Incorporar oferta” | frontend wiring |
| TR-L02 | P2 | Attachment ID expuesto | provider | selector/carga de imagen comprensible | frontend wiring |

## 26. Git/delta

Baseline ejecutado antes de auditar: `git status --short`, `git diff --stat`, `git diff --check`. El árbol ya estaba sucio con cambios preexistentes; no se limpió, revirtió, guardó ni modificó ninguno. `git diff --check` inicial terminó sin errores (sólo avisos de line endings).

```text
DELTA_PROPIO=[
  docs/training-readiness-visual-functional-audit-2026-08-14.md
]
COMMIT=NO
PUSH=NO
```
