# Auditoría de implementabilidad A7 — coordinación externa inicial durable

## 1. Veredicto ejecutivo

**A7 IMPLEMENTABLE**

A7 puede implementarse exactamente sobre la base certificada como el único
adaptador del flujo productivo nuevo autorizado para invocar una vez
`DurableRetryExternalScheduleCoordinatorInterface::coordinate()` con el
`schedule_id` y la `generation` entregados por una resolución A6 continuable.
No decide autoridad, no consulta A2–A6, no llama al adaptador, no ejecuta SQL y
no habilita legacy.

No se detectaron bloqueos documentales. A10 fija nombre, FQCN, seis rutas,
firma, dependencia, catálogo de cinco estados, hook de entrada productiva,
hooks durable, group y payload. Los contratos reales permiten implementar la
delegación sin ampliar autoridad. Esta auditoría cierra factories, mapeo de los
14 códigos del coordinador, presupuestos, 12 casos funcionales y seis escenarios
de integración. El siguiente microhito recomendado es implementar A7 bajo la
allowlist de §30 y recertificarlo antes de iniciar A8.

## 2. Estado base certificado

| Control | Resultado certificado |
|---|---|
| Rama | `main` |
| HEAD | `6976493aca5494690ef55347a24e4444d73c446b` |
| Divergencia `origin/main...HEAD` | `0` atrás / `48` adelante |
| Staging | vacío |
| Cambios tracked | `0` |
| Suite Durable Retry | `62/62`; `4.780` assertions |
| Fallos / warnings / notices / deprecations | `0 / 0 / 0 / 0` |
| Integraciones de executors | reconciliation `31`, business `25`, delivery `24`, fulfillment `10`; cuatro verdes |
| `artifacts/` | exactamente `504` archivos |
| Temporales / índices temporales | `0 / 0` |
| A7–A9 | no implementados |
| Wiring productivo | no realizado |
| Push | no realizado |

La ejecución se dividió en 58 harnesses no-MySQL, `4.693` assertions, y cuatro
harnesses MySQL, `87` assertions. Todos terminaron con código cero.

Integridad protegida:

| Documento | SHA-256 |
|---|---|
| `docs/durable-retry-production-wiring-a10-normative-correction.md` | `a0b28304715a4a0a4389de5743a425cbe5b0bb07939b5cef77ab81bc94db79bf` |
| `docs/durable-retry-production-wiring-a6-readiness-audit.md` | `8c0406638f09d2031698281cb5b316383e43c5e1f9d11cc54c5c8b87d6505c90` |

El commit `6976493` contiene exactamente estos cinco archivos A6, todos añadidos:

- `app/Modules/Orders/Contracts/DurableRetryInitialScheduleResolverInterface.php`
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialScheduleResolutionResult.php`
- `app/Modules/Orders/Services/DurableRetryInitialScheduleResolver.php`
- `tests/manual/durable-retry-initial-schedule-resolver-test.php`
- `tests/manual/durable-retry-initial-schedule-resolver-infrastructure-test.php`

Sus FQCN, constructor, `resolve()`, cinco estados, razones, nulabilidad y guardia
`mayContinueToA7()` coinciden con A10. No existe archivo productivo A7, A8 o A9.

## 3. Autoridad normativa

La precedencia aplicable es: (1) contratos PHP versionados A1–A6; (2) A10; (3)
auditoría certificada A6; (4) contratos existentes del coordinador, repositorio
y scheduler en cuanto implementan el runtime que A10 conserva; (5) auditoría
previa de wiring; (6) diseño histórico de wiring y composición.

A10 reemplaza las reglas históricas «A5 crea schedule», «A5 llama Action
Scheduler», «A6 materializa» y «el hook inicial queda abierto». La regla vigente
es A4 materializa gen1, A5 decide, A6 lee, A7 coordina y A8 enruta. El contrato
actual del coordinador conserva autoridad sobre lectura por ID, identidad
externa, scheduling, verificación, asociación CAS y compensación. A7 no replica
esas operaciones.

## 4. Responsabilidad exacta de A7

A7 recibe exclusivamente un `DurableRetryInitialScheduleResolutionResult`
continuable, extrae id y generación, valida la invariantes públicas y delega
exactamente una vez. Es el único caller del coordinador externo dentro del flujo
nuevo de scheduling inicial.

A7 no decide legacy/durable; no llama A2, A3, A4 o A5; no vuelve a ejecutar A6;
no inventa id o generación; no crea generaciones ni filas; no registra hooks;
no ejecuta callback, executor o processor; no usa scheduler legacy; no contiene
fallback; no agenda directamente; no asocia directamente; no escribe logs.

El coordinador existente conserva: dos lecturas estabilizadoras para
`dispatching`, una lectura para `scheduled`, derivación hook/group/arguments,
uso de `scheduled_for`, llamada al adaptador, verificación pending, asociación
CAS, relectura de convergencia y compensación mediante cancelación. A7 conserva
solo validación de frontera, delegación y traducción cerrada del resultado.

## 5. Entrada A6 → A7

Firma real A6:

```php
public function resolve(
    DurableRetryInitialTransferRequest $request,
    DurableRetryInitialAuthorityProductionResult $authority
): DurableRetryInitialScheduleResolutionResult;
```

| Estado A6 | Invoca A7 | Datos | Validación A7 | Retorno / efecto máximo |
|---|---:|---|---|---|
| `resolved_dispatching` | sí | id, gen, fecha UTC | continuidad, id/gen positivos, gen `1`, fecha válida | una coordinación |
| `resolved_scheduled` | sí | id, gen, fecha UTC | iguales | una coordinación verificadora |
| `not_found` | no | tres `null` | rechazo | `InvalidArgumentException`; cero efectos |
| `incompatible` | no | tres `null` | rechazo | `InvalidArgumentException`; cero efectos |
| `read_error` | no | tres `null` | rechazo | `InvalidArgumentException`; cero efectos |

Las factories reales son `resolvedDispatching(int, int, DateTimeImmutable)`,
`resolvedScheduled(int, int, DateTimeImmutable)`, `notFound()`,
`incompatible()` y `readError()`. Los accessors son `state()`, `reason()`,
`scheduleId()`, `generation()`, `scheduledForUtc()`, `mayContinueToA7()` y
`permitsLegacy()`; este último siempre retorna `false`.

## 6. Snapshot y datos autoritativos

A7 no recibe el snapshot: A6 lo validó y el coordinador lo relee por id.

| Campo | Origen / tipo / nullable | Validación y uso | Coordinator | Resultado A7 |
|---|---|---|---:|---:|
| `schedule_id` | snapshot `id`; `int`; no | positivo, coincide en relecturas | sí, argumento | sí |
| `generation` | snapshot; `int`; no | positiva e inicial `1` | sí, argumento/payload | sí |
| stage | snapshot; `string`; no | `reconciliation` en A6; catálogo en coordinator | deriva hook | no |
| subject ID | snapshot; `int`; no | positivo y coincide request A6 | no payload | no |
| completion ID | snapshot; `?int`; sí general, no en reconciliation | igual a subject | no payload | no |
| attempt number | snapshot; `int`; no | A6 exige `0` | no payload | no |
| `scheduled_for` | snapshot; `string`; no | UTC canónico | scheduler | no |
| status | snapshot; `string`; no | A6: dispatching/scheduled; coordinator relee | rama | no |
| `scheduled_action_id` | snapshot; `?int` | null en dispatching; positivo en scheduled | verifica/asocia | sí, nullable |
| version | snapshot; `int`; no | positiva; expectedVersion CAS | asociación | no |
| reason code | snapshot; `string`; no | catálogo por status | preservado, no mutado por A7 | no |
| active slot | snapshot; `?int` | `1` para ambos estados activos | validado por snapshot | no |

`schedule_id` y `generation` nunca se aceptan desde el hook productivo inicial
ni desde un payload externo. El coordinador vuelve a derivarlos de la identidad
durable para construir el payload del callback.

## 7. Inventario real del coordinador externo

- Interfaz: `VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface`, ruta `app/Modules/Orders/Contracts/DurableRetryExternalScheduleCoordinatorInterface.php`.
- Concreto: `VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator`, ruta `app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php`.
- Constructor concreto: `__construct(DurableRetryScheduleRepositoryInterface $repository, DurableRetryExternalSchedulerInterface $scheduler, Closure $utcNow)`.
- Único método público propio: `coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult`.
- Dependencias internas: repositorio durable, scheduler externo y reloj UTC cerrado.

Operaciones permitidas: `findById`, `schedule`, `findPending`,
`associateScheduledAction`, relectura y `cancel` compensatorio. Prohibidas:
creación de fila/generación, decisión de autoridad, legacy, hook registration,
executor y processors.

El resultado actual tiene 14 códigos: `synchronized_new`,
`synchronized_existing`, `already_synchronized`, `not_found`,
`stale_generation`, `ineligible_state`, `durable_inconsistency`,
`external_inconsistency`, `concurrent_convergence`, `conflict_compensated`,
`compensation_unconfirmed`, `external_unavailable`, `external_error` y
`persistence_error`; además id, generación, action id nullable, compensated e
interventionRequired.

A7 depende solo de `DurableRetryExternalScheduleCoordinatorInterface`. Añadir
repositorio, scheduler, reloj o catálogo a su constructor duplicaría autoridad.

## 8. Adaptador Action Scheduler

Interfaz real `DurableRetryExternalSchedulerInterface`:

```php
public function schedule(string $hook, array $arguments, string $group,
    string $scheduledFor): DurableRetryExternalScheduleResult;
