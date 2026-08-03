# Corrección normativa complementaria A11 — contratos ejecutables cerrados

## 1. Autoridad, alcance y allowlist definitiva

Este documento complementa y, ante una diferencia, prevalece sobre
`durable-retry-production-activation-a11-normative-correction.md` únicamente en
firmas de helpers, protocolo de pruebas, decorators, acceso al materializer y
detalle de casos. No cambia autoridad ni semántica A1–A10.

La allowlist posterior tiene exactamente doce rutas:

```text
app/Core/Application.php
app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php
app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php
tests/manual/durable-completion-orchestration-test.php
tests/manual/durable-retry-a11-operational-acceptance-test.php
tests/manual/durable-retry-a11-multiprocess-concurrency-test.php
tests/manual/durable-retry-a11-crash-recovery-test.php
tests/manual/durable-retry-a11-webpay-replay-test.php
tests/manual/durable-retry-a11-legacy-exclusion-test.php
tests/manual/support/durable-retry-a11-coordinator.php
tests/manual/support/durable-retry-a11-child-worker.php
tests/manual/support/durable-retry-a11-http-webpay-stub.php
```

Las primeras cuatro son modificadas; las últimas ocho son los ocho archivos A11
nuevos exactos. Después de su adopción, este documento queda protegido y fuera
de la allowlist de implementación. Quedan desplazadas y prohibidas, entre otras,
`tests/manual/support/DurableRetryA11Orchestrator.php` y
`tests/manual/support/DurableRetryA11Fixture.php`. No se crea ningún cuarto
helper, DTO separado, decorator separado ni runner separado.

## 2. Namespace, clases compartidas y firmas cerradas

Los tres helpers declaran `namespace VeciAhorra\Tests\Manual\A11;` y se cargan
con `require_once`, nunca mediante autoload productivo. Todas sus clases son
`final` y comienzan por `DurableRetryA11`.

`tests/manual/support/durable-retry-a11-coordinator.php` declara, en este orden:

```php
final class DurableRetryA11Invocation
{
    public function __construct(
        public readonly string $operation,
        public readonly string $caseId,
        public readonly string $runId,
        public readonly array $payload,
        public readonly ?string $crashPoint = null,
        public readonly int $timeoutSeconds = 30
    ) {}
}

final class DurableRetryA11ProcessResult
{
    public function __construct(
        public readonly string $operation,
        public readonly string $caseId,
        public readonly int $pid,
        public readonly array $childPids,
        public readonly string $startedAt,
        public readonly string $finishedAt,
        public readonly int $durationMilliseconds,
        public readonly ?int $exitCode,
        public readonly bool $timedOut,
        public readonly bool $crashed,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?array $result,
        public readonly string $cleanupStatus
    ) {}
}

final class DurableRetryA11Coordinator
{
    public function __construct(
        private readonly string $phpBinary,
        private readonly string $wpLoadPath,
        private readonly string $tempRoot
    ) {}

    public function guardEnvironment(): array;
    public function allocate(string $caseId, array $fixture): string;
    public function run(DurableRetryA11Invocation $invocation): DurableRetryA11ProcessResult;
    public function runConcurrent(DurableRetryA11Invocation ...$invocations): array;
    public function release(string $runId, string $caseId): void;
    public function startHttpServer(string $manifestPath, int $timeoutSeconds = 10): array;
    public function stopHttpServer(array $server): void;
    public function cleanup(string $manifestPath): string;
}
```

`guardEnvironment()` retorna el shape cerrado
`array{environment:string,database_host:string,gateway:string,action_scheduler:string,tables:array<string,string>}`
o lanza `RuntimeException`. `allocate()` retorna la ruta absoluta del manifiesto.
`runConcurrent()` retorna `list<DurableRetryA11ProcessResult>` en el orden de las
invocaciones, no en orden de término. `startHttpServer()` retorna exactamente
`array{process:resource,pid:int,port:int,stdout_path:string,stderr_path:string,manifest_path:string}`.
Los otros métodos lanzan
`InvalidArgumentException` por contrato inválido, `RuntimeException` por fallo
funcional/infraestructura y `UnexpectedValueException` por evidencia corrupta.

