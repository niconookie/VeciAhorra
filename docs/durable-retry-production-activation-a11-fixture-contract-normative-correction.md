# Corrección normativa A11 — contrato cerrado de fixtures reales

## 1. Autoridad y precedencia

Esta corrección complementa las correcciones normativa, complementaria y de ruta
de bootstrap A11. Prevalece exclusivamente sobre cualquier definición previa
incompleta o ambigua de `$fixture`, `fixture_ids`, manifest, ownership,
allocation y cleanup de fixtures A11. Los demás contratos permanecen intactos,
incluidos bootstrap nivel cinco, allowlist de doce rutas, 31 casos, cinco crash
windows, protocolo JSON/JSONL, códigos de salida y validación fail-closed.

## 2. Catálogo de casos y `allocate()`

La firma permanece:

```php
public function allocate(string $caseId, array $fixture): string;
```

`$caseId` se compara byte por byte y pertenece exactamente a `A11-OP-01..05`,
`A11-CON-01..05`, `A11-CR-01..05`, `A11-WR-01..06` o `A11-EX-01..10`, regex
`/^A11-(?:OP|CON|CR|WR|EX)-(?:0[1-9]|10)$/D`, restringida además por esos rangos.
Cada ID se asigna una vez por coordinator. Desconocido, repetido, normalizado,
trimmeado o con case distinto lanza `InvalidArgumentException('Invalid or duplicate A11 case_id.')`.

## 3. Shape cerrado de `$fixture`

Shape exacto, sin claves adicionales:

```text
profile:string; operation:string; role:string; crash_point:string|null;
deadline_ms:int; payload:object; expected:object; variations:object
```

Todas son obligatorias. `profile` pertenece a §9; `operation` a
`publish|callback|legacy|as_action|recovery|http`; `role` cumple
`/^[a-z][a-z0-9_]{0,31}$/D`; `crash_point` es null o uno de §13;
`deadline_ms` es 1.000..30.000. `payload`, `expected` y `variations` son shapes
cerrados por perfil. Faltante/adicional/tipo/rango/catálogo inválido lanza
`InvalidArgumentException('Invalid A11 fixture contract.')`. No hay coerción,
defaults, null adicional ni arrays interpretativos.

## 4. Ownership

`ownership_token` son 32 bytes de `random_bytes(32)` codificados como 64 hex
lowercase. Se crea una vez, vive solo en `manifest.json` y se deriva
`ownership_hash=hash('sha256','veciahorra-a11|'.$ownership_token)`. Nunca aparece
en argv, stdout o SQL logs. Marcadores funcionales usan `a11_<run-id-fragment>_`
más hash truncado y respetan la longitud de columna.

Una fila es propia solo si coinciden: PK capturada, tabla lógica esperada y
marcador único derivado cuando existe. En tablas puente sin marcador, deben
coincidir PK capturada y ambas FK hacia padres previamente acreditados como
propios. Discrepancia preserva la fila, marca `cleanup_failed`, exit 30 y reporta
solo tabla lógica/PK. Nunca basta estado, fecha o dato funcional común.

## 5. Manifiesto durable

`allocate()` retorna la ruta absoluta a
`sys_get_temp_dir()/veciahorra-a11/<run_id>/manifest.json`. El archivo JSON UTF-8
usa permisos 0600, directorio 0700, escritura `manifest.tmp` + `flock(LOCK_EX)` +
flush + rename atómico. `manifest_id` es el `run_id`; no existe tabla nueva.

Shape superior exacto:

```text
schema="veciahorra-a11/v1"; manifest_id:string; run_id:string; case_id:string;
ownership_token:string; ownership_hash:string; profile:string; operation:string;
role:string; crash_point:string|null; created_at:string; deadline_ms:int;
wp_load_path:string; temp_dir:string; release_path:string|null; payload:object;
fixture_ids:object; expected:object; variations:object
```

`created_at` es UTC `Y-m-d\TH:i:s.u\Z`. El hash SHA-256 del archivo acompaña
cada child. Tras crash, el coordinator reabre ruta+hash; la memoria nunca es
autoridad exclusiva. Colisión, hash distinto o claves extra terminan exit 20.

## 6. Shape cerrado de `fixture_ids`

Todas las claves existen y contienen listas de enteros positivos, sin repetidos,
ordenadas por inserción; una lista puede estar vacía solo si el perfil lo permite:

```text
orders; checkouts; checkout_orders; payment_sessions; payment_origin_contexts;
webpay_returns; payment_reconciliations; durable_retry_schedules;
business_completions; business_completion_orders; payments; payment_orders;
delivery_completions; fulfillment_completions; action_scheduler_actions
```

Cada nombre corresponde a `$wpdb->prefix . Config::TABLE_PREFIX . <nombre>`,
salvo `action_scheduler_actions`, manipulada exclusivamente por API pública.
La columna capturada es `id`; actions conservan el ID retornado por AS. Un child
recibe el manifiesto completo y jamás descubre IDs mediante consultas abiertas.

## 7. Catálogo cerrado de tablas

| Tabla | Inserción mínima y ownership | Dependencia |
|---|---|---|
| orders | customer_id,minimarket_id,total,status,created_at,updated_at; PK+padre checkout | ninguna A11 |
| checkouts | public_id propio,owner_type,user/session,status,fulfillment_method,currency,total_amount,timestamps | orders por puente |
| checkout_orders | checkout_id,order_id,created_at; ownership por dos padres | checkout+order |
| payment_sessions | public_id,idempotency_key/request_fingerprint propios,checkout_id,status,currency,amount,timestamps | checkout |
| payment_origin_contexts | public_id/payment_attempt_id/origin_key propios,site_scope,origin,resource,gateway,amount,currency,environment,merchant,buy_order,financial_session,token_hash,version,timestamps | session lógico |
| webpay_returns | token_hash propio,flow,processing_status,timestamps; financieros solo por stub | session opcional |
| payment_reconciliations | public_id/origin_key propios,return/origin IDs,provider,fingerprint,scope,origin,resource,gateway,attempt,status,timestamps | return+origin |
| durable_retry_schedules | public_id/dispatch_token_hash propios,stage,subject,completion,generation,attempt,scheduled_for,status,active_slot,version,reason,timestamps | reconciliation/completion |
| business_completions | reconciliation_id,idempotency_key propio,status,timestamps | reconciliation |
| business_completion_orders | business_completion_id,order_id,created_at; dos padres | business+order |
| payments | payment_reference/idempotency_key propios,checkout/session/reconciliation,customer,amount,currency,status,timestamps | parents |
| payment_orders | payment_id,order_id,created_at; dos padres | payment+order |
| delivery_completions | business_completion_id,idempotency_key propio,status,timestamps | business |
| fulfillment_completions | business_completion_id,idempotency_key propio,status,timestamps | business |

No se autorizan otras tablas. No se escriben tablas AS directamente.

## 8. Orden de creación y eliminación

Creación: orders → checkouts → checkout_orders → payment_sessions →
payment_origin_contexts → webpay_returns → payment_reconciliations → schedules →
business_completions → business_completion_orders → payments → payment_orders →
delivery_completions → fulfillment_completions → actions por API.

Eliminación exacta inversa: cancelar action ID → fulfillment → delivery →
payment_orders → payments → business_completion_orders → business → schedules →
reconciliations → returns → origins → sessions → checkout_orders → checkouts →
orders. Cada DELETE usa `WHERE id=%d` y, si existe marcador, lo valida antes con
SELECT por PK. Ausente es éxito idempotente; alterada/ajena es fallo sin DELETE.

## 9. Perfiles nominales

Shapes de `variations`: `operational` `{activation:on|off,scheduler:available|unavailable,outcome:success|retryable,redelivery:bool}`;
`concurrency` `{race:publish|callback|insert|generation|cancel,workers:2}`;
`crash` `{point:<§13>,recovery_runs:2}`; `webpay` `{scenario:approved|rejected|error_before_commit|delayed_approved,replays:int<1..2>,woocommerce:bool}`;
`legacy` `{authority:legacy|durable|indeterminate|throw,preexisting:absent|scheduled|consumed,concurrent:bool}`.
`payload` es exactamente el payload de operación §5 complementaria y `expected`
contiene solo `rows`, `actions`, `result`, `mutations` con enteros no negativos o
catálogos del caso. Ninguna otra variación está permitida.

## 10. Matriz OP y CON

En las tablas §§10–13, `OP-*`, `CON-*`, `CR-*`, `WR-*` y `EX-*` son únicamente
abreviaturas visuales; el `case_id` persistido antepone siempre `A11-`. El catálogo
enumerado completo es: `A11-OP-01`, `A11-OP-02`, `A11-OP-03`, `A11-OP-04`,
`A11-OP-05`, `A11-CON-01`, `A11-CON-02`, `A11-CON-03`, `A11-CON-04`,
`A11-CON-05`, `A11-CR-01`, `A11-CR-02`, `A11-CR-03`, `A11-CR-04`,
`A11-CR-05`, `A11-WR-01`, `A11-WR-02`, `A11-WR-03`, `A11-WR-04`,
`A11-WR-05`, `A11-WR-06`, `A11-EX-01`, `A11-EX-02`, `A11-EX-03`,
`A11-EX-04`, `A11-EX-05`, `A11-EX-06`, `A11-EX-07`, `A11-EX-08`,
`A11-EX-09` y `A11-EX-10`. Son 31 valores únicos y exhaustivos.

