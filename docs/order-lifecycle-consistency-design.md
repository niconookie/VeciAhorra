# Hito 37.1 — Contrato de lifecycle y consistencia operacional de Orders

## 1. Propósito y estado del documento

Este documento diseña el contrato que una futura lectura administrativa
read-only debe usar para interpretar una Order. Parte de la auditoría
`docs/order-admin-audit.md` y vuelve a contrastar sus decisiones críticas con
el código disponible en
`a720f4caa8c92e3337ce5e1c4f3af2b40ab8ee23`.

Este hito no crea enums, clases, tablas, migraciones, rutas, acciones ni UI. Los
nombres y algoritmos siguientes son contratos propuestos para 37.2; no describen
una implementación existente salvo cuando se indica expresamente.

### Principio central

El estado administrativo visible de una Order **no se resuelve únicamente con
`orders.status`**. Se compone de autoridades independientes:

```text
Order + Checkout + Reservations
      + PaymentSession + WebpayReturn + Reconciliation + Payment
      + BusinessCompletion
      + fulfillment_method + Delivery + DeliveryCompletion
      + FulfillmentCompletion
      + hallazgos de consistencia
```

La resolución:

- es read-only y sin efectos secundarios;
- conserva cada autoridad observada;
- produce dimensiones cerradas y un resumen derivado;
- no reescribe historia;
- no convierte una proyección en autoridad;
- no oculta datos faltantes ni combinaciones imposibles.

## 2. Vocabulario contractual

| Término | Significado |
| --- | --- |
| Autoridad primaria | Fila/campo cuyo módulo propietario puede decidir |
| Snapshot histórico | Dato congelado al comprar que no se recalcula |
| Evidencia auxiliar | Registro durable que prueba o contextualiza un hecho |
| Estado técnico | Progreso de una operación, lease o retry; no estado comercial |
| Proyección derivada | Resultado reproducible desde autoridades, no persistido |
| Hallazgo | Invariante evaluada con código y severidad estables |
| Bloqueo | Hallazgo que impide afirmar un estado o ejecutar una acción |
| Historial tolerado | Forma antigua coherente que merece warning, no corrupción |
| Desconocido | Valor no reconocido o evidencia insuficiente |
| Inconsistente | Autoridades presentes que se contradicen |

Una ausencia puede ser válida, desconocida o inconsistente según la fase. Por
ejemplo, Delivery ausente es válida para pickup, esperable antes de
DeliveryCompletion y contradictoria si fulfillment delivery aparece completed.

## 3. Autoridades durables

### 3.1 Matriz principal

| Dimensión | Tabla/entidad y campos | Escritor autorizado actual | Estados observados | Tipo | Terminalidad | Idempotencia/concurrencia | Dependencias |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Order | `orders.id,status,total,minimarket_id,customer_id,reservation_expires_at,created_at,updated_at` | `OrderService`, `BusinessCompletionProcessor`, `DeliveryService` vía `OrderRepository` | `reserved`, `paid`, `delivered` | Autoridad limitada/snapshot | `delivered` se trata como señal histórica, no prueba terminal suficiente | `reserved→paid` condicional y con locks; entrega sin CAS propio | Checkout, Reservations, Delivery |
| Líneas | `order_items.order_id,product_id,inventory_id,quantity,unit_price,subtotal` | `OrderService` | No lifecycle | Snapshot histórico | Inmutable por contrato futuro | Inserción; sin versión | Order, referencias actuales opcionales |
| Checkout | `checkouts.public_id,owner_type,user_id,session_id,status,fulfillment_method,total_amount,currency,expires_at,updated_at` | `CheckoutService`, `PaymentSessionService` | `pending`, `payment_started`, `expired`, `cancelled` | Autoridad contractual agrupadora | `expired`,`cancelled` terminales para ese Checkout | Transacción, idempotency owner/key, update condicional | CheckoutOrders, PaymentSession |
| Order↔Checkout | `checkout_orders.checkout_id,order_id` | `CheckoutService` | Relación | Autoridad relacional | Inmutable | Unique por Order y par | Checkout, Order |
| Reservations | `reservations.order_id,inventory_id,product_id,minimarket_id,quantity,status,reserved_at,expires_at,released_at,updated_at` | `ReservationService`, `ReservationExpirationService`, confirmación de pago | `active`,`released`,`expired`,`consumed` | Autoridad de bloqueo/consumo de stock | `released`,`expired`,`consumed` terminales por reserva | Updates condicionales; locks ordenados; compensación `restoreActive()` | OrderItems, Inventory |
| PaymentSession | `payment_sessions.checkout_id,payment_id,status,create_version,lease*,confirmation_fingerprint*,confirmed_at,expires_at,updated_at` | `PaymentSessionService`, confirmación/recovery | `pending`,`create_processing`,`create_retryable`,`create_ambiguous`,`create_failed`,`ready`,`confirmed`,`expired`,`cancelled` | Estado técnico y evidencia | `confirmed`,`expired`,`cancelled`,`create_failed`; ambiguous requiere revisión | Idempotency key, request fingerprint, create lease/version | Checkout, provider |
| Payment | `payments.checkout_id,payment_session_id,reconciliation_id,status,amount,currency,paid_at,updated_at` | Payment services/BusinessCompletion | `pending`,`paid` | Materialización comercial financiera | `paid` terminal en contrato actual | Unique por session/reconciliation/idempotency | Checkout, PaymentOrders |
| Payment↔Order | `payment_orders.payment_id,order_id` | BusinessCompletion/PaymentRepository | Relación | Autoridad relacional | Inmutable | Unique por Order y par | Payment, Order |
| WebpayReturn | `webpay_returns.processing_status,result_status,financial_status,fingerprint*,response_code,amount_clp,currency,financial_*_at,updated_at` | `WebpayReturnService`, materializer/recovery | processing y resultado técnico; `financial_status` aprobado/rechazado cuando validado | Evidencia financiera auxiliar | Depende de estado validado; retryable no terminal | Token/fingerprint únicos | OriginContext, Reconciliation |
| Reconciliation | `payment_reconciliations.reconciliation_status,business_result_code,attempt_count,lease*,last_error_code,reconciled_at,updated_at` | Reconciliation processor/claim repository | `pending`,`processing`,`completed`,`retryable`,`permanent_failure`,`manual_review` | Estado técnico financiero | `completed`,`permanent_failure`,`manual_review` | Lease owner/version, fingerprints, backoff | WebpayReturn, origin, handler |
| Business processing | `business_completions.status,payment_id,attempt_count,lease*,last_result_code,completed_at,updated_at`; `business_completion_orders` | `BusinessCompletionProcessor` | `pending`,`processing`,`completed`,`retryable`,`permanent_failure`,`manual_review` | Estado técnico postpago + snapshot de Orders | `completed`,`permanent_failure`,`manual_review` | Idempotency key, lease/version, unique Order | Reconciliation, Payment, Reservations, Orders |
| Delivery | `deliveries.order_id,customer_id,minimarket_id,courier_id,status,updated_at` | `DeliveryCompletionProcessor`, `DeliveryService` | `pending`,`assigned`,`picked_up`,`delivered`,`cancelled` | Autoridad de entrega física | `delivered`,`cancelled` | Unique por Order depende de migración; transición validada, sin CAS Order | Order, Store, courier |
| Delivery events | `delivery_tracking.delivery_id,event,created_at` | `DeliveryTrackingService` | Evento string | Evidencia auxiliar | Evento inmutable | Orden `created_at,id` | Delivery |
| Delivery processing | `delivery_completions.completion_status,attempt_count,lease*,last_result_code,completed_at,updated_at` | `DeliveryCompletionProcessor` | `pending`,`processing`,`completed`,`not_required`,`retryable`,`permanent_failure`,`manual_review` | Estado técnico de materialización | `completed`,`not_required`,`permanent_failure`,`manual_review` | Idempotency key, lease/version | BusinessCompletion, fulfillment method |
| Fulfillment | `fulfillment_completions.completion_status,attempt_count,lease*,last_result_code,completed_at,updated_at` | `FulfillmentCompletionProcessor` | `pending`,`processing`,`completed`,`retryable`,`permanent_failure`,`manual_review` | Estado técnico de cierre de materialización | `completed`,`permanent_failure`,`manual_review` | Idempotency key, lease/version | Business, DeliveryCompletion, Deliveries |

