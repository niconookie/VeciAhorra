# VeciAhorra 24.0 — Payment Functional Design

## 1. Objetivos y alcance

Payments administra la intención de pago asociada a uno o más pedidos creados
por Checkout. Su responsabilidad comienza cuando existen Orders en estado
`reserved` y Reservations activas, y termina cuando el resultado de la
pasarela queda registrado de forma idempotente y el dominio aplica una única
transición final.

El módulo resuelve:

- agrupar varios pedidos de un checkout en un solo cobro;
- congelar monto, moneda y relación con los pedidos;
- iniciar y rastrear una operación en una pasarela externa;
- procesar confirmaciones, reintentos y respuestas duplicadas;
- coordinar Orders, Reservations e Inventory después del resultado del pago;
- conservar una trazabilidad independiente del proveedor de pagos.

Payments consume resultados de Checkout y contratos de Orders y Reservations.
Ante pago confirmado actualiza Payments, marca los Orders como pagados y
consume sus Reservations. Inventory sólo participa a través de
InventoryLockService: el stock ya fue bloqueado al reservar y la confirmación
definitiva no vuelve a descontarlo.

Quedan fuera de 24.0 la implementación PHP, tablas reales, rutas, migraciones,
credenciales, SDK de proveedores, reembolsos, contracargos, conciliación,
cuotas, impuestos, facturación y frontend.

## 2. Principios de diseño

- Payments orquesta pagos; no crea Orders ni calcula nuevamente sus totales.
- Un Payment puede agrupar múltiples Orders del mismo checkout.
- Una Order sólo puede pertenecer a un Payment durante toda su vida. Los
  reintentos recuperan ese mismo Payment en lugar de crear otro.
- El monto se congela al crear el Payment y nunca se acepta desde el cliente.
- La pasarela se integra mediante un puerto estable; sus SDK no entran al
  dominio.
- La confirmación del servidor o webhook es la autoridad, no el retorno del
  navegador.
- Toda creación y confirmación es idempotente.
- Una Reservation activa representa stock temporalmente bloqueado; marcarla
  `consumed` confirma ese bloqueo sin volver a descontar Inventory.
- Las transiciones finales coordinadas se ejecutan en una transacción local.
- Ningún secreto, token sensible ni payload completo de tarjeta se persiste.

## 3. Arquitectura y responsabilidades

```text
Frontend
   │
   ▼
CheckoutService ──► Orders (reserved) ──► Reservations (active, 15 min)
   │                         │                         │
   └──────────────► PaymentService ◄──────────────────┘
                              │
                              ▼
                    PaymentGatewayInterface
                      │         │         │
                      ▼         ▼         ▼
                  Transbank  MercadoPago Stripe
                              │
                    webhook / confirmación
                              │
                              ▼
                    PaymentConfirmationService
                      ├──► Payments
                      ├──► Orders
                      ├──► Reservations
                      └──► InventoryLockService
```

### Checkout

Entrega los Orders creados y sus Reservations activas. No conoce SDK, tokens,
URLs ni estados internos de una pasarela. Solicita a PaymentService crear o
recuperar la intención correspondiente al checkout.

### Orders

Es la fuente del importe y del minimarket. Sus ítems y precios ya están
congelados. Payments no altera cantidades, precios ni composición. Tras una
confirmación válida, Orders pasa de `reserved` al estado `paid` que se
formalizará en 24.3. Actualmente Orders sólo crea el estado `reserved`; 24.1 no
debe introducir prematuramente la transición de pago. Un pago fallido no debe
modificar sus importes.

### Reservations e Inventory

Reservations conserva el bloqueo de 15 minutos mientras el pago está pendiente.
Al confirmar, cada reserva activa pasa a `consumed`. InventoryLockService
ejecuta `commitStock`, que no vuelve a descontar porque `lockStock` ya lo hizo.
Al cancelar, fallar definitivamente o expirar antes del pago, la liberación se
delega a ReservationService/ReservationExpirationService.

