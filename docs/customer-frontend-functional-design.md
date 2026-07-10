# VeciAhorra 28.0 — Customer Frontend Functional Design

## 1. Objetivo, alcance y lectura del documento

Este documento diseña la experiencia pública del cliente para el recorrido
Catálogo → oferta → carrito → checkout → pago → pedidos → seguimiento, sin
implementar frontend ni alterar el backend. La propuesta se limita a las rutas y
datos existentes inspeccionados en el código de `veciahorra/v1`.

Etiquetas usadas:

- **Soportado**: existe una ruta utilizable por ese tipo de cliente y el backend
  entrega los datos necesarios.
- **Diseñado**: comportamiento de presentación previsto, todavía sin UI.
- **No disponible**: el backend actual no ofrece al cliente la autorización,
  consulta o dato necesario. Es un bloqueo, no una invitación a simularlo.
- **Futuro**: mejora fuera de 28.0 y de las fases que dependan de ese bloqueo.

No se crean directorios, páginas, shortcodes, bloques, plantillas, endpoints ni
flujos de negocio. No se modifican Products, Product Catalogs, Inventory, Cart,
Checkout, Orders, Payments, Customer Panel, Delivery, tablas o migraciones.

## 2. Principios y límites de responsabilidad

1. El servidor es autoridad de identidad, autorización, catálogo activo,
   inventario, precio, stock, snapshots, subtotales, totales, reservas, pedidos,
   pagos, delivery, transiciones e idempotencia.
2. El navegador presenta, recoge intención, valida ergonomía básica, evita clics
   accidentales, formatea y vuelve a consultar. Nunca confirma stock, precio,
   total, pago ni propiedad de un recurso.
3. Toda respuesta mutante reemplaza la representación local relacionada. Tras
   un fallo ambiguo se consulta el estado real antes de reintentar.
4. Una oferta identifica `inventory_id`: producto, minimarket, precio y stock no
   se mezclan como si fueran atributos del producto. Cart conserva un snapshot
   de precio; Inventory sigue siendo la fuente vigente.
5. Cada minimarket produce un pedido independiente. La UI puede mostrar un total
   general, pero conserva grupos y pedidos resultantes visibles.
6. La ausencia de un endpoint público no se resuelve consumiendo una ruta de
   administrador, leyendo la base de datos, calculando localmente o exponiendo
   credenciales privilegiadas.

## 3. Arquitectura frontend conceptual

Estructura prevista, no creada:

```text
assets/frontend/
├── css/          # tokens, layout responsive y estilos accesibles
├── js/           # bootstrap y navegación
├── components/   # piezas reutilizables sin reglas de dominio
├── pages/        # composición y ciclo de vida por vista
├── services/     # cliente REST, normalización y códigos de error
├── stores/       # sesión, carrito y estado compartido mínimo
└── utils/        # formato, validaciones de UI y tiempo
```

| Capa | Responsabilidad prevista |
|---|---|
| Pages | Cargar datos, componer componentes, definir título/foco y recuperar errores de la vista. |
| Components | Renderizar datos recibidos y emitir intenciones; no decidir precio, stock ni transición. |
| Services | Construir URLs, nonce/cabeceras, JSON, cancelación, timeout y normalización de errores REST. |
| Stores | Contexto autenticado/invitado, representación canónica del carrito, operaciones en curso y avisos. No duplicar el dominio. |
| Utils | CLP/precio decimal, cantidades enteras, fechas del servidor, etiquetas de estado y texto seguro. |
| Navigation | Rutas de WordPress o router progresivo; URL compartible para producto, pedido y resultado cuando exista soporte seguro. |

El bootstrap obtiene sesión WordPress y configuración inyectada por servidor; no
infiere roles. Para usuario autenticado, Cart/Checkout usan `user_id` derivado en
servidor. Para invitado, conservan el identificador opaco de carrito y lo envían
en `X-Veciahorra-Cart-Session` (preferido) o `session_id`. La generación,
expiración, rotación y migración invitado→usuario no están definidas por una ruta
actual: son decisiones pendientes. No se fusionan carritos localmente.

La sincronización sigue `intent → pending → respuesta → reemplazo/refetch`. Una
respuesta tardía no pisa una más reciente (sequence id/AbortController). Cambios
entre pestañas sólo disparan refetch; `BroadcastChannel` puede transportar una
señal no sensible, nunca el carrito como autoridad.

