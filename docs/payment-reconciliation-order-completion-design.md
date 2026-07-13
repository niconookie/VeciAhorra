# VeciAhorra 28.7.4.6 — Payment Reconciliation & Order Completion

## Evidencia revisada en el repositorio

Este diseño se apoya en la implementación existente y no sustituye la base
financiera validada en 28.7.4.5:

- `WebpayPlusGateway`, en
  `app/Modules/Payments/WooCommerce/WebpayPlusGateway.php`, inicia el pago de
  un `WC_Order`, pero deliberadamente no llama `payment_complete()`, no cambia
  estados y no reduce stock. El repositorio no contiene hoy llamadas a
  `payment_complete()`, `add_order_note()`, `get_transaction_id()` ni escrituras
  de metadatos de conciliación WooCommerce.
- `WebpayReturnController`, `PaymentRoutes` y `WebpayReturnService`, en
  `app/Modules/Payments/{Controller,Routes,Service}`, reciben el retorno público,
  normalizan GET/POST, ejecutan un único `commit()` y validan monto,
  `buy_order` y `session_id`.
- `WebpayCommitResult` es la normalización directa del SDK y
  `WebpayReturnResult` es la respuesta del retorno. `NormalizedFinancialApproval`
  existe para la confirmación transaccional nativa, pero la ruta productiva de
  retorno aún no lo construye ni invoca `TransactionalPaymentConfirmationService`;
  ese puente pertenece a 28.7.4.6.
- En la instalación auditada,
  `../woocommerce/includes/class-wc-order.php::payment_complete()` dispara
  `woocommerce_pre_payment_complete`, valida estados mediante
  `woocommerce_valid_order_statuses_for_payment_complete`, guarda transaction
  ID y fecha pagada, filtra el estado con
  `woocommerce_payment_complete_order_status`, agrega su propia nota, guarda y
  dispara `woocommerce_payment_complete`; para estados no pagables dispara el
  hook específico del estado. Captura `Exception`, registra/agrega nota de error
  y devuelve false, por lo que el handler siempre debe inspeccionar después.
- `WebpayReturnContext` y `TransientWebpayReturnContextRepository`, en
  `app/Modules/Payments/{Gateway,Repository}`, resuelven el retorno WooCommerce
  por el hash SHA-256 del token sin depender de la sesión WooCommerce. El DTO
  actual conserva origen, ambiente, commerce code, referencias financieras,
  monto y expiración; todavía no conserva el ID interno del pedido ni moneda.
- `WooCommerceWebpayReturnGatewayResolver` resuelve credenciales/configuración
  del gateway para commit; no resuelve el WC_Order ni constituye todavía un
  resolver de origen de negocio. El checkout nativo se localiza actualmente por
  `PaymentSessionRepository::findByProviderSessionId()`.
- La sesión WooCommerce de ida sí conserva temporalmente `order_id`, order key,
  monto decimal normalizado, moneda, URL y token para renderizar el formulario
  POST. Esa sesión se consume antes del retorno y no es una fuente durable de
  conciliación.
- `va_webpay_returns`, definida por `WebpayReturnSchema`, tiene unicidad sobre
  `token_hash` y estados técnicos `processing`, `retryable` y `completed`. Su
  `result_status` distingue, entre otros, `approved`, `rejected`, `aborted` e
  `inconsistent`.
- El endpoint actual es REST público en
  `/veciahorra/v1/payments/webpay/return`, acepta POST y GET y devuelve JSON. No
  existe todavía página de resultado ni patrón POST/Redirect/GET.
- `NormalizedFinancialApproval`, `TransactionalPaymentConfirmationService`,
  `PaymentConfirmationTransaction` y `PaymentConfirmationAudit` ya implementan
  una confirmación transaccional estricta para el checkout nativo. Bloquean el
  agregado, validan relaciones y evidencia, marcan Payment y Orders, consumen
  Reservations y registran auditoría.
- Las relaciones nativas reales son `checkouts` → `checkout_orders` → `orders`
  y `payments` → `payment_orders` → `orders`. Una compra puede contener varias
  Orders, incluso de minimarkets distintos.
- `DeliveryService` solo crea una Delivery para una Order `paid`, pero
  `DeliverySchema` aún no declara `order_id` único. El método `pickup` o
  `delivery` aparece en el diseño/UI pública, no en `CheckoutSchema`,
  `OrderSchema` ni `DeliverySchema`; por tanto, hoy no existe una fuente
  persistente que autorice automáticamente crear una Delivery.
- Las pruebas relacionadas incluyen `webpay-return-foundation-test.php`,
  `webpay-return-rest-route-test.php`, `woocommerce-webpay-gateway-test.php`,
  `transactional-payment-confirmation-unit-test.php`,
  `transactional-payment-confirmation-integration-test.php` y
  `transactional-payment-confirmation-concurrency-test.php`.

## 1. Objetivo y alcance

El hito debe convertir un resultado financiero Webpay ya validado en un efecto
de negocio consistente, seguro, auditable e idempotente. Son cinco resultados
separados:

1. **Resultado financiero:** qué respondió Transbank y si superó la validación
   de autoridad financiera.
2. **Efecto WooCommerce:** si un `WC_Order` fue completado, ya estaba pagado o
   quedó pendiente de conciliación.
3. **Efecto VeciAhorra:** si Payment, PaymentSession, Orders y Reservations
   alcanzaron de forma atómica sus estados finales; la creación futura de
   Deliveries depende del método de cumplimiento persistido.
4. **Auditoría:** evidencia inmutable y sanitizada de cada intento y resultado.
5. **Presentación HTTP:** una respuesta segura para Transbank y una vista de
   resultado recargable para el navegador. Ninguna de ellas constituye por sí
   sola evidencia de pago.

Este documento define la implementación posterior. En este hito de diseño no
se ejecutan efectos de negocio ni se modifican esquemas.

## Arquitectura propuesta

```text
PaymentRoutes / WebpayReturnController
    → WebpayReturnService (claim, único commit, validación financiera)
    → persistencia durable del resultado validado
    → PaymentReconciliationService (claim/lease de negocio)
        ├── WooCommercePaymentCompletionHandler
        └── VeciAhorraCheckoutCompletionHandler
    → auditoría durable
    → respuesta técnica o redirect a resultado GET
```

`WebpayReturnService` sigue siendo la única frontera con Transbank. El
reconciliador nunca recibe el token completo y nunca llama el SDK. Los handlers
solo reciben un resultado financiero inmutable y un contexto de origen resuelto
en servidor.

## Secuencia financiera determinista

Los estados son independientes y no se reinterpretan entre sí:

```text
financial_processing       resultado financiero todavía no obtenido
financial_obtained         commit respondió, aún no conciliado internamente
financial_validated        respuesta completa y referencias coincidentes
financial_rejected         rechazo financiero válido
financial_inconsistent     respuesta no conciliable con contexto servidor

reconciliation_pending
reconciliation_processing
reconciliation_completed
reconciliation_retryable
reconciliation_permanent_failure
reconciliation_manual_review
```

La secuencia aprobada es:

1. `WebpayReturnRepository::claim()` reclama el token hash, como hoy.
2. `WebpayReturnService` ejecuta `WebpayReturnGatewayInterface::commit()` solo
   si no existe un resultado financiero terminal durable.
3. La respuesta pasa por `WebpayPaymentGateway::commitResult()` y por las
   comparaciones exactas de monto, `buy_order` y `session_id` del servicio.
4. Solo entonces se persiste `financial_validated` y el payload normalizado
   allowlist. Este write ocurre antes de adquirir el lease de negocio.
5. En la misma unidad SQL que crea/actualiza el registro durable se establece
   `reconciliation_pending`; aún no se ejecuta el handler.
6. Un claim atómico separado adquiere el lease y cambia a
   `reconciliation_processing`.
7. El handler ejecuta e inspecciona el efecto; finalmente persiste completed,
   retryable, permanent failure o manual review.

Solo `financial_validated` approved crea reconciliación pending. Rejected,
aborted e inconsistent terminan en la autoridad financiera sin handler.

