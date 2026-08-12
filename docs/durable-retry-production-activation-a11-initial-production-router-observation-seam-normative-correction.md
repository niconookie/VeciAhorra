# VeciAhorra A11: seam normativa de observación del initial production router

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Veredicto

`A11 INITIAL PRODUCTION ROUTER OBSERVATION SEAM IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`

La única arquitectura autorizada es interfaz productiva más decorator A11 process-local. Se prohíbe insertar observer callback en `publishRetryAuthorityCandidate()`.

## 2. Frontera productiva resuelta

`WebpayReconciliationMaterializer::publishRetryAuthorityCandidate()` conserva su retorno `void`, su cuerpo y su `match`. La observación ocurre alrededor de la única llamada a `DurableRetryInitialProductionRouterInterface::routeReconciliation()`, antes de que el caller descarte el DTO.

Fuente literal única: `durable_retry_initial_production_router.route_reconciliation`, regex `\Adurable_retry_initial_production_router\.route_reconciliation\z`, ASCII case-sensitive y sin aliases. Mapping exacto: `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php`, clase router, función `routeReconciliation`.

## 3. Interfaz productiva exacta

Path futuro: `app/Modules/Orders/Contracts/DurableRetryInitialProductionRouterInterface.php`.

```php
<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Orders\Contracts;
use DateTimeImmutable;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult;
interface DurableRetryInitialProductionRouterInterface
{
    public function routeReconciliation(
        int $reconciliationId,
        DateTimeImmutable $scheduledForUtc
    ): DurableRetryInitialProductionRoutingResult;
}
```

No contiene segunda función, estado, lógica, A11 API, getters, nullability o excepción añadida.

## 4. Implementación productiva

Declaración futura exacta: `final class DurableRetryInitialProductionRouter implements DurableRetryInitialProductionRouterInterface`. Se añade el import de la interfaz; constructor, cuatro dependencias, función pública, argumentos y return type permanecen byte-semánticamente equivalentes.

No cambian decisiones, states, reasons, downstream calls, acciones, catch blocks ni cantidad de invocaciones. La abstracción de tipo no altera comportamiento ordinario.

## 5. Dependencia del materializer

En `WebpayReconciliationMaterializer` se sustituye solo el import concreto por `VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialProductionRouterInterface` y el parámetro promovido queda:

```php
private readonly DurableRetryInitialProductionRouterInterface $initialProductionRouter
```

No cambia constructor adicional, asignación, `materialize()`, `publishRetryAuthorityCandidate(): void`, `match`, control flow ni llamada. El DTO continúa descartándose localmente tras validarlo.

## 6. Decorator A11 exacto

Path futuro: `tests/manual/support/DurableRetryA11ObservedInitialProductionRouter.php`. Namespace `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11ObservedInitialProductionRouter implements DurableRetryInitialProductionRouterInterface
{
    public function __construct(
        private readonly DurableRetryInitialProductionRouterInterface $inner,
        private readonly DurableRetryA11InitialProductionRoutingObservationStore $store,
        private readonly DurableRetryA11InitialProductionRoutingObservationBinding $binding
    ) {}
    public function routeReconciliation(
        int $reconciliationId,
        \DateTimeImmutable $scheduledForUtc
    ): DurableRetryInitialProductionRoutingResult;
}
```

El decorator no clona, normaliza, proyecta, crea proposals o consulta hashes. Double wrapping se rechaza al construir mediante `a11_initial_router_double_wrapped`.

## 7. Retorno exitoso

Secuencia: validar store open y binding; marcar invocation attempt; llamar al inner exactamente una vez; exigir DTO tipado; comprobar ausencia previa; construir observation con argumentos/instancia; registrar; sellar; retornar exactamente la misma instancia (`===`). El timestamp monotónico es metadata privada de lifecycle y no integra identidad, DTO canónico o hash.

Cada state retornado se registra sin filtro. No se considera éxito por ausencia de excepción.

Si el producto retornó pero record/seal falla, el decorator lanza `DurableRetryA11InitialProductionRouterObservationException` y no retorna otro DTO. El efecto productivo ya ocurrido no se revierte ni se falsifica; la invocation A11 falla, conserva evidencia solo para cleanup y ejecuta rollback supervisor. Esta excepción A11 no envuelve ni modifica una excepción productiva.