| Caso | H/perfil/variación | Filas iniciales; procesos/action; resultado/cleanup |
|---|---|---|
| OP-01 | H1 operational on/available/success | order→recon; HTTP,publish,AS,callback; consumed; todas IDs inversas |
| OP-02 | H1 operational off | order→recon; HTTP+legacy action; durable0; inversa |
| OP-03 | H1 operational scheduler unavailable | recon; publish; action0/fallback0; inversa |
| OP-04 | H1 operational redelivery | recon+terminal schedule/action; AS dos; proceso1; inversa |
| OP-05 | H1 operational retryable | recon+gen1; callback+recovery; gen1 superseded+gen2; inversa |
| CON-01 | H2 concurrency publish | recon; coordinator+2 publish/action≤1; inversa |
| CON-02 | H2 concurrency callback | recon+schedule/action; 2 callback; consumed/proceso≤1; inversa |
| CON-03 | H2 concurrency insert | recon; 2 publish; schedule activo1; inversa |
| CON-04 | H2 concurrency generation | recon+gen vieja/actual+actions; 2 callback; actual1/vieja stale; inversa |
| CON-05 | H2 concurrency cancel | recon+scheduled/action; recovery+callback; cierre1; inversa |

## 11. Matriz CR

| Caso | Perfil/punto | Estado inicial; proceso; durable esperado/cleanup |
|---|---|---|
| CR-01 | crash/external action | recon+dispatching; publish kill; action≤1 asociada tras recovery2; manifiesto coordinator |
| CR-02 | crash/local claim | recon+scheduled/action; callback kill; claimed intento0 luego terminal≤1; coordinator limpia |
| CR-03 | crash/functional attempt | recon+scheduled/action; attempt kill; evidencia1 luego terminal; coordinator limpia |
| CR-04 | crash/result persisted | recon+scheduled/action; repository kill; terminal idéntico/proceso0; coordinator limpia |
| CR-05 | crash/callback return | recon+scheduled/action; executor kill; terminal/replay0; coordinator limpia |

## 12. Matriz WR

| Caso | Perfil/variación | Filas/procesos; resultado; limpieza |
|---|---|---|
| WR-01 | webpay approved replay1 | order,checkout,session,origin; POST; return+recon+gen1 una; inversa |
| WR-02 | webpay approved replay2 | mismas IDs WR-02 propias; dos POST; commit/recon1; inversa |
| WR-03 | webpay approved con post-A8 | base propia; POST500+POST200; evidence/recon1; inversa |
| WR-04 | webpay delayed_approved concurrente | base; coordinator+2 HTTP; commit/recon≤1; inversa |
| WR-05 | webpay approved return existente | base+return+recon; POST+recovery; recon existente; inversa |
| WR-06 | webpay approved WooCommerce | order/checkout/session/origin; POST2; transición pago/pedido1; inversa |

Tokens son 32 caracteres alfanuméricos sintéticos derivados del ownership; el
manifiesto guarda solo hash. Stub responde campos financieros del origin propio:
amount, buy_order, financial_session_id, status AUTHORIZED/FAILED y response 0/-1.
Nunca usa Webpay real. No se crean business/delivery/fulfillment salvo que el
procesamiento del caso los produzca; todo ID producido se incorpora atómicamente
al manifiesto antes de continuar.

## 13. Matriz EX y crash windows

| Caso | Perfil/autoridad | Fixture/procesos; resultado/cleanup |
|---|---|---|
| EX-01 | legacy/legacy absent | recon; legacy child; proceso/retry≤1; inversa |
| EX-02 | legacy/durable absent | recon+gen1; legacy; efectos0; inversa |
| EX-03 | legacy/durable scheduled | recon+schedule/action; legacy; snapshot idéntico; inversa |
| EX-04 | legacy/indeterminate absent | recon; legacy; efectos0; inversa |
| EX-05 | legacy/throw absent | recon; legacy; misma excepción; inversa |
| EX-06 | legacy/durable consumed | recon+terminal schedule; legacy; idéntico; inversa |
| EX-07 | legacy/durable scheduled | recon+legacy action+schedule; AS; no retry; inversa |
| EX-08 | legacy/durable concurrent | recon+schedule/action; legacy+callback; durable≤1; inversa |
| EX-09 | legacy/durable absent | recon; publish+legacy; durable converge/legacy0; inversa |
| EX-10 | legacy/legacy absent | recon sin gen1; legacy histórico≤1; inversa |

Crash points exactos: `CRASH_AFTER_EXTERNAL_ACTION_CREATED`,
`CRASH_AFTER_LOCAL_CLAIM`, `CRASH_AFTER_FUNCTIONAL_ATTEMPT`,
`CRASH_AFTER_RESULT_PERSISTED`, `CRASH_BEFORE_CALLBACK_RETURN`. El coordinator
crea fixture y manifiesto; child solo recibe ruta+hash. Antes del kill todos los
IDs conocidos se escriben atómicamente. Coordinator valida evidencia, mata PID,
reabre manifiesto, lanza recovery independiente dos veces y limpia. Si muere el
coordinator, la siguiente ejecución solo puede limpiar mediante manifest explícito;
no hay búsqueda global automática.

## 14. Multiproceso

Coordinator es único creador y limpiador. Exactamente dos children para CON-01..05,
WR-04 y EX-08; cada uno tiene conexión/objetos propios y mismo manifest inmutable.
Canal: ruta absoluta+SHA-256 argv. Cada PID escribe ready; coordinator verifica
ambos vivos y crea release atómico. Barrera 5 s, child 30 s, caso 45 s. Hijo muerto
termina compañeros propios, preserva manifest y ejecuta cleanup coordinator.
No hay discovery SQL. PIDs tardíos se terminan por ID, nunca por nombre.

## 15. API de helpers

Se conservan sin adiciones las firmas públicas de coordinator, invocation y
result de la corrección complementaria. En particular:

```php
public function allocate(string $caseId, array $fixture): string;
public function cleanup(string $manifestPath): string;
```

Las propuestas `cleanup(string $manifestId): void` y
`cleanupAllOwnedByRun(string $runId): void` quedan reemplazadas y prohibidas:
contradicen ruta+hash durable y ampliarían la API cerrada. Child y router conservan
sus APIs normadas. El coordinator parcial existente debe completarse dentro del
mismo archivo, no reemplazarse por otra ruta; su código actual no queda certificado.

## 16. Semántica y presupuesto de cleanup

Cleanup adquiere `cleanup.lock` con `flock`, valida manifest/hash/ownership y abre
una transacción por filas SQL; cancela actions por API fuera de la transacción.
No hay reintentos. Máximos por perfil: operational 14 INSERT/20 SELECT/12 UPDATE/14
DELETE/2 transacciones/4 procesos/2 actions/12 archivos; concurrency 16/30/20/16/2/3/2/14;
crash 14/30/20/14/2/4/2/16; webpay 18/36/24/18/2/3/2/16; legacy 8/20/10/8/2/3/2/12.
Ausente es idempotente; SQL error rollback y cleanup_failed; ownership incompatible
no elimina nada restante. Resultado parcial enumera IDs residuales sin secretos.

## 17. Preservación de datos ajenos

Prohibidos TRUNCATE, DELETE sin PK, rangos, estado, fecha, case_id no autoritativo,
LIKE/prefijos, filas meramente observadas, IDs reutilizados y suposición de DB
vacía. Cada caso usa ownership nuevo. Una mutación solo alcanza IDs del manifiesto
y las filas explícitamente producidas por APIs productivas desde esos IDs.

## 18. Implementabilidad y veredicto

La revisión estructural posterior demuestra que este documento todavía no cierra
la matriz de fixtures. Las tablas de §§10–13 enumeran 31 identificadores, pero
cada fila contiene solo `caso`, `perfil/variación` y una descripción narrativa.
Ninguna fila contiene simultáneamente las quince categorías enumeradas como obligatorias:
`case_id`, `harness`, `profile`, `variations`, `payload`, `expected`,
`fixture_ids`, `rows_to_create`, `initial_state`, `external_actions`, `processes`,
`allowed_mutations`, `forbidden_mutations`, `cleanup` y `budget`.

En particular, los perfiles de §9 declaran claves obligatorias que no se asignan
completamente en cada caso; los payloads y expected no son arrays literales; las
filas no enumeran todas sus columnas; las referencias simbólicas no tienen un
catálogo cerrado; y los presupuestos de §16 se expresan por perfil, no expandidos
por caso. Por ello existen **0/31 fixtures directamente materializables** sin
defaults, herencia o interpretación de prosa.

Las cinco ventanas de crash están enumeradas, pero no poseen cinco registros
autosuficientes con las quince categorías. WR-01..06 no contienen seis vectores
Webpay completos y EX-01..10 no contienen diez vectores completos A3/A5/A6/A7/A8.
La API, ownership, manifiesto, catálogo de tablas y semántica global de cleanup
pueden servir como base de una corrección futura, pero no convierten esta matriz
en contrato ejecutable.

Hasta que una corrección posterior incorpore y valide los 31 registros completos,
este documento no autoriza completar el coordinator, crear harnesses, ejecutar
certificación ni declarar A11 implementable. No certifica A11 y no autoriza
staging, commit o push por sí mismo.

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 23. Shape cerrado de `variations` para el perfil Webpay

Esta sección prevalece exclusivamente sobre el nombre del perfil Webpay, el
shape de `variations` para `A11-WR-01`…`A11-WR-06` y la interpretación de
`replays`. Permanecen intactos los seis casos WR, sus resultados funcionales, el
contrato `woocommerce_order`, los observables estables de WR-06, las otras
catorce categorías, la allowlist, la ruta de bootstrap, las cinco ventanas de
crash y los contratos A1–A10.

### 23.1 Nombre canónico y responsabilidad

El catálogo cerrado de `profile` para WR-01…06 contiene un único valor:

```php
'profile' => 'webpay',
```

`webpay_replay` es una descripción conceptual del grupo de casos, pero no es un
valor permitido del campo `profile`. Se prohíben aliases, normalización,
traducción silenciosa y comparación que no sea exacta y sensible a mayúsculas.