## 4. Páginas y flujos

### 4.1 Catálogo de productos

**Objetivo/ruta prevista:** explorar productos activos y abrir `/productos` y
`/productos/{slug-o-id}`. **Estado backend: no disponible al cliente.** `GET
/products`, `/products/search` y `/products/{id}` requieren `manage_options`; el
listado de Inventory también es administrativo. No existe una composición
pública producto+catálogos+ofertas.

Datos diseñados: imagen o placeholder, nombre, categoría, marca, unidad y tarjetas
de ofertas activas con minimarket, precio y stock. Producto es identidad y
descripción; inventario vincula producto/minimarket y disponibilidad; oferta es
la presentación elegible de ese inventario; selección es intención local;
disponibilidad real sólo existe tras validación del backend.

Comportamiento diseñado cuando exista fuente pública:

- Sin stock/inventario inactivo/oferta retirada: tarjeta visible sólo si la
  respuesta aún incluye producto, marcada “No disponible”, sin agregar.
- Una o dos ofertas: mostrar todas. Exactamente tres: mostrar tres. Más de tres:
  las tres más económicas y “Ver más”. Empates conservan orden estable del
  servidor; no prometer “más barato” sin comparar valores normalizados.
- Cambio entre cargas: actualizar y anunciar precio/stock; una selección retirada
  se invalida y requiere elección consciente.
- Sin imagen o dato opcional: placeholder y omitir campo, nunca texto roto.
- Fallo parcial: conservar sección válida, identificar la incompleta y reintentar.
  Fallo total: ErrorState. Cero productos: EmptyState, no error.
- Móvil: cuadrícula de una columna, selector de ofertas táctil y acción cercana;
  desktop/tablet usan tarjetas fluidas sin tabla horizontal.

Invitados y autenticados pueden explorar conceptualmente igual; agregar difiere
sólo en identidad de Cart. Hasta resolver el bloqueo, no se implementan catálogo,
detalle ni selector con datos administrativos.

### 4.2 Selector de oferta

Componente conceptual `OfferSelector(product, offers, selectedInventoryId,
quantity)`. Por defecto selecciona la oferta disponible de menor precio; muestra
como máximo tres inicialmente y expande el resto. Al cambiar `inventory_id`,
actualiza precio/stock visibles desde la última respuesta, reinicia o acota la
cantidad sólo como ayuda y anuncia el cambio.

Cantidad mínima: 1; máxima visual: stock informado. Backend revalida ambas. Al
agregar, se bloquea el control mientras `POST /cart/items` está pendiente y se
envía `inventory_id` y `quantity` más identidad. Éxito: reemplazar/refrescar
carrito y anunciar. 404/422: asociar mensaje al selector; 409 si aparece en una
evolución del backend: refrescar oferta; red/500: estado incierto, consultar
carrito antes de habilitar repetición. Nunca garantizar precio o stock.

Cuando Cart devuelva precio actual distinto de su snapshot previo, mostrar
“Precio actualizado: antes X, ahora Y”, usar exclusivamente el total devuelto y
pedir confirmación sólo si el backend exige una acción. No sobrescribir snapshots
ni inventar un endpoint de aceptación.

### 4.3 Carrito

**Ruta prevista:** `/carrito`. **Soportado** por Cart para usuario o invitado con
identidad. Presenta imagen/nombre sólo si la respuesta los entrega; actualmente
debe verificarse el contrato efectivo y no completar datos con Products admin.
Agrupa por minimarket, muestra precio unitario snapshot, cantidad, subtotal,
total por grupo, total general y el aviso “Se crearán N pedidos, uno por
minimarket”.

Acciones: continuar comprando (bloqueada funcionalmente mientras no haya
catálogo), cambiar cantidad, eliminar, vaciar y avanzar a checkout. Cada mutación
deshabilita sólo el ámbito afectado; vaciar requiere diálogo. Tras cada respuesta
se reemplaza/refetch el carrito y se recalculan vistas desde valores del servidor;
los cálculos locales son únicamente transitorios y nunca la cifra definitiva.

Estados: skeleton inicial; vacío con retorno al catálogo; item pending; eliminado
con aviso no intrusivo; inventario inactivo/no disponible, precio cambiado, stock
insuficiente o cantidad inválida junto al ítem; fallo parcial mantiene el resto.
404 refresca y comunica ítem inexistente; 422 conserva entrada y enfoca el campo;
409 refresca por conflicto; 401/403 renueva contexto o dirige a acceso; pérdida de
identidad invitada impide mostrar/adoptar otro carrito; 500/503/red ofrece
reintento y evita asumir resultado. Al detectar otra pestaña, avisar y recargar.