Si el resultado se persiste y falla el handler, el resultado financiero sigue
siendo autoridad y el negocio queda retryable/manual review; el reintento no
llama `commit()`. Si el proceso cae después de recibir commit y antes de
persistirlo, el estado es `financial_unknown`: no se repite commit a ciegas. Se
requiere recuperación explícita mediante una consulta de estado soportada por
el proveedor o revisión manual. La semántica idempotente de un segundo commit
no se presume en este diseño.

`webpay_returns.processing_status = completed` continúa significando solamente
que el retorno financiero terminó. La autoridad futura de negocio será
`payment_reconciliations.reconciliation_status`; Payment, Orders, Delivery y
WC_Order son evidencias del efecto, no una reinterpretación de ese campo.

## Secuencia de conciliación

```text
financial_validated durable
    → localizar reconciliation por fingerprint
    → adquirir lease mediante UPDATE atómico
    → resolver origen durable
    → inspeccionar evidencia de efecto previo
    → ejecutar handler solo si sigue siendo necesario y seguro
    → releer recursos
    → registrar auditoría terminal
    → cerrar estado de conciliación mediante compare-and-set
```

Un segundo proceso que encuentre processing no espera ni ejecuta efectos:
devuelve pending con el identificador público. Uno que encuentre completed
devuelve el resultado durable. Un lease expirado se reclama atómicamente y
siempre comienza por inspección.

## 2. Principio de autoridad financiera

Un pago se clasifica como aprobado solo si se cumplen simultáneamente:

- `commit()` terminó correctamente;
- `status === 'AUTHORIZED'`;
- `response_code === 0`;
- el monto autorizado coincide exactamente con el monto entero CLP conservado;
- `buy_order` coincide mediante comparación segura;
- `session_id` coincide mediante comparación segura;
- el contexto servidor es válido, vigente y pertenece a un origen reconocido;
- el recurso de origen se resuelve desde ese contexto, no desde el navegador;
- la transacción no fue conciliada antes, o la repetición coincide exactamente
  con la evidencia ya conciliada.

Recibir `token_ws` solo habilita la consulta financiera; no aprueba el pago.
Un HTTP 200 solo confirma que el endpoint respondió. El navegador, sus query
parameters y campos ocultos nunca son fuente de verdad. IDs, montos, moneda y
origen enviados por el cliente no pueden reemplazar los valores internos.

Un resultado rechazado, abortado, incompleto o inconsistente se persiste y se
presenta, pero nunca ejecuta un manejador de completitud. Si ya existe un
resultado financiero aprobado y persistido, los reintentos de conciliación lo
reutilizan y no repiten `commit()`.

## 3. Resultado financiero normalizado

Debe evolucionarse `NormalizedFinancialApproval` en vez de crear una segunda
normalización paralela. El nombre propuesto es `ValidatedPaymentResult`; puede
reemplazar gradualmente al DTO actual o ser una evolución compatible. Será
inmutable y solo podrá construirse después de la conciliación financiera de
`WebpayReturnService`.

| Campo | Obligatorio | Uso |
| --- | --- | --- |
| `provider` | Sí | `webpay_plus`; parte de claves e identificación. |
| `financialStatus` | Sí | Estado normalizado: approved, rejected, aborted o inconsistent. |
| `providerStatus` | Para commit | Valor sanitizado como `AUTHORIZED`. |
| `responseCode` | Para commit | Código financiero entero. |
| `authorizedAmount` | Si hubo commit completo | Entero CLP validado. |
| `currency` | Sí | `CLP`, tomada del contexto interno. |
| `buyOrder` | Si hubo commit completo | Referencia validada, no ingresada por navegador. |
| `financialSessionId` | Si hubo commit completo | `session_id` validado. |
| `authorizationCode` | Nullable | Obligatorio para approved si el SDK lo entrega; no se muestra al público. |
| `paymentTypeCode` | Nullable | Informativo. |
| `installmentsNumber` | Nullable | Informativo; cero puede ser válido según respuesta del SDK. |
| `accountingDate` | Nullable | Informativo. |
| `transactionDate` | Approved: sí | Evidencia temporal financiera normalizada. |
| `financialFingerprint` | Sí | SHA-256 versionado del subconjunto financiero estable. |
| `safeFinancialReference` | Sí | Referencia corta `sha256:…` apta para soporte. |
| `tokenHash` | Sí | SHA-256 completo; nunca el token. |
| `paymentOrigin` | Sí | `woocommerce` o `veciahorra_checkout`. |
| `originResourceId` | Sí | ID interno servidor; no proviene del retorno público. |
| `alreadyReconciled` | Sí | Lectura del registro persistente de conciliación. |
| `correlationId` | Sí | Correlación opaca entre retorno, conciliación y respuesta. |
| `payloadVersion` | Sí | Versión de serialización y fingerprint. |

El campo `origin` de `NormalizedFinancialApproval` actual significa origen de
invocación (`webpay_return`, `manual_recovery`, `internal_retry`, `test`), no
origen del pago. La evolución debe separarlo en `invocationOrigin` y
`paymentOrigin` para no reutilizar una palabra con dos autoridades distintas.

`card_last_four` y `balance`, hoy presentes en `WebpayCommitResult`, no son
necesarios para completar pedidos. Si se conservan para soporte, serán
informativos, con retención limitada y nunca formarán parte de una respuesta
pública. No se persisten PAN, CVV, API Key, token completo ni payload SDK sin
filtrar.

### Fingerprint financiero exacto

`financialFingerprintV1` será SHA-256 hexadecimal, en minúsculas, de un JSON
UTF-8 canónico con claves en este orden fijo:

```text
schema                 = "webpay-financial-v1"
provider               = "webpay_plus"
environment            = "integration" | "production"
merchant_identity_hash = sha256(commerce_code)
provider_status        = status normalizado en mayúsculas
response_code          = entero decimal
amount_clp             = entero positivo en pesos, no float ni decimal string
currency               = "CLP"
buy_order              = valor validado
financial_session_id   = valor validado
transaction_date       = ISO-8601 UTC validado o null literal
authorization_hash     = sha256(authorization_code) o null literal
payment_type_code      = valor normalizado o null literal
installments_number    = entero o null literal
accounting_date        = valor normalizado o null literal
```

La codificación usa `JSON_UNESCAPED_SLASHES`, sin whitespace, sin claves
adicionales y conservando `null` como JSON null. Antes de codificar se validan
tipos; jamás se serializan floats. Ambiente y merchant hash distinguen
sandboxes/comercios. El token, correlation ID, timestamps locales, número de
intento, estado de lease, origen de negocio y resource ID quedan fuera porque
son volátiles o no pertenecen a la evidencia financiera.

El fingerprint no depende solo de `buy_order`: también incluye merchant,
ambiente, session ID, monto, fecha y huella de autorización. El origen se liga
por una clave diferente, `originKeyV1 = sha256(JSON canónico de
{site_scope, origin, origin_resource_id, gateway_id, payment_attempt_id})`.
Ambas huellas se almacenan y comparan; una identifica la transacción financiera
y la otra el intento de negocio.

### Moneda y unidades monetarias

La unidad canónica de conciliación es **peso CLP entero** (`amount_clp`):

- WooCommerce entrega `WC_Order::get_total()` como string. El gateway actual
  solo acepta `digits` o `digits.00`, elimina ceros iniciales y produce
  `"N.00"`; luego `integerAmount()` lo convierte de forma estricta a entero.
- Webpay puede entregar int o float desde el SDK, pero
  `WebpayPaymentGateway::commitResult()` exige valor finito, positivo e integral
  antes del cast a int.
- PaymentSession.amount, Payment.amount, Checkout.total_amount, Order.total,
  OrderItem.unit_price/subtotal y auditoría son `DECIMAL(10,2)` y se leen como
  strings. Para confirmación Webpay, `TransactionalPaymentConfirmationService`
  exige exactamente `^[1-9]\d*\.00$` y los transforma a pesos enteros.
- Algunas rutas de preparación del checkout calculan centavos enteros y
  `OrderService` legado usa floats para redondear. Ninguno de esos valores se
  compara directamente durante conciliación: se relee el decimal persistido y
  se aplica la conversión estricta anterior.

