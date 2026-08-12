# Corrección normativa A10 — wiring productivo Durable Retry

## 1. Propósito normativo de A10

Esta corrección cierra el contrato de integración productiva sin modificar las
autoridades A2.1–A5 ya certificadas. La secuencia autoritativa es:

```text
A5: decisión de autoridad
→ A6: resolución de identidad durable persistida
→ A7: coordinación externa inicial
→ A8: enrutamiento productivo inicial
→ A9: composición, hooks y exclusión legacy
```

A2.1, A2, A3 y A4 son dependencias internas de A5; A8 no las invoca
directamente. A4 ya materializa `generation = 1`. Por tanto, A6 no es un
materializador con INSERT: es el puente read-only que resuelve la fila durable
confirmada y entrega su identificador técnico a A7.

Veredicto: **A10 IMPLEMENTABLE**.

## 2. Precedencia documental

A10 es autoritativo desde su versionado. El orden de precedencia es:

1. contratos PHP versionados de A1–A5;
2. correcciones normativas A3, A4, A5 y este documento;
3. especificaciones A2/A2.1 y auditorías certificadas;
4. diseño de activación;
5. especificación histórica de composición;
6. diseño histórico de wiring.

Una regla inferior incompatible queda reemplazada, no combinada.

| Regla histórica | Fuente | Estado | Regla autoritativa A10 |
|---|---|---|---|
| A5 crea schedule | composition spec | anulada | A4 crea fila; A5 solo decide |
| A5 llama Action Scheduler | composition spec | anulada | solo A7 llama coordinator |
| A10 no reutiliza A3/A4 | composition spec | anulada | A5 conserva ownership de A3/A2/A4 |
| producción inicial sin hook cerrado | wiring design | anulada | action literal definido en §16 |
| registrar idempotente por instancia basta | wiring histórico | insuficiente | A9 usa guardia estática global |
| legacy convive sin consultar A3 | flujo histórico | anulada | A9 instala guardas obligatorios |

Continúan vigentes los catálogos, invariantes y presupuestos certificados de
A2.1–A5, repositorio durable, coordinator, executor, callback y processors.

## 3. Regla definitiva de A5

`VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer`
evalúa, en este orden, A3 y, solo para `legacy`, A2/A2.1 y A4. Devuelve
`DurableRetryInitialAuthorityProductionResult`.

A5 no ejecuta SQL propio, no crea filas por sí mismo, no obtiene locks, no
agenda acciones, no consulta Action Scheduler, no registra hooks, no llama
legacy, no genera `schedule_id` ni `scheduled_action_id`, no inicia executor ni
processor y no contiene fallback.

Catálogo exacto:

| Estado A5 | Razón | Continuación | Legacy |
|---|---|---|---|
| `legacy_allowed` | `activation_policy_rejected` | A8 llama legacy una vez | sí |
| `legacy_in_flight` | razón A4 | terminal/recovery | no |
| `durable_existing` | `durable_authority_already_exists` | A6 | no |
| `durable_created` | `initial_transfer_created` | A6 | no |
| `durable_converged` | `equivalent_transfer_exists` | A6 | no |
| `functionally_ineligible` | razón A4 | terminal | no |
| `authority_indeterminate` | razón A3 | terminal/recovery | no |
| `durable_inconsistency` | razón A4 | terminal/intervención | no |
| `configuration_invalid` | razón A2/A2.1 | terminal | no |
| `persistence_error` | `persistence_write_failed` | terminal/recovery | no |
| `outcome_uncertain` | `persistence_outcome_uncertain` | terminal/recovery | no |
| `operational_failure` | catálogo A5 | terminal/recovery | no |

Los campos exactos permanecen `state`, `reason`, `authorityResult` nullable y
`transferResult` nullable. A10 no añade estados ni campos a A5.

## 4. Nomenclatura definitiva A6–A9

| ID | Nombre | Responsabilidad única |
|---|---|---|
| A6 | Resolución inicial de identidad durable | resolver por identidad la fila gen1 ya materializada |
| A7 | Coordinación externa inicial durable | invocar coordinator una vez con id y generación resueltos |
| A8 | Enrutamiento productivo inicial | ejecutar A5 y seleccionar exactamente legacy, durable o cierre |
| A9 | Composición productiva y exclusión legacy | instalar grafo/hooks una vez y proteger scheduler/worker/recovery legacy |

