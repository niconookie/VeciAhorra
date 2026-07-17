# Hito 29.3 — Diseño del Frontend del Panel del Cliente

## 1. Propósito y alcance

Este documento define el frontend autenticado del Panel del Cliente de VeciAhorra sobre la infraestructura de lectura aprobada en el Hito 29.2. Su objetivo es dejar una especificación implementable y verificable para consultar compras, abrir su detalle y comunicar su estado sin introducir una segunda interpretación del negocio en el navegador.

Este hito de diseño no implementa PHP, JavaScript, CSS, shortcodes, páginas, endpoints ni pruebas. Tampoco modifica Checkout, Payments, Webpay, Completion, persistencia o rutas REST.

El frontend se incorporará al módulo público existente `app/Modules/Frontend`, reutilizará su carga de assets, configuración REST y sistema visual, y consumirá exclusivamente el read model autenticado de Customer Panel.

## 2. Principios de diseño

1. **El backend conserva la autoridad.** El navegador representa los campos recibidos; no deriva estados de Checkout, Payment, PaymentSession, Reconciliation, BusinessCompletion, DeliveryCompletion o FulfillmentCompletion.
2. **Privacidad por defecto.** Ninguna compra se incorpora al HTML inicial, a variables globales, a almacenamiento web ni a una respuesta pública. La autorización se resuelve en cada petición REST con la sesión WordPress vigente.
3. **Fallo seguro.** Un identificador inexistente, ajeno o no visible produce la misma experiencia neutra. La interfaz no confirma la existencia de compras de terceros.
4. **Mejora progresiva.** Lista y detalle tienen URLs navegables. JavaScript mejora la transición, pero los enlaces siguen siendo enlaces reales y una recarga reconstruye la vista desde la URL.
5. **Una sola gramática visual.** Se reutilizan tokens y componentes de `veciahorra-frontend.css`; cualquier selector nuevo queda limitado por `.veciahorra-frontend`.
6. **Sin estado durable en el cliente.** La memoria de lista, foco y scroll dura solamente durante la instancia de la página. No se usa `localStorage`, `sessionStorage`, cookies propias, Service Worker ni caché persistente.
7. **Accesibilidad desde la estructura.** El contenido sigue siendo comprensible sin color, hover, animación ni disposición de escritorio.

## 3. Infraestructura real sobre la que se construye

La implementación pública actual centraliza vistas y assets en:

- `app/Modules/Frontend/FrontendModule.php`: registro de shortcodes y assets.
- `app/Modules/Frontend/Controller/FrontendController.php`: render, encolado y configuración de cada superficie.
- `app/Modules/Frontend/Assets/FrontendAssets.php`: handles, dependencias y objeto global `window.VeciAhorra`.
- `app/Modules/Frontend/Support/ViewRenderer.php`: allowlist estricta de vistas.
- `app/Modules/Frontend/Views/`: shells PHP accesibles.
- `assets/frontend/css/veciahorra-frontend.css`: tokens y componentes compartidos.
- `assets/frontend/js/veciahorra-frontend.js`: utilidades comunes de la API y comportamiento base.

La configuración actual ya publica:

- `restUrl`, con base `/wp-json/veciahorra/v1/`;
- `nonce`, creado con la acción `wp_rest` para usuarios autenticados;
- `currentUser.id` y `currentUser.loggedIn`;
- `pages.orders`, actualmente resuelto a `/mis-pedidos/`;
- una utilidad REST que usa `X-WP-Nonce`, `credentials: "same-origin"` y rutas relativas validadas.

No existe todavía un shortcode, una vista o un script del Panel del Cliente. Tampoco existe en el plugin un mecanismo que cree automáticamente la página de pedidos.

### 3.1 Clasificación de lo documentado

Para evitar que una propuesta se confunda con el contrato vigente, este documento usa cuatro categorías:

- **Hecho comprobado:** comportamiento que ya existe en el código del Hito 29.2 o en la infraestructura Frontend actual.
- **Decisión de frontend:** conducta que deberá implementar el Hito 29.3 sin cambiar el read model.
- **Riesgo:** limitación o defecto observado que el navegador solo puede degradar de forma segura.
- **Dependencia futura:** cambio no autorizado por este documento y necesario para ampliar capacidades.

Las secciones de contratos REST describen hechos comprobados. Las secciones de navegación, estado UI y presentación contienen decisiones de frontend. La sección 21 enumera riesgos y dependencias futuras.

## 4. URL canónica, página y shortcode

### 4.1 Decisión

- **Slug canónico:** `/mis-pedidos/`.
- **Shortcode propuesto:** `[veciahorra_customer_panel]`.
- **Vista de lista:** `/mis-pedidos/`.
- **Vista de detalle:** `/mis-pedidos/?compra={checkout_public_id}`.

La elección de `/mis-pedidos/` no crea una convención nueva: `FrontendAssets` ya lo expone como `pages.orders`. El administrador deberá publicar una página WordPress con ese slug e insertar el shortcode. El plugin no creará la página, no modificará reglas de rewrite y no ejecutará `flush_rewrite_rules()`.

`compra` es un parámetro de navegación, no una credencial ni una autorización. En el cliente se lee con `URLSearchParams` y se codifica con `encodeURIComponent`/`URLSearchParams`; el backend vuelve a comprobar la propiedad de la compra.

### 4.2 Inserción en una página distinta

El shortcode podrá renderizarse técnicamente en otra página, sin redirecciones forzadas ni errores por slug. Sin embargo:

- los enlaces de detalle siempre se construirán desde `VeciAhorra.pages.orders`;
- el enlace «Volver a mis compras» siempre apuntará a la URL canónica limpia;
- la página distinta se considerará una ubicación no canónica y no definirá URLs alternativas de detalle.

Esto evita que una inserción accidental fracture el historial del navegador. La publicación efectiva de `/mis-pedidos/` es una dependencia administrativa que debe verificarse antes de liberar la implementación.

### 4.3 Shortcode repetido

