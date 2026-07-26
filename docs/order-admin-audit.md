# Hito 37.0 — Auditoría de la administración operativa de Orders

## 1. Resumen ejecutivo

Esta auditoría describe el estado de Orders en
`f9dc2e96545c10b840b76036c253cbdfe103bad2`. Es una revisión del código,
esquemas, rutas, pruebas e historial Git; no implementa la Serie 37.

Orders no posee actualmente una administración operacional comparable con
Product, Store o Inventory. Existe un REST técnico protegido por
`manage_options` (`GET/POST /veciahorra/v1/orders` y
`GET /veciahorra/v1/orders/{id}`), pero no existe menú, página, listado
paginado, detalle agregado, timeline ni acciones administrativas de Order.

La operación durable de una compra está distribuida entre varias autoridades:

- Order conserva la división por Store, el total y los snapshots de líneas;
- Checkout agrupa Orders y conserva propietario, total, método e idempotencia;
- Reservations gobierna el bloqueo y liberación de stock;
- PaymentSession, WebpayReturn y Reconciliation gobiernan la evidencia
  financiera;
- Payment y BusinessCompletion materializan el resultado comercial;
- Delivery y sus eventos gobiernan el despacho;
- DeliveryCompletion y FulfillmentCompletion coordinan ramas durables.

`orders.status` no es un lifecycle cerrado. Los únicos valores escritos por el
código actual son `reserved`, `paid` y `delivered`. La expiración o cancelación
de Checkout y Reservations no actualiza Order; un pickup completado puede dejar
Order en `paid`; y `delivered` se escribe desde Delivery sin CAS ni transacción
con la mutación de Delivery. El estado que ve Customer Panel es una proyección
derivada de todas las autoridades, no una traducción directa de
`orders.status`.

### Veredicto

La base transaccional de pagos y completions contiene buenas garantías de
idempotencia, locks, leases y recuperación. Sin embargo, una UI administrativa
de Orders **no debe construirse directamente sobre las lecturas o mutaciones
actuales**. Antes deben fijarse un contrato de lifecycle agregado, un inspector
de consistencia, una política de privacidad y un read model explícito. El
`POST /orders` técnico tampoco debe reutilizarse: no invoca `OrderRequest` y
acepta precio unitario aportado por el caller.

## 2. Mapa arquitectónico actual

```text
Cart
  └─ CheckoutService
       ├─ Reservations (stock bloqueado)
       ├─ OrderService / OrderRepository
       │    ├─ orders
       │    └─ order_items (snapshots)
       ├─ checkouts
       └─ checkout_orders
              │
              ▼
PaymentSessionService ── payment_sessions
  ├─ payment_origin_contexts
  ├─ Webpay ── webpay_returns
  └─ Reconciliation ── payment_reconciliations
          │
          ▼
BusinessCompletionProcessor
  ├─ payments / payment_orders
  ├─ Reservations: active → consumed
  ├─ Orders: reserved → paid
  └─ business_completions / business_completion_orders
          │
          ▼
DeliveryCompletionProcessor
  ├─ deliveries (si método delivery)
  └─ delivery_completions
          │
          ▼
FulfillmentCompletionProcessor
  └─ fulfillment_completions

DeliveryService
  ├─ pending → assigned → picked_up → delivered
  ├─ delivery_tracking
  └─ Order: paid → delivered

CustomerPurchaseQuery + CustomerPurchaseStatusResolver
  └─ proyección pública derivada de todas las autoridades anteriores
```

### 2.1 Ubicaciones principales

| Responsabilidad | Implementación principal |
| --- | --- |
| Orders | `app/Modules/Orders/**` |
| Checkout | `app/Modules/Checkout/**` |
| Reservations | `app/Modules/Reservations/**` |
| Payments y Webpay | `app/Modules/Payments/**` |
| Reconciliation | `app/Modules/Payments/Reconciliation/**` |
| Business completion | `app/Modules/Payments/BusinessCompletion/**` |
| Delivery | `app/Modules/Delivery/**` |
| Fulfillment durable | `app/Modules/Fulfillment/**` |
| Proyección pública | `app/Modules/CustomerPanel/**` |
| Esquemas | `app/Database/Schemas/*Order*`, `*Checkout*`, `*Reservation*`, `*Payment*`, `*Delivery*`, `*Completion*` |

