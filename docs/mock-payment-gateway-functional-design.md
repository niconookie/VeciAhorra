# VeciAhorra — Diseño funcional de Mock Payment Gateway

## 1. Objetivo

Definir el comportamiento y la arquitectura de un `MockPaymentGateway` para probar el inicio y la recuperación de Payment Sessions sin integrar una pasarela real y sin modificar el contrato público construido en la foundation de pagos.

El Mock debe representar el primer adaptador concreto del nuevo límite de proveedores. Su propósito es permitir pruebas deterministas de éxito, rechazo y expiración, no simular con fidelidad comercial una pasarela ni confirmar pagos reales.

Este documento es exclusivamente de diseño. No implementa clases, rutas, migraciones, tablas, frontend ni llamadas externas.

## 2. Alcance

El diseño cubre:

- La abstracción estable `PaymentGatewayInterface` orientada a Payment Session.
- Responsabilidades de `PaymentSessionService` y del gateway.
- Contrato lógico de creación y recuperación de una sesión de proveedor.
- Comportamiento determinista de `MockPaymentGateway`.
- Simulación controlada de sesión preparada, rechazo y expiración.
- Idempotencia, recuperación, errores y seguridad.
- Integración futura mediante inyección de dependencias.
- Pruebas necesarias antes de reemplazar el Mock por Webpay.

## 3. Fuera de alcance

Quedan fuera:

- Webpay, Mercado Pago, Stripe o cualquier proveedor real.
- SDKs, credenciales, secretos, certificados o llamadas HTTP externas.
- Cobros, captura, autorización o devolución de dinero.
- Confirmación de pagos, webhooks y conciliación.
- Transiciones de Payment Session a `processing`, `succeeded` o `failed` por resultados financieros.
- Cambios de Orders a `paid`.
- Creación de Delivery.
- Consumo o liberación de Reservations.
- Cambios de Inventory o stock.
- Redirecciones externas reales.
- Modificaciones al frontend, endpoints REST, modelo de datos o migraciones.

## 4. Principios de diseño

Los siguientes principios son invariantes arquitectónicas:

1. `PaymentSessionService` depende únicamente de `PaymentGatewayInterface`.
2. El dominio no conoce `MockPaymentGateway`.
3. El dominio no conoce Webpay.
4. El dominio no conoce Mercado Pago.
5. El dominio no conoce Stripe.
6. El frontend nunca conoce ni selecciona el proveedor.
7. Los endpoints REST permanecen idénticos.
8. El modelo `PaymentSession` permanece idéntico.
9. El flujo de Checkout permanece idéntico.
10. Sustituir el proveedor es únicamente cambiar el binding de `PaymentGatewayInterface` mediante inyección de dependencias.
11. El Mock no contiene reglas de ownership, Checkout, Orders, Reservations ni idempotencia local.
12. El servicio no interpreta detalles específicos del proveedor; solo traduce un resultado tipado a la Payment Session persistida.
13. No se generan resultados aleatorios. Una misma entrada y configuración produce el mismo resultado observable.

## 5. Estado actual y brecha de compatibilidad

La foundation vigente contiene dos caminos que deben distinguirse:

- El flujo público `POST /payments/session` crea o recupera `va_payment_sessions` de forma transaccional e idempotente. Actualmente no invoca gateway y deja la sesión en `pending`, con `provider`, `provider_session_id` y `redirect_url` en `NULL`.
- El flujo administrativo heredado `POST /payments/{id}/session` usa el `PaymentSessionService` existente con `PaymentGatewayInterface` y `DummyPaymentGateway`, pero opera sobre el modelo anterior `Payment`. Su interfaz expone `createPaymentSession(Payment)`, `confirmPayment()` y `getProviderName()`; Dummy genera una URL ficticia.

El contrato heredado no debe convertirse silenciosamente en el contrato del Mock. La implementación posterior debe versionar o refactorizar la interfaz y adaptar el flujo administrativo de forma explícita. El objetivo final es una única frontera orientada a `PaymentSessionContext`, sin alterar las rutas públicas ni el modelo `PaymentSession`.

