# Panel del Cliente v1 de VeciAhorra

**Estado:** diseño propuesto, listo para revisión de implementación.  
**Hito:** 29.1.  
**Naturaleza:** documento funcional y técnico; no autoriza código, rutas, tablas ni migraciones.

## 1. Objetivo

Definir un panel autenticado y de solo lectura para consultar compras propias originadas por `veciahorra_checkout`. El panel es una proyección de autoridades durables existentes: no crea, corrige, completa ni reintenta estados; no ejecuta efectos de negocio; no adquiere leases; y no reemplaza Checkout, Payment, Reconciliation, Orders, Delivery ni completions.

## 2. Alcance

V1 ofrece dos vistas: **Mis pedidos** y **Detalle del pedido**. La unidad visible es una compra VeciAhorra completa, incluso cuando contiene varias `Order`, una por minimarket. Sólo usuarios WordPress autenticados pueden consultar raíces cuyo `checkouts.owner_type = user` y `checkouts.user_id` coincide con `get_current_user_id()`.

La información se presenta mediante DTOs de lectura estables y estados amigables resueltos en servidor. Ningún estado técnico crudo forma parte del contrato público.

## 3. Fuera de alcance

Quedan expresamente fuera: cancelaciones, devoluciones, reclamos, soporte y mensajería; edición de pedidos, perfil, datos personales o direcciones; cambio de fulfillment o método de entrega; recompra; facturas, boletas y otros documentos tributarios; tracking en tiempo real; notificaciones; calificaciones; acciones o reintentos sobre pagos; reparación o reproceso interno; panel de minimarket, repartidor o administración; unificación con “Mi cuenta” de WooCommerce; exposición de compras WooCommerce; nuevas tablas, migraciones, índices, estados persistentes o autoridades.

## 4. Principios arquitectónicos

1. El panel es una proyección de lectura, nunca autoridad de negocio.
2. No crea, corrige ni completa estados y no ejecuta efectos.
3. No reconstruye autoridad mediante heurísticas, ausencia de filas, email, teléfono, nombre, monto o proximidad temporal.
4. No altera pagos, pedidos, Delivery ni fulfillment y nunca invoca processors o Action Scheduler.
5. Todo campo tiene una fuente durable explícita; la falta de una etapa posterior no demuestra el fracaso de una anterior.
6. Los estados técnicos se traducen en servidor mediante reglas deterministas, conservadoras y versionables.
7. Contradicción, cardinalidad inválida, relación perdida u origen desconocido producen **En revisión**, nunca progreso optimista.
8. JavaScript sólo renderiza DTOs; no decide ownership, relaciones ni estados.
9. Listado y detalle validan autenticación y ownership de forma independiente.
10. Ocultar una ruta o botón no constituye autorización.

## 5. Contexto y autoridades existentes

### 5.1 Checkout y ownership

`checkouts` posee `public_id` opaco con formato `chk_…`, `owner_type`, `user_id`/`session_id`, estado (`pending`, `payment_started`, `expired`, `cancelled`), `fulfillment_method` (`pickup` o `delivery` cuando existe), moneda, total y timestamps. `CheckoutRepository::findOwnedByPublicId()` ya demuestra el patrón correcto: identificador público + tipo de owner + identidad durable.

Para este panel autenticado sólo son elegibles checkouts creados con `owner_type=user` y `user_id` positivo. Un checkout de sesión invitada no se atribuye posteriormente por email ni por datos personales. Una futura vinculación de invitados requiere un diseño de autoridad separado.

`checkout_orders` une una raíz Checkout con una o varias Orders y garantiza que cada Order pertenece como máximo a un Checkout. Por ello Checkout es la raíz pública y la unidad visual correcta.

### 5.2 Orders, productos y minimarkets

Cada `Order` representa el pedido de un minimarket y contiene `customer_id`, `minimarket_id`, total, estado y reserva. Los estados realmente escritos por el flujo inspeccionado son `reserved`, `paid` y `delivered`; no se diseñan traducciones para estados hipotéticos. `checkout_orders` y `business_completion_orders` constituyen relaciones durables, no inferencias por cliente o monto.

`OrderItem` congela `product_id`, `inventory_id`, cantidad, precio unitario y subtotal. **No congela nombre, SKU, slug ni imagen.** El nombre y la imagen del catálogo actual son mutables y no pueden presentarse como evidencia histórica. V1 muestra:

- cantidad, precio unitario y subtotal desde `order_items`;
- nombre actual, sólo si el producto aún existe, etiquetado semánticamente como dato actual;
- fallback neutro “Producto #n del pedido” dentro del DTO, donde `n` es posición, no ID interno;
- imagen actual opcional, con `historical=false`; su ausencia nunca invalida el pedido.

El nombre del minimarket se obtiene del Store actual para presentación, también con `historical=false`. Totales y pertenencia al grupo provienen de Order, no del catálogo ni Store.

### 5.3 Pago durable

`PaymentSession` gobierna creación/recuperación de sesión (`pending`, `create_processing`, `create_retryable`, `create_ambiguous`, `create_failed`, `ready`, `confirmed`, `expired`, `cancelled`). Es útil antes de conciliación, pero `ready` sólo indica una sesión utilizable; no prueba pago. Expiración o fallo de creación tampoco reemplazan un resultado financiero durable posterior.

`PaymentOriginContext` registra origen durable (`veciahorra_checkout` o `woocommerce`), recurso de origen, importe e identidad técnica. Puede resolver relaciones internamente, pero jamás se serializan `origin_key`, `buy_order`, `financial_session_id`, hashes, entorno o identidad del comercio.

