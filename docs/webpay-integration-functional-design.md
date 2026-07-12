# VeciAhorra 28.8 — Diseño funcional de integración Webpay Plus

## 1. Objetivo

Definir la integración de Transbank Webpay Plus como una implementación adicional de la abstracción de pagos construida en 28.7.4.x. La incorporación del proveedor real no debe rediseñar Checkout, PaymentSession, los contratos REST ni las reglas del dominio.

`PaymentSessionService` continúa dependiendo únicamente de `PaymentGatewayInterface`; el contenedor elige entre `MockPaymentGateway` y `WebpayPaymentGateway`. El Mock permanece disponible para desarrollo y pruebas.

Este documento es exclusivamente de diseño. No instala el SDK, no añade credenciales y no modifica código, rutas, frontend, migraciones ni tablas.

## 2. Alcance

El diseño cubre:

- Arquitectura y responsabilidades de `WebpayPaymentGateway`.
- Adaptación del contrato neutral a Webpay Plus.
- Creación de transacción y persistencia del token.
- Inicio seguro del POST de redirección a Webpay.
- Retorno del navegador, `commit` y consulta `status`.
- Confirmación idempotente del resultado local.
- Estados y mapeo entre Webpay y PaymentSession.
- Ambientes Integración y Producción.
- Credenciales y URLs.
- Errores, reintentos, seguridad, casos límite y pruebas.
- Evolución hacia múltiples proveedores.

La propuesta se basa en el contrato real vigente del repositorio:

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

## 3. Fuera de alcance

Quedan fuera de 28.8:

- Webpay Plus Mall, Webpay Diferido, Oneclick y Transacción Completa.
- Mercado Pago, Stripe u otros proveedores.
- Reembolsos, anulaciones, reversas y captura diferida.
- Guardar tarjetas o datos bancarios.
- Cambiar el modelo Checkout o su flujo de creación.
- Cambiar el modelo o la tabla PaymentSession.
- Cambiar los contratos de `POST /payments/session`, `GET /payments/session/{id}`, `POST /checkout` o `GET /checkout/{id}`.
- Cambiar el frontend, salvo iniciar la navegación hacia el proveedor y presentar el resultado recuperado.
- Crear Delivery o modificar Orders, Reservations e Inventory antes de una confirmación válida.
- Definir en detalle el hito posterior que marca Orders como `paid`.

## 4. Principios arquitectónicos

1. `PaymentSessionService` depende únicamente de `PaymentGatewayInterface` para crear o recuperar sesiones.
2. `WebpayPaymentGateway` es una implementación adicional de esa interfaz.
3. `MockPaymentGateway` sigue existiendo y es el adaptador por defecto de desarrollo y pruebas aisladas.
4. El dominio no importa clases, constantes, tokens ni estados de Transbank.
5. Checkout y PaymentSession permanecen idénticos.
6. Los contratos REST existentes permanecen idénticos.
7. El frontend no selecciona proveedor ni interpreta estados Webpay.
8. La composición de dependencias es el único lugar que conoce la implementación activa.
9. El token Webpay identifica una transacción, pero no reemplaza ownership ni autorización local.
10. Crear una sesión, confirmar una transacción y aplicar efectos de negocio son operaciones separadas e idempotentes.

## 5. Arquitectura propuesta

```text
Cliente
    │
    ▼
Checkout
    │
    ▼
PaymentSessionService
    │
    ▼
PaymentGatewayInterface
    ├────────────────────────┐
    ▼                        ▼
MockPaymentGateway     WebpayPaymentGateway
                               │
                               ▼
                        Transbank Webpay Plus
```

El dominio depende de la interfaz, nunca de una clase concreta:

```text
REST existente
    │
    ▼
PaymentController
    │
    ▼
PaymentSessionService ──> PaymentGatewayInterface
                                  ▲
                                  │ binding
                    ┌─────────────┴─────────────┐
                    │                           │
                  Mock                       Webpay
```

El retorno incorpora una entrada técnica adicional sin cambiar las rutas REST existentes:

```text
Webpay ── navegador ──> WebpayReturnController
                              │
                              ▼
                    PaymentConfirmationService
                              │
                              ▼
                 abstracción neutral de confirmación
                              │
                              ▼
                    WebpayPaymentGateway.commit
```