public function findPending(string $hook, array $arguments,
    string $group): DurableRetryExternalScheduleResult;
public function cancel(int $scheduledActionId, string $hook,
    array $arguments, string $group): DurableRetryExternalScheduleResult;
```

El concreto es `ActionSchedulerDurableRetryAdapter`. Los tres métodos
normalizan identidad con el catálogo. `schedule()` valida fecha UTC, exige
`as_schedule_single_action` y `as_get_scheduled_actions`, usa timestamp UTC,
hook, argumentos, group y `unique=true`. Un entero positivo es `scheduled`; un
`0` activa una búsqueda convergente; otro valor o excepción es
`external_error`. `findPending()` exige `as_get_scheduled_actions`, filtra hook,
args, group y status `pending`, pide dos ids y acepta exactamente uno.
`cancel()` exige además `as_unschedule_action`, verifica el id y confirma
ausencia después.

Ausencia de API retorna `unavailable`; request inválido, `invalid_request`;
ausencia pending, `not_found`; excepción, forma o multiplicidad inesperada,
`external_error`. La incertidumbre queda expresada como error externo o
compensación no confirmada por el coordinador. A7 tiene prohibido llamar estos
métodos directamente.

## 9. Firma definitiva A7

Rutas y firmas normativas:

```php
// app/Modules/Orders/Contracts/DurableRetryInitialScheduleCoordinatorInterface.php
namespace VeciAhorra\Modules\Orders\Contracts;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;
interface DurableRetryInitialScheduleCoordinatorInterface
{
    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult;
}

// app/Modules/Orders/Services/DurableRetryInitialScheduleCoordinator.php
namespace VeciAhorra\Modules\Orders\Services;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;
final class DurableRetryInitialScheduleCoordinator
    implements DurableRetryInitialScheduleCoordinatorInterface
{
    public function __construct(
        private readonly DurableRetryExternalScheduleCoordinatorInterface $coordinator
    );
    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult;
}
```

El resultado vive en
`app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialSchedulingResult.php`
y su FQCN es
`VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult`.
No hay `mixed`, array abierto ni callback configurable en A7.

## 10. Resultado tipado A7

Catálogo exacto fijado por A10:

| Constante / valor | Factory autoritativa | Códigos coordinator |
|---|---|---|
| `SYNCHRONIZED = 'synchronized'` | `synchronized(DurableRetryCoordinationResult $result)` | `synchronized_new`, `synchronized_existing`, `concurrent_convergence` |
| `ALREADY_SYNCHRONIZED = 'already_synchronized'` | `alreadySynchronized(DurableRetryCoordinationResult $result)` | `already_synchronized` |
| `EXTERNAL_UNAVAILABLE = 'external_unavailable'` | `externalUnavailable(DurableRetryCoordinationResult $result)` | `external_unavailable` |
| `COORDINATION_FAILED = 'coordination_failed'` | `coordinationFailed(DurableRetryCoordinationResult $result)` | todo código no exitoso con `interventionRequired=false` |
| `COORDINATION_UNCERTAIN = 'coordination_uncertain'` | `coordinationUncertain(DurableRetryCoordinationResult $result)` | todo código no exitoso con `interventionRequired=true` |

La regla por flag preserva la semántica concreta: `conflict_compensated` y un
`persistence_error` compensado son fallos conocidos; `not_found`, stale o
ineligible son fallos conocidos; inconsistencias, error externo, persistencia
no compensada y compensación no confirmada son inciertos cuando el coordinador
marca intervención. Ningún nombre extra representa «pending reutilizado» o
«scheduling nuevo»: ambos son `synchronized`, diferenciados por `reason()`.

Forma cerrada:

```php
public const SYNCHRONIZED = 'synchronized';
public const ALREADY_SYNCHRONIZED = 'already_synchronized';
public const EXTERNAL_UNAVAILABLE = 'external_unavailable';
public const COORDINATION_FAILED = 'coordination_failed';
public const COORDINATION_UNCERTAIN = 'coordination_uncertain';