## 3. Ownership y coordinator único de H1–H5

El coordinator es la única autoridad sobre directorios temporales, manifiestos,
procesos, barreras, servidor HTTP, stdout, stderr, timeouts y cleanup. Cada H1–H5
construye exactamente una instancia con:

```php
new DurableRetryA11Coordinator(
    PHP_BINARY,
    dirname(__DIR__, 4) . '/wp-load.php',
    sys_get_temp_dir() . '/veciahorra-a11'
)
```

`run()` ejecuta exactamente `PHP_BINARY <child-path> <manifest-path> <sha256>`.
No incluye secretos en argv. Crea el proceso con `proc_open()`, captura PID desde
`proc_get_status()`, usa pipes no bloqueantes, drena stdout/stderr cada máximo 20
ms y aplica el timeout de la invocación con reloj monotónico. Al timeout termina
primero los hijos capturados y luego el principal; espera 2 s y fuerza kill solo
sobre esos PIDs. Nunca mata por nombre de proceso.

Un crash intencional es válido únicamente si existe `entered.<role>.<pid>`, el
coordinator verificó la evidencia durable requerida y luego ejecutó
`proc_terminate($process, 9)`. `crashed=true` exige `exitCode !== 0`,
`timedOut=false` y crash point reconocido. Un exit espontáneo no acredita crash.

En `finally`, el coordinator detiene únicamente procesos propios, cancela
acciones exactas por API pública, elimina fixtures por IDs del manifiesto en
orden inverso, borra locks/barreras y elimina el directorio solo en PASS. El
resultado usa `cleanupStatus` cerrado: `clean`, `preserved_failure` o
`cleanup_failed`. Solo `clean` permite PASS.

## 4. Protocolo harness → coordinator → child

El transporte único es un manifiesto JSON UTF-8 por ruta absoluta más SHA-256 en
argv. No se usa stdin, payload JSON en argv ni variables de entorno salvo
`VECIAHORRA_A11_CERTIFICATION=1`, `WP_ENVIRONMENT_TYPE=local` y
`VECIAHORRA_PAYMENT_GATEWAY=mock`, restauradas por el coordinator. Exclusivamente
para `php -S`, añade `VECIAHORRA_A11_MANIFEST=<ruta absoluta>` y
`VECIAHORRA_A11_MANIFEST_SHA256=<64 lowercase hex>`; el router las valida antes
de responder y nunca las hereda a otro proceso.

Shape cerrado del manifiesto:

```text
schema="veciahorra-a11/v1"; run_id:string; case_id:string; operation:string;
role:string; crash_point:string|null; created_at:string UTC; deadline_ms:int;
wp_load_path:string; temp_dir:string; release_path:string|null;
payload:object; fixture_ids:object; expected:object
```

`run_id` cumple `/^a11_[0-9]{14}_[1-9][0-9]*_[a-f0-9]{16}$/D`; `case_id`
pertenece a la matriz de §12; `operation` pertenece a §5. El child recalcula el
hash antes de bootstrap, rechaza claves ausentes o extra y verifica que
`realpath(temp_dir)` esté bajo `sys_get_temp_dir()/veciahorra-a11`.

Stdout es JSONL. Cada línea tiene exactamente
`{"schema":"veciahorra-a11/v1","case_id":string,"operation":string,"pid":int,"event":string,"at":string,"data":object}`.
La última línea exit 0 usa `event="result"`. Stderr debe estar vacío en PASS.
Exit 0=PASS, 10=fallo funcional, 20=infraestructura/contrato, 30=cleanup. Un
crash intencional no retorna JSON result y termina por señal/kill después de
`entered`.

Ejemplo válido:

```text
php durable-retry-a11-child-worker.php C:/Temp/veciahorra-a11/a11_.../manifest.json 8f...
{"schema":"veciahorra-a11/v1","case_id":"A11-CON-01","operation":"publish","pid":1234,"event":"result","at":"2030-01-01T00:00:00.000000Z","data":{"code":"converged","schedule_id":71,"generation":1}}
```

Fallo funcional termina 10 con `event="failure"` y
`data={"class":"functional","code":string}`. Crash válido escribe
`event="entered"`, no escribe result y es terminado por el coordinator.