`DummyPaymentGateway` y `MockPaymentGateway` no son sinónimos:

- Dummy pertenece al flujo administrativo anterior y mezcla creación con confirmación simulada.
- Mock pertenece a la arquitectura nueva, no confirma pagos y solo produce/reconstruye sesiones controladas.

## 6. Arquitectura propuesta

```text
Checkout
     │
     ▼
PaymentSessionService
     │
     ▼
PaymentGatewayInterface
     │
     ├───────────────────────┬──────────────────────┐
     ▼                       ▼                      ▼
MockPaymentGateway     WebpayGateway          Otros gateways
                           (futuro)                 (futuro)
                                              ┌─────┴─────┐
                                              ▼           ▼
                                       Mercado Pago    Stripe
                                          (futuro)     (futuro)
```

El resto del sistema no cambia al sustituir el adaptador:

```text
REST Routes → Controller → PaymentSessionService → PaymentGatewayInterface
                                      │
                                      ├─ PaymentSessionRepository
                                      ├─ CheckoutRepository
                                      └─ IdempotencyService
```

Las rutas y controllers no resuelven el gateway. El contenedor de dependencias elige una implementación durante el bootstrap. Ningún `if ($provider === ...)`, factory basada en input del navegador ni import concreto del Mock debe aparecer en el dominio.

## 7. `PaymentGatewayInterface`

### 7.1 Responsabilidades

La interfaz debe:

- Recibir un contexto inmutable y validado por el dominio.
- Solicitar o construir una sesión en la implementación concreta.
- Recuperar una sesión previamente creada usando su identificador de proveedor.
- Devolver un resultado tipado y neutral.
- Traducir fallos propios a excepciones clasificables del gateway.

La interfaz no debe:

- Consultar Cart, Checkout, Orders o ownership.
- Calcular montos ni moneda.
- Crear el identificador público local de Payment Session.
- Persistir en tablas VeciAhorra.
- Controlar transacciones de base de datos.
- Implementar la idempotencia REST.
- Cambiar estados de Checkout, Orders, Reservations, Inventory o Delivery.
- Conocer objetos HTTP, controllers o responses REST.

### 7.2 Contrato lógico

Contrato conceptual recomendado:

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

`PaymentSessionContext` debe contener solo datos ya validados:

- Identificador público estable de la Payment Session.
- Identificador público de Checkout.
- Monto decimal canónico.
- Moneda en mayúsculas.
- Fecha máxima de expiración.
- Clave estable de idempotencia para el adaptador, derivada por backend.
- Metadata explícitamente permitida y no sensible, si fuese necesaria.

No debe incluir IDs internos, objetos de WordPress, tokens de sesión, datos de tarjeta, secretos ni payload REST sin filtrar.

`GatewaySessionResult` debe representar:

- `provider`: nombre técnico controlado por backend.
- `providerSessionId`: referencia opaca del adaptador.
- `status`: resultado neutral permitido, inicialmente `ready`, `rejected` o `expired` a nivel del resultado del gateway.
- `redirectUrl`: URL recuperable o `NULL`, según la estrategia segura del Mock.
- `expiresAt`: fecha no posterior a la del Checkout/Payment Session.
- Código y mensaje técnico controlado cuando el resultado no esté preparado.

El resultado del gateway no sustituye la máquina de estados del dominio. `PaymentSessionService` decide qué estados persistidos son válidos.

### 7.3 Independencia del proveedor

La interfaz no debe mencionar `token_ws`, `buy_order`, `session_id` de Webpay, preferencias de Mercado Pago, Payment Intents de Stripe ni nombres equivalentes. Esos conceptos pertenecen exclusivamente al adaptador real y se traducen al contrato neutral.

El frontend recibe la misma respuesta de VeciAhorra con independencia del adaptador:

```json
{
  "payment_session_id": "ps_...",
  "checkout_id": "chk_...",
  "status": "ready",
  "provider": "mock",
  "redirect_url": "...",
  "currency": "CLP",
  "amount": "15000.00",
  "expires_at": "2026-07-11 15:30:00",
  "created_at": "2026-07-11 15:16:00",
  "reused": false
}
```

