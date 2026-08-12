# Corrección normativa A11-WR-06: activación y ruta durable

## 1. Propósito

Este documento cierra exclusivamente la activación, disponibilidad, ruta durable y `expected.rows` de `A11-WR-06`. La primera devolución Webpay aprobada crea exactamente una autoridad durable inicial de reconciliación; el replay reutiliza esa autoridad sin crear otra fila, otra generación ni una segunda programación incompatible.

## 2. Alcance

Quedan cerrados el snapshot A2/A2.1, la cohorte, la clasificación A3, la transferencia A4, la producción A5, la resolución A6, la coordinación A7, el resultado A8, la exclusión del worker y las cardinalidades de seis tablas VeciAhorra.

No se auditan `expected.actions`, `expected.result`, `expected.mutations` ni `fixture_ids`. Las referencias `@allocated.*` son simbólicas y no asignan IDs.

## 3. Antecedentes

`rows` es un array asociativo PHP y un objeto JSON. Sus fases exactas son `first_delivery` y `replay`; ambas son snapshots absolutos finales observados después de commit y recarga, con el mismo key set y orden, valores enteros no negativos y nombres lógicos sin prefijo WordPress. No contiene deltas, IDs, filas, estados ni mutaciones.

Las cardinalidades de `checkouts`, `payment_sessions`, `payment_origin_contexts`, `webpay_returns` y `payment_reconciliations` ya estaban fijadas en `1/1`. Esta corrección determina `durable_retry_schedules=1/1`.

## 4. Bloqueo previo

El bloqueo era `case=A11-WR-06`, `category=expected`, `field=expected.rows.durable_retry_schedules`: la publicación de un candidato no fijaba por sí sola una ruta entre legacy, durable, unavailable y authority closed. La autoridad faltante era el contrato específico de activación, disponibilidad y procesamiento de WR-06. Este documento la aporta sin generalizarla a otros casos o entornos.

## 5. Autoridades inspeccionadas

- A2/A2.1: `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource` en `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionActivationConfigurationSource.php`, método `snapshot(): DurableRetryActivationConfiguration`; `DurableRetryDeterministicActivationPolicy::allowsInitialTransfer(DurableRetryAuthorityIdentity $identity): bool`; `DurableRetryActivationCohort::bucket(DurableRetryAuthorityIdentity $identity): int`.
- A3: `DurableRetryLegacyAuthorityRepository::classify(DurableRetryAuthorityIdentity $identity): DurableRetryLegacyAuthorityResult`.
- A4: `DurableRetryInitialTransferAuthority::transferReconciliation(DurableRetryInitialTransferRequest $request): DurableRetryInitialTransferResult` y `DurableRetryInitialTransferRepository::transferReconciliation(...)`.
- A5: `DurableRetryInitialAuthorityProducer::produceReconciliation(DurableRetryInitialTransferRequest $request): DurableRetryInitialAuthorityProductionResult`.
- A6: `DurableRetryInitialScheduleResolver::resolve(DurableRetryInitialTransferRequest $request, DurableRetryInitialAuthorityProductionResult $authority): DurableRetryInitialScheduleResolutionResult`.
- A7: `DurableRetryInitialScheduleCoordinator::coordinate(DurableRetryInitialScheduleResolutionResult $resolution): DurableRetryInitialSchedulingResult` y `DurableRetryExternalScheduleCoordinator::coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult`.
- A8: `DurableRetryInitialProductionRouter::routeReconciliation(int $reconciliationId, DateTimeImmutable $scheduledForUtc): DurableRetryInitialProductionRoutingResult`.
- Scheduler: `ActionSchedulerDurableRetryAdapter::schedule(string $hook, array $arguments, string $group, string $scheduledForUtc): DurableRetryExternalScheduleResult` y `findPending(string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult`.

## 6. Snapshot de activación

WR-06 fija `stage=reconciliation` y porcentaje entero `100`. La opción física autoritativa es `veciahorra_durable_retry_activation_reconciliation_percentage`, leída por `WordPressOptionDurableRetryActivationConfigurationValueReader`. La fuente acepta representación entera válida dentro de `0..100`, no aplica fallback y produce un snapshot inmutable para cada llamada a `snapshot()`.

Representación normativa: PHP `100`, JSON `100`, manifest decimal `100`. Configuración ausente, ilegible, fuera de rango o de stage incompatible no satisface WR-06.

## 7. Cohorte

El algoritmo es `sha256-24bit-mod100-v1`. Su entrada canónica es `veciahorra|durable-retry|initial-transfer|cohort|v1|stage=reconciliation|subject_id=<id>`; usa SHA-256 binario, interpreta los primeros tres bytes como entero big-endian y aplica módulo 100.