`variations` contiene exclusivamente selectores del escenario. No contiene ni
duplica request Webpay, response code, status Webpay, token literal, IDs,
resultados funcionales, fingerprint final, reconciliation final, cardinalidades
finales, respuesta HTTP o filas creadas. Esos datos pertenecen a sus categorías
autoritativas de §23.5.

### 23.2 Shape exacto y catálogos

Los seis casos WR usan exactamente siete claves, todas obligatorias:

```php
'variations' => [
    'scenario' => '<catalog_value>',
    'initial_deliveries' => 1,
    'replay_deliveries' => 0, // catálogo entero cerrado: 0|1
    'woocommerce_order_required' => false,
    'token_policy' => '<catalog_value>',
    'duplicate_policy' => '<catalog_value>',
    'idempotency_policy' => '<catalog_value>',
],
```

Las claves adicionales y ausentes son error. No hay conversión de tipos,
defaults implícitos, herencia ni valores nulos. Los catálogos cerrados son:

```text
scenario = approved | rejected | error_before_commit | delayed_approved
initial_deliveries = int(1)
replay_deliveries = int(0) | int(1)
woocommerce_order_required = bool
token_policy = reuse_same_token | new_token_per_delivery
duplicate_policy = forbid_all_domain_duplicates |
                   allow_documented_transport_replay_only
idempotency_policy = single_application | retry_until_single_application |
                     no_application
```

`scenario` se compara byte por byte y es sensible a mayúsculas. Quedan
prohibidos `success`, `authorized`, `failed`, `timeout`, `pending` y
`approved_woocommerce` como aliases de escenario.

### 23.3 Entregas y políticas

`initial_deliveries` es siempre el entero `1`. `replay_deliveries` cuenta solo
entregas posteriores a la inicial: `0` significa una entrega total y `1`, dos.
La relación exacta es
`total_deliveries = initial_deliveries + replay_deliveries`. La antigua clave
`replays` queda retirada y prohibida en el shape definitivo porque confundía
entregas totales con repeticiones adicionales.

`woocommerce_order_required=true` obliga al fixture a crear exactamente un
recurso lógico `woocommerce_order`; `false` prohíbe crearlo. Su cardinalidad se
declara en `fixture_ids`, no en `variations`.

`reuse_same_token` usa el mismo token sintético en entrega inicial y replay.
`new_token_per_delivery` usa un token sintético distinto por entrega únicamente
cuando el caso lo exige expresamente. El token literal pertenece a `payload`.

`forbid_all_domain_duplicates` prohíbe toda fila o efecto funcional duplicado.
`allow_documented_transport_replay_only` permite recibir otra vez el mismo
transporte Webpay, pero prohíbe duplicar pedido WooCommerce, payment session,
reconciliation, business completion, delivery completion, fulfillment
completion, durable retry schedule, Action Scheduler action o intento funcional
exitoso.

`single_application` exige una aplicación funcional única en la primera entrega
y convergencia sin segunda aplicación. `retry_until_single_application` permite
reintentar una entrega inicialmente no aplicada hasta una única aplicación.
`no_application` prohíbe toda aplicación funcional.

### 23.4 Fragmento normativo cerrado de `variations` para A11-WR-06

Este bloque es exclusivamente el fragmento normativo cerrado de `variations`
para `A11-WR-06`; no constituye el registro completo de quince categorías:

```php
'A11-WR-06' => [
    'case_id' => 'A11-WR-06',
    'harness' => 'H4',
    'profile' => 'webpay',
    'variations' => [
        'scenario' => 'approved',
        'initial_deliveries' => 1,
        'replay_deliveries' => 1,
        'woocommerce_order_required' => true,
        'token_policy' => 'reuse_same_token',
        'duplicate_policy' => 'allow_documented_transport_replay_only',
        'idempotency_policy' => 'single_application',
    ],
],
```

### 23.5 Proyección autoritativa de datos excluidos

Esta proyección es obligatoria:

| Dato | Categoría autoritativa |
|---|---|
| Token literal | `payload` |
| Response code Webpay | `payload` |
| Status Webpay | `payload` |
| Buy order | `payload` |
| Session ID | `payload` |
| Resultado inicial | `expected` |
| Resultado del replay | `expected` |
| Fingerprint final | `expected` |
| Reconciliation final | `expected` |
| Cardinalidad de filas | `expected` |
| IDs creados | `fixture_ids` |
| Política de cleanup | `cleanup` |
| Límites operacionales | `budget` |

En consecuencia, `response_code`, `webpay_status`,
`first_processing_result`, `replay_result`, `fingerprint_state` y
`reconciliation_state` están prohibidos dentro de `variations`; deben cerrarse
posteriormente en `payload` o `expected`, según la tabla.

**SHAPE VARIATIONS WEBPAY A11 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 22. Corrección normativa del observable final de A11-WR-06

Esta sección prevalece sobre §19 exclusivamente en la definición del observable
final WooCommerce de `A11-WR-06`. Queda retirada como requisito la clave
`expected.final_order_status`: ningún status literal, conjunto de statuses ni
predicado del status integra la aceptación normativa de este caso. El status
final puede registrarse únicamente como diagnóstico no normativo y nunca puede
decidir pass/fail.

La razón contractual es estable y pública: `WC_Order::payment_complete()` parte
del valor determinado por `WC_Order::needs_processing()` y elige su transición
usando `woocommerce_payment_complete_order_status`. El harness no añade ni
elimina filtros, no presupone que estén ausentes y no cambia prioridades ni el
catálogo de filtros para fijar el resultado de esa transición.
Por tanto, `pending`, `processing`, `completed` u otro status observado no puede
reemplazar los observables de pago siguientes.

### 22.1 Referencia simbólica exacta

El catálogo simbólico incorpora para WR-06:

```php
'@allocated.woocommerce_transaction_reference' => [
    'type' => 'non_empty_string',
    'allocation' => 'WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(@allocated.financial_fingerprint)',
    'format' => 'va-wp-v1-{64_lowercase_hex_financial_fingerprint}',
    'available_before_processing' => true,
    'nullable' => false,
    'scope' => 'A11-WR-06',
],
```

La referencia asignada es el transaction ID durable exacto. No se admite una
referencia fabricada por el harness, una comparación parcial, un prefijo sin el
fingerprint completo ni una lectura inferida desde el status del pedido.

### 22.2 Forma exacta de `expected` para WR-06

La extensión específica de WR-06 a la forma común de `expected` es:

```php
'expected' => [
    'public_result' => 'APPLIED_NOW',
    'woocommerce_order_is_paid' => true,
    'woocommerce_order_date_paid_present' => true,
    'woocommerce_order_transaction_id' => [
        'operator' => 'strict_equals',
        'expected' => '@allocated.woocommerce_transaction_reference',
    ],
    'fingerprint_reconciled' => true,
    'reconciliation_status' => 'completed',
],
```

Los tres observables se evalúan después del procesamiento, tras releer mediante
`wc_get_order(@allocated.woocommerce_order_id)`, y pertenecen exclusivamente a
ese pedido. `woocommerce_order_is_paid`, de tipo `bool`, se evalúa exactamente
una vez al final con `WC_Order::is_paid() === true`; es un
`target_type=derived_observable`, no una propiedad persistida ni una inferencia
desde el transaction ID. `woocommerce_order_date_paid_present` se evalúa con
`WC_Order::get_date_paid('edit') !== null`: el valor debe ser una fecha válida de
WooCommerce para ese pedido. No se fija un timestamp literal ni se exige
igualdad con el reloj del sistema; una ventana controlada solo puede añadirse si
otra regla normativa la define. La comparación del transaction ID se hace con
`WC_Order::get_transaction_id('edit') ===
@allocated.woocommerce_transaction_reference`. `fingerprint_reconciled` exige
que la meta reconciliada productiva contenga exactamente el fingerprint
financiero usado para construir esa referencia. `reconciliation_status` exige
el estado durable exacto `completed`.

La regla común queda precisada así: todo caso declara sus observables comunes y
la extensión exacta de su recurso lógico; un status final solo puede ser
obligatorio si un contrato normativo inequívoco lo fija. Para WR-06 no existe tal
fijación. La regla extensible acepta observables adicionales únicamente cuando
están tipados y definidos por la corrección normativa del recurso; no permite
que un observable diagnóstico adquiera semántica de aceptación.

Regla reusable: cuando una plataforma externa permita modificar un valor
mediante hooks, filtros o configuración y el contrato funcional dependa de un
predicado público más estable, la matriz certifica ese predicado, no un valor
concreto no garantizado. Solo aplica si el literal no está fijado, existe el
predicado público estable, producto y pruebas dependen de él y se conserva una
aserción determinista; no autoriza observables abiertos.

### 22.3 Mutaciones permitidas y prohibidas

El estado inicial obligatorio del pedido es:

```php
'woocommerce_order_is_paid' => false,
'woocommerce_order_date_paid' => null,
'woocommerce_order_date_completed' => null,
'woocommerce_order_transaction_id' => '',
'woocommerce_order_status' => 'pending',
```

El `pending` inicial es un dato controlado del fixture; no implica ni exige un
status final. El status final no es mutación obligatoria y no se utiliza para
cleanup ni como evidencia de idempotencia.

Las únicas mutaciones funcionales WooCommerce permitidas son:

1. `is_paid`: `false` a `true`;
2. `date_paid`: `null` a un valor no nulo;
3. `transaction_id`: string vacío a
   `@allocated.woocommerce_transaction_reference` exacta;
4. la meta de fingerprint reconciliado: ausente a fingerprint financiero exacto;
5. el registro durable de reconciliación: transición normativa hasta `completed`.

El transaction ID admite como máximo una mutación funcional. Debe persistir
idéntico tras replay y jamás puede ser sobrescrito por otro valor.