## 5. Child helper y catálogo cerrado

`tests/manual/support/durable-retry-a11-child-worker.php` declara:

```php
final class DurableRetryA11ChildWorker
{
    public function __construct(
        private readonly string $manifestPath,
        private readonly string $manifestHash
    ) {}
    public static function main(array $argv): never;
    public function execute(): array;
}
```

El punto de entrada obligatorio al final del archivo es
`DurableRetryA11ChildWorker::main($argv);`. `main()` exige exactamente tres argv,
valida el manifiesto, define el gateway mock antes de cargar WordPress, requiere
`wp-load.php` una vez, valida `$GLOBALS['wpdb'] instanceof wpdb`, AS compatible
con 3.9.3 y ejecuta `execute()`.

Catálogo cerrado y resultado `data`:

| Operación | Payload obligatorio | Método interno exacto | Resultado |
|---|---|---|---|
| `publish` | `reconciliation_id:int` | `executePublish(array): array` | `code,schedule_id,generation,action_id` |
| `callback` | `hook:string,schedule_id:int,generation:int` | `executeCallback(array): array` | `code,schedule_id,generation,status` |
| `legacy` | `authority_id:int` | `executeLegacy(array): array` | `authority,worker_return,retry_count` |
| `as_action` | `hook:string,args:object,group:string,action_id:int` | `executeAction(array): array` | `action_id,before_status,after_status,executions` |
| `recovery` | `schedule_id:int,generation:int` | `executeRecovery(array): array` | `code,status,action_id` |
| `http` | `url:string,headers:object,body:object` | `executeHttp(array): array` | `status,headers,body,trace` |

No hay operación genérica. Enteros son positivos y canónicos; hooks/grupos se
validan con `DurableRetryExternalScheduleCatalog`; URL debe ser HTTP loopback;
headers admiten solo `Content-Type` y `X-VeciAhorra-A11-Run-Id`. Las excepciones
de contrato terminan 20; excepciones productivas no esperadas terminan 10 y se
registran sin secreto. El child no crea procesos adicionales salvo la petición
HTTP; no deja archivos salvo result/entered/stderr declarados.

## 6. Resultado tipado e invariantes

El coordinator retorna exclusivamente `DurableRetryA11ProcessResult` de §2.
`durationMilliseconds >= 0`; timestamps son UTC con microsegundos; PID y child
PIDs son positivos y distintos. `timedOut=true` implica `exitCode=null|no-cero`,
`crashed=false`, `result=null`. `crashed=true` implica crash point presente,
entered validado, `timedOut=false`, `result=null`. PASS implica exit 0, no
timeout/crash, stderr vacío, result no null y cleanup `clean`. `stdout` siempre
se conserva completo; `result` es solo la última línea JSON `event=result`.

## 7. Cinco decorators test-only

Todos viven en `durable-retry-a11-child-worker.php`, namespace de §2, son `final`,
aceptan `string $crashPoint, string $enteredPath, Closure $awaitTermination` al
final del constructor, validan el catálogo de §8 y activan una sola vez.
`awaitTermination` escribe entered con `flock()`, hace flush y bloquea; nunca
mata el proceso. El coordinator provoca el kill. Fuera de certificación su
constructor lanza `LogicException`; nunca participan en `Application`.