La policy normalmente permite cuando `bucket < percentage`. Sin embargo, `isFullyEnabled()` retorna antes del cálculo comparativo: porcentaje `100` produce `true` para toda identidad válida de reconciliación. Por ello ninguna identidad WR-06 cae a legacy por cohorte.

## 8. Autoridad inicial

Antes de la transferencia no existe fila durable generation 1 ni evidencia incompatible, y la lectura A3 es determinada. `DurableRetryLegacyAuthorityRepository` busca por `stage` y `subject_id`; la ausencia de generation 1 clasifica exactamente `legacy`.

`legacy` describe la autoridad observada inicialmente. No es un marcador persistido y no ordena terminar usando el scheduler legacy.

## 9. Transferencia

Con snapshot válido, porcentaje 100 y A3=`legacy`, A5 invoca A4 una sola vez. A4 intenta como máximo un INSERT inicial y crea generation 1 con resultado `transferred`, reason `initial_transfer_created`; A5 expone `durable_created`. Una colisión compatible converge a `already_transferred`/`durable_converged`; una identidad duplicada incompatible produce `durable_inconsistency`, no fallback.

La fila creada parte con `status=dispatching`, `active_slot=1`, `version=1`, `scheduled_action_id=null`, generation `1`, attempt `0` y `reason_code=retryable_failure`. A6 la resuelve como `resolved_dispatching` con reason `initial_dispatch_required`.

## 10. Disponibilidad

WR-06 fija contractualmente `durable_retry_scheduler_available=true`. Significa: Action Scheduler está cargado; su API es accesible; el adaptador se construye; `schedule()` no lanza excepción; la operación es aceptada o encuentra la misma operación pendiente; y retorna un ID externo positivo.

No significa que la acción se ejecute, que el worker procese, que la fila sea claimed o consumed.

## 11. Resultado del router

En la primera entrega A5=`durable_created`, A6=`resolved_dispatching`, A7=`synchronized` y la coordinación externa=`synchronized_new`; A8 retorna literalmente `durable_synchronized`.

El scheduler legacy no es invocado. No hay `durable_external_unavailable`, `authority_closed`, `dependency_failure` ni degradación legacy. A7 asocia como máximo un action ID a la autoridad generation 1.

## 12. Identidad durable

La identidad normativa es:

```text
stage=reconciliation
subject_id=@allocated.payment_reconciliation_id
completion_id=@allocated.payment_reconciliation_id
generation=1
attempt_number=0
status=scheduled
active_slot=1
reason_code=retryable_failure
version=2
```

`subject_id` y `completion_id` reciben el mismo ID positivo de la fila `payment_reconciliations`. El ID numérico queda reservado a `fixture_ids`. `version=2` es el observable posterior a la asociación: A4 crea versión 1 y `associateScheduledAction()` hace una única transición CAS a versión 2.

## 13. Primera entrega

Tras retorno Webpay, reconciliación, publicación A11, transferencia, programación, asociación, commit y recarga existe exactamente una fila owned por WR-06. Su estado es `scheduled`, conserva generation 1, attempt 0, active slot 1 y tiene un `scheduled_action_id` positivo. La cardinalidad final es `durable_retry_schedules=1`.

## 14. Replay

El replay reconstruye y verifica la evidencia financiera, vuelve a publicar el candidato y A3 encuentra la autoridad generation 1 existente: A5 retorna `durable_existing`. A6 retorna `resolved_scheduled`; A7 verifica con `findPending()` el mismo action ID y retorna `already_synchronized`; A8 retorna literalmente `durable_already_synchronized`.

No se ejecuta INSERT de transferencia, no se crea otra fila ni generation 2, no aparece un segundo active slot y no se vuelve a legacy. El status observable sigue siendo `scheduled`, versión 2, y `durable_retry_schedules=1`.

## 15. Worker excluido

`durable_retry_execute_worker=false` es vinculante dentro del alcance temporal WR-06. La acción se programa pero no se ejecuta; no se invoca callback manual, claim, processor, consume, backoff ni retry adicional. Por tanto no hay generation 2. La eventual ejecución posterior de Action Scheduler fuera del caso no contradice este contrato.

## 16. Scheduler externo

La identidad externa usa el hook productivo derivado por `DurableRetryExternalScheduleCatalog::hookForStage(reconciliation)`, argumentos `schedule_id` y `generation=1`, y el grupo productivo del catálogo. La primera coordinación acepta `SCHEDULED` o una operación ya existente compatible; la asociación persistente fija el action ID.

Las tablas internas de Action Scheduler no pertenecen al key set de `expected.rows`: son infraestructura externa, su esquema puede variar y sus operaciones corresponden a la futura auditoría `expected.actions`.

## 17. Cardinalidades

El key set y orden definitivos son:

```text
checkouts, payment_sessions, payment_origin_contexts,
webpay_returns, payment_reconciliations, durable_retry_schedules
```