La URL de retorno es imprescindible para Webpay. Debe ser un endpoint técnico del backend, separado de la API pública existente. Su adición futura es compatible con la regla “no modificar contratos REST existentes”: no cambia método, request ni response de las cuatro operaciones públicas ya disponibles.

## 6. Componentes involucrados

### Componentes que permanecen sin cambios

- `CheckoutService`, Checkout y `checkout_orders`.
- Modelo y tabla `payment_sessions`.
- `IdempotencyService`.
- Ownership por usuario/sesión.
- `POST /checkout` y `GET /checkout/{checkout_id}`.
- `POST /payments/session` y `GET /payments/session/{payment_session_id}`.
- Envelope JSON y nombres de campos.
- Orders, Reservations, Inventory y Delivery durante la creación de sesión.

### Componentes nuevos o adaptados en una implementación posterior

- `WebpayPaymentGateway`.
- `WebpayConfiguration` inmutable.
- Cliente/SDK Transbank encapsulado.
- DTO neutral para confirmación.
- `WebpayReturnController` y entrada técnica de retorno.
- Launch endpoint interno para construir el POST `token_ws`.
- Servicio de confirmación/reconciliación idempotente.
- Binding de producción para `PaymentGatewayInterface`.
- Logs estructurados, métricas y correlation ID.

## 7. `WebpayPaymentGateway`

### 7.1 Responsabilidades

Debe:

- Implementar completamente `PaymentGatewayInterface`.
- Traducir `PaymentSessionContext` a `buy_order`, `session_id`, `amount` y `return_url`.
- Invocar la creación de Webpay Plus mediante el SDK oficial.
- Validar token y URL devueltos.
- Traducir creación/consulta a `GatewaySessionResult`.
- Encapsular ambiente, credenciales, timeout y excepciones del SDK.
- Consultar el estado remoto por token para recuperación.
- Implementar, además, una abstracción neutral de confirmación para ejecutar `commit` en el retorno.

No debe:

- Consultar Cart, Orders, Reservations u ownership.
- Persistir directamente en tablas VeciAhorra.
- Marcar Orders como `paid`.
- Crear Delivery.
- Construir responses REST.
- Leer proveedor, monto o escenario desde el navegador.
- Exponer API Key o Commerce Code.

### 7.2 Relación con `PaymentGatewayInterface`

```php
final class WebpayPaymentGateway implements PaymentGatewayInterface
{
    public function createSession(
        PaymentSessionContext $context
    ): GatewaySessionResult;

    public function recoverSession(
        string $providerSessionId
    ): GatewaySessionResult;
}
```