`WebpayReturn` es evidencia técnica/financiera. El servicio de lectura puede usar su resultado normalizado únicamente a través de una proyección controlada; no expone JSON, tokens, fingerprints, códigos técnicos, autorización, payload ni datos contables crudos.

`PaymentReconciliation` es la autoridad de conciliación con estados `pending`, `processing`, `completed`, `retryable`, `permanent_failure` y `manual_review`. `completed` prueba conciliación financiera exitosa según el pipeline, pero no preparación ni entrega.

`Payment`, materializado por BusinessCompletion para VeciAhorra, es la fuente preferida de monto, moneda, proveedor, estado y `paid_at`. `payment_orders` enlaza Payment con todas las Orders. `payment_reference` y `provider_reference` no se muestran completos; v1 omite identificadores financieros salvo futura necesidad aprobada.

### 5.4 Completions y logística

`BusinessCompletion.completed` prueba la materialización coherente de Payment, la transición de Orders y el snapshot `business_completion_orders`. Estados `pending`, `processing`, `retryable`, `permanent_failure` y `manual_review` describen el pipeline interno y sólo alimentan la traducción conservadora.

`DeliveryCompletion.not_required` prueba que el método durable es retiro y que no deben existir Deliveries. No prueba “listo para retiro”. `DeliveryCompletion.completed` prueba que se materializó exactamente una Delivery `pending` por Order para despacho; no prueba avance logístico.

`Delivery` es la autoridad logística real. Sus estados verificados son `pending`, `assigned`, `picked_up`, `delivered` y `cancelled`. Se traducen respectivamente como preparación de despacho, preparación/asignación, en reparto, entregado y cancelado, sujetos a coherencia global. No se exponen `courier_id`, coordenadas ni información privada del repartidor. `delivery_tracking` no se usa en v1: sus eventos no constituyen tracking público aprobado.

`FulfillmentCompletion.completed` sólo prueba el cierre de materialización: BusinessCompletion completo, DeliveryCompletion compatible y conjunto de Deliveries coherente. Para retiro no significa que el pedido esté listo; para despacho no significa que haya sido entregado. **Entregado exige Delivery `delivered` para todas las Orders.**

### 5.5 WooCommerce

`CompletionBranchPolicy` envía `veciahorra_checkout` a BusinessCompletion y considera terminada la rama `woocommerce` tras conciliación; origen desconocido es `unsupported`. WooCommerce usa su propia Order, ownership, identificador y cierre. No existe raíz común inequívoca con Checkout interno.

**Decisión:** v1 excluye WooCommerce. No se fusionan arquitecturas por email, usuario, monto o fecha. Una futura versión necesitará un adaptador WooCommerce separado que valide ownership con la API de WooCommerce, use el número público de esa Order y produzca el mismo DTO de presentación sin fabricar relaciones con Orders internas.

## 6. Unidad funcional del panel

La unidad es **una compra VeciAhorra = un Checkout autenticado**. Su número visible es `checkout.public_id`; nunca `checkout.id`, `order.id`, `payment.id` ni `delivery.id`. Una compra multi-minimarket se representa como una sola tarjeta y un solo detalle, con grupos por cada Order/minimarket.

Una raíz se incluye cuando:

1. `owner_type=user` y `user_id` coincide con el usuario autenticado;
2. el `public_id` es válido;
3. corresponde al origen VeciAhorra; antes de existir PaymentOriginContext se admite como compra iniciada porque la propia raíz es Checkout nativo;
4. sus relaciones se proyectan sin atribuir recursos ajenos.

Una raíz válida pero incompleta sigue visible con **En revisión** o un estado temprano demostrable. Una Order aislada sin `checkout_orders` no se incorpora a v1 porque carece de raíz pública navegable segura; se registra como limitación histórica.

## 7. Arquitectura de lectura

Componentes conceptuales futuros:

- `CustomerOrderQueryService`: consulta raíces del usuario y carga relaciones en lotes.
- `CustomerOrderOwnershipPolicy`: valida owner desde sesión WordPress y Checkout.
- `CustomerOrderStatusResolver`: aplica precedencia y produce un código visible estable.
- `CustomerOrderPresenter`: transforma autoridades en DTO, textos y capacidades de UI.
- `CustomerOrderListItemDTO` y `CustomerOrderDetailDTO`: contratos inmutables de lectura.
- repositorios/query objects de lectura especializados: no contienen textos ni reglas de presentación.

El módulo actual `CustomerPanelService`, basado en Orders incrementales y `/me/orders/{id}`, no es el contrato objetivo de este diseño: expone IDs internos y una Order por minimarket. La implementación futura debe migrar conceptualmente a Checkout público sin reutilizar esas decisiones inseguras, preservando mientras corresponda compatibilidad hasta retirar el contrato antiguo mediante un hito explícito.

## 8. Matriz de autoridades