Cada clave vale `1` en `first_delivery` y `replay`. No se añaden tablas de Action Scheduler.

## 18. Ownership

El predicado lógico exacto para el conteo durable es:

```text
stage = 'reconciliation'
AND subject_id = @allocated.payment_reconciliation_id
AND completion_id = @allocated.payment_reconciliation_id
AND generation = 1
```

`active_slot=1`, `status=scheduled`, attempt 0 y version 2 son comprobaciones de compatibilidad del snapshot, no sustitutos de ownership. Se prohíben `COUNT(*)` global, fecha actual, action ID como único dueño, status mutable como identidad única y discovery de filas parecidas.

## 19. Unicidad e idempotencia

La identidad durable `(stage, subject_id, generation)` y el active slot impiden dos autoridades iniciales compatibles activas. La asociación actualiza la misma fila; no es una segunda fila. Un duplicate key compatible converge a la fila canónica; uno incompatible cierra con inconsistencia. Sin worker no hay transición claimed/consumed, backoff ni mecanismo que cree generation 2.

Una segunda acción externa compatible encontrada por la identidad externa se reutiliza; una acción incompatible no es aceptada. `expected.rows` cuenta filas, pero su `1/1` depende de estas garantías.

## 20. Configuración mutable

Después de persistida generation 1, A3 clasifica `durable`; A5 retorna `durable_existing` antes de autorizar una transferencia nueva. Por eso el replay no reevalúa la cohorte para regresar a legacy. Cambios posteriores de porcentaje, opción WordPress, commerce code, ambiente, gateway o disponibilidad para transferencias nuevas no reemplazan la autoridad ya persistida.

Para verificar la programación existente A7 sí requiere que el scheduler contractual del caso continúe disponible; si deja de estarlo, el replay ya no satisface WR-06, aunque la fila durable siga prevaleciendo.

## 21. Manifest contractual

Entradas `fixture`:

```text
durable_retry_activation_stage=reconciliation
durable_retry_activation_percentage=100
durable_retry_scheduler_available=true
durable_retry_execute_worker=false
```

Entradas `expected`:

```text
durable_retry_expected_generation=1
durable_retry_expected_row_count_first_delivery=1
durable_retry_expected_row_count_replay=1
```

No hay aliases, IDs ni entradas derived adicionales.

## 22. Representación PHP

```php
'rows' => [
    'first_delivery' => [
        'checkouts' => 1,
        'payment_sessions' => 1,
        'payment_origin_contexts' => 1,
        'webpay_returns' => 1,
        'payment_reconciliations' => 1,
        'durable_retry_schedules' => 1,
    ],
    'replay' => [
        'checkouts' => 1,
        'payment_sessions' => 1,
        'payment_origin_contexts' => 1,
        'webpay_returns' => 1,
        'payment_reconciliations' => 1,
        'durable_retry_schedules' => 1,
    ],
],
```

## 23. Representación JSON

```json
{
  "rows": {
    "first_delivery": {
      "checkouts": 1,
      "payment_sessions": 1,
      "payment_origin_contexts": 1,
      "webpay_returns": 1,
      "payment_reconciliations": 1,
      "durable_retry_schedules": 1
    },
    "replay": {
      "checkouts": 1,
      "payment_sessions": 1,
      "payment_origin_contexts": 1,
      "webpay_returns": 1,
      "payment_reconciliations": 1,
      "durable_retry_schedules": 1
    }
  }
}
```

## 24. Matriz adversarial

Convenciones: `FD/R` son conteos first_delivery/replay; `—` significa que el escenario no alcanza un snapshot aceptable. Los reason codes se indican solo cuando son literales productivos comprobados.