### 3.2 Jerarquía de autoridad

1. Los snapshots de Order/OrderItems mandan sobre la compra histórica.
2. Checkout manda sobre agrupación, ownership, método y cancelación/expiración.
3. Reservations manda sobre stock reservado/consumido/restaurado.
4. evidencia financiera validada y Reconciliation mandan sobre aprobación o
   rechazo financiero;
5. Payment/BusinessCompletion prueban materialización comercial postpago;
6. Delivery manda sobre transporte físico;
7. completions mandan sobre progreso técnico de sus ramas;
8. Customer Panel y el futuro resolver administrativo son proyecciones.

`orders.status` no puede anular una autoridad más específica. Un Order `paid`
sin Payment ni explicación histórica no crea por sí mismo evidencia financiera.

## 4. Limitaciones contractuales de `orders.status`

### 4.1 Hechos actuales

| Valor escrito | Escritor real | Transición |
| --- | --- | --- |
| `reserved` | `OrderService::create*()` | creación |
| `paid` | confirmación/BusinessCompletion → `OrderRepository::markPaid()` | solo desde `reserved` en el camino endurecido |
| `delivered` | `DeliveryService::updateStatus()` → `markDelivered()` | por ID, sin expected state |

No hay enum de Order. El esquema admite strings arbitrarios. No existen estados
Order `expired`, `cancelled`, `preparing`, `dispatched`, `pickup_completed`,
`refunded` ni `voided`.

### 4.2 Semántica segura futura

`orders.status` se usa como:

- snapshot limitado de un hito histórico;
- compatibilidad con lectores actuales;
- señal secundaria para invariantes;
- evidencia de que un escritor intentó materializar un cambio.

Nunca se usa como:

- autoridad única del lifecycle;
- prueba financiera aislada;
- prueba de entrega aislada;
- señal de que una reserva sigue activa;
- permiso para una acción administrativa;
- sustituto de Checkout, Delivery o completions.

No se reescriben estados históricos en la Serie 37. Las formas históricas se
clasifican con evidence y warnings.

### 4.3 Asimetrías que el resolver debe aceptar

- Checkout expired/cancelled con Order todavía `reserved`;
- Reservations terminales con Order todavía `reserved`;
- pickup correctamente completado con Order todavía `paid`;
- Delivery `delivered` y Order `delivered` escritos en operaciones separadas;
- Order antigua `paid` sin toda la infraestructura durable moderna, si existe
  evidencia histórica explícita y coherente.

Aceptar una asimetría conocida no significa ocultarla: puede producir un
hallazgo `warning` de compatibilidad.

## 5. Dimensiones canónicas

Todas las dimensiones tienen valores cerrados. Un valor fuente desconocido
nunca se normaliza al default.

### 5.1 Estado comercial `commercial_state`

| Valor | Significado | Terminal |
| --- | --- | --- |
| `reserved` | Order creada y reservas agregadas activas; no hay inicio financiero concluyente | No |
| `payment_pending` | Checkout reutilizable con intento pendiente/en curso | No |
| `confirmed` | pago y business processing completados; compra confirmada | No |
| `expired` | Checkout o reservas expiraron sin aprobación financiera | Sí para ese Checkout |
| `cancelled` | Checkout cancelado sin aprobación financiera | Sí para ese Checkout |
| `fulfilled` | fulfillment válido completado según pickup/delivery | Sí operacional |
| `unknown` | evidencia insuficiente o fuente no reconocida | No afirmable |
| `inconsistent` | autoridades comerciales se contradicen | No afirmable |

Precedencia:

1. contradicción bloqueante → `inconsistent`;
2. fulfillment válido → `fulfilled`;
3. business completado y pago aprobado → `confirmed`;
4. cancelado sin evidencia aprobada → `cancelled`;
5. expirado sin evidencia aprobada → `expired`;
6. intento financiero en curso → `payment_pending`;
7. reservas activas coherentes → `reserved`;
8. otro → `unknown`.

Un pago rechazado no convierte el Checkout en cancelado: si sigue reutilizable,
`commercial_state=payment_pending` y `financial_state=rejected`.

### 5.2 Estado financiero `financial_state`

| Valor | Regla |
| --- | --- |
| `not_started` | no hay PaymentSession, retorno, reconciliation ni Payment |
| `pending` | sesión/retorno/reconciliation no terminal sin aprobación validada |
| `approved` | evidencia financiera validada aprobada y relaciones coherentes |
| `rejected` | evidencia financiera validada rechazada; Checkout no se inventa cancelado |
| `failed` | creación o reconciliación terminó en fallo permanente |
| `manual_review` | evidencia ambigua/inconsistente o reconciliation manual_review |
| `unknown` | estado fuente desconocido o evidencia insuficiente |
| `inconsistent` | autoridades financieras incompatibles, montos o relaciones contradictorios |