| FQCN | Interfaz exacta | Dependencia envuelta | Constructor inicial | Punto |
|---|---|---|---|---|
| `DurableRetryA11ExternalSchedulerCrashDecorator` | `DurableRetryExternalSchedulerInterface` | misma interfaz | `(DurableRetryExternalSchedulerInterface $inner, string $crashPoint, string $enteredPath, Closure $awaitTermination)` | después de `inner->schedule()` con action ID positivo, antes de retornar |
| `DurableRetryA11StageProcessorCrashDecorator` | `DurableRetryStageProcessorInterface` | misma interfaz | `(DurableRetryStageProcessorInterface $inner, string $crashPoint, string $enteredPath, Closure $awaitTermination)` | al entrar en `process()`; el executor ya persistió claim, antes de `$inner->process()` |
| `DurableRetryA11ReconciliationAttemptCrashDecorator` | `PaymentReconciliationAttemptProcessorInterface` | misma interfaz | `(PaymentReconciliationAttemptProcessorInterface $inner, string $crashPoint, string $enteredPath, Closure $awaitTermination)` | después de `$inner->process()` y antes de retornar al stage processor |
| `DurableRetryA11ScheduleRepositoryCrashDecorator` | `DurableRetryScheduleRepositoryInterface` | misma interfaz | `(DurableRetryScheduleRepositoryInterface $inner, string $crashPoint, string $enteredPath, Closure $awaitTermination)` | en `transition()`, después de resultado APPLIED terminal y antes de retornarlo |
| `DurableRetryA11ExecutorCrashDecorator` | `DurableRetryExecutorInterface` | misma interfaz | `(DurableRetryExecutorInterface $inner, string $crashPoint, string $enteredPath, Closure $awaitTermination)` | después de `$inner->execute()` y antes de retornar al callback |

Cada decorator implementa todos los métodos de su interfaz y delega byte por
byte cuando la ventana no coincide. Scheduler solo activa en `schedule()`;
repository solo en transición a `consumed|failed|orphaned`; los restantes solo en
`process()`/`execute()`. Ninguno captura excepciones del inner ni altera resultados.

Las APIs completas, sin métodos adicionales, son:

```php
// DurableRetryA11ExternalSchedulerCrashDecorator
public function schedule(string $hook, array $arguments, string $group, string $scheduledFor): DurableRetryExternalScheduleResult;
public function findPending(string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult;
public function cancel(int $scheduledActionId, string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult;

// DurableRetryA11StageProcessorCrashDecorator
public function stage(): string;
public function process(DurableRetryExecutionContext $context): DurableRetryProcessingResult;

// DurableRetryA11ReconciliationAttemptCrashDecorator
public function process(ReconciliationLease $lease): PaymentReconciliationProcessingResult;

// DurableRetryA11ScheduleRepositoryCrashDecorator
public function create(array $initialFields): DurableRetryPersistenceResult;
public function findById(int $id): DurableRetryPersistenceResult;
public function findByIdentity(string $stage, int $subjectId, int $generation): DurableRetryPersistenceResult;
public function associateScheduledAction(int $id, int $expectedVersion, int $scheduledActionId, string $dispatchedAt, string $updatedAt): DurableRetryPersistenceResult;
public function transition(DurableRetryScheduleSnapshot $expected, DurableRetryScheduleSnapshot $target): DurableRetryPersistenceResult;
public function supersedeAndCreateNextGeneration(DurableRetryScheduleSnapshot $claimed, DurableRetryNextAttemptDecision $decision, string $supersededAtUtc): DurableRetryNextGenerationPersistenceResult;

// DurableRetryA11ExecutorCrashDecorator
public function execute(string $hook, int $scheduleId, int $generation): DurableRetryExecutionResult;
```

## 8. Cinco ventanas de crash cerradas

| ID | Decorator | Evidencia previa | Pendiente/punto de kill | Recuperación y convergencia | Prohibido |
|---|---|---|---|---|---|
| `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | ExternalScheduler | action pending exacta; fila dispatching v1 con action ID null | retorno de schedule y asociación local | `recovery` encuentra por identidad, cancela/coordina y deja una action asociada máxima | segunda action ejecutable |
| `CRASH_AFTER_LOCAL_CLAIM` | StageProcessor | schedule claimed, version incrementada, claimed_at; 0 intento | antes del intento | vencimiento/recuperación y callback independiente dejan terminal con ≤1 intento | efecto funcional antes del kill |
| `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | ReconciliationAttempt | evidencia funcional única; schedule aún claimed | antes de clasificación/persistencia del stage | replay relee evidencia y persiste un terminal | repetir commit/materialización |
| `CRASH_AFTER_RESULT_PERSISTED` | ScheduleRepository | transición APPLIED terminal leída independientemente | retorno al executor | redelivery devuelve already-terminal y procesa 0 | nueva transición/attempt |
| `CRASH_BEFORE_CALLBACK_RETURN` | Executor | executor retornó terminal; callback aún no retorna | retorno al callback | nueva invocación retorna idempotente, procesa 0 | segunda ejecución funcional |