En la implementación actual, `lockStock` reduce inmediatamente la columna
`inventory.stock` para retirar esas unidades de la disponibilidad y evitar
sobreventa. Esa reducción es temporal y reversible mientras la Reservation
está `active`: `releaseStock` la deshace. El stock sólo se considera consumido
de forma definitiva después de un pago exitoso, cuando la Reservation cambia a
`consumed` y `commitStock` confirma el bloqueo sin ejecutar otra resta.

### Payments

Mantiene identidad, estado, monto congelado, proveedor, referencias externas,
idempotencia y auditoría. PaymentRepository sólo persiste. PaymentService
aplica reglas y PaymentConfirmationService coordina la transición final.

### Payment Gateway futuro

`PaymentGatewayInterface` traduce solicitudes y respuestas externas a DTOs del
dominio. Cada adaptador se ocupa de autenticación, firma, timeouts, códigos y
URLs del proveedor, sin decidir estados de Orders, Reservations o Inventory.

## 4. Flujo completo

### 4.1 Creación e inicio

1. Checkout valida el carrito, bloquea stock, crea Reservations y Orders.
2. Checkout solicita un Payment para todos los Orders del intento.
3. PaymentService comprueba propiedad, estado `reserved`, reservas activas y
   que ninguna Order esté vinculada a otro Payment.
4. Suma los totales congelados de Orders con aritmética decimal.
5. Crea `payments` en `pending` y sus relaciones `payment_orders` dentro de una
   transacción.
6. Invoca al gateway con ID interno, monto, moneda, URL de retorno y clave de
   idempotencia.
7. Guarda la referencia externa y cambia a `processing`.
8. Devuelve al frontend la URL o token de redirección, nunca credenciales.

### 4.2 Confirmación

1. La pasarela redirige al usuario y/o envía un webhook.
2. El adaptador verifica firma, origen y referencia; después consulta al
   proveedor cuando su protocolo lo exija.
3. PaymentConfirmationService bloquea la fila Payment para evitar dos
   confirmaciones concurrentes.
4. Si el evento ya fue aplicado, devuelve el resultado persistido.
5. Verifica monto, moneda, estado externo y que todas las Reservations sigan
   activas y correspondan a los Orders.
6. En una transacción marca Payment `paid`, Orders pagadas y Reservations
   `consumed`; llama `commitStock`, que no descuenta nuevamente.
7. Confirma la transacción y responde éxito.

### 4.3 Resultado no exitoso

- Un rechazo definitivo cambia Payment a `failed`.
- Una cancelación solicitada cambia a `cancelled` si todavía es reversible.
- Si vence la ventana, cambia a `expired` y las reservas se liberan una vez.
- Un timeout o respuesta ambigua no se interpreta como fallo: Payment permanece
  `processing` hasta consultar o reconciliar el estado externo.

## 5. Modelo de datos

Los nombres físicos siguen el prefijo WordPress y el prefijo VeciAhorra. En una
instalación estándar serían `wp_va_payments` y `wp_va_payment_orders`; nunca se
hardcodea `wp_` en producción.

### 5.1 `wp_va_payments`

| Campo | Tipo lógico | Regla y propósito |
|---|---|---|
| `id` | bigint unsigned | PK autoincremental e identidad interna |
| `user_id` | bigint unsigned | Comprador autenticado propietario |
| `checkout_key` | varchar(64) | Identifica el checkout que originó el pago |
| `idempotency_key` | varchar(128) | Clave de creación única por usuario |
| `provider` | varchar(32) | Adaptador seleccionado: `transbank`, `mercadopago`, `stripe` |
| `provider_payment_id` | varchar(191) nullable | Referencia no sensible de la pasarela |
| `status` | varchar(20) | Estado normalizado del ciclo de vida |
| `amount` | decimal(12,2) | Suma congelada de los Orders |
| `currency` | char(3) | Código ISO 4217, inicialmente `CLP` |
| `redirect_url` | text nullable | URL temporal entregada por el gateway |
| `failure_code` | varchar(64) nullable | Código normalizado de fallo |
| `failure_message` | varchar(255) nullable | Mensaje seguro para auditoría |
| `provider_payload` | longtext nullable | Metadatos mínimos sanitizados, idealmente JSON |
| `processing_at` | datetime nullable | Inicio en pasarela |
| `paid_at` | datetime nullable | Confirmación definitiva |
| `failed_at` | datetime nullable | Fallo definitivo |
| `cancelled_at` | datetime nullable | Cancelación definitiva |
| `expires_at` | datetime | Límite alineado con la reserva más temprana |
| `created_at` | datetime | Creación |
| `updated_at` | datetime | Última transición |

