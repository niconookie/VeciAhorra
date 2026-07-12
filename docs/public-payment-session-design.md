# Diseño de integración pública de sesión de pago (28.7.4)

## 1. Objetivo

Este documento registra el comportamiento **existente** de Payments y diseña, sin implementarla, una integración pública para iniciar o recuperar una sesión de pago después de que Checkout haya creado sus pedidos. Las afirmaciones de estado actual se basan en el código del repositorio; las decisiones futuras se rotulan como **Propuesta futura**.

## 2. Alcance y fuera de alcance

**Existente inspeccionado:** creación de Payment, asociación de varios Orders, sesión Dummy, confirmación, reservas, permisos REST y consultas del Customer Panel.

**Fuera de alcance:** modificar PHP/JavaScript/CSS/SQL, rutas, tablas, estados o permisos; integrar Webpay, Mercado Pago o Stripe; iniciar o confirmar pagos; crear Delivery; habilitar invitados.

La secuencia preservada es:

```text
28.7.2  validar checkout
28.7.3  crear checkout, Orders y Reservations
28.7.4  iniciar o recuperar sesión de pago
28.7.5  confirmar el resultado del pago
28.7.6  completar pedidos y habilitar Delivery
```

## 3. Archivos y componentes inspeccionados

- Payments: `PaymentRoutes`, `PaymentController`, `PaymentRequest`, `PaymentConfirmationRequest`, `PaymentService`, `PaymentSessionService`, `PaymentConfirmationService`, `PaymentRepository`, modelos, interfaz de gateway y `DummyPaymentGateway`.
- Persistencia: `CreatePaymentsTables`, `PaymentSchema`, `PaymentOrderSchema`, `OrderSchema`, `ReservationSchema`.
- Integraciones: `CheckoutService`, `OrderService`, `OrderRepository`, `ReservationService`, `ReservationExpirationService`, `CustomerPanelService` y sus rutas.
- Configuración: binding de `PaymentGatewayInterface` a `DummyPaymentGateway` en `Application`.
- Pruebas: `payment-foundation-test.php`, `payment-session-test.php`, `payment-confirmation-test.php`, `transactional-workflow-test.php` y pruebas relacionadas de Checkout, Orders y Reservations.
- Documentación contrastada: `payment-functional-design.md`, `checkout-functional-design.md`, `public-checkout-functional-design.md`, `customer-frontend-functional-design.md`, `transaction-flow.md` y `beta-readiness.md`.

Los documentos funcionales contienen objetivos futuros (por ejemplo `Idempotency-Key`, estados `expired` o gateways reales) que **no forman parte del contrato implementado** salvo cuando el código citado los materializa.

## 4. Estado actual de Payments

**Existente.** Hay dos operaciones distintas:

1. `POST /payments` crea una intención interna `pending` y la vincula a uno o varios pedidos.
2. `POST /payments/{payment_id}/session` solicita al gateway una sesión y persiste proveedor, referencia y expiración en el mismo Payment.

Todas las rutas de Payments (`GET`, creación, sesión y confirmación) usan `current_user_can('manage_options')`. No existe fachada pública de Payments. El único gateway enlazado es Dummy; no hay SDK, webhook ni adaptador productivo.

## 5. Contrato REST real

Namespace: `/wp-json/veciahorra/v1` (las rutas WordPress se registran bajo `veciahorra/v1`).

### 5.1 Crear Payment interno

**Existente:** `POST /wp-json/veciahorra/v1/payments`.

- Permiso: `manage_options`; un visitante o usuario sin capability recibe el error de autorización de WordPress, normalmente HTTP 401 o 403. No se implementa nonce propio; se aplican los mecanismos normales de autenticación REST de WordPress.
- Headers declarados por la ruta: ninguno. Para el body se espera JSON, pero la ruta no valida explícitamente `Content-Type`, JSON inválido ni objeto frente a lista: convierte `get_json_params()` a array.
- Body utilizado:

```json
{
  "customer_id": 42,
  "amount": "15000.00",
  "currency": "CLP",
  "provider": null,
  "order_ids": [101, 102]
}
```

| Campo | Regla implementada |
| --- | --- |
| `customer_id` | Obligatorio; entero positivo o cadena decimal canónica positiva. |
| `amount` | Obligatorio; entero, float finito o cadena decimal positiva; máximo 8 dígitos enteros y 2 decimales; normalizado a dos decimales. |
| `currency` | Opcional, default `CLP`; exactamente tres letras, normalizadas a mayúsculas. No se restringe realmente a CLP. |
| `provider` | Opcional/null; texto no vacío de hasta 50 bytes, normalizado a minúsculas. No hay allowlist. |
| `order_ids` | Obligatorio; lista no vacía de enteros positivos sin duplicados. |

Campos desconocidos no se rechazan: `PaymentRequest` simplemente no los usa. El cliente entrega `customer_id`, `amount` y `currency`, aunque el servicio vuelve a comprobar ownership, estado, reservas y suma de Orders.

Éxito: HTTP 201:

```json
{
  "success": true,
  "data": {
    "id": 7,
    "payment_reference": "PAY-...",
    "customer_id": 42,
    "amount": "15000.00",
    "currency": "CLP",
    "status": "pending",
    "provider": null,
    "provider_reference": null,
    "expires_at": null,
    "paid_at": null,
    "created_at": "...",
    "updated_at": "...",
    "order_ids": [101, 102]
  }
}
```

Errores propios: HTTP 422 `validation_error`; HTTP 500 `persistence_error` o `internal_error`. La autorización ocurre antes del callback. No hay 409 propio.

### 5.2 Iniciar sesión de pago

**Existente:** `POST /wp-json/veciahorra/v1/payments/{id}/session`, donde `{id}` es un `payment_id` decimal positivo según el patrón de ruta.

- Permiso: `manage_options`.
- Body: ninguno es leído ni validado. Headers propios: ninguno.
- Precondición: Payment existente y estado exactamente `pending`.
- Éxito: HTTP 200:

```json
{
  "success": true,
  "data": {
    "payment_id": 7,
    "status": "pending",
    "provider": "dummy",
    "provider_reference": "DUMMY-XXXXXXXX",
    "payment_url": "https://dummy.veciahorra/pay/DUMMY-XXXXXXXX",
    "expires_at": "2026-07-11T12:34:56"
  }
}
```

Errores: HTTP 404 `payment_not_found`; HTTP 422 `validation_error` para estado no pendiente o sesión inválida del gateway; HTTP 500 `persistence_error` o `internal_error`; además 401/403 de WordPress. No se define 201, 409 ni 429.

### 5.3 Consultas y confirmación relacionadas

- `GET /payments`, `GET /payments/{id}`: solo `manage_options`; devuelven datos completos, no filtran por propietario.
- `POST /payments/confirm`: solo `manage_options`; body exacto utilizado: `provider` (texto requerido, máximo 50) y `provider_reference` (texto requerido, máximo 191, patrón alfanumérico/guion/guion bajo). Confirma mediante gateway y aplica efectos terminales.
- `GET /me/orders` y `GET /me/orders/{id}`: requieren login y filtran Orders por usuario. El detalle incluye un resumen del Payment, pero no entrega `payment_url`, `provider_reference` ni una operación para recuperar sesión.

## 6. Modelo de datos real

### `payments`

Campos: `id`, `payment_reference` único, `customer_id`, `amount`, `currency`, `status`, `provider`, `provider_reference`, `expires_at`, `paid_at`, timestamps. `provider_reference` está indexado pero **no es único**. No existen `checkout_id`, `session_id`, `payment_session_id`, token público, URL persistida, return URL ni idempotency key.

### `payment_orders`

Relaciona `payment_id` con `order_id`. Tiene `UNIQUE(order_id)`, `UNIQUE(payment_id, order_id)` e índice por `payment_id`. Por tanto, un Payment puede agrupar varios Orders y cada Order solo puede pertenecer a un Payment a nivel de base de datos.