La presencia de `provider` es informativa y no autoriza lógica condicional en el frontend.

## 8. `MockPaymentGateway`

### 8.1 Responsabilidades

El Mock debe:

- Implementar únicamente `PaymentGatewayInterface`.
- Generar una referencia estable a partir del contexto y una configuración local no secreta.
- Devolver resultados deterministas.
- Permitir escenarios de sesión preparada, rechazo y expiración.
- Recuperar exactamente el mismo resultado para una referencia conocida.
- Registrar solo eventos técnicos mínimos, sin datos sensibles.
- Garantizar cero tráfico de red.

No debe acceder a repositorios, modificar entidades de dominio, confirmar pagos, consumir reservas ni crear entregas.

### 8.2 Comportamiento esperado

Para el escenario normal:

1. Valida defensivamente el contexto recibido.
2. Deriva `provider_session_id` de una clave estable del backend, por ejemplo `MOCK-` más un digest truncado.
3. Calcula `expires_at` como el menor valor entre la duración configurada del Mock y la expiración entregada por el dominio.
4. Devuelve `provider=mock`, estado de resultado `ready` y datos recuperables.
5. Una llamada repetida con el mismo contexto devuelve la misma referencia y no crea una sesión lógica adicional.

El Mock no debe generar una URL que aparente pertenecer a un proveedor real. Si se necesita probar navegación en una fase posterior, `redirect_url` debe apuntar únicamente a una ruta interna controlada de simulación, habilitada por configuración y fuera de producción. Mientras esa ruta no exista, el resultado puede permanecer `ready` con `redirect_url=NULL`; el contrato REST no cambia.

## 9. Flujo completo

### 9.1 Checkout

Checkout conserva su flujo actual:

1. Valida Cart.
2. Crea Orders y Reservations.
3. Persiste Checkout y asociaciones.
4. Devuelve `checkout_id` público.

No conoce gateway ni escenario simulado.

### 9.2 `PaymentSessionService`

Al recibir `POST /payments/session`:

1. Valida ownership, estado, expiración, Orders, Reservations, total y moneda.
2. Bloquea el Checkout dentro de la transacción local.
3. Aplica `Idempotency-Key` y fingerprint.
4. Recupera una sesión activa si existe.
5. Crea la Payment Session local en estado `pending` cuando corresponde.
6. Construye `PaymentSessionContext` desde datos backend.
7. Invoca únicamente `PaymentGatewayInterface::createSession()`.
8. Valida el resultado neutral.
9. Persiste proveedor, referencia, URL permitida, expiración y transición válida.
10. Devuelve el mismo contrato REST actual.

La llamada al gateway no debe mantener locks de base de datos durante una operación externa futura. Para el Mock puede parecer inocua, pero la arquitectura debe prepararse para Webpay: persistir intención local, liberar transacción, invocar con clave estable y cerrar el resultado en una segunda transacción con comparación de estado.

### 9.3 Interfaz y Mock

`PaymentSessionService` no construye `MockPaymentGateway`. El contenedor inyecta la interfaz. El Mock recibe el contexto, selecciona el escenario configurado y devuelve `GatewaySessionResult`.

### 9.4 Retorno de la sesión

El controller y las routes no conocen el origen del resultado. Se conservan:

- `POST /wp-json/veciahorra/v1/payments/session`.
- `GET /wp-json/veciahorra/v1/payments/session/{payment_session_id}`.
- El header `Idempotency-Key`.
- El envelope `success/data` y `success/error`.
- Los IDs públicos, ownership y códigos HTTP existentes.

`GET` recupera datos persistidos; no llama al Mock para cada lectura. `recoverSession()` se reserva para reconciliación explícita cuando el estado local sea ambiguo, nunca como efecto lateral oculto de un GET público.

## 10. Ciclo de vida de una Payment Session

### 10.1 Estados y transiciones

Estados persistidos existentes:

- `pending`: fila local creada, resultado del adaptador aún no consolidado.
- `ready`: sesión preparada por el adaptador.
- `expired`: sesión fuera de vigencia.
- `cancelled`: cancelación local explícita futura.

Reservados para hitos posteriores: `processing`, `succeeded`, `failed`.

Transiciones permitidas con Mock:

```text
pending ── resultado preparado ──> ready
pending ── expiración ───────────> expired
ready   ── expiración ───────────> expired
pending ── cancelación futura ───> cancelled
ready   ── cancelación futura ───> cancelled
```

Un rechazo de creación no debe persistirse como `failed`, porque `failed` está reservado para el ciclo financiero futuro. La estrategia recomendada es conservar la sesión `pending` con un error técnico controlado y permitir retry/recovery, o cancelar explícitamente la sesión si el contrato de la fase lo decide. La elección final debe ser única y probarse antes de implementar; no se debe reutilizar `succeeded`/`failed` para simular cobros.

No existen transiciones a `succeeded`, Checkout `paid` ni Orders `paid` en esta fase.

## 11. Estrategia de simulación

La selección del escenario es configuración de entorno o fixture de prueba, nunca input público. Orden de preferencia:

1. Inyección de una política/fixture al construir el Mock en tests.
2. Configuración backend de desarrollo con allowlist.
3. Metadata interna generada por el test, nunca copiada desde el body REST.

### 11.1 Éxito técnico

“Éxito” significa solamente que la sesión fue preparada:

- Resultado `ready`.
- Referencia estable.
- Expiración válida.
- URL interna controlada o `NULL`.
- Payment Session pasa de `pending` a `ready`.
- No se confirma ni cobra nada.

### 11.2 Rechazo

El escenario de rechazo simula que el adaptador no puede preparar la sesión:

- Devuelve un resultado rechazado tipado o lanza `GatewayRejectedException`.
- La API entrega un error estable, por ejemplo `422 gateway_session_rejected`, si el rechazo es definitivo para ese contexto.
- No expone mensajes internos del Mock.
- No modifica Checkout, Orders, Reservations, Inventory o Delivery.
- La misma clave/contexto reproduce el mismo resultado y nunca crea duplicados.

### 11.3 Expiración

Dos variantes deben probarse:

- Expiración al crear: el Mock devuelve una sesión ya expirada para verificar rechazo seguro y ausencia de transición a `ready`.
- Expiración posterior: el Mock devuelve `ready` con una vida corta; el dominio la considera expirada al superar `expires_at`.

La hora debe inyectarse mediante un reloj controlable. No se usan `sleep`, azar ni comparaciones frágiles con el reloj real.

## 12. Idempotencia

La idempotencia sigue siendo responsabilidad de VeciAhorra:

- `IdempotencyService` valida la clave y calcula el fingerprint.
- El índice único `(checkout_id, idempotency_key)` evita duplicados locales.
- Misma clave y fingerprint recupera la misma Payment Session.
- Misma clave con fingerprint distinto produce `409 idempotency_conflict` antes de invocar gateway.
- Una sesión activa se reutiliza con clave nueva según el contrato vigente.

El gateway recibe además una clave estable para proteger la frontera externa. En el Mock, la referencia determinista demuestra esa propiedad. En Webpay, la estrategia deberá adaptarse a las capacidades reales del proveedor sin debilitar la idempotencia local.

Un retry tras respuesta perdida debe:

1. Recuperar primero la fila local por clave.
2. Si está `ready`, devolverla sin invocar gateway.
3. Si está `pending` y tiene `provider_session_id`, usar `recoverSession()`.
4. Si no hay evidencia de creación externa, reintentar `createSession()` con la misma clave estable.
5. Nunca crear una segunda sesión local para resolver ambigüedad.

## 13. Integración con `PaymentSession`

No se agregan ni eliminan columnas. Se usan los campos existentes:

| Campo | Uso con Mock |
| --- | --- |
| `public_id` | Identidad pública local, generada antes del gateway. |
| `checkout_id` | Relación inmutable con Checkout. |
| `idempotency_key` | Replay REST local. |
| `request_fingerprint` | Detección de conflicto. |
| `status` | `pending`, `ready`, `expired` o `cancelled`. |
| `provider` | `mock` cuando hay resultado consolidado. |
| `provider_session_id` | Referencia opaca estable del Mock. |
| `redirect_url` | Ruta interna controlada o `NULL`. |
| `currency` / `amount` | Snapshot backend inmutable. |
| `metadata` | `NULL` salvo necesidad explícita y no sensible. |
| timestamps / `expires_at` | Ciclo de vida local. |

El proveedor no puede alterar `checkout_id`, owner, monto ni moneda. Cualquier discrepancia invalida el resultado y se trata como error de integración.

## 14. Manejo de errores

Taxonomía recomendada:

- `GatewayValidationException`: contexto imposible generado por una brecha interna; respuesta pública controlada `500`, porque el cliente no puede corregirlo.
- `GatewayRejectedException`: el adaptador rechaza preparar la sesión; `422 gateway_session_rejected`.
- `GatewayUnavailableException`: fallo temporal; `503 gateway_unavailable` en una fase que incorpore proveedores externos.
- `GatewayTimeoutException`: resultado ambiguo; `503 gateway_timeout`, conservando sesión recuperable.
- `GatewayProtocolException`: respuesta inválida del adaptador; `500 gateway_protocol_error`.

El Mock debe permitir disparar estas categorías mediante fixtures. Los mensajes públicos son genéricos; detalles técnicos se registran con correlation ID y sin secretos. Una excepción nunca provoca escrituras parciales: la transición se confirma con estado esperado dentro de una transacción local.

## 15. Seguridad

- El escenario Mock no se acepta desde query, header o body público.
- El binding Mock solo se habilita en desarrollo, test o un entorno explícitamente autorizado.
- Producción debe fallar de forma segura si intenta arrancar con Mock, salvo una decisión operacional documentada.
- Una URL interna de simulación usa HTTPS cuando corresponda, ID opaco y ownership; no convierte `provider_session_id` en autorización.
- No se almacenan tarjetas, CVV, datos bancarios, secretos ni payloads completos.
- Logs no incluyen Idempotency-Key completa, token de sesión, metadata sensible ni stack traces públicos.
- El resultado se valida por allowlist: proveedor, estado, referencia, esquema/host de URL y expiración.
- El frontend no elige proveedor ni escenario y no interpreta referencias del Mock.
- `recoverSession()` no elude ownership: solo se llama después de que el servicio autoriza la Payment Session local.

## 16. Casos límite

- Dos requests simultáneos con la misma clave.
- Requests simultáneos con claves distintas para el mismo Checkout.
- Respuesta del Mock obtenida pero commit local fallido.
- Commit local exitoso y respuesta HTTP perdida.
- Retry cuando la sesión ya está `ready`.
- Sesión `pending` con referencia persistida y resultado ambiguo.
- Checkout o Reservations expiran durante la preparación.
- El Mock propone expiración posterior al Checkout: el servicio la limita o rechaza.
- Monto o moneda del resultado no coincide con el contexto.
- Referencia vacía, demasiado larga o duplicada.
- URL con esquema/host no permitido.
- Resultado desconocido o combinación incoherente (`ready` sin datos requeridos).
- Reloj en el límite exacto de `expires_at`.
- Cambio accidental de binding a Mock en producción.
- Reemplazo por Webpay sin cambiar rutas, responses, modelo ni frontend.

## 17. Plan de pruebas

### 17.1 Unitarias

- `PaymentSessionContext` rechaza datos incompletos o no canónicos.
- Mock preparado devuelve referencia y resultado deterministas.
- Mismo contexto produce el mismo `provider_session_id`.
- Contextos distintos no colisionan en fixtures razonables.
- Recuperación devuelve el mismo resultado creado.
- Fixtures de rechazo, expiración, timeout y protocolo inválido.
- Expiración nunca supera la fecha máxima del contexto.
- Cero llamadas de red.
- El reloj inyectado controla todos los escenarios.