A6 es read-only. A7 no decide autoridad. A8 no implementa persistencia ni
Action Scheduler. A9 no decide cohortes ni crea autoridad.

## 5. A6: resolución inicial durable

FQCN de interfaz:
`VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface`.

FQCN concreto:
`VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver`.

```php
interface DurableRetryInitialScheduleResolverInterface
{
    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $production
    ): DurableRetryInitialScheduleResolutionResult;
}

final class DurableRetryInitialScheduleResolver
    implements DurableRetryInitialScheduleResolverInterface
{
    public function __construct(
        DurableRetryScheduleRepositoryInterface $repository
    );

    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $production
    ): DurableRetryInitialScheduleResolutionResult;
}
```

A6 acepta únicamente `durable_created`, `durable_converged` o
`durable_existing`. Ejecuta exactamente
`findByIdentity('reconciliation', subjectId, 1)`. Exige un snapshot compatible:

- stage `reconciliation`;
- subject y completion iguales al reconciliation id;
- generation `1`;
- attempt `0`;
- `scheduled_for` igual al request para created/converged;
- status `dispatching` o `scheduled`;
- active slot `1` para dispatching;
- version positiva;
- id positivo.

Para `durable_existing`, la fecha persistida prevalece sobre el request porque
la autoridad precede la invocación. A6 no compara `scheduled_for` en esa rama.

Resultado cerrado:

```php
final class DurableRetryInitialScheduleResolutionResult
{
    public const RESOLVED_DISPATCHING = 'resolved_dispatching';
    public const RESOLVED_SCHEDULED = 'resolved_scheduled';
    public const NOT_FOUND = 'not_found';
    public const INCOMPATIBLE = 'incompatible';
    public const READ_ERROR = 'read_error';

    public function state(): string;
    public function reason(): string;
    public function scheduleId(): ?int;
    public function generation(): ?int;
    public function scheduledForUtc(): ?DateTimeImmutable;
}
```

Solo los dos estados `RESOLVED_*` poseen id, generación `1` y fecha UTC.
`RESOLVED_SCHEDULED` permite convergencia verificadora en A7. A6 no llama A4:
A4 ya fue invocado dentro de A5 y es el único INSERT inicial.

## 6. Firma definitiva del punto materializador

El materializador no recibe A2–A7. Publica un evento productivo tipado por
parámetros escalares cerrados después de confirmar la reconciliation:

```php
private function publishRetryAuthorityCandidate(
    int $reconciliationId,
    string $scheduledForUtc
): void;
```

Implementación normativa:

```php
do_action(
    'veciahorra_durable_retry_initial_reconciliation',
    $reconciliationId,
    $scheduledForUtc
);
```

Se invoca exactamente una vez en cada retorno no nulo de `materialize()` y
`resume()`, sustituyendo la construcción directa de
`DurableCompletionScheduler`. `reconciliationId` es positivo y procede de la
fila confirmada. `scheduledForUtc` se captura una vez mediante
`gmdate('Y-m-d H:i:s')`, no es nullable y conserva precisión de segundos.

No cambia la firma pública ni el resultado de
`WebpayReconciliationMaterializer`. El action es síncrono; una excepción del
callback A8 no se propaga porque A8 captura dependencias y produce cierre
tipado. WordPress no aporta campos de autoridad.

## 7. Puente A5/A4 hacia schedule id

Secuencia obligatoria:

1. A8 construye un único `DurableRetryInitialTransferRequest`.
2. A8 llama A5 exactamente una vez.
3. A5 puede llamar A4 exactamente una vez.
4. A4 crea o converge `generation = 1`.
5. A5 devuelve created, converged o existing.
6. A8 entrega request y resultado A5 a A6.
7. A6 llama `findByIdentity(stage, subjectId, 1)`.
8. El snapshot proporciona `id()` como `schedule_id`.
9. El snapshot proporciona `generation()` y debe ser `1`.
10. A7 recibe el resultado resuelto.

Duplicate key compatible converge mediante A4 y luego A6 lee la fila.
Incompatible, commit incierto sin evidencia o lectura incompatible no llegan a
A7. Una segunda llamada A4, un segundo INSERT o inferir id desde `insert_id`
quedan prohibidos.

## 8. Identidad canónica