No se declaran foreign keys físicas en estos schemas. La integridad referencial adicional es lógica.

## 7. Relación con Checkout

**Existente.** Checkout agrupa ítems por `minimarket_id` y crea un Order por grupo. Una compra puede generar uno o varios pedidos. Devuelve esos Orders, pero no persiste una entidad global `checkout` ni un `checkout_id` que permita reconstruir con certeza el conjunto después de perder la respuesta.

Payments recibe `order_ids`, no carrito, reserva ni checkout. Puede representar múltiples Orders en un único Payment y suma sus totales. En consecuencia, el modelo permite un pago global para los pedidos conocidos, pero no puede demostrar que una lista arbitraria corresponde exactamente a un mismo checkout original.

**Brecha:** después de una respuesta ambigua de `POST /checkout`, no existe identificador agrupador recuperable con el cual iniciar el Payment de forma segura.

## 8. Relación con Orders

Al crear Payment, cada Order debe existir, estar `reserved` y tener `customer_id` igual al valor pedido. La suma de `orders.total` debe igualar `amount`. La creación del Payment no modifica Orders.

La confirmación exitosa marca todos los Orders asociados como `paid`. La sesión por sí sola no cambia su estado. `UNIQUE(payment_orders.order_id)` impide asociaciones persistidas con pagos distintos, incluso ante una carrera; una de las escrituras fallará.

**Brechas:** la ruta administrativa confía en un `customer_id` enviado en body y no deriva el comprador del actor actual. No valida que los Orders formen un checkout único, que compartan una ventana de creación o que la expiración registrada en todos coincida.

## 9. Relación con Reservations

Antes de crear Payment, cada Order debe tener al menos una Reservation y todas deben estar `active` con `expires_at > current_time('mysql')`. La creación y la sesión no consumen, liberan ni extienden reservas.

La confirmación exitosa exige nuevamente reservas activas/vigentes, confirma stock y cambia Reservations a `consumed`. Una confirmación fallida deja Orders `reserved` y Reservations `active`. El proceso independiente de expiración puede pasar reservas vencidas de `active` a `expired` y devolver stock; no cambia automáticamente Payment de `pending` a otro estado.

**Brecha:** la expiración de sesión Dummy se calcula desde `payment.created_at + 15 minutos`, no desde la reserva más temprana. Puede quedar desalineada con `orders.reservation_expires_at`.

## 10. Autenticación e invitados

**Existente:** Payments exige `manage_options`, no solo autenticación. No acepta sesión de carrito ni invitado. `PaymentSessionService` recibe únicamente `payment_id` y no verifica ownership; el aislamiento actual depende completamente del permiso administrativo de la ruta. Conocer un ID no basta para un usuario común porque el callback lo bloquea, pero cualquier administrador puede operar cualquier Payment.

Checkout exige usuario autenticado para crear Orders. Customer Panel sí usa `get_current_user_id()` y `findForCustomer`, pero no inicia ni recupera sesiones. No existe token público seguro para invitados.

**Propuesta futura:** la fachada pública debe exigir login, derivar `customer_id` exclusivamente de WordPress y responder 404 tanto para inexistencia como para falta de ownership para reducir enumeración.

## 11. Máquina de estados real

### Payments

```text
pending (inicial al crear Payment; crear sesión no cambia estado)
  ├─ confirmación positiva ─> paid   (terminal para confirmación)
  └─ confirmación negativa ─> failed (terminal para confirmación)
```

Solo `pending`, `paid` y `failed` aparecen en lógica ejecutable. `paid` y `failed` son idempotentes ante confirmación repetida. No hay transición implementada a `expired`, cancelación ni reactivación de `failed`. `expires_at` describe la sesión, no dirige una transición de Payment.

### Orders

Para este flujo: `reserved -> paid` únicamente en confirmación exitosa. Otros estados de otros módulos no forman parte del inicio de sesión.

### Reservations