Se prohíben decimales distintos de `.00`, notación científica, NaN, infinitos,
strings parcialmente numéricos, comparación de floats y mezcla entre centavos
y pesos. Para CLP, `1500.00` persistido equivale exactamente a
`amount_clp = 1500`, nunca a 150000.

## 4. Resolución del origen

La resolución se separa de la conciliación mediante contratos del módulo
Payments, siguiendo el patrón de interfaces ya usado por gateways y por
`OrderPaymentConfirmationInterface`:

```text
PaymentOriginContextRepositoryInterface
    resolveByTokenHash(tokenHash): PaymentOriginContext

PaymentReconciliationService
    reconcile(ValidatedPaymentResult): PaymentReconciliationResult

PaymentCompletionHandlerInterface
    supports(origin): bool
    complete(result, originContext): CompletionResult
    inspect(result, originContext): CompletionInspection

WooCommercePaymentCompletionHandler
VeciAhorraCheckoutCompletionHandler
```

`PaymentOriginContext` contiene `origin`, `originResourceId`, monto, moneda,
referencias esperadas, expiración y versión. Para WooCommerce, el recurso es el
ID interno del `WC_Order`; para VeciAhorra es el ID de PaymentSession y, como
consistencia secundaria, el Checkout asociado.

El contexto durable mínimo de WooCommerce es:

| Campo | Regla |
| --- | --- |
| `origin` | Literal `woocommerce`. |
| `site_scope` | Network/blog ID; `get_current_blog_id()` en multisite y un valor estable en single-site. |
| `origin_resource_id` | ID interno positivo de WC_Order. No usar número visible del pedido. |
| `gateway_id` | `veciahorra_webpay_plus`. |
| `payment_attempt_id` | UUID/identificador opaco nuevo por transacción creada. |
| `amount_clp` | Entero positivo en pesos, ya normalizado. |
| `currency` | Literal validado `CLP`. |
| `environment`, `merchant_identity_hash` | Configuración ligada al create, sin API Key. |
| `buy_order`, `financial_session_id` | Referencias deterministas esperadas. |
| `token_hash` | Se agrega inmediatamente después de create; nunca token completo. |
| `created_at`, `expires_at`, `context_version` | Vigencia y evolución de formato. |

No hace falta persistir order number, order key ni datos personales para
conciliar. `payment_attempt_id` evita confundir varios intentos legítimos sobre
el mismo pedido. La relación se crea desde `$orderId` validado dentro de
`process_payment()`; jamás se reconstruye con parámetros del retorno.

El registro de handlers se inyecta en el reconciliador. Agregar un origen no
debe introducir `if (woocommerce)` en la lógica financiera. Un origen no
registrado produce `unknown_origin`, sin efectos.

El transient de 28.7.4.5 sigue siendo válido para resolver el retorno inmediato,
pero debe ampliarse posteriormente con moneda e identificador opaco/interno del
origen. Antes de llamar create se persiste el contexto de origen durable con el
attempt ID y referencias deterministas; después de create y antes de enviar al
usuario se vincula atómicamente el token hash. La fila de conciliación se crea
solo cuando existe un resultado financiero. No se depende del transient para
idempotencia definitiva.

## 5. Conciliación WooCommerce

Para un approved de origen `woocommerce`:

1. Obtener `originResourceId` exclusivamente del contexto servidor persistido.
2. Resolver con `wc_get_order()` y exigir un `WC_Order` existente.
3. Revalidar gateway usado, moneda `CLP` y total entero contra el resultado.
4. Leer `is_paid()`/`needs_payment()` y el transaction ID existente.
5. Si no está pagado y el estado es pagable, llamar una sola vez
   `payment_complete($safeTransactionId)`.
6. Dejar que WooCommerce elija el estado final y ejecute sus hooks. No llamar
   directamente `update_status('processing')`.
7. Recargar el pedido y comprobar `is_paid()` y que la evidencia segura
   coincide usando `get_transaction_id()`, `get_date_paid()` y `get_status()`.
8. Marcar la conciliación durable como completada.

`$safeTransactionId` será una referencia estable derivada del fingerprint, no
`token_ws`, y cabrá en el transaction ID de WooCommerce. Se propone un metadato
privado de intento antes de invocar y
`_veciahorra_reconciled_fingerprint_v1` solo después de verificar éxito. Se
escriben con `update_meta_data()`/`save()` y se leen con `get_meta()`. La ausencia
del meta final después de una caída no prueba ausencia de pago: transaction ID,
fecha y estado se inspeccionan conjuntamente. La autoridad primaria sigue
siendo la fila durable. Actualmente estos metadatos no existen en el plugin.

No se agrega una nota adicional por defecto: `payment_complete()` ya genera su
propia nota de pago y puede generar una de error. Ninguna nota es autoridad ni
sustituye la auditoría. El transaction ID y cualquier texto controlado por el
handler son siempre referencias seguras, de modo que el logger/nota de
WooCommerce no reciba token, API Key ni payload financiero.

`payment_complete()` puede llevar el pedido a `processing`, `completed` u otro
estado por tipo de productos, filtros o configuración. El criterio de éxito es
`is_paid()` y evidencia coincidente, no un slug de estado fijo.

### Autoridad y regla de reintento WooCommerce

La decisión combina cuatro señales, en este orden:

1. fila durable y lease/fingerprint: autoridad primaria de coordinación;
2. `_transaction_id`, meta de intento y fingerprint final: evidencia de identidad;
3. `is_paid()` y `get_date_paid()`: evidencia del efecto WooCommerce;
4. estado actual: elegibilidad, no prueba financiera por sí solo.

Solo se permite volver a llamar `payment_complete()` cuando la conciliación es
pending/retryable, el proceso posee la lease, la inspección demuestra que el
pedido sigue no pagado, no tiene transaction ID/fingerprint conflictivo y el
intento anterior falló inequívocamente **antes** de invocar el método. Si el
intento alcanzó `payment_complete()` o un hook lanzó después de una escritura,
el resultado es incierto: se relee evidencia y no se repite mientras no pueda
probarse ausencia total de efecto. Una inconsistencia pasa a manual review.

Casos idempotentes:

- pedido ya pagado con la misma evidencia: `already_reconciled`, sin nueva
  llamada;
- pedido ya pagado con otra evidencia: conflicto permanente y revisión manual;
- pedido cancelado: no llamar automáticamente; approved pero no reconciliable;
- pedido inexistente, monto o moneda distintos: fallo permanente;
- excepción antes de observar un efecto: reintentable según clasificación;
- excepción de hook tras una escritura potencial: estado ambiguo; recuperar
  leyendo el pedido antes de decidir reintento.

Las escrituras normales que WooCommerce realizó al crear el pedido `pending`
siguen siendo válidas. La conciliación solo controla el paso financiero a
pagado.

### Límite de atomicidad WooCommerce

`payment_complete()` puede escribir pedido, metadatos y stock, y dispara hooks
de WooCommerce y de terceros. No se ejecuta dentro de una transacción SQL del
plugin. El procedimiento realista es: marcar processing, ejecutar el método,
releer pedido, inspeccionar transaction ID/fingerprint/fecha/estado y clasificar.

No se promete exactly-once físico para hooks externos. Se promete ejecución
lógica idempotente en componentes controlados por VeciAhorra, prevención de
duplicados conocidos, serialización por lease e inspección antes de recuperar
un resultado incierto.

La llamada se evita por completo si la inspección previa muestra pedido pagado,
porque incluso una llamada sobre estado no pagable dispara hooks pre/status en
WooCommerce. El valor booleano false de `payment_complete()` tampoco prueba que
no hubo escrituras; siempre se relee un objeto fresco con `wc_get_order()`.

## 6. Conciliación del dominio VeciAhorra

El handler `veciahorra_checkout` debe adaptar, no reimplementar,
`TransactionalPaymentConfirmationService`. El flujo real actual es:

```text
PaymentSession ready + WebpayReturn approved
    → lock del agregado
    → evidencia en PaymentSession y estado confirmed
    → Payment pending → paid
    → todas las Orders reserved → paid
    → todas las Reservations active → consumed
    → auditoría confirmation_succeeded
```