Se prohíben cambios del total, moneda, customer, billing, shipping, line items,
fees, taxes, refunds, parent, created-via y claves de ownership. También se
prohíben accesos directos o asunciones sobre `wp_posts`, `wp_postmeta`, tablas
HPOS, nombres físicos, sincronización dual, autoridad de datastore o esquema.
La comprobación opera exclusivamente mediante `WC_Order` y APIs públicas de
WooCommerce, por lo que es agnóstica entre almacenamiento clásico y HPOS.

Asimismo, `is_paid()` no puede quedar en `false`, `date_paid` no puede quedar en
`null`, y el transaction ID no puede quedar vacío ni diferir de la referencia
durable. El replay no puede cambiarlo, crear otro pedido WooCommerce, duplicar
reconciliation ni duplicar completions. Ningún status concreto se prohíbe salvo
que otra norma productiva lo exija expresamente.

El harness no registra ni retira filtros o hooks para forzar un status. Hooks
externos preexistentes pueden cambiar el status final sin invalidar el caso,
siempre que los seis valores de `expected` anteriores y las mutaciones
permitidas se satisfagan exactamente. Cualquier mutación prohibida, referencia
inexacta, observable ausente o resultado distinto es fallo cerrado.

El status concreto posterior a `payment_complete()` no forma parte del resultado
funcional certificado: no se compara con literal o unión, no determina PASS o
FAIL, no es mutación obligatoria, no participa en cleanup y no evidencia
idempotencia. Si un filtro causa `is_paid() === false`, fecha ausente o transaction
ID incorrecto, el fallo corresponde a esos observables estables, no al status.

### 22.4 Alcance del cierre

Esta corrección cierra solamente el observable final WooCommerce de WR-06. No
materializa el fixture, no completa sus demás categorías y no completa los otros
treinta casos de la matriz.

**OBSERVABLE FINAL WOOCOMMERCE WR-06 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 19. Bloqueo material del registro literal `A11-WR-06`

El recurso lógico de §20 está cerrado, pero el registro literal WR-06 no puede
asignar sin invención el campo obligatorio `expected.final_order_status`.

Fuentes inspeccionadas:

- matriz A11 normativa: exige pago/pedido sin doble transición, pero no nombra el
  status WooCommerce final;
- matriz complementaria: exige transición única e HTTP idempotente, pero tampoco
  fija el status;
- `WooCommercePaymentCompletionHandler`: llama
  `$order->payment_complete($reference)` y acredita resultado por inspección de
  pago, transaction ID, fecha y metas;
- `woocommerce-payment-completion-integration-test.php`: exige `is_paid()`, fecha
  de pago no nula, transaction ID exacto, fingerprint y reconciliation completed;
  no exige `processing` ni `completed`;
- WooCommerce instalado, `WC_Order::payment_complete()`: calcula inicialmente
  `processing` si `needs_processing()` o `completed` en caso contrario, pero pasa
  ese valor por el filtro `woocommerce_payment_complete_order_status` antes de
  persistirlo.

Bloqueo exacto:

```text
case: A11-WR-06
category: expected
field: final_order_status
available contract: WC_Order::is_paid()=true; date_paid!=null;
                    transaction_id=<reference durable exacta>
missing contract: valor cerrado de WC_Order::get_status()
reason: el resultado depende de needs_processing() y de un filtro WooCommerce;
        A11 no fija ni prohíbe ese filtro y la prueba autoritativa no fija status
```

No se permite escoger `completed` por ausencia de order items, porque eso asume
que ningún callback modifica el filtro. Tampoco se permite escoger `processing`,
aceptar una unión abierta, quitar filtros, añadir un filtro test-only o cambiar
producto: cualquiera de esas decisiones alteraría el observable pedido o la
integración real. Una corrección futura debe fijar expresamente el valor o
redefinir el observable como un predicado cerrado ya acreditado por producto,
por ejemplo `is_paid=true`, sin exigir un status no estable.

Como una de las quince categorías continúa indeterminada, no se incorpora un
array PHP parcialmente materializable, no se crea validador WR-06 y los conteos
permanecen:

```text
Registro literal WR-06: 0/1
Categorías completas: 14/15 como máximo; expected incompleto
Validador WR-06: no ejecutado por precondición contractual fallida
```

**A11-WR-06 CONTINÚA BLOQUEADO**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 20. Primer bloqueo material para el registro literal WR-06

La construcción literal de `A11-WR-06` no puede cerrarse con los catálogos de
este documento. La matriz A11 exige un “Woo order A11” y transición única de
pago/pedido. El catálogo de `fixture_ids` de §6 y el catálogo de tablas de §7
solo admiten filas VeciAhorra y Action Scheduler; no declaran
`woocommerce_order_id`, `woocommerce_order`, su ownership, su creación ni su
cleanup.

Fuentes inspeccionadas:

- corrección normativa A11, caso `A11-WR-06`;
- corrección complementaria A11, fila `A11-WR-06`;
- `WooCommerceOrderRepositoryInterface`, que expone únicamente
  `find(int $orderId): ?object`;
- `WooCommerceOrderRepository`, que tampoco crea ni elimina pedidos;
- `woocommerce-payment-completion-integration-test.php`, que crea mediante
  `wc_create_order()` y limpia mediante `$order->delete(true)` fuera del contrato
  de fixtures aquí definido.

Bloqueo exacto:

```text
case: A11-WR-06
category: fixture_ids, rows_to_create, initial_state, cleanup, budget
field: woocommerce_order_id / woocommerce_order resource
missing: catálogo de referencia, ownership, create_via, columnas/propiedades,
         estado inicial, mutaciones, cleanup y presupuesto de operaciones Woo
reason: añadirlos ahora ampliaría los catálogos cerrados §§6–7 sin una decisión
        normativa explícita sobre almacenamiento WooCommerce (HPOS o posts)
```

No es válido fijar tablas físicas de WooCommerce: la implementación soporta
almacenamiento clásico u HPOS y la API pública abstrae esa decisión. Tampoco es
válido contar el pedido como fila `orders`, porque esa tabla es de VeciAhorra y
no representa un `WC_Order`. Una corrección futura debe autorizar expresamente
el recurso lógico `woocommerce_order`, la referencia
`@allocated.woocommerce_order_id`, creación exclusiva por `wc_create_order()`,
ownership mediante meta A11 exacta, eliminación por `$order->delete(true)` y un
presupuesto de API independiente de SQL físico. Hasta entonces WR-06 no es
materializable y el total permanece en 0/31 registros completos.

## 21. Contrato cerrado del recurso lógico `woocommerce_order`

`woocommerce_order` es un recurso lógico administrado exclusivamente mediante
la API pública WooCommerce. No es una decimoquinta tabla SQL VeciAhorra. Los
catálogos son: `sql_resources`=las 14 tablas de §7;
`api_resources`=`woocommerce_order`; `external_actions`=Action Scheduler por API.
Se prohíbe SQL directo sobre `wp_posts`, `wp_postmeta`, `wc_orders`,
`wc_order_addresses`, `wc_order_operational_data` o cualquier almacenamiento
interno. El contrato es agnóstico a almacenamiento clásico y HPOS.

### 20.1 APIs verificadas y repository

La instalación local declara `wc_create_order(array $args = [])`,
`wc_get_order(mixed $the_order = false)`, `WC_Data::get_id(): int`,
`save(): int`, `delete(bool $force_delete=false): bool`,
`get_meta(string $key='', bool $single=true, string $context='view'): mixed` y
`update_meta_data(string $key, string|array $value, int $meta_id=0): void`.
A11 solo acepta `WC_Order`, nunca `WC_Order_Refund`.

`WooCommerceOrderRepositoryInterface` y `WooCommerceOrderRepository` permanecen
read-only con `find(int $orderId): ?object`. No se añaden métodos productivos de
creación, guardado o eliminación; esas operaciones pertenecen al coordinator.

### 20.2 Referencia y ownership

Única referencia autorizada:

```text
@allocated.woocommerce_order_id
type=positive_int; source=WC_Order::get_id(); available=post-save y post-validación;
nullable=false; cardinality=1 solo WR-06; reusable=false; ownership/cleanup=required
```

Se prohíben `@fixture.woocommerce_order`, `@wc.order_id`, `@order.post_id` y
`@hpos.order_id`. No se detectó colisión local para las metas privadas exactas:

```text
_veciahorra_a11_ownership_token = @manifest.ownership_token
_veciahorra_a11_case_id = A11-WR-06
```

Ambas se escriben con `update_meta_data()`, se guardan antes de completar
allocation y se releen con `get_meta(<key>, true, 'edit')` antes del cleanup.
ID y ambas metas deben coincidir; discrepancia preserva el recurso y produce
`cleanup_failed`, exit 30.

### 20.3 Creación y estado inicial exactos

Única creación: `$order = wc_create_order();`. `WP_Error`, tipo distinto de
`WC_Order` o ID no positivo falla. Se configuran solo setters públicos y luego
se llama una vez `$order->save()`:

```php
[
 'resource_key'=>'woocommerce_order', 'resource_type'=>'api_resource',
 'logical_resource'=>'woocommerce_order', 'create_via'=>'wc_create_order',
 'identifier_reference'=>'@allocated.woocommerce_order_id',
 'ownership_reference'=>'@manifest.ownership_token',
 'attributes'=>[
  'status'=>'pending', 'currency'=>'CLP', 'total'=>'15990.00',
  'payment_method'=>'veciahorra_webpay_plus', 'transaction_id'=>'',
  'customer_id'=>0, 'billing_email'=>'', 'order_items'=>[],
  '_veciahorra_a11_ownership_token'=>'@manifest.ownership_token',
  '_veciahorra_a11_case_id'=>'A11-WR-06',
 ],
]
```

