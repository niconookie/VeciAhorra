# Auditoría de implementabilidad A8 — orquestación inicial de rama única Durable Retry

## 1. Veredicto ejecutivo

**A8 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

A8 puede orquestar A5 → A6 → A7 con los contratos versionados y puede cerrar
todos los resultados durable sin fallback. No puede implementarse exactamente
bajo A10 porque el cuarto puerto de su constructor,
`DurableRetryLegacySchedulerInterface`, carece de método y retorno normativos.
El productor real `DurableCompletionScheduler::reconciliation(int): void`
silencia éxito, deduplicación e indisponibilidad, mientras A10 obliga a distinguir
`legacy_scheduled` de `legacy_unavailable`.

La arquitectura restante está cerrada: A8 construye el request, invoca A5 una
vez, elige una rama, invoca A6/A7 una vez en durable y devuelve el resultado
global A10. Antes de implementar A8 debe versionarse una corrección normativa
que fije el puerto legacy de §11. Después corresponde una nueva auditoría A8;
A9 continúa prohibido.

## 2. Estado base certificado

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `f827047bbb39abdcd42c288dbb45801c193d1672` |
| Parent | `7769dcf74e7b75385f07fff31bbb2be4847fd51d` |
| Divergencia | `0` atrás / `50` adelante |
| Staging / tracked | `0 / 0` |
| Suite Durable Retry | `65/65`, `5.162` assertions |
| Fallos / warnings / notices / deprecations | `0 / 0 / 0 / 0` |
| Integraciones | cuatro históricas y A7 verdes |
| `artifacts/` | `504` |
| Temporales / índices temporales | `0 / 0` |
| A8 / A9 / wiring | ausentes |
| Push | no realizado |

A10 conserva SHA-256
`a0b28304715a4a0a4389de5743a425cbe5b0bb07939b5cef77ab81bc94db79bf`.
Esta auditoría no existía al iniciar. Los untracked ajenos preexistentes no se
tocaron.

## 3. Autoridad normativa

Precedencia: contratos PHP versionados A5–A7; A10; auditorías versionadas
A5–A7; auditoría histórica de wiring; diseño histórico; flujo legacy real como
evidencia, no como norma nueva.

A10 reemplaza «A5 agenda», «A6 materializa», selección legacy directa desde el
materializer y convivencia híbrida. La norma vigente es: A8 construye request,
A5 decide, solo `legacy_allowed` habilita legacy, autoridad durable llama A6 y
solo resolución continuable llama A7. A9 publica/registra el hook y protege el
runtime legacy.

## 4. Responsabilidad exacta de A8

A8 recibe `reconciliationId` positivo y fecha UTC, construye una identidad
`reconciliation`, construye un `DurableRetryInitialTransferRequest`, llama A5
exactamente una vez y selecciona legacy, durable o cierre. En legacy llama una
vez al puerto autorizado. En durable llama A6 una vez y A7 una vez solo si A6
continúa. Devuelve un resultado global cerrado.

A8 debe construir el request: su firma pública A10 recibe escalares tipados, y
el request es la captura estable compartida por A5 y A6. Recibirlo ya construido
divergiría de `routeReconciliation(int, DateTimeImmutable)`.

A8 no reevalúa A2–A4, no repite A5–A7, no ejecuta SQL/scheduling, no registra
hooks, no ejecuta callback/executor/processors y no reintenta.

## 5. Inventario del punto de producción

Ruta real:
`app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`.
Hoy no existe `publishRetryAuthorityCandidate()`.

`materialize(...)` y `resume(...)` construyen `MaterializedReconciliation` y,
antes de retornarlo, ejecutan directamente:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

Ocurre en las líneas reales 125 y 212. La identidad disponible es el ID positivo
de la reconciliation confirmada. Para retry durable: stage `reconciliation`,
subject ID = completion ID = reconciliation ID. No hay schedule ID/generation.

A10 fija para A9 el método futuro exacto:

```php
private function publishRetryAuthorityCandidate(
    int $reconciliationId,
    string $scheduledForUtc
): void;
```

Debe sustituir ambas llamadas directas, ser invocado una vez por retorno no
nulo y publicar después de confirmar la reconciliation. Captura
`gmdate('Y-m-d H:i:s')` una vez. La firma productiva está cerrada aunque su
implementación pertenece a A9.

## 6. Hook síncrono inicial