Solo la primera aparición del shortcode en una respuesta será un montaje funcional. Una segunda aparición mostrará un aviso accesible y un enlace a `/mis-pedidos/`; no lanzará otra consulta ni duplicará IDs del DOM. El controlador llevará un contador por request, mientras que los assets seguirán encolándose una sola vez mediante el mecanismo existente.

Esta es una decisión nueva del Panel del Cliente, no el comportamiento actual de los otros shortcodes, que generan un `instanceId` distinto en cada render. El aviso de duplicidad no incluirá una segunda región `aria-live` ni repetirá el contenido principal.

## 5. Autenticación y privacidad

### 5.1 Usuario no autenticado

El shell PHP comprobará `is_user_logged_in()` antes de habilitar el montaje. Para una sesión anónima:

- no se solicitarán endpoints del panel;
- no se incluirán identificadores de compra;
- se mostrará el título «Mis compras», un mensaje de acceso requerido y un enlace de inicio de sesión;
- el enlace se generará con `wp_login_url()` y una redirección de retorno únicamente a la lista canónica `/mis-pedidos/`.

Puede encolarse el CSS compartido para presentar el mensaje. El script específico del panel no es necesario en esta rama.

No habrá redirección automática. El parámetro `compra` se elimina del retorno a login aunque estuviera en la barra de direcciones: conservarlo lo copiaría al `href` del login y, por tanto, al HTML anónimo. Esta decisión prioriza no propagar el identificador. Tras autenticarse, el usuario vuelve a la lista y puede abrir el detalle desde una compra autorizada. `wp_login_url()` se usa una sola vez y la página de login no apunta de vuelta a sí misma, por lo que no se crea un bucle.

### 5.2 Sesión autenticada

Las peticiones usarán exclusivamente:

- cookie de sesión WordPress del mismo origen;
- nonce REST `wp_rest` en `X-WP-Nonce`;
- `credentials: "same-origin"`;
- base REST generada por WordPress.

No se enviará un ID de usuario desde la interfaz. Los endpoints obtienen la identidad con `get_current_user_id()` y protegen la lectura con `is_user_logged_in()`.

### 5.3 Sesión vencida o nonce inválido

Un `401` o `403`, incluidos errores estándar de WordPress como un nonce REST inválido, se tratará como sesión no disponible. Una cookie vencida hace que WordPress deje de reconocer al usuario y la permission callback rechaza la llamada antes del callback del módulo; un nonce inválido también puede producir un error WP REST antes de obtener el envelope privado.

La interfaz descartará del DOM los datos de compra, reemplazará el área por el mismo bloque seguro de acceso y ofrecerá login con retorno a la lista canónica o recarga. No intentará renovar el nonce, no agregará un endpoint de renovación y no repetirá automáticamente la petición. La recarga completa es el único mecanismo para recibir una configuración/nonce nuevo si la sesión todavía es válida.

## 6. Endpoints consumidos

El frontend consumirá exactamente las rutas introducidas en el Hito 29.2:

| Uso | Método y ruta | Respuesta exitosa |
| --- | --- | --- |
| Listado | `GET /veciahorra/v1/customer-panel/purchases` | `{ "success": true, "data": PurchaseSummary[] }` |
| Detalle | `GET /veciahorra/v1/customer-panel/purchases/{checkout_public_id}` | `{ "success": true, "data": PurchaseDetail }` |

Ambas rutas están registradas bajo el namespace exacto `veciahorra/v1` con `WP_REST_Server::READABLE`, cuyo método declarado es `GET`, callback de permisos `CustomerPanelRoutes::isAuthenticated()` y comprobación `is_user_logged_in()`. Los callbacks ignoran el objeto request en listado, obtienen la identidad exclusivamente mediante `get_current_user_id()` y no aceptan un `user_id` del cliente.

No hay argumentos REST declarados para el listado ni query parameters consumidos. El frontend enviará un GET sin parámetros. El repositorio usa el límite predeterminado exacto de 20 y ordena por `checkouts.created_at DESC, checkouts.id DESC`: compras más recientes primero y, ante igualdad de fecha, ID interno mayor primero. No se entrega cursor, página, total ni `has_more`.

El detalle declara únicamente el parámetro de ruta `checkout_public_id` mediante `(?P<checkout_public_id>[^/]+)`. El servicio valida después la forma exacta `^chk_[A-Za-z0-9_-]{43}$`. Un valor con forma inválida, un ID inexistente, uno perteneciente a otra persona o una proyección excluida por invariantes desembocan uniformemente en `404 customer_order_not_found`. El lookup exige `owner_type = "user"`, `user_id` igual al usuario de la sesión y origen durable VeciAhorra. La presencia del ID en una URL nunca prueba ownership.

Los callbacks exitosos agregan `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache` y `Vary: Cookie`. El frontend no contradecirá esas directivas mediante una caché propia.

Los errores controlados del módulo tienen esta forma:

```json
{
  "success": false,
  "error": {
    "code": "customer_order_not_found",
    "message": "..."
  }
}
```

La denegación producida antes del callback por WordPress puede usar un status `401` o `403` y el envelope estándar de WP REST. Por ello, el cliente clasificará primero por status HTTP y después validará la forma del body; no asumirá que todos los errores contienen `success` y `error`.

Los callbacks del módulo responden `200` en éxito, `404` para `customer_order_not_found`, `422` para `invalid_query` y `500` para `customer_panel_unavailable`. Con el código actual, la entrada pública inválida del detalle se transforma específicamente en 404; `invalid_query` queda como error controlado general del controlador, no como resultado esperado de esa validación.

## 7. Contrato de listado

`data` es un array plano. Cada elemento contiene:

| Campo | Tipo real | Uso de presentación |
| --- | --- | --- |
| `checkout_public_id` | `string` | Construir la URL y solicitar el detalle. No se muestra como dato principal. |
| `created_at` | `string` ISO UTC | Fecha de creación localizada. |
| `total.amount` | `string` decimal | Total, sin cálculos en el cliente. |
| `total.currency` | `string` | Moneda para formato visual. |
| `product_quantity` | `integer` | Cantidad total de productos. |
| `order_count` | `integer` | Número de órdenes internas. |
| `minimarket_count` | `integer` | Número de minimarkets. |
| `minimarkets` | `string[]` | Nombres actuales de minimarkets. |
| `fulfillment_method` | `string \| null` | Normalmente `pickup` o `delivery`; un valor durable inesperado puede llegar junto a estado `under_review`. |
| `visible_status.code` | `string` | Selección limitada del tono visual del badge. |
| `visible_status.label` | `string` | Texto visible autoritativo del estado. |
| `visible_status.message` | `string` | Explicación autoritativa del estado. |
| `requires_review` | `boolean` | Refuerzo visual de revisión, sin recalcular el estado. |

La tarjeta/fila completa será un enlace real al detalle. El nombre accesible combinará fecha, minimarket y estado, evitando enlaces genéricos «Ver más» repetidos sin contexto.

Los valores monetarios del listado no son números JSON ni textos localizados: `amount` es un string decimal normalizado con dos posiciones (por ejemplo, `"12990.00"`) y `currency` es un string procedente del Checkout. Las fechas tampoco vienen formateadas para lectura: son strings UTC `Y-m-dTH:i:sZ`. El frontend solo puede aplicar formato visual conforme a la sección 16.

Los nombres de minimarket proceden del catálogo actual y no constituyen snapshot histórico. Si el array está vacío, la interfaz mostrará «Minimarket no disponible»; no inferirá el nombre a partir del número de órdenes.

## 8. Contrato de detalle

### 8.1 Raíz y resumen

| Campo | Tipo real | Uso |
| --- | --- | --- |
| `checkout_public_id` | `string` | Identidad técnica secundaria y navegación. |
| `created_at` | `string` ISO UTC | Fecha de la compra. |
| `visible_status` | `{code,label,message}` | Encabezado de estado autoritativo. |
| `requires_review` | `boolean` | Refuerzo accesible cuando corresponda. |
| `fulfillment.method` | `string \| null` | Método técnico. |
| `fulfillment.label` | `string` | Etiqueta autoritativa del método. |
| `summary.subtotal` | `string` | Subtotal visual. |
| `summary.total` | `string` | Total visual. |
| `summary.currency` | `string` | Moneda del resumen y fallback de importes. |
| `summary.product_quantity` | `integer` | Unidades totales. |
| `summary.line_count` | `integer` | Líneas de producto. |
| `summary.order_count` | `integer` | Órdenes internas. |
| `summary.minimarket_count` | `integer` | Minimarkets involucrados. |

### 8.2 Órdenes e ítems

Cada elemento de `orders` contiene:

```text
minimarket: { name: string, historical: false }
subtotal: string
items: ProductLine[]
```

Cada `ProductLine` contiene:

```text
name: string
name_historical: false
image: string|null
image_historical: false
quantity: integer
unit_price: string
subtotal: string
```

El servicio garantiza un nombre string no vacío mediante el fallback `Producto {posición} del pedido`; el contrato no entrega un nombre nulo. El nombre y la imagen son referencias actuales del catálogo. La interfaz no los presentará como evidencia histórica inmutable. Las imágenes serán decorativas porque el nombre del producto está junto a ellas (`alt=""`), tendrán `loading="lazy"` salvo las visibles inicialmente, `decoding="async"` y dimensiones/aspect ratio reservados para prevenir layout shift. Una URL `null`, vacía, con protocolo no permitido o una carga fallida se reemplaza visualmente por un placeholder neutro sin retirar el nombre, la cantidad, el precio unitario ni el subtotal. Los nombres largos envolverán palabras y no expandirán la cuadrícula.

### 8.3 Pago

`payment` puede ser `null`. Cuando existe contiene:

```text
status: "received"|"pending"
label: string
amount: string
currency: string
paid_at: string|null
method: "Webpay Plus"|null
```

La interfaz mostrará la etiqueta entregada, el monto y, si existen, método y fecha. `payment: null` se presentará como «Información de pago no disponible». No se convertirá la ausencia en «pendiente», «rechazado» o «cancelado».

### 8.4 Entrega

`delivery` contiene:

```text
method: string|null
status: string
label: string
```

La interfaz mostrará `label`. `status` podrá seleccionar únicamente un tono o icono visual dentro de una allowlist; nunca alterará el estado visible general ni generará pasos de negocio.

### 8.5 Línea de tiempo

`timeline` es una lista ordenada de:

```text
code: string
label: string
occurred_at: string
```

Los códigos actualmente producidos son `checkout_created`, `payment_confirmed`, `payment_reconciled`, `orders_materialized` y `delivery_created`. El DTO no contiene descripción y `occurred_at` no es nullable. El servicio siempre agrega `checkout_created`, agrega los demás solo cuando su timestamp fuente no está vacío y ordena todos los eventos en el servidor por `[occurred_at, code]` ascendente. Puede emitir varios `delivery_created`.

El frontend conservará exactamente el orden recibido: no reordenará, deduplicará ni asumirá una secuencia universal. Representará cada entrada como evento consumado usando literalmente `label` y formateando `occurred_at`. Un código futuro también se muestra con tratamiento neutro. Aunque el código actual normalmente produce al menos un evento, el cliente tolerará un array vacío con «No hay eventos para mostrar». Un evento malformado se trata como contrato incompleto; no se inventa un reemplazo. No se dibujarán hitos futuros, porcentajes, pasos faltantes ni una barra de progreso.

## 9. Arquitectura frontend propuesta

La futura implementación debería limitarse a la infraestructura pública existente:

| Responsabilidad | Ubicación propuesta |
| --- | --- |
| Registro del shortcode | `app/Modules/Frontend/FrontendModule.php` |
| Render, autenticación y encolado | `app/Modules/Frontend/Controller/FrontendController.php` |
| Registro del handle específico | `app/Modules/Frontend/Assets/FrontendAssets.php` |
| Allowlist de vista | `app/Modules/Frontend/Support/ViewRenderer.php` |
| Shell accesible | `app/Modules/Frontend/Views/customer-panel.php` |
| Interacción y render dinámico | `assets/frontend/js/veciahorra-customer-panel.js` |
| Estilos scoped | `assets/frontend/css/veciahorra-frontend.css` |