| Autoridad | Responsabilidad real | Puede consumir | No debe consumir/exponer | Ownership/relación | Estado visible posible |
|---|---|---|---|---|---|
| Checkout | raíz, owner, total, moneda, fulfillment | `public_id`, owner validado, total, método, fechas semánticas | ID interno, idempotency/fingerprint | raíz directa `user_id` | Pendiente de pago, Procesando pago, Cancelado, En revisión |
| CheckoutOrder | agrupar Orders | conjunto exacto de Orders | IDs al cliente | sólo tras Checkout owned | conteos y grupos |
| PaymentSession | sesión previa a resultado financiero | estado sólo si no hay conciliación concluyente | redirect, provider session, metadata, leases, fingerprints | por `checkout_id` | Pendiente/Procesando pago/En revisión |
| PaymentOriginContext | origen y enlace durable | `origin` y relación interna | hashes, buy order, session, environment | `origin_resource_id` debe corresponder a la raíz | excluir origen desconocido; En revisión |
| WebpayReturn | evidencia financiera normalizada | clasificación aprobada vía aplicación | payloads, token, hashes, response codes | por origin/reconciliation verificados | Pago rechazado o En revisión, nunca directo |
| PaymentReconciliation | conciliación financiera | estado y `reconciled_at` | leases, errores crudos, fingerprint | origin VeciAhorra + Checkout | Procesando, Pago recibido, Pago rechazado, En revisión |
| BusinessCompletion | materialización Payment/Orders | estado, `completed_at`, snapshot | lease/result codes crudos | desde reconciliation y Checkout | Pago recibido/Preparando/En revisión |
| Payment | registro financiero materializado | monto, moneda, proveedor amigable, `paid_at` | referencias completas, fingerprint | checkout + payment_orders coherentes | Pago recibido |
| BusinessCompletionOrder | snapshot de Orders | conjunto exacto | IDs públicos | debe coincidir con checkout_orders | coherencia o En revisión |
| Order | pedido por minimarket | total, estado, timestamps semánticos limitados | ID navegable, reserva interna como progreso | mediante CheckoutOrder; `customer_id` debe coincidir | Preparando, Entregado; cancelación sólo si estado durable compatible |
| OrderItem | línea congelada | cantidad, unitario, subtotal | inventory/product ID público | Order autorizada | detalle de productos |
| Store/Product actuales | etiqueta/imagen actual opcional | nombres e imagen con `historical=false` | asumir snapshot histórico, datos privados | sólo tras IDs de Order/Item autorizados | ninguno |
| DeliveryCompletion | materialización/no necesidad | estado para coherencia | leases, result codes | por BusinessCompletion | Preparando despacho o En revisión; nunca Entregado/Listo retiro |
| Delivery | logística por Order | estado y fechas compatibles | courier, coordenadas, notas internas | conjunto exacto de Orders owned | Preparando despacho, En reparto, Entregado, Cancelado |
| FulfillmentCompletion | cierre de materialización | estado para coherencia | leases/result codes | por BusinessCompletion | no prueba entrega; En revisión ante conflicto |
| WooCommerce Order | compra WooCommerce | nada en v1 | mezcla con Orders internas | ownership WooCommerce separado | fuera de v1 |

## 9. Flujo de datos

1. WordPress autentica la petición; invitado recibe error uniforme.
2. El servidor obtiene `get_current_user_id()`; nunca acepta `customer_id` del cliente.
3. QueryService busca Checkouts `owner_type=user`, `user_id=current`, ordenados por fecha e ID interno sólo como desempate no expuesto.
4. En detalle valida formato de `public_id` y ejecuta una búsqueda owned en la misma consulta/política.
5. Carga en lote CheckoutOrders y, sólo desde esos IDs autorizados, Orders/Items, Store/Product opcionales, PaymentSession, origen, retorno, Reconciliation, completions, Payment y Deliveries.
6. Valida cardinalidades, owner redundante (`Order.customer_id`, `Payment.customer_id`, `Delivery.customer_id`) y conjuntos exactos.
7. StatusResolver selecciona un estado visible por precedencia.
8. Presenter crea DTOs sin campos técnicos.
9. REST aplica `Cache-Control: private, no-store`, `Pragma: no-cache` cuando proceda y `Vary: Cookie`/contexto de autenticación.
10. Frontend renderiza; no consulta autoridades por separado.

## 10. Página “Mis pedidos”

**Propósito:** localizar y abrir compras propias, no operar sobre ellas.

**Fuente:** Checkout owned más agregados mínimos cargados en lote. El listado no carga items completos, catálogo, tracking ni payloads financieros.

**Inclusión y orden:** checkouts autenticados según sección 6, orden `created_at DESC` y desempate interno `id DESC`. V1 propone `limit` 20 (máximo 50) y respuesta preparada para cursor. No se muestra una Order huérfana.

Cada tarjeta muestra:

- `public_id` como “Pedido”; opcionalmente una versión visual abreviada sin cambiar el valor del enlace;
- `created_at` de Checkout;
- `total_amount` y `currency` de Checkout;
- suma de cantidades de OrderItems, obtenida por agregado en lote;
- número de Orders/minimarkets;
- `fulfillment_method` traducido a Retiro/Despacho, o “Por confirmar” para legado nulo;
- estado visible y texto breve;
- enlace **Ver detalle** al `public_id` opaco.

Estados UI: skeletons con `aria-busy` durante carga; vacío “Aún no tienes pedidos VeciAhorra”; error recuperable de lectura con acción “Reintentar carga” (esta acción sólo repite GET, nunca un processor). Navegación accesible mediante enlace real.

## 11. Detalle del pedido

### 11.1 Encabezado y resumen

Muestra `public_id`, fecha de Checkout, estado visible, total/moneda y fulfillment durable. Resumen incluye subtotal (suma durable de Orders, sólo si coincide con Checkout), total Checkout, cantidad de unidades, líneas, Orders y minimarkets. Si totales o conjuntos contradicen la raíz, se conserva el total de Checkout, se omite el desglose dudoso y el estado es **En revisión**.

V1 no muestra dirección ni contacto: Checkout actual no persiste esos datos como snapshot aprobado. Para retiro tampoco hay snapshot durable de dirección del punto; puede mostrarse sólo el nombre actual del minimarket, marcado como actual, no una promesa histórica.