`createSession()` adapta la operación `Transaction::create()`. La documentación oficial muestra que la creación recibe orden de compra, ID de sesión, monto y URL de retorno, y entrega `token` y `url`. [Ejemplo oficial PHP de creación Webpay Plus](https://proyecto-ejemplo-php.transbankdevelopers.cl/webpay-plus/create)

`recoverSession()` adapta `Transaction::status(token)`, no `commit`. Transbank indica que `status()` permite consultar el resultado ante errores inesperados y que la consulta está disponible hasta siete días desde la creación. [Referencia oficial de operaciones Webpay Plus](https://proyecto-ejemplo-php.transbankdevelopers.cl/api-reference/webpay-plus)

### 7.3 Brecha de confirmación

La interfaz actual modela creación y recuperación, pero `commit(token)` es una operación distinta, necesaria cuando Webpay devuelve al cliente. No debe sobrecargarse semánticamente `recoverSession()` para confirmar.

La solución recomendada es que `WebpayPaymentGateway` implemente también una interfaz neutral y separada, por ejemplo:

```php
interface PaymentConfirmationGatewayInterface
{
    public function confirmSession(
        string $providerSessionId
    ): GatewayConfirmationResult;
}
```

Esto conserva `PaymentSessionService -> PaymentGatewayInterface` y mantiene la confirmación fuera del inicio de sesión. El dominio recibe un resultado neutral; solo el adaptador sabe que la operación concreta se llama `commit`.

## 8. Adaptación de datos

| VeciAhorra | Webpay Plus | Regla |
| --- | --- | --- |
| `paymentSessionId` | `buy_order` | Derivar un valor único, estable y de hasta 26 caracteres; no enviar el ID completo si excede el límite. |
| `checkoutId` / sesión local | `session_id` | Derivar referencia opaca de hasta 61 caracteres; no usar el token de sesión invitada. |
| `amount` | `amount` | CLP entero; rechazar fracciones distintas de `.00`. Nunca usar float. |
| Configuración backend | `return_url` | HTTPS, absoluta y allowlisted. |
| respuesta `token` | `provider_session_id` | Persistir el token completo en la columna existente. |
| respuesta `url` | dato del launch | Validar host/HTTPS; no confiar ciegamente. |
| `provider` | — | Guardar `webpay_plus`. |

Transbank documenta máximos de 26 caracteres para `buy_order` y 61 para `session_id`; su referencia de estado también enumera los estados remotos relevantes. [Referencia oficial Webpay Plus](https://proyecto-ejemplo-php.transbankdevelopers.cl/api-reference/webpay-plus)

### Generación de `buy_order`

Debe ser:

- Único por PaymentSession.
- Estable ante retry.
- No predecible como mecanismo de autorización.
- Compatible con el largo/formato permitido por Transbank.
- Recuperable localmente sin exponer IDs internos.

Ejemplo conceptual: prefijo de ambiente + digest Base32 truncado del `paymentSessionId`. La asociación canónica sigue siendo la fila PaymentSession y su token; `buy_order` no reemplaza el ID público.

## 9. Flujo completo de pago

### 9.1 Creación de la transacción

1. El cliente crea o recupera Checkout con el flujo existente.
2. Envía `POST /payments/session` con `checkout_id` e `Idempotency-Key`.
3. `PaymentSessionService` valida ownership, estado, expiración, Orders, Reservations, monto e idempotencia.
4. Persiste o recupera la PaymentSession local.
5. Construye `PaymentSessionContext`.
6. El contenedor entrega `WebpayPaymentGateway` como `PaymentGatewayInterface`.
7. El adaptador ejecuta `create(buyOrder, sessionId, amount, returnUrl)`.
8. Valida respuesta y traduce a `GatewaySessionResult`.
9. El servicio persiste `provider=webpay_plus`, token, estado `ready`, expiración y URL de inicio controlada.
10. Devuelve exactamente el JSON existente.

La llamada externa no debe ejecutarse mientras se mantiene un lock de base de datos. Se recomienda el patrón:

```text
transacción A: validar + crear pending + commit
llamada Webpay: create con referencias estables
transacción B: lock de PaymentSession + persistir ready/token + commit
```

### 9.2 Obtención y persistencia del token

Webpay devuelve un `token` y una URL. El token se guarda como `provider_session_id`; nunca se registra completo en logs ni se expone en URLs propias. La URL se valida contra hosts oficiales del ambiente configurado.

El token no debe ir en metadata si ya existe `provider_session_id`. No se duplica en Cart, Checkout, cookies ni localStorage.

### 9.3 Inicio de la redirección

La documentación oficial inicia el formulario mediante un POST a la URL entregada por Transbank con un input oculto `token_ws`. No basta hacer `window.location = url`. [Flujo oficial de creación y formulario](https://proyecto-ejemplo-php.transbankdevelopers.cl/webpay-plus/create)

Para mantener el JSON REST sin añadir `token_ws`, `redirect_url` debe señalar un launch endpoint interno, por ejemplo conceptualmente:

```text
GET /veciahorra/payment-session/{public_id}/launch
```

Ese endpoint:

1. Verifica ownership y estado `ready`.
2. Lee token y URL desde backend.
3. Revalida expiración y allowlist del host Webpay.
4. Responde HTML mínimo con formulario `method=POST` hacia Webpay.
5. Incluye solo `token_ws` como campo oculto.
6. Aplica CSP estricta, `Cache-Control: no-store` y protección contra framing.
7. Autoenvía el formulario y ofrece botón accesible de respaldo.

Este es el único cambio funcional necesario en frontend: navegar a `redirect_url`. El frontend no conoce token, host real ni proveedor.

### 9.4 Retorno del cliente

Webpay devuelve el navegador a `return_url`. El handler debe admitir los parámetros documentados para flujo normal y retorno anómalo. La documentación de recuperación menciona `token_ws`, `TBK_TOKEN`, `TBK_ID_SESION` y `TBK_ORDEN_COMPRA`. [Recuperación oficial de transacción](https://proyecto-ejemplo-node.transbankdevelopers.cl/webpay-plus/commit)

Reglas:

- `token_ws` presente: candidato a flujo normal; buscar PaymentSession por token y ejecutar confirmación idempotente.
- Parámetros `TBK_*` sin `token_ws`: tratar como abandono/anulación/flujo incompleto; no ejecutar `commit` a ciegas.
- Request vacío o malformado: registrar evento sanitizado y mostrar resultado no confirmado.
- Nunca confiar en `buy_order`, `session_id`, monto o estado recibidos desde navegador; comparar con datos persistidos y respuesta firmada/autenticada del API Transbank.

El retorno no requiere que el usuario conserve la misma pestaña. La autorización se resuelve por token + relación local y después se aplica ownership para la vista final.

### 9.5 Consulta del resultado

`recoverSession(token)` llama `status(token)` para resolver:

- Timeout de creación con token ya persistido.
- Respuesta HTTP perdida.
- Commit ambiguo.
- Reconciliación manual o job.
- Retorno tardío.

`GET /payments/session/{id}` permanece read-only y devuelve el estado local; no debe efectuar una llamada externa oculta en cada lectura. La reconciliación es una operación interna explícita.

### 9.6 Confirmación del pago

1. El return handler valida forma y longitud del token antes de DB.
2. Recupera PaymentSession por `provider=webpay_plus` + token.
3. Si ya está terminal, devuelve el resultado local sin repetir efectos.
4. Bloquea/declara una confirmación en progreso con comparación de estado.
5. Ejecuta `commit(token)` exactamente una vez por intento lógico.
6. Verifica como mínimo token, `buy_order`, `session_id`, monto, estado y `response_code` contra el snapshot local.
7. Persiste el resultado neutral en una transacción.
8. Solo un resultado autorizado y consistente habilita el hito posterior que marca el pago exitoso.
9. El navegador es enviado a una página interna que consulta Checkout/PaymentSession; nunca se usa la respuesta del browser como fuente de verdad.

Confirmar la transacción Webpay no debe mezclar en la misma llamada externa cambios en Orders, Reservations o Delivery. Esos efectos se orquestan localmente, de forma idempotente y con estados esperados.

## 10. Estados y transiciones

### 10.1 PaymentSession

Estados ya definidos:

- `pending`: intención local creada o resultado externo ambiguo.
- `ready`: token Webpay creado y launch disponible.
- `expired`: sesión/token fuera de vigencia.
- `cancelled`: abandono/cancelación local reconocida.
- Reservados: `processing`, `succeeded`, `failed`.

Transiciones propuestas:

```text
pending ── create Webpay válido ──> ready
pending ── timeout ambiguo ───────> pending
ready   ── retorno token_ws ──────> processing
ready   ── vencimiento ───────────> expired
ready   ── abandono comprobado ───> cancelled
processing ── AUTHORIZED válido ──> succeeded
processing ── rechazo definitivo ─> failed
processing ── resultado ambiguo ──> processing
```

`succeeded` no se obtiene solo por recibir `token_ws`; requiere `commit`/consulta autorizada y validación completa. `failed` no debe utilizarse para timeouts recuperables.

### 10.2 Mapeo Webpay → PaymentSession

Transbank enumera `INITIALIZED`, `AUTHORIZED`, `REVERSED`, `FAILED`, `NULLIFIED`, `PARTIALLY_NULLIFIED` y `CAPTURED` en su operación de estado. [Referencia oficial de estados](https://proyecto-ejemplo-php.transbankdevelopers.cl/api-reference/webpay-plus)

| Estado Webpay | PaymentSession | Interpretación |
| --- | --- | --- |
| `INITIALIZED` | `ready` o `processing` | Creada, todavía no autorizada; depende de si ya ocurrió retorno. |
| `AUTHORIZED` + `response_code=0` + datos coincidentes | `succeeded` | Pago confirmado localmente. |
| `FAILED` | `failed` | Rechazo definitivo después de validar respuesta. |
| `REVERSED` | estado futuro de reversa | No degradar silenciosamente a `failed`; requiere modelo posterior. |
| `NULLIFIED` | estado futuro de anulación | Fuera de 28.8. |
| `PARTIALLY_NULLIFIED` | estado futuro parcial | Fuera de 28.8. |
| `CAPTURED` | `succeeded` para modalidad aplicable | Webpay Plus normal captura al autorizar; validar producto contratado. |
| timeout/sin respuesta | conservar `pending`/`processing` | Reconciliar, no duplicar ni declarar rechazo. |

El mapeo usa estado, `response_code` y consistencia de monto/orden/sesión. Ningún campo aislado basta.

## 11. Configuración

### 11.1 Objeto de configuración

`WebpayConfiguration` debe contener:

- `environment`: `integration` o `production`.
- `commerce_code`.
- `api_key`.
- `return_url` absoluta.
- Hosts permitidos de redirección/API.
- Timeouts de conexión/lectura.
- Versión/configuración del SDK.
- Identificador de ambiente para prefijos de `buy_order`.

Se valida al iniciar la aplicación. Una configuración incompleta falla cerradamente y no cae automáticamente a Mock en producción.

### 11.2 Ambiente Integración

- Usar el ambiente Integration del SDK y sus credenciales oficiales de prueba.
- Solo tarjetas y casos de prueba documentados.
- URLs de retorno HTTPS públicas o túnel controlado; nunca una URL compartida no autenticada.
- Datos claramente separados de producción.
- El ejemplo oficial PHP configura `Options` con API Key, Commerce Code y `ENVIRONMENT_INTEGRATION`. [Ejemplo oficial PHP](https://proyecto-ejemplo-php.transbankdevelopers.cl/webpay-plus/create)

No copiar valores de credenciales desde este documento: deben obtenerse de la documentación/portal oficial vigente al implementar.

### 11.3 Ambiente Producción

- Commerce Code y API Key entregados/habilitados para el comercio real.
- HTTPS obligatorio, dominio definitivo y return URL aprobada.
- Secretos gestionados fuera del repositorio y de WordPress options visibles.
- Rotación, acceso mínimo y procedimiento de emergencia.
- Activación solo después de certificación y checklist operacional.
- Binding explícito `PaymentGatewayInterface -> WebpayPaymentGateway`.

### 11.4 URLs

- `return_url`: backend técnico de VeciAhorra; no URL arbitraria del cliente.
- `redirect_url` REST: launch endpoint interno, no token Webpay.
- URL Webpay: usar la devuelta por SDK y validar esquema/host según ambiente.
- URL final del cliente: página interna de resultado que recupera estado local.

No concatenar token en query strings propios. Todas las páginas intermedias usan `no-store`.

## 12. Idempotencia

### Creación local

Se conserva el contrato existente:

- `Idempotency-Key` de 16–128 caracteres.
- Fingerprint por Checkout, owner, moneda, total y Orders.
- Índice único `(checkout_id, idempotency_key)`.
- Misma clave/fingerprint recupera la misma PaymentSession.
- Conflicto devuelve `409` antes de Webpay.

### Creación en Webpay

No se debe asumir que repetir `create()` con los mismos argumentos es idempotente en el proveedor. La estrategia local es:

1. Crear una única PaymentSession local.
2. Generar `buy_order` estable.
3. Persistir evidencia antes y después de la llamada.
4. Si se recibió token, nunca volver a crear: consultar `status(token)`.
5. Si el resultado fue ambiguo sin token, bloquear/reconciliar antes de decidir un nuevo `create`.
6. Serializar por PaymentSession mediante transición con estado esperado.

### Confirmación

- `commit(token)` se protege con lock/estado `processing` y registro de intento.
- Retornos duplicados devuelven resultado terminal local.
- Dos procesos concurrentes no ejecutan efectos de negocio dos veces.
- Si `commit` responde pero el commit local falla, `status(token)` resuelve la ambigüedad.
- Aplicar Orders `paid` y otros efectos pertenece a una unidad idempotente posterior.

## 13. Manejo de errores y reintentos

| Situación | Tratamiento |
| --- | --- |
| Validación local | `400/409/422` existente; no llamar Webpay. |
| Credenciales/configuración inválidas | Fallo de arranque o `500` controlado; no fallback silencioso. |
| Timeout al crear sin token | PaymentSession `pending`; retry/reconciliación acotada. |
| Token recibido, respuesta HTTP local perdida | Replay REST recupera fila `ready`. |
| Error temporal de API | Retry con backoff+jitter solo si la operación es segura. |
| `commit` ambiguo | Consultar `status(token)` antes de repetir. |
| Rechazo definitivo | Persistir `failed` tras validar respuesta. |
| Token vencido/flujo abandonado | `expired` o `cancelled`; no crear automáticamente otra sesión si Checkout expiró. |
| Respuesta inconsistente | `gateway_protocol_error`; no marcar éxito. |

Reintentos:

- Número acotado.
- Timeouts explícitos.
- Sin locks DB durante red.
- Métricas por operación/resultado.
- Circuit breaker como hardening futuro.
- Consulta de la página oficial de estado operacional durante incidentes, sin automatizar decisiones financieras únicamente desde ella. [Estado de servicios Transbank Developers](https://status.transbankdevelopers.cl/)

## 14. Seguridad

- API Key y Commerce Code nunca en Git, frontend, HTML, JSON o logs.
- Secretos desde variables de entorno/secret manager y acceso mínimo.
- HTTPS y validación TLS; no desactivar certificados.
- Allowlist distinta para hosts Integration y Producción.
- El token se persiste solo donde corresponde, se enmascara en logs y no funciona como autorización local.
- `return_url` fija desde configuración; nunca enviada por frontend.
- Validación estricta de método, content type, parámetros, longitudes y duplicados del retorno.
- Comparación de `buy_order`, `session_id`, monto y moneda contra snapshot local.
- Ownership antes de mostrar la vista final o permitir launch.
- Launch endpoint con CSP `form-action` limitada al host Webpay, `frame-ancestors 'none'`, `Referrer-Policy: no-referrer` y `Cache-Control: no-store`.
- Protección CSRF/nonce en acciones iniciadas localmente; el retorno Webpay se valida por token y resultado remoto, no por nonce de navegador.
- Rate limiting para launch, return y reconciliación.
- No almacenar PAN; Transbank puede entregar últimos dígitos, pero no son necesarios para autorizar ni deben exponerse por defecto.
- Stack traces y respuestas SDK nunca llegan al cliente.

## 15. Casos límite

- Doble clic antes de crear la sesión.
- Dos pestañas con la misma o distinta Idempotency-Key.
- Webpay crea token pero VeciAhorra pierde la respuesta.
- VeciAhorra persiste `ready` pero el cliente pierde el JSON.
- Launch repetido con el mismo token.
- Retorno normal duplicado.
- Retorno con `token_ws` y parámetros `TBK_*` incompatibles.
- Retorno solo con `TBK_TOKEN` por abandono.
- Retorno vacío por timeout.
- Usuario vuelve manualmente sin completar Webpay.
- Checkout/Reservations expiran mientras el usuario está en Webpay.
- Autorización llega después de expiración local.
- `commit` exitoso y escritura local fallida.
- `commit` timeout y `status=AUTHORIZED`.
- `AUTHORIZED` con monto, orden o sesión diferentes.
- Token asociado a otro owner.
- Token malformado o desconocido.
- URL devuelta con host no permitido.
- Cambio de ambiente con sesiones pendientes.
- Credenciales rotadas durante una transacción.
- Estado futuro `REVERSED`/`NULLIFIED` recibido por reconciliación.

Ante duda financiera, no crear otra transacción ni declarar fallo definitivo: conservar estado recuperable y reconciliar.

## 16. Plan de pruebas

### 16.1 Unitarias del adaptador

- Traducción exacta de contexto a create.
- `buy_order` único, estable y dentro del límite.
- `session_id` opaco y dentro del límite.
- CLP entero; rechazo de decimales no soportados.
- Configuración Integration/Production.
- Validación de token, URL, host y expiración.
- Mapeo completo de estados y `response_code`.
- Excepciones SDK a errores neutrales.
- Cero dependencia de Checkout, repositorios, HTTP REST o frontend.

### 16.2 Contract tests compartidos

Ejecutar la misma suite contra Mock y Webpay fake:

- `createSession()` devuelve `GatewaySessionResult` válido.
- `recoverSession()` conserva identidad y estado.
- Resultado nunca cambia monto/moneda/contexto.
- Reintentos no producen múltiples sesiones lógicas.
- `PaymentSessionService` no importa clases concretas.

### 16.3 Integración con SDK simulado

- Crear token y URL.
- Timeout antes/después de recibir token.
- Respuesta corrupta o incompleta.
- `status()` para todos los estados documentados.
- `commit()` autorizado, rechazado, duplicado y ambiguo.
- Verificación de monto, orden y sesión.
- Confirmación concurrente con un solo resultado local.

### 16.4 Ambiente oficial de Integración

- Compra aprobada con datos oficiales de prueba.
- Compra rechazada.
- Abandono y retorno anómalo.
- Timeout.
- Recarga/doble retorno.
- Consulta `status` después de error ambiguo.
- Verificación del POST `token_ws` y return URL HTTPS.
- Evidencia de que no se alteran Orders/Reservations/Delivery antes de confirmación.

### 16.5 Regresión VeciAhorra

- Contratos snapshot de los cuatro endpoints existentes idénticos.
- Checkout idéntico con Mock o Webpay fake.
- Modelo y schema PaymentSession sin cambios.
- Frontend solo navega al `redirect_url` interno.
- Ownership user/session e IDOR.
- Idempotencia y concurrencia existentes.
- Mock continúa pasando toda su suite sin red.
- Búsqueda estática: dominio sin imports Webpay.

### 16.6 Seguridad y operación

- Secret scanning.
- Logs sin token completo ni credenciales.
- CSP/headers del launch.
- Hosts no allowlisted rechazados.
- Return malformado y replay.
- Timeouts y límites de retry.
- Cambio accidental de Mock/Webpay por ambiente detectado.
- Prueba de rollback operacional sin perder sesiones pendientes.

## 17. Roadmap para múltiples proveedores

### 28.8.1 — Adaptador Webpay y configuración

- SDK encapsulado.
- `WebpayPaymentGateway` detrás de la interfaz.
- Fakes contractuales.
- Sin producción.

### 28.8.2 — Launch y retorno

- Launch POST seguro.
- Return handler técnico.
- Confirmación neutral y consulta status.
- Reconciliación de errores ambiguos.

### 28.8.3 — Certificación Integration

- Casos oficiales, observabilidad, seguridad y operación.
- Runbook de incidentes y rotación de secretos.

### 28.8.4 — Producción controlada

- Credenciales reales.
- Feature flag backend y despliegue gradual.
- Monitoreo de autorización, rechazo, timeout y latencia.

### Proveedores futuros

Para Mercado Pago, Stripe u otro proveedor:

- Implementar `PaymentGatewayInterface` y la abstracción neutral de confirmación.
- Añadir configuración y allowlist propias.
- Ejecutar la suite contractual compartida.
- Cambiar binding/feature flag backend.
- No modificar Checkout, PaymentSession, REST ni frontend.

La selección inicial debe ser configuración del comercio/entorno, no input del cliente. Si en el futuro coexisten proveedores, una factory backend puede seleccionar una implementación autorizada, manteniendo el dominio dependiente de interfaces.

## 18. Decisiones y conclusiones

- Webpay Plus se integra como adaptador, no como lógica de dominio.
- `PaymentSessionService` conserva su dependencia exclusiva de `PaymentGatewayInterface` para sesiones.
- `WebpayPaymentGateway` implementa creación y recuperación; la confirmación usa una interfaz neutral separada.
- Mock permanece para desarrollo, pruebas y contract testing.
- Checkout, PaymentSession y contratos REST existentes no cambian.
- El frontend solo inicia navegación al launch interno y muestra estado local.
- El launch interno es necesario porque Webpay exige POST de `token_ws`.
- `commit` y `status` no son equivalentes: commit confirma en retorno; status reconcilia.
- Un resultado ambiguo nunca habilita un segundo cobro.
- Orders, Reservations y Delivery solo cambian después de confirmación autorizada, consistente e idempotente en su hito correspondiente.

La integración se considera arquitectónicamente correcta si cambiar Mock por Webpay requiere sustituir bindings y configuración, mientras el dominio, Checkout, PaymentSession, los endpoints REST y el frontend —salvo el inicio de redirección— conservan sus contratos.