Índices y restricciones:

- PK (`id`);
- unique (`user_id`, `idempotency_key`) evita doble creación;
- unique (`provider`, `provider_payment_id`) cuando la referencia no es nula;
- index (`checkout_key`), para recuperar el pago del checkout;
- index (`status`, `expires_at`), para expiración y conciliación futuras;
- index (`user_id`, `created_at`), para historial del comprador;
- `amount >= 0`, moneda de tres caracteres y estados limitados por dominio;
- timestamps terminales coherentes con el estado correspondiente.

No se almacenan PAN, CVV, secretos, firmas crudas ni tokens reutilizables. Si
`provider_payload` no es imprescindible, se omite; de existir, se aplica una
lista permitida de campos y política de retención.

### 5.2 `wp_va_payment_orders`

| Campo | Tipo lógico | Regla y propósito |
|---|---|---|
| `id` | bigint unsigned | PK autoincremental |
| `payment_id` | bigint unsigned | Relación con Payments |
| `order_id` | bigint unsigned | Relación con Orders |
| `amount_snapshot` | decimal(12,2) | Total congelado de esa Order |
| `created_at` | datetime | Momento de asociación |

Índices y restricciones:

- PK (`id`);
- unique (`order_id`) garantiza que una Order pertenezca a un solo Payment;
- unique (`payment_id`, `order_id`) protege contra relaciones duplicadas;
- index (`payment_id`) permite recuperar todos los Orders del cobro;
- FK lógica `payment_id → payments.id` y `order_id → orders.id`;
- `amount_snapshot >= 0`;
- suma de snapshots igual a `payments.amount` al crear, inmutable después.

La política inicial usa borrado restringido: Payments y sus asociaciones no se
eliminan una vez enviados al gateway. Se conservan para auditoría y los estados
terminales sustituyen al borrado físico.

## 6. Ciclo de estados

| Estado | Significado |
|---|---|
| `pending` | Intención persistida; todavía no aceptada por el gateway |
| `processing` | Gateway iniciado o resultado externo aún no concluyente |
| `paid` | Pago confirmado y efectos de dominio aplicados |
| `failed` | Rechazo o error definitivo confirmado |
| `cancelled` | Cancelado antes de una confirmación exitosa |
| `expired` | Venció la ventana sin confirmación válida |

Transiciones permitidas:

```text
pending ─────► processing ─────► paid
   │               │  ├────────► failed
   │               │  ├────────► cancelled
   │               │  └────────► expired
   ├───────────────► failed
   ├───────────────► cancelled
   └───────────────► expired

paid, failed, cancelled, expired ──► estados terminales
```

Reglas de transición:

- sólo `pending` puede iniciar una operación nueva de gateway;
- `processing` admite consultas y confirmaciones repetidas;
- `paid` nunca retrocede por un callback tardío;
- un evento `paid` posterior a `failed`, `cancelled` o `expired` no se aplica
  automáticamente: se registra para conciliación manual;
- repetir la misma transición terminal es idempotente;
- toda transición inválida se rechaza y queda observable, sin alterar dominio.

## 7. Reglas de negocio