## 8. Excepciones productivas

El decorator usa `catch (\Throwable $error)`: registra únicamente lifecycle failure separado, sin routing observation; vuelve a lanzar la misma instancia con `throw $error`. Clase, message, code, previous y trace permanecen. Store se limpia durante rollback/finally. No se convierte excepción en routing result.

Fallo del registro de lifecycle no reemplaza la excepción productiva: se adjunta solo al supervisor process-local y se relanza el `$error` original. La invocation sigue fallida.

## 9. Productive observation DTO

Path `tests/manual/support/DurableRetryA11InitialProductionRoutingObservation.php`; `final readonly`.

```php
final readonly class DurableRetryA11InitialProductionRoutingObservation
{
    public function __construct(
        public string $executionId,
        public string $invocationId,
        public string $caseId,
        public string $phase,
        public string $entrypointId,
        public string $participantId,
        public int $participantIndex,
        public string $productiveSource,
        public int $reconciliationId,
        public \DateTimeImmutable $scheduledForUtc,
        public DurableRetryInitialProductionRoutingResult $routingResult,
        public string $routingResultClass,
        public bool $sealed,
        public string $observationHash
    ) {}
    public function toCanonicalArray(): array;
}
```

Orden lógico es el constructor. Canonical projection serializa scheduled time UTC `Y-m-d\TH:i:s.u\Z` y una proyección readonly completa del routing DTO fijada por su futuro codec; conserva además el DTO tipado en memoria. `routingResultClass` debe ser el FQCN exacto. Hash SHA-256 lowercase del JSON canónico sin `observation_hash`. Máximo lógico 65536 bytes; no PID, timing, proposal, action/base hash, state integrado o secretos.

## 10. Binding y store process-local

Binding readonly exacto: execution, invocation, case, phase, entrypoint, participant ID/index y productive source. Key: `(execution_id,invocation_id,participant_id,productive_source)`.

Path store: `tests/manual/support/DurableRetryA11InitialProductionRoutingObservationStore.php`; clase final no static. Estados `empty|open|recorded|sealed|consumed|cleared|failed`.

```php
public function open(DurableRetryA11InitialProductionRoutingObservationBinding $binding): void;
public function beginInvocation(): void;
public function record(DurableRetryA11InitialProductionRoutingObservation $observation): void;
public function seal(): void;
public function hasObservation(): bool;
public function readOnce(DurableRetryA11InitialProductionRoutingObservationBinding $binding): DurableRetryA11InitialProductionRoutingObservation;
public function markConsumed(): void;
public function recordProductiveFailure(\Throwable $error): void;
public function clear(): void;
public function assertEmpty(): void;
public function state(): string;
```

Cardinalidad 0 antes y 1 después de retorno; overwrite, second begin/record, cross-binding y post-seal write fallan. Solo memoria de instancia: no DB, file, cache, transient, option, env, shared memory, socket o pipe.

## 11. Read-once y cleanup

Child owner lee solo en estado sealed, valida binding/hash/class, recibe el DTO, transita a consumed y proyectará en una autoridad posterior. Segunda lectura falla. `markConsumed()` no elimina antes de completar la futura proyección.

Cleanup en `finally`: cerrar open; invalidar unsealed; marcar required-unconsumed; eliminar routing DTO y bindings; transitar cleared; `assertEmpty()`; verificar cero contamination. Se ejecuta tras éxito, excepción productiva/proyección, timeout, termination y rollback global.

## 12. A11-CON-01

Invocation `a11_000000000001_fd`: dos publish participants del identity catalog, dos router decorators, dos store instances y dos bindings independientes. Cada proceso admite máximo una observation. Ningún objeto se comparte entre procesos. Ambos routing results se conservan para proyección posterior aunque uno tenga cero proposals.

Schedule proposal y routing observation son evidencias distintas. Proposal winner, index, timing y PID no seleccionan ni eliminan observation.

## 13. Alcance sobre otras invocations