| Campo | Origen | Clase de dato | Confianza |
|---|---|---|---|
| stage | A8 constante reconciliation; repository para callback | interno | no externo |
| subject_id | reconciliation confirmada | interno positivo | validado |
| completion_id | igual a subject para reconciliation | derivado | validado |
| schedule_id | snapshot A6 | persistido positivo | nunca externo inicial |
| generation | snapshot A6 | persistido, inicialmente 1 | nunca inferido del hook inicial |
| attempt_number | A4, inicialmente 0 | persistido | no externo |
| scheduled_for | snapshot/request UTC | persistido | estable |
| hook/group | catálogo | derivado | cerrado |
| worker_id | executor/processor | interno | no payload |

Ejemplos de callback:

- reconciliation: `(schedule_id=101, generation=1)` en
  `veciahorra_durable_retry_reconciliation`;
- business completion: `(202,2)` en
  `veciahorra_durable_retry_business_completion`;
- delivery completion: `(303,1)` en
  `veciahorra_durable_retry_delivery_completion`;
- fulfillment: `(404,3)` en
  `veciahorra_durable_retry_fulfillment_completion`.

El stage siempre se deriva de la fila, no del payload.

## 9. A7: scheduling externo inicial

Interfaz:
`VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface`.
Concreto:
`VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator`.

```php
interface DurableRetryInitialScheduleCoordinatorInterface
{
    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult;
}

final class DurableRetryInitialScheduleCoordinator
    implements DurableRetryInitialScheduleCoordinatorInterface
{
    public function __construct(
        DurableRetryExternalScheduleCoordinatorInterface $coordinator
    );

    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult;
}
```

A7 es el único caller autorizado para coordinación externa inicial. Delega una
vez `coordinate(scheduleId, generation)`. El coordinator existente determina
hook, grupo, payload, fecha persistida, búsqueda, schedule, asociación,
convergencia y compensación.

Máximos heredados: dos lecturas iniciales del repositorio, una búsqueda/schedule
externo en la rama dispatching, una asociación CAS y las relecturas/compensación
cerradas por el coordinator. A7 no amplía esos máximos.

## 10. Resultado de scheduling A7

```php
final class DurableRetryInitialSchedulingResult
{
    public const SYNCHRONIZED = 'synchronized';
    public const ALREADY_SYNCHRONIZED = 'already_synchronized';
    public const EXTERNAL_UNAVAILABLE = 'external_unavailable';
    public const COORDINATION_FAILED = 'coordination_failed';
    public const COORDINATION_UNCERTAIN = 'coordination_uncertain';

    public function state(): string;
    public function reason(): string;
    public function scheduleId(): int;
    public function generation(): int;
    public function scheduledActionId(): ?int;
    public function requiresIntervention(): bool;
}
```

`SYNCHRONIZED` agrupa creación o convergencia confirmada.
`ALREADY_SYNCHRONIZED` conserva una asociación confirmada.
`EXTERNAL_UNAVAILABLE` no crea fallback.
`COORDINATION_FAILED` representa cierre conocido sin éxito.
`COORDINATION_UNCERTAIN` exige intervención/recuperación A9.
Los reason codes son exactamente los códigos de
`DurableRetryCoordinationResult`; A7 no inventa razones ni emite logs/métricas.

## 11. A8: orquestación productiva inicial

FQCN:
`VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter`.

```php
final class DurableRetryInitialProductionRouter
{
    public function __construct(
        DurableRetryInitialAuthorityProducerInterface $producer,
        DurableRetryInitialScheduleResolverInterface $resolver,
        DurableRetryInitialScheduleCoordinatorInterface $coordinator,
        DurableRetryLegacySchedulerInterface $legacy
    );

    public function routeReconciliation(
        int $reconciliationId,
        DateTimeImmutable $scheduledForUtc
    ): DurableRetryInitialProductionRoutingResult;
}
```

Orden real:

```text
validar entrada
→ construir authority/request
→ A5 una vez
→ legacy_allowed: legacy una vez
→ durable confirmed: A6 una vez → A7 una vez
→ cualquier otro estado: cierre sin productor
```

A8 no lee configuración, A2, A3 ni A4 directamente; esas operaciones pertenecen
a A5. No contiene SQL, no llama adapter, no reinterpreta reason codes, no
reintenta y no hace fallback.

El callback del action inicial normaliza la fecha con formato exacto
`!Y-m-d H:i:s` y timezone UTC. Entrada inválida produce resultado
`INVALID_INPUT` sin dependencias.