1. Un Payment contiene uno o más Orders del mismo comprador y checkout.
2. Una Order pertenece como máximo a un Payment.
3. Todos los Orders deben estar `reserved` y tener Reservations activas.
4. `amount` es la suma exacta de `amount_snapshot`; ambos quedan congelados.
5. El cliente no define IDs de usuario, montos, moneda ni estado.
6. No se modifican Orders ya pagadas ni se inicia un segundo Payment para ellas.
7. La creación repetida con la misma idempotency key devuelve el mismo Payment.
8. La misma clave con un conjunto de Orders diferente responde conflicto.
9. Una confirmación se identifica por proveedor, referencia y evento; procesarla
   varias veces produce un solo efecto.
10. La respuesta del navegador no confirma por sí sola un pago.
11. `commitStock` no vuelve a descontar el stock bloqueado por la reserva.
12. Fallo/cancelación/expiración libera cada reserva como máximo una vez.
13. Si una reserva vence antes de confirmar, el pago no se marca `paid` sin una
    política de recuperación y conciliación explícita.
14. Los Orders de distintos minimarkets pueden compartir Payment, pero cada
    Order conserva su total y ciclo operativo independiente.

## 8. REST API futura

Namespace: `veciahorra/v1`. Todos los endpoints mantienen el envoltorio
`success/data` o `success/error`, autenticación WordPress y autorización por
propietario. No se implementan en 24.0.

### POST `/payments`

Crea o recupera el Payment para el checkout actual. Requiere header
`Idempotency-Key`; el body sólo puede seleccionar un `provider` permitido. Los
Orders se resuelven en servidor desde el resultado de Checkout.

- 201 al crear;
- 200 al repetir una clave completada;
- 409 si la clave está en proceso o no coincide con el mismo intento;
- 422 si Orders o Reservations no son pagables.

### POST `/payments/{id}/initialize`

Inicia la pasarela y devuelve `redirect_url`, token público temporal y estado.
Es idempotente: un Payment ya inicializado devuelve sus mismos datos seguros.

### GET `/payments/{id}`

Devuelve estado, monto, moneda, Orders y timestamps al propietario. Nunca
incluye secretos ni payload interno completo.

### POST `/payments/{id}/confirm`

Endpoint de retorno cuando el proveedor exige confirmación activa. No confía en
un estado enviado por el navegador; usa token/referencia y consulta al gateway.

### POST `/payments/webhooks/{provider}`

Recibe notificaciones firmadas. Responde rápido, procesa idempotentemente y usa
la referencia externa para localizar Payment. La autenticación es la firma del
proveedor, no la sesión del usuario.

### POST `/payments/{id}/cancel`

Solicita cancelación sólo cuando el gateway y estado lo permiten. La liberación
de reservas ocurre tras confirmar la cancelación, no por intención del cliente.

## 9. Atomicidad, idempotencia y concurrencia

La creación de Payment y `payment_orders` es una transacción local. La llamada
HTTP externa ocurre fuera de una transacción de base de datos prolongada. Antes
de llamar, el Payment queda reclamado; después se persiste el resultado.

La confirmación bloquea la fila Payment (`SELECT ... FOR UPDATE` o repositorio
equivalente) y ejecuta en una sola transacción:

```text
Payment processing → paid
Orders reserved → paid
Reservations active → consumed
InventoryLockService.commitStock() → sin segundo descuento
```

Si falla cualquier escritura, se revierte toda la transición local y el evento
puede reintentarse. Para evitar perder callbacks entre la pasarela y la base de
datos, una fase posterior puede añadir inbox/outbox; no se simula atomicidad
distribuida manteniendo abierta la transacción durante una llamada de red.

## 10. Casos borde

