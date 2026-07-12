# VeciAhorra 28.7.4.1 — Payment Session Backend Contract Foundation

## 1. Objetivo y alcance

Este hito define, sin implementarlo, el contrato técnico y funcional de la infraestructura backend necesaria para persistir una compra global e iniciar o recuperar una sesión interna de pago. El diseño se basa en el código vigente y separa expresamente el comportamiento actual de la propuesta.

Incluye:

- Checkout persistente y su relación uno-a-muchos con Orders.
- Ownership para usuario autenticado e invitado.
- Idempotencia persistente y protección ante concurrencia.
- Payment Session independiente del proveedor.
- Inicio, reutilización y recuperación de la sesión.
- Persistencia suficiente para recuperar respuestas perdidas.
- Contratos REST públicos, seguridad y plan de pruebas.
- Modelos, repositorios, servicios, requests, controllers, routes, schemas y migraciones que requerirá una implementación posterior.

Quedan fuera: Webpay, Mercado Pago, Stripe o cualquier pasarela real; confirmación de pagos; webhooks; transiciones de Orders a `paid`; creación de Delivery; consumo o liberación de reservas; cambios de stock o inventario; correos; redirecciones externas; y cualquier URL de pago ficticia.

Este documento es el único entregable de 28.7.4.1. No modifica el contrato ejecutable actual.

## 2. Base real del repositorio y brechas

Se revisaron los módulos Checkout, Orders, Reservations, Payments, Cart, Frontend, Core/Database, schemas, migraciones, rutas REST y pruebas manuales relacionadas.

Hallazgos relevantes:

- `POST /veciahorra/v1/checkout/validate` llama a `CheckoutValidationService` y no persiste. Esta propiedad debe conservarse.
- El actual `POST /veciahorra/v1/checkout` valida el carrito, crea reservas y un Order `reserved` por minimarket, asocia las reservas y vacía el carrito. No crea una entidad Checkout persistente y rechaza invitados con `guest_checkout_not_supported`.
- Orders usa `customer_id`, no tiene ownership de invitado, y conserva `total` y `reservation_expires_at`. Reservations se asocia a Orders, dura inicialmente 15 minutos y puede expirar en un proceso independiente.
- Cart resuelve primero `get_current_user_id()` y, para invitado, acepta hoy `session_id` por query o `X-Veciahorra-Cart-Session`. El frontend genera un valor hexadecimal aleatorio de 64 caracteres y lo guarda en la sesión PHP mediante `CartSession`/`Session`.
- Payments es administrativo (`manage_options`). `payments` y `payment_orders` ya permiten agrupar múltiples Orders, pero exponen internamente IDs numéricos, reciben cliente/monto/moneda desde el request y no conocen Checkout.
- `PaymentSessionService` actual llama siempre a `DummyPaymentGateway`, persiste parte de la sesión dentro de `payments`, no persiste la URL y no ofrece recuperación pública ni `Idempotency-Key`.
- `PaymentGatewayInterface` y Dummy tienen un contrato anterior (`createPaymentSession`, `confirmPayment`, `getProviderName`) que no coincide con la interfaz conceptual propuesta aquí. Dummy además genera una URL ficticia. Debe coexistir temporalmente o refactorizarse en otro hito; no se debe reemplazar silenciosamente.
- Las tablas usan el nombre físico `$wpdb->prefix . Config::TABLE_PREFIX . <nombre>`, donde `Config::TABLE_PREFIX` es `va_`; `wp_va_*` es solo el ejemplo con el prefijo WordPress por defecto.
- Los schemas usan `TableBuilder`, InnoDB y `dbDelta`; no declaran foreign keys físicas. `PaymentRepository` sí demuestra el patrón `START TRANSACTION`/`COMMIT`/`ROLLBACK`, aunque varias creaciones actuales hacen compensación manual.
- `MigrationManager` reejecuta migraciones registradas en orden y versiona mediante `Config::SCHEMA_VERSION`/`veciahorra_db_version`.

Brecha contractual: este diseño agrega invitados, IDs públicos, ownership estricto, transacciones reales y nuevos códigos REST que hoy no están implementados. La implementación futura deberá corregir el origen inseguro del `session_id`, añadir ownership de invitado a Orders o una equivalencia verificable, y mantener las rutas administrativas antiguas aisladas hasta decidir su migración.

## 3. Flujo funcional

```text
Cart
  ↓
POST /checkout/validate
  ↓
Creación de Orders y reservas
  ↓
Checkout persistente
  ↓
Inicio o recuperación de Payment Session
  ↓
Gateway futuro
```

Reglas de interpretación:

- `POST /checkout/validate` solo valida y nunca crea Checkout, Orders, reservas ni Payment Session.
- Checkout no reemplaza Orders: representa una compra global y agrupa uno o varios pedidos por minimarket.
- Cada Payment Session pertenece a exactamente un Checkout; un Checkout puede conservar historial de sesiones, pero solo una puede ser recuperable/activa a la vez.
- Payments debe resolver todo desde Checkout y Orders persistidos, nunca desde Cart. Vaciar o modificar Cart después no cambia el monto del Checkout.
- Para evitar una escritura parcial, la implementación debe integrar la creación de Checkout en la misma unidad transaccional que crea/asocia los Orders, o adaptar la orquestación actual para que todos queden confirmados juntos. No es aceptable crear Checkout después de devolver una creación de Orders parcialmente compensable.

## 4. Modelo de datos propuesto

Todos los nombres siguientes son lógicos. El nombre físico se construye con `$wpdb->prefix . Config::TABLE_PREFIX`; por ejemplo, `wp_va_checkouts`. InnoDB es obligatorio. Los importes se guardan en `DECIMAL(10,2)` para ser compatibles con Orders actuales y se manipulan como decimales/céntimos, nunca como `float`.

### 4.1 `wp_va_checkouts`

| Campo | Tipo | NULL/default | Regla |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | no | PK interna. |
| `public_id` | `VARCHAR(64)` | no | ID opaco único generado por backend. |
| `owner_type` | `VARCHAR(16)` | no | `user` o `session`. |
| `user_id` | `BIGINT UNSIGNED` | sí | Obligatorio solo para `owner_type=user`. |
| `session_id` | `CHAR(64)` | sí | Huella SHA-256/HMAC del identificador de sesión; obligatoria solo para `owner_type=session`. |
| `status` | `VARCHAR(30)` | no, `pending` | Estado controlado por servicio. |
| `currency` | `CHAR(3)` | no, `CLP` | ISO 4217 en mayúsculas. |
| `total_amount` | `DECIMAL(10,2)` | no | Suma backend de Orders, mayor que cero. |
| `created_at` | `DATETIME` | no | Zona horaria WordPress, convención actual. |
| `updated_at` | `DATETIME` | no | Se actualiza en cada transición. |
| `expires_at` | `DATETIME` | no | No posterior a la expiración mínima de sus Orders/reservas. |

Índices:

- `UNIQUE(public_id)`.
- `INDEX(owner_type, user_id, status)` y `INDEX(owner_type, session_id, status)` para lecturas con ownership incluido.
- `INDEX(status, expires_at)` para expiración por lotes.

Restricciones e invariantes:

- XOR de ownership: `user` implica `user_id IS NOT NULL AND session_id IS NULL`; `session` implica `session_id IS NOT NULL AND user_id IS NULL`. Debe validarse en servicio y, si la versión MySQL/MariaDB y `dbDelta` lo permiten con seguridad, también mediante `CHECK`; no se dependerá solo de `CHECK`.
- La sesión se compara mediante una huella estable con secreto de aplicación y comparación segura; no se guarda el token crudo reutilizable. El nombre `session_id` se conserva por contrato, pero su contenido persistido es la huella.
- `total_amount` y `currency` son snapshot de Orders y no aceptan valores del cliente.
- Debe contener al menos una fila en `checkout_orders` antes del commit.
- `expires_at` se fija al mínimo de `orders.reservation_expires_at`/reservas activas. Al alcanzarlo, `pending` o `payment_started` deja de aceptar inicio y pasa a `expired` por evaluación sincrónica o job futuro; este hito solo define la regla.

### 4.2 `wp_va_checkout_orders`

| Campo | Tipo | NULL | Regla |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | no | PK. |
| `checkout_id` | `BIGINT UNSIGNED` | no | Referencia interna a Checkout. |
| `order_id` | `BIGINT UNSIGNED` | no | Referencia interna a Order. |
| `created_at` | `DATETIME` | no | Fecha de asociación. |

Índices y cardinalidad:

- `UNIQUE(order_id)` impide que una Order pertenezca a dos Checkouts, incluidos estados históricos. Es más fuerte que “dos activos” y elimina ambigüedad; una reasignación excepcional exigiría migración/auditoría explícita.
- `UNIQUE(checkout_id, order_id)` satisface la unicidad del par y documenta la relación.
- `INDEX(checkout_id)` permite recuperar todas las Orders del Checkout.

No se almacenarán `order_ids` como JSON, texto serializado ni metadata. La integridad es lógica, coherente con los schemas actuales: repositorios verifican existencia/ownership y la transacción bloquea las Orders (`SELECT ... FOR UPDATE`) antes de insertar. Si posteriormente se adoptan foreign keys, deben añadirse con una migración compatible, no suponerse en este hito.

### 4.3 `wp_va_payment_sessions`