Esta seam se requiere solo cuando el entrypoint controlado cruza `routeReconciliation()`:

| Invocation/familia | Entrypoint/role | Required |
|---|---|---|
| `001_fd` CON-01 | publish 01/02 | dos, una por participant |
| `005_fd` CON-03 | publish/create | una por proceso que cruza router; forbidden si create no lo cruza |
| `011_fd` CR-01 | publish crash | observation no sustituye barrier; required solo si retorno ocurre antes de crash |
| `037_fd` EX-09 | publish side | required solo para publish child; legacy forbidden |
| `041_fd` OP-01 | HTTP→publish | required para proceso que ejecuta router |
| `045_fd` OP-03 | publish | required |
| `051_fd`,`055_fd`,`057_fd`,`061_fd` | WR HTTP publish paths | required por requester que cruza router |
| `059_fd` WR-05 | recovery→publish | required solo si cruza router |
| restantes 51 invocations | otros entrypoints o replay | forbidden |

La decisión required se congela en invocation descriptor según el path literal; no se infiere después. Esta seam no cubre callback, executor, recovery cancel, repository, legacy o loopback cuando no llaman al router.

## 14. Wiring ordinario

`DurableRetryProductionComposition` sigue construyendo el router concreto y lo entrega como interfaz. `Application` puede conservar la instancia concreta internamente; el materializer solo ve la interfaz. No se crean store, decorator, binding o branch A11 en graph ordinario.

## 15. Wiring A11

Owner: future child composition method `DurableRetryA11ChildComposition::observedInitialProductionRouter(real,store,binding): DurableRetryInitialProductionRouterInterface`. Secuencia: construir real; store; open binding; wrap una vez; inyectar decorator; ejecutar; read-once; futura proyección; clear/assertEmpty en finally.

Un registry process-local de object IDs impide double wrapping durante el contexto y se limpia con store; no es static global ni cruza invocations.

## 16. Compatibilidad histórica

Futuros cambios productivos exactos: interface nueva; router `implements`; materializer import/type. `DurableRetryProductionComposition` y sus construcciones `new` siguen válidas. Tests de router que construyen concrete siguen válidos.

Harnesses que inspeccionan type strings deben adaptarse explícitamente: `tests/manual/durable-retry-production-composition-infrastructure-test.php`, `tests/manual/durable-retry-production-direct-bootstrap-test.php` y tests de direct wiring/materializer afectados. No se autorizan cambios preventivos fuera de referencias exactas.

## 17. Invariantes

Orden validator: interface existe; una función; firma; router implements; materializer interface; decorator implements; inner once; same DTO; same exception; process-local; full key; no previous; one record; sealed; read-once; no contamination; no proposal; no action hash; no channel; empty cleanup.

## 18. Failure reasons

| # | Reason | Condición/etapa | Efecto/rollback/cleanup |
|---:|---|---|---|
| 1 | `a11_initial_router_interface_contract_invalid` | interface/signature | fail before graph; no product; clear |
| 2 | `a11_initial_router_interface_missing` | router lacks implements | igual |
| 3 | `a11_initial_router_concrete_dependency_forbidden` | materializer concrete | igual |
| 4 | `a11_initial_router_decorator_missing` | A11 graph unwrapped | fail pre-call |
| 5 | `a11_initial_router_double_wrapped` | nested decorator | fail pre-call |
| 6 | `a11_initial_router_context_missing` | store not open | fail pre-call |
| 7 | `a11_initial_router_context_mismatch` | binding differs | fail pre-call |
| 8 | `a11_initial_router_observation_duplicate` | second record | invalidate invocation; cleanup |
| 9 | `a11_initial_router_inner_invocation_duplicate` | second delegate attempt | fail closed |
| 10 | `a11_initial_router_result_missing` | no DTO after normal return | invalidate |
| 11 | `a11_initial_router_result_type_mismatch` | wrong type/class | invalidate |
| 12 | `a11_initial_router_result_mutation_detected` | identity/hash changed | invalidate |
| 13 | `a11_initial_router_productive_exception_mutated` | rethrow differs | invalidate |
| 14 | `a11_initial_router_observation_recording_failed` | record fails | A11 exception; product effect retained; cleanup |
| 15 | `a11_initial_router_observation_seal_failed` | seal fails | igual |
| 16 | `a11_initial_router_read_before_seal` | early read | invalidate |
| 17 | `a11_initial_router_read_duplicate` | second read | invalidate |
| 18 | `a11_initial_router_observation_not_consumed` | required remains sealed | invalidate at cleanup |
| 19 | `a11_initial_router_cleanup_incomplete` | residual reference/binding | invocation failed; report residual |
| 20 | `a11_initial_router_store_contaminated` | cross invocation | invalidate |
| 21 | `a11_initial_router_productive_source_mismatch` | source not literal | invalidate |