| Caso | Resultado esperado |
|---|---|
| Timeout al inicializar | Mantener `processing`; consultar por referencia antes de reintentar |
| Doble clic al pagar | Misma idempotency key devuelve el mismo Payment |
| Dos claves para los mismos Orders | La unicidad de `order_id` permite sólo un Payment |
| Webhook repetido | Respuesta exitosa idempotente; cero efectos adicionales |
| Retorno y webhook simultáneos | Bloqueo de fila; una transición gana y la otra observa el resultado |
| Monto externo distinto | No confirmar; registrar discrepancia y enviar a conciliación |
| Moneda externa distinta | Rechazar confirmación y alertar |
| Reserva expira durante pago | No confirmar automáticamente; reconciliar pago tardío |
| Gateway rechaza | `failed`; liberar reservas una sola vez según política |
| Usuario cancela navegador | No asumir cancelación; esperar estado del gateway o expiración |
| Servidor cae tras crear operación externa | Recuperar usando idempotency key/referencia y consultar gateway |
| Servidor cae durante confirmación | Transacción revierte o queda aplicada completa; reintento seguro |
| Callback con firma inválida | 401/403, sin cambios y con registro de seguridad |
| Callback de Payment desconocido | 404 controlado o acuse neutro según proveedor; sin crear Payment |
| Evento pagado después de expirar | No reactivar stock; conciliación y eventual devolución manual |
| Orders ya pagadas | No crear ni reiniciar Payment |
| Fallo liberando una reserva | Reintento idempotente; no marcar compensación completa antes de terminar |
| Pasarela no disponible | Circuit breaker/reintento acotado; Payment conserva estado recuperable |

## 11. Integración desacoplada con pasarelas

El dominio depende de un contrato, no de SDK concretos:

```text
PaymentGatewayInterface
  initialize(PaymentIntent): GatewayInitialization
  fetchStatus(GatewayReference): GatewayResult
  confirm(GatewayConfirmation): GatewayResult
  cancel(GatewayReference): GatewayResult
  verifyWebhook(headers, body): VerifiedGatewayEvent
```

Los DTOs normalizan `provider_payment_id`, estado, monto, moneda, URL, código de
fallo y metadatos permitidos. PaymentService traduce el resultado normalizado a
estados del dominio.

### Transbank

El adaptador administra token de transacción, URL de redirección y confirmación
server-to-server. El retorno del navegador dispara consulta/commit según el
protocolo, pero no altera Orders directamente.

### Mercado Pago

El adaptador crea la preferencia o Payment, conserva el ID externo y verifica
webhooks mediante firma y consulta API. Estados detallados se mapean a los seis
estados internos sin filtrarlos al dominio.

### Stripe

El adaptador usa PaymentIntent y su idempotency key. Los webhooks firmados son
la fuente principal; `succeeded`, fallos y cancelación se normalizan antes de
entregarlos a PaymentConfirmationService.

Cambiar o añadir proveedor sólo requiere un adaptador, configuración y pruebas
de contrato. Checkout, Orders, Reservations e Inventory no cambian.

## 12. Diagramas de secuencia

### Pago exitoso

```text
Cliente      Checkout       Payments       Gateway       Dominio
  │             │              │              │             │
  │ checkout    │              │              │             │
  ├────────────►│ Orders + Reservations       │             │
  │             ├─────────────►│ pending       │             │
  │             │              ├─────────────►│ initialize  │
  │◄────────────┴──────────────┤ redirect_url │             │
  ├──────────────────────────────────────────►│ pagar       │
  │             │              │◄─────────────┤ webhook     │
  │             │              ├──────────────┼────────────►│
  │             │              │              │ Orders paid │
  │             │              │              │ Res consumed│
  │             │              │ paid         │ commitStock │
  │◄───────────────────────────┤              │             │
```

### Fallo recuperable

```text
initialize ─► timeout ─► Payment processing
                              │
                              ├─ consulta gateway ─► paid ─► confirmar dominio
                              ├─ consulta gateway ─► failed ─► liberar reservas
                              └─ sin resultado hasta expires_at ─► expired
```

## 13. Servicios propuestos