Para cada ventana H3: crea fixture propio; inicia child; espera entered; consulta
por repositorios productivos fila, versión, status, IDs, hash y timestamps;
consulta action por API pública; mata el PID; verifica no cleanup del child;
lanza un child `recovery` nuevo sin objetos en memoria; ejecuta segunda recovery;
verifica terminal idéntico, cardinalidades y cero huérfanos; limpia por IDs.

## 9. Integración tipada con Application

Único modelo permitido: accessor público tipado. `Application.php` añade:

```php
public function durableRetryWebpayMaterializer(): WebpayReconciliationMaterializer
{
    return $this->container->make(WebpayReconciliationMaterializer::class);
}
```

El binding singleton existente sigue siendo la única propiedad que conserva la
instancia; no se añade otra propiedad ni binding. El constructor registra el
grafo antes de que el accessor pueda llamarse. El método puede llamarse antes o
después de `run()` y retorna por identidad (`===`) la misma instancia. Propaga
errores de composición. Quedan prohibidos reflexión, acceso al container,
factory paralela, segundo `DurableRetryProductionComposition`, segundo `router()`
y reconstrucción A5–A10. Solo `Application.php` puede cambiar para este acceso.

## 10. Router HTTP real y WebpayReturnService

`tests/manual/support/durable-retry-a11-http-webpay-stub.php` declara:

```php
final class DurableRetryA11WebpayGatewayStub implements WebpayReturnGatewayInterface
{
    public function __construct(private readonly array $manifest) {}
    public function commit(string $token): WebpayCommitResult;
}

final class DurableRetryA11HttpRouter
{
    public function __construct(private readonly string $manifestPath) {}
    public static function main(): never;
    public function handle(): never;
}
```

El punto de entrada es `DurableRetryA11HttpRouter::main()`. `main()` obtiene ruta
y hash únicamente de `VECIAHORRA_A11_MANIFEST` y
`VECIAHORRA_A11_MANIFEST_SHA256`, verifica con `hash_equals()` y construye
`new DurableRetryA11HttpRouter($manifestPath)`. Es exclusivamente un
router de servidor PHP real iniciado con `php -S 127.0.0.1:<port> -t <WP_ROOT>
<router>`. Acepta solo peer loopback, header run_id exacto, GET health y POST
`/wp-json/veciahorra/v1/payments/webpay/return` JSON. Responde JSON y status
200/400/403/404/409/500; nunca redirige.

En POST requiere `wp-load.php`, crea una `Application`, obtiene exactamente
`durableRetryWebpayMaterializer()`, construye una sola vez por request:

```php
new WebpayReturnService(
    new DurableRetryA11WebpayGatewayStub($manifest),
    new PaymentSessionRepository(),
    new WebpayReturnRepository(),
    $application->durableRetryWebpayMaterializer(),
    new TransientWebpayReturnContextRepository(),
    new WooCommerceWebpayReturnGatewayResolver($stub),
    new PaymentOriginContextRepository()
)
```

Construye `WebpayReturnRequest::fromArray($json)` y llama `process()` una vez.
El stub acepta escenarios cerrados `approved`, `rejected`,
`error_before_commit`, `delayed_approved`; valida hash del token contra el
manifiesto, registra bajo flock hash truncado y count, y retorna el
`WebpayCommitResult` exacto del fixture. Nunca instancia `WebpayPaymentGateway`,
resuelve DNS ni abre socket no loopback. La traza obligatoria prueba orden
`request,service,A5,A6,A7,A8,A9,A10,response` y cardinalidad declarada.

## 11. Harnesses H1–H5

Todos requieren coordinator, ejecutan `guardEnvironment()` (8 assertions), usan
fixture fresco por caso y `try/finally`. Un primer fallo termina el harness con
exit 10 después de cleanup. Timeout por proceso 30 s salvo HTTP 15 s; timeout de
harness: H1 60, H2 90, H3 120, H4 120, H5 90 segundos.