| Campo | Tipo | NULL/default | Regla durante 28.7.4.1 |
| --- | --- | --- | --- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | no | PK interna. |
| `public_id` | `VARCHAR(64)` | no | ID opaco único. |
| `checkout_id` | `BIGINT UNSIGNED` | no | Checkout propietario. |
| `idempotency_key` | `VARCHAR(128)` | no | Clave normalizada del request. |
| `request_fingerprint` | `CHAR(64)` | no | SHA-256 hexadecimal del request canónico. |
| `status` | `VARCHAR(30)` | no, `pending` | Estado interno. |
| `provider` | `VARCHAR(50)` | sí | `NULL` recomendado; `internal` permitido. |
| `provider_session_id` | `VARCHAR(191)` | sí | `NULL`, porque no hay proveedor. |
| `redirect_url` | `TEXT` | sí | Siempre `NULL` en este hito; no inventar URL. |
| `currency` | `CHAR(3)` | no | Copia verificada del Checkout. |
| `amount` | `DECIMAL(10,2)` | no | Copia verificada del Checkout. |
| `metadata` | `TEXT` | sí | JSON canónico no sensible o `NULL`; no se expone públicamente. |
| `created_at` | `DATETIME` | no | Creación. |
| `updated_at` | `DATETIME` | no | Último cambio. |
| `expires_at` | `DATETIME` | no | Igual o anterior a `checkout.expires_at`. |

Índices:

- `UNIQUE(public_id)`.
- `UNIQUE(checkout_id, idempotency_key)` define el alcance de idempotencia.
- `INDEX(checkout_id, status, expires_at)` para recuperar sesión activa.
- `UNIQUE(provider, provider_session_id)` solo al introducir proveedor y después de validar compatibilidad de múltiples `NULL`; no es necesario en 28.7.4.1.
- `INDEX(status, expires_at)` para expiración.

En este hito pueden ser `NULL`: `provider`, `provider_session_id`, `redirect_url` y `metadata`. Ningún otro campo lo es. `metadata` no guarda tarjetas, secretos, headers, nonce, token de sesión ni datos personales; si no hay necesidad concreta debe permanecer `NULL`.

MySQL/MariaDB no ofrece un índice parcial portable para “una sesión activa por Checkout”. Esa invariante se protege bloqueando la fila Checkout y consultando dentro de la misma transacción; los índices únicos de idempotencia son la defensa de concurrencia adicional.

## 5. Identificadores públicos

- Los IDs numéricos (`id`, `checkout_id`, `order_id`) nunca aparecen en request o response público.
- Formato recomendado: `chk_` para Checkout y `ps_` para Payment Session, seguido de 43 caracteres Base64URL sin padding derivados de 32 bytes de CSPRNG. Entropía: 256 bits.
- Solo el backend usa `random_bytes`; el cliente nunca propone, completa ni modifica IDs.
- Validación antes de consultar: regex exactas `^chk_[A-Za-z0-9_-]{43}$` y `^ps_[A-Za-z0-9_-]{43}$`, longitud completa y comparación sensible a mayúsculas.
- Un ID válido solo localiza un candidato. Toda consulta añade ownership; nunca funciona como bearer token.
- La colación de `public_id` debe ser binaria/ASCII sensible a mayúsculas o la aplicación debe garantizar comparación binaria, para que la unicidad coincida con el validador.

## 6. Ownership

Un Checkout pertenece exactamente a un owner y jamás es público sin ownership.

### Usuario autenticado

- El owner se deriva de `get_current_user_id()` y se persiste como `owner_type=user`, `user_id=<ID>`, `session_id=NULL`.
- La sesión invitada no es fuente principal ni alternativa cuando hay usuario autenticado.
- Crear Checkout exige que todas las Orders tengan `customer_id` igual al usuario actual. Iniciar o consultar añade `user_id` y `owner_type` al query.
- Otro usuario recibe `404` por defecto para no confirmar existencia. `403` queda para un recurso ya autenticado/visible sobre el cual falta una capacidad adicional, no para revelar ownership cruzado.

### Invitado

- Se persiste `owner_type=session`, `user_id=NULL` y la huella del ID obtenido del mecanismo de sesión existente.
- El backend debe obtener el valor de `CartSession`/`Session` del contexto HTTP confiable. Nunca acepta `session_id` arbitrario en body, query ni header para Checkout/Payments.
- Brecha obligatoria: Cart y Checkout actuales aceptan query/header. Antes de habilitar invitados en este contrato debe existir un resolver compartido y server-side; el header actual puede servir para Cart durante transición, pero no autoriza Checkout ni Payment Session.
- Orders actuales carecen de owner invitado. La implementación debe añadir ownership compatible a Orders o una relación segura creada atómicamente por Checkout. No debe simular un `customer_id` ni usar un usuario genérico compartido.