Precedencia es el número. Rollback nunca revierte producto; descarta evidencia A11 candidate y conserva coordinator state/hashes. Cleanup elimina store/registry y termina el proceso conforme al supervisor.

## 19. API y paths

Además de §§3,6,9–10: binding `final readonly` en `tests/manual/support/DurableRetryA11InitialProductionRoutingObservationBinding.php`; exception final en `tests/manual/support/DurableRetryA11InitialProductionRouterObservationException.php` con constructor `__construct(public readonly string $reason, ?\Throwable $previous=null)`.

Router y materializer conservan firmas descritas. Ordinary composition retorna concrete donde su API histórica lo exige; A11 factory retorna interface. No existe observer callback alternativo.

## 20. Precedencia

Esta corrección selecciona exclusivamente interface+decorator, prohíbe callback en materializer y no cambia states, reasons, returns, actions o decisions. No proyecta/agrega contributions, usa winner, materializa deltas, avanza hashes o añade canales. Es obligatoria para projection catalog, aggregator, topology e implementación EA6.

## 21. Allowlist futura exacta

1. `app/Modules/Orders/Contracts/DurableRetryInitialProductionRouterInterface.php`.
2. `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php`.
3. `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`.
4. `tests/manual/support/DurableRetryA11ObservedInitialProductionRouter.php`.
5. `tests/manual/support/DurableRetryA11InitialProductionRoutingObservation.php`.
6. `tests/manual/support/DurableRetryA11InitialProductionRoutingObservationBinding.php`.
7. `tests/manual/support/DurableRetryA11InitialProductionRoutingObservationStore.php`.
8. `tests/manual/support/DurableRetryA11InitialProductionRouterObservationException.php`.
9. A11 child composition wiring file cuando sea publicado por topology.
10. `tests/manual/durable-retry-a11-initial-production-router-observation-seam-test.php`.
11. `tests/manual/durable-retry-production-composition-infrastructure-test.php`.
12. `tests/manual/durable-retry-production-direct-bootstrap-test.php`.
13. `tests/manual/durable-retry-production-direct-wiring-test.php`.

Sin comodines. Se excluyen projection catalog, aggregator, action materializer, topology y suites generales.

## 22. Matriz adversarial

| R | Escenario | Resultado |
|---:|---|---|
| 01–03 | ordinary interface, real materializer, decorated materializer | acepta |
| 04–05 | one delegation / double delegation | acepta / reject |
| 06–08 | same DTO/state/reason | preservados |
| 09–10 | same exception / no wrapping | preservados |
| 11–16 | exact observation, duplicate, no context, wrong invocation/participant/source | acepta/reason |
| 17–19 | read pre-seal, read-once, second read | reject/accept/reject |
| 20–22 | cleanup success/product exception/projection failure | empty |
| 23 | contaminated store | reject |
| 24 | two CON-01 processes | isolated stores |
| 25–26 | inverted winner / different PIDs | observations unchanged |
| 27–28 | decorator proposal/hash | none/none |
| 29 | ordinary graph | no store |
| 30 | double wrapping | reject |
| 31 | historic harness | compatible after exact assertion update |
| 32 | extra persistence/channel | reject |

## 23. Cierre

La frontera antes inaccesible queda decorable mediante una única interfaz sin tocar el cuerpo void. El DTO productivo se observa por identidad, una vez, en memoria local y se limpia; producto ordinario permanece inalterado.