| Harness | Ruta | Casos | Mínimo assertions | Operaciones |
|---|---|---:|---:|---|
| H1 | `tests/manual/durable-retry-a11-operational-acceptance-test.php` | OP-01..05 (5) | 18 | http,publish,as_action,callback,recovery |
| H2 | `tests/manual/durable-retry-a11-multiprocess-concurrency-test.php` | CON-01..05 (5) | 18 | publish,callback,recovery concurrentes |
| H3 | `tests/manual/durable-retry-a11-crash-recovery-test.php` | CR-01..05 (5) | 18 | publish,callback,recovery con cinco decorators |
| H4 | `tests/manual/durable-retry-a11-webpay-replay-test.php` | WR-01..06 (6) | 19 | http secuencial/concurrente,recovery |
| H5 | `tests/manual/durable-retry-a11-legacy-exclusion-test.php` | EX-01..10 (10) | 23 | legacy,callback,publish |

Cada mínimo incluye ocho guardias, una primaria por caso y cinco cleanup
globales. La suma mínima A11 es 96, superior a 64. PASS exige todos los result
exactos, stderr vacío, cleanup clean,
cero fixtures/actions/procesos/temp. No hay SKIP en certificación.

## 12. Matriz cerrada de 31 casos

Abreviaturas de assertions: `R`=fila/status/version exactos; `A`=action pública
exacta; `C`=cardinalidades; `N`=efectos prohibidos ausentes; `P`=PID/exit/orden;
`L`=cleanup total. Cada fila exige todas las letras listadas.

| ID | H | Precondición | Operación/procesos/sync | Resultado y efectos esperados | Prohibido | Assertions | Cleanup |
|---|---|---|---|---|---|---|---|
| A11-OP-01 | H1 | approved nuevo/on | http→publish→as_action→callback; 4 procesos | recon1, gen1/action/claim/process1, consumed, HTTP200 | duplicados/legacy | R,A,C,N,P | IDs inversos |
| A11-OP-02 | H1 | approved/off | http 1 proceso | recon1, legacy action1, durable0, HTTP200 | durable schedule | R,A,C,N | action+fixture |
| A11-OP-03 | H1 | scheduler unavailable | publish con double | external unavailable, legacy0 | fallback/action | R,C,N | fixture |
| A11-OP-04 | H1 | action terminal | as_action dos invocaciones | proceso total1, segunda no-op | doble proceso | R,A,C,N | action+fila |
| A11-OP-05 | H1 | attempt retryable | callback→recovery | gen1 superseded, gen2/action1 | dos successors | R,A,C,N | ambas filas |
| A11-CON-01 | H2 | recon fresca | 2 publish; ready/release | gen1/action máximo1, ambos convergen | dos authorities | R,A,C,P,N | identidad |
| A11-CON-02 | H2 | schedule exacta | 2 callback; ready/release | claim/process/consumed máximo1 | doble efecto | R,C,P,N | fila/action |
| A11-CON-03 | H2 | sin gen1 | 2 publish/create; ready/release | una fila active compatible | collision incompatible | R,C,P,N | fila |
| A11-CON-04 | H2 | gen vieja+actual | 2 callback; ready/release | vieja stale, actual procesa1 | vieja procesa | R,C,P,N | filas/actions |
| A11-CON-05 | H2 | scheduled | recovery cancel vs callback; release | un cierre, proceso≤1 | dos terminales | R,A,C,P,N | fila/action |
| A11-CR-01 | H3 | dispatching | publish; external entered/kill | pending exacta, local null; recovery asocia≤1 | segunda action | R,A,C,P,N | recovery2+IDs |
| A11-CR-02 | H3 | scheduled | callback; stage entered/kill | claimed, intento0; recovery terminal≤1 | intento pre-kill | R,C,P,N | recovery2 |
| A11-CR-03 | H3 | scheduled | callback; attempt entered/kill | evidencia1, claimed; replay terminal | segunda evidencia | R,C,P,N | recovery2 |
| A11-CR-04 | H3 | scheduled | callback; repo entered/kill | terminal APPLIED; replay proceso0 | transición2 | R,C,P,N | recovery2 |
| A11-CR-05 | H3 | scheduled | callback; executor entered/kill | terminal, respuesta ausente; replay0 | efecto2 | R,C,P,N | recovery2 |
| A11-WR-01 | H4 | token nuevo | POST1 | HTTP200 approved, recon/publish/gen1=1 | Webpay real | R,C,N,P | HTTP+fixture |
| A11-WR-02 | H4 | WR-01 persistido | POST replay | HTTP200 already_processed, commit total1 | commit2 | R,C,N | fixture |
| A11-WR-03 | H4 | excepción post-A8 | POST500 luego POST200 | evidence/recon1, publish converge | materialización2 | R,C,N,P | fixture |
| A11-WR-04 | H4 | token nuevo | 2 POST; ready/release | commit/recon≤1; approved+already/processing | dos commits | R,C,P,N | fixture |
| A11-WR-05 | H4 | return existente | POST+recovery | mismo recon, resume publish≤1 | recon nuevo | R,C,N | fixture |
| A11-WR-06 | H4 | Woo order A11 | POST dos veces | pago/pedido transición única, HTTP idempotente | doble pago | R,C,N | order+fixture |
| A11-EX-01 | H5 | A3 legacy | legacy child | classify1 primero, proceso/retry≤1 | classify2 | C,P,N | fixture |
| A11-EX-02 | H5 | A3 durable | legacy child | return void, claim/process/retry0 | SQL funcional | C,P,N | fixture |
| A11-EX-03 | H5 | durable scheduled | legacy child | misma gen/action sin mutación | cancel/retry | R,A,C,N | fixture |
| A11-EX-04 | H5 | indeterminate | legacy child | efectos0 | fallback | C,P,N | fixture |
| A11-EX-05 | H5 | A3 double throws | legacy child | misma excepción, efectos0 | catch/continue | C,P,N | fixture |
| A11-EX-06 | H5 | durable consumed | legacy child | durable byte-equivalent | mutación | R,C,N | fixture |
| A11-EX-07 | H5 | legacy action luego durable | as_action legacy | callback no-op, retry0, action consumed | cancel durable | R,A,C,N | fixture |
| A11-EX-08 | H5 | legacy vs durable callbacks | 2 child; release | legacy0, durable proceso≤1 | doble proceso | R,C,P,N | fixture |
| A11-EX-09 | H5 | replay durable | publish+legacy | publish converge, legacy0 | downgrade | R,C,N | fixture |
| A11-EX-10 | H5 | sin gen1 | legacy child | A3 legacy y conducta histórica≤1 | durable inferido | C,P,N | fixture |