### 17.2 Servicio e integración

- `PaymentSessionService` se construye con una implementación espía de la interfaz.
- El servicio no importa ni instancia `MockPaymentGateway`.
- Creación normal: `pending -> ready`, persistiendo campos existentes.
- Replay con misma clave no vuelve a llamar al gateway cuando está `ready`.
- Conflicto de fingerprint no llama al gateway.
- Doble request simultáneo crea una sola sesión local y una sola sesión lógica Mock.
- Respuesta perdida se recupera por POST idempotente y GET.
- Rechazo/timeout no produce cambios parciales.
- Expiración respeta Checkout y Reservations.
- Ownership cruzado se rechaza antes de invocar gateway.
- Snapshots prueban ausencia de cambios en Orders, Reservations, Inventory y Delivery.
- No existen transiciones a `succeeded`, Checkout `paid` u Orders `paid`.

### 17.3 Contrato REST

- Los cuatro endpoints actuales conservan método, ruta, request, response y códigos base.
- `POST /payments/session` sigue exigiendo `Idempotency-Key` y `checkout_id`.
- `GET /payments/session/{id}` es read-only y no llama al gateway.
- La respuesta no expone IDs internos, metadata ni detalles del escenario.
- Cambiar el binding de Mock por un gateway espía no cambia snapshots REST.

### 17.4 Arquitectura y seguridad

- Búsqueda estática: dominio sin imports de Mock, Webpay, Mercado Pago o Stripe.
- Frontend sin nombres de proveedor ni branching por `provider`.
- Configuración impide Mock en producción.
- Logs sanitizados y sin datos sensibles.
- Test de allowlist para URL, estado y proveedor devueltos.

## 18. Roadmap hacia Webpay

### Fase A — Consolidar contrato neutral

- Introducir DTOs `PaymentSessionContext` y `GatewaySessionResult`.
- Versionar/refactorizar la interfaz heredada sin romper el flujo administrativo.
- Inyectar interfaz en el flujo público.
- Mantener la sesión interna `pending` ante resultados ambiguos.

### Fase B — Implementar Mock

- Adaptador determinista, reloj inyectable y fixtures internos.
- Creación y recuperación sin red.
- Pruebas de estados, errores, idempotencia y concurrencia.
- Eventual ruta interna de simulación separada del contrato público, si fuese necesaria.

### Fase C — Preparar Webpay

- Implementar `WebpayGateway` detrás de la misma interfaz.
- Encapsular SDK, credenciales, timeouts y traducción de respuestas.
- Definir idempotencia/recovery según capacidades reales.
- Configurar allowlist de URLs y observabilidad.
- Ejecutar suite contractual compartida Mock/Webpay.

### Fase D — Confirmación

- Diseñar retorno, webhook/consulta y conciliación como flujo separado.
- Añadir estados financieros sin alterar la creación de sesión.
- Solo después habilitar Orders `paid`, consumo de Reservations y Delivery.

El cambio de Mock a Webpay debe reducirse al binding:

```php
PaymentGatewayInterface::class => WebpayGateway::class
```

No requiere cambios en Checkout, PaymentSession, REST, frontend ni reglas de ownership/idempotencia.

## 19. Conclusiones

`MockPaymentGateway` debe ser un adaptador de infraestructura pequeño, determinista y reemplazable. Su valor consiste en probar la frontera que usará Webpay sin introducir conceptos del proveedor en el dominio.

La estabilidad se obtiene manteniendo una única dirección de dependencia: `PaymentSessionService` conoce el contrato abstracto; el contenedor conoce la implementación; Checkout, REST y frontend no conocen ninguna pasarela. El Mock prepara o recupera una sesión técnica, pero no cobra, no confirma y no desencadena efectos posteriores.

La implementación futura queda aceptada solo si sustituir Mock por Webpay requiere cambiar la inyección de dependencias y configuración, mientras permanecen idénticos el flujo de Checkout, el modelo PaymentSession, los endpoints REST y el frontend.