Todos los repositorios públicos deben ofrecer métodos como `findOwnedByPublicId(publicId, OwnerContext)` y no exponer un `findByPublicId` directo al controller. Conocer solo `public_id` nunca basta.

## 7. Idempotencia persistente

### Clave

- Header obligatorio: `Idempotency-Key`.
- Longitud: 16 a 128 caracteres después de eliminar espacios ASCII únicamente en los extremos.
- Caracteres: ASCII visible seguro `[A-Za-z0-9._:-]`; sin espacios internos, Unicode, saltos de línea ni controles.
- La clave conserva mayúsculas/minúsculas y no se transforma; la columna debe compararse con colación binaria. Una clave malformada produce `400 invalid_idempotency_key` sin escritura.
- Alcance: Checkout. La unicidad física es `(checkout_id, idempotency_key)`; owners distintos o Checkouts distintos pueden reutilizar el texto.
- Retención: la fila y su clave se conservan al menos 24 horas después de que Checkout/Payment Session alcance estado terminal y nunca menos que la vida del Checkout. Purga posterior por job y política de auditoría, no dentro de este hito.

### Fingerprint

`IdempotencyService` serializa en JSON canónico, con claves ordenadas, strings UTF-8, importes con dos decimales y Orders ordenadas ascendentemente; luego calcula SHA-256 hexadecimal. Contenido mínimo:

```json
{
  "operation": "payment_session.start.v1",
  "checkout_public_id": "chk_...",
  "owner": {"type": "user", "stable_id": "42"},
  "currency": "CLP",
  "total_amount": "15000.00",
  "orders": [101, 102]
}
```

Los IDs internos de Orders pueden formar parte del hash server-side, pero nunca de la respuesta. Para invitado, `stable_id` es la huella persistida de sesión, no el token crudo. El fingerprint se calcula exclusivamente desde datos leídos y bloqueados en backend.

### Reintentos

- Misma clave + mismo fingerprint: devolver exactamente la misma Payment Session, `200`, sin nuevas escrituras de negocio ni gateway.
- Misma clave + fingerprint distinto: `409 idempotency_conflict`; no revelar el fingerprint guardado.
- Clave nueva con sesión activa recuperable: reutilizar esa sesión y devolver `200` con `reused=true`; no crear una segunda fila ni reemplazar su clave original. Repetir con esa clave nueva vuelve a resolver primero la sesión activa del Checkout y obtiene el mismo resultado. Esta clave alternativa no adquiere historial propio; el cliente debe conservar la clave original para la garantía fuerte tras expiración.
- Clave nueva sin sesión activa: crear una nueva fila `pending` con `201`, siempre que Checkout siga pagable.
- Un error único por carrera se resuelve releyendo dentro del mismo ownership y aplicando las reglas anteriores; nunca se responde `500` sin intentar recuperación determinista.

## 8. Estados y transiciones

### Checkout

Estados actuales del diseño: `pending`, `payment_started`, `expired`, `cancelled`. Reservados: `paid`, `failed`.

```text
pending ── iniciar/reutilizar sesión ──> payment_started
pending ── vencimiento ───────────────> expired
pending ── cancelación explícita futura -> cancelled
payment_started ── vencimiento ───────> expired
payment_started ── cancelación futura -> cancelled
```

`expired` y `cancelled` son terminales en este hito. Son inválidas todas las regresiones, cualquier inicio desde terminal y todas las transiciones a `paid`/`failed`. Repetir una transición ya aplicada solo puede ser una lectura idempotente, no una actualización nueva.

### Payment Session

Estados: `pending`, `ready`, `expired`, `cancelled`. Reservados: `processing`, `succeeded`, `failed`.

En 28.7.4.1 la creación interna queda `pending`: no hay gateway que permita llegar a `ready`. Solo se permiten `pending -> expired` por tiempo y `pending -> cancelled` por una operación futura explícita. Se reserva `ready` para 28.7.4.2 cuando exista un adaptador controlado. No existen transiciones automáticas a `succeeded`, ni Checkout `paid`, ni efectos sobre Orders/Reservations.

## 9. Contratos REST propuestos

Namespace completo: `/wp-json/veciahorra/v1`. Envelope compatible con las rutas actuales:

```json
{"success": true, "data": {}}
```

```json
{"success": false, "error": {"code": "...", "message": "...", "details": {}}}
```

`details` es opcional, estable y nunca contiene internals. Requests con body exigen `application/json`, JSON válido, objeto raíz y rechazo de campos desconocidos.

### 9.1 Crear Checkout persistente

`POST /checkout`

Este nombre ya existe para la inicialización actual. La implementación debe evolucionar su respuesta y atomicidad, no registrar una ruta duplicada. `POST /checkout/validate` permanece intacto.

Request propuesto después de crear Orders en la misma orquestación:

```json
{
  "order_ids": ["ord_publico_1", "ord_publico_2"]
}
```