Todos usan run_id en IDs/metadatos, fixture propio, acción exacta por API y
cleanup `L`; por tanto cada fila añade obligatoriamente L aunque no se repita en
la columna assertions.

## 13. Concurrencia multiproceso exacta

CON-01..05 y WR-04 usan exactamente dos procesos PHP, conexiones/objetos
independientes y la misma identidad durable. Cada child escribe ready; el
coordinator exige dos PIDs vivos y dos ready antes de crear `release.<case>` por
rename atómico bajo `flock()`. No se supone orden de ganador. Resultados por
proceso son `applied|converged|stale|already_terminal` según el caso; ningún otro
código es admisible. Persistencia final es única según §12.

Timeout child 30 s, barrera 5 s, caso 45 s. Deadlock DB es fallo funcional; no se
reintenta localmente. Solo las recuperaciones explícitas de la matriz están
permitidas. Cero locks, ready/release, children o PIDs al final.

## 14. Exclusión legacy operacional y trazas

H5 instala un double A3 solo en composición manual del child. La traza cerrada
usa eventos `identity,classify,claim,process,retry,return,throw`. Para todos los
casos `identity` y `classify` son los dos primeros; exactamente un classify.

| Autoridad | SQL permitido tras classify | Dependencias permitidas | Prohibidas | Resultado |
|---|---|---|---|---|
| legacy | repositorios históricos del worker | claim/process/scheduler, máximo normado | Webpay/A5–A10 nuevos | void o excepción histórica |
| durable | solo lectura A3 ya realizada | ninguna | claim/process/retry/cancel/SQL funcional/Webpay | void |
| indeterminate | solo lectura A3 ya realizada | ninguna | las mismas y fallback | void |
| A3 exception | ninguno posterior | ninguna | catch, retry, fallback | misma excepción |
| identidad inválida | ninguno | construcción identity | classify y todo efecto | excepción A1 |