### 2.2 Responsabilidades exactas

**Orders** crea una Order por Store, conserva cliente, Store, total, expiración
de reserva y estado resumido. `OrderService` también coordina la creación de
líneas y, en el flujo heredado, reservas.

**OrderItems** conserva `product_id`, `inventory_id`, cantidad, precio unitario
y subtotal. Precio y subtotal son snapshots históricos; los nombres actuales de
Product que añade `findItems()` son decorativos y no snapshots.

**Checkouts** representa el intento comercial agrupador, con propietario
user/session, `public_id`, método de fulfillment, moneda, total, expiración y
clave/fingerprint de idempotencia.

**CheckoutOrders** fija la pertenencia de cada Order a un único Checkout mediante
unicidad durable de `order_id`.

**Reservations** es autoridad del bloqueo de unidades por Inventory. Sus estados
son `active`, `released`, `expired` y `consumed`. La liberación repone stock; el
consumo confirma que un pago aprobado tomó la reserva.

**PaymentSessions** gobierna la creación remota de una sesión, su lease de
creación, token seguro, evidencia de confirmación y estados técnicos.

**Payments** materializa un pago comercial para un Checkout y sus Orders.
`payment_orders` fija una relación única Order → Payment.

**WebpayReturn** conserva el retorno financiero normalizado y evidencia técnica.
Almacena hashes y también payloads JSON que no deben exponerse sin proyección.

**Reconciliation** evalúa evidencia financiera y coordina processing mediante
lease/version. Es autoridad de `pending`, `processing`, `completed`,
`retryable`, `permanent_failure` y `manual_review`.

**BusinessCompletion** materializa de forma idempotente Payment, consume
Reservations, marca Orders pagadas y congela el conjunto de Orders.

**Deliveries** representa entrega por Order y su lifecycle. DeliveryTracking
registra eventos y puede contener geolocalización.

**DeliveryCompletion** materializa Deliveries para fulfillment `delivery` o
registra `not_required` para pickup.

**FulfillmentCompletion** confirma que la rama de entrega requerida terminó de
materializarse de manera consistente. No equivale por sí solo a que el cliente
haya recibido físicamente el pedido.

## 3. Autoridades durables y estados derivados

| Dato | Autoridad durable | Observación |
| --- | --- | --- |
| Identidad y Store de Order | `orders` | Una Order pertenece a un Store |
| Líneas, cantidades y precios congelados | `order_items` | No deben recalcularse desde Product/Inventory |
| Agrupación de Orders | `checkouts` + `checkout_orders` | Checkout puede contener varias Stores |
| Unidades bloqueadas | `reservations` + stock Inventory | `active` ya redujo stock |
| Resultado financiero | WebpayReturn + Reconciliation | Payment es materialización comercial |
| Orders pagadas | `orders.status=paid` | Escrito por BusinessCompletion |
| Entrega física | `deliveries` + tracking | Order `delivered` es una proyección persistida parcial |
| Progreso durable de ramas | tablas `*_completions` | Tienen leases y resultados técnicos |
| Estado mostrado al cliente | Customer Panel resolver | Derivado, no persistido en Order |

No existe una sola fila que sea autoridad sobre “estado operacional completo de
la compra”. Una futura administración debe presentar las dimensiones por
separado y solo después derivar un resumen.

## 4. Modelo de datos

### 4.1 Núcleo Order

`orders`:

- `id`;
- `customer_id`;
- `minimarket_id`;
- `total decimal(10,2)`;
- `status varchar(20)`, default `reserved`;
- `reservation_expires_at`;
- `created_at`, `updated_at`.

Índices: `customer_id`, `minimarket_id`, `status` y
`reservation_expires_at`. No existe `public_id`, versión CAS ni índice compuesto
para filtros administrativos.

`order_items`:

- `id`, `order_id`;
- `product_id`, `inventory_id`;
- `quantity`;
- `unit_price`, `subtotal`;
- timestamps.