Precedencia: `inconsistent` > `manual_review` > `approved` > `rejected` >
`failed` > `pending` > `not_started` > `unknown`, con una excepción: un valor
fuente desconocido relevante fuerza `unknown` o `inconsistent`, nunca se ignora.

`approved` requiere como mínimo resultado financiero validado y reconciliation
coherente; Payment `paid` es corroboración/materialización, no sustituto
silencioso.

### 5.3 Estado de reservas `reservation_state`

| Valor | Regla agregada |
| --- | --- |
| `active` | todas las reservas esperadas existen, coinciden con líneas y están active |
| `consumed` | todas las esperadas están consumed |
| `expired` | todas las esperadas están expired |
| `released` | todas las esperadas están released |
| `mixed` | combinación temporal permitida o terminal heterogénea que requiere inspección |
| `missing` | faltan reservas esperadas o no existe ninguna para una Order que debía tenerlas |
| `unknown` | estado no reconocido o lectura incompleta |
| `inconsistent` | cantidades/identidades/cardinalidad contradicen OrderItems o estado financiero |

El agregado se calcula por clave canónica
`(order_id,inventory_id,product_id,quantity)` y no por orden de filas. IDs
duplicados o varias reservas que suman una línea solo se aceptan si el contrato
histórico lo demuestra; de lo contrario generan hallazgo.

Precedencia: `inconsistent` > `unknown` > `missing` > `mixed` > estado uniforme.

### 5.4 Estado de procesamiento postpago `processing_state`

Agrupa Reconciliation y BusinessCompletion, sin perder sus estados crudos.

| Valor | Significado |
| --- | --- |
| `not_required` | no existe aprobación financiera que requiera postproceso |
| `pending` | trabajo durable creado/esperado, aún no reclamado |
| `processing` | lease vigente y estado processing |
| `retry_wait` | retryable o lease processing vencido recuperable |
| `completed` | reconciliation y business completion requeridos terminaron coherentemente |
| `failed` | permanent_failure |
| `manual_review` | manual_review o evidencia ambigua |
| `unknown` | estado/fuente no reconocida o lectura insuficiente |
| `inconsistent` | completed contradice Payment, Orders, Reservations o snapshots |

Un lease vencido no es `failed`: es `retry_wait` y puede añadir
`processing_lease_expired`. `attempt_count` alto tampoco es `exhausted` salvo que
una autoridad haya persistido fallo terminal; el contrato actual no posee un
estado `exhausted` separado.

### 5.5 Estado de fulfillment `fulfillment_state`

| Valor | Significado |
| --- | --- |
| `not_started` | compra no confirmada o no existe completion esperable |
| `pending` | business completado; rama durable pendiente/retryable |
| `in_progress` | Delivery creada y no terminal, o completion processing |
| `completed` | FulfillmentCompletion completed y evidencia por método válida |
| `failed` | permanent_failure |
| `manual_review` | manual_review |
| `unknown` | método/estado no reconocido o evidencia insuficiente |
| `inconsistent` | completion contradice método, Delivery o conjunto de Orders |

`retryable` se resuelve a `pending` con warning/retry attention, no a `failed`.

### 5.6 Estado de entrega `delivery_state`

| Valor | Aplicación |
| --- | --- |
| `not_applicable` | `fulfillment_method=pickup`; no debe existir Delivery |
| `not_started` | método delivery confirmado, antes de materialización |
| `pending` | Delivery pending |
| `assigned` | courier asignado y Delivery assigned |
| `picked_up` | Delivery picked_up |
| `delivered` | Delivery delivered y relaciones coherentes |
| `cancelled` | Delivery cancelled |
| `unknown` | método o estado no reconocido |
| `inconsistent` | cardinalidad, Store/Order/cliente o método contradictorio |

Para pickup, Delivery ausente es correcto. Mostrar “entrega faltante” sería un
error. Pickup completado se prueba con DeliveryCompletion `not_required` y
FulfillmentCompletion `completed`, no con `orders.status=delivered`.

### 5.7 Estado técnico de sesión `payment_session_state`

El detalle conserva el valor fuente cerrado:

`absent | pending | create_processing | create_retryable | create_ambiguous |
create_failed | ready | confirmed | expired | cancelled | unknown`.

No se usa directamente como badge primario; alimenta `financial_state`,
timeline y hallazgos.

## 6. Estado operacional primario

### 6.1 Catálogo cerrado `primary_state`

| Estado | Uso |
| --- | --- |
| `inconsistent` | uno o más blockers críticos/errores hacen no confiable la operación |
| `manual_review` | una autoridad durable exige revisión humana |
| `failed` | procesamiento terminal falló sin contradicción de datos |
| `completed` | fulfillment válido completado |
| `in_fulfillment` | Delivery/fulfillment avanza físicamente |
| `fulfillment_pending` | compra confirmada esperando materialización/fulfillment |
| `post_payment_processing` | aprobación existe, reconciliation/business aún no completan |
| `confirmed` | compra pagada/materializada, método desconocido o legado coherente |
| `payment_rejected` | intento rechazado; Checkout puede seguir reutilizable |
| `payment_in_progress` | sesión/reconciliation financiera en progreso sin aprobación final |
| `cancelled` | Checkout cancelado sin aprobación |
| `expired` | Checkout/reservas expiraron sin aprobación |
| `reserved` | reserva activa, pago no iniciado |
| `unknown` | evidencia insuficiente para cualquier estado anterior |

Terminales operacionales: `completed`, `cancelled` y `expired`.
`payment_rejected` es terminal para el intento, no necesariamente para Checkout.
`failed` y `manual_review` requieren atención y no se consideran cierre
comercial exitoso.

### 6.2 Precedencia exacta

1. lectura fallida o evidencia mínima imposible → `unknown`, más hallazgo;
2. blocker crítico de consistencia → `inconsistent`;
3. reconciliation/completion `manual_review` o ambigüedad financiera →
   `manual_review`;
4. permanent failure de processing/fulfillment → `failed`;
5. `fulfillment_state=completed` con invariantes satisfechas → `completed`;
6. delivery `assigned|picked_up` o fulfillment processing →
   `in_fulfillment`;
7. commercial confirmed y fulfillment pending/not_started conocido →
   `fulfillment_pending`;
8. financiero approved con processing no completado →
   `post_payment_processing`;
9. business completed/pago materializado con método histórico desconocido pero
   coherente → `confirmed`;