Móvil usa tarjetas agrupadas y barra inferior no obstructiva con total/checkout;
desktop puede usar resumen sticky dentro del viewport.

### 4.4 Checkout

**Ruta prevista:** `/checkout`. **Soportado parcialmente** para usuario/invitado:
`POST /checkout/validate` y `POST /checkout`, cuerpo vacío, identidad de Cart.
Flujo fijo: Revisión → Validación → Reserva → Creación de pedidos → Creación de
pago → Sesión de pago. Checkout Service coordina los efectos; la UI no llama
Reservations, Orders o Payments administrativos por separado.

La revisión muestra grupos por minimarket, pedidos independientes y total del
servidor. “Pagar” ejecuta validación; si es válida inicializa una sola vez. Desde
el primer envío se muestra un panel de procesamiento con etapa neutral (“Estamos
confirmando disponibilidad y preparando tus pedidos”), spinner accesible y
advertencia de no cerrar. No se proclama qué escritura interna terminó.

La respuesta de inicialización debe proveer identificadores, pago/sesión y
`expires_at` para habilitar redirección y contador. Si los entrega, el contador se
calcula como `expires_at servidor - ahora`, se resincroniza en cada respuesta y
expira visualmente en cero; no extiende la reserva. La documentación backend
establece 15 minutos, pero la fecha del servidor prevalece.

Doble clic y recarga reutilizan la operación/idempotency key sólo si el contrato
la expone. Hoy CheckoutRequest no admite campos, por lo que la UI no puede enviar
una clave: idempotencia observable y consulta del checkout ya procesado son una
**limitación crítica**. Ante timeout, desconexión, error parcial o recarga no se
asume rollback ni se reinicia; consultar Cart y, para autenticados, Customer
Panel. Para invitados no existe consulta de pedidos propios: reintento seguro
queda bloqueado si el resultado es ambiguo.

Precio/stock/carrito cambiado: mostrar diferencias entregadas y volver a Cart.
Reserva vencida: detener sesión y volver a Cart tras verificar. No se agregan
dirección, entrega, cupón, cancelación ni método no respaldado. En móvil, resumen
colapsable y acción sticky que no tape errores ni contador.

### 4.5 Resultado de pago

**Ruta prevista:** `/pago/resultado`, con parámetros de retorno tratados como no
confiables. **No disponible al cliente:** todas las rutas Payments requieren
`manage_options`; no hay consulta/confirmación pública por propietario. La
redirección exitosa nunca prueba aprobación.

Diseño de estados basado únicamente en respuesta futura/autorizada de Payments:

| Estado | Mensaje/acciones |
|---|---|
| pending/processing/indeterminado | “Estamos verificando tu pago”; consulta controlada, sin repetir confirmación. |
| paid | “Pago aprobado”; abrir pedidos (autenticado). |
| failed/rechazado | “Pago no aprobado”; reintentar sólo si servidor lo permite o volver al carrito. |
| expired/reserva vencida | Explicar vencimiento; volver al carrito tras verificar. |
| ya procesado/confirmación duplicada | Mostrar estado terminal real, sin segundo efecto. |
| sesión inexistente/expirada | No revelar IDs; recuperar por recurso propio si existe. |

En recarga se consulta de nuevo. Se aceptan sólo parámetros con nombre, longitud
y formato permitidos, nunca se renderizan sin escape ni autorizan acceso; tokens
de gateway no van a localStorage ni logs. Hasta existir una vía pública segura,
no implementar confirmación, polling, reintento ni resultado concluyente.

### 4.6 Mis pedidos

**Ruta prevista:** `/mi-cuenta/pedidos`. **Soportado sólo para autenticados** con
`GET /me/orders`; el servidor usa `get_current_user_id()` y filtra por cliente.
Vista read-only agrupada por fecha con número, fecha, minimarket id, total, estado
visible, pago y acceso al detalle. El nombre de minimarket no está en el resumen.

No hay parámetros de filtro/paginación en la ruta: filtro por estado puede ser
local sobre la página completa, sin afirmar paginación; con grandes volúmenes es
riesgo. Vacío muestra bienvenida. 401 dirige a login conservando retorno; 403 no
ofrece cambio de identidad; 404 se usa en detalle; red/500 permite reintento.
Invitado debe autenticarse y no puede recuperar pedidos creados como invitado con
las rutas actuales. No hay cancelar, devolver, recomprar ni editar.