Payment ya se crea antes de iniciar la sesión mediante `PaymentService` y queda
vinculado uno a uno con PaymentSession: `payment_sessions.payment_id` tiene
índice único. Payment no está almacenada en cada Order; la cardinalidad es una
Payment a una o más Orders mediante `payment_orders`. Un Checkout también tiene
una o más Orders mediante `checkout_orders`, y ambos esquemas hacen `order_id`
único. No se debe crear otro Payment durante el retorno.

La autoridad del total es la igualdad exacta entre PaymentSession.amount,
Payment.amount, Checkout.total_amount, suma de `Order.total` y `amount_clp`
financiero. `TransactionalPaymentConfirmationService::validateRelationships()`
ya comprueba las cuatro representaciones. Si falta una Order, los conjuntos
CheckoutOrders/PaymentOrders difieren, o una Order está paid y otra reserved,
se produce `order_set_mismatch`, `orders_not_found` o
`partial_inconsistency`; toda la transacción revierte. No existe conciliación
parcial silenciosa.

### Garantías actuales del servicio transaccional

`PaymentConfirmationTransaction::run()` abre `START TRANSACTION`, hace COMMIT o
ROLLBACK, clasifica deadlock/lock timeout y trata un COMMIT ambiguo mediante
recuperación. Dentro de esa transacción, el orden real actual es:

1. PaymentSession por ID con `SELECT ... FOR UPDATE`;
2. Payment ligada a PaymentSession con `SELECT ... FOR UPDATE`;
3. Checkout mediante `find()` simple: **hoy no queda bloqueado**;
4. CheckoutOrders ordenadas por `order_id` con `FOR UPDATE`;
5. PaymentOrders ordenadas por `order_id` con `FOR UPDATE`;
6. Orders, IDs únicos y ordenados, con `FOR UPDATE`;
7. Reservations de esas Orders, ordenadas por ID, con `FOR UPDATE`.

Después inserta auditoría started, cambia PaymentSession ready → confirmed con
compare-and-set, Payment pending → paid con compare-and-set, todas las Orders
reserved → paid, todas las Reservations active → consumed e inserta auditoría
succeeded. Conteos distintos provocan excepción y ROLLBACK. Los índices únicos
en PaymentSession/PaymentOrders/CheckoutOrders aportan protección adicional.
No hay compensación manual después de un rollback exitoso y no se crea Delivery.

### Cambios requeridos en 28.7.4.6

El handler debe reutilizar el servicio anterior. Debe agregarse un método
`CheckoutRepository::findForUpdate()` si Checkout participará como invariante
mutable. El orden común futuro será PaymentSession → Payment → Checkout →
CheckoutOrders → PaymentOrders → Orders ordenadas → Reservations ordenadas.
Deliveries no se insertarán dentro de este orden hasta definir fulfillment y su
restricción única; si luego se integran, se procesan por Order ID ascendente.
Cambiar el orden exige actualizar todas las rutas que bloqueen el mismo agregado
para no introducir un orden inverso.

La creación de Deliveries debe ser una fase posterior a la confirmación del
pago y solo cuando exista un método de cumplimiento persistente:

- `pickup`: no crear Delivery;
- `delivery`: crear una Delivery por cada Order que requiera despacho;
- múltiples minimarkets: mantener una Order por minimarket y evaluar cada Order
  por separado;
- Delivery existente para la misma Order: reutilizarla y auditar
  `delivery_already_exists`.

Actualmente la elección solo existe en la vista/JavaScript del checkout público.
`CheckoutRequest::validated()` rechaza cualquier payload no vacío, y
CheckoutSchema, CheckoutOrderSchema, OrderSchema y DeliverySchema no conservan
el método. Tampoco existe payload temporal backend que pueda recuperarlo. La
regla configurable de 8000 CLP en `FrontendAssets` determina si la UI ofrece
delivery; no registra lo que el usuario eligió.

Por ello, la implementación no debe inferir delivery desde dirección, monto,
umbral o presencia de campos del navegador. Posteriormente se debe persistir
`fulfillment_method` validado (`pickup`/`delivery`) en una entidad de intención
de checkout durable antes de crear PaymentSession y copiar la decisión efectiva
a cada Order al materializarla. La copia por Order es la autoridad para crear
una Delivery después del pago. Hasta entonces, Payment y Orders pueden
conciliarse, pero la creación automática de Delivery permanece bloqueada.

Si Deliveries se crean en la misma transacción SQL que la confirmación nativa,
un fallo revierte Payment, Orders, Reservations, Deliveries y auditoría. Si se
separa por hooks o una fase asíncrona futura, el pago queda `paid` y el estado
de fulfillment queda `pending/retryable`; nunca se deshace un pago aprobado.

### Unicidad real de Delivery

`DeliverySchema` solo tiene un índice no único sobre `order_id`.
`DeliveryRepository::exists()` seguido de `create()` es vulnerable a carrera y
no existe `findByOrderId()`. Antes de automatizar debe:

1. auditar y resolver duplicados existentes por `order_id`;
2. crear una migración compatible con índice único `deliveries(order_id)`;
3. intentar insert y tratar duplicate key como carrera idempotente;
4. leer la Delivery ganadora mediante un nuevo `findByOrderId()`;
5. validar que customer/minimarket/Order coincidan;
6. registrar created o already_existing en auditoría durable.

Una comprobación previa `exists()` puede optimizar, pero nunca es la garantía.

## 7. Estados reales y transiciones propuestas

Estados observados en código:

- Payment: `pending`, `paid`, `failed` en `PaymentService` y
  `PaymentConfirmationService`.
- Order: flujo operativo `reserved`, `paid`, `delivered` en `OrderService` y
  `OrderRepository`. No existe un modelo de constantes para Order.
- Delivery: `pending`, `assigned`, `picked_up`, `delivered`, `cancelled` en
  `Delivery` y `DeliveryService`.
- Checkout: `pending`, `payment_started`, `expired`, `cancelled` en `Checkout`.
  No existe hoy un estado `paid` o `completed`.
- PaymentSession: `pending`, `ready`, `confirmed`, `expired`, `cancelled` en
  `PaymentSession`.
- Reservation relevante: `active`, `consumed`, `expired` y estados de
  liberación usados por `ReservationRepository`.
- WooCommerce: sus estados no se fijan desde VeciAhorra; se usa `is_paid()`.

| Recurso | Estado previo | Evento | Estado posterior real | Condiciones | Repetición |
| --- | --- | --- | --- | --- | --- |
| PaymentSession | ready | approved reconciliado | confirmed | Fingerprint, relaciones y locks válidos | Mismo fingerprint devuelve already_confirmed |
| Payment | pending | approved reconciliado | paid | Monto, moneda, proveedor y Orders coinciden | paid coherente no se actualiza |
| Payment | pending | rechazo financiero por flujo legado | failed | Solo servicio de confirmación que modela rechazo | failed se devuelve estable |
| Order | reserved | Payment aprobado | paid | Todas las Orders y Reservations válidas | Todas paid coherentes no se actualizan |
| Reservation | active | Payment aprobado | consumed | Cobertura exacta de OrderItems y vigencia | Todas consumed coherentes no se actualizan |
| Checkout | payment_started | Payment aprobado | payment_started | No existe estado final en el modelo actual | Sin escritura hasta definir estado real |
| Delivery | inexistente | Order paid + fulfillment delivery | pending | Decisión persistida y unicidad por Order | Reutilizar existente |
| Delivery | pending | asignación | assigned | Courier aprobado | Transición existente |
| Delivery | assigned | retiro por courier | picked_up | Transición existente | Estado final observado, no repetir |
| Delivery | picked_up | entrega | delivered | Transición existente; Order pasa a delivered | Estado final, no repetir |
| WC_Order | no pagado | approved reconciliado | decidido por WooCommerce | Total/moneda/evidencia coinciden | Si `is_paid()` y evidencia coincide, no repetir |