## 12. Resultado global pos-A5

`DurableRetryInitialProductionRoutingResult` tiene:
`state`, `reason`, `reconciliationId`, `scheduleId?`, `generation?`,
`scheduledActionId?`, `legacyScheduled`, `requiresIntervention`.

Catálogo:

| Estado | Persistencia | Scheduling | Legacy |
|---|---|---|---|
| `legacy_scheduled` | ninguna durable | legacy confirmado | sí |
| `legacy_unavailable` | ninguna durable | ninguno | autorizado pero falló |
| `durable_synchronized` | durable | confirmado | no |
| `durable_already_synchronized` | durable | confirmado | no |
| `durable_external_unavailable` | durable | no confirmado | no |
| `durable_coordination_failed` | durable | fallido | no |
| `durable_coordination_uncertain` | durable | incierto | no |
| `authority_closed` | según A5 | ninguno | no |
| `resolution_failed` | durable/indeterminada | ninguno | no |
| `invalid_input` | ninguna | ninguno | no |
| `dependency_failure` | desconocida | ninguno adicional | no |

`reason` conserva el código A5, A6, A7 o legacy. Solo los dos primeros estados
pueden reflejar permiso legacy; solo `legacy_scheduled` marca
`legacyScheduled=true`. No se lanzan excepciones fuera del callback.

## 13. Convivencia legacy

| A3/A2/A4 vía A5 | Siguiente | Legacy | Durable externo |
|---|---|---|---|
| `legacy_allowed` | scheduler legacy guardado | máximo 1 | 0 |
| `durable_existing` | A6→A7 | 0 | máximo 1 convergente |
| `durable_created` | A6→A7 | 0 | máximo 1 |
| `durable_converged` | A6→A7 | 0 | máximo 1 convergente |
| `legacy_in_flight` | cierre | 0 nuevo | 0 |
| `functionally_ineligible` | cierre | 0 | 0 |
| `authority_indeterminate` | cierre | 0 | 0 |
| `configuration_invalid` | cierre | 0 | 0 |
| error/inconsistencia/incertidumbre | cierre/recovery | 0 | 0 |

Quedan prohibidos fallback legacy por excepción, scheduling fallido, Action
Scheduler ausente, persistencia durable, configuración inválida o
indeterminación. A5 se evalúa una sola vez y `legacy_allowed` es la única
autorización.

## 14. A9: bootstrap, registro y exclusión

`Application::registerDurableRetryGraph(): void` permanece privado y es el único
composition root. Registra como singletons A2.1–A8, repositorios, coordinator,
processors, registry, executor, callback y registrars.

`Application::run()` invoca:

1. registrar A9 del action inicial;
2. registrar de cuatro callbacks durables;
3. orquestación legacy construida con guardas A9;
4. módulos restantes.

Clase dedicada:
`VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionHookRegistrar`.
Registra el action inicial y delega a A8.

A9 también introduce `DurableRetryLegacySchedulerInterface` como puerto sobre
el scheduler legacy y añade A3 como guarda obligatorio de scheduler, workers y
recovery. Ninguno reevalúa A2.

## 15. Idempotencia global del bootstrap

Estrategia autoritativa: propiedad estática privada en `Application`:

```php
private static bool $registered = false;
```

`run()` retorna inmediatamente si es `true`; la cambia a `true` antes del
primer `add_action`. Si ocurre una excepción de registro, restaura `false` y
propaga: no se continúa con una composición parcial. Los registrars conservan
sus guardas de instancia como defensa secundaria.

Quedan prohibidos `did_action`, globals, opción WordPress y guardia solo de
contenedor. Cada hook se registra máximo una vez por proceso PHP/request.
Procesos separados registran una vez cada uno, que es la semántica normal de
WordPress. Admin, frontend, REST, cron, runner y tests usan la misma regla.

## 16. Hooks productivos exactos

| Hook | Tipo | Prioridad | Args | Callback |
|---|---|---:|---:|---|
| `veciahorra_durable_retry_initial_reconciliation` | `add_action` | 10 | 2 | `DurableRetryProductionHookRegistrar` → A8 |
| `veciahorra_durable_retry_reconciliation` | `add_action` | 10 | 2 | callback durable existente |
| `veciahorra_durable_retry_business_completion` | `add_action` | 10 | 2 | callback durable existente |
| `veciahorra_durable_retry_delivery_completion` | `add_action` | 10 | 2 | callback durable existente |
| `veciahorra_durable_retry_fulfillment_completion` | `add_action` | 10 | 2 | callback durable existente |