Para este flujo: `active -> consumed` en confirmación exitosa; un proceso separado permite `active -> expired`. `released` existe como estado permitido, pero el inicio de sesión no lo aplica.

Dummy devuelve un resultado `paid` o `failed` al confirmar; esos valores no son un estado separado persistido de gateway. Checkout solo devuelve un resultado de orquestación y no tiene máquina persistida propia.

## 12. Efectos de iniciar una sesión

Orden exacto de `PaymentSessionService::create(paymentId)`:

1. Lee Payment y sus `order_ids`.
2. Falla si no existe o no está `pending`.
3. Llama `gateway.createPaymentSession(Payment)`.
4. Valida proveedor, referencia no vacía, URL válida y fecha `Y-m-d\TH:i:s`.
5. Actualiza `provider`, `provider_reference`, `expires_at` y `updated_at` si Payment sigue `pending`.
6. Devuelve datos de sesión, incluida una `payment_url` que **no se persiste**.

No crea un registro separado, no recalcula Orders/totales/reservas, no modifica Orders ni Reservations, no abre navegador, no confirma pago y no crea Delivery. Tampoco usa una transacción que incluya llamada externa y escritura. Si el gateway crea la sesión y luego falla la persistencia o la respuesta, queda un resultado externo ambiguo.

Dummy genera referencia determinista desde `payment_reference`, URL fija y expiración desde `created_at`; por eso las pruebas observan la misma referencia al repetir. La interfaz no obliga a que un gateway real sea idempotente.

## 13. Idempotencia y concurrencia

**Cubierto parcialmente en creación de Payment:** `findByOrderIds` reutiliza un Payment si los pedidos solicitados ya intersectan uno existente y `assertSamePayment` exige exactamente los mismos Orders, cliente, monto y moneda. La unicidad de `order_id` protege carreras en base de datos. No existe idempotency key explícita; conjuntos parciales/superpuestos producen error, no una recuperación formal.

**No cubierto suficientemente en sesión:** cada llamada `POST /payments/{id}/session` vuelve a invocar al gateway. No busca primero una sesión vigente ni persiste URL. Dummy es determinista, pero un gateway real podría crear múltiples sesiones o intenciones. Doble clic, replay, dos pestañas, F5 y concurrencia pueden invocar varias veces al proveedor. `provider_reference` no es único y `updateSessionData` permite sobrescribir una sesión pendiente.

**Confirmación:** está mejor protegida: consulta terminal temprana, transacción, lectura `FOR UPDATE`, actualización con estado esperado y resultados terminales idempotentes.

## 14. Recuperación

Administradores pueden consultar Payment por ID o listar pagos, y el repositorio puede buscar por referencia u Order; esas búsquedas no son fachadas públicas. Customer Panel muestra estado de pago asociado a un Order propio.

No existe endpoint público para recuperar por checkout, Orders o Payment; no se persiste `payment_url`; no existe token de recuperación; no hay operación para devolver una sesión vigente. Tras F5, cierre, timeout o otra pestaña, el usuario no puede recuperar de manera segura la URL. Repetir el POST vuelve a llamar al gateway.

**Bloqueo:** no debe conectarse el frontend público al inicio actual hasta disponer de creación/recuperación idempotente y autorizada.

## 15. Seguridad y escenarios adversos