Literal: `veciahorra_durable_retry_initial_reconciliation`. A9 hará:

```php
do_action($hook, $reconciliationId, $scheduledForUtc);
```

Orden: ID entero positivo, fecha string UTC canónica. Registro futuro:
prioridad `10`, accepted args `2`. El registrar A9 valida `mixed` de WordPress,
crea `DateTimeImmutable` y llama A8. A8 recibe tipos cerrados; no recibe stage,
completion, schedule ID, generation, action ID, hook o group. El action no
devuelve valor observable. A8 no registra, dispara ni agenda este hook.

## 7. Firma definitiva A8

Fijada por A10 salvo el puerto legacy bloqueado:

```php
// app/Modules/Orders/Contracts/DurableRetryInitialProductionRouterInterface.php
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

// app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php
final class DurableRetryInitialProductionRouter
    implements DurableRetryInitialProductionRouterInterface
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

Resultado:
`VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult`.
No usa nullable input, `mixed`, arrays, closure ni service locator.

## 8. Dependencias exactas

Son exactamente cuatro: producer A5, resolver A6, coordinator A7 y puerto
legacy. Se prohíben A2, A3, A4, configuration source, repositorios,
coordinador externo, adapter, callback, executor, registry y processors.

Las tres dependencias durable tienen firmas versionadas suficientes. La cuarta
solo tiene FQCN y posición de constructor en A10; no tiene método utilizable.

## 9. Entrada y request

Validación: ID `> 0`; fecha offset `0`, microsegundos `000000` y forma estable.
Construcción:

```php
$identity = DurableRetryAuthorityIdentity::reconciliation($reconciliationId);
$request = DurableRetryInitialTransferRequest::reconciliation(
    $identity,
    $reconciliationId,
    $scheduledForUtc
);
```

Stage fijo, subject = completion. Generation `1`, attempt `0` y reason
`retryable_failure` proceden del request. A8 no acepta id/generation/action ID
externos ni payload Action Scheduler.

## 10. Relación A8 → A5

| Estado A5 | Rama | Legacy/A6/A7 | Resultado A8 | Razón |
|---|---|---|---|---|
| `legacy_allowed` | legacy | `1/0/0` | legacy scheduled/unavailable | resultado puerto / `activation_policy_rejected` |
| `durable_existing` | durable | `0/1/≤1` | por A6/A7 | razón downstream |
| `durable_created` | durable | `0/1/≤1` | por A6/A7 | razón downstream |
| `durable_converged` | durable | `0/1/≤1` | por A6/A7 | razón downstream |
| `legacy_in_flight` | cierre | `0/0/0` | `authority_closed` | A5 |
| `functionally_ineligible` | cierre | `0/0/0` | `authority_closed` | A5 |
| `authority_indeterminate` | cierre | `0/0/0` | `authority_closed` | A5 |
| `durable_inconsistency` | cierre | `0/0/0` | `authority_closed` | A5 |
| `configuration_invalid` | cierre | `0/0/0` | `authority_closed` | A5 |
| `persistence_error` | cierre | `0/0/0` | `authority_closed` | A5 |
| `outcome_uncertain` | cierre | `0/0/0` | `authority_closed` | A5 |
| `operational_failure` | cierre | `0/0/0` | `authority_closed` | A5 |

Solo `legacy_allowed` habilita legacy. La tabla cubre los 12 estados reales.

## 11. Rama legacy

Productor real:
`VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionScheduler`,
ruta `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`.
Método actual:

```php
public function reconciliation(int $id): void;
```

Agenda `veciahorra_process_payment_reconciliation`, args
`['authority_id' => $id]`, group `veciahorra-completion`; deduplica con
`as_has_scheduled_action`; ausencia de `as_schedule_single_action`, ID inválido
o pending existente terminan silenciosamente. El retorno `void` no diferencia
éxito, already o unavailable y las excepciones del provider no se capturan.

No existe interfaz real de productor legacy. A10 nombra una interfaz nueva y la
ubica en la allowlist A8, pero no define método, retorno o semántica. Esta es la
ambigüedad bloqueante. A8 no puede saber si devuelve `legacy_scheduled` o
`legacy_unavailable`.

Corrección normativa mínima propuesta:

```php
interface DurableRetryLegacySchedulerInterface
{
    public function scheduleReconciliation(int $reconciliationId): bool;
}
```

Debe declarar `true` solo ante schedule nuevo o pending compatible confirmado;
`false` ante API ausente o fallo confirmado; debe capturar `Throwable`. A9 debe
adaptar `DurableCompletionScheduler` sin una séptima ruta A8. Otra forma exige
una corrección explícita y un DTO dentro de la allowlist.

## 12. Rama durable A5 → A6

Solo `durable_existing`, `durable_created` y `durable_converged`. A8 llama:

```php
$resolution = $resolver->resolve($request, $production);
```

Máximo una vez. `resolved_dispatching` y `resolved_scheduled` llaman A7.
`not_found`, `incompatible` y `read_error` cierran como `resolution_failed`,
preservan la razón A6 y ejecutan cero A7/legacy.

## 13. Rama durable A6 → A7

| A7 | Resultado global A8 |
|---|---|
| `synchronized` | `durable_synchronized` |
| `already_synchronized` | `durable_already_synchronized` |
| `external_unavailable` | `durable_external_unavailable` |
| `coordination_failed` | `durable_coordination_failed` |
| `coordination_uncertain` | `durable_coordination_uncertain` |

Reason, schedule ID, generation, action ID e intervención se copian sin
reinterpretación. Legacy siempre falso.

## 14. Rama de cierre

Entrada inválida: `invalid_input`, cero dependencias. Excepción de dependencia:
`dependency_failure`, cero llamadas posteriores. A5 no durable/no legacy:
`authority_closed`. A6 no continuable: `resolution_failed`. Ningún cierre llama
legacy, agenda, repite autoridad o crea fila.

## 15. Resultado global A8

Catálogo exacto A10:

| Estado / factory | Campos e invariantes |
|---|---|
| `legacy_scheduled` / `legacyScheduled()` | reconciliation positivo; legacy true; durable IDs null |
| `legacy_unavailable` / `legacyUnavailable()` | ID positivo; legacy false; durable IDs null |
| `durable_synchronized` / `durableSynchronized()` | schedule/gen/action positivos; legacy false |
| `durable_already_synchronized` / `durableAlreadySynchronized()` | IDs positivos; legacy false |
| `durable_external_unavailable` / `durableExternalUnavailable()` | schedule/gen positivos; action nullable; legacy false |
| `durable_coordination_failed` / `durableCoordinationFailed()` | durable identity; legacy false |
| `durable_coordination_uncertain` / `durableCoordinationUncertain()` | intervention true; legacy false |
| `authority_closed` / `authorityClosed()` | razón A5; durable IDs null; legacy false |
| `resolution_failed` / `resolutionFailed()` | razón A6; durable IDs conforme evidencia; legacy false |
| `invalid_input` / `invalidInput()` | ID normalizado no positivo; cero efectos |
| `dependency_failure` / `dependencyFailure()` | razón `dependency_failure`; intervención true |

Campos A10: `state`, `reason`, `reconciliationId`, `scheduleId?`,
`generation?`, `scheduledActionId?`, `legacyScheduled`,
`requiresIntervention`. Todo resultado es terminal para la invocación; recovery
solo en estados marcados por A5/A6/A7. No se propagan excepciones fuera del
callback futuro.

## 16. Regla de rama única

```text
validar id/fecha; si falla → invalid_input
construir identity/request una vez
try A5 una vez; excepción → dependency_failure
si legacy_allowed:
    try legacy una vez; true → legacy_scheduled; false/excepción → legacy_unavailable