Índices individuales por Order, Product e Inventory.

### 4.2 Relaciones

| Relación | Tabla/campo | Garantía real |
| --- | --- | --- |
| Order → Store | `orders.minimarket_id` | Índice; referencia lógica |
| Order → Checkout | `checkout_orders.order_id` | Unique por Order |
| Order → líneas | `order_items.order_id` | Índice, sin FK declarada |
| Order → Reservations | `reservations.order_id` nullable | Índice, sin unicidad por línea |
| Order → Payment | `payment_orders.order_id` | Unique por Order |
| Order → Delivery | `deliveries.order_id` | Índice; la unicidad se añade por migración |
| Order → BusinessCompletion | `business_completion_orders.order_id` | Unique por Order |

Los builders revisados no declaran claves foráneas SQL para estas relaciones.
Las integridades son lógicas, índices únicos o validaciones de servicio. Por
tanto son posibles huérfanos si una ruta de borrado o fallo elude los
coordinadores.

### 4.3 Snapshots

Se preservan:

- `product_id` e `inventory_id` originales;
- cantidad;
- `unit_price` y `subtotal`;
- `orders.minimarket_id`;
- total de Order y total del Checkout.

No se congela en OrderItems el nombre, SKU o imagen del Product ni el nombre del
Store. Customer Panel consulta nombres actuales y los identifica como no
históricos. Una UI administrativa debe etiquetar claramente identidad actual
frente a snapshot.

### 4.4 Versiones y temporalidad

Order solo ofrece `updated_at`; no existe CAS durable sobre esa columna.
PaymentSession usa `create_version` y lease de creación. Reconciliation,
BusinessCompletion, DeliveryCompletion y FulfillmentCompletion usan
`lease_owner`, expiración y `lease_version`. Los timestamps de cada módulo
permiten construir una cronología, pero hoy no existe una tabla de eventos
unificada.

### 4.5 Riesgos de inconsistencia

- OrderItems, CheckoutOrders, Reservations, PaymentOrders, Deliveries y
  completions carecen de FKs declaradas.
- `OrderRepository::delete()` borra OrderItems y Order, pero no todas las
  referencias externas. Se usa como compensación de creación, no como delete
  comercial seguro.
- `deliveries.order_id` depende de una migración para unicidad; la auditoría de
  despliegue deberá confirmar que fue aplicada.
- Product o Store faltantes no deben destruir el snapshot histórico.
- Una Reservation expirada puede coexistir con Order `reserved`.
- Checkout cancelado/expirado puede coexistir con Order `reserved`.
- Fulfillment pickup completado puede coexistir con Order `paid`.
- Delivery `delivered` y Order `delivered` se actualizan en llamadas separadas.

## 5. Lifecycle real de Order

### 5.1 Estados persistidos observados

| Estado | Escritor | Significado real |
| --- | --- | --- |
| `reserved` | `OrderService` | Order creada con reserva esperada |
| `paid` | `BusinessCompletionProcessor` → `markPaid()` | Pago materializado y reservas consumidas |
| `delivered` | `DeliveryService` → `markDelivered()` | Delivery transitó a delivered |

No existe un enum/contrato de dominio propio para Order. El esquema acepta
cualquier string de hasta 20 caracteres y los lectores conocen por convención
los tres valores anteriores.

### 5.2 Transiciones ejecutables

```text
creación → reserved
reserved --(reconciliación aprobada + business completion)→ paid
paid --(Delivery picked_up → delivered)→ delivered
```

`reserved → paid` está protegido por locks de Orders, validación de Checkout,
Payment y Reservations y un update condicional a `status=reserved`.

`paid → delivered` no usa comparación del estado esperado: `markDelivered()`
actualiza por ID. Delivery valida su propia transición antes, pero ambas
mutaciones no forman una única transacción.

### 5.3 Estados distribuidos