10. financiero rejected, sin aprobación/Payment paid → `payment_rejected`;
11. financiero pending → `payment_in_progress`;
12. Checkout cancelled, sin evidencia financiera approved → `cancelled`;
13. Checkout expired o reservas terminales por expiración, sin approved →
   `expired`;
14. reservations active coherentes → `reserved`;
15. otro → `unknown`.

Esta precedencia garantiza:

- fallo postpago prevalece sobre “paid”;
- Delivery/Fulfillment validan “completed”, no Order aislada;
- rechazo no se transforma en cancelación;
- Reservations restauradas/expiradas no se muestran active;
- pickup completado se reconoce aunque Order permanezca `paid`.

## 7. Algoritmo conceptual de resolución

### Paso 0 — Congelar el conjunto de entrada

El repositorio entrega una instantánea lógica:

- una Order;
- sus OrderItems ordenados por ID;
- cero o un Checkout mediante CheckoutOrders;
- Reservations agrupadas;
- intentos financieros ordenados;
- Payment y relaciones;
- Reconciliation/Business/Delivery/Fulfillment;
- Deliveries y tracking.

El servicio no consulta durante el render ni modifica fuentes.

### Paso 1 — Normalizar sin perder valores

- IDs a enteros positivos o hallazgo;
- dinero a enteros CLP/cadena decimal canónica, nunca float;
- timestamps a UTC/cadena SQL válida con zona declarada;
- colecciones ordenadas por `(timestamp,source_priority,id)`;
- enums fuente validados;
- valores crudos desconocidos guardados solo como códigos seguros truncados, no
  payloads.

### Paso 2 — Resolver identidad y cardinalidad

- exactamente una Order;
- líneas no vacías;
- máximo un Checkout, Payment y Delivery por relaciones únicas esperadas;
- conjuntos de Order IDs idénticos entre Checkout, Payment y Business cuando
  corresponda;
- Store/cliente coherentes.

Las contradicciones producen findings antes de resolver estados.

### Paso 3 — Reconciliar snapshots

- suma `quantity × unit_price = subtotal` por línea;
- suma subtotales = Order.total;
- suma Orders del Checkout = Checkout.total_amount;
- cada línea conserva Product/Inventory históricos;
- Store Order coincide con Reservations y relación comercial esperada;
- referencias actuales faltantes son warnings históricos, no borran líneas.

### Paso 4 — Resolver Reservations

Agrupar por Order y clave de línea, sumar cantidades, clasificar estados,
detectar faltantes/duplicados y resolver `reservation_state`.

### Paso 5 — Resolver finanzas

Ordenar intentos por creación e ID; identificar el intento autoritativo por
relaciones durables, no “última fila”. Evaluar sesión, retorno validado,
reconciliation, Payment, moneda y montos. Resolver `financial_state`.

Múltiples intentos son válidos si las unicidades y la selección comercial
permanecen coherentes; no son pagos duplicados por sí solos.

### Paso 6 — Resolver processing

Combinar Reconciliation y BusinessCompletion:

- lease processing vigente → processing;
- lease vencido → retry_wait;
- retryable → retry_wait;
- terminales se conservan;
- completed exige Payment/Orders/Reservations coherentes.

### Paso 7 — Resolver fulfillment y Delivery

Primero validar `fulfillment_method`.

- pickup: Delivery debe ser ausente; DeliveryCompletion `not_required`;
- delivery: DeliveryCompletion `completed` y una Delivery coherente por Order;
- FulfillmentCompletion completed solo vale si la rama anterior coincide.

Resolver `delivery_state` y luego `fulfillment_state`.

### Paso 8 — Evaluar invariantes

Crear findings estables, deduplicados por
`(code,affected_dimension,evidence_key)`. No concatenar excepciones.

### Paso 9 — Clasificar consistencia

Aplicar la tabla de severidad de la sección 9.

### Paso 10 — Resolver commercial y primary

Usar dimensiones y blockers ya calculados. El resumen no evalúa directamente
filas crudas fuera de las reglas anteriores.

### Paso 11 — Resolver acciones potenciales

En primera versión:

```text
allowed_actions = ["view"]
mutable_actions = []
```

Las acciones futuras se clasifican, no se habilitan.

### Paso 12 — Construir timeline y DTO

Timeline reutiliza timestamps ya cargados. DTO devuelve facts usados, dimensiones,
findings, versión operacional y navegación segura.

## 8. Interfaz conceptual del resolver

```text
resolveOrderOperationalState(OrderOperationalFacts facts)
  -> OrderOperationalResolution
```

### 8.1 Entradas mínimas

```text
OrderOperationalFacts
  order
  order_items[]
  checkout?
  checkout_order_links[]
  reservations[]
  payment_attempts[]
    payment_session
    origin_context?
    webpay_return_safe_projection?
    reconciliation?
  payment?
  payment_order_links[]
  business_completion?
  business_order_links[]
  delivery_completion?
  deliveries[]
  delivery_tracking[]
  fulfillment_completion?
  read_failures[]
  historical_profile
```

`historical_profile` es un identificador cerrado de compatibilidad, no un
escape para ignorar inconsistencias.

### 8.2 Salida

```text
OrderOperationalResolution
  policy: "orders-operational-v1"
  primary_state
  dimensions
    commercial
    financial
    reservations
    processing
    fulfillment
    delivery
    payment_session
  consistency
    classification
    findings[]
    blockers[]
    warnings[]
  evidence_summary
  timeline[]
  concurrency
    operational_version
    fingerprint_algorithm
    observed_at
  allowed_actions[]
```

El resolver debe ser puro, determinista y testeable sin WordPress ni DB.

## 9. Clasificación de consistencia

### 9.1 Catálogo cerrado

| Clasificación | Regla |
| --- | --- |
| `consistent` | todas las invariantes aplicables pasan; sin findings relevantes |
| `warning` | solo información histórica/tolerable, sin bloqueo |
| `degraded` | datos insuficientes o fallo parcial; lectura útil pero no autoriza acciones |
| `inconsistent` | contradicción real o invariant crítica/error |
| `unknown` | no fue posible cargar la evidencia mínima para resolver |

Precedencia: `unknown` por fallo total > `inconsistent` > `degraded` >
`warning` > `consistent`. Un fallo parcial normalmente es `degraded`; si impide
identificar la Order, es `unknown`.

### 9.2 Finding

```text
ConsistencyFinding
  code: string enum
  severity: "info" | "warning" | "error" | "critical"
  title: string seguro
  description: string segura
  affected_dimension: enum
  blocker: boolean
  historical_tolerance: boolean
  evidence:
    ids/counts/status_codes/timestamps mínimos
```