No se introduce en este diseño un estado ficticio de Checkout. Si un hito futuro
necesita `paid` o `completed`, deberá incorporarlo explícitamente al modelo,
repositorio, migración, transiciones y pruebas.

## 8. Idempotencia

La clave financiera primaria será `(provider, financial_fingerprint_version,
financial_fingerprint)`, con índice único. `token_hash` también será único para
Webpay. El registro durable conserva el resultado validado y el estado de
conciliación incluso después de expirar el transient.

Garantías:

- el claim atómico de `va_webpay_returns` evita commits concurrentes; un
  resultado financiero terminal durable evita repetir `commit()`, mientras que
  un claim abandonado se clasifica financial_unknown y exige recuperación;
- el claim atómico de conciliación evita dos completion handlers simultáneos;
- el fingerprint detecta el mismo evento financiero con otro request;
- la evidencia en PaymentSession y `_transaction_id`/meta WooCommerce permite
  recuperar un proceso que cayó después del efecto;
- `payment_orders.order_id` y `checkout_orders.order_id` ya son únicos;
- `payment_sessions.payment_id` ya es único;
- se requiere agregar unicidad sobre `deliveries.order_id` antes de creación
  automática;
- los eventos terminales de auditoría usan una `event_key` única;
- todas las respuestas repetidas se reconstruyen desde el resultado persistido.

Los escenarios POST repetido, GET repetido, callback WooCommerce, recarga,
atrás/adelante o timeout convergen en el mismo registro. Nunca se confía solo
en `if (!$alreadyProcessed)`: cada transición usa insert único, compare-and-set
o lock de fila.

Si el token temporal ya fue consumido, la conciliación se recupera mediante
`financial_fingerprint`/`token_hash` y origen persistido. Si el efecto ya ocurrió,
se inspecciona la evidencia del recurso y se cierra como idempotente.

## 9. Concurrencia

La unidad de lock es la fila de conciliación financiera, no el navegador ni el
transient. El algoritmo propuesto:

1. `INSERT ... ON DUPLICATE KEY` por fingerprint crea o localiza la fila.
2. Un compare-and-set cambia `pending`/`retryable` a `processing`, asigna
   `lease_owner`, incrementa `attempt_count` y fija `lease_expires_at`.
3. Solo el propietario vigente ejecuta el handler.
4. Otro proceso recibe el resultado terminal, o `processing` con una respuesta
   estable de pendiente; no ejecuta efectos.
5. Una lease vencida puede reclamarse atómicamente. Antes de repetir, el handler
   ejecuta `inspect()` para descubrir un efecto ya aplicado.
6. El propietario finaliza `completed`, `retryable`, `permanent_failure` o
   `manual_review` y limpia la lease mediante compare-and-set.

La creación usa `INSERT IGNORE` con el índice único del fingerprint y luego
lee la fila; nunca un upsert sobrescribe origen o resultado terminal. El claim
es **un único UPDATE preparado**, no SELECT seguido de UPDATE:

```sql
UPDATE va_payment_reconciliations
SET reconciliation_status = 'processing',
    lease_owner = :owner,
    lease_expires_at = :new_expiry,
    attempt_count = attempt_count + 1,
    last_attempt_at = :now,
    updated_at = :now
WHERE id = :id
  AND attempt_count < 5
  AND (
      reconciliation_status IN ('pending', 'retryable')
      OR (
          reconciliation_status = 'processing'
          AND lease_expires_at < :now
      )
  )
```

Es implementable con el patrón actual de repositorios, que ya usa
`$wpdb->query($wpdb->prepare(...))`, affected rows y compare-and-set en
`WebpayReturnRepository`, `PaymentSessionRepository` y `PaymentRepository`.

`:owner` es un valor aleatorio de 128 bits por proceso. `:now` y expiración se
calculan una vez con la misma zona usada por la tabla. La duración inicial
propuesta es 10 minutos, configurable y mayor que el timeout PHP esperado. Se
renueva mediante otro UPDATE CAS por `id + status processing + lease_owner`
antes de cada fase controlada si resta menos de la mitad; no puede renovarse
mientras un hook síncrono de WooCommerce mantiene el control.

El cierre también es CAS por `id`, `processing` y `lease_owner`; escribe estado
terminal/retryable, código, fecha y deja owner/expiry en null. Affected rows debe
ser exactamente uno. Si el claim afecta cero filas y `attempt_count >= 5`, otro
UPDATE CAS sobre pending/retryable/lease expirada lo lleva a manual_review sin
ejecutar handler. Un proceso que pierde la lease no puede cerrar ni iniciar otro
efecto.

Mientras la lease está vigente, un segundo proceso devuelve HTTP 202/pending.
Si encuentra completed, devuelve el resultado durable con HTTP 200. Si reclama
una lease expirada, primero ejecuta `inspect()`. Debido a que un hook podría
seguir vivo más que la lease, una expiración nunca autoriza por sí sola a llamar
otra vez `payment_complete()`; evidencia ausente pero ejecución previa incierta
termina en manual_review.

Para VeciAhorra, dentro del claim se reutilizan los `SELECT ... FOR UPDATE` de
`TransactionalPaymentConfirmationService`; la base de datos serializa el
agregado completo. Deadlock y lock timeout mantienen los dos intentos acotados
ya existentes y luego quedan reintentables.

Para WooCommerce, no se mantiene una transacción SQL del plugin abierta durante
`payment_complete()`: sus hooks pueden realizar I/O y escrituras ajenas. La
lease serializa el intento y `_transaction_id` más la evidencia segura permiten
recuperación. Un timeout no autoriza repetir ciegamente.

## 10. Atomicidad y límites transaccionales

No existe una transacción distribuida entre Transbank, WordPress y hooks:

- `commit()` es remoto y ocurre antes de la transacción local;
- el resultado financiero validado se persiste antes del efecto de negocio;
- las tablas VeciAhorra InnoDB pueden actualizarse juntas mediante
  `PaymentConfirmationTransaction`;
- `payment_complete()` y sus hooks no deben envolverse en esa transacción;
- auditoría nativa crítica se escribe en la misma transacción del agregado;
- auditoría del intento WooCommerce se escribe antes y después del límite
  externo, con recuperación por inspección.

Orden recomendado:

```text
commit remoto (solo si no existe resultado durable)
→ validar y persistir resultado financiero
→ claim de conciliación
→ resolver y revalidar origen
→ inspeccionar efecto previo
→ ejecutar handler
→ verificar efecto
→ persistir resultado/auditoría terminal
→ responder sin secretos
```

Estados de conciliación: `pending`, `processing`, `completed`, `retryable`,
`permanent_failure` y `manual_review`. Son estados del nuevo registro de
conciliación, no estados financieros ni de Order.

Approved + efecto fallido se conserva como approved financieramente y
`retryable`/`manual_review` en negocio. Nunca se transforma en rejected. Un
commit SQL ambiguo se resuelve leyendo el agregado, como ya hace
`TransactionalPaymentConfirmationService::recover()`.

## 11. Persistencia necesaria

Las tablas actuales son suficientes para la confirmación nativa sin Delivery,
pero no para una conciliación genérica WooCommerce y su recuperación durable.
Las autoridades se separan en tres agregados futuros.

### Contexto durable: `va_payment_origin_contexts`

Se crea antes de `createSession()` con `public_id/payment_attempt_id`,
`site_scope`, `origin`, `origin_resource_id`, `gateway_id`, `amount_clp`,
`currency`, `environment`, `merchant_identity_hash`, `buy_order`,
`financial_session_id`, `context_version`, `created_at` y `expires_at`. Tras una
respuesta create válida se asigna `token_hash` con compare-and-set antes de
entregar el formulario al usuario.

Índices únicos en payment attempt ID y token hash; índice por
`(site_scope, origin, origin_resource_id)`. No contiene token, order key, API Key
ni datos personales. Si create falla, el intento queda failed/expired. Si create
responde y falla el binding, no se entrega el formulario; el incidente queda
auditado para no producir un retorno imposible de resolver.

### Autoridad financiera: evolución de `va_webpay_returns`