- reserva expirada/released: Reservation, no Order;
- pago iniciado: Checkout `payment_started` y PaymentSession;
- pago aprobado/rechazado: WebpayReturn/Reconciliation;
- pago materializado: Payment `paid` y Order `paid`;
- cancelación/expiración: Checkout/PaymentSession/Reservations;
- preparación: estado derivado del Customer Panel; no existe estado Order ni
  comando “mark preparing”;
- despacho: Delivery `assigned`/`picked_up`;
- entrega: Delivery `delivered` y luego Order `delivered`;
- fulfillment: completion durable separado.

### 5.4 Conclusión de lifecycle

Order no tiene lifecycle cerrado; tiene tres hitos persistidos y convenciones
distribuidas. `reserved` no permite distinguir reserva vigente, expiración,
cancelación o pago rechazado. `paid` no distingue preparación, pickup listo,
delivery pendiente o fulfillment completado.

No hay transición Order ejecutable a `cancelled`, `expired`, `preparing` o
`dispatched`. Esos conceptos son externos o derivados. Tampoco existe devolución
o anulación de pago.

## 6. Concurrencia, idempotencia y recuperación

### 6.1 Garantías existentes

- Checkout: transacción, lock de Orders y unicidad
  `(idempotency_owner_key,idempotency_key)`.
- CheckoutOrders: unicidad por Order.
- PaymentSession: clave por Checkout, fingerprint, lease de creación y
  `create_version`.
- WebpayReturn: unicidad por token hash y fingerprint financiero.
- Reconciliation: unicidades de retorno/origen/fingerprint, lease versionado,
  attempt count y estados terminales.
- Payment: unicidad por session, reconciliation e idempotency key.
- PaymentOrders y BusinessCompletionOrders: unicidad por Order.
- Business/Delivery/Fulfillment completions: idempotency key, lease versionado,
  retries y cierre terminal.
- Reservations: `active → consumed/expired` mediante updates condicionales.
- Inventory stock: descuento/reposición atómicos.
- Locks de colecciones se adquieren en orden ascendente de Order/Reservation.
- DurableCompletionScheduler usa Action Scheduler y backoff acotado.

### 6.2 Riesgos

- Order no posee `version` ni CAS administrativo.
- `updated_at` no se compara antes de escribir.
- `markDelivered()` no exige `status=paid` y no está en la misma transacción que
  Delivery.
- El endpoint técnico de creación no es idempotente.
- `OrderService::create()` coordina compensaciones manuales; una caída entre
  pasos puede requerir inspección.
- La expiración marca Reservation y repone stock en dos operaciones; existe
  compensación `restoreActive()`, pero debe seguir probándose ante fallos.
- Cualquier acción administrativa directa sobre Order podría competir con
  reconciliación, leases o Delivery y aceptar una lectura obsoleta.
- Reintentar manualmente sin respetar lease/idempotency key puede duplicar
  procesamiento o generar `manual_review`.

### 6.3 Versión administrativa

Una versión administrativa durable es necesaria antes de mutaciones de Order.
Puede ser una versión explícita monotónica o un token compuesto y estable, pero
no debe reutilizar `lease_version` de otro agregado. Para una primera versión
read-only basta exponer `last_write_wins`/ausencia de CAS y advertir que las
acciones están deshabilitadas.

## 7. Administración existente

### 7.1 Lo que existe

- REST técnico:
  - `GET /veciahorra/v1/orders`;
  - `POST /veciahorra/v1/orders`;
  - `GET /veciahorra/v1/orders/{id}`.
- Filtros de repositorio por `customer_id`, `minimarket_id` y `status`.
- REST de Reservations y Delivery protegido por `manage_options`.
- Customer Panel read-only para el propietario.
- herramientas y pruebas manuales bajo `tests/manual`.

### 7.2 Lo que no existe

- menú o página WordPress Admin de Orders;
- listado administrativo paginado;
- búsqueda, filtros tipados y count;
- detalle administrativo agregado;
- timeline;
- inspector de consistencia;
- navegación administrativa contextual;
- acciones operativas de Order;
- read model con privacidad y rutas seguras.

El Customer Panel no es administración: aplica ownership, minimiza datos y
deriva estados públicos. Las pruebas manuales tampoco son una UI operacional.

### 7.3 Código que no debe reutilizarse directamente