Evidence no contiene PII, SQL, stack, rutas, clases, tokens, payload Webpay,
redirect URLs ni mensajes de proveedores.

## 10. Invariantes verificables

| Código | Invariante/evidencia | Severidad | Efecto primario | Bloquea acción | Clasificación |
| --- | --- | --- | --- | --- | --- |
| `order_status_unknown` | `orders.status` fuera de reserved/paid/delivered | error | inconsistent | Sí | Real |
| `paid_without_financial_evidence` | Order paid/delivered sin aprobación validada ni perfil histórico explícito | critical | inconsistent | Sí | Real; warning solo con perfil histórico probado |
| `approved_without_business_processing` | financiero approved sin BusinessCompletion | warning si pendiente reciente; error si ausente/terminal contradictorio | post_payment_processing o inconsistent | Sí para mutar | Temporal/real según evidencia |
| `business_completed_without_paid_order` | Business completed pero Order no paid/delivered | critical | inconsistent | Sí | Real |
| `delivered_without_delivery_evidence` | Order delivered sin Delivery delivered para método delivery | critical | inconsistent | Sí | Real |
| `delivery_completed_order_not_delivered` | Delivery delivered y Order no delivered | error; tolerancia transitoria muy acotada por timestamps | inconsistent/degraded | Sí | Carrera o fallo real |
| `pickup_has_delivery` | pickup con Delivery materializada | error | inconsistent | Sí | Real |
| `pickup_completion_invalid` | pickup sin DeliveryCompletion not_required o Fulfillment coherente | error | inconsistent/pending | Sí | Real/temporal |
| `delivery_integrity_mismatch` | Delivery order/customer/store no coincide o su cardinalidad no es una por Order | critical | inconsistent | Sí | Real |
| `fulfillment_completed_without_branch` | Fulfillment completed sin evidence válida de pickup/delivery | critical | inconsistent | Sí | Real |
| `reservation_items_mismatch` | claves/cantidades no coinciden con líneas | critical | inconsistent | Sí | Real |
| `active_reservation_after_terminal_release` | evidencia de restauración definitiva pero Reservation active | critical | inconsistent | Sí | Real |
| `reservations_active_after_payment` | Payment/business completed y reservas siguen active | critical | inconsistent | Sí | Real |
| `reservations_consumed_without_approval` | consumed sin aprobación/materialización coherente | critical | inconsistent | Sí | Real |
| `reservation_terminal_mixed` | mezcla released/expired/consumed | error o warning histórico documentado | inconsistent/mixed | Sí salvo tolerancia | Contextual |
| `stock_double_terminal_evidence` | misma reserva evidencia consumo y restauración | critical | inconsistent | Sí | Real |
| `order_item_subtotal_mismatch` | quantity×unit_price != subtotal | critical | inconsistent | Sí | Real |
| `order_total_mismatch` | suma líneas != Order.total | critical | inconsistent | Sí | Real |
| `checkout_total_mismatch` | suma Orders != Checkout total/currency | critical | inconsistent | Sí | Real |
| `order_store_mismatch` | Order Store difiere de Reservations/aislamiento de líneas | critical | inconsistent | Sí | Real |
| `checkout_order_relation_missing` | Order operacional sin vínculo Checkout esperado | error o warning histórico | inconsistent/degraded | Sí para mutar | Contextual |
| `checkout_order_owner_mismatch` | cliente/owner incompatible | critical | inconsistent | Sí | Real |
| `operational_order_set_mismatch` | PaymentOrders o snapshot Business no coinciden con CheckoutOrders | critical | inconsistent | Sí | Real |
| `payment_flow_mismatch` | Session/Payment/Reconciliation pertenecen a distinto Checkout/intento | critical | inconsistent | Sí | Real |
| `payment_amount_mismatch` | montos/moneda difieren | critical | inconsistent | Sí | Real |
| `financial_terminal_regression` | evidencia terminal vuelve a estado no terminal | critical | inconsistent | Sí | Real |
| `processing_lease_expired` | processing con lease vencido | warning | conserva retry_wait | Sí para acción ajena al recovery | Recuperable, no fallo |
| `processing_retry_scheduled` | retryable/attempt incrementado | info | no cambia a duplicado | No para lectura | Operación normal |
| `current_catalog_reference_missing` | Product o Inventory actual ausente; los IDs snapshot permanecen | warning | no cambia estado histórico | No | Histórico tolerable |
| `current_store_missing` | Store actual ausente | warning/error según necesidad operacional | degraded | Sí para mutar | Histórico/operacional |
| `read_failure` | una consulta opcional falló (error/degraded) u Order/líneas no pudieron leerse (critical/unknown) | error o critical | degraded o unknown | Sí | Temporal |

### 10.1 Regla de tolerancia histórica

Una tolerancia requiere:

- perfil versionado explícito;
- evidencia mínima coherente;
- no esconder montos, owner o Store contradictorios;
- finding visible `historical_tolerance=true`.

La ausencia actual de Product/Inventory nunca invalida el snapshot por sí sola.

## 11. Timeline derivado

### 11.1 Contrato de evento

```text
TimelineEvent
  key: string determinista
  type: enum
  occurred_at: timestamp
  source: enum
  source_id: int|string seguro
  source_rank: int
  sequence: int
  label: string segura
  tone: info|success|warning|error
  metadata: IDs/conteos/códigos mínimos
```

No se crea un evento si la fuente o timestamp requerido falta. La ausencia
produce finding cuando el hito era obligatorio.

### 11.2 Hitos derivables

| Tipo | Fuente y timestamp | Condición/etiqueta segura |
| --- | --- | --- |
| `checkout_created` | Checkout `created_at` | “Checkout creado” |
| `order_created` | Order `created_at` | “Order creada para Store” |
| `stock_reserved` | Reservations `reserved_at` | agregado por timestamp/Order; cantidades totales |
| `payment_started` | PaymentSession `create_started_at` o `created_at` | “Inicio de pago” sin URL/token |
| `payment_session_ready` | Session `updated_at` cuando estado ready | solo si evidencia permite atribución; si no, omitir |
| `financial_evidence_obtained` | `financial_obtained_at` | estado aprobado/rechazado seguro |
| `financial_evidence_validated` | `financial_validated_at` | “Evidencia financiera validada” |
| `payment_confirmed` | Session `confirmed_at` / Payment `paid_at` | eventos distintos si timestamps difieren |
| `reconciliation_attempted` | Reconciliation `last_attempt_at` | intento, no éxito |
| `reconciliation_completed` | `reconciled_at` con completed | “Reconciliación completada” |
| `business_completed` | Business `completed_at` | “Procesamiento de negocio completado” |
| `delivery_created` | Delivery `created_at` | solo delivery |
| `delivery_event` | Tracking `created_at`, event allowlisted | assigned/picked_up/delivered |
| `delivery_processing_completed` | DeliveryCompletion `completed_at` | “Delivery materializada” o “Pickup: entrega no requerida” |
| `fulfillment_completed` | FulfillmentCompletion `completed_at` | “Fulfillment completado” |
| `reservation_expired` | Reservation `released_at` con status expired | no afirmar más que la fuente |
| `reservation_released` | `released_at` con released | “Reserva liberada” |
| `stock_restored` | no existe evento independiente general | solo derivar si el contrato del escritor y estado terminal lo prueban; si no, omitir |
| `checkout_expired` | Checkout `updated_at` con expired | timestamp aproximado de transición, etiquetado como observado |
| `checkout_cancelled` | Checkout `updated_at` con cancelled | idem |