private function __construct(
    private readonly string $state,
    private readonly string $reason,
    private readonly int $scheduleId,
    private readonly int $generation,
    private readonly ?int $scheduledActionId,
    private readonly bool $requiresIntervention
);
public static function synchronized(
    DurableRetryCoordinationResult $result
): self;
public static function alreadySynchronized(
    DurableRetryCoordinationResult $result
): self;
public static function externalUnavailable(
    DurableRetryCoordinationResult $result
): self;
public static function coordinationFailed(
    DurableRetryCoordinationResult $result
): self;
public static function coordinationUncertain(
    DurableRetryCoordinationResult $result
): self;
public function state(): string;
public function reason(): string;
public function scheduleId(): int;
public function generation(): int;
public function scheduledActionId(): ?int;
public function requiresIntervention(): bool;
public function permitsLegacy(): bool; // siempre false
public function mayContinueToA8(): bool; // siempre true: A8 traduce el cierre
```

Las factories verifican que id/generación coincidan con la entrada A6, que los
éxitos tengan action id positivo, que `EXTERNAL_UNAVAILABLE` no requiera
intervención y que `COORDINATION_UNCERTAIN` sí la requiera. `reason` es
exactamente `DurableRetryCoordinationResult::code()`. A7 no cambia estado
durable: el esperado es el snapshot final producido o verificado por el
coordinador. Retry local siempre es falso; recovery posterior depende del
estado/razón; permiso legacy siempre es falso.

Si el contrato del coordinator lanza, A7 construye una única evidencia local
`new DurableRetryCoordinationResult(EXTERNAL_ERROR, $scheduleId, $generation,
null, false, true)` y la entrega a `coordinationUncertain()`. Esto conserva el
catálogo de razones real sin añadir una factory escalar ni exponer la excepción.

## 11. Hook inicial exacto

`veciahorra_durable_retry_initial_reconciliation` es el hook síncrono que el
materializer publicará y el registrar A9 entregará a A8 con dos accepted args:
`(int $reconciliationId, string $scheduledForUtc)`. No es el hook que agenda A7.

A7, mediante el coordinador, agenda el callback durable existente:
`veciahorra_durable_retry_reconciliation`. Su payload es
`['schedule_id' => int, 'generation' => int]`; Action Scheduler entrega los dos
valores al registrar/callback durable con accepted args `2`, y el callback
existente recibe además el hook internamente al ejecutar
`execute($hook, $scheduleId, $generation)`.

El hook no forma parte de `arguments`. Están prohibidos stage, subject,
completion, attempt, fecha, status, action id, versión, reason, active slot,
worker id y datos del usuario.

## 12. Group exacto

El literal único es `veciahorra-durable-retry`, definido por
`DurableRetryExternalScheduleCatalog::GROUP`. Se prohíben group dinámico,
derivado del usuario, vacío, legacy o divergente por request. A10 §16 y el
catálogo real coinciden.

## 13. Argumentos del scheduling

La estructura exacta, asociativa y ordenada por normalización es:

```php
[
    'schedule_id' => $snapshot->id(),
    'generation' => $snapshot->generation(),
]
```

Cardinalidad `2`; claves y orden exactos; valores enteros positivos. El hook no
es argumento. Stage se deriva de persistencia; subject y completion no se
incluyen. `DurableRetryActionCallback` reconstruye esta forma, la valida con el
group literal y delega al executor, que relee la fila. No se confía en identidad
funcional externa.

## 14. `scheduled_for`

Procede exclusivamente de `snapshot['scheduled_for']`, no del accessor A6 al
invocar el scheduler. Su forma es `Y-m-d H:i:s`, timezone UTC, precisión de
segundos; el catálogo usa `createFromFormat('!Y-m-d H:i:s', ..., UTC)`, exige
round-trip exacto y timestamp positivo. No se normaliza, recalcula ni sustituye.
Una fecha vencida conserva su timestamp: Action Scheduler decide ejecución
inmediata. Una fecha inválida produce `invalid_request`, traducido por el
coordinador a `external_inconsistency`; no se agenda otra fecha.

## 15. Scheduler guard

| Garantía | Dueño |
|---|---|
| Solo A6 continuable, id/gen iniciales | A7 + tipo A6 |
| ID/generation corresponden a fila actual | coordinator, `findById` y `closeRead` |
| fila terminal/no continuable | coordinator retorna `ineligible_state` |
| segunda acción inicial | coordinator relee antes de scheduling; adapter usa `unique=true` |
| pending duplicado | adapter consulta máximo dos ids y cierra con error |
| hook/group/args exactos | coordinator + catálogo + adapter |
| asociación incompatible | repositorio CAS + relectura/compensación coordinator |
| asociación ya confirmada | coordinator verifica pending, no schedule |
| snapshot A6 obsoleto/superseded | segunda lectura y CAS |

A7 no duplica ninguna guardia interna. Solo comprueba continuidad y coherencia
del resultado devuelto contra id/generación de la resolución.

## 16. Estado `dispatching`

Secuencia real: (1) A7 valida; (2) coordinator lee por id; (3) relee para
estabilizar; (4) deriva identidad; (5) llama una vez `schedule()`, cuya rama de
retorno cero busca pending compatible; (6) asocia mediante CAS; (7) confirma o
relee convergencia; (8) compensa una acción recién creada si la asociación no
converge; (9) A7 traduce.

No existe transición separada: `associateScheduledAction()` realiza
`dispatching → scheduled`, asigna action id, `dispatched_at`, `updated_at` e
incrementa versión. Fallo externo confirmado deja `dispatching`; incertidumbre
de asociación activa relectura y, solo para acción nueva, cancelación máxima
uno. Nunca hay segundo schedule ni legacy.

## 17. Estado `scheduled`

El coordinator ejecuta una lectura, reutiliza el action id persistido y una
búsqueda `findPending()` con identidad derivada. Coincidencia exacta retorna
`already_synchronized`; API ausente retorna `external_unavailable`; error
externo retorna incierto; ausencia o id divergente retorna
`external_inconsistency`. No asocia, no transiciona, no cancela y no agenda.
La ausencia externa es inconsistencia para recovery posterior, nunca permiso
para crear una segunda acción durante A7.

## 18. Pending compatible

Es exactamente una acción `pending` encontrada por hook durable derivado del
stage, group literal y argumentos normalizados con el mismo id/generation. La
fecha no participa en `findPending`; sí fue validada al crear. En `dispatching`,
la respuesta `already_scheduled` se asocia sin cancelación. En `scheduled`, el
id debe coincidir además con el persistido y retorna `already_synchronized`.

## 19. Pending incompatible

Argumentos distintos, generación distinta, group divergente o hook divergente
no son compatibles y no se adoptan. Dos matches exactos producen
`external_error`; un id encontrado distinto del asociado produce
`external_inconsistency`. A7 no enumera ni cancela pendientes divergentes.
Cancelación permanece cero salvo compensación del único action id recién creado
por esta coordinación cuando falla la asociación. Una acción reutilizada nunca
se cancela.

## 20. Action Scheduler ausente

El adaptador detecta ausencia por `function_exists`; el coordinator la traduce
a `DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE`; A7 retorna
`external_unavailable` con id/generation, action id nullable según la rama,
intervention false y reason `external_unavailable`. SQL directo A7 `0`,
asociaciones `0`, transición `0`, schedule efectivo `0`, logs A7 `0`, fallback
legacy `0`. La fila se conserva para recovery futuro.

## 21. Scheduling exitoso

`scheduled` con id positivo significa acción nueva; `already_scheduled` con id
positivo significa convergencia externa. El repositorio asocia con versión
esperada, cambia a `scheduled`, incrementa versión exactamente uno y conserva
el reason code existente. Los resultados coordinator son `synchronized_new`,
`synchronized_existing` o `concurrent_convergence`; A7 retorna `synchronized`
con reason exacta y action id. Si la asociación falla, el coordinator relee;
convergencia compatible triunfa, acción nueva no convergida se cancela una vez,
y cancelación no confirmada retorna incertidumbre.

## 22. Scheduling fallido

Fallo confirmado es un resultado no exitoso con
`interventionRequired=false`: `not_found`, `stale_generation`,
`ineligible_state`, `conflict_compensated` o `persistence_error` compensado.
A7 retorna `coordination_failed`, conserva reason, id/generation y action id si
el coordinador lo aporta. No crea asociación adicional, no reintenta, no agenda
otra vez y no habilita legacy.

## 23. Scheduling incierto

La evidencia es un resultado no exitoso con `interventionRequired=true`:
inconsistencia durable/externa, error externo, persistencia no compensada o
compensación no confirmada. Coordinator permite una relectura tras fallo CAS y
una cancelación verificadora para acción nueva. La misma invocación tiene cero
segundos schedules y cero loops. A7 retorna `coordination_uncertain`, conserva
reason/action id, marca intervención, deja la fila en el estado observable y
reserva retry/recovery para A9. Legacy permanece prohibido.

## 24. Asociación de `scheduled_action_id`

Responsable único: coordinator mediante:

```php
DurableRetryScheduleRepositoryInterface::associateScheduledAction(
    int $id,
    int $expectedVersion,
    int $scheduledActionId,
    string $dispatchedAt,
    string $updatedAt
): DurableRetryPersistenceResult;
```

Precondiciones: ids positivos, fila `dispatching`, action null, versión exacta,
hora UTC válida. El repositorio relee, construye snapshot `scheduled`, hace
UPDATE CAS, confirma por relectura y reconoce `ALREADY_APPLIED` solo con mismo
id, status, versión `expected+1` y timestamps. Action distinto es `CONFLICT`;
estado distinto, `UNEXPECTED_STATE`; versión perdida, `AUTHORITY_LOST`; error
SQL, `PERSISTENCE_ERROR`. A7 jamás llama este método.

## 25. Excepciones

| Caso | Tratamiento cerrado | Segunda búsqueda / schedule / asociación / fallback |
|---|---|---|
| resolución no continuable o shape imposible | `InvalidArgumentException` antes del coordinator | `0 / 0 / 0 / 0` |
| excepción coordinator | A7 retorna `coordination_uncertain`, reason autoritativa `external_error`, mismos id/gen, intervención true | `0 / 0 / 0 / 0` desde A7 |
| excepción repository | coordinator la captura; `persistence_error` | relectura máxima contractual / `0` segundo / máximo `1` / `0` |
| excepción adapter | coordinator retorna `external_error` | `0` segunda desde A7 / `0` segundo / `0 / 0` |
| resultado coordinator inválido o id/gen divergente | A7 retorna `coordination_uncertain`, reason `external_inconsistency` | `0 / 0 / 0 / 0` adicionales |
| action id inválido | coordinator produce `external_inconsistency` | `0 / 0 / 0 / 0` adicionales |
| payload/fecha inválido | adapter `invalid_request`; coordinator `external_inconsistency` | `0 / 0 / 0 / 0` adicionales |
| snapshot incompatible | coordinator `durable_inconsistency` o `ineligible_state` | máximo lecturas de cierre / `0 / 0 / 0` |

A7 no emite logs; A8/A9 podrán observar el resultado sin reinterpretarlo. La
captura de excepción del coordinator en A7 es necesaria para cumplir el cierre
tipado del futuro callback; no autoriza una segunda invocación.

## 26. Presupuesto operacional cerrado

Contadores máximos por invocación, incluyendo operaciones internas reales:

| Operación | `resolved_dispatching` | `resolved_scheduled` | error/incompatible A7 |
|---|---:|---:|---:|
| validaciones A7 | 6 | 6 | 6 |
| llamadas coordinator | 1 | 1 | 0 |
| `findPending` adapter | 2 | 1 | 0 |
| schedules | 1 | 0 | 0 |
| cancelaciones | 1 | 0 | 0 |
| asociaciones | 1 | 0 | 0 |
| SELECT SQL indirectos | 5 | 1 | 0 |
| INSERT | 0 | 0 | 0 |
| UPDATE | 1 | 0 | 0 |
| locks explícitos | 0 | 0 | 0 |
| transacciones explícitas | 0 | 0 | 0 |
| hooks registrados/ejecutados por A7 | 0 | 0 | 0 |
| llamadas legacy | 0 | 0 | 0 |

Las seis validaciones A7 son: estado continuable, `mayContinueToA7`, id no
null/positivo, generation no null/igual a `1`, fecha no null/UTC, coherencia
id/generation del resultado coordinator. Los cinco SELECT máximos dispatching
son dos lecturas coordinator, lectura previa de asociación, confirmación CAS o
clasificación CAS y relectura de fallo CAS. Los dos `findPending` máximos
pertenecen al `cancel()` compensatorio (antes/después); la rama de schedule que
retorna cero usa uno y no cancela una acción reutilizada.

## 27. Concurrencia

Dos A7 simultáneos releen antes de schedule; `unique=true` converge en una
acción efectiva. Si un pending nace durante scheduling, el retorno cero activa
búsqueda y adopción. Dos asociaciones compiten por versión: mismo action id
converge a `concurrent_convergence`; id distinto no se adopta y la acción nueva
se compensa. Un snapshot A6 obsoleto se cierra por segunda lectura, generation
stale o estado ineligible. Si la fila se vuelve superseded, el CAS falla y la
relectura impide asociación. Ninguna guardia promete eliminar toda ventana del
proveedor: la combinación unique, CAS, relectura y compensación garantiza una
acción efectiva confirmada o un resultado incierto cerrado, nunca un segundo
schedule dentro de la misma llamada.

## 28. Seguridad y confianza

Confiables: id/generation/fecha del resultado A6 porque proceden de snapshot y
son revalidados; snapshot re-leído por coordinator; código e ids de un resultado
coordinator válido. Derivados: hook por stage, group constante, argumentos por
id/generation. No confiables: parámetros WordPress, valores devueltos por API
AS antes de validar tipo/cardinalidad y excepciones. Prohibidos en A7: stage,
subject, completion, attempt, worker, hook, group, action id o fechas aportados
por hooks/usuarios. La persistencia, no el payload, es autoridad funcional.

## 29. Compatibilidad con A8

A8 recibe `DurableRetryInitialSchedulingResult` y no necesita consultar Action
Scheduler. `state`, `reason`, id, generation, action id nullable,
`requiresIntervention`, `permitsLegacy=false` y `mayContinueToA8=true` bastan
para mapear a `durable_synchronized`, `durable_already_synchronized`,
`durable_external_unavailable`, `durable_coordination_failed` o
`durable_coordination_uncertain`. Los side effects ya terminaron dentro del
coordinator. A8 no reintenta ni interpreta pending.

## 30. Allowlist A7

Los seis archivos literales de A10 son todos nuevos:

| Ruta | Rol | Permitido | Prohibido |
|---|---|---|---|
| `app/Modules/Orders/Contracts/DurableRetryInitialScheduleCoordinatorInterface.php` | interfaz | firma §9 | dependencias extra |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialSchedulingResult.php` | resultado | catálogo §10 | estados abiertos |
| `app/Modules/Orders/Services/DurableRetryInitialScheduleCoordinator.php` | servicio | validar/delegar/mapear | SQL, adapter, legacy, A2–A6 |
| `tests/manual/durable-retry-initial-schedule-coordinator-test.php` | funcional | 12 casos | integración A8 |
| `tests/manual/durable-retry-initial-schedule-coordinator-infrastructure-test.php` | infraestructura | guardias §32 | inventario global rígido |
| `tests/manual/durable-retry-initial-schedule-coordinator-integration-test.php` | integración | seis escenarios | A8/wiring |