El primer payload es `(int reconciliationId, string scheduledForUtc)`. Los
cuatro payloads durable son `(int scheduleId, int generation)`. Grupo externo:
`veciahorra-durable-retry`.

Los hooks legacy existentes no cambian de nombre, prioridad o argumentos. Su
ejecución queda guardada por A3. No se añade hook de bootstrap.

## 17. Punto de producción

El punto exacto es
`WebpayReconciliationMaterializer::publishRetryAuthorityCandidate()`, invocado
después de obtener una reconciliation confirmada y antes del retorno.

El callback público del registrar es:

```php
public function onInitialReconciliation(
    mixed $reconciliationId,
    mixed $scheduledForUtc
): void;
```

WordPress entrega mixed; el registrar exige entero positivo y string canónico
UTC. Después construye `DateTimeImmutable` y llama A8. Entrada inválida cierra
sin efectos. El callback no acepta arrays, stage, completion, generation,
schedule id ni hook desde el emisor.

## 18. Disponibilidad de Action Scheduler

El adapter verifica las funciones exactas que ya usa:
`as_schedule_single_action`, `as_get_scheduled_actions` y, para compensación,
`as_unschedule_action`. La comprobación ocurre en A7/coordinator al coordinar,
no durante bootstrap.

Los callbacks se registran aunque Action Scheduler no esté disponible. La
autoridad durable ya confirmada se conserva. A7 retorna
`EXTERNAL_UNAVAILABLE`; legacy queda prohibido. No se lanza excepción ni se
borra/retrocede la fila.

## 19. Orden transaccional y efectos

```text
A3/A2 lecturas
→ A4 START TRANSACTION
→ locks funcional + durable
→ INSERT gen1 máximo uno
→ COMMIT o reconciliación incierta
→ A5 retorno
→ A6 lectura findByIdentity
→ A7 coordinator
→ Action Scheduler
→ asociación scheduled_action_id por CAS
```

Solo repositorios hacen SQL. Action Scheduler siempre ocurre después de commit.
Un fallo externo deja autoridad durable en `dispatching` para recuperación; no
revierte el commit ni habilita legacy.

## 20. Matriz de errores

| Caso | Resultado | SQL/cierre | Scheduling | Legacy/retry |
|---|---|---|---|---|
| config inválida | `authority_closed` | solo lecturas | 0 | no/no local |
| A3 indeterminate | `authority_closed` | SELECT A3 | 0 | no/recovery |
| A4 uncertainty | `authority_closed` | commit incierto | 0 | no/recovery |
| A4 inconsistency | `authority_closed` | rollback/commit certificado | 0 | no/intervención |
| duplicate compatible | durable converged | commit | A6→A7 | no/converge |
| duplicate incompatible | closed | rollback | 0 | no |
| commit incierto resuelto | durable created/converged | evidencia externa | A6→A7 | no |
| fila existente | durable existing | A6 SELECT | A7 converge | no |
| schedule existente | already synchronized | asociación existente | búsqueda | no |
| scheduling fallido | coordination failed | posible CAS/compensación | fallido | no/recovery |
| scheduling incierto | coordination uncertain | fila durable | incierto | no/intervención |
| AS ausente | external unavailable | fila durable | 0 | no/recovery |
| hook inicial inválido | invalid input | 0 | 0 | no |
| callback durable inválido | resultado callback existente | lectura 0 | 0 | no |
| excepción inesperada | dependency failure | sin efecto adicional | 0 adicional | no |
| legacy no disponible | legacy unavailable | 0 durable | 0 | no retry local |
| bootstrap duplicado | no-op | 0 | 0 | no |

Reason codes se heredan del componente causante. A8/A9 no crean logs ni métricas.

## 21. Recovery legacy

Recovery legacy es la búsqueda de reconciliations/completions
pending/retryable y su reprogramación en hooks `veciahorra-completion`.
A9 instala el guarda, pero su implementación se certifica como subhito A9-L.

Antes de cada scheduling recovery, A3 clasifica la identidad. Solo `legacy`
permite continuar. `durable` e `indeterminate` bloquean. Cualquier generation 1,
incluidos estados dispatching, scheduled, claimed, terminales o superseded,
mantiene autoridad durable y bloquea recovery legacy. Fallo externo nunca
devuelve autoridad a legacy.