La tabla existente conserva `token_hash`, claim, flow y `result_json`, pero sus
estados no distinguen obtenido de validado. Se propone agregar columnas
explícitas `public_result_id`, `provider`, `environment`, `merchant_identity_hash`,
`financial_status`, `financial_fingerprint`, `fingerprint_version`,
`provider_status`, `response_code`, `amount_clp`, `currency`, `buy_order`,
`financial_session_id`, `authorization_code_hash`, `payload_version`,
`financial_obtained_at` y `financial_validated_at`. `normalized_payload_json`
mantiene solo la allowlist versionada.

`token_hash` continúa único. También será único
`(provider, fingerprint_version, financial_fingerprint)` cuando el fingerprint
exista, y `public_result_id` será aleatorio de al menos 128 bits y único. Esta
fila es la autoridad de si el resultado fue obtenido, validado,
rechazado, inconsistente o quedó desconocido; nunca es autoridad del pedido.

### Autoridad de negocio: `va_payment_reconciliations`

| Campo | Propósito |
| --- | --- |
| `id`, `public_id` | Identidad interna y referencia opaca pública. |
| `webpay_return_id`, `origin_context_id` | Relaciones únicas con autoridad financiera y origen durable. |
| `site_scope`, `origin`, `origin_resource_id` | Blog/site y agregado real. |
| `gateway_id`, `payment_attempt_id`, `origin_key` | Identidad durable del intento de negocio. |
| `reconciliation_status` | pending, processing, completed, retryable, permanent_failure o manual_review. |
| `business_result_code` | Resultado estable del handler. |
| `attempt_count`, `lease_owner`, `lease_expires_at` | Concurrencia y reintentos. |
| `last_error_code`, `last_error_at` | Error sanitizado, sin mensajes SQL. |
| `created_at`, `last_attempt_at`, `reconciled_at`, `updated_at` | Trazabilidad. |

Índices únicos en `public_id`, `webpay_return_id` y `origin_key`; índices de
consulta por `(site_scope, origin, origin_resource_id)`, estado, lease y fechas.
El mismo WC_Order puede tener varios intentos porque `payment_attempt_id` cambia,
pero una transacción financiera solo puede producir una conciliación.

Además se requiere posteriormente:

- ampliar el contexto de origen WooCommerce con WC order ID y moneda;
- agregar una restricción única `deliveries(order_id)`;
- persistir el método de fulfillment por Checkout/Order antes de automatizar
  Deliveries;
- decidir si `provider_reference` de Payment deja de guardar tokens completos
  en flujos futuros y migra a una referencia segura;
- adaptar `PaymentConfirmationAuditSchema`, hoy dependiente de
  `payment_session_id` y `checkout_id` no nulos, o crear auditoría genérica de
  conciliación. No se crearán IDs ficticios para eventos WooCommerce.

`va_webpay_returns` puede seguir siendo el inbox de retorno y garantía de
commit único. No debe convertirse en el único registro de negocio porque su
estado `completed` actualmente significa fin del procesamiento financiero,
no finalización del pedido.

Nunca se almacenan API Key, token completo, PAN, CVV, payload SDK completo,
credenciales URL ni datos personales innecesarios.

## 12. Auditoría

Eventos mínimos:

| Evento | Nivel normal | Datos permitidos |
| --- | --- | --- |
| `return_received` | info | correlation ID, método, token hash/referencia segura |
| `commit_requested` | info | proveedor, correlation ID, intento |
| `commit_approved` | info | fingerprint, monto, moneda, referencias financieras |
| `commit_rejected` | info | status y response code |
| `financial_validation_failed` | high | código de discrepancia, nunca valores sensibles completos |
| `reconciliation_started` | info | origen, ID interno, lease, intento |
| `reconciliation_completed` | info | handler y resultado estable |
| `reconciliation_repeated` | info | fingerprint y resultado previo |
| `reconciliation_failed` | warning/high | código sanitizado y retryable |
| `woocommerce_payment_completed` | info | WC order ID, estado observado, referencia segura |
| `payment_created_or_reused` | info | Payment ID; normalmente reused en este flujo |
| `order_updated_or_already_updated` | info | lista ordenada de IDs y conteo |
| `delivery_created_or_existing` | info | Order ID y Delivery ID |

Se reutiliza `PaymentConfirmationAudit` para el agregado nativo. La auditoría
genérica debe admitir origen WooCommerce sin PaymentSession ficticia y aplicar
una `event_key` única a cada evento lógico terminal.

El esquema futuro de auditoría genérica tendrá: `aggregate_type`
(`woocommerce_order`, `payment_session`, `payment_reconciliation`),
`aggregate_id` string validado, `origin`, `correlation_id`, `event_type`,
`event_key`, `result_code`, `severity`, `attempt_number`,
`safe_financial_reference`, `context_json` allowlist y `created_at`. Payment ID,
PaymentSession ID, Checkout ID y Order IDs serán referencias opcionales según
el agregado, nunca IDs ficticios. `event_key` será única para eventos lógicos
terminales.

La auditoría durable prueba decisiones de negocio. Los logs técnicos sirven
para diagnóstico transitorio y no la sustituyen. Una nota WooCommerce es visible
en el pedido y tampoco sustituye auditoría; su fallo no revierte ni invalida una
conciliación ya demostrada.

Prohibido: token, API Key, cookies, nonces, PAN, CVV, URL con query sensible,
stack trace, SQL, payload financiero completo y datos personales. Los logs de
aplicación solo contienen correlation ID, referencias hash, códigos allowlist
y clase de excepción. Las notas WooCommerce se sanitizan con texto fijo y
escaping de WordPress.

Retención recomendada: conciliación y eventos terminales conforme a la política
contable/legal del proyecto; logs operativos de bajo nivel por un período más
corto. La retención exacta debe aprobarse antes de producción y acompañarse de
controles de acceso y borrado.

## 13. Errores y recuperación

| Caso | Clasificación | Efecto |
| --- | --- | --- |
| WC_Order inexistente | Permanente/manual_review | Approved financiero; sin completar pedido |
| WC_Order ya pagado con misma evidencia | Ya procesado | Cerrar idempotente |
| WC_Order ya pagado con otra evidencia | Conflicto/manual_review | No sobrescribir |
| WC_Order cancelado | No reconciliable/manual_review | No reabrir automáticamente |
| Monto o moneda diferente | Permanente | Sin efecto; auditoría high |
| PaymentSession inexistente | Permanente | Mantener approved y escalar |
| Order nativa inexistente | Permanente | Rollback completo |
| Delivery preexistente coherente | Ya procesado | Reutilizar |
| Delivery preexistente conflictiva | Conflicto/manual_review | No duplicar |
| Fallo DB/deadlock/timeout | Reintentable | Rollback y lease retryable |
| Excepción antes de `payment_complete()` | Reintentable según causa | Inspeccionar antes de repetir |
| Excepción de hook tras escritura | Ambiguo | Recargar WC_Order y comparar evidencia |
| Contexto temporal expirado con origen durable | Recuperable | Usar registro durable |
| Contexto temporal expirado sin origen durable | Permanente/manual_review | No confiar en cliente |
| Resultado financiero persistido | Ya procesado financieramente | No repetir `commit()` |
| Approved cuyo efecto falló | Retryable o manual_review | Nunca reclasificar como rejected |
| Rejected | Rechazado financiero | No ejecutar handler |
| Aborted | Abortado | No ejecutar handler |
| Origen desconocido | Permanente/security | No ejecutar handler |

Los errores públicos se expresan como códigos estables. Los detalles internos
quedan en auditoría sanitizada. Un operador puede reintentar solo estados
`retryable`; `manual_review` exige una decisión explícita y auditable.

### Iniciadores y política de reintento

- Una repetición natural POST/GET del retorno puede reclamar una conciliación
  pending/retryable, pero reutiliza el resultado durable y no repite commit.
- La recarga de la página GET de resultado es exclusivamente lectura y nunca
  inicia reintentos.
- Un endpoint interno futuro puede reintentar con capability administrativa,
  nonce, motivo y auditoría; no será público.
- Un comando WP-CLI de mantenimiento puede aplicar la misma operación para
  soporte, con correlation ID y operador auditado.