`OrderRepository::list()` devuelve `SELECT *`, no pagina, acepta filtros sin
parser canónico, no comprueba `last_error` y ordena solo por ID descendente.

`OrderRepository::find()` devuelve la fila cruda, sin líneas ni autoridades
relacionadas.

`POST /orders` llama directamente a `OrderController::store()` y
`OrderService::create()`; no usa `OrderRequest`. El servicio exige y persiste
`unit_price` aportado por el caller, sin derivarlo del snapshot confiable de
Cart/Inventory. Aunque requiere `manage_options`, es un constructor técnico que
no debe presentarse como acción administrativa.

`OrderService::cancelOrders()` y `OrderRepository::delete()` son compensaciones
de creación. No representan cancelación comercial y no deben exponerse.

## 8. Lecturas administrativas propuestas

### 8.1 Listado operacional

Cada fila debería incluir:

- ID interno y, si se decide crear, identificador público durable;
- Checkout `public_id`;
- Store ID, nombre actual y diagnóstico de referencia;
- cliente minimizado: tipo de owner y un identificador administrativo
  contextual, sin email/teléfono por defecto;
- `orders.status` observado;
- estado resumido de Reservations;
- PaymentSession/Payment/Reconciliation;
- Delivery y Fulfillment;
- total y moneda del Checkout/Order;
- cantidad de líneas y unidades;
- creación/actualización;
- alertas dimensionales;
- acciones calculadas por backend.

Filtros mínimos: ID, Checkout, Store, estado Order, estado financiero, reserva,
delivery/fulfillment, rango de fechas y alertas. Orden allowlisted con desempate
por `o.id`; paginación estable y count con los mismos predicados.

### 8.2 Detalle operacional

Secciones propuestas:

1. identidad, estado observado y resumen derivado;
2. Store y cliente minimizado;
3. líneas con snapshots y referencias actuales separadas;
4. totales Order/Checkout y reconciliación aritmética;
5. Checkout y ownership;
6. Reservations y relación exacta con líneas;
7. PaymentSession, WebpayReturn seguro, Reconciliation, Payment y
   BusinessCompletion;
8. Delivery, tracking sanitizado y Fulfillment;
9. timeline derivado de timestamps/eventos;
10. inspector de cardinalidad, referencias, estados y montos;
11. navegación a Store, Product, Inventory y recursos operacionales;
12. acciones permitidas, inicialmente ninguna mutación de Order.

El DTO debe conservar estructuras dimensionales y códigos estables; no debe
aplanar todo a un supuesto “estado final”.

## 9. Privacidad y seguridad

### 9.1 PII disponible

- `customer_id` y usuario WordPress asociado;
- `session_id` de Checkout para guest;
- posible nombre/email del perfil WordPress si se resuelve;
- `courier_id`;
- latitud/longitud en DeliveryTracking;
- metadata y redirect URL de PaymentSession;
- tokens/hashes, buy order, session financiera y evidencia Webpay;
- payloads normalizados y contextos JSON;
- referencias financieras y códigos de autorización hasheados.

### 9.2 Mínimo necesario

Listado: Order/Checkout/Store, owner type, ID interno del cliente solo cuando sea
necesario, estados, total y alertas. No mostrar email, nombre, dirección,
teléfono, session ID, coordenadas, tokens ni payload financiero.

Detalle: revelar PII solo detrás de una capacidad explícita y con propósito
operacional. Las coordenadas deben omitirse o reducirse a eventos; los datos
Webpay deben proyectarse a códigos seguros y referencias ya sanitizadas.

### 9.3 Contrato de seguridad propuesto

- capacidad nueva y específica, por ejemplo `manage_veciahorra_orders`, con
  migración/assign explícito; `manage_options` puede ser compatibilidad inicial,
  no la frontera final;
- nonce REST obligatorio;
- `Cache-Control: private, no-store`;
- 401 no autenticado, 403 sin capacidad, 404 recurso ausente, 409 conflicto de
  versión/estado, 422 input inválido y 500 fallo interno;