Los Orders actuales no tienen IDs públicos. Por ello la opción recomendada es que el servicio resuelva internamente los Orders recién creados y que el request público de `POST /checkout` siga vacío; `order_ids` solo debe habilitarse cuando Orders tenga IDs públicos opacos. Nunca se aceptarán IDs numéricos. Esta es una brecha explícita, no un contrato disponible hoy.

Comportamiento:

1. Resolver owner confiable.
2. Validar/recrear la unidad de trabajo que genera Orders y reservas.
3. Bloquear y verificar que existe al menos una Order, que todas pertenecen al owner, están `reserved`, tienen reservas activas/vigentes y no están asociadas a otro Checkout.
4. Exigir una moneda única; mientras Orders no persista moneda, `CLP` proviene de Config de backend. Esta brecha debe cerrarse antes de soportar otras monedas.
5. Calcular total sumando `orders.total`, sin leer total/precio/moneda del cliente.
6. Crear Checkout y todas las relaciones en una transacción; commit solo al completar todo.

Respuesta `201`:

```json
{
  "success": true,
  "data": {
    "checkout_id": "chk_...",
    "status": "pending",
    "currency": "CLP",
    "total_amount": "15000.00",
    "order_count": 2,
    "expires_at": "2026-07-11T15:30:00-04:00",
    "created_at": "2026-07-11T15:15:00-04:00"
  }
}
```

Errores: `400 invalid_json|invalid_public_id`; `401 owner_session_required`; `404 order_not_found` solo cuando no filtra ownership; preferentemente `404 resource_not_found` para ajenos; `409 order_already_attached|checkout_state_conflict`; `422 empty_checkout|orders_not_payable|mixed_owners|mixed_currency|expired_reservation|invalid_total`; `500 persistence_error` controlado.

### 9.2 Iniciar o recuperar Payment Session

`POST /payments/session`

Header: `Idempotency-Key: 16-128-caracteres`.

Body exacto:

```json
{"checkout_id": "chk_..."}
```

El servicio valida formato antes de DB, resuelve owner, recupera Checkout con ownership, bloquea Checkout/Orders, evalúa expiración/estado, verifica reservas sin modificarlas, recalcula suma/moneda y crea o recupera idempotentemente. No llama a proveedor.

Respuesta de creación `201` o recuperación `200`:

```json
{
  "success": true,
  "data": {
    "payment_session_id": "ps_...",
    "checkout_id": "chk_...",
    "status": "pending",
    "provider": null,
    "redirect_url": null,
    "currency": "CLP",
    "amount": "15000.00",
    "expires_at": "2026-07-11T15:30:00-04:00",
    "created_at": "2026-07-11T15:16:00-04:00",
    "reused": false
  }
}
```

No se devuelve `idempotency_key`, fingerprint, IDs internos ni metadata. Errores: `400 invalid_json|invalid_checkout_id|missing_idempotency_key|invalid_idempotency_key`; `401 owner_session_required`; `404 resource_not_found`; `409 idempotency_conflict|checkout_state_conflict`; `422 checkout_expired|checkout_not_payable|amount_mismatch|orders_not_payable`; `500 persistence_error`.

### 9.3 Recuperar Payment Session

`GET /payments/session/{payment_session_id}`

No recibe body, no crea ni actualiza nada y no requiere `Idempotency-Key`. Valida formato, consulta por `public_id` + owner y devuelve `200` con los mismos campos públicos anteriores, sin `reused`. Puede calcular que está vencida para la respuesta, pero la persistencia de expiración debe realizarla un servicio separado; GET permanece sin escritura.

Respuestas: `400 invalid_payment_session_id`; `401 owner_session_required`; `404 resource_not_found`; `500 internal_error`. Ownership cruzado siempre se oculta con `404`.

### 9.4 Recuperar Checkout

`GET /checkout/{checkout_id}` pertenece a 28.7.4.1 porque la recuperación tras respuesta perdida es parte del objetivo. Es estrictamente read-only, aplica ownership y devuelve:

```json
{
  "success": true,
  "data": {
    "checkout_id": "chk_...",
    "status": "payment_started",
    "currency": "CLP",
    "total_amount": "15000.00",
    "order_count": 2,
    "payment_session_id": "ps_...",
    "expires_at": "2026-07-11T15:30:00-04:00",
    "created_at": "2026-07-11T15:15:00-04:00",
    "updated_at": "2026-07-11T15:16:00-04:00"
  }
}
```

`payment_session_id` es `null` si no existe una sesión recuperable. No devuelve IDs ni detalles internos de Orders en este hito. Usa `400`, `401`, `404` y `500` con las mismas reglas.

## 10. Códigos HTTP y no enumeración