`wc_get_order($id)` debe acreditar `WC_Order`, ID exacto, status `pending`, CLP,
total `15990.00`, método exacto, transaction ID vacío, customer 0, metas exactas,
`date_paid=null` y `date_completed=null`. No hay productos, clientes, cupones,
reembolsos ni líneas reales.

### 20.4 `fixture_ids` e `initial_state`

```php
'woocommerce_order_id'=>[
 'source'=>'@allocated.woocommerce_order_id', 'type'=>'positive_int',
 'resource_type'=>'api_resource', 'logical_resource'=>'woocommerce_order',
 'primary_identifier'=>'WC_Order::get_id()', 'cardinality'=>1,
 'ownership_required'=>true, 'cleanup_required'=>true,
]
```

No tiene tabla física. `initial_state` contiene exactamente `order_id`, `status`,
`currency`, `total`, `payment_method`, `transaction_id`, `customer_id`,
`ownership_token`, `case_id`, `date_paid`, `date_completed`, con valores de
§20.3 y `order_id=@allocated.woocommerce_order_id`.

### 20.5 Manifest y crashes de allocation

Orden: manifest `allocating`; crear; asignar metas/atributos; save; obtener ID;
validar por `wc_get_order()`; persistir ID mediante temp+flock+flush+rename;
marcar `allocated`; continuar.

| Ventana infraestructura | Evidencia | Conducta |
|---|---|---|
| `crash_before_order_id` | no ID acreditado | exit 20; cleanup solo capturados |
| `crash_after_order_id_before_manifest_write` | pedido posible, ID solo en proceso muerto | exit 20; `untracked_owned_resource`; no búsqueda ni delete |
| `crash_after_manifest_write` | ID durable | verificar ID+metas y cleanup; exit 20 |

Estas no son las cinco crash windows productivas. La brecha inevitable anterior
al manifest se reporta; jamás se resuelve buscando por meta. Una excepción con
proceso vivo persiste el ID antes de limpiar.

### 20.6 Cleanup único

```php
$order = wc_get_order($woocommerceOrderId);
$order->delete(true);
```

Se usa solo el ID capturado. `false` al leer es `already_absent`. Tipo, ID o metas
distintas implican fail-closed, residuo y exit 30. `delete(true)!==true` falla.
Después, `wc_get_order($id)` debe retornar false. Se prohíbe buscar por status,
fecha, email, total, transaction ID, buy order, meta o pedidos recientes; limpiar
en masa; usar SQL o `wp_delete_post()`; y eliminar sin ownership.

```php
['step'=>1, 'target_type'=>'api_resource',
 'target_reference'=>'@allocated.woocommerce_order_id',
 'method'=>'WC_Order::delete(true)',
 'ownership_check'=>['_veciahorra_a11_ownership_token'=>'@manifest.ownership_token',
                     '_veciahorra_a11_case_id'=>'A11-WR-06'],
 'expected_count'=>1, 'missing_behavior'=>'continue_idempotently',
 'mismatch_behavior'=>'fail_closed']
```

### 20.7 Presupuesto API WR-06

El budget único añade seis claves, cero fuera de WR-06. Para WR-06:

```php
'woocommerce_order_create_max'=>1,
'woocommerce_order_save_max'=>1,
'woocommerce_order_read_max'=>4,
'woocommerce_order_delete_max'=>1,
'woocommerce_order_meta_write_max'=>2,
'woocommerce_order_meta_read_max'=>4,
```

Las reads cubren post-save, ejecución, pre-delete y post-delete; meta reads cubren
dos metas en validación y dos en cleanup. Se cuentan llamadas API del harness,
no SQL interno WooCommerce.

### 20.8 Integración limitada WR-06

WR-06 incorpora `logical_resource=woocommerce_order`,
`reference=@allocated.woocommerce_order_id`, `creation=wc_create_order()`, dos
metas de ownership, `cleanup=WC_Order::delete(true)`, storage clásico/HPOS opaco
y los seis contadores de §20.7. No se completan aquí sus otras categorías ni los
otros treinta casos.

**CONTRATO WOOCOMMERCE_ORDER A11 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 24. Contrato cerrado del token Webpay sintético A11

Esta sección prevalece exclusivamente sobre la generación, referencia,
almacenamiento efímero, transmisión y limpieza del token Webpay sintético A11.
Permanecen intactos el shape `variations`, el contrato `woocommerce_order`, el
observable final WooCommerce, el endpoint y método H4, los resultados
funcionales, las demás categorías y los contratos A1–A10.

### 24.1 Referencia, tipo y disponibilidad

La única referencia simbólica autorizada es:

```text
@allocated.webpay_token
type=string; format=/^[A-Z0-9]{32}$/D; length=32; nullable=false;
cardinality=1 por caso; available=después de allocate() y antes de H4;
created_in=allocation; product_persistence=forbidden;
manifest_plaintext_persistence=forbidden; WR-06 deliveries=2 con el mismo valor
```

Se prohíben `@fixture.token`, `@payload.token`, `@manifest.webpay_token` y
`@allocated.token_ws`. No hay aliases, normalización ni recuperación desde hash.

### 24.2 Algoritmo único

Después de validar `$caseId` y generar el ownership token normativo, `allocate()`
ejecuta literalmente:

```php
$material = $caseId . "\0" . $ownershipToken;
$digest = hash('sha256', $material, false);
$webpayToken = strtoupper(substr($digest, 0, 32));
```

El separador es exactamente un byte NUL (`"\0"`). `$caseId` y
`$ownershipToken` se concatenan como bytes UTF-8 sin normalización adicional.
El resultado pertenece al subconjunto `0-9A-F` de `[A-Z0-9]`, mide exactamente
32 bytes y es determinista y único para la combinación
`case_id + ownership_token`. No depende de reloj, PID, current working directory
ni aleatoriedad adicional.

El orden obligatorio es: generar `ownership_token`; validar `case_id`; calcular
`webpay_token`; calcular hash y máscara mediante `WebpayTokenReference`; escribir
el manifest; entregar el literal únicamente al proceso autorizado. No se genera
antes del ownership ni se regenera durante replay.

### 24.3 Manifest y secreto efímero

El manifest durable conserva exclusivamente:

```text
webpay_token_hash = WebpayTokenReference::hash(@allocated.webpay_token)
webpay_token_mask = WebpayTokenReference::masked(@allocated.webpay_token)
```

El coordinator reutiliza esas funciones productivas y no duplica sus algoritmos.
El manifest no puede contener las claves `webpay_token`, `token_ws`,
`token_plaintext`, `token_encoded` o `token_encrypted`, ni el literal como valor.

El literal se autoriza únicamente en el archivo secreto efímero:

```text
tests/manual/.a11-runtime/<manifest-id>.webpay-token
```

El contenido son exactamente los 32 bytes del token, sin newline, JSON ni
metadatos. El padre crea el directorio no versionado, rechaza una ruta existente
y escribe en un archivo temporal hermano mediante escritura completa, flush y
rename atómico. Append está prohibido y cualquier fallo es fail-closed. El nombre
se deriva del manifest ID aleatorio; no contiene el token.

El archivo nunca forma parte del manifest durable, Git, `artifacts/`, stdout,
stderr, logs o mensajes de excepción. En Windows no se presupone soporte POSIX;
la protección normativa es directorio no versionado, nombre derivado del
manifest ID, cero exposición y eliminación obligatoria. Las salidas solo pueden
mostrar `webpay_token_mask` producido por `WebpayTokenReference`.

### 24.4 Entrega a H4 y multiproceso

El coordinator entrega `@allocated.webpay_token` a H4 leyendo el archivo asociado
a la ruta exacta del manifest. H4 recibe únicamente `manifest_path`, deriva la
ruta secreta exacta, lee exactamente 32 bytes, valida `/^[A-Z0-9]{32}$/D`, compara
su hash mediante `hash_equals()` con `webpay_token_hash` y lo usa como `token_ws`.
No vuelve a escribirlo.

El literal no se pasa por argv, variables de entorno, stdin, nombre de archivo,
stdout, stderr ni JSON de resultados. En multiproceso el padre crea el secreto,
cada hijo recibe solo `manifest_path`, relee y verifica el mismo secreto sin
modificarlo, y el coordinator conserva la responsabilidad exclusiva de cleanup.

Para WR-06 existe un token generado, un hash durable, un archivo secreto y dos
lecturas autorizadas, una por entrega. Hay exactamente dos entregas y un único
token distinto observado. Ambas solicitudes contienen mecánicamente:

```php
'body' => [
    'token_ws' => '@allocated.webpay_token',
]
```

El replay reutiliza el archivo y el valor originales; no regenera, sustituye ni
crea una segunda referencia. Esto implementa exactamente
`token_policy=reuse_same_token` de `variations`.

### 24.5 Cleanup y recovery

Cleanup ejecuta en este orden:

1. termina los procesos que puedan leer el secreto;
2. verifica que la ruta exacta pertenece al manifest conocido;
3. intenta sobrescribir con bytes neutros solo cuando sea seguro y portable;
4. elimina el archivo secreto;
5. comprueba físicamente su ausencia;
6. continúa el cleanup del manifest.

La sobrescritura es best-effort y no decide éxito; la eliminación sí. Archivo
ausente es éxito idempotente. Hash incompatible, ruta fuera de
`tests/manual/.a11-runtime/` o longitud distinta son fail-closed. Una eliminación
fallida deja residuo y termina con exit no cero. Está prohibido localizar
secretos mediante glob, prefijo o búsqueda global.

Las ventanas de infraestructura son:

| Ventana | Conducta cerrada |
|---|---|
| crash después del manifest y antes del secreto | allocation incompleta; H4 no comienza; cleanup elimina manifest parcial |
| crash después de crear el secreto | el hash del manifest y su ruta conocida permiten eliminar el archivo exacto |
| crash durante H4 | el secreto permanece para recovery y replay hasta cleanup del coordinator |
| crash del coordinator | recovery recibe el manifest path conocido y elimina el secreto exacto, sin discovery global |