No se propone un segundo módulo frontend dentro de Customer Panel: el módulo `Frontend` ya es la frontera de presentación pública y Customer Panel ya expone el read model. Esta separación evita mezclar HTML con la autoridad de negocio.

El handle propuesto es `veciahorra-customer-panel`; dependerá del handle base `veciahorra-frontend`, como ya lo hacen catálogo, carrito, checkout y ofertas. Se registrará con `wp_register_script()`, en footer y con `Config::PLUGIN_VERSION`, la misma versión usada por todos los assets públicos. El CSS seguirá en el handle compartido `veciahorra-frontend`, también versionado con `Config::PLUGIN_VERSION`; no se creará una copia de la hoja completa. El script se registrará desde `FrontendAssets::registerAssets()` y se encolará solo cuando el primer shortcode funcional se renderice.

El punto de bootstrap comprobado es `Application::run()`: el contenedor crea `FrontendModule` y llama `register()`. Ese método conecta `FrontendAssets::registerAssets()` a `wp_enqueue_scripts` y registra cada shortcode con `add_shortcode()` apuntando a un método de `FrontendController`. El Panel seguirá exactamente esa ruta: una constante nueva en el controlador, un `add_shortcode()` nuevo en `FrontendModule` y un método de render dedicado.

No se buscará el shortcode mediante una inspección simple de `post_content`. La carga se decide al ejecutar realmente el callback, como en los shortcodes actuales; esto cubre bloques reutilizables, widgets y contenido procesado fuera del loop. En `is_admin()` —incluido el editor que procesa el shortcode como petición administrativa— el callback seguirá la convención actual y devolverá string vacío, sin assets. Una vista previa pública donde `is_admin()` sea falso podrá montar normalmente. Existe una dependencia de WordPress si un tercero ejecuta el shortcode después de que el tema ya imprimió estilos: el encolado en tiempo de render puede ser tardío para CSS; no se solucionará con heurísticas sobre `post_content` en este hito.

La vista PHP será deliberadamente simple: decide rama autenticada/anónima, genera IDs con `esc_attr`, texto con `esc_html`/funciones i18n y compone el shell allowlisted. No consulta el read model ni replica una capa REST. Toda validación de respuesta y creación de nodos ocurre en el script de presentación, sin lógica de dominio.

## 10. Shell PHP y contrato del DOM

El HTML inicial autenticado contendrá:

- wrapper `.veciahorra-frontend`;
- skip link existente hacia el `<main>`;
- `<main class="va-container va-customer-panel" tabindex="-1">`;
- encabezado con título «Mis compras» y texto breve;
- región de estado inicial;
- región principal de resultados;
- región `aria-live="polite"` reutilizando el patrón announcer;
- un identificador de instancia no sensible.

Los hooks de JavaScript usarán atributos `data-va-*`; las clases se reservarán para estilo. Habrá una sola raíz con ID generado y encapsulación `.veciahorra-frontend .va-customer-panel`. El HTML inicial no contendrá JSON de compras, IDs públicos, datos personales ni resultados de una consulta al read model. El script leerá únicamente configuración no sensible de `window.VeciAhorra` y la URL actual.

JavaScript es requisito funcional porque PHP no obtiene compras. Un `<noscript>` dentro de la región principal mostrará una sola vez «Activa JavaScript para consultar tus compras» y no intentará duplicar lista, detalle, headings o regiones live. El shell seguirá ofreciendo landmark, título y explicación aun sin JavaScript.

### 10.1 Configuración pública comprobada

`FrontendAssets::configuration()` obtiene la base con `rest_url('veciahorra/v1/')`, la sanea con `esc_url_raw()` y crea el nonce solamente para un `get_current_user_id() > 0` mediante `wp_create_nonce('wp_rest')`. También expone locale, moneda, URLs públicas y estado básico del usuario.