- errores públicos con códigos estables y mensajes propios;
- logging interno con correlation ID, sin devolver excepciones;
- allowlist de query/body y URLs administrativas internas;
- nunca exponer SQL, stack, rutas, clases, mensajes de Transbank, tokens,
  credenciales ni payloads crudos.

Las rutas Orders actuales dependen de la autenticación REST de WordPress para el
nonce, devuelven un booleano de `current_user_can()` y no añaden
`private, no-store`. Un endpoint administrativo nuevo debe hacer explícito el
contrato certificado en módulos anteriores.

## 10. Inventario de acciones

| Acción | Existe realmente | Autoridad y precondiciones | Efectos/idempotencia/riesgo | Primera versión |
| --- | --- | --- | --- | --- |
| Consultar | Parcial | Orders REST, `manage_options` | Fila cruda; sin privacidad agregada | Sí, mediante read model nuevo |
| Cancelar Order | No | Solo delete compensatorio durante creación | Hard delete incompleto; no es cancelación | No |
| Reintentar reconciliación | Interna | Scheduler/worker; pending/retryable/lease recuperable | Lease, backoff, fingerprints; alto riesgo manual | No hasta comando dedicado |
| Reintentar negocio | Interna | BusinessCompletion retryable | Lease e idempotency key | No inicialmente |
| Reintentar DeliveryCompletion | Interna | completion retryable | Lease/version | No inicialmente |
| Reintentar Fulfillment | Interna | completion retryable | Lease/version | No inicialmente |
| Marcar preparación | No | Sin autoridad persistida | Sería un lifecycle nuevo | No |
| Marcar despacho | Parcial | Delivery: assign/picked_up | REST Delivery existente; no acción Order | Enlace contextual, no duplicar |
| Confirmar entrega | Sí, en Delivery | Delivery debe estar `picked_up` | Escribe Delivery y Order separadamente; no CAS Order | No hasta endurecer atomicidad |
| Restaurar stock | Interna | Expiración/compensación Reservation | Update condicional y reposición; riesgo de doble restore si se elude | No |
| Devolver/anular pago | No | No hay dominio refund/void | Riesgo financiero crítico | No |
| Eliminar Order | Solo compensación | Creación incompleta | Puede dejar referencias huérfanas | Nunca como primera acción |

Las acciones futuras deben ser comandos semánticos idempotentes, no PATCH
genérico de `orders.status`.

## 11. Consultas y rendimiento

### 11.1 Estado actual

- listado Orders: una query sin paginación y sin joins;
- detalle Order: una query de fila;
- líneas: una query adicional con LEFT JOIN Product;
- Store: una query adicional;
- cada módulo relacionado posee consultas propias.

Componer el detalle llamando servicios uno por uno produciría N+1 y lecturas no
coherentes. CustomerPurchaseQuery demuestra que las lecturas batch son
posibles, pero su DTO y ownership son públicos y no deben reutilizarse como
contrato administrativo.

### 11.2 Estrategia propuesta

Listado:

1. página con Orders, Checkout y Store;
2. agregado batch de líneas/Reservations;
3. agregado batch de Payment/Reconciliation/Delivery/Completions y flags de
   consistencia;
4. count con filtros equivalentes.

Detalle:

1. Order + Checkout + Store;
2. líneas;
3. Reservations;
4. cadena PaymentSession/WebpayReturn/Reconciliation/Payment/Business;
5. Delivery;
6. tracking proyectado;
7. completions;
8. consulta de inspector solo si no puede derivarse de las anteriores.

El timeline debe ensamblarse desde estas lecturas y no consultar por evento.

### 11.3 Presupuesto preliminar

| Lectura futura | Queries propias máximas | Auxiliares |
| --- | ---: | ---: |
| Listado vacío | 2 | 0 |
| Listado no vacío | 4 | 0 |
| Detalle completo | 8 | 0–1 para usuario WordPress, solo si autorizado |
| Líneas | 1 batch | incluido |
| Timeline | 0 adicionales | derivado de lecturas del detalle |
| Inspector | 0–1 | incluido en máximo 8 |