No se modifica coordinator, adapter, repositorio, schema, migraciones, A1–A6,
executor, callback, processors, bootstrap o documentos.

## 31. Harness funcional A7

Cada caso usa resolución real y double estricto del coordinator:

| ID | Entrada / persistido | Double y retorno | Resultado A7 | Llamadas, efectos y assertions |
|---|---|---|---|---|
| F01 | dispatching, id 101/gen1 | `synchronized_existing`, action 501 | synchronized | 1 llamada; adopción; 15 |
| F02 | dispatching, id 102/gen1 | `synchronized_new`, action 502 | synchronized | 1; asociación encapsulada; 15 |
| F03 | dispatching | `conflict_compensated`, action 503 | coordination_failed | 1; compensated; 15 |
| F04 | dispatching | `compensation_unconfirmed`, action 504 | coordination_uncertain | 1; intervención; 15 |
| F05 | dispatching | `external_unavailable`, null | external_unavailable | 1; cero legacy; 15 |
| F06 | scheduled, action 506 | `already_synchronized`, 506 | already_synchronized | 1; no schedule A7; 15 |
| F07 | scheduled | `synchronized_existing`, 507 | synchronized | 1; convergencia; 15 |
| F08 | scheduled | `external_inconsistency`, 508 | coordination_uncertain | 1; no re-agenda; 15 |
| F09 | dispatching id 109 | coordinator devuelve id 999/action 509 | coordination_uncertain/external_inconsistency | 1; rechazo divergencia; 15 |
| F10 | `incompatible` | double prohibido | `InvalidArgumentException` | 0; cero efectos; 15 |
| F11 | dispatching | double lanza | coordination_uncertain/external_error | 1; captura; 15 |
| F12 | dispatching | synchronized_new | synchronized | 1 máximo; journal prueba 0 legacy/loops; 15 |