si A5 no confirma durable → authority_closed
try A6 una vez; excepción → dependency_failure
si A6 no continúa → resolution_failed
try A7 una vez; excepción → dependency_failure
mapear estado A7 una vez y retornar
```

Cada retorno termina la máquina. No hay salto de durable a legacy ni de cierre
a productor.

## 17. Orden exacto de llamadas

La secuencia propuesta por la solicitud coincide con contratos reales:
validación → identity/request → A5 → selección → legacy, o A6 → A7 → resultado.
A6 recibe el mismo request y el resultado A5. A7 recibe exclusivamente el
resultado A6. No existe llamada posterior al productor de la rama elegida.

## 18. Snapshots y estabilidad

A5 consume el snapshot lógico contenido en request y las dependencias internas
A3/A2/A4. A8 conserva el mismo objeto para A6. No relee fecha/configuración ni
reconstruye request. Cambio de configuración durante la invocación no provoca
segundo A5. Cambio de fila entre A6/A7 queda bajo las relecturas, generation y
CAS del scheduler guard A7.

## 19. Configuración inválida

A5 retorna `configuration_invalid`; A8 retorna `authority_closed` con razón A5.
Excepción `0`, legacy/A6/A7 `0`, SQL/scheduling/retry A8 `0`. Nunca se transforma
en `legacy_allowed`.

## 20. Autoridad indeterminada

`authority_indeterminate` retorna `authority_closed`, conserva reason y marca
recovery/intervención según resultado global. Legacy/durable producers `0` y
side effects A8 `0`.

## 21. A6 `not_found`

`resolution_failed`, reason `initial_schedule_not_found`; legacy `0`, segunda
lectura `0`, INSERT `0`, A7 `0`, retry local `0`. Recovery posterior. A4/A5 no
se repiten.

## 22. A6 `incompatible`

`resolution_failed`, reason `initial_schedule_incompatible`; legacy/A7 `0`,
persistencia A8 `0`, excepción `0`, intervención/recovery. No se repara en A8.

## 23. A6 `read_error`

`resolution_failed`, reason `initial_schedule_read_error`; legacy/A7/retry/log
A8 `0`; retorno tipado, recovery posterior.

## 24. A7 `external_unavailable`

`durable_external_unavailable`, autoridad durable preservada, IDs copiados,
legacy/retry/scheduling adicional `0`; recovery posterior A9. A8 no recoordina.

## 25. A7 `coordination_failed`

`durable_coordination_failed`, reason exacta A7, legacy/retry `0`; conserva los
side effects ya cerrados por A7 y no inicia otros.

## 26. A7 `coordination_uncertain`

`durable_coordination_uncertain`, intervención true, razón exacta A7, segunda
A7/schedule/legacy `0`; recovery posterior.

## 27. Excepciones

| Origen | Resultado | Llamadas posteriores / legacy / retry / logs |
|---|---|---|
| validación/request | `invalid_input` | `0 / 0 / 0 / 0` |
| A5 | `dependency_failure` | `0 / 0 / 0 / 0` |
| legacy | `legacy_unavailable` | `0 / ya autorizada / 0 / 0` |
| A6 | `dependency_failure` | `0 / 0 / 0 / 0` |
| A7 | `dependency_failure` | `0 / 0 / 0 / 0` |
| resultado shape incompatible | `dependency_failure` | `0 / 0 / 0 / 0` |
| dependencia ausente | falla composición A9 antes del callback | A8 no se construye |

Captura `Throwable` por llamada. No se propaga fuera del callback futuro y no
se llama otra dependencia después del fallo.

## 28. Presupuesto operacional cerrado

| Operación máxima | Legacy | Durable | Cierre |
|---|---:|---:|---:|
| validación/request | `1/1` | `1/1` | `1/1` |
| A5 | 1 | 1 | 1 |
| legacy | 1 | 0 | 0 |
| A6 | 0 | 1 | 0 |
| A7 | 0 | 1 | 0 |
| coordinator externo | 0 | 1 indirecto | 0 |
| SELECT indirectos A8+A5–A7 | 1 A3 máximo | presupuesto A5 + A6 1 + A7 5 | presupuesto A5 |
| schedule | 1 legacy | 1 durable | 0 |
| asociación/cancelación | `0/0` | `1/1` | `0/0` |
| hooks/retries A8 | `0/0` | `0/0` | `0/0` |

A8 directo siempre: SQL, repository, adapter, AS functions, hooks, loops,
sleeps, logs y retry `0`.

## 29. Concurrencia

Dos A8 ejecutan A5 una vez cada uno. A3/A4 garantizan autoridad durable o
legacy bajo locks/identidad; A6 recupera gen1; A7 converge con unique/CAS. Una
rama legacy concurrente con durable es el riesgo que A3/A4 y las guardas A9
deben excluir. A8 no puede hacer idempotente al productor legacy; el puerto
corregido debe deduplicar y A9 debe instalar guardas antes de activar. Snapshot
obsoleto se cierra en A7. No se reevalúa autoridad.

## 30. Seguridad y confianza

Confiable: ID de reconciliation confirmada y fecha capturada por materializer
tras validación A9; request construido; resultados tipados A5–A7; identidad
persistida A6. Derivado: stage, completion, gen1/attempt0. Prohibido/no confiable:
schedule ID, generation, action ID, stage o subject desde hook/AS/usuario. A8
solo acepta ID y fecha.

## 31. Compatibilidad con A9

A9 necesita singleton
`DurableRetryInitialProductionRouterInterface`, método `routeReconciliation`,
registrar `onInitialReconciliation(mixed, mixed): void`, hook literal, prioridad
10 y dos args. El registrar valida y crea UTC antes de A8. A9 compone cuatro
dependencias y adapta/protege legacy. Guardia estática e idempotencia viven en
`Application`, nunca en A8.

## 32. Allowlist A8

A10 proporciona exactamente seis rutas nuevas:

| Ruta | Rol | Permitido / prohibido |
|---|---|---|
| `app/Modules/Orders/Contracts/DurableRetryLegacySchedulerInterface.php` | puerto | contrato legacy corregido / infraestructura |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialProductionRoutingResult.php` | resultado | 11 estados / catálogo abierto |
| `app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php` | servicio | rama única / SQL, hooks, direct infrastructure |
| `tests/manual/durable-retry-initial-production-router-test.php` | funcional | 24 casos / A9 |
| `tests/manual/durable-retry-initial-production-router-infrastructure-test.php` | infraestructura | guardias / inventario global rígido |
| `tests/manual/durable-retry-initial-production-router-integration-test.php` | integración | 10 escenarios / wiring A9 |