### 11.2 Productos y minimarkets

Se agrupa por Order/minimarket. Cada grupo muestra nombre actual seguro, subtotal durable de Order y líneas con etiqueta actual/fallback, cantidad, unitario congelado y subtotal congelado. No se recalculan precios. Si Product cambia o queda inactivo, la línea permanece por OrderItem; nombre/imagen pueden desaparecer o cambiar y se señalan como datos actuales. Una futura fidelidad histórica requiere snapshot durable, fuera de este hito.

### 11.3 Pago

Muestra estado amigable, monto/moneda desde Payment cuando existe y es coherente, `paid_at` cuando existe, y proveedor traducido (`webpay_plus` → “Webpay Plus”; otros sólo mediante allowlist). Si Payment aún no se materializa, puede mostrar el monto de Checkout como “total de la compra”, no “monto pagado”. No muestra referencias financieras en v1.

### 11.4 Entrega

- **Retiro:** método Retiro; `DeliveryCompletion.not_required` confirma únicamente ausencia esperada de Delivery. No existe autoridad actual que pruebe “Listo para retiro”; ese estado se excluye de v1.
- **Despacho:** resume estados de todas las Deliveries. `pending`/`assigned` → Preparando despacho; todas `picked_up` o combinación `picked_up/delivered` → En reparto; todas `delivered` → Entregado; cualquier `cancelled` coherente → Cancelado; mezcla imposible/Delivery faltante tras materialización → En revisión.

No se promete mapa ni tracking en tiempo real y no se muestran datos de courier.

### 11.5 Estado principal

Se calcula una sola vez en `CustomerOrderStatusResolver`. Evalúa primero integridad/contradicciones y estados terminales financieros; después evidencia logística; después materialización; finalmente sesión/Checkout temprano. Las secciones secundarias nunca contradicen el badge principal.

### 11.6 Timeline verificable

V1 incluye un timeline **limitado y opcional**, no una historia exhaustiva:

- “Compra creada”: `Checkout.created_at`.
- “Pago confirmado”: `Payment.paid_at`; fallback `Reconciliation.reconciled_at` se etiqueta “Pago conciliado”, no fecha bancaria.
- “Pedidos preparados en el sistema”: `BusinessCompletion.completed_at` (materialización, no preparación física).
- “Despacho creado”: `Delivery.created_at`, sólo para delivery.
- “En reparto” y “Entregado”: no hay timestamps semánticos dedicados en Delivery; `updated_at` no basta. Se omiten hasta disponer de eventos durables aprobados. `delivery_tracking` no se adopta sin contrato semántico público.

Nunca se inventan hitos por ausencia o secuencia esperada.

## 12. Modelo de estados visibles

Conjunto v1:

| Estado | Descripción cliente | Condición exacta resumida | Autoridad primaria | Aplica |
|---|---|---|---|---|
| Pendiente de pago | “Tu compra fue creada y aún no registra un pago confirmado.” | Checkout `pending`, sin evidencia financiera posterior ni contradicción; PaymentSession ausente/pending/failed/expired sin Reconciliation concluyente | Checkout | retiro/despacho VeciAhorra |
| Procesando pago | “Estamos confirmando el resultado de tu pago.” | Checkout `payment_started` o sesión lista/confirmada con Reconciliation ausente/pending/processing/retryable | Reconciliation, complementa Session | ambos |
| Pago rechazado | “El pago no fue aprobado.” | evidencia financiera durable rechazada y Reconciliation `permanent_failure` con clasificación financiera inequívoca; no existe Reconciliation completed/Payment pagado | Reconciliation + Webpay projection | ambos |
| Pago recibido | “Tu pago fue confirmado.” | Reconciliation `completed` y Payment coherente; BusinessCompletion aún no completó | Reconciliation/Payment | ambos |
| Preparando pedido | “Los minimarkets están preparando tu compra.” | BusinessCompletion completed, Orders paid; pickup o delivery aún sin evidencia logística superior | BusinessCompletion/Orders | ambos |
| Preparando despacho | “Tu despacho está siendo preparado.” | método delivery, conjunto exacto de Deliveries en pending/assigned, sin cancelación | Delivery | despacho |
| En reparto | “Tu compra va en camino.” | método delivery y todas las Deliveries están en `picked_up` o `delivered`, al menos una `picked_up` | Delivery | despacho |
| Entregado | “La entrega fue completada.” | método delivery y todas las Deliveries están `delivered`; o Orders `delivered` sólo si el conjunto y método son coherentes, preferencia Delivery | Delivery | despacho |
| Cancelado | “La compra fue cancelada.” | Checkout `cancelled` antes de pago concluyente, o todas las Deliveries `cancelled` con coherencia; no inferir desde expiración | Checkout/Delivery | ambos según etapa |
| En revisión | “Estamos revisando el estado de tu compra.” | manual_review, contradicción, origen desconocido, relación/cardinalidad ausente, fallo permanente no traducible inequívocamente o datos históricos insuficientes | integridad transversal | ambos |

Se excluye **Listo para retiro** porque ninguna autoridad actual prueba preparación física. Se excluye un “Preparado” genérico basado en FulfillmentCompletion. `Checkout.expired` sin evidencia posterior se presenta como Pendiente de pago con texto “La sesión de compra venció”; no como Cancelado. `Order.delivered` para pickup no se interpreta sin semántica verificada adicional.

## 13. Precedencia

Orden de evaluación, de mayor a menor:

1. **En revisión:** ownership inconsistente, origen desconocido, cardinalidad/conjuntos inválidos, estados imposibles, `manual_review`, permanent failure no clasificable.
2. **Pago rechazado:** fallo financiero permanente inequívoco, siempre que no exista conciliación completada contradictoria; contradicción vuelve a En revisión.
3. **Cancelado:** autoridad durable terminal coherente; conflicto con pago/entrega vuelve a En revisión.
4. **Entregado:** todas las Deliveries entregadas y conjunto completo.
5. **En reparto:** evidencia Delivery real.
6. **Preparando despacho:** Deliveries materializadas, no terminales.
7. **Preparando pedido:** BusinessCompletion completo y Orders pagadas.
8. **Pago recibido:** Reconciliation/Payment coherentes.
9. **Procesando pago:** proceso financiero no terminal.
10. **Pendiente de pago:** estado temprano sin evidencia posterior.

Reglas de exclusión:

- PaymentSession nunca sobreescribe Reconciliation o Payment.
- `Reconciliation.completed` no implica fulfillment.
- `DeliveryCompletion.completed` no implica reparto ni entrega.
- `DeliveryCompletion.not_required` no implica listo para retiro.
- `FulfillmentCompletion.completed` no implica entrega física.
- Delivery creada (`pending`) no implica En reparto.
- ausencia de Business/Delivery/FulfillmentCompletion no significa fallo.
- cualquier evidencia posterior sin sus relaciones requeridas produce En revisión.

## 14. Seguridad y ownership

### Autenticación

Todas las rutas requieren sesión WordPress autenticada y nonce REST cuando corresponda. Invitado recibe HTTP `401` con `authentication_required`; no se redirige desde REST. La página frontend puede ofrecer enlace de login conservando una URL de retorno local validada.

### Ownership en profundidad

La raíz confiable es Checkout: `owner_type=user AND user_id=current_user`. Sólo después se atraviesa:

`Checkout → CheckoutOrder → Orders/OrderItems → PaymentOrder/Payment → BusinessCompletionOrder/BusinessCompletion → DeliveryCompletion/FulfillmentCompletion → Delivery`.

PaymentSession se carga por Checkout; origen/reconciliation por relaciones durables verificadas. Cada entidad con `customer_id` debe coincidir como invariante adicional, pero no sustituye la raíz. Detalle repite toda la cadena; nunca carga primero una Order/Delivery por ID de URL.

### Enumeración y errores

La navegación usa `checkout.public_id`. Formato inválido, inexistente y ajeno responden igual: HTTP `404`, código `customer_order_not_found`, mensaje “No encontramos el pedido solicitado.” No se revela cuál caso ocurrió. IDs internos no se serializan.

### Protección y caché

Allowlist estricta de campos. Se excluyen datos de otros clientes, credenciales, tokens, hashes, fingerprints, payloads, leases, notas administrativas, SQL, excepciones, datos privados de Store/courier y estructuras internas. Respuestas: `Cache-Control: private, no-store, max-age=0`; no CDN/shared cache; `Vary` según autenticación. La caché privada futura debe estar particionada por user ID y versión de proyección.

## 15. Identificadores públicos

El recurso usa `Checkout.public_id` (`chk_` + 43 caracteres URL-safe). Se transmite completo en API/enlace; la UI puede mostrar una abreviación visual acompañada por etiqueta accesible, pero copiar/enlazar usa el valor completo. Orders, Payments, Deliveries y completions carecen de identificador público necesario para v1 y permanecen anidados, sin rutas propias. No se proponen UUID ni migraciones.

## 16. Contratos REST conceptuales

Estas rutas son diseño, no implementación.

### 16.1 Listado

`GET /veciahorra/v1/customer/orders?limit=20&cursor=…`

- autenticación obligatoria;
- `limit`: 1–50, default 20;
- `cursor`: opcional, opaco, firmado/codificado por servidor a partir de `(created_at,id)`; v1 puede iniciar sin aceptar cursor, pero siempre devuelve `page.next_cursor` nullable;
- orden descendente estable;
- no admite `user_id`, estados técnicos ni filtros arbitrarios.

```json
{
  "success": true,
  "data": {
    "items": [{
      "public_id": "chk_…",
      "created_at": "2026-07-16T12:00:00Z",
      "total": {"amount": "8000.00", "currency": "CLP"},
      "product_quantity": 3,
      "order_count": 2,
      "minimarket_count": 2,
      "fulfillment_method": "delivery",
      "visible_status": {"code": "preparing_delivery", "label": "Preparando despacho", "message": "…"},
      "detail_url": "/mi-cuenta/pedidos/chk_…"
    }],
    "page": {"limit": 20, "has_more": false, "next_cursor": null}
  }
}
```

`detail_url` puede omitirse y construirse desde configuración frontend; nunca se acepta una URL externa.

### 16.2 Detalle

`GET /veciahorra/v1/customer/orders/{public_id}`

Revalida formato y ownership. Respuesta:

```json
{
  "success": true,
  "data": {
    "public_id": "chk_…",
    "created_at": "2026-07-16T12:00:00Z",
    "visible_status": {"code": "payment_received", "label": "Pago recibido", "message": "…"},
    "fulfillment": {"method": "pickup", "label": "Retiro"},
    "summary": {"subtotal": "8000.00", "total": "8000.00", "currency": "CLP", "product_quantity": 3, "minimarket_count": 2},
    "groups": [{
      "minimarket": {"name": "Minimarket", "historical": false},
      "subtotal": "4000.00",
      "items": [{"name": "Producto", "name_historical": false, "image": null, "image_historical": false, "quantity": 2, "unit_price": "2000.00", "subtotal": "4000.00"}]
    }],
    "payment": {"status": "received", "label": "Pago recibido", "amount": "8000.00", "currency": "CLP", "paid_at": null, "method": "Webpay Plus"},
    "delivery": {"method": "pickup", "status": "not_applicable", "label": "Retiro"},
    "timeline": [{"code": "checkout_created", "label": "Compra creada", "occurred_at": "2026-07-16T12:00:00Z"}]
  }
}
```