```text
app/Modules/Payments/
  Requests/
  Repository/
  Service/
    PaymentService.php
    PaymentConfirmationService.php
  Gateway/
    PaymentGatewayInterface.php
    PaymentGatewayRegistry.php
  Controller/
  Routes/
```

- `PaymentService`: creación, validación, inicio y consulta del Payment.
- `PaymentConfirmationService`: transiciones idempotentes y coordinación con
  Orders/Reservations/Inventory.
- `PaymentRepository`: persistencia de Payments y asociaciones, sin reglas.
- `PaymentGatewayRegistry`: selecciona adaptador por nombre permitido.
- `PaymentController`: delega y traduce errores; no valida firmas ni ejecuta SQL.
- `PaymentRoutes`: registra endpoints una vez y separa permisos de usuario y
  autenticación de webhooks.

## 14. Estrategia de pruebas por fase

### 24.1 — Payments Backend Foundation

- se limita al dominio y persistencia local; no llama pasarelas ni confirma
  Orders o Reservations;
- esquema e índices de ambas tablas;
- creación de Payment y múltiples relaciones con Orders;
- monto y moneda congelados;
- una Order no puede vincularse dos veces;
- estados y transiciones básicas;
- idempotency key única por usuario;
- Repository sin reglas y registro único del módulo;
- regresión de Checkout, Orders, Reservations e Inventory.

### 24.2 — Payment Gateway Integration

- inicia y consulta operaciones externas, pero todavía no aplica la transición
  definitiva sobre Orders, Reservations o Inventory;
- contrato común con gateway falso;
- inicialización exitosa, rechazo, timeout y excepción;
- persistencia segura de referencia y redirect URL;
- reintento no crea otra operación;
- adaptadores traducen estados sin tocar dominio;
- firmas válidas e inválidas de webhook;
- ningún dato sensible se registra o expone.

### 24.3 — Payment Confirmation

- incorpora los efectos definitivos y su transacción local idempotente;
- confirmación exitosa marca Payment y Orders como pagados;
- Reservations pasan de `active` a `consumed`;
- stock no se descuenta dos veces;
- confirmación repetida es idempotente;
- webhook y retorno concurrentes producen un solo efecto;
- fallo, cancelación y expiración liberan stock una vez;
- monto/moneda discrepantes no confirman;
- reserva vencida y pago tardío van a conciliación;
- fallo intermedio revierte toda la transición local;
- caída y reintento recuperan el resultado;
- regresión integral de Checkout, Orders, Reservations e Inventory.

## 15. Observabilidad y seguridad

- Registrar `payment_id`, proveedor, referencia externa enmascarada, transición
  y correlation ID; nunca secretos o datos de tarjeta.
- Métricas: pagos creados, conversión, latencia, timeouts, duplicados,
  discrepancias y pagos tardíos.
- Alertas para Payments `processing` envejecidos, confirmaciones rechazadas por
  monto y fallos de compensación.
- Verificar firma antes de parsear un webhook como evento confiable.
- Aplicar rate limiting, HTTPS, rotación de secretos y comparación segura de
  firmas.
- Separar mensajes públicos de detalles técnicos de auditoría.

## 16. Decisiones explícitas

- Un Payment puede cobrar múltiples Orders.
- Una Order sólo pertenece a un Payment.
- El importe proviene de Orders y queda congelado.
- Payments no crea Orders ni vuelve a calcular precios.
- La confirmación no vuelve a descontar stock.
- Las Reservations exitosas pasan a `consumed`.
- Webhook/consulta al proveedor prevalece sobre el retorno del navegador.
- Los estados terminales no se reabren automáticamente.
- Creación y confirmación son idempotentes.
- Las llamadas externas no mantienen transacciones de base de datos abiertas.
- Proveedores se integran mediante adaptadores intercambiables.
- Pagos tardíos o discrepantes requieren conciliación, no cambios automáticos
  peligrosos.
- 24.0 es exclusivamente diseño: no crea código, tablas, rutas ni migraciones.