El presupuesto debe ser constante respecto de filas (máximo 100), probarse con
conteo real y acompañarse de `EXPLAIN` antes de crear índices. La ordenación debe
terminar siempre en `o.id`.

## 12. Integraciones que deben preservarse

- **Store Admin:** navegación filtrada, sin alterar lifecycle Store.
- **Product Admin:** enlaces a identidad actual; snapshots siguen intactos.
- **Inventory Admin:** `inventory_id` original permanece autoridad histórica.
- **Customer Panel:** ownership, DTO mínimo y estados derivados no cambian.
- **Cart/Checkout:** aislamiento por Store, precios congelados e idempotencia.
- **Reservations:** consumo o restauración única, nunca stock neto inventado.
- **Payments/Webpay/Reconciliation:** fingerprints, tokens, leases y
  materialización única.
- **Delivery/Fulfillment:** no duplicar sus comandos ni reinterpretar completion
  como entrega física.
- **Catálogo público:** ninguna lectura administrativa participa en
  disponibilidad o precio público.

Una administración Orders no debe mutar snapshots, reasignar Store, Product o
Inventory, recalcular precios, mezclar Orders de distintos clientes ni romper el
aislamiento por minimarket.

## 13. Brechas y riesgos priorizados

### 13.1 Bloqueantes

1. No existe contrato agregado de lifecycle/consistencia.
2. No existe read model administrativo con privacidad.
3. Order no tiene CAS/version administrativa.
4. Delivery y Order `delivered` se escriben sin atomicidad ni CAS compartido.
5. `POST /orders` acepta snapshots aportados por caller y omite `OrderRequest`.
6. Hard delete compensatorio no es cancelación segura.
7. No hay refund/void ni política administrativa financiera.

### 13.2 Importantes

- ausencia de página, listado, detalle, timeline e inspector;
- ausencia de `public_id` de Order;
- ausencia de Cache-Control explícito en Orders REST;
- parser de listado no tipado, sin rechazo de desconocidos ni paginación;
- fallos de DB no comprobados uniformemente en lecturas Orders;
- relaciones sin FKs y posibles huérfanos;
- Order `reserved` ambiguo tras expiración/cancelación;
- pickup completado sin estado Order equivalente;
- Customer/Store/Product decorativos no históricos.

### 13.3 Mejoras posteriores

- capacidad granular separada de `manage_options`;
- timeline durable unificado si la proyección por timestamps resulta
  insuficiente;
- índices compuestos basados en medición;
- exportación auditable con minimización de PII;
- acciones manuales dedicadas para completions en `manual_review`.

### 13.4 Comportamiento correcto que debe conservarse

- separación de Orders por Store;
- snapshots de precio, cantidad, Product e Inventory;
- unicidades CheckoutOrder/PaymentOrder/BusinessCompletionOrder;
- locks ordenados y transacciones de pago;
- fingerprints e idempotency keys;
- leases versionados y backoff;
- consumo y restauración condicional de Reservations;
- Customer Panel read-only y con ownership;
- referencias financieras sanitizadas.

### 13.5 Clasificación conceptual

Defectos reales: constructor técnico de Order con precio confiado al caller,
escritura Delivery/Order no atómica y lecturas que no distinguen fallo DB de
vacío de forma uniforme.

Ausencia de interfaz: menú, listado, detalle, filtros, timeline y acciones.

Deuda arquitectónica: lifecycle distribuido, falta de versión Order, relaciones
lógicas y ausencia de DTO administrativo.

Deliberadamente no implementado: cancelación comercial, preparación, refunds y
acciones manuales sobre completions. No deben describirse como funciones
existentes.

## 14. Propuesta incremental de Serie 37

### 37.1 Contrato de lifecycle y consistencia

- **Objetivo:** definir autoridades, estados observados, resumen derivado,
  invariantes y comandos posibles.
- **Alcance:** Order, Checkout, Reservations, Payment, Reconciliation, Delivery
  y Fulfillment.
- **Contratos:** matriz de estados, precedencia, códigos de inconsistencia y
  política CAS.
- **Pruebas:** todas las combinaciones válidas/inválidas y carreras
  paid/delivered/expiration.