| Código | Uso |
| --- | --- |
| `200` | Recuperación o reutilización exitosa. |
| `201` | Checkout o Payment Session creados. |
| `400` | JSON, header o identificador malformado. |
| `401` | No hay autenticación ni sesión invitada confiable. |
| `403` | Actor conocido sin una capability adicional; no usar para ownership cruzado. |
| `404` | Inexistente o no visible para el owner. |
| `409` | Clave/fingerprint, asociación concurrente o transición de estado en conflicto. |
| `422` | Entrada sintáctica válida que incumple negocio. |
| `500` | Error interno controlado, sin traza. |

Mensajes, latencia y envelope para inexistente y ajeno deben ser equivalentes. No se hacen consultas con un ID malformado.

## 11. Arquitectura interna

Estructura propuesta, coherente con módulos actuales:

```text
app/Modules/Checkout/{Models,Repositories,Service,Requests,Controller,Routes}
app/Modules/Payments/{Models,Repository,Service,Requests,Controller,Routes,Gateway}
app/Database/{Schemas,Migrations}
tests/{integration,manual}
```

Responsabilidades:

- `CheckoutService`: orquesta creación/recuperación, reglas de Orders, monto, moneda, expiración y transición de Checkout; no emite SQL ni conoce HTTP.
- `CheckoutRepository`: CRUD y locks de Checkout, queries siempre compatibles con `OwnerContext`, y frontera transaccional compartida.
- `CheckoutOrderRepository`: inserta/consulta relaciones normalizadas, bloquea y detecta asociaciones conflictivas.
- `PaymentSessionService`: inicia/reutiliza/recupera sesiones, valida estado y coordina idempotencia; no lee Cart ni confirma pagos.
- `PaymentSessionRepository`: persiste sesión y busca por clave, activa o public ID con ownership indirecto por join a Checkout.
- `IdempotencyService`: valida/normaliza clave, construye JSON canónico, calcula/compara fingerprint y decide replay/conflicto.
- `PaymentGatewayInterface`: límite hacia proveedores futuros. No se invoca durante 28.7.4.1.
- Models/DTOs: `OwnerContext`, `Checkout`, `PaymentSession`, `PaymentSessionContext`, `GatewaySessionResult`; no contienen acceso global a WordPress.
- Requests: JSON estricto, headers, formato de IDs; no hacen ownership ni reglas de negocio.
- Controllers: traducen resultados/excepciones a envelope estable.
- Routes: registran namespace, método y permission callback; resuelven autenticación/nonce/sesión.
- Schemas/Migrations: solo estructura e índices; no contienen negocio.

Las rutas administrativas actuales `/payments`, `/payments/{id}`, `/payments/{id}/session` y `/payments/confirm` no se deben reutilizar como fachada pública: tienen IDs y permisos incompatibles. La ruta estática `/payments/session` debe registrarse antes de patrones dinámicos para evitar colisiones.

## 12. Abstracción del proveedor

Contrato conceptual futuro:

```php
interface PaymentGatewayInterface
{
    public function createSession(
        PaymentSessionContext $context
    ): GatewaySessionResult;

    public function recoverSession(
        string $providerSessionId
    ): GatewaySessionResult;
}
```

La interfaz vigente tiene firma distinta y confirmación incluida. La evolución debe versionar/adaptar la interfaz o refactorizar sus consumidores en 28.7.4.2; cambiarla en 28.7.4.1 rompería `PaymentSessionService`, `PaymentConfirmationService` y Dummy.

Durante 28.7.4.1 no hay llamadas externas: `provider` queda preferentemente `NULL` (`internal` es admisible si se necesita distinguir filas), `provider_session_id` y `redirect_url` quedan `NULL`, y la sesión permanece `pending`. No se generan URLs falsas.

Decisión: no implementar `NullPaymentGateway` en este hito. Crear una clase que simule creación/recovery agregaría comportamiento ficticio y acoplaría la foundation a una interfaz aún no consolidada. `PaymentSessionService` debe persistir la sesión interna sin resolver ni invocar gateway. Un gateway simulado/controlado se evalúa en 28.7.4.2.

## 13. Transacciones y concurrencia

### Checkout

1. Iniciar transacción InnoDB.
2. Resolver/bloquear Orders y reservas relevantes en orden ascendente para minimizar deadlocks.
3. Verificar owner, estados, expiración, ausencia de asociación y monto.
4. Insertar Checkout y todas las `checkout_orders`.
5. Verificar cardinalidad y commit.
6. Ante cualquier excepción, rollback; nunca borrar compensatoriamente filas ya visibles como sustituto de atomicidad.

La creación de Orders/reservas y Checkout debe incorporarse a una unidad transaccional real. Si servicios existentes usan conexiones compatibles pero realizan compensación, se requiere refactor previo y pruebas de rollback.

### Payment Session