### 4.7 Detalle del pedido

**Ruta prevista:** `/mi-cuenta/pedidos/{id}`. **Soportado para autenticado** por
`GET /me/orders/{id}`, aislado mediante `findForCustomer`. Presenta pedido,
minimarket/seller, items, cantidades, precio unitario congelado, subtotal, total,
estado, payment asociado, expiración de reserva y fechas de creación/actualización.

La respuesta actual no incluye imagen, delivery, tracking ni fecha específica de
último evento. Esos paneles se omiten o indican “Información aún no disponible”,
sin consultar rutas admin. Estados reservado/pagado se etiquetan; reserva vencida
sólo si lo expresa el estado/dato real; pago nulo se muestra “Sin pago asociado”.
404 deliberadamente no distingue pedido ajeno de inexistente. Carga parcial
mantiene cabecera y marca sección; red ofrece reintento. Invitado no accede.

### 4.8 Seguimiento del delivery

**Ruta prevista:** panel dentro del detalle o `/mi-cuenta/pedidos/{id}/seguimiento`.
**No disponible al cliente:** `/deliveries*` requiere `manage_options` y Customer
Panel no incluye Delivery. Tampoco existe consulta por order propia.

Cuando exista una fuente autorizada, la línea visual mapeará estados reales:
pedido pagado/confirmado → delivery `pending` → `assigned` (courier asignado) →
`picked_up` → en ruta (misma fase `picked_up`, pues no hay estado `in_transit`) →
`delivered`; `cancelled` es terminal alternativo. Eventos reales: assigned,
picked_up, location_update y delivered. “En ruta” es etiqueta de presentación,
no estado nuevo.

Se muestran completados, actual, futuros, última actualización, courier sólo si
la respuesta pública lo permite, minimarket y pedido. Sin delivery, courier o
tracking se usan estados vacíos distintos. Botón “Actualizar” deshabilitado
mientras carga, con `aria-live`; no polling. 401/403/404 y datos incompletos no
filtran información. No mapas, GPS, ETA, rutas, contacto, push ni ubicación en
tiempo real.

## 5. Componentes transversales conceptuales

| Componente | Datos/eventos y páginas | Accesibilidad/responsive | Bloqueos |
|---|---|---|---|
| Loading/Skeleton | label, región; todas; emite cancel opcional | `aria-busy`, texto para lector; replica forma sin parpadeo | Acción dependiente |
| EmptyState | título, detalle, acción; catálogo/carrito/pedidos/tracking | encabezado y enlace real; apilado móvil | Ninguno |
| ErrorState/Retry | código público, mensaje, retry; todas | `role=alert` sólo al aparecer, foco en resumen si bloqueante | Reintentos simultáneos |
| Success/Toast | mensaje y destino; Cart/Pago | `status`, no roba foco, descartable | Nunca bloquea navegación |
| ValidationMessage | fieldId, texto; cantidad/checkout | `aria-describedby`, foco al primer inválido | Envío inválido |
| StatusBadge | estado real y etiqueta; pedidos/pago/delivery | texto+icono, nunca sólo color | Ninguno |
| PriceChanged/StockWarning | antes/ahora/disponible; oferta/Cart/Checkout | `aria-live=polite`, texto explícito | Agregar/pagar si servidor invalida |
| ProcessingGuard | pendingKey, label; mutaciones | botón conserva nombre y expone busy | Doble clic/misma intención |
| ReservationTimer | expiresAt/serverNow; Checkout/Pago | texto comprensible, anuncios por hitos, no cada segundo | Pago tras cero |
| ConfirmDialog | título, efecto, confirmar/cancelar; vaciar/eliminar | diálogo modal, foco atrapado/restaurado, Escape | Fondo y doble submit |
| Order/StoreSummary | grupos, items, totales; Cart/Checkout/Pedido | lista semántica; tarjetas móvil | Checkout si datos obsoletos |
| ManualRefresh | lastUpdated, pending; Pago/Tracking | nombre accesible, conserva foco | Solicitudes repetidas |
| BackNavigation | destino/contexto; todas | enlace, no sólo icono | Durante efecto ambiguo crítico |

## 6. Matriz REST real