## 22. Scheduler guard

El scheduler legacy recibe
`DurableRetryLegacyExclusionInterface`. Antes de programar reconciliation
clasifica una sola identidad:

- legacy: aplica deduplicación legacy existente;
- durable: no-op;
- indeterminate/error: no-op cerrado.

El scheduler durable queda guardado por A6 y coordinator: id/generation
positivos, fila compatible, status dispatching/scheduled, payload derivado,
pending único y asociación CAS. A7 implementa este tramo; A9 implementa el
guarda legacy.

## 23. Worker guard

El callback durable existente valida hook cerrado, schedule id y generación.
El executor relee la fila, deriva stage, realiza una claim CAS, rechaza
generación stale y callbacks repetidos. Worker id no proviene del payload.

Los workers legacy deben consultar A3 antes de claim/proceso:

- legacy: continúan;
- durable o indeterminate: terminan sin retry ni siguiente stage.

Acciones antiguas pending quedan inocuas. Porcentaje 0 no habilita legacy para
una identidad durable.

## 24. Rollback operativo

Configurar 0% afecta solo nuevas identidades legacy. Se preservan filas,
generaciones, acciones pending, callbacks, processors, recovery durable y
exclusión legacy. Una fila dispatching sin scheduling confirmado continúa
durable y A9 recovery debe reintentar coordinación, nunca legacy.

## 25. Presupuesto operacional

### Producción inicial

| Operación | Máximo |
|---|---:|
| A5 | 1 |
| A3 | 1 clasificación |
| A2/A2.1 | 1/1 solo si legacy |
| A4 | 1 |
| A6 | 1 |
| INSERT | 1 |
| locks | 2 filas lógicas por A4 |
| COMMIT | 1 |
| ROLLBACK | 1, excluyente con commit confirmado |
| búsqueda/creación AS | 1 operación lógica de coordinación |
| schedule nuevo | 1 |
| asociación | 1 |
| legacy | 1 solo legacy_allowed |

### Bootstrap

- una construcción lazy por singleton solicitado;
- cinco registros nuevos/existentes durable por proceso;
- cero comprobaciones Action Scheduler;
- cero SQL y scheduling.

### Callback durable

- una lectura inicial;
- una claim CAS;
- un processor;
- una transición terminal o creación de sucesor;
- una coordinación de sucesor;
- cero legacy.

Los presupuestos internos de coordinator y A4 permanecen los certificados por
sus harnesses y no se amplían.

## 26. Allowlists normativas

### A6

Nuevos:

- `app/Modules/Orders/Contracts/DurableRetryInitialScheduleResolverInterface.php`
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialScheduleResolutionResult.php`
- `app/Modules/Orders/Services/DurableRetryInitialScheduleResolver.php`
- `tests/manual/durable-retry-initial-schedule-resolver-test.php`
- `tests/manual/durable-retry-initial-schedule-resolver-infrastructure-test.php`

### A7

Nuevos:

- `app/Modules/Orders/Contracts/DurableRetryInitialScheduleCoordinatorInterface.php`
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialSchedulingResult.php`
- `app/Modules/Orders/Services/DurableRetryInitialScheduleCoordinator.php`
- `tests/manual/durable-retry-initial-schedule-coordinator-test.php`
- `tests/manual/durable-retry-initial-schedule-coordinator-infrastructure-test.php`
- `tests/manual/durable-retry-initial-schedule-coordinator-integration-test.php`

### A8

Nuevos:

- `app/Modules/Orders/Contracts/DurableRetryLegacySchedulerInterface.php`
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialProductionRoutingResult.php`
- `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php`
- `tests/manual/durable-retry-initial-production-router-test.php`
- `tests/manual/durable-retry-initial-production-router-infrastructure-test.php`
- `tests/manual/durable-retry-initial-production-router-integration-test.php`

### A9

Nuevos:

- `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionHookRegistrar.php`
- `tests/manual/durable-retry-production-hook-registrar-test.php`
- `tests/manual/durable-retry-production-hook-registrar-infrastructure-test.php`

Modificados:

- `app/Core/Application.php`
- `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionRecovery.php`
- `app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php`

Integraciones nuevas:

- `tests/manual/durable-retry-production-wiring-integration-test.php`
- `tests/manual/durable-retry-legacy-exclusion-integration-test.php`
- `tests/manual/durable-retry-bootstrap-idempotency-test.php`

Cada subhito prohíbe modificar schema, migraciones, A1–A5, repositorio durable,
coordinator, executor, callback, processors y documentos. La integración final
solo puede modificar `Application.php`, materializer y orquestación legacy si
esas modificaciones no fueron ya realizadas por A9; no existe allowlist “por
si acaso”.

## 27. Harnesses A6–A9

| Subhito | Casos funcionales | Infraestructura | Integración |
|---|---:|---:|---:|
| A6 | 12 | firmas, una lectura, cero escritura | incluido en A8 |
| A7 | 12 | único caller, cero decisión | 6 casos coordinator real |
| A8 | 24 | orden, cero SQL/adapter | 10 integraciones |
| A9 | 16 | hooks/guardas/idempotencia | 8 escenarios WordPress |

A6 cubre tres estados durables, dispatching/scheduled, ausencia, error,
incompatibilidades de stage/id/generation/completion/status y fecha.
A7 cubre todos los códigos del coordinator.
A8 conserva los 24 casos de la auditoría.
Las 10 integraciones cubren wiring→A5, legacy, A4, A6/A7, callback/executor,
registry/4 processors, exclusión, AS disponible/ausente, concurrencia y cohorte.
A9 cubre doble run, dos Application, cinco contextos WordPress y excepción.

Cada harness usa fixtures/doubles nuevos, journal ordenado, limpieza `finally`,
guardias de allowlist, cero diagnostics y detención ante primera desviación.

## 28. Matriz de trazabilidad

| Regla A10 | Fuente previa | Contradicción corregida | Futuro | Harness |
|---|---|---|---|---|
| A5 authority-only | corrección A5 | A5 scheduler histórico | A5 intacto | infraestructura A8 |
| A4 único INSERT | A4 | “A6 materializa” | A6 resolver | A6 funcional |
| id desde snapshot | repository API | identity sin id | A6 | A6 integración |
| único caller inicial | coordinator | caller ausente | A7 | A7 infra |
| rama única | auditoría wiring | legacy directo | A8 | 24 casos |
| hook inicial literal | auditoría | punto abierto | A9 registrar | A9 infra |
| bootstrap estático | auditoría | guardia por instancia | Application | idempotencia |
| guardas legacy | design A6–A8 | ausencia actual | A9 | exclusión |
| rollback conserva durable | A3/A4 | fallback implícito | A8/A9 | integración |
| AS ausente sin fallback | coordinator | semántica abierta | A7/A8 | integración |

## 29. Orden obligatorio

1. versionar A10;
2. auditar, implementar, recertificar y versionar A6;
3. auditar, implementar, recertificar y versionar A7;
4. auditar, implementar, recertificar y versionar A8;
5. auditar, implementar, recertificar y versionar A9;
6. auditoría final de wiring;
7. integración final solo si queda trabajo de composición;
8. suite completa;
9. activación productiva inicialmente 0%;
10. commit selectivo separado; sin push.

Un subhito posterior queda prohibido si el anterior no está versionado y verde.

## 30. Condiciones para autorizar A6

- A10 versionado e intacto;
- base Git exacta recertificada;
- A1–A5 verdes;
- interfaz, resultado, FQCN y allowlist A6 respetados;
- repositorio durable `findByIdentity` intacto;
- cero cambios a A4;
- presupuesto de una lectura A6;
- cero SQL fuera del repositorio;
- cero hooks/scheduling/legacy en A6;
- staging vacío antes de implementación;
- commit solo tras recertificación separada.

## 31. Criterio de aceptación A10

A10 cierra A5 como authority-only; separa A6–A9; define el puente hasta
scheduling, la firma materializadora, el origen de id/generation, el caller
único del coordinator, el action inicial, la convivencia legacy, los resultados
pos-A5, idempotencia global, disponibilidad externa, recovery y guardas.

No quedan decisiones arquitectónicas necesarias para iniciar A6. Las APIs,
estados, presupuestos, secuencia, hooks y allowlists quedan cerrados por esta
corrección. Cualquier alternativa no enumerada está prohibida y requiere una
nueva corrección normativa versionada antes de código.

**A10 IMPLEMENTABLE**