- **Exclusiones:** UI, endpoints y acciones nuevas.
- **Dependencias:** esta auditoría.
- **Cierre:** documento aprobado y sin estados/acciones ficticios.

### 37.2 Read model administrativo

- **Objetivo:** repositorio y servicio read-only para listado/detalle.
- **Alcance:** DTOs tipados, inspector, privacidad, presupuesto y errores.
- **Contratos:** endpoints `/orders/admin` y `/orders/{id}/admin`, capacidad,
  nonce y no-store.
- **Pruebas:** queries, fallos DB, PII, 401/403/404/422/500, N+1 y DTO.
- **Exclusiones:** mutaciones.
- **Dependencias:** 37.1.
- **Cierre:** presupuestos medidos y read model certificado.

### 37.3 Listado operacional

- **Objetivo:** listado navegable, filtrable y paginado.
- **Alcance:** parser canónico, búsqueda, filtros, orden, estados y enlaces.
- **Contratos:** una request inicial, retorno tipado y render seguro.
- **Pruebas:** desktop/375 px, XSS, accesibilidad, consola y regresiones.
- **Exclusiones:** acciones mutables.
- **Dependencias:** 37.2 y navegación de Store/Product/Inventory.
- **Cierre:** harness estructural y navegador verde.

### 37.4 Detalle operacional

- **Objetivo:** representar todas las dimensiones sin aplanar autoridades.
- **Alcance:** líneas, reservas, pago, delivery, completions e inspector.
- **Contratos:** una request agregada, mensajes seguros y navegación tipada.
- **Pruebas:** datos faltantes, cardinalidades, inconsistencias, PII, XSS,
  accesibilidad y respuestas tardías.
- **Exclusiones:** acciones financieras o destructivas.
- **Dependencias:** 37.2–37.3.
- **Cierre:** detalle read-only certificado.

### 37.5 Timeline operacional

- **Objetivo:** cronología determinista desde evidencia durable.
- **Alcance:** eventos derivados, fuentes, timestamps y precedencia.
- **Contratos:** cada evento identifica fuente y no inventa causalidad.
- **Pruebas:** empates, timestamps nulos, reintentos y eventos desconocidos.
- **Exclusiones:** nueva event store salvo evidencia de necesidad.
- **Dependencias:** 37.4.
- **Cierre:** timeline reproducible sin queries por evento.

### 37.6 Acciones seguras

- **Objetivo:** exponer solo comandos existentes o implementar explícitamente
  los nuevos que hayan sido aprobados.
- **Alcance inicial sugerido:** comandos de recovery acotados; evaluar entrega
  atómica. Cancel/refund requieren diseño independiente.
- **Contratos:** CAS Order, lease ownership, idempotency key, auditoría, 409 y
  relectura posterior.
- **Pruebas:** concurrencia real, doble click, retry, lease perdido y
  compensaciones.
- **Exclusiones:** PATCH genérico, hard delete, edición de snapshots.
- **Dependencias:** 37.1–37.5.
- **Cierre:** cada comando tiene autoridad, precondición e idempotencia
  demostradas.

### 37.7 Certificación integral

- **Objetivo:** certificar backend, listado, detalle, timeline, acciones e
  integraciones.
- **Alcance:** seguridad, privacidad, queries, responsive y regresiones.
- **Pruebas:** Product/Store/Inventory Admin, Customer Panel, Cart, Checkout,
  Reservations, Payments, Webpay, Delivery y Fulfillment.
- **Exclusiones:** mejoras posteriores e índices sin evidencia.
- **Dependencias:** microhitos aprobados anteriores.
- **Cierre:** documento final, diff exacto y publicación selectiva separada.

## 15. Cierre de la auditoría

El diseño posterior debe partir de las autoridades distribuidas existentes, no
convertir `orders.status` en una ficción monolítica. La primera entrega segura
es read-only. Cualquier acción administrativa requiere primero lifecycle,
versión, idempotencia y auditoría explícitos.

Este Hito 37.0 creó únicamente este documento. No modifica código productivo,
endpoints, esquemas, migraciones, pruebas ni documentos previos.