| # | input | initial_authority | activation | scheduler | route | FD/R | accepted | reason |
|---:|---|---|---|---|---|---|:---:|---|
| 1 | porcentaje 100 válido | legacy | full enabled | disponible | durable_synchronized | 1/1 | sí | contrato WR-06 |
| 2 | porcentaje 0 | legacy | disabled | disponible | legacy | 0/0 | no | no es el fixture |
| 3 | porcentaje inválido | legacy | inválida | disponible | dependency_failure | — | no | snapshot inválido |
| 4 | configuración ausente | legacy | no disponible | disponible | dependency_failure | — | no | sin fallback |
| 5 | stage distinto | legacy | incompatible | disponible | dependency_failure | — | no | stage no soportado |
| 6 | autoridad inicial legacy | legacy | 100 | disponible | durable_synchronized | 1/1 | sí | transferencia requerida |
| 7 | gen1 existente compatible | durable | no reevaluada | disponible | durable_already_synchronized | 1/1 | sí | durable_existing |
| 8 | gen1 existente incompatible | indeterminate | 100 | disponible | authority_closed | — | no | estado incompatible |
| 9 | lectura A3 indeterminada | indeterminate | 100 | disponible | authority_closed | — | no | lectura no determinada |
| 10 | scheduler disponible | legacy | 100 | disponible | durable_synchronized | 1/1 | sí | ID externo positivo |
| 11 | scheduler no disponible | legacy | 100 | no disponible | durable_external_unavailable | — | no | contrato incumplido |
| 12 | scheduler lanza excepción | legacy | 100 | excepción | durable_coordination_uncertain | — | no | sin ocultar fallo |
| 13 | asociación satisfactoria | legacy | 100 | disponible | durable_synchronized | 1/1 | sí | scheduled/version 2 |
| 14 | asociación incierta | legacy | 100 | disponible | durable_coordination_uncertain | — | no | intervención requerida |
| 15 | fallback legacy | legacy | 100 | disponible | legacy | — | no | fallback prohibido |
| 16 | resultado unavailable | legacy | 100 | no disponible | durable_external_unavailable | — | no | ruta incorrecta |
| 17 | authority closed | indeterminate | 100 | disponible | authority_closed | — | no | no hay autoridad confirmada |
| 18 | dependency failure | legacy | 100 | excepción | dependency_failure | — | no | dependencia fallida |
| 19 | primera entrega correcta | legacy | 100 | disponible | durable_synchronized | 1/1 | sí | durable_created |
| 20 | replay inmediato | durable | persistida | disponible | durable_already_synchronized | 1/1 | sí | misma fila/acción |
| 21 | segundo replay | durable | persistida | disponible | durable_already_synchronized | 1/1 | sí | idempotente |
| 22 | porcentaje cambia antes de replay | durable | mutable | disponible | durable_already_synchronized | 1/1 | sí | autoridad prevalece |
| 23 | scheduler cambia antes de replay | durable | persistida | no disponible | durable_external_unavailable | 1/1 | no | verificación no disponible |
| 24 | una fila durable final | durable | 100 | disponible | durable | 1/1 | sí | cardinalidad exacta |
| 25 | cero filas durable | legacy | 100 | disponible | incompleta | 0/0 | no | transferencia ausente |
| 26 | dos filas durable | indeterminate | 100 | disponible | cerrada | 2/2 | no | duplicate durable identity |
| 27 | generation 2 creada | durable | 100 | disponible | worker ejecutado | >1/>1 | no | worker prohibido |
| 28 | worker ejecutado | durable | 100 | disponible | procesamiento | — | no | fuera de alcance |
| 29 | schedule claimed | durable | 100 | disponible | claimed | 1/1 | no | worker prohibido |
| 30 | schedule consumed | durable | 100 | disponible | consumed | 1/1 | no | worker prohibido |
| 31 | backoff creado | durable | 100 | disponible | retry | >1/>1 | no | generation 2 prohibida |
| 32 | misma fila actualizada | durable | 100 | disponible | durable_synchronized | 1/1 | sí | CAS versión 1→2 |
| 33 | segundo INSERT compatible | durable | 100 | disponible | convergente | 1/1 | sí | already_transferred |
| 34 | segundo INSERT incompatible | indeterminate | 100 | disponible | cerrada | — | no | durable_inconsistency |
| 35 | segunda acción externa | durable | 100 | incompatible | inconsistente | 1/1 | no | acción incompatible |
| 36 | tabla Action Scheduler en rows | durable | 100 | disponible | durable | — | no | infraestructura externa |
| 37 | COUNT global sin ownership | durable | 100 | disponible | durable | — | no | predicado inválido |
| 38 | status como única identidad | durable | 100 | disponible | durable | — | no | status mutable |
| 39 | replay vuelve a cohorte | durable | reevaluada | disponible | legacy posible | — | no | durable prevalece |
| 40 | manifest parcial | legacy | indeterminada | indeterminada | indeterminada | — | no | contrato incompleto |

## 25. Bloqueo siguiente

Estado posterior:

```text
expected.rows: cerrado
expected.actions: siguiente unidad normativa
expected.result: pendiente
expected.mutations: pendiente
fixture_ids: no auditado
```

No se audita `expected.actions` aquí. Los otros 30 casos permanecen sin cambios.

## 26. Veredictos

```text
CONTRATO ACTIVACIÓN DURABLE WR-06 CERRADO
CONTRATO RUTA DURABLE INICIAL WR-06 CERRADO
CONTRATO REPLAY DURABLE WR-06 CERRADO
CONTRATO EXPECTED ROWS WR-06 CERRADO
A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍA EXPECTED INDETERMINADA
A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA
```

No se declara cerrado `expected` completo ni A11.

## 27. Integridad

Esta corrección es documental. No autoriza implementación, fixtures ejecutables, validadores, auxiliares, harnesses, cambios de producto o pruebas. Debe coexistir con los cuatro cambios tracked locales y los documentos antecedentes sin modificarlos. No requiere staging, commit ni push.