No incluye estados técnicos, IDs internos ni campos nulos sensibles. Montos son strings decimales; fechas ISO 8601 UTC; códigos visibles en `snake_case` son contrato estable, labels localizables.

### 16.3 Errores

| HTTP | Código | Uso |
|---|---|---|
| 401 | `authentication_required` | invitado |
| 404 | `customer_order_not_found` | formato inválido, inexistente o ajeno |
| 409 | `customer_order_inconsistent` | raíz propia temporalmente no proyectable; mensaje seguro “Estado en revisión” sin detalles |
| 422 | `invalid_query` | parámetros de listado inválidos |
| 500 | `customer_panel_unavailable` | error inesperado, sin excepción |

Preferencia: inconsistencias representables retornan 200 con estado En revisión; 409 sólo cuando ni siquiera puede construirse un DTO seguro.

### 16.4 Compatibilidad

`veciahorra/v1` mantiene campos existentes; sólo se agregan campos opcionales. Cambios de significado, eliminación o estructura requieren nueva versión. Frontend ignora campos desconocidos y depende de `visible_status.code`, no de labels. Cursor es opaco y no contractual internamente.

## 17. Diseño frontend conceptual

Entrada futura: página “Mis pedidos” con shortcode exclusivo y configuración de URL; invitado ve mensaje y enlace de acceso. Navegación listado→detalle usa enlaces y History normal, tolera recarga y deep link.

Listado híbrido de cards: filas compactas en escritorio, cards de una columna en móvil, sin tabla horizontal. Debe soportar IDs/textos largos, múltiples minimarkets, badges extensos, skeleton, vacío y error.

Detalle: `CustomerPanelShell` → encabezado/estado → `OrderSummary` → grupos `OrderGroup`/`ProductLine` → `PaymentSummary` → `DeliverySummary` → `VerifiedTimeline`. Componentes conceptuales adicionales: `CustomerOrderList`, `CustomerOrderCard`, `CustomerOrderStatusBadge`, `CustomerOrderDetail`, `EmptyState`, `ErrorState`, `LoadingState`.

Accesibilidad: jerarquía h1/h2/h3; estados con texto e icono, nunca sólo color; links distinguibles; foco visible; navegación completa por teclado; `aria-live` para resultado de carga; skeleton no anunciado repetidamente; errores comprensibles; moneda/fecha localizadas sin alterar valores; no esconder datos esenciales en hover.

## 18. Integración con WooCommerce

V1 es exclusiva de `veciahorra_checkout`. Pedidos WooCommerce no aparecen. El origen se identifica durablemente en PaymentOriginContext/Reconciliation; un origen desconocido nunca se interpreta como VeciAhorra.

WooCommerce conserva su Order y ownership (`customer_id`/sesión WooCommerce), número público, estados y rama de completion propios. No se crea correspondencia con Orders internas. Una evolución puede implementar `WooCommerceCustomerOrderAdapter` que produzca DTOs equivalentes, pero debe mantener consultas, ownership, estados y links separados. La unificación visual sólo procede tras probar raíz común de presentación, no raíz de dominio.

## 19. Escenarios especiales

Acciones disponibles en todos los casos: **Ver detalle**, volver al listado y, si se indica, consultar canales generales de ayuda; nunca reintentar pago, processor o reparación.