1. Iniciar transacción y obtener Checkout con owner mediante `SELECT ... FOR UPDATE`.
2. Leer/bloquear Orders asociados, recalcular contexto y fingerprint.
3. Buscar `(checkout_id, idempotency_key)` y aplicar replay/conflicto.
4. Buscar una sesión activa recuperable; reutilizarla si existe.
5. Insertar la sesión `pending`; actualizar Checkout de `pending` a `payment_started` con estado esperado.
6. Commit y releer respuesta pública.

Los índices únicos son la última barrera. En duplicate key se hace rollback/relectura, no retry ciego. Los deadlocks pueden reintentarse un número pequeño y acotado con jitter, manteniendo la misma clave. Como no existe llamada externa, una respuesta HTTP perdida se recupera por la clave o por GET y no deja ambigüedad externa. Está prohibido confirmar una escritura parcial.

## 14. Reglas de negocio

- Checkout contiene al menos una Order.
- Todas las Orders pertenecen exactamente al mismo owner del Checkout.
- Una Order no pertenece a dos Checkouts; `UNIQUE(order_id)` lo garantiza incluso si están terminales.
- Todas las Orders usan una moneda única. Hasta añadir moneda a Orders, solo `CLP` configurado server-side es válido.
- Total y moneda se calculan/verifican solo en backend; frontend no envía precios, subtotales ni total.
- Orders deben estar `reserved`, con reservas completas, activas y vigentes al crear Checkout y al iniciar sesión.
- No se inicia sesión para Checkout expirado o cancelado.
- `expires_at` de Payment Session nunca supera el del Checkout.
- Este hito no modifica Orders, no crea Delivery, no consume/libera/extiende reservas y no altera stock/inventario.
- La transición de Checkout es bookkeeping propio y no equivale a que un pago haya sido intentado externamente.

## 15. Seguridad

- Usuarios autenticados: cookies REST de WordPress con `X-WP-Nonce` válido cuando se use autenticación por cookie; el ID se deriva del contexto, no del body.
- Invitados: sesión PHP segura (`Secure`, `HttpOnly`, `SameSite` apropiado, regeneración y HTTPS), identificador CSPRNG y resolver server-side. No confiar en query/header/body arbitrarios.
- Sanitización no reemplaza validación: JSON estricto, allowlists, límites de longitud, regex ancladas y tipos exactos.
- Todas las lecturas y escrituras incluyen ownership; joins desde Payment Session hasta Checkout evitan IDOR.
- IDs opacos reducen enumeración, pero no autorizan.
- Respuestas 404 uniformes para inexistente/ajeno y logs sin diferencias visibles.
- Rate limiting por IP+owner+operación es recomendación para hardening futuro; no debe basarse solo en IP.
- No registrar datos de tarjeta, tokens, secretos, bodies completos ni session IDs. Nunca almacenar tarjetas ni secretos de proveedor.
- `metadata` usa allowlist y queda fuera de responses/logs.
- Excepciones se traducen a códigos controlados; nunca stack traces, SQL, fingerprints o IDs internos.
- Prepared statements en todos los queries; nombres de tabla solo desde Config/código.

## 16. Recuperación y escenarios

| Escenario | Resultado |
| --- | --- |
| Recarga de página | `GET /checkout/{id}` y luego GET de sesión recuperable. |
| Doble clic | Misma clave devuelve `200` y la misma sesión. |
| Timeout frontend / respuesta HTTP perdida | Repetir POST con la misma clave; no crea duplicado. |
| Misma clave, mismo fingerprint | Replay `200`. |
| Misma clave, fingerprint distinto | `409 idempotency_conflict`. |
| Clave distinta con sesión activa | Reutiliza sesión `200`, sin nueva fila. |
| Clave distinta sin sesión activa | Nueva sesión `201` solo si Checkout sigue pagable. |
| Sesión expirada | GET la informa como expirada; POST puede crear otra si Checkout sigue vigente. |
| Checkout expirado | `422 checkout_expired`; no hay escritura ni efectos colaterales. |
| Sesión existente recuperable | POST o GET devuelve la misma información pública. |
| Error DB antes de commit | Rollback total; retry seguro. |
| Commit exitoso y respuesta perdida | Replay/GET recupera la fila confirmada. |

## 17. Plan de pruebas

### Integración automatizada