Namespace común: `/wp-json/veciahorra/v1`. “WP” implica cookie/sesión y nonce
REST para solicitudes desde el sitio. Los códigos listados combinan el mapeo
explícito de rutas y los que WordPress puede producir por autenticación.

| Página/acción | Módulo y endpoint existente | Método/parámetros | Acceso/respuesta | Errores y conducta frontend |
|---|---|---|---|---|
| Catálogo/listar | Products `/products` o `/products/search` | GET: page, per_page, term, status, order_by, direction | **Admin `manage_options`**; productos+meta | 404/422/500/503; no consumir desde público |
| Producto/detalle | Products `/products/{id}` | GET id | **Admin**; producto | 404/500; bloqueado |
| Ofertas | Inventory `/inventory` | GET filtros admitidos por request | **Admin**; inventarios | No hay composición pública; bloqueado |
| Ver carrito | Cart `/cart` | GET; header `X-Veciahorra-Cart-Session` o query `session_id` para invitado | Público con propietario; carrito | 400 identidad, 404/422/500 según operación; renovar/refetch |
| Agregar | Cart `/cart/items` | POST JSON `inventory_id`, `quantity`; identidad | Usuario/invitado; item/cart | 404/422/500; mostrar junto a oferta, refetch si ambiguo |
| Cantidad | Cart `/cart/items/{id}` | PATCH JSON `quantity`; id+identidad | Propietario; éxito | 404/422/500; conservar entrada/refetch |
| Eliminar | Cart `/cart/items/{id}` | DELETE id+identidad | Propietario | 404/500; refetch |
| Vaciar | Cart `/cart` | DELETE identidad | Propietario; cantidad eliminada | 400/500; confirmar y refetch |
| Validar checkout | Checkout `/checkout/validate` | POST JSON `{}`; identidad | Usuario/invitado; validación agrupada | 400 identidad, 422, 500; volver a items afectados |
| Inicializar checkout | Checkout `/checkout` | POST JSON `{}`; identidad | Usuario/invitado; reservas/pedidos/pago coordinados | 400/422/500; no asumir rollback |
| Resultado/consultar pago | Payments `/payments/{id}` | GET id | **Admin** | 404/500; no usar en frontend cliente |
| Crear sesión pago | Payments `/payments/{id}/session` | POST id | **Admin** | 404/422/500; Checkout debe coordinar; acceso directo bloqueado |
| Confirmar pago | Payments `/payments/confirm` | POST provider, provider_reference | **Admin** | 404/422/500; parámetros de navegador no autorizan llamada |
| Mis pedidos | Customer Panel `/me/orders` | GET, sin filtros | WP autenticado; lista propia | 401/403/500; login o reintento |
| Detalle pedido | Customer Panel `/me/orders/{id}` | GET id | WP autenticado; detalle propio | 401/403/404/422/500; 404 neutro |
| Tracking | Delivery `/deliveries/{id}` y `/{id}/tracking` | GET id | **Admin** | 404/500; no consumir desde cliente |

Rutas de Orders, Reservations, catálogos y Stores existentes son administrativas
y no forman parte del consumo público. Los 409 documentados por dominios pueden
aparecer en futuras fachadas, pero las rutas cliente actuales no los mapean de
forma uniforme; la UI mantiene un manejador genérico 409 sin afirmar contrato.

## 7. Sesión, seguridad y errores

- En contexto WordPress usar cookie de sesión y `X-WP-Nonce`; un nonce no es
  identidad ni reemplaza autorización. En 401, refrescar contexto una vez o
  solicitar login; en 403, detener; en 404, mensaje neutro; 409, refetch; 422,
  errores accionables; 500/503, mensaje público y reintento acotado.
- El servidor deriva el usuario actual y verifica pertenencia. Nunca enviar un
  `customer_id` elegido por navegador para acceder a recursos.
- El id de invitado es credencial de sesión: preferir cookie segura/HttpOnly si
  una futura foundation la emite. Si debe persistirse temporalmente por el
  contrato actual, minimizar vida y alcance; nunca compartirlo por URL, analytics
  o logs. La query existente es fallback, no preferencia.
- localStorage sólo admite preferencias no sensibles (vista/filtros). No guardar
  nonce, sesión de carrito, tokens/referencias de pago, PII, respuestas completas
  de pedidos, precio o stock como autoridad.