| Escenario | Cobertura actual | Evidencia/riesgo |
| --- | --- | --- |
| Total manipulado | Parcial | Se compara con suma de Orders, pero cliente envía monto/moneda/cliente. |
| Pedido inexistente, no reservado, ajeno | Cubierto en creación interna | `assertPayableOrders`; no deriva actor REST. |
| Reserva vencida/inactiva | Cubierto al crear Payment y confirmar | No se revalida al iniciar sesión. |
| Order repetido en body | Cubierto | DTO lo rechaza. |
| Order en dos Payments | Cubierto | `UNIQUE(order_id)` y búsqueda previa. |
| Orders de checkout distintos | No cubierto | No existe `checkout_id`. |
| Doble clic/replay de sesión | No cubierto para gateway real | Se invoca gateway cada vez. |
| Dos sesiones concurrentes | No cubierto | Sin lock, clave ni reutilización previa. |
| Payment ya pagado/fallido | Cubierto | Sesión solo para `pending`. |
| Payment pendiente pero reservas expiradas | No cubierto al iniciar | Solo creación/confirmación comprueban reservas. |
| Enumeración/otro usuario | Parcial | Admin-only evita público; no hay ownership dentro de sesión. |
| Referencia de proveedor duplicada | No cubierto | Índice no único. |
| Respuesta ambigua del gateway | No cubierto | Sin recovery ni reconciliación. |
| Escritura local tras éxito externo falla | No cubierto | No hay compensación/consulta al gateway. |
| Callback repetido | Parcial | Confirmación local terminal es idempotente; no hay webhook firmado. |
| Confirmación antes del retorno | Parcial | Servicio tolera confirmación, pero no existe flujo público de retorno/consulta. |
| Token reutilizado | No aplicable todavía | No existe token público; referencia no debe convertirse en autorización. |
| Checkout parcial/inexistente | No cubierto | Payments desconoce entidad checkout. |
| Cobro doble | Riesgo no cubierto | Un gateway real podría crear operaciones múltiples para el mismo Payment. |

## 16. Brechas y bloqueos detectados

1. Todas las rutas Payments son administrativas; no hay autorización pública por propietario.
2. Checkout no persiste un identificador global recuperable para sus múltiples Orders.
3. La sesión no es idempotente por contrato y no reutiliza una sesión vigente antes de llamar al gateway.
4. `payment_url` no se persiste ni se puede recuperar.
5. `provider_reference` carece de unicidad.
6. Payment creation recibe cliente, monto, moneda y Orders desde el consumidor REST en vez de derivarlos server-side desde una referencia de checkout autorizada.
7. No se valida vigencia de Orders/Reservations al iniciar sesión ni se alinea expiración con la reserva mínima.
8. No existen expiración de Payment, conciliación, webhook firmado ni gateway productivo.
9. No hay recuperación segura después de timeout, F5 o cierre.

## 17. Diseño propuesto para la futura implementación 28.7.4

**Propuesta futura, no contrato actual.** Antes de implementar UI se requiere una operación pública atómica de **iniciar o recuperar**, por ejemplo una fachada dedicada bajo Checkout. Su nombre final debe decidirse al implementar; este documento no afirma que exista.

- Punto de inicio: únicamente después de un Checkout 28.7.3 confirmado y recuperable.
- Identificador canónico: un `checkout_id` opaco, persistido y ligado al usuario, preferible a exponer una lista manipulable de Orders. Si no se incorpora esa entidad, el backend deberá emitir un identificador opaco equivalente que resuelva el conjunto exacto de Orders.
- Ownership: derivar usuario de WordPress; nunca aceptar `customer_id` del navegador.
- Montos: derivar Orders y sumar `orders.total` server-side; fijar moneda server-side.
- Precondiciones: todos los Orders pertenecen al checkout/usuario, están `reserved`; Reservations completas, `active` y vigentes; no hay Payment incompatible.
- Payment: crear o recuperar un único Payment para el conjunto exacto. La unicidad de `order_id` sigue siendo defensa final.
- Idempotencia: clave opaca persistida por usuario+checkout y restricción única; bloquear el Payment durante inicio; si ya existe sesión vigente, devolverla sin llamar al gateway.
- Gateway: pasar una clave estable (`payment_reference`/idempotency key) al adaptador; exigir idempotencia/recovery en la interfaz productiva.
- Sesión: persistir solamente datos necesarios y seguros para recuperación, incluida URL o identificador regenerable, referencia única y expiración no posterior a la Reservation más temprana.
- Respuesta mínima: identificador público de Payment, `status`, proveedor abstracto, URL HTTPS autorizada, `expires_at`, indicador `created|reused` y hora del servidor. No devolver secretos ni IDs internos innecesarios.
- Sesión expirada: no reutilizarla; verificar primero Orders/Reservations. Crear una nueva solo mediante una transición/regla explícita e idempotente, nunca por reintento ciego.
- Timeout: el mismo GET/POST de recuperación debe devolver la sesión persistida; el frontend no debe generar una clave nueva automáticamente.
- Proveedor: el frontend consume una URL y estado neutrales; no conoce Webpay, Mercado Pago o Stripe.
- Redirección: ocurre solo tras respuesta confirmada. Iniciar no confirma, no marca Orders `paid`, no consume Reservations y no crea Delivery.