### 24.6 Presupuesto y prohibiciones

WR-06 incorpora exactamente estos máximos, sin sustituir budgets HTTP o
funcionales posteriores:

```php
'webpay_token_generate_max' => 1,
'webpay_token_secret_write_max' => 1,
'webpay_token_secret_read_max' => 2,
'webpay_token_hash_verify_max' => 2,
'webpay_token_secret_delete_max' => 1,
```

Se prohíben token aleatorio no derivado, `random_bytes()` adicional, `uniqid()`,
timestamp, PID, UUID, base64, caracteres no alfanuméricos, algoritmos alternativos,
token literal en manifest/Git/logs/argv/env, cifrado reversible desde el hash,
regeneración durante replay y búsqueda de secretos mediante glob.

**CONTRATO TOKEN WEBPAY SINTÉTICO A11 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 25. Identidades sintéticas de checkout y sesión Webpay para WR-06

Esta sección prevalece exclusivamente sobre la asignación A11 del public ID del
checkout, la idempotency key de la payment session y las referencias Webpay
derivadas de ambas. No altera sus validadores, generadores o algoritmos
productivos, ni completa por sí sola `payload` u otra categoría WR-06.

### 25.1 Checkout public ID asignado

La referencia única es `@allocated.checkout_public_id`. Su contrato es:

```text
type=string; format=/^chk_[A-Za-z0-9_-]{43}$/D; length=47;
prefix=chk_; suffix=43 base64url characters without padding; nullable=false;
cardinality=1; created_in=allocation; available=before checkout INSERT;
persisted_to=checkouts.public_id; manifest_field=checkout_public_id;
cleanup=checkout row by captured positive PK after exact identity verification
```

El algoritmo A11 exacto es:

```php
$checkoutMaterial = "veciahorra-a11.checkout-public-id.v1\0"
    . $caseId . "\0" . $ownershipToken;
$checkoutDigest = hash('sha256', $checkoutMaterial, true);
$checkoutPublicId = 'chk_' . rtrim(strtr(
    base64_encode($checkoutDigest),
    '+/',
    '-_'
), '=');
```

La salida de SHA-256 raw son 32 bytes y su codificación base64url sin padding son
exactamente 43 caracteres; por tanto satisface `Checkout::validPublicId()` sin
copiar `Checkout::publicId()`. La unicidad pertenece a la combinación de dominio,
`case_id` y ownership criptográfico. Los bytes de entrada son UTF-8 sin
normalización. No se consulta la base para descubrir este valor; solo puede
releerse la fila por PK capturada para verificar igualdad estricta.

### 25.2 Idempotency key asignada

La referencia única es `@allocated.payment_session_idempotency_key`:

```text
type=string; format=/^a11-pay-[a-f0-9]{64}$/D; length=72;
prefix=a11-pay-; casing=lowercase hex; nullable=false; cardinality=1;
created_in=allocation; available=before payment_sessions INSERT;
persisted_to=payment_sessions.idempotency_key;
manifest_field=payment_session_idempotency_key;
cleanup=payment session row by captured positive PK after identity verification
```

El algoritmo A11 exacto es:

```php
$idempotencyMaterial = "veciahorra-a11.payment-session-idempotency.v1\0"
    . $caseId . "\0" . $ownershipToken;
$paymentSessionIdempotencyKey = 'a11-pay-' . hash(
    'sha256',
    $idempotencyMaterial,
    false
);
```

Sus 72 caracteres pertenecen a `[A-Za-z0-9._:-]` y al rango 16..128 aceptado
por `IdempotencyService::key()`. Es distinta del checkout public ID por dominio,
formato y prefijo. Se prohíben trim, case folding, truncamiento o hash adicional.

### 25.3 Referencias Webpay derivadas

`@derived.webpay_buy_order` no se asigna ni se construye manualmente. Se deriva
una vez, después de disponer de ambos inputs, exclusivamente mediante:

```php
$webpayBuyOrder = WebpayTransactionReference::buyOrder(
    $checkoutPublicId,
    $paymentSessionIdempotencyKey
);
```

Contrato: `string`, `/^VA[A-F0-9]{24}$/D`, longitud 26, no nulo, cardinalidad
uno, disponible antes de persistir el origin y configurar el stub, manifest
field `webpay_buy_order`. Se persiste en `payment_origin_contexts.buy_order`, se
entrega al gateway stub y `WebpayReturnService` lo compara con igualdad estricta.
Primera entrega y replay usan el mismo valor.

`@derived.webpay_session_id` se deriva una vez exclusivamente mediante:

```php
$webpaySessionId = WebpayTransactionReference::sessionId($checkoutPublicId);
```

Contrato: `string`, `/^VA-[A-F0-9]{58}$/D`, longitud 61, no nulo, cardinalidad
uno, disponible antes de persistir el origin y configurar el stub, manifest
field `webpay_session_id`. Se persiste en
`payment_origin_contexts.financial_session_id`, se entrega al stub y se compara
estrictamente durante el retorno. Es idéntico en ambas entregas.

Los algoritmos internos de `buyOrder()` y `sessionId()` no se copian al
coordinator: las funciones productivas son la única autoridad derivadora.

### 25.4 Manifest, orden y prohibiciones

Antes de insertar filas se conocen `case_id`, ownership,
`checkout_public_id`, `payment_session_idempotency_key`, `webpay_buy_order`,
`webpay_session_id`, `webpay_token_hash`, `webpay_token_mask`, `amount=15990`,
`currency=CLP`, `webpay_status=AUTHORIZED` y `webpay_response_code=0`. El literal
del token continúa únicamente en el secreto de §24. El manifest usa campos
distintos y tipados para cada valor; ninguno se descubre después por consultas.

Orden exacto: ownership → dos identidades asignadas → referencias productivas
derivadas → hash/máscara del token → manifest → inserts por valores conocidos.
Las lecturas posteriores solo verifican por PK capturada y nunca son discovery.

Se prohíbe usar directamente `case_id`, ownership o token Webpay como checkout
public ID o idempotency key; reutilizar un valor para ambas identidades; inventar
literales por caso; usar `@checkout.public_id`, `@session.idempotency_key`,
`@allocated.buy_order` o `@allocated.session_id`; descubrir por consultas;
omitir buy order/session ID; copiar o imitar el algoritmo interno de las funciones
productivas; y aceptar nombres informales no declarados en el manifest.

**CONTRATO IDENTIDADES CHECKOUT/PAYMENT SESSION WR-06 CERRADO**

El cierre integral de `payload` permanece pendiente del siguiente vector
financiero no fijado por esta sección.

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 26. Código de autorización y continuación ordenada del vector WR-06

Esta sección prevalece exclusivamente sobre el código de autorización sintético
de WR-06 y los campos posteriores del vector financiero que cierra expresamente.
No completa `payload` mientras permanezca el bloqueo indicado en §26.4.

### 26.1 Autoridad y asignación de `authorization_code`

`WebpayCommitResult` declara `?string $authorizationCode` y
`WebpayPaymentGateway::optionalString()` acepta el valor retornado por
`getAuthorizationCode()` únicamente cuando es string y `trim($value) !== ''`;
normaliza mediante `trim`. No existe un literal canónico: los fixtures aprobados
usan múltiples códigos no equivalentes como literal. Por ello WR-06 adopta una
asignación sintética domain-separated, no `null`.

La referencia autoritativa es `@fixture.webpay_authorization_code`:

```text
type=string; format=/^A11[A-F0-9]{13}$/D; length=16; casing=uppercase;
nullable=false; cardinality=1; created_in=fixture allocation;
available=before manifest and gateway stub configuration;
manifest_field=webpay_authorization_code;
product_plaintext_persistence=forbidden;
persisted_evidence=webpay_returns.authorization_code_hash;
cleanup=manifest deletion; replay=reuse exact value
```

Algoritmo exacto:

```php
$authorizationMaterial = "veciahorra-a11.webpay-authorization-code.v1\0"
    . $caseId . "\0" . $ownershipToken;
$webpayAuthorizationCode = 'A11' . strtoupper(substr(hash(
    'sha256',
    $authorizationMaterial,
    false
), 0, 13));
```

El resultado es un string no vacío que no cambia bajo `trim`, usa solo ASCII
uppercase y satisface la adaptación productiva. Es único por dominio, caso y
ownership; no reutiliza directamente case ID, ownership, token, buy order,
session ID o idempotency key y no requiere discovery.

El stub entrega el mismo literal en primera entrega y replay. Antes de construir
`FinancialFingerprintComponents`, la autoridad productiva ejecuta
`FinancialFingerprintComponents::authorizationHash($code)`, equivalente a
`hash('sha256', $code)`. Solo ese hash lowercase de 64 caracteres se persiste en
`webpay_returns.authorization_code_hash` y participa en el canonical data del
fingerprint bajo `authorization_hash`. El literal permanece únicamente en el
manifest temporal conocido y desaparece con su cleanup.

Aliases prohibidos: `auth_code`, `authorization`, `webpay_auth`,
`gateway_auth_code`, `@allocated.authorization_code`,
`@derived.authorization_code` y cualquier literal copiado de otro fixture.

**CONTRATO AUTHORIZATION_CODE WEBPAY WR-06 CERRADO**

### 26.2 `payment_type_code`

La auditoría del siguiente argumento del constructor encuentra una convención
única en los vectores locales aprobados y en las pruebas de fingerprint:

```text
gateway_result.payment_type_code = "VD"
type=string; format=/^[A-Z0-9._:-]{1,4}$/D; length=2; nullable=false;
manifest_field=webpay_payment_type_code; persisted_to=webpay_returns.payment_type_code
```