| Escenario durable | Estado visible | Mostrar / omitir | Mensaje/autoridad |
|---|---|---|---|
| Checkout creado sin PaymentSession | Pendiente de pago | total/método; omitir pago | “Aún no registra pago”; Checkout |
| Session `pending` | Pendiente de pago | estado amigable; omitir refs | Checkout + Session |
| Session `create_processing` | Procesando pago | total; omitir detalles técnicos | Session |
| Session `ready` | Procesando pago si Checkout payment_started; si no, En revisión | método amigable | Session no prueba pago |
| Session `create_retryable` | Procesando pago | omitir error | Session, proceso recuperable interno |
| Session `create_ambiguous` | En revisión | total; omitir conclusión | Session |
| Session `create_failed` | Pendiente de pago | indicar que no hay pago confirmado | Session subordinada |
| Session `expired`/`cancelled` sin evidencia posterior | Pendiente de pago | indicar sesión vencida/cerrada | no equivale a compra cancelada |
| Webpay enviado sin retorno | Procesando pago | omitir resultado | Checkout/Session |
| Reconciliation ausente con retorno | En revisión | total; omitir conclusión | relación incompleta |
| Reconciliation `pending`/`processing`/`retryable` | Procesando pago | omitir errores | Reconciliation |
| Reconciliation `manual_review` | En revisión | mensaje soporte general | Reconciliation |
| Reconciliation `permanent_failure`, rechazo inequívoco | Pago rechazado | estado amigable; omitir códigos | Reconciliation + proyección Webpay |
| Reconciliation `permanent_failure`, causa técnica/ambigua | En revisión | omitir causa | Reconciliation |
| Reconciliation `completed`, Payment ausente | En revisión | conciliación confirmada, omitir monto pagado | falta materialización |
| Pago aprobado + Reconciliation completed | Pago recibido | monto/moneda; fecha si existe | Reconciliation/Payment |
| BusinessCompletion ausente tras conciliación reciente | Pago recibido | pago; omitir fulfillment | ausencia no es fallo |
| BusinessCompletion `pending`/`processing`/`retryable` | Pago recibido | pago; “preparación pendiente” | BusinessCompletion |
| BusinessCompletion `permanent_failure`/`manual_review` | En revisión | pago confirmado si seguro | BusinessCompletion |
| BusinessCompletion completed, Orders paid | Preparando pedido | grupos/ítems | BusinessCompletion/Orders |
| DeliveryCompletion ausente/pending/processing/retryable | Preparando pedido | método; omitir logística | DeliveryCompletion no lista aún |
| DeliveryCompletion `not_required` + pickup | Preparando pedido | Retiro; no “listo” | DeliveryCompletion |
| `not_required` + delivery o Deliveries presentes | En revisión | omitir progreso | contradicción |
| DeliveryCompletion completed + conjunto pending | Preparando despacho | grupos; estado despacho | DeliveryCompletion + Delivery |
| DeliveryCompletion `permanent_failure`/`manual_review` | En revisión | omitir causa | DeliveryCompletion |
| FulfillmentCompletion ausente/pending/processing/retryable | conservar estado probado anterior | no inferir fallo | FulfillmentCompletion |
| FulfillmentCompletion completed | conservar estado de Delivery/Orders | no “Entregado” por esto | cierre materialización |
| Fulfillment permanent/manual | En revisión | estado seguro | FulfillmentCompletion |
| Retiro sin autoridad de preparación física | Preparando pedido | punto sólo si fuente actual aprobada | no existe “listo” |
| Delivery `pending` | Preparando despacho | método y grupos | Delivery |
| Delivery `assigned` | Preparando despacho | no courier | Delivery |
| Delivery `picked_up` | En reparto | sin tracking/mapa | Delivery |
| todas Delivery `delivered` | Entregado | fecha sólo si hito durable futuro | Delivery |
| Delivery `cancelled` coherente | Cancelado | omitir causa interna | Delivery |
| mezcla delivered/cancelled o faltante | En revisión | omitir conclusión | cardinalidad/integridad |
| relación durable ausente | En revisión o excluir raíz si no navegable | no inferir | integridad |
| cardinalidad inválida | En revisión | sin IDs | constraints/proyección |
| origen `woocommerce` | fuera del listado | nada | política v1 |
| origen desconocido | En revisión/no proyectable | nada técnico | CompletionBranchPolicy |
| timestamps ausentes | estado sin timeline | omitir hito | no usar updated_at |
| pedido histórico sin fulfillment | En revisión o “Por confirmar” | datos comprobables | legado no inferido |
| Checkout `expired` | Pendiente de pago | “sesión vencida” | no cancelación automática |
| Checkout `cancelled` sin pago posterior | Cancelado | resumen | Checkout |

## 20. Rendimiento y escalabilidad

- Evitar N+1: seleccionar primero una página de Checkout IDs y cargar CheckoutOrders, agregados de Orders/Items, PaymentSession/Reconciliation/Payment/completions y Deliveries con consultas `IN` acotadas.
- Listado usa DTO mínimo y agregados SQL; no carga líneas, Product/Store, timeline ni tracking.
- Detalle carga todas las relaciones de una sola raíz y valida conjuntos en memoria acotada.
- Índices existentes útiles: Checkout owner/status, CheckoutOrder checkout, Order customer, Payment checkout/customer, PaymentSession checkout/status, Reconciliation origin, completions por business y Delivery order/customer. No se propone índice nuevo.
- Riesgo: no existe índice explícito `checkouts(owner_type,user_id,created_at,id)`; el índice actual incluye status, no fecha. Medir con volumen real; posible optimización futura, fuera del hito.
- Cursor `(created_at,id)` evita duplicación/saltos frente a nuevos pedidos; el ID queda dentro del cursor opaco.
- Limitar grupos/ítems en detalle mediante límites defensivos y error En revisión si cardinalidad anómala; no truncar silenciosamente una compra.
- Caché sólo privada por usuario y versión, preferiblemente no-store en v1.

## 21. Riesgos

| Riesgo | Impacto | Probabilidad | Mitigación | ¿Bloquea? |
|---|---|---|---|---|
| Orders históricas huérfanas sin Checkout público | alto: no navegables | media | excluir v1 y medir; diseño futuro de migración separado | no para compras nuevas |
| nombre/imagen no son snapshots | medio | alta | marcar actuales, fallback neutro, no prometer historia | no |
| no existe autoridad “listo para retiro” | medio | alta | excluir ese estado | no |
| Delivery carece de timestamps por transición | medio | alta | timeline limitado; no usar updated_at | no |
| CustomerPanel actual expone Order IDs internos | alto | existente | sustituir contrato por Checkout público en implementación futura; plan de compatibilidad | sí para publicar v1 nuevo |
| consultas complejas/N+1 | medio | media | query service por lotes y DTO separado | no |
| índice de orden por owner/fecha no óptimo | medio | baja inicialmente | medir EXPLAIN/volumen, registrar mejora futura | no |
| estados históricos contradictorios | alto | media | precedencia En revisión y observabilidad interna | no |
| confusión WooCommerce/VeciAhorra | alto | media | excluir WooCommerce y validar origin | no |
| checkouts invitados no atribuibles | medio | alta | no inferir; fuera de v1 | no |