EX-03/06 comparan snapshot antes/después completo. EX-07 prueba consumo normal de
la action legacy por AS sin reprogramación. EX-08 usa dos procesos y demuestra
que solo callback durable puede procesar. Ausencia se acredita con spies
test-only y contador SQL del fixture, nunca con búsqueda textual solamente.

## 15. Datos, fixtures y limpieza cerrados

Tablas permitidas: tablas productivas de order/session/origin/webpay return,
financial result, reconciliation/claims/completions y
`va_durable_retry_schedules`; AS solo por API. No truncate, rangos, LIKE amplio
ni datos ajenos. IDs autoincrementales se capturan; public IDs/token hashes usan
run_id. Tablas deben ser InnoDB.

Una transacción solo prepara un fixture dentro del mismo proceso y se confirma
antes de lanzar children. No abarca AS ni procesos. En crash sobreviven las
filas/action exigidas en §8. Cleanup: detener procesos/server; cancelar action ID
exacta; borrar schedules/completions/reconciliation/return/origin/session/order
por IDs capturados; restaurar solo opciones explícitamente capturadas; comprobar
0 fixture/action/temp/lock/PID. Un fallo de cleanup termina 30 y conserva logs
fuera del repo.

## 16. Orden y comandos de implementación/certificación

Orden obligatorio: helpers base; coordinator/child; cinco decorators; H5; H1;
completar matriz; H2; H3; H4; conjunto A11; regresión Durable Retry.

Patrón Windows obligatorio por harness:

```powershell
$p = Start-Process php -ArgumentList '<harness>' -PassThru -NoNewWindow `
  -RedirectStandardOutput '<temp-out>' -RedirectStandardError '<temp-err>'
if (-not $p.WaitForExit(<timeout-ms>)) { Stop-Process -Id $p.Id -Force; throw 'timeout' }
```

Comandos child son únicamente los generados por coordinator conforme §3. Orden
individual: histórico; H5; H1; H2; H3; H4. Conjunto ejecuta esos cinco en ese
orden con máximo 480 s. Luego los cuatro A10 direct-wiring, todos los harnesses
`tests/manual/durable-retry-*.php` del catálogo base y pagos relacionados. Cada
salida registra harnesses, casos, assertions, duración y cero diagnostics.

## 17. Veredicto y condiciones cerradas

A11 solo se certifica con 12/12 rutas, ocho nuevos exactos, firmas de este
documento, H1–H5 verdes, 31/31 casos asignados una vez, 5/5 kills, ≥96 assertions
A11, suite base 76/76 y 5.999 intacta, total ≥6.095, cero fallos/skips/warnings/
notices/deprecations, action por ID exacto, HTTP real loopback y cleanup cero.

No se permite interpretación alternativa de helper, DTO, protocolo, accessor,
router, decorator, caso o cleanup. Cualquier imposibilidad contra las interfaces
versionadas exige una nueva corrección documental, no adaptación local.

**A11 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA COMPLEMENTARIA.**

## 18. Validación documental obligatoria

La allowlist contiene 12 rutas y exactamente ocho nuevos; no contiene rutas
desplazadas. Hay un solo coordinator, child y router. Todas las clases de §§2,
5, 7 y 10 tienen constructor/API; los cinco decorators implementan interfaces
concretas y cada ventana de §8 tiene uno. La tabla §12 contiene 31 IDs únicos:
5 H1 + 5 H2 + 5 H3 + 6 H4 + 10 H5. H1–H5 comparten protocolo y no requieren
decisiones arquitectónicas adicionales.

Al adoptar el documento se registran su ruta, número de líneas, headings `##` y
SHA-256 calculados sobre sus bytes finales. El staging debe contener, si se
autoriza un commit documental posterior, solo este documento; los cuatro cambios
locales previos permanecen unstaged y con SHA-256 idéntico. Esta tarea no autoriza
commit ni push.