No hay timestamp dedicado para todas las transiciones Order. `updated_at` puede
servir como “estado Order observado” pero no crear un evento financiero o de
entrega ficticio.

### 11.3 Orden determinista

Orden ascendente por:

1. `occurred_at`;
2. `source_rank` fijo:
   Checkout 10, Order 20, Reservation 30, PaymentSession 40, Webpay 50,
   Reconciliation 60, Payment 70, Business 80, DeliveryCompletion 90,
   Delivery 100, Tracking 110, Fulfillment 120;
3. `source_id` canónico;
4. `type`;
5. `sequence`.

Empates no infieren causalidad. La UI puede decir “mismo momento registrado”,
no “inmediatamente después”.

Una futura event store se difiere. Solo se evaluará si timestamps derivados no
permiten auditoría suficiente.

## 12. Privacidad del contrato

### 12.1 Listado permitido

- Order ID y Checkout public ID;
- Store ID/nombre actual;
- owner type;
- estado primario y dimensiones resumidas;
- total/moneda;
- conteo de líneas/unidades;
- timestamps;
- indicadores y códigos de atención.

No incluir nombre, email, teléfono, dirección, session ID, courier, coordenadas
ni referencias financieras.

### 12.2 Detalle evaluado

| Dato | Justificación | Exposición | Enmascarado | Capacidad futura | Decisión |
| --- | --- | --- | --- | --- | --- |
| `customer_id` | soporte y ownership | detalle | no, ID interno | `manage_veciahorra_orders` | Permitido |
| nombre cliente | identificar caso de soporte | detalle opcional | iniciales/nombre parcial | capacidad PII separada | Excluir v1 por defecto |
| email | contacto | detalle opcional | `a***@dominio` | capacidad PII | Excluir v1 |
| teléfono | coordinación de entrega | solo detalle delivery | últimos 4 | capacidad PII/delivery | No existe en Order; excluir hasta contrato |
| dirección | fulfillment | detalle delivery | minimizada | capacidad PII/delivery | No existe en tablas auditadas de Order; no inventar |
| courier ID | operación Delivery | detalle | ID | capacidad Delivery | Enlace, no PII ampliada |
| coordenadas | diagnóstico logístico | detalle excepcional | precisión reducida | capacidad sensible | Excluir v1 |
| provider reference segura | conciliación | detalle | parcial/hash seguro | capacidad financiera | Solo `safe_financial_reference` |
| payment attempt public ID | correlación | detalle | no | capacidad financiera | Permitido si no es token |

### 12.3 Prohibiciones

Nunca devolver:

- tokens o token hashes;
- credenciales;
- redirect URL;
- idempotency keys completas;
- fingerprints completos salvo hash operacional no reversible creado para CAS;
- payloads/result JSON de Webpay;
- buy order/session financiera sin proyección aprobada;
- authorization codes;
- metadata cruda;
- SQL, stack, rutas, clases o mensajes internos/proveedor.

## 13. Versionado y concurrencia futura

### 13.1 Por qué `orders.updated_at` no basta

Una acción queda obsoleta si cambia Checkout, Reservation, PaymentSession,
Reconciliation, BusinessCompletion, Delivery o Fulfillment aunque Order no
cambie. Usar solo `expected_updated_at` permitiría TOCTOU entre autoridades.

### 13.2 `operational_version`

Propuesta de lectura:

```text
operational_version =
  "orders-operational-v1:" + base64url(
    SHA-256(canonical_json(version_facts))
  )
```

`version_facts` incluye únicamente:

- policy version;
- Order `id,status,updated_at,total,minimarket_id`;
- Checkout `id,status,fulfillment_method,updated_at,total_amount`;
- lista ordenada de líneas: `id,updated_at,quantity,unit_price,subtotal`;
- Reservations: `id,status,updated_at,quantity`;
- intento autoritativo: IDs públicos seguros, status y updated_at;
- Payment: `id,status,updated_at,amount`;
- Reconciliation: `id,status,lease_version,updated_at`;
- Business/Delivery/Fulfillment completion:
  `id,status,lease_version,updated_at`;
- Deliveries: `id,status,updated_at,courier_id`;
- conjuntos relacionales ordenados;
- clasificación/hallazgo blockers por code.

No incluye PII, tokens, payloads ni mensajes.

Canonicalización:

- claves JSON ordenadas;
- arrays ordenados por ID;
- enteros decimales;
- dinero como string decimal canónica;
- null explícito;
- timestamps UTC normalizados;
- UTF-8 NFC;
- sin whitespace.

### 13.3 Futuro contrato de acción

Un comando futuro enviaría:

```text
expected_operational_version
expected_primary_state
idempotency_key específica del comando
```

El servidor:

1. abre transacción;
2. bloquea autoridades en orden contractual;
3. relee facts;
4. recalcula fingerprint;
5. responde 409 si difiere;
6. valida blockers y precondición del dominio;
7. ejecuta comando idempotente;
8. confirma;
9. obliga a releer DTO.

Orden preliminar de locks: Checkout → Orders por ID → OrderItems/links →
Reservations por ID → PaymentSession/Payment/Reconciliation →
BusinessCompletion → DeliveryCompletion → Deliveries →
FulfillmentCompletion. Cada comando debe reducirlo a sus autoridades y respetar
el orden usado por los flujos existentes.

No se implementa CAS ni se modifica ninguna tabla en 37.1.

## 14. Clasificación de acciones futuras

Categorías:

- `read_only`;
- `potentially_safe`;
- `unsafe_without_new_domain_contract`;
- `unsupported`.