- Escapar texto; URLs e imágenes pasan allowlist de protocolos/orígenes; iconos
  decorativos se ocultan y los informativos tienen nombre. No mostrar stack traces
  ni mensajes internos.
- Abort/timeout no equivale a fallo del servidor. Mutaciones ambiguas fuerzan
  consulta antes de reintento. Botones usan exclusión por operation key.
- Precios y fechas se formatean desde strings del servidor sin convertir dinero a
  float para decidir. Cantidades son enteros positivos. Estados desconocidos se
  muestran como “En proceso” y se registran sin inventar transición.

## 8. Responsive y accesibilidad

Desktop usa cuadrículas y resúmenes sticky limitados entre header/footer. Tablet
reduce columnas. Móvil convierte tablas en tarjetas, una columna, controles de al
menos 44×44 CSS px, totales próximos a la acción y barras sticky con espacio de
compensación; nada fijo cubre foco, mensajes, contador o teclado virtual. Se
preserva selección, scroll lógico y borradores al rotar.

Todas las páginas incluyen salto a contenido, `main`, encabezados jerárquicos,
listas semánticas y foco visible. El orden DOM coincide con el visual. Formularios
tienen `label`; cantidades explican mínimo/máximo y botones +/− tienen nombres.
Errores se asocian al campo y el resumen enfoca el primer error. Navegación
dinámica enfoca `h1`; cerrar diálogo restaura foco.

`aria-live=polite` anuncia precio, stock, producto agregado y actualizaciones;
errores críticos usan `alert` sin repetir. Loading usa `aria-busy` y texto. El
contador anuncia hitos (5 min, 1 min, expirado), no cada segundo. Timeline es una
lista ordenada con “completado/actual/pendiente” en texto. Contraste cumple WCAG
AA y color nunca es el único indicador. Se respeta `prefers-reduced-motion`.

## 9. Estrategia futura de pruebas

| Nivel | Cobertura prevista |
|---|---|
| Unitarias | formato monetario/fecha, tiempo de reserva, estados desconocidos, validación de cantidad, normalización 401/403/404/409/422/500/503. |
| Componentes | selector 1/2/3/>3 ofertas, badges, avisos, dialogs, skeleton, empty/error, foco y bloqueo de doble clic. |
| Integración REST | contratos reales de Cart, Checkout y Customer Panel; snapshots/totales del servidor; invitado vs autenticado; fallos ambiguos y recarga. |
| Navegador/E2E | navegación, agregar/editar/eliminar/vaciar, grupos por minimarket, checkout, expiración, retorno de pago, pedidos/detalle y aislamiento. Casos bloqueados quedan skipped con dependencia explícita, no mocks como prueba de soporte. |
| Manual/responsive | desktop/tablet/mobile, orientación, teclado virtual, sticky, red lenta/offline y otra pestaña. |
| Accesibilidad | teclado, foco, nombres, live regions, contraste, zoom 200 %, lector de pantalla básico y timeline. |
| Regresión | administración actual de Products/Inventory y flujos backend/manuales existentes; ningún asset frontend contamina admin. |

Datos de prueba incluyen cero productos/pedidos, stock agotado, oferta retirada,
precio cambiado, cantidades límite, reserva vencida, pago pending/paid/failed,
delivery ausente/parcial/terminal y respuestas 401, 403, 404, 409, 422 y red.

## 10. Roadmap de implementación 28.1–28.9

Los archivos son esperados, no creados en 28.0.