A10 §11 menciona una interfaz A8 de router, pero §26 no incluye su ruta. La
firma aparece como clase concreta, no como declaración `interface`. Por tanto,
la allowlist literal autoriza seis archivos pero no autoriza crear
`DurableRetryInitialProductionRouterInterface.php`. La corrección debe decidir:
clase concreta sin interfaz (coherente con §11) o sustituir una ruta. Este es un
segundo bloqueo documental.

## 33. Harness funcional A8

Exactamente 24 casos, 15 assertions cada uno, total **360**:

| ID | Entrada / doubles | Rama y resultado | Llamadas A5/L/A6/A7 |
|---|---|---|---|
| F01 | válida; legacy allowed; legacy true | legacy scheduled | `1/1/0/0` |
| F02 | válida; legacy allowed; legacy throw | legacy unavailable | `1/1/0/0` |
| F03 | durable created; dispatching; synchronized | durable synchronized | `1/0/1/1` |
| F04 | durable existing; scheduled; already | durable already | `1/0/1/1` |
| F05 | durable; A7 unavailable | durable external unavailable | `1/0/1/1` |
| F06 | durable; A7 failed | durable coordination failed | `1/0/1/1` |
| F07 | durable; A7 uncertain | durable coordination uncertain | `1/0/1/1` |
| F08 | durable; A6 not found | resolution failed | `1/0/1/0` |
| F09 | durable; A6 incompatible | resolution failed | `1/0/1/0` |
| F10 | durable; A6 read error | resolution failed | `1/0/1/0` |
| F11 | configuration invalid | authority closed | `1/0/0/0` |
| F12 | authority indeterminate | authority closed | `1/0/0/0` |
| F13 | A4 durable inconsistency | authority closed | `1/0/0/0` |
| F14 | functionally ineligible | authority closed | `1/0/0/0` |
| F15 | ID/fecha inválidos | invalid input | `0/0/0/0` |
| F16 | journal A5 | cierre | A5 exactamente 1 |
| F17 | legacy successful journal | legacy scheduled | legacy exactamente 1 |
| F18 | durable journal | por A6 | A6 exactamente 1 |
| F19 | durable journal | por A7 | A7 exactamente 1 |
| F20 | durable success | durable | legacy 0 |
| F21 | legacy success | legacy | A6/A7 0 |
| F22 | durable error | cierre durable | fallback 0 |
| F23 | A5 throws | dependency failure | `1/0/0/0` |
| F24 | journal total | rama única | máximos exactos |