Total funcional exacto: **180 assertions**. Las 15 por caso son estado, reason,
id, generation, action id, intervención, legacy, continuidad A8, número de
llamadas, argumentos id, argumentos generation, cero segunda llamada, cero
adapter directo, cero repositorio directo y side effect esperado.

## 32. Harness de infraestructura A7

Guardias: seis rutas exactas y `git diff --name-only`; namespaces/FQCN; interfaz
con un método; firma y retorno; clase final; constructor de una dependencia
exacta; resultado final y catálogo de cinco; factories/accessors; único caller
nuevo de `DurableRetryExternalScheduleCoordinatorInterface::coordinate`;
ausencia de scheduler/repository/SQL; literales de hook/group/payload solo en
componentes dueños; ausencia A2–A6 calls, legacy, `add_action`, executor,
processors, loops, sleep, retry y catch que redelegue; presupuesto estructural.

No usa conteos históricos globales: compara allowlist explícita y analiza solo
los seis archivos A7 más el caller baseline certificado. Total exacto:
**72 assertions**, distribuidas 6 rutas + 8 FQCN/namespace + 12 firmas + 8
constructor/dependencia + 10 resultado + 10 autoridad única + 12 prohibiciones
+ 6 allowlist Git.

## 33. Integraciones A7

Un único archivo contiene exactamente seis escenarios con limpieza `finally`:

| ID | Fixture / componentes | Base/AS | Assertions y estado final |
|---|---|---|---|
| I01 | A6 real resuelve gen1 dispatching → A7 → coordinator real | repo fake contractual; adapter fake | 20; scheduled/action 601 |
| I02 | pending compatible preexistente | repo fake; adapter fake devuelve already | 21; asociación 602, cero cancel |
| I03 | schedule nuevo | repo y coordinator reales; fake AS | 23; action 603 persistido, versión +1 |
| I04 | AS ausente | repo real contractual; adapter real sin funciones | 19; dispatching intacto, cero legacy |
| I05 | schedule nuevo + CAS concurrente compatible | repo fake concurrente; adapter fake | 22; concurrent_convergence, una acción |
| I06 | dos invocaciones intercaladas | repo fake CAS; fake AS unique | 25; ambas éxito/convergencia, un pending efectivo |

Cada fixture contiene snapshot canónico reconciliation, id/gen/fecha UTC,
journal de llamadas y restauración de globals/fakes. No hay A8. Base de datos
real se usa solo cuando el harness existente permite fixture aislada; el fake
contractual reproduce códigos y CAS sin SQL textual productivo. Total de
integración: **130 assertions**.

## 34. Assertions exactas

| Artefacto | Assertions |
|---|---:|
| funcional, 12 × 15 | 180 |
| infraestructura | 72 |
| I01 | 20 |
| I02 | 21 |
| I03 | 23 |
| I04 | 19 |
| I05 | 22 |
| I06 | 25 |
| seis integraciones | 130 |
| total nuevo A7 | **382** |