| Fase | Objetivo/alcance y archivos esperados | Dependencias/endpoints | Exclusiones, pruebas y criterio de salida |
|---|---|---|---|
| 28.1 Foundation | bootstrap, REST client, sesión, router, stores mínimos, loading/error; `assets/frontend/{js,css,services,stores,utils,components}` | WP nonce; Cart como prueba de contexto | Sin páginas de negocio. Unitarias de cliente/sesión/error. Sale con carga aislada de admin y manejo seguro de identidad. |
| 28.2 Product Catalog | catálogo, tarjetas, filtros realmente permitidos, vacío/error; `pages/catalog*`, `components/ProductCard*` | **Bloqueada:** Products/Inventory sólo admin | Sin consumir admin. Sale sólo cuando exista contrato público soportado y probado; riesgo de composición/imagen/catálogos. |
| 28.3 Offer Selector | tres precios, expansión, cantidad, selección y Cart; `OfferSelector*` | 28.2 + `POST /cart/items` | Backend valida. Pruebas 1/2/3/>3, cambios y doble clic. Sale con oferta pública y refetch canónico. |
| 28.4 Cart UI | grupos, items, cantidades, eliminar/vaciar/totales; `pages/cart*`, `stores/cart*` | Cart GET/POST/PATCH/DELETE | Sin total autoritativo local. Sale con invitado/autenticado, errores y mobile probados; datos visuales faltantes son riesgo. |
| 28.5 Checkout UI | revisión, validate, initialize, reserva, contador/redirección; `pages/checkout*`, `ReservationTimer*` | `/checkout/validate`, `/checkout`; Cart | Sin pasos nuevos. Pruebas concurrencia/timeout/expiración. Sale sólo con recuperación/idempotencia observable definida; hoy es riesgo crítico. |
| 28.6 Payment Result | retorno, estado, reintento permitido; `pages/payment-result*` | **Bloqueada:** Payments sólo admin | Sin confiar en redirect. Sale con consulta pública por propietario, recarga y parámetros seguros probados. |
| 28.7 Customer Orders | lista, filtro local y detalle read-only; `pages/orders*`, `OrderSummary*` | `/me/orders`, `/me/orders/{id}` | Sin acciones ni invitado. Sale con aislamiento 401/403/404, vacío y responsive. Delivery/imagen se omiten. |
| 28.8 Delivery Tracking | timeline y actualización manual; `pages/tracking*`, `DeliveryTimeline*` | **Bloqueada:** Delivery sólo admin y ausente en Customer Panel | Sin mapas/polling. Sale con consulta propia autorizada y estados reales probados. |
| 28.9 Stabilization | integración, accesibilidad, responsive, errores, beta; pruebas/configuración de build previstas | Todas las fases desbloqueadas | Sin expandir negocio. Sale con E2E feliz y adverso, auditoría AA básica, regresión admin y cero bloqueos críticos. |

Cada fase depende del criterio de salida anterior; una fase bloqueada no se declara
completa mediante mocks. Las pruebas de contrato pueden adelantarse sin publicar
UI. Archivos exactos, bundler y mecanismo de montaje WordPress se decidirán en
28.1 tras revisar la política de assets del plugin.

## 11. Dependencias, riesgos, limitaciones y decisiones pendientes

| Tipo | Hallazgo/decisión |
|---|---|
| Bloqueo | No existe catálogo/oferta pública; Products, Inventory y catálogos son administrativos. |
| Bloqueo | Payments no ofrece consulta/confirmación por cliente; resultado y recuperación segura no son implementables. |
| Bloqueo | Delivery es administrativo y Customer Panel no lo serializa; tracking cliente no es implementable. |
| Bloqueo invitado | Customer Panel exige WP login; no existe consulta de pedidos de invitado ni mecanismo documentado de vinculación posterior. |
| Riesgo | Checkout acepta cuerpo vacío: no expone idempotency key ni endpoint de estado para recuperar resultado ambiguo. |
| Riesgo | Resumen de pedidos carece de nombre de minimarket; detalle carece de imagen y Delivery. |
| Riesgo | No hay paginación/filtro de Customer Panel; volumen grande afecta rendimiento/UX. |
| Riesgo | Códigos 409 no se mapean uniformemente en rutas cliente; usar fallback y validar contratos antes de UI. |
| Pendiente | Definir emisión, almacenamiento, rotación, expiración y recuperación segura de `X-Veciahorra-Cart-Session`. |
| Pendiente | Definir navegación/montaje (páginas WP, rewrite o contenedor) sin crear shortcodes/bloques en 28.0. |
| Pendiente | Confirmar esquema exacto de respuestas Cart/Checkout y campos `expires_at`, redirect e IDs antes de componentes. |
| Futuro | Fachadas públicas autorizadas, paginación, recuperación idempotente y asociación segura de invitado, fuera de 28.0. |
| Fuera de alcance | Direcciones, métodos de entrega, cupones, cancelar/devolver/recomprar, mapas, GPS, ETA, contacto courier y push. |

## 12. Criterio de cierre 28.0

28.0 termina con este documento y ningún cambio ejecutable. La experiencia queda
diseñada, los endpoints reales quedan trazados, las responsabilidades servidor/UI
están separadas y las capacidades faltantes están visibles. La implementación no
debe comenzar por una fase cuyo criterio dependa de una ruta pública inexistente;
ese bloqueo requiere una decisión de backend separada y explícita, fuera de este
entregable.