La entrega no usa `wp_localize_script`: `enqueue()` serializa con `wp_json_encode()` y `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, y agrega antes del script base, mediante `wp_add_inline_script()`, una asignación a `window.VeciAhorra`. Es una global existente, específica del proyecto y compartida por todas las instancias; el Panel no creará otra global genérica. El script base añade `VeciAhorra.api`, envía cookie same-origin y `X-WP-Nonce` cuando existe.

El Panel no agregará payloads, IDs de compra, tokens, cookies ni credenciales a esa configuración y nunca la registrará en consola. El nonce no se renueva en caliente. Varias instancias leen la misma configuración inmutable, pero solo la primera monta funcionalmente según 4.3.

## 11. Navegación lista–detalle

### 11.1 Lectura y canonicalización

La lectura se hará con `new URL(window.location.href)` y `url.searchParams.getAll('compra')`; no se decodificará manualmente con `decodeURIComponent`. La única validación local será estructural y coincidirá con el formato público comprobado: exactamente un valor y regex `^chk_[A-Za-z0-9_-]{43}$`. Esta comprobación evita segmentos ambiguos, pero no reconstruye reglas de negocio ni determina existencia, visibilidad u ownership.

La decisión para cada forma de URL es:

| Entrada | Conducta |
| --- | --- |
| Sin `compra` | Cargar listado. |
| Un `compra` válido estructuralmente | Cargar directamente el detalle, codificando el segmento con `encodeURIComponent`. |
| `compra=` vacío | No llamar REST; mostrar el mismo `not_found` uniforme. |
| Dos o más valores `compra` | No elegir uno; mostrar `not_found`. |
| Escape porcentual inválido o caracteres que no cumplen la regex | No llamar REST; mostrar `not_found`. `URLSearchParams` evita una excepción de decodificación manual. |
| Parámetros desconocidos adicionales | Ignorarlos para la lectura; retirarlos al generar URLs internas canónicas. |
| Hash fragment | No usarlo para estado; retirarlo al generar lista o detalle canónicos. |

La URL canónica de lista es exactamente el valor configurado en `VeciAhorra.pages.orders`, sin query ni fragment. La URL canónica de detalle conserva únicamente `compra={valor codificado}`. Al arrancar desde una variante con parámetros desconocidos o hash, el frontend puede ejecutar `history.replaceState()` hacia la forma canónica después de decidir la vista, sin crear una entrada adicional. No imprime el ID en el shell inicial: el identificador ya presente en la dirección solo se lee en el navegador y se envía al endpoint privado.

### 11.2 Apertura de detalle

Cada compra tendrá un `href` canónico. Con JavaScript disponible:

- se intercepta únicamente el click primario sin modificadores y del mismo origen;
- se ejecuta `history.pushState()` con la URL del enlace;
- se conserva en memoria la lista, la posición de scroll y el elemento que originó la navegación;
- se muestra el loader de detalle y se solicita el endpoint correspondiente.

Clicks con teclado, apertura en otra pestaña y copia de enlace siguen funcionando mediante el `href` real.

### 11.3 Regreso

El detalle incluye un enlace «Volver a mis compras» hacia la URL canónica limpia, eliminando `compra`, parámetros desconocidos y hash. La mejora JavaScript usa `history.pushState()`/`replaceState()` hacia esa URL; no ejecuta `history.back()` a ciegas porque la entrada anterior podría ser externa. El botón Atrás del navegador sí dispara `popstate`, que vuelve a resolver la URL:

- si la lista permanece en memoria, se restaura sin una petición adicional, se recupera scroll y el foco vuelve al enlace originador;
- si se llegó directamente al detalle o se recargó, se solicita el listado al volver.

No se manipula `document.title` con información sensible más allá de «Mis compras» o «Detalle de compra».

Un refresh directo reconstruye la vista desde `location`: pide solo el detalle cuando hay un único ID estructuralmente válido y no presupone una lista previa. Un enlace compartido funciona igual, siempre sujeto a sesión y ownership en el servidor.

## 12. Modelo de estados de interfaz

| Estado UI | Entrada | Salida permitida | Presentación |
| --- | --- | --- | --- |
| `booting` | shell autenticado recién montado | `loading_list`, `loading_detail`, `not_found`, `unauthenticated` | Sin datos; decide la URL una sola vez. |
| `unauthenticated` | PHP anónimo o REST 401/403 | login/recarga | Mensaje seguro, sin datos. |
| `loading_list` | entrada sin `compra` | `list_ready`, `list_empty`, `recoverable_error`, `unauthenticated` | Skeleton/loader y `aria-busy=true`. |
| `list_ready` | array válido no vacío | `loading_detail`, `loading_list` | Filas/tarjetas y snapshot efímero de lista. |
| `list_empty` | array válido vacío | `loading_list` | Empty state «Aún no tienes compras para mostrar». |
| `loading_detail` | entrada con `compra` o click | `detail_ready`, `not_found`, `recoverable_error`, `unauthenticated`, lista | Encabezado y, si existe, snapshot de lista fuera de la vista activa. |
| `detail_ready` | detalle válido | lista, otra navegación | Resumen, órdenes, pago, entrega y timeline. |
| `not_found` | 404 o identificador local inválido | volver a lista | «La compra no está disponible». |
| `recoverable_error` | red, timeout, 422/500, body inválido | reintento o lista | Mensaje recuperable sin detalles internos. |

`booting` es siempre el estado inicial del montaje autenticado; la rama PHP anónima entra directamente en `unauthenticated` sin montar el script. Estos nombres describen exclusivamente presentación y nunca se comparan con `visible_status.code`.

Durante cada petición se deshabilita solo el control que podría repetir esa acción y se marca el contenedor con `aria-busy`. No se bloquea el enlace para volver. El botón «Reintentar» crea una única nueva generación para la URL vigente. Al abrir detalle se conserva en memoria el snapshot de lista, foco y scroll; un error de detalle no lo destruye y «Volver» lo restaura. Al recibir lista nueva se descarta el snapshot anterior. `unauthenticated` descarta todos los payloads y nodos con datos. Un detalle directo no crea un snapshot ficticio.

Navegar durante carga cancela lógicamente la operación anterior y empieza el estado que corresponda a la URL nueva. Volver desde `loading_detail` restaura una lista previa de inmediato o entra en `loading_list` si no existía.

## 13. Concurrencia, timeout y respuestas obsoletas

El script mantendrá por instancia:

- un `AbortController` para la petición activa;
- un número de secuencia monotónico;
- el modo y el identificador esperados;
- un timeout máximo de 12 segundos, consistente con las superficies públicas existentes.

Antes de navegar o reintentar se aborta la petición anterior. Una respuesta solo puede modificar el DOM si su secuencia, URL e identificador siguen siendo los activos. Así, abrir A, volver y abrir B no permite que una respuesta tardía de A reemplace B.

El doble click queda cubierto por un guard de navegación más la deshabilitación transitoria: una URL ya activa no inicia otra generación. Cada reintento incrementa la generación y cancela la anterior. Al desmontar o reinicializar se marca la instancia como destruida, se incrementa la generación, se retira `popstate` y se aborta la petición si es posible.

`AbortController` se usará cuando esté disponible. Si no existe, la petición no se cancela físicamente, pero la validación obligatoria de generación/URL/instancia impide que una respuesta obsoleta renderice. `AbortError` provocado por navegación o desmontaje no cambia estado ni se anuncia. Un timeout sí produce `recoverable_error`; sin AbortController, el timeout invalida la generación aunque la red continúe. No habrá polling, precarga de todos los detalles ni reintentos automáticos.

## 14. Validación defensiva del contrato

El cliente validará el envelope y los tipos necesarios antes de renderizar:

- `success === true` y `data` presente para respuestas exitosas;
- array raíz para el listado;
- strings no vacíos para ID, fecha, moneda y etiquetas esenciales;
- enteros finitos no negativos para contadores y cantidades;
- arrays reales para minimarkets, órdenes, ítems y timeline;
- objetos o `null` solo donde el contrato lo permite.

Si falla la raíz del contrato, se mostrará un error recuperable y no se renderizarán fragmentos potencialmente engañosos. En campos secundarios opcionales se aplica fallback explícito: imagen placeholder, fecha no disponible, información de pago no disponible o etiqueta neutra. Nunca se imprimen objetos mediante coerción implícita.

Todo texto remoto se insertará en nodos creados con `document.createElement()` y `textContent`; no se usará `innerHTML`, templates interpolados ni `insertAdjacentHTML` con valores REST. Los atributos se asignarán desde valores controlados: clases desde allowlists, cantidades convertidas a texto y enlaces de navegación construidos con `URL`. No habrá handlers inline ni datos de compra en atributos `data-*` salvo el ID estrictamente necesario en un `href` canónico; el handler obtendrá ese ID de la URL validada, no de HTML oculto.

Las imágenes solo aceptarán URLs absolutas `https:` o `http:` analizadas con `new URL()`; se descartarán credenciales embebidas y protocolos como `data:`, `blob:`, `javascript:` o `file:`. El contrato actual puede devolver URLs de attachments del sitio, pero no existe una garantía DTO explícita de mismo origen, por lo que exigir same-origin queda como decisión de seguridad del frontend. No se almacenarán respuestas en `localStorage`, `sessionStorage`, IndexedDB, Cache API ni Service Worker.

## 15. Estados visibles y representación semántica

Los códigos estables que hoy puede entregar `CustomerPurchaseStatusResolver` son:

| Código | Tratamiento visual propuesto |
| --- | --- |
| `pending_payment` | advertencia suave |
| `processing_payment` | informativo |
| `payment_rejected` | peligro |
| `payment_received` | éxito/informativo |
| `preparing_order` | informativo |
| `preparing_delivery` | informativo |
| `out_for_delivery` | informativo destacado |
| `delivered` | éxito |
| `cancelled` | neutro con borde de peligro |
| `under_review` | advertencia |

El contrato real entrega `visible_status.code` como clave estable y `visible_status.label`/`message` como semántica visible. No entrega `severity`, categoría visual ni color. La tabla anterior es, por tanto, una decisión de presentación local que selecciona únicamente una clase decorativa dentro de una allowlist; no es un segundo resolver. El badge y el mensaje muestran siempre label/message recibidos. `requires_review` es un booleano ya calculado por el servidor y puede añadir texto accesible «Requiere revisión», pero no reemplaza el código ni calcula otro estado.

Un código desconocido usa tono neutro y conserva label/message. No se mapea desde el texto traducido, no oculta la compra, no bloquea acciones y no se trata como error contractual si el resto del objeto es válido. El texto visible acompaña siempre al color.

El navegador no recibe ni inspecciona los estados financieros internos usados por el resolver. No consulta Delivery, Payment, PaymentReconciliation, BusinessCompletion o FulfillmentCompletion, no compara timestamps para inferir cumplimiento y no convierte `payment: null`, timeline vacío o ausencia de delivery en una conclusión. `delivery.status` y `payment.status` son campos públicos del read model para sus secciones descriptivas; no pueden reemplazar ni modificar `visible_status`.

## 16. Formato de moneda, cantidades y fechas

- Los importes vienen ya normalizados por backend como strings decimales con dos posiciones, pero no vienen localizados. Se conservan como strings; el cliente no suma, resta, redondea, compara ni recalcula subtotal/total.
- El locale primario será `VeciAhorra.locale`, que el código actual obtiene con `determine_locale()`, convierte `_` a `-` y sanea. Se validará construyendo `Intl.NumberFormat`; el fallback exacto será `es-CL`, nunca `es_CL`.
- Para CLP, solo un string que cumpla `^(0|[1-9]\d*)\.00$` se convierte de forma segura a entero y se presenta con `Intl.NumberFormat(locale, {style: "currency", currency: "CLP", minimumFractionDigits: 0, maximumFractionDigits: 0})`. Si no termina en `.00`, se muestra el string original seguido de `CLP` para no ocultar precisión ni redondear.
- Para otra moneda, solo se aplica `Intl.NumberFormat` cuando la moneda es un código admitido por `Intl` y el decimal puede convertirse sin pérdida para el rango seguro previsto; cualquier duda usa el fallback textual `{amount} {currency}`.
- La moneda autoritativa es la del propio objeto (`total.currency`, `summary.currency` o `payment.currency`), no `VeciAhorra.currency`.
- Las cantidades se presentarán con singular/plural visual, sin modificar el número autoritativo.
- Las fechas vienen como ISO UTC `Y-m-dTH:i:sZ`, no como texto localizado. `paid_at` es el único timestamp público nullable; los timestamps de timeline no son nullable.
- Para evitar que la zona arbitraria del visitante cambie el día mostrado, la futura configuración deberá exponer un `timeZone` no sensible obtenido de `wp_timezone_string()` y serializado por el mecanismo existente. `Intl.DateTimeFormat(locale, {timeZone, dateStyle: "medium", timeStyle: "short"})` será el formato. Si `timeZone` falta o es inválida, el fallback será `UTC`, indicado en el texto o nombre accesible; no se usará silenciosamente la zona del navegador.
- `paid_at: null` omite la fila de fecha de pago; no implica pago pendiente. Una fecha inválida muestra «Fecha no disponible» y no participa en orden, progreso ni inferencias.

## 17. Diseño visual responsivo

### 17.1 Tokens y componentes existentes

Se reutilizarán las variables actuales de color, texto, borde, background, espaciado, radios y sombra, junto con:

- `.va-container`;
- `.va-card`;
- `.va-button` y variantes;
- `.va-alert`;
- `.va-empty-state`;
- `.va-loader`;
- `.va-visually-hidden`;
- skip link y estilos globales de foco.

Los selectores nuevos seguirán el patrón `.veciahorra-frontend .va-customer-panel__*`. No se introducirán resets globales.

### 17.2 Lista

La lista será semánticamente un `<ol>` o `<ul>` de artículos enlazados, no una tabla HTML. En escritorio cada artículo se dispondrá como fila CSS Grid con columnas para fecha/identificación, minimarkets, estado, productos y total. En móvil la misma estructura se apilará como tarjeta, preservando el orden de lectura:

1. fecha;
2. minimarket(s);
3. estado y mensaje;
4. cantidades/método;
5. total;
6. acción de detalle.

Esto evita scroll horizontal y duplicación de marcado: no coexistirán una tabla desktop y cards mobile en el DOM. Cada track grid usará `minmax(0, 1fr)` donde corresponda; importes podrán envolver sin perder moneda, y el `checkout_public_id` no se mostrará como columna. Los targets interactivos conservarán un alto y ancho táctil mínimo de `2.75rem`.

### 17.3 Detalle

En pantallas amplias el encabezado y resumen podrán usar dos columnas; órdenes y timeline mantienen una sola columna legible. En móvil todo se apila. Cada minimarket es una sección con heading, ítems y subtotal; cada producto utiliza una cuadrícula pequeña de imagen, nombre/datos y subtotal.

Se tomarán como referencias los breakpoints existentes (`30rem`, `48rem`, `64rem`, `72rem`), priorizando el cambio principal alrededor de `48rem`. No se crearán breakpoints basados en dispositivos específicos. El panel tendrá `width: 100%`, `min-width: 0` y respetará `.va-container` (`max-width: 80rem`) dentro del contenedor de contenido de Blocksy, sin usar anchos de viewport ni márgenes negativos.

A zoom del 200 %, viewport estrecho u orientación horizontal activarán naturalmente el layout apilado. No habrá overflow horizontal de página; nombres, mensajes e importes usarán wrapping seguro. Timeline y listas largas de productos crecen verticalmente, sin carruseles ni regiones de scroll anidadas. Podrá usarse `content-visibility` solo si no retira contenido del árbol accesible; no es requisito v1.

### 17.4 Movimiento y hover

Hover será solo una mejora de borde/sombra y nunca la única señal de interacción. Con `prefers-reduced-motion: reduce` se desactivarán transiciones, skeleton animado y cualquier movimiento, siguiendo el tratamiento actual del loader.

### 17.5 Integración con las superficies existentes

Catálogo, ficha de producto, carrito y checkout comparten realmente la raíz `.veciahorra-frontend`, `.va-container`, botones, alertas, cards, loader, empty state, announcer, foco, tokens y breakpoints. Esos patrones visuales y sus componentes PHP simples pueden reutilizarse. El render dinámico no puede «llamar» componentes PHP después de cargar: deberá crear DOM equivalente y usar sus clases públicas sin copiar el CSS.

No se reutilizarán las grillas específicas de catálogo, la tabla específica del carrito, formularios de checkout ni selectores de ofertas porque sus semánticas no corresponden al panel. No habrá dependencia de WooCommerce, de estilos internos de Blocksy ni de clases genéricas del tema. Las reglas nuevas vivirán bajo `.veciahorra-frontend .va-customer-panel`, con especificidad baja y sin `!important`, resets globales o sobrescritura de elementos fuera de la raíz.

## 18. Accesibilidad

- Un único `<h1>` «Mis compras»; el detalle usa un `<h2>` para su encabezado y headings descendentes por minimarket/sección.
- Lista y timeline usan listas semánticas; cantidades y precios conservan texto visible.
- El badge no depende solo del color y siempre incluye label.
- Los loaders incluyen texto accesible y la región activa usa `aria-busy`.
- Cambios de vista y errores se anuncian en una región `aria-live="polite"`; fallos críticos pueden usar `role="alert"` sin duplicar anuncios.
- Tras cargar una ruta, el foco pasa al heading de lista o detalle con `tabindex="-1"`. Al volver, se restaura al enlace originador cuando existe.
- El reintento es un `<button>` real; volver y abrir detalle son `<a>` reales.
- No se agregan `tabindex="0"` a tarjetas completas si ya contienen un enlace principal.
- Las imágenes de producto son decorativas junto a su nombre; el placeholder tampoco necesita texto alternativo redundante.
- Estados de foco usan el outline existente y nunca se eliminan.
- Mensajes no dependen de iconos. Si se usan iconos, serán decorativos con `aria-hidden="true"`.
- Skeletons y formas puramente visuales tendrán `aria-hidden="true"`; existirá un único texto de carga anunciado. El announcer se limpia antes de un mensaje nuevo y no repite actualizaciones por cada ítem renderizado.
- El enlace de la vista vigente podrá usar `aria-current="page"`; no se aplicará a todos los enlaces de tarjetas.
- Colores nuevos deberán conservar al menos contraste WCAG AA para texto y foco. Las tonalidades de badge reutilizarán tokens existentes solo después de verificar contraste de foreground/background.

## 19. Errores y mensajes seguros

| Condición | Respuesta de interfaz |
| --- | --- |
| Lista vacía | «Aún no tienes compras para mostrar.» |
| Detalle 404 | «La compra no está disponible.» |
| 401/403 | «Tu sesión no está disponible. Inicia sesión para ver tus compras.» |
| Error de red/timeout | «No pudimos cargar tus compras. Inténtalo nuevamente.» |
| 422/500 o envelope inválido | Mensaje recuperable equivalente, sin código interno ni stack trace. |
| Imagen fallida | Placeholder; el ítem sigue visible. |
| Código de estado desconocido | Badge neutro con label/message del backend. |

El 404 no diferencia entre inexistencia, pertenencia a otro usuario, eliminación o exclusión por invariantes. Tampoco se mostrará el mensaje técnico del backend si puede revelar detalles. En producción no se usarán `console.log`, `console.error` ni telemetría con payloads, IDs, URLs completas, nonce, cookies, nombres, importes o datos personales. En un modo de desarrollo explícito solo podrá registrarse una categoría local (`network_error`, `invalid_contract`, status HTTP y generación), nunca body ni identificador.

## 20. Límites funcionales explícitos

El frontend v1 es de solo lectura. No incluirá:

- cancelar, repetir o modificar compras;
- iniciar/reintentar pagos;
- acciones de entrega o fulfillment;
- filtros, búsqueda o orden configurable;
- descarga de documentos;
- polling o notificaciones en tiempo real;
- edición de perfil;
- persistencia de respuestas en el navegador;
- inferencias a partir de tablas o endpoints ajenos al read model.
- devoluciones, reclamos o repetición de compra;
- seguimiento en vivo, WebSockets, mapas o notificaciones;
- facturas, boletas o descargas;
- ordenamiento interactivo o paginación simulada;
- nuevas tablas, migraciones o endpoints;
- cambios al read model, Payments, Orders, Reconciliation, Delivery o Fulfillment.

## 21. Dependencias y riesgos detectados

### 21.1 Listado limitado sin paginación

El repositorio devuelve como máximo 20 compras y el contrato no informa si existen más. El frontend no puede detectar si el array de 20 está truncado: recibir exactamente 20 no demuestra que haya una compra 21. Por ello no mostrará «últimas 20», «hay más», «todo tu historial» ni un botón para cargar más; usará el título neutro «Mis compras». No ofrecerá paginación, filtros o búsqueda. Para una futura paginación, el backend deberá añadir un contrato versionado con cursor/página y metadatos.

### 21.2 Página canónica no garantizada

`pages.orders` apunta a `/mis-pedidos/`, pero el plugin no comprueba ni crea esa página. La liberación depende de que WordPress tenga una página publicada con el shortcode. Si el slug cambia, la configuración deberá obtener la URL real mediante una autoridad configurable, no mediante strings duplicados en JavaScript.

### 21.3 Error potencial en la proyección por minimarket duplicado

En la implementación actual de `CustomerPanelService::project()`, la detección de un minimarket repetido retorna `true` pese a que el método declara `?array`. En PHP estricto ese camino puede producir un `TypeError`, que el controlador degrada a `500 customer_panel_unavailable`. Es una dependencia backend del Hito 29.2: el frontend debe mostrar error recuperable, pero no puede corregir ni interpretar esa inconsistencia.

### 21.4 Contrato de autenticación no uniforme

La permission callback de WP REST puede responder `401` o `403` con un body distinto al envelope del módulo. El cliente debe clasificar por status y tolerar ambos formatos.

### 21.5 Identidad visual no histórica

Los nombres de minimarket, nombres de producto e imágenes están marcados explícitamente como no históricos. La interfaz debe evitar expresiones como «imagen al momento de comprar» y no usar esos datos como prueba de lo adquirido históricamente. Cantidades e importes del read model sí son los datos de la compra que se presentan.

### 21.6 Etiqueta de fulfillment en lista

El detalle entrega `fulfillment.label`; el listado solo entrega `fulfillment_method`. La decisión v1 queda cerrada con un diccionario puramente textual: `pickup` → «Retiro», `delivery` → «Despacho», y `null` o cualquier string desconocido → «Por confirmar». No se imprime el valor desconocido ni se deriva un estado. Una futura evolución podría entregar también la etiqueta desde backend, pero no es requisito para implementar v1.

### 21.7 URLs de detalle construidas por cliente

El listado no entrega `detail_url`. El cliente debe unir la URL canónica y un ID público codificado. Cualquier futura variación de permalink debe centralizarse en la configuración PHP; no debe dispersarse entre template y script.

### 21.8 Zona horaria de presentación

La configuración actual expone locale pero no zona horaria. La decisión de formatear instantes en la zona del sitio requiere agregar en la futura implementación el valor no sensible `timeZone` a la configuración existente. Hasta que exista, el fallback explícito es UTC; usar la zona del navegador haría que dos clientes pudieran ver días distintos.

### 21.9 Encolado tardío fuera del flujo normal del tema

La convención actual encola al renderizar el shortcode y no inspecciona anticipadamente `post_content`. Esto funciona en el flujo normal, pero un tercero que procese shortcodes después de imprimir `wp_head` puede dejar el CSS para demasiado tarde. Se documenta como integración no garantizada; no justifica escanear contenido ni cargar assets globalmente.

## 22. Criterios de aceptación para la futura implementación

1. `[veciahorra_customer_panel]` renderiza un shell seguro y solo encola sus assets cuando corresponde.
2. Una sesión anónima no llama a Customer Panel REST ni recibe datos de compras.
3. La lista consume únicamente `GET customer-panel/purchases` y no afirma exhaustividad más allá de los datos recibidos.
4. El detalle consume únicamente `GET customer-panel/purchases/{checkout_public_id}`.
5. Lista, deep link, recarga, atrás/adelante y apertura en nueva pestaña funcionan con `/mis-pedidos/?compra=...`.
6. Una respuesta tardía o abortada nunca reemplaza la vista vigente.
7. `401/403`, `404`, timeout, `500`, body inválido y lista vacía tienen estados distintos, seguros y accesibles.
8. Labels y mensajes visibles proceden del backend; los códigos solo seleccionan estilos de una allowlist.
9. No se calcula un estado desde Payment, Checkout, Completion, delivery o ausencia de registros.
10. No se persisten payloads en almacenamiento del navegador y se respetan las directivas `no-store`.
11. La vista funciona a teclado, restaura foco, anuncia cambios y no depende de color, hover o movimiento.
12. En móvil no existe scroll horizontal y la jerarquía de lectura coincide con el DOM.
13. Una imagen ausente, un pago `null` o un código futuro no rompen la compra completa.
14. Un detalle ajeno e inexistente presentan exactamente el mismo mensaje.
15. Shortcodes duplicados no generan montajes, IDs ni consultas duplicadas.
16. La implementación permanece dentro del módulo `Frontend` y no modifica la autoridad backend del Hito 29.2.

## 23. Fuera de alcance de este documento

Este diseño no autoriza cambios de código. La creación de la página WordPress, la implementación del shortcode, los assets, las pruebas manuales/E2E y cualquier corrección del riesgo backend detectado requieren hitos o instrucciones posteriores y revisión independiente.