- Un cron futuro puede usar el contrato de claim, aunque su implementación queda
  fuera de alcance. El diseño no debe impedirlo.

Deadlock, lock timeout, indisponibilidad DB antes del efecto y fallos inequívocos
pre-handler son seguros para reintentar. Monto/moneda/origen/relaciones
incompatibles son permanentes. Conflictos de evidencia y resultados posteriores
a entrar en `payment_complete()` son inseguros o inciertos y requieren
inspección; si esta no prueba éxito ni ausencia total de efecto, manual review.

### Matriz de fallos parciales

| Punto de fallo | Estado financiero durable | Efecto posible | Estado de conciliación | Acción |
| --- | --- | --- | --- | --- |
| Antes de llamar commit | No | No | Sin fila o financial_processing | Repetir flujo financiero bajo claim |
| Commit lanzó sin respuesta válida | No confirmado | Desconocido en proveedor | financial_unknown | Consulta soportada o revisión; no commit ciego |
| Commit respondió y proceso cayó antes de persistir | No, aunque hubo respuesta remota | No local | financial_unknown | Recuperación de proveedor/manual |
| Después de validar y persistir | Sí | No | pending | Claim y handler seguros |
| Después de claim, antes del handler | Sí | No | processing hasta expirar lease | Reclaim + inspect |
| Durante `payment_complete()` | Sí | Parcial o completo, incluidos hooks | processing/incierto | Releer transaction ID, fingerprint, fecha y estado; no repetir ciegamente |
| Después de `payment_complete()`, antes de cerrar fila | Sí | Sí | processing hasta expirar lease | Reclaim + inspect; cerrar idempotente si coincide |
| Tras PaymentSession y antes de Payment nativa | Sí | Escrituras SQL no confirmadas | processing; transacción revierte | Reintento seguro tras rollback |
| Tras Payment y antes de Orders nativas | Sí | Escrituras SQL no confirmadas | processing; transacción revierte | Reintento seguro tras rollback |
| Tras Orders y antes de Reservations/auditoría | Sí | Escrituras SQL no confirmadas | processing; transacción revierte | Reintento seguro tras rollback |
| COMMIT SQL nativo ambiguo | Sí | Puede estar completo | processing/incierto | `recover()` inspecciona agregado completo |
| Payment/Orders confirmados y Delivery separada falla | Sí | Pago completo; fulfillment incompleto | pago completed + fulfillment retryable | Reintentar solo Delivery |
| Negocio completo antes de marcar reconciliación | Sí | Sí | processing hasta lease/ambiguo | Releer evidencia y cerrar, sin repetir efecto |

## 14. Respuesta al navegador y POST/Redirect/GET

Hoy `PaymentRoutes::webpayReturn()` devuelve JSON directamente para POST/GET.
La implementación posterior separará endpoint técnico y presentación:

1. Webpay llega por POST normal o GET defensivo al endpoint público actual.
2. El endpoint ejecuta o inicia la conciliación dentro de un presupuesto corto.
3. Para navegación devuelve `303 See Other` hacia una URL GET de resultado.
4. La URL contiene solo `public_result_id` de la autoridad financiera, aleatorio
   de al menos 128 bits; no incluye token, fingerprint, WC order ID ni clave del
   pedido. Así también existen páginas para rejected, aborted e inconsistent,
   que no crean una fila de conciliación.
5. El GET lee exclusivamente la fila durable y renderiza una vista escapada.

El registro contable conserva su retención, pero el acceso público al
identificador puede expirar, por ejemplo, a las 24 horas y mostrar luego un
mensaje genérico. El identificador no concede acceso a datos personales ni al
payload financiero. Deben aplicarse `Cache-Control: no-store`, referrer policy
restrictiva y no indexación.

Si la conciliación está pending/processing, la vista muestra estado pendiente.
Puede usar polling **solo lectura** a un endpoint por public ID, con intervalo y
límite acotados; el polling no reclama lease ni reintenta. Sin polling, la
recarga manual conserva la misma semántica. El endpoint técnico mantiene JSON
para pruebas/integraciones mediante negociación o controlador separado, pero
nunca mezcla render con efectos.

| Situación | Estado presentado | Semántica HTTP orientativa |
| --- | --- | --- |
| Approved y conciliado | Pago confirmado | 200 |
| Approved ya conciliado | Pago ya confirmado | 200 |
| Approved, conciliación pendiente | Pago recibido, confirmación en proceso | 202 |
| Rejected | Pago rechazado | 200 para vista; resultado de negocio explícito |
| Aborted | Pago cancelado por usuario/proveedor | 200 para vista |
| Error recuperable | Confirmación temporalmente pendiente | 202/503 con retry guidance controlada |
| Error permanente | No fue posible conciliar automáticamente | 409/422 según contrato |

La vista no muestra token, autorización completa, stack trace, API Key, payload
SDK ni mensaje SQL. Toda salida usa escaping. Recargar, compartir o volver a la
página solo lee estado persistido.

## 15. Seguridad

- Origen limitado por enum/allowlist y handler registrado.
- Recurso resuelto desde contexto servidor durable; nunca desde `order_id` del
  retorno o del navegador.
- Monto, moneda y referencias se comparan nuevamente dentro del handler.
- Token solo en memoria durante commit; persistencia mediante hash.
- Replay bloqueado por token hash, fingerprint, claim y evidencia del recurso.
- Queries preparadas y nombres de tabla controlados por repositorios.
- Endpoint de retorno público conserva `permission_callback => __return_true`;
  administración y reintentos manuales requieren capacidades y nonce.
- Transaction ID y notas WooCommerce usan referencia segura y texto sanitizado.
- Respuesta y logs usan allowlists, correlation ID y escaping.
- Ningún ID manipulado cambia el origen ya ligado a la transacción.
- Payload normalizado minimiza datos y tiene versión/fingerprint.
- Secretos y datos de tarjeta se excluyen por validación estructural, siguiendo
  el enfoque de `PaymentConfirmationAudit::validate()`.

## 16. Compatibilidad con lo existente

El diseño conserva sin cambios conceptuales:

- `WebpayPaymentGateway` como único adaptador del SDK Transbank;
- `WebpayReturnService` como único punto de commit y validación financiera;
- el contexto hash-only de 28.7.4.5;
- el endpoint público GET/POST y el manejo separado de `TBK_TOKEN`;
- el gateway WooCommerce registrado por `WebpayGatewayRegistration`;
- `payment_complete()` como API responsable de las transiciones WooCommerce;
- Payment, PaymentSession, Checkout, Orders y Reservations existentes;
- `TransactionalPaymentConfirmationService` y su orden de locks;
- separación de Orders por minimarket mediante las relaciones existentes;
- checkout público y consultas futuras de Panel Cliente;
- Delivery y tracking actuales para Panel Minimarket/Repartidor.

No se acopla el reconciliador a pantallas, no se cambia el SDK y no se
rediseñan carrito, catálogo, reservas ni paneles.

## 17. Matriz de pruebas para la implementación

### Unitarias

- DTO acepta todos los campos válidos y rechaza tipos, estados y formatos
  inválidos.
- Fingerprint canónico es estable, versionado y no contiene token.
- Fingerprint distingue ambiente/comercio/session/fecha/autorización, conserva
  null canónico y no cambia por correlation ID, intento o timestamps locales.
- Normalización CLP demuestra equivalencia `1500.00` → 1500 y rechaza
  decimales, exponentes, NaN, infinitos, overflow y unidades mezcladas.
- Resolver rechaza origen desconocido, contexto expirado e IDs del cliente.
- Clasificación de errores y respuestas es estable.
- Handler registry selecciona exactamente un manejador.
- Handler WooCommerce valida monto/moneda y decide complete/already/conflict.
- Handler nativo adapta el servicio transaccional sin duplicar validaciones.

### Integración WooCommerce

- approved completa pedido no pagado una vez;
- `payment_complete()` puede producir processing, completed u otro estado;
- rejected y aborted no llaman `payment_complete()`;
- monto o moneda diferentes no modifican pedido;
- pedido inexistente y cancelado quedan sin efectos;
- pedido pagado con misma evidencia es idempotente;
- pedido pagado con evidencia distinta produce conflicto;
- POST repetido, GET repetido, callback y recarga devuelven mismo resultado;
- dos procesos con lease vigente permiten una sola invocación controlada;
- lease expirada tras entrar a hooks obliga a inspección/manual review y no a
  una segunda llamada ciega;