## 22. Decisiones de diseño

| ID | Decisión | Justificación |
|---|---|---|
| D1 | Checkout es raíz y compra visible | tiene owner durable, total, fulfillment y public_id |
| D2 | Una tarjeta por Checkout | representa correctamente varias Orders/minimarkets |
| D3 | navegación por `Checkout.public_id` | evita IDs incrementales y migraciones |
| D4 | ownership sólo desde Checkout user | no inferir por PII ni URL |
| D5 | WooCommerce fuera de v1 | no comparte raíz/ownership/modelo inequívoco |
| D6 | estados visibles resueltos en servidor | frontend no es autoridad |
| D7 | En revisión domina contradicciones | evita progreso optimista |
| D8 | no “Listo para retiro” | falta autoridad física durable |
| D9 | Entregado exige Delivery delivered | completions sólo materializan/cierra pipeline |
| D10 | timeline limitado | sólo timestamps semánticos reales |
| D11 | catálogo actual es decorativo, no histórico | OrderItem no congela nombre/imagen |
| D12 | errores ajeno/inexistente/formato iguales | prevención de enumeración |
| D13 | listado/detalle DTO separados | rendimiento y evolución contractual |
| D14 | cursor opaco como evolución | estabilidad frente a inserciones |

## 23. Estrategia de implementación futura (sin código)

1. Crear pruebas de contrato/ownership que fallen con el diseño actual por Order ID.
2. Implementar queries read-only por Checkout, sin modificar autoridades.
3. Implementar policy de ownership y validador de integridad.
4. Implementar StatusResolver con tabla de precedencia y fixtures exhaustivos.
5. Implementar DTOs/Presenter allowlist.
6. Registrar rutas conceptuales bajo un hito REST explícito; mantener/retirar `/me/orders` con política de compatibilidad documentada.
7. Implementar shortcode/página autenticada y componentes sólo después de estabilizar contrato.
8. Probar enumeración, caché, N+1, multi-minimarket, históricos incompletos y WooCommerce excluido.
9. Instrumentar observabilidad interna de inconsistencias sin exponer detalles.

## 24. Auditoría arquitectónica final

### Observaciones encontradas

1. El CustomerPanel vigente usa Order incremental como recurso y lista una compra multi-minimarket como pedidos separados.
2. Su `visibleStatus()` traduce sólo Order (`reserved`/`paid`) sin PaymentReconciliation, completions ni Delivery.
3. PaymentSession podía confundirse con resultado financiero si se usaba aisladamente.
4. `DeliveryCompletion.completed` sólo crea Deliveries pending; no prueba reparto/entrega.
5. `FulfillmentCompletion.completed` cierra materialización y tampoco prueba entrega física.
6. `not_required` no prueba listo para retiro.
7. OrderItem no contiene snapshot de nombre/imagen; el repositorio vigente hace LEFT JOIN al catálogo mutable.
8. Delivery tiene estados logísticos suficientes para “En reparto/Entregado”, pero no timestamps semánticos de transición.
9. WooCommerce sigue una rama distinta y no puede fusionarse con Checkout interno.
10. Checkouts invitados no pueden atribuirse a una cuenta por autoridad existente.
11. El índice de Checkout no está orientado expresamente a paginar owner por fecha.

### Correcciones aplicadas al diseño

- Se cambió la raíz conceptual de Order a Checkout público.
- Se agrupó toda compra multi-minimarket en una unidad con Orders anidadas.
- Se definió ownership desde WordPress→Checkout y traversal autorizado.
- Se estableció una precedencia conservadora con En revisión dominante.
- Se subordinó PaymentSession a Reconciliation/Payment.
- Se separaron DeliveryCompletion, FulfillmentCompletion y Delivery.
- Se eliminó “Listo para retiro” y se exigió Delivery delivered para Entregado.
- Se marcó catálogo/Store actual como no histórico y se añadió fallback.
- Se limitó el timeline a timestamps semánticos demostrables.
- Se excluyó WooCommerce e invitados de v1.
- Se uniformó 404 para formato inválido, inexistente y ajeno.
- Se separaron DTOs de listado/detalle y se diseñó carga por lotes/cursor.

### Riesgos residuales

Persisten la falta de snapshots textuales/imagen, ausencia de estado físico para retiro, falta de timestamps logísticos específicos, Orders históricas sin Checkout, atribución imposible de invitados y posible limitación de índice a escala. Ninguno debe resolverse mediante heurísticas del panel; el contrato actual de CustomerPanel sí debe sustituirse antes de publicar v1.

### Conclusión

El diseño está **listo para implementación condicionada**: primero deben implementarse la raíz Checkout, ownership opaco, proyección por lotes y resolver de estados con pruebas. No requiere ni justifica nuevas tablas, migraciones o autoridades. La implementación no debe ampliar estados visibles más allá de evidencia durable ni reutilizar el endpoint incremental actual como contrato final.

## 25. Conclusión

Panel del Cliente v1 debe construirse como una proyección autenticada de Checkouts VeciAhorra propios, agrupando sus Orders por minimarket y navegando exclusivamente por `Checkout.public_id`. WooCommerce, compras invitadas no vinculadas y Orders históricas sin raíz quedan fuera de v1. El estado principal se resuelve en servidor con precedencia conservadora: Reconciliation/Payment gobiernan el resultado financiero, Delivery gobierna la logística real y cualquier contradicción queda En revisión. Con estas condiciones, el diseño puede pasar a una fase de implementación sin crear tablas, migraciones, estados persistentes ni nuevas autoridades.