Cada caso afirma state, reason, IDs, flags, llamadas, orden, terminalidad,
side effects y cero fallback.

## 34. Harness de infraestructura A8

Propone **90 assertions**: 6 rutas, 9 namespace/FQCN, 12 firma/constructor, 8
dependencias, 11 catálogo, 8 factories/invariantes, 8 llamada máxima, 18
ausencias (A2–A4, repo, coordinator externo, adapter, SQL, hooks, AS, callback,
executor, processors, loops, sleep, retry, legacy fallback), 6 rama única y 4
allowlist Git. Inspecciona solo seis archivos y baseline de callers; no usa
globs, inventarios globales ni conteos históricos.

## 35. Integraciones A8

Exactamente 10 escenarios, **20 assertions cada uno**, total **200**:

| ID | Fixture/componentes | Estado final |
|---|---|---|
| I01 | materializer fixture + firma A9 simulada | candidatura ID/UTC válida |
| I02 | A5 real/doubles internos + puerto legacy | solo legacy |
| I03 | A5/A6/A7 contractuales | durable synchronized |
| I04 | fila scheduled + A7 real | durable already |
| I05 | adapter unavailable | durable external unavailable |
| I06 | coordinator failure | durable failed |
| I07 | coordinator uncertainty | durable uncertain |
| I08 | repository absence/incompatibility | resolution failed, A7 0 |
| I09 | dos routers intercalados | una rama por invocación, convergencia |
| I10 | cohortes deterministas | legacy xor durable, nunca ambos |