| Acción | Categoría | Estado/precondición | Autoridad/idempotencia | Fingerprint y blockers | Primera versión / deuda |
| --- | --- | --- | --- | --- | --- |
| Consultar | `read_only` | Order existente | read model puro | no requiere expected version | Sí |
| Reintentar reconciliation | `potentially_safe` | pending/retryable o lease vencido; no terminal | scheduler/claim lease y fingerprint financiero | operational version + autoridad reconciliation; bloquea inconsistencia financiera | No v1; certificar comando |
| Reintentar business processing | `potentially_safe` | reconciliation completed, completion retryable | BusinessCompletion idempotency/lease | fingerprint completo; bloquea sets/montos | No v1 |
| Reintentar fulfillment/delivery completion | `potentially_safe` | upstream completed y completion retryable | completion lease/version | fingerprint de rama | No v1 |
| Cancelar | `unsafe_without_new_domain_contract` | no definido | afectaría Checkout, Reservations, pago | requiere política financiera/stock y CAS | No existe |
| Marcar preparación | `unsupported` | estado no modelado | no hay autoridad | requeriría dominio nuevo | No existe |
| Marcar despacho | `unsafe_without_new_domain_contract` como Order; Delivery tiene transición propia | Delivery assigned | DeliveryService | CAS/atomicidad y capability Delivery | Enlazar, no duplicar |
| Confirmar entrega | `unsafe_without_new_domain_contract` | Delivery picked_up | DeliveryService escribe Delivery+Order separado | fingerprint y transacción atómica faltantes | No v1 |
| Restaurar stock | `unsafe_without_new_domain_contract` | Reservation activa/expirada según comando | Reservation/Inventory | riesgo doble restore; idempotencia obligatoria | Solo recovery interno actual |
| Refund | `unsupported` | no hay dominio | proveedor/Payment/Orders/stock | contrato financiero completo | No existe |
| Void | `unsupported` | no hay dominio | proveedor | contrato financiero completo | No existe |

`allowed_actions` de 37.2–37.5 es:

```json
{
  "view": true,
  "mutable": []
}
```

La tabla no constituye un diseño de endpoints ejecutables.

## 15. Contrato lógico del read model

### 15.1 Tipos comunes

- IDs internos: integer positivo;
- IDs públicos: string con patrón del dominio;
- enums: string cerrado;
- dinero: `{amount: string decimal, currency: "CLP"}`;
- timestamp: string ISO-8601 UTC o null;
- boolean: verdadero/falso, nunca `0/1`;
- colecciones: arrays, nunca maps con IDs sensibles como claves;
- ausencia válida: null;
- desconocido: enum `unknown`, no null ambiguo.

### 15.2 DTO de listado

```text
OrderAdminListItem
  identity
    order_id: int
    checkout_public_id: string|null
  store
    id: int
    name: string|null
    exists: bool
  totals
    order: Money
    line_count: int
    unit_count: int
  primary_state: PrimaryState
  dimensions
    commercial: enum
    financial: enum
    reservations: enum
    processing: enum
    fulfillment: enum
    delivery: enum
  consistency
    classification: enum
    blocker_count: int
    warning_count: int
    top_codes: string[]
  fulfillment_method: "pickup"|"delivery"|"unknown"
  timestamps
    created_at: timestamp
    updated_at: timestamp
    attention_at: timestamp|null
  attention_required: bool
  allowed_actions
    view: true
    mutable: []
  navigation
    detail: internal URL
    store: internal URL|null
```

No Product/Inventory por línea en listado; sus conteos/alertas vienen batch.

### 15.3 DTO de detalle

```text
OrderAdminDetail
  identity
    order_id
    persisted_status
    created_at
    updated_at
  snapshots
    store_id
    customer_id
    reservation_expires_at
  lines[]
    order_item_id
    product_id
    inventory_id
    quantity
    unit_price
    subtotal
    current_references {product_exists, inventory_exists}
  totals
    lines_total
    order_total
    checkout_total
    currency
    coherent
  authorities
    checkout: SafeCheckoutProjection|null
    reservations: ReservationAggregate
    payment_session: SafeSessionProjection|null
    financial: SafeFinancialProjection
    reconciliation: SafeProcessingProjection|null
    payment: SafePaymentProjection|null
    business: SafeProcessingProjection|null
    delivery_processing: SafeProcessingProjection|null
    deliveries: SafeDeliveryProjection[]
    fulfillment: SafeProcessingProjection|null
  resolution
    policy
    primary_state
    dimensions
  consistency
    classification
    findings[]
    blockers[]
    warnings[]
  timeline[]
  privacy
    pii_included: false
    redacted_fields: string[]
  concurrency
    operational_version
    algorithm: "sha256-canonical-json-v1"
    observed_at
  allowed_actions
    view: true
    mutable: []
  navigation
    list
    checkout|null
    store|null
    products_by_line[]
    inventories_by_line[]
    delivery|null
```

### 15.4 Safe processing projection

Expone `status`, `attempt_count`, `lease_state` (`none|active|expired`),
`last_result_code` allowlisted y timestamps. Nunca expone lease owner,
idempotency key ni errores crudos.

## 16. Consultas y rendimiento

Se conserva el presupuesto de 37.0:

| Lectura futura | Consultas máximas |
| --- | ---: |
| Listado vacío | 2 |
| Listado no vacío | 4 |
| Detalle completo | 8 |
| Timeline | 0 adicionales |
| Inspector | 0–1, incluido en el máximo |

### 16.1 Listado

1. página: Order + CheckoutOrders + Checkout + Store, columnas explícitas;
2. agregado batch de líneas y Reservations para IDs de página;
3. agregado batch financiero/processing/Delivery/completions y relaciones;
4. count con filtros equivalentes.

Si página vacía: página + count; omitir agregados.

No llamar resolver con consultas internas. Resolver recibe facts batch.

### 16.2 Detalle

1. Order + Checkout + Store;
2. OrderItems y referencias actuales decorativas;
3. Reservations;
4. PaymentSession + origin + retorno financiero seguro + Reconciliation;
5. Payment + PaymentOrders + Business + snapshot Orders;
6. DeliveryCompletion + Deliveries;
7. DeliveryTracking;
8. FulfillmentCompletion e inspector adicional si no se derivó.

Timeline usa esas filas: cero queries. Inspector reutiliza conjuntos y solo usa
la consulta 8 cuando una cardinalidad no está cubierta.

Tablas opcionales usan LEFT JOIN o consultas batch; ausencia no dispara una
consulta por entidad.

### 16.3 Paginación