- contexto transient expirado funciona con origen durable;
- sesión WooCommerce perdida no impide conciliar;
- excepción antes/después de hooks se recupera por inspección;
- no hay reducción adicional de stock fuera de reglas WooCommerce.
- false/excepción de `payment_complete()` obliga a releer un WC_Order fresco;
- hooks pre, completed y status-specific no reciben token ni se disparan por
  una llamada deliberada sobre pedido ya pagado;
- redirect 303 contiene solo public ID y el GET/polling son solo lectura.

### Integración VeciAhorra

- approved para pickup confirma Payment/Orders/Reservations y no crea Delivery;
- approved para delivery crea una Delivery por Order cuando exista fulfillment
  persistido;
- múltiples minimarkets mantienen varias Orders bajo un Payment;
- Payment único y relaciones exactas con todas las Orders;
- Delivery única por Order y reutilización de preexistente;
- Order ya paid con mismo fingerprint es idempotente;
- fallo entre Payment y Order revierte todo;
- fallo entre Order y Reservation/Audit revierte todo;
- fallo de Delivery en fase transaccional revierte o, si se adopta fase separada,
  deja fulfillment retryable sin revertir el pago;
- reintento seguro tras deadlock, timeout y commit ambiguo;
- relaciones, reservas, monto, moneda, buy order y session ID manipulados fallan.

### Seguridad

- token ausente de tablas, logs, auditoría, notas, HTML y JSON;
- API Key ausente de errores y excepciones públicas;
- IDs manipulados no cambian WC_Order, PaymentSession ni Checkout;
- origen desconocido no ejecuta handlers;
- duplicados no crean Payment, nota, Delivery ni efecto repetido;
- errores HTTP y vistas están sanitizados;
- payload persistido rechaza campos fuera de allowlist.

### Manuales sin red

- dobles de commit y `WC_Order` reproducen todos los estados;
- inspección de SQL comprueba índices, CAS y locks;
- prueba de fallo inyectado en cada frontera;
- recarga de página de resultado es solo lectura.

### Concurrencia real

- dos procesos PHP contra la misma base y fingerprint;
- el UPDATE de claim afecta exactamente una fila y un SELECT+UPDATE ingenuo no
  aparece en el repositorio;
- owner incorrecto no renueva ni finaliza una lease;
- quinto intento agotado transiciona a manual_review sin handler;
- uno completa y otro obtiene resultado idempotente;
- lease vencida se reclama con UPDATE atómico e inspección previa;
- deadlock/lock timeout queda retryable;
- crash simulado después de `payment_complete()` se recupera por transaction ID.

### Sandbox

- solo después de aprobar unitarias, integración, seguridad y concurrencia;
- una transacción authorized de WooCommerce completa exactamente un pedido;
- una transacción nativa confirma el agregado de múltiples Orders;
- repetición controlada del retorno no vuelve a llamar commit ni efectos;
- evidencia sanitizada del access log y auditoría.

## 18. Criterios de aceptación

- Solo `AUTHORIZED` con response code cero y contexto válido habilita negocio.
- Monto, moneda, buy order y session ID coinciden con valores servidor.
- El origen y recurso se resuelven sin datos confiados al navegador.
- Los recursos controlados por VeciAhorra tienen exactly-once lógico mediante
  transacción, CAS e índices únicos.
- El callback Webpay es at-least-once y todas sus repeticiones convergen al mismo
  resultado durable.
- VeciAhorra no inicia una segunda llamada controlada a `payment_complete()` con
  lease vigente; ante hooks de resultado incierto inspecciona o escala, sin
  prometer exactly-once físico externo.
- WooCommerce decide el estado final y este se valida con `is_paid()`.
- Existe un solo Payment nativo por agregado y una relación exacta con Orders.
- No se crea un Payment adicional durante el retorno.
- Todas las Orders se actualizan una vez o ninguna; no hay parcialidad aceptada.
- Una Order faltante, incompatible o parcialmente pagada impide el cambio del
  conjunto completo.
- Cada Order delivery tiene como máximo una Delivery; pickup no crea Delivery.
- Varias Orders por minimarket permanecen asociadas al mismo Payment global.
- Repeticiones y concurrencia producen el mismo resultado durable.
- Approved con fallo de negocio puede reintentarse sin repetir commit.
- Un resultado financiero persistido evita nuevas llamadas a Transbank.
- La conciliación durable no depende de transients ni de sesión WooCommerce.
- No se exponen token, API Key, tarjeta, payload completo ni errores internos.
- La página de resultado es recargable y solo lectura.
- Retornos simultáneos convergen al mismo estado durable.
- Auditoría terminal es única por evento lógico.
- Las pruebas concurrentes demuestran unicidad de filas/recursos controlados y
  recuperación por inspección para WooCommerce.

## 19. Plan incremental de implementación

1. Evolucionar el DTO financiero y crear contratos de origen, reconciliación y
   completion handlers.
2. Evolucionar `webpay_returns` como autoridad financiera y diseñar/migrar
   `payment_reconciliations`, índices únicos, lease y repositorio CAS; ampliar
   el contexto WooCommerce con origen durable.
3. Conectar `WebpayReturnService` al reconciliador después de persistir approved,
   sin mover ni duplicar `commit()`.
4. Implementar `WooCommercePaymentCompletionHandler` con inspección,
   `payment_complete()` y recuperación por evidencia.
5. Implementar `VeciAhorraCheckoutCompletionHandler` como adaptador de
   `TransactionalPaymentConfirmationService`.
6. Persistir fulfillment y agregar unicidad de Delivery; solo entonces conectar
   creación idempotente de Deliveries.
7. Generalizar auditoría para WooCommerce y agregar eventos de frontera.
8. Crear página/consulta de resultado separada y sanitizada.
9. Ejecutar unitarias, integración, fallos inyectados e invariantes de seguridad.
10. Ejecutar workers concurrentes y recuperación de lease/commit ambiguo.
11. Revisar manualmente diff, credenciales, logs y migraciones.
12. Realizar una única prueba sandbox controlada al final.

Cada etapa debe ser revisable y mantener passing las pruebas de 28.7.4.5. La
fase de Delivery no bloquea la conciliación de Payment/Orders, pero no se activa
hasta contar con fulfillment persistido y unicidad física.

## Riesgos abiertos

- Existe una ventana inevitable entre respuesta remota de commit y persistencia
  local; requiere política de consulta al proveedor o revisión manual.
- Un hook WooCommerce puede exceder la lease o producir efectos externos antes
  de fallar; no existe exactly-once distribuido.
- El transient actual carece de WC order ID y moneda, y expira a los 10 minutos;
  debe existir origen durable antes de habilitar completitud.
- La sesión WooCommerce de ida almacena brevemente el token completo para el
  formulario POST; no debe reutilizarse como registro de conciliación.
- Checkout no se bloquea hoy y no tiene estado final de pago.
- Fulfillment no llega al backend y el umbral de 8000 CLP solo gobierna la UI.
- Delivery admite duplicados físicos por Order hasta crear el índice único y
  sanear datos anteriores.
- `Payment.provider_reference` puede conservar provider session ID completo en
  flujos nativos; su migración a una referencia segura requiere compatibilidad.
- La auditoría actual exige PaymentSession y Checkout, por lo que no cubre
  WooCommerce sin generalización.
- La retención y privacidad del public result ID requieren política operativa
  antes de producción.

## 20. Fuera de alcance

- reembolsos y anulaciones;
- capturas diferidas;
- pagos parciales;
- cuotas administradas por VeciAhorra;
- conciliación bancaria nocturna;
- cron automático de consulta;
- múltiples proveedores de pago;
- chargebacks y disputas;
- cambio del SDK Transbank;
- rediseño del checkout;
- implementación de paneles de usuario;
- notificaciones por correo, SMS o push.