- Crear Checkout de una Order y agrupar múltiples Orders.
- Ownership autenticado derivado del usuario; body con `user_id` rechazado/ignorado según request estricto.
- Ownership invitado desde sesión confiable; query/header/body falsificado rechazado.
- Acceso cruzado de usuario y sesión devuelve 404 y no filtra existencia.
- IDs públicos válidos, malformados y no existentes; los malformados no consultan DB.
- Orders inexistentes, duplicadas, de owners distintos, ya asociadas, no `reserved` o sin reservas vigentes.
- Checkout vacío, monedas mixtas y total decimal/overflow.
- Payload con total/precios manipulados rechazado; resultado siempre coincide con Orders.
- Misma clave/fingerprint recupera misma fila; mismo key/fingerprint distinto da 409.
- Clave nueva con activa reutiliza; expirada permite nueva solo con Checkout vigente.
- Dos o más requests concurrentes con misma clave crean una fila y respuestas equivalentes.
- Requests concurrentes con claves distintas crean como máximo una sesión activa.
- Recuperación tras fallo antes de insert, tras insert antes de commit y respuesta perdida tras commit.
- Expiración de sesión y Checkout con reloj controlado.
- Fallos en cada insert/update producen rollback sin Checkout/relaciones/sesión parciales.
- Snapshots antes/después prueban ausencia de cambios en Orders, Reservations, Inventory y Delivery.
- Gateway espía prueba cero llamadas externas y `redirect_url=NULL`.
- Responses no contienen metadata, IDs internos, session ID, fingerprint ni stack trace.
- Matriz HTTP 200/201/400/401/403/404/409/422/500.

### Pruebas manuales

- Autenticado e invitado: crear, recargar, consultar Checkout y sesión.
- Doble clic, dos pestañas, desconexión/reconexión y retry con misma/distinta clave.
- Cambiar de usuario/sesión y confirmar que el recurso deja de ser visible.
- Dejar vencer sesión/Checkout y confirmar mensajes/acciones permitidas.
- Inspeccionar DB para cardinalidad, timestamps, nulabilidad e índices.
- Revisar logs y tráfico: ninguna URL externa, dato sensible, correo o redirección.

## 18. Migración y compatibilidad

Orden de creación:

1. `checkouts`.
2. `checkout_orders`.
3. `payment_sessions`.

Se crearán clases Schema y una migración registrada en `MigrationManager` después de Orders/Reservations y antes de consumidores que las requieran. Los nombres usan `Config::TABLE_PREFIX`; `Config::SCHEMA_VERSION` aumenta y `Installer` cubre instalaciones nuevas y existentes.

`dbDelta` debe poder reejecutar la definición sin perder datos. La migración se prueba en base vacía, versión anterior y reejecución. Antes de declarar compatibilidad se inspeccionan realmente `SHOW CREATE TABLE` y `SHOW INDEX`, porque `dbDelta` puede normalizar o ignorar ciertas restricciones. No se añaden foreign keys sin probar orden, engine y upgrades existentes.

Compatibilidad:

- `POST /checkout/validate` no cambia.
- La ruta actual `POST /checkout` requiere evolución coordinada porque hoy crea Orders sin Checkout persistente y no admite invitados.
- Las rutas administrativas Payments permanecen separadas; no se exponen ni se reinterpretan IDs numéricos.
- `payments/payment_orders` existentes no se migran automáticamente a Payment Sessions: no existe evidencia suficiente para reconstruir Checkout/ownership invitado/idempotencia.
- El binding Dummy actual no participa del nuevo flujo.

Rollback documental: desactivar primero las nuevas rutas/servicios, conservar tablas para recuperación/auditoría y restaurar la versión de código. Eliminar tablas solo mediante una operación explícita, respaldada y en orden inverso (`payment_sessions`, `checkout_orders`, `checkouts`); nunca en rollback automático de despliegue.

## 19. Roadmap

- **28.7.4.1:** este diseño: persistencia, contratos, ownership, idempotencia, transacciones y recovery interno.
- **28.7.4.2:** gateway simulado o adaptador interno controlado, consolidación/versionado de `PaymentGatewayInterface`, sin proveedor real.
- **28.7.5:** integración del frontend con IDs públicos, claves persistidas en cliente y recovery.
- **28.7.6:** proveedor real, URLs externas autorizadas, secretos y adaptación idempotente.
- **28.7.7:** confirmación y webhooks autenticados/reconciliación.
- **28.7.8:** transición controlada de Orders a `paid`.
- **28.7.9:** creación de Delivery posterior al pago.

## 20. Decisiones de salida

- Checkout es el agregado persistente de una compra global; Orders conserva su rol por minimarket.
- Payment Session es una entidad separada, recuperable y sin dependencia de Cart.
- Ownership se deriva del usuario WordPress o de una sesión invitada confiable, nunca del payload.
- IDs públicos son opacos, generados por backend y validados antes de DB.
- Idempotencia se persiste por Checkout y se compara mediante fingerprint canónico.
- El inicio interno termina en `pending`, sin proveedor, URL, confirmación ni efectos colaterales.
- `GET /checkout/{id}` forma parte del hito por ser necesario para recuperación.
- No se implementa `NullPaymentGateway`; se difiere a 28.7.4.2 para evitar acoplamiento y comportamiento ficticio.