Cada escenario usa fixtures frescos, journal, DB fake/real contractual,
Action Scheduler fake cuando se alcanza A7, conteos exactos, limpieza `finally`
y cero A9 productivo.

## 36. Assertions exactas

| Artefacto | Assertions |
|---|---:|
| funcional | 360 |
| infraestructura | 90 |
| I01–I10 | 20 cada una = 200 |
| total A8 | **650** |

Tres harnesses nuevos elevan la suite lógica de 65 a **68 harnesses** y de
5.162 a **5.812 assertions**.

## 37. Riesgos

| Riesgo | Cierre requerido |
|---|---|
| doble productor/A5 | retornos terminales y journal |
| fallback legacy/híbrido | única autorización literal |
| A6/A7 repetidos | una llamada estructural |
| legacy tras durable | ramas mutuamente excluyentes |
| excepción parcial | captura y cero dependencia posterior |
| snapshot obsoleto | guard A7 |
| puerto legacy ambiguo | corrección normativa previa |
| dependencia circular | A8 solo cuatro puertos |
| infraestructura directa | harness de ausencias |
| allowlist/interfaz router | corrección normativa previa |
| acoplamiento A9 | hook/bootstrap fuera de A8 |

## 38. Bloqueos documentales

**B1 — puerto legacy.** A10 §11 línea 319 y §14 línea 410 nombran
`DurableRetryLegacySchedulerInterface`; §26 línea 638 autoriza el archivo. No
hay método/retorno. El concreto real retorna `void`, pero A10 §12 líneas 358–359
exige dos resultados. Impacto: imposible mapear éxito/unavailable y diseñar
doubles exactos. Decisión: versionar firma y semántica, propuesta §11.

**B2 — interfaz del router.** A10 §11 da FQCN solo para la clase concreta y
constructor; la allowlist §26 contiene el puerto legacy en vez de una interfaz
router. La solicitud exige interfaz A8. Impacto: crearla sería séptimo archivo;
omitirla incumple el contenido solicitado. Decisión: declarar autoritativamente
que A8 no tiene interfaz o corregir la allowlist sustituyendo/añadiendo ruta.

No se resuelven silenciosamente. El punto productivo, hook, resultado global,
algoritmo durable y tests sí están cerrados.

## 39. Secuencia de implementación A8

1. Versionar corrección B1/B2.
2. Repetir base y auditoría; detener si diverge.
3. Crear interfaz autorizada por corrección.
4. Crear resultado de 11 estados.
5. Crear router de rama única.
6. Ejecutar funcional 24/360.
7. Ejecutar infraestructura 90.
8. Ejecutar integración 10/200.
9. Ejecutar suite esperada 68/5.812.
10. Stage solo allowlist corregida y commit separado.
11. Recertificar; no push.

Detener ante puerto legacy abierto, séptima ruta, contrato A5–A7 distinto,
fallback, segunda llamada, SQL/adapter/repo/hook, fallo, diagnostic, A9 o wiring.

## 40. Criterio de aceptación

A8 solo se completa después de corregir B1/B2 y demostrar: A5 una vez; rama
única; legacy solo ante `legacy_allowed`; A6/A7 solo durable y una vez; cero
fallback/SQL/scheduling/hook; 24 casos/360; 10 integraciones/200; suite
68/5.812 verde; commit solo allowlist corregida; cero A9/wiring/push.

**A8 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**