`FinancialFingerprintComponents` aplica `strtoupper(trim())` y lo incorpora como
`payment_type_code`. El stub entrega `VD` en ambas entregas. Se prohíben null,
otros códigos, normalización alternativa y aliases `payment_type` o `type_code`.

### 26.3 `installments_number`

El argumento siguiente queda fijado por los mismos vectores aprobados:

```text
gateway_result.installments_number = 0
type=int; nullable=false; minimum=0; manifest_field=webpay_installments_number;
persisted_to=webpay_returns.installments_number
```

El valor entero `0` participa en el fingerprint y es idéntico en entrega inicial
y replay. Se prohíben string `"0"`, null, coerción y aliases.

### 26.4 Siguiente bloqueo material

El siguiente argumento real de `WebpayCommitResult` es `accountingDate`. Las
fuentes locales aprobadas usan, sin selector normativo, `0712`, `0713`, `0714` y
`0715`; otras rutas permiten null. A11 no fija una fecha contable, una relación
con el reloj ni un algoritmo sintético para WR-06. Elegir un literal o null
cambiaría el canonical data y el fingerprint financiero.

```text
case=A11-WR-06
category=payload
field=gateway_result.accounting_date
status=blocked
reason=multiple admissible local vectors and no A11 selector or generation rule
```

No se incorpora un `payload` parcial ni se auditan argumentos posteriores hasta
resolver este campo.

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 27. Contrato temporal Webpay cerrado para WR-06

Esta sección prevalece exclusivamente sobre la autoridad temporal sintética y
los campos `accounting_date` y `transaction_date` de WR-06. No modifica los
relojes productivos de recepción, validación o persistencia.

### 27.1 Autoridad temporal sintética única

No existe un reloj A11 previo compatible ni una relación productiva que seleccione
uno de los literales históricos. El dominio admite múltiples instantes válidos:
`WebpayCommitResult` acepta ambos campos como `?string`, el gateway los obtiene
como strings opcionales y `FinancialFingerprintComponents` valida
`transaction_date` como UTC y `accounting_date` como código no vacío de máximo
10 caracteres. WR-06 exige evidencia aprobada completa, por lo que ambos quedan
no nulos y derivados de un único reloj sintético.

La referencia fuente es `@fixture.webpay_clock_utc`. Se genera exactamente así:

```php
$clockMaterial = "veciahorra-a11.webpay-clock.v1\0"
    . $caseId . "\0" . $ownershipToken;
$clockDigest = hash('sha256', $clockMaterial, false);
$clockDayOffset = hexdec(substr($clockDigest, 0, 8)) % 3650;
$webpayClockUtc = (new DateTimeImmutable(
    '2030-01-01T12:00:00.000000Z',
    new DateTimeZone('UTC')
))->add(new DateInterval('P' . $clockDayOffset . 'D'));
```

Contrato: instante entre 2030-01-01 y 2039-12-29 inclusive, timezone UTC,
precisión de segundos para el vector Webpay, cardinalidad uno, disponible durante
allocation antes del manifest, manifest field `webpay_clock_utc` serializado
`Y-m-d\TH:i:s\Z`. La hora fija 12:00:00 y UTC eliminan cambios por DST. No usa el
reloj real, WordPress, MySQL, PHP “now”, sistema operativo, PID o fecha del run.

### 27.2 Campos derivados

`@derived.webpay_transaction_date` se obtiene exclusivamente mediante:

```php
$webpayTransactionDate = $webpayClockUtc->format('Y-m-d\TH:i:s\Z');
```

Es `string`, formato `/^20(?:3[0-9])-\d{2}-\d{2}T12:00:00Z$/D`, longitud 20,
UTC, no nulo, manifest field `webpay_transaction_date`. El gateway stub lo entrega
como `gateway_result.transaction_date`; se persiste en
`webpay_returns.transaction_date` y participa en el fingerprint bajo
`transaction_date` tras `ReconciliationValidation::utcDate()`.

`@derived.webpay_accounting_date` se obtiene del mismo instante:

```php
$webpayAccountingDate = $webpayClockUtc->format('md');
```

Es `string`, formato `/^(?:0[1-9]|1[0-2])(?:0[1-9]|[12][0-9]|3[01])$/D`,
longitud 4, semántica `MMDD` en UTC, no nulo, manifest field
`webpay_accounting_date`. El stub lo entrega como
`gateway_result.accounting_date`; se persiste en
`webpay_returns.accounting_date` y participa en el fingerprint bajo
`accounting_date`.

La relación obligatoria es:

```text
webpay_accounting_date
= MMDD UTC de webpay_transaction_date
= format('md') de @fixture.webpay_clock_utc
```

No existe año implícito independiente: el año autoritativo vive en
`webpay_transaction_date` y el accounting date es su proyección contable `MMDD`.
Ambos valores son idénticos en entrega inicial y replay.

### 27.3 Matriz de autoridades temporales

| Campo | Tipo/formato | Zona | Fuente | Persistencia | Fingerprint | Replay |
|---|---|---|---|---|---|---|
| `webpay_clock_utc` | instante / `Y-m-dTH:i:sZ` | UTC | fixture domain-separated | manifest solamente | no directo | idéntico |
| `gateway_result.transaction_date` | string ISO UTC, 20 | UTC | reloj A11 | `webpay_returns.transaction_date` | sí | idéntico |
| `gateway_result.accounting_date` | string `MMDD`, 4 | UTC | proyección del mismo reloj | `webpay_returns.accounting_date` | sí | idéntico |
| recepción del return | datetime MySQL | reloj productivo | `current_time()` productivo | timestamps de inbox | no | puede diferir |
| validación financiera | datetime MySQL | reloj productivo | repositorio/materializer | `financial_validated_at` | no | evidencia ya persistida |
| creación/actualización | datetime MySQL | reloj productivo | repositorios | `created_at`,`updated_at` | no | puede diferir |

Los tres timestamps productivos no son inputs del gateway result, no se copian al
manifest como reloj del fixture y no cambian los dos campos del fingerprint.

### 27.4 Manifest, cleanup y prohibiciones

El manifest contiene tres campos distintos: `webpay_clock_utc`,
`webpay_transaction_date` y `webpay_accounting_date`. El primero es productor;
los otros dos son proyecciones. Están disponibles antes de configurar el stub,
son consumidos en ambas entregas y desaparecen con el manifest. No se descubren
desde filas.

Se prohíben aliases `accounting`, `account_date`, `webpay_account_date`,
`transaction_at`, `transaction_time`, `webpay_date`, `gateway_date`; fecha actual
del sistema; `current_time()` como fuente del vector; `date()` sin reloj;
`new DateTimeImmutable()` sin argumento; `CURRENT_TIMESTAMP`; discovery desde
filas; derivar `MMDD` de otro timestamp; literales históricos `0712`, `0713`,
`0714` o `0715`; timezone local; y cambiar fechas durante replay.

**CONTRATO ACCOUNTING_DATE WEBPAY WR-06 CERRADO**

**CONTRATO TEMPORAL WEBPAY WR-06 CERRADO**

### 27.5 Siguiente bloqueo material

Tras cerrar `accountingDate` y `transactionDate`, el siguiente argumento de
`WebpayCommitResult` es `cardLastFour`. Los fixtures aprobados usan valores
distintos (`1234`, `6623`) y otras rutas permiten null. A11 no contiene selector,
valor canónico ni algoritmo sintético para WR-06. Elegir cualquiera alteraría el
resultado JSON almacenado, aunque el campo no integra el canonical data del
fingerprint financiero.

```text
case=A11-WR-06
category=payload
field=gateway_result.card_last_four
status=blocked
reason=multiple admissible vectors and no A11 selector or generation rule
```

No se incorpora un payload parcial ni se audita `balance` hasta resolver este
campo.

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 28. Contrato `card_last_four` Webpay cerrado para WR-06

Esta sección prevalece exclusivamente sobre el detalle de cuatro dígitos del
vector financiero WR-06. No introduce PAN, no altera el fingerprint y no cierra
`payload` mientras permanezca el bloqueo de §28.4.

### 28.1 Autoridad productiva y representación

`WebpayCommitResult` declara `?string $cardLastFour`. La única adaptación
productiva vive en `WebpayPaymentGateway::commitResult()`: lee
`getCardDetail()`, acepta `card_detail['card_number']` solo si es string y cumple
`/^\d{4}$/D`, y en otro caso produce `null`. No convierte a entero, por lo que
los ceros iniciales son significativos.

`WebpayCommitResult::toArray()` serializa siempre la clave exacta
`card_last_four`, cuyo valor JSON es string o null. `WebpayReturnService` conserva
esa estructura dentro de `WebpayReturnResult.financial`; la evidencia queda en
`webpay_returns.result_json`. No existe columna separada. El campo no integra
`FinancialFingerprintComponents::canonicalData()` y por tanto no participa en el
fingerprint ni en la referencia de transacción WooCommerce.

### 28.2 Asignación sintética cerrada

No existe tarjeta o literal canónico WR-06 y el dominio admite múltiples strings
de cuatro dígitos. La referencia definitiva es
`@fixture.webpay_card_last_four`:

```text
type=string; format=/^[0-9]{4}$/D; length=4; nullable=false;
leading_zeroes=preserved; cardinality=1; created_in=fixture allocation;
manifest_field=webpay_card_last_four; json_key=card_last_four;
persisted_inside=webpay_returns.result_json.financial.card_last_four;
fingerprint=false; replay=reuse exact value; cleanup=manifest/fixture row deletion
```

Algoritmo exacto:

```php
$cardMaterial = "veciahorra-a11.webpay-card-last-four.v1\0"
    . $caseId . "\0" . $ownershipToken;
$cardDigest = hash('sha256', $cardMaterial, false);
$cardNumber = hexdec(substr($cardDigest, 0, 8)) % 10000;
$webpayCardLastFour = str_pad(
    (string) $cardNumber,
    4,
    '0',
    STR_PAD_LEFT
);
```