La suite esperada tras A7 será 65 harnesses y 5.162 assertions: los tres
archivos nuevos se suman a 62 y `382` a `4.780`.

## 35. Riesgos

| Riesgo | Cierre |
|---|---|
| doble scheduling/pending | segunda lectura, unique y cardinalidad exacta |
| action id divergente | CAS, relectura, no adopción y compensación |
| snapshot obsoleto/superseded | findById repetido y version CAS |
| schedule sin asociación | relectura; cancelación si nuevo; incertidumbre si no confirma |
| asociación sin schedule visible | verificación scheduled → inconsistencia, cero re-agenda |
| AS ausente/incertidumbre | resultados cerrados, fila preservada |
| cancelación indebida | solo action recién creado, nunca reutilizado |
| fallback legacy | `permitsLegacy=false` y guardias de harness |
| adapter directo | única dependencia coordinator |
| autoridad duplicada con A8 | A8 solo consume resultado |
| allowlist ampliada | infraestructura compara seis rutas literales |

## 36. Bloqueos documentales

No existen ambigüedades residuales bloqueantes. A10 §9 fija firmas y delegación;
§10 fija catálogo; §16 separa hook inicial y cuatro hooks durable; §§18–19 fijan
ausencia AS y asociación; §22 fija guard; §23 del código real callback confirma
payload; §26 fija seis rutas; §27 fija 12 casos y seis integraciones.

La frase histórica «scheduling inicial» podía confundirse con agendar el hook
`...initial_reconciliation`. A10 §§6, 16 y 17 la resuelve: ese hook síncrono
entra a A8; A7 agenda `...reconciliation` mediante coordinator. No requiere
corrección normativa adicional.

Decisiones cerradas por esta auditoría donde A10 daba semántica pero no nombre
de factory: factories de §10, `permitsLegacy()`, `mayContinueToA8()`, rechazo
pre-delegación y mapeo por `interventionRequired`. Todas son derivaciones
unívocas del catálogo A10 y del DTO real, no cambios de arquitectura.

## 37. Secuencia de implementación A7

1. Repetir la compuerta base de §2; detener ante cualquier diferencia.
2. Crear interfaz exacta y verificar autoload/FQCN.
3. Crear resultado cerrado y sus invariantes.
4. Crear servicio con una dependencia y una delegación máxima.
5. Crear harness funcional de 12 casos/180 assertions.
6. Crear harness de infraestructura de 72 assertions.
7. Crear un harness de integración con seis escenarios/130 assertions.
8. Ejecutar los tres nuevos y la suite completa esperada 65/5.162.
9. Verificar allowlist y hacer staging selectivo solo de seis archivos.
10. Commit A7 separado solo después de recertificación; no push.
11. Recertificar HEAD, hashes, suite, artifacts, temporales y ausencia A8/A9.

Condiciones de detención: base distinta, documento A10/A6 alterado, firma real
del coordinator distinta, group/hook/payload divergente, suite no verde,
assertions distintas, archivo fuera de allowlist, SQL/adapter/legacy en A7,
segundo coordinate/schedule, implementación A8/A9, staging previo o necesidad
de modificar un contrato existente.

## 38. Criterio de aceptación

A7 queda completado solo si consume resoluciones A6 continuables; usa id/gen
persistidos; es caller único del coordinator externo inicial; no llama adapter;
no agenda dos veces; no habilita legacy; usa hook durable, group y argumentos
literales derivados por coordinator; asocia action id por el contrato existente;
distingue éxito, ya sincronizado, fallo, incertidumbre y AS ausente; supera 12
casos, seis integraciones y 382 assertions; conserva la suite completa verde;
el commit contiene solo seis archivos; no implementa A8/A9/wiring; y no hace
push.

**A7 IMPLEMENTABLE**