- filtros allowlisted;
- keyset futuro opcional, página/count inicialmente;
- orden allowlisted;
- desempate obligatorio `o.id`;
- máximo 100;
- ninguna agregación después de paginar si afecta filtros/count;
- query count reproduce EXISTS/predicados.

El presupuesto no se ajusta en este diseño. 37.2 debe medirlo con DB real y
justificar cualquier desviación antes de cambiar el contrato.

## 17. Compatibilidad obligatoria

El resolver:

- conserva precio/cantidad/subtotal congelados;
- conserva Product/Inventory/Store históricos;
- mantiene `inventory_id` aunque la oferta actual no exista;
- no mezcla Stores ni cambia una Order por minimarket;
- no recalcula desde catálogo;
- no consume ni restaura stock;
- no inicia/reintenta pagos;
- no adquiere leases;
- no altera Customer Panel;
- no altera Cart, Checkout, catálogo, Delivery ni Fulfillment;
- no reescribe `orders.status`;
- no añade transiciones al leer;
- no convierte Admin en autoridad financiera.

Las proyecciones actuales del Customer Panel continúan con su contrato. El
resolver administrativo puede compartir políticas puras futuras, no DTOs ni
queries con ownership distinto de forma accidental.

## 18. Decisiones, alternativas y tradeoffs

### 18.1 Estado derivado frente a ampliar `orders.status`

**Decisión:** dimensiones y primary state derivados.

Descartado: añadir todos los conceptos a `orders.status`. Duplicaría
autoridades, exigiría sincronización distribuida y reescritura histórica.

Tradeoff: lecturas más complejas, compensadas con resolver puro y facts batch.

### 18.2 Resolver en lectura frente a persistir estado compuesto

**Decisión:** resolver en lectura en Serie 37.

Descartado: columna compuesta persistida. Puede quedar obsoleta si cambia una
autoridad externa.

Deuda: si escala, evaluar proyección materializada con rebuild/versionado, nunca
autoridad.

### 18.3 Fingerprint compuesto frente a `updated_at`

**Decisión:** fingerprint canónico de todas las autoridades relevantes.

Descartado: solo Order.updated_at, porque no detecta cambios en Checkout,
Reservations, Payment o Delivery.

Tradeoff: token cambia con más frecuencia y obliga relectura, que es la
protección buscada.

### 18.4 Timeline derivado frente a event store

**Decisión:** timeline derivado, sin tabla nueva.

Descartado por ahora: event store retroactivo incompleto e infraestructura no
justificada.

Deuda: algunos cambios solo tienen `updated_at`; se etiquetan “observados”, no
eventos exactos.

### 18.5 Read-only frente a acciones tempranas

**Decisión:** 37.2–37.5 read-only.

Descartado: exponer REST técnico, PATCH de status o workers como botones.

Tradeoff: menor capacidad operativa inicial, riesgo financiero/stock
materialmente menor.

### 18.6 Estado `exhausted`

**Decisión:** no inventarlo. El código persiste `permanent_failure` y
`manual_review`; attempt count no demuestra agotamiento.

### 18.7 Identidad actual de Product/Store

**Decisión:** decorativa y nullable. Snapshot numérico/histórico permanece
visible aunque la entidad actual falte.

## 19. Impacto sobre microhitos posteriores

### 37.2 — Read model administrativo

Debe:

- implementar `orders-operational-v1` como resolver puro;
- ser read-only;
- producir facts batch, dimensiones y findings seguros;
- medir 2/4/8 queries;
- probar todos los enums, precedencia e invariantes;
- rechazar fallos DB sin convertirlos en ausencia;
- aplicar privacidad y no-store.

No debe exponer acciones mutables.

### 37.3 — Listado operacional

Debe:

- consumir solo el endpoint/read model administrativo;
- realizar una request inicial;
- filtrar por primary/dimensiones/consistencia;
- no consultar por fila;
- mostrar atención sin ocultar dimensiones;
- mantener `mutable=[]`.

### 37.4 — Detalle operacional

Debe:

- mostrar authorities crudas seguras, snapshots y resolución por separado;
- mostrar inspector, blockers, warnings y concurrency metadata;
- preservar navigation tipada;
- no producir efectos secundarios ni botones de comandos.

### 37.5 — Timeline administrativo

Debe:

- usar `timeline[]` ya devuelto;
- ejecutar cero queries adicionales;
- respetar source rank y no inventar causalidad;
- representar faltantes como findings, no eventos ficticios.

### 37.6 — Acciones administrativas seguras

Permanece bloqueado hasta:

- contrato de dominio por comando;
- operational version/expected state;
- idempotency key;
- orden de locks;
- auditoría y 409;
- pruebas de concurrencia/compensación.

Puede reducirse a retries ya idempotentes solo después de certificarlos como
comandos explícitos; no basta invocar workers existentes desde UI.

### 37.7 — Certificación integral

Debe certificar:

- matriz de estados y precedencia;
- invariantes y tolerancia histórica;
- privacidad/PII;
- presupuesto y N+1;
- asincronía frontend;
- navegación;
- integraciones Customer Panel, Cart, Checkout, Payments, Reservations,
  Delivery y Fulfillment;
- staging/diff exactos.

## 20. Estrategia de pruebas futura

Aunque 37.1 no crea pruebas, fija cobertura requerida:

- tabla cruzada de dimensiones;
- primary state por cada rama de precedencia;
- unknown source codes;
- Checkout cancelled + payment rejected reutilizable;
- approved + business retryable;
- pickup completed + Order paid;
- delivery delivered + Order paid/delivered;
- lease vigente/vencido;
- múltiples intentos legítimos;
- montos y sets incompatibles;
- reservas active/consumed/expired/released/mixed;
- referencias actuales faltantes;
- fallos parciales/totales de lectura;
- determinismo ante filas permutadas;
- fingerprint idéntico ante mismo facts y distinto ante cada autoridad;
- timeline con empates;
- ausencia de PII/tokens/payloads.

## 21. Criterio de cierre del contrato

El contrato queda listo para 37.2 cuando:

- ninguna dimensión depende solo de `orders.status`;
- todos los valores son cerrados;
- primary state tiene precedencia única;
- cada inconsistencia posee código y severidad;
- pickup y delivery se resuelven separadamente;
- retries/leases no se confunden con fallos comerciales;
- timeline no inventa eventos;
- versión incluye autoridades externas a Order;
- primera fase permanece read-only;
- presupuesto 2/4/8 sigue verificable.

Este Hito 37.1 crea únicamente este documento. No implementa resolver, enums,
CAS, endpoints, migraciones, UI, acciones ni pruebas.