El módulo produce el rango 0..9999 y `str_pad` garantiza exactamente cuatro
caracteres sin convertir el resultado final en entero. El valor es determinista,
domain-separated y único para la evidencia del fixture, aunque no se usa como
identidad ni se exige unicidad global. No depende del reloj, PK, consulta, token,
buy order o session ID.

El stub configura el mismo string en entrega inicial y replay. La relectura del
JSON exige tipo string e igualdad estricta con el manifest; no regenera ni
normaliza el valor.

### 28.3 Privacidad, exposición y aliases

El fixture nunca crea, recibe o persiste un PAN completo. Solo controla el campo
de cuatro dígitos admitido por el adapter. No se usa en nombres de archivo,
ownership, búsquedas, cleanup, IDs o logs adicionales. Su exposición queda
limitada a la estructura financiera que ya produce el producto.

Se prohíben `last_four`, `card_digits`, `card_number`, `last4`, `card_last4`,
`webpay_last_four`, `@allocated.card_last_four`, `@derived.card_last_four`;
literales históricos `1234` o `6623`; entero de cuatro dígitos; PAN completo;
discovery mediante consultas; y derivación desde token, buy order, session ID o
IDs persistidos.

**CONTRATO CARD_LAST_FOUR WEBPAY WR-06 CERRADO**

### 28.4 Siguiente bloqueo material: `balance`

El último argumento de `WebpayCommitResult` es `int|float|null $balance`. El
adapter retorna el valor de `getBalance()` solo cuando es int o float; en otro
caso retorna null. `toArray()` lo serializa bajo `balance` y el replay lo
reconstruye conservando int/float/null. No se persiste en columna separada y no
participa en el fingerprint.

Las fuentes aprobadas usan tanto `0` como `null`, pero no fijan si WR-06 representa
saldo informado de cero, campo no soportado, no aplicable o saldo desconocido.
A11 tampoco define unidad o semántica para este caso. La nulabilidad técnica no
resuelve esa diferencia y elegir `0` o null cambiaría el JSON financiero.

```text
case=A11-WR-06
category=payload
field=gateway_result.balance
status=blocked
reason=0 and null are both productively admissible without an A11 applicability selector
```

No se incorpora un payload parcial ni se cierra el JSON financiero hasta resolver
este campo.

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 29. Balance, resultado tipado y JSON financiero WR-06

Esta sección prevalece sobre la política de `balance` del fixture WR-06 y cierra
la representación completa de `WebpayCommitResult` y del JSON persistido. La
selección de `balance` es normativa de A11, no una conclusión universal sobre
Webpay Plus, débito `VD`, el SDK o el adapter.

### 29.1 Contrato normativo de `balance`

La referencia `@fixture.webpay_balance` tiene el contrato exacto:

```text
PHP type=null; value=null; JSON key=balance; JSON value=null; key=required;
manifest_field=webpay_balance; manifest value=null; column=none;
persisted_inside=webpay_returns.result_json.financial.balance;
fingerprint=false; initial=null; replay=null; discovery=forbidden;
cleanup=manifest and owned webpay_returns row deletion
```

Su única semántica es: **WR-06 no afirma un saldo financiero numérico.** No
significa saldo cero, tarjeta sin saldo, saldo insuficiente, no aplicabilidad a
débito, soporte universal ausente, saldo desconocido, error de gateway, default
financiero o ausencia de la clave. La autoridad es este contrato A11-WR-06. El
adapter solo acredita que null es representable y serializable.

`WebpayCommitResult::toArray()` siempre incluye `balance`; por tanto quedan
prohibidos `0`, `0.0`, `"0"`, string vacío, `"null"`, false y clave ausente. El
stub, objeto productivo, array, manifest y replay conservan null sin coerción.

Aliases prohibidos: `gateway_balance`, `payment_balance`, `card_balance`,
`remaining_balance`, `saldo`, `webpay_saldo`, `available_balance`,
`balance_amount`, `@allocated.webpay_balance` y `@derived.webpay_balance`.
También se prohíben número sintético, derivación desde cualquier otro campo y
discovery.

**CONTRATO BALANCE WEBPAY WR-06 CERRADO**

### 29.2 Tabla integral de `WebpayCommitResult`

El constructor termina en `balance`; no existen argumentos posteriores ni
propiedades adicionales serializadas por `toArray()`.

| Orden | Campo | Tipo PHP | Nullable WR-06 | Valor/referencia | Manifest | JSON | Persistencia | Fingerprint | Replay |
|---:|---|---|---|---|---|---|---|---|---|
| 1 | `status` | string | no | `AUTHORIZED` | `webpay_status` | string | result JSON + columna `provider_status` | sí | igual |
| 2 | `responseCode` | int | no | `0` | `webpay_response_code` | number int | result JSON + columna `response_code` | sí | igual |
| 3 | `amount` | int | no | `15990` | `webpay_amount` | number int | result JSON + columna `amount_clp` | sí | igual |
| 4 | `buyOrder` | string | no | `@derived.webpay_buy_order` | `webpay_buy_order` | string | result JSON + columna `buy_order` | sí | igual |
| 5 | `sessionId` | string | no | `@derived.webpay_session_id` | `webpay_session_id` | string | result JSON + `financial_session_id` | sí | igual |
| 6 | `authorizationCode` | ?string | no | `@fixture.webpay_authorization_code` | `webpay_authorization_code` | string | result JSON; solo hash en columna | hash | igual |
| 7 | `paymentTypeCode` | ?string | no | `VD` | `webpay_payment_type_code` | string | result JSON + `payment_type_code` | sí | igual |
| 8 | `installmentsNumber` | ?int | no | `0` | `webpay_installments_number` | number int | result JSON + `installments_number` | sí | igual |
| 9 | `accountingDate` | ?string | no | `@derived.webpay_accounting_date` | `webpay_accounting_date` | string | result JSON + `accounting_date` | sí | igual |
| 10 | `transactionDate` | ?string | no | `@derived.webpay_transaction_date` | `webpay_transaction_date` | string | result JSON + `transaction_date` | sí | igual |
| 11 | `cardLastFour` | ?string | no | `@fixture.webpay_card_last_four` | `webpay_card_last_four` | string | result JSON solamente | no | igual |
| 12 | `balance` | int\|float\|null | sí, null obligatorio | `@fixture.webpay_balance` | `webpay_balance` | null | result JSON solamente | no | igual |

La moneda `CLP` pertenece al vector canónico financiero y al origin; no es un
argumento ni una clave de `WebpayCommitResult::toArray()`.

### 29.3 Salida completa de `WebpayCommitResult::toArray()`

La autoridad productiva siempre produce exactamente estas doce claves, en el
orden mostrado por el método; el orden no decide igualdad semántica ni se hashea:

```php
[
    'status' => 'AUTHORIZED',
    'response_code' => 0,
    'amount' => 15990,
    'buy_order' => '@derived.webpay_buy_order',
    'session_id' => '@derived.webpay_session_id',
    'authorization_code' => '@fixture.webpay_authorization_code',
    'payment_type_code' => 'VD',
    'installments_number' => 0,
    'accounting_date' => '@derived.webpay_accounting_date',
    'transaction_date' => '@derived.webpay_transaction_date',
    'card_last_four' => '@fixture.webpay_card_last_four',
    'balance' => null,
]
```

No hay claves omitidas. `balance` es el único null en WR-06. No se transforman
nombres o tipos durante replay.

**CONTRATO WEBPAYCOMMITRESULT TOARRAY WR-06 CERRADO**

### 29.4 JSON financiero persistido

`WebpayReturnService::finalize()` construye `WebpayReturnResult` con
`financial=WebpayCommitResult::toArray()`. `WebpayReturnResult::toArray()` crea la
raíz siguiente; `WebpayReturnRepository::complete()` la codifica mediante
`wp_json_encode($result, JSON_UNESCAPED_SLASHES)` y falla si no obtiene string:

```php
[
    'result' => 'approved',
    'payment_session_id' => '@allocated.payment_session_id',
    'token_reference' => '@derived.webpay_token_mask',
    'business_state_updated' => false,
    'financial' => [
        // exactamente las doce claves y valores de §29.3
    ],
]
```

La raíz no es el array financiero directo. `previous_result` está ausente en la
primera persistencia porque es null; `publicCheckoutId` no es serializado por
`toArray()`. `payment_session_id` es JSON number int positivo y
`business_state_updated` es JSON boolean false. `token_reference` se deriva
exclusivamente mediante `WebpayTokenReference::masked(@allocated.webpay_token)`
y corresponde al manifest field ya cerrado `webpay_token_mask`.

El replay no reescribe `result_json`: `WebpayReturnService::repeated()` relee el
wrapper, obtiene `stored['financial']`, reconstruye el mismo commit y retorna
`already_processed`. La evidencia persistida permanece byte-equivalente salvo
que ninguna comparación normativa dependa del orden JSON.

**JSON FINANCIERO WR-06 CERRADO**

### 29.5 Cierre de balance y siguiente auditoría

El body HTTP sigue conteniendo únicamente `token_ws`; ningún campo financiero se
añade al POST. Entrega inicial y replay usan el mismo vector configurado, aunque
solo la primera invoca `commit()` y persiste el JSON.

Para cerrar `payload` resta acreditar el vector canónico del fingerprint completo
y la referencia de transacción. El primer componente de
`FinancialFingerprintComponents::canonicalData()` es `environment`. Las fuentes
locales permiten `integration` y `production`; A11 exige gateway mock/local pero
no ha seleccionado todavía cuál se persiste como ambiente financiero de WR-06.

```text
case=A11-WR-06
category=payload
field=financial_fingerprint.environment
status=blocked
reason=integration and production are productively valid; local/mock does not select the persisted financial environment
```

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**