Si el proyecto decide no persistir `checkout_id`, debe documentar y probar cómo se recupera exactamente el conjunto completo de Orders sin aceptar listas arbitrarias; mientras eso no exista, es un bloqueo arquitectónico.

## 18. Integración futura con Customer Panel

**Propuesta futura:** extender una respuesta autorizada del panel para mostrar Payment público seguro, estado y expiración; recuperar una sesión `pending` vigente; permitir reinicio solo según regla server-side; impedirlo tras `paid`; y listar todos los Orders asociados verificando que pertenecen al usuario. Nunca exponer `provider_reference` como credencial ni usar parámetros de URL para autorizar.

## 19. Límites respecto de 28.7.5 y 28.7.6

28.7.4 solo inicia/recupera. La confirmación verificable corresponde a 28.7.5 y debe provenir del gateway, retorno seguro o webhook firmado, no de parámetros confiados del navegador. Marcar Orders pagados y consumir Reservations debe ocurrir una vez dentro de esa confirmación transaccional.

Delivery corresponde a 28.7.6, después de pago confirmado y finalización del pedido. Nunca se crea al iniciar sesión.

## 20. Criterios de aceptación para implementar 28.7.4

- Endpoint público definido, autenticado y con ownership probado.
- Identificador opaco recuperable resuelve exactamente todos los Orders del checkout.
- Cliente no envía monto, moneda ni customer ID autoritativos.
- Un Payment por conjunto y un Order como máximo en un Payment.
- Inicio/recovery idempotente ante doble clic, concurrencia, F5 y timeout.
- Sesión vigente se reutiliza sin nueva llamada externa.
- Expiración respeta Reservations y estados de Orders.
- Respuesta no filtra IDs internos, secretos ni referencias usadas como autorización.
- Ningún efecto sobre confirmación, Orders, consumo de Reservations o Delivery.
- Errores y respuestas ambiguas tienen recuperación verificable.
- Gateway real soporta clave estable, consulta y callbacks autenticados.

## 21. Estrategia de pruebas del futuro hito

1. Contrato: método, body mínimo, campos extra, permisos, ownership y respuestas.
2. Un checkout con uno y varios minimarkets produce un Payment con todos y solo sus Orders.
3. Suma/monto/moneda se derivan del servidor; manipulación del navegador no altera resultado.
4. Pedido ajeno, duplicado, pagado, inexistente o de otro checkout se rechaza sin revelar existencia.
5. Reservas vencidas/inactivas y expiración durante inicio se resuelven de forma definida.
6. Doble clic, replay, dos pestañas y solicitudes concurrentes llaman una sola vez al gateway.
7. F5, cierre y timeout recuperan la misma sesión/URL; no crean otra operación.
8. Fallo antes/después de la llamada externa y antes/después de persistir tiene reconciliación.
9. Sesión vigente se reutiliza; sesión expirada sigue la transición definida.
10. `paid` y `failed` bloquean inicios no permitidos.
11. URLs se validan contra HTTPS/proveedor y salidas se escapan.
12. No se llama a confirmación, no se marcan Orders `paid`, no se consumen Reservations y no se crea Delivery.
13. Customer Panel solo recupera pagos y Orders del usuario actual.
14. Pruebas de gateway contractuales para Dummy y cada adaptador productivo, incluyendo idempotencia y consulta.

