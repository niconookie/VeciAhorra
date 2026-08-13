# VeciAhorra A11 EA6: identidad productiva de A11-CON-03 `create_01`

Estado: corrección normativa cerrada. Fecha: 2026-08-05.

## 1. Veredicto

`A11 EA6 OPERATION RESULT PROJECTION CATALOG IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA DE A11-CON-03`

Existe una cadena productiva única, observable y total para `create_01`. Su operación es `publish`; `create_01` es un participante de esa operación y no un entrypoint adicional.

## 2. Decisión arquitectónica cerrada

El catálogo de operaciones del child conserva exactamente:

`publish|callback|legacy|as_action|recovery|http`.

Quedan prohibidos `operation=create`, `executeCreate()`, un request específico de create, un dispatcher específico, un protocolo específico y un segundo return envelope. El participante conserva `participant_id`, `participant_index=2`, rol `create_01` e `invocation_id` propios dentro del descriptor, pero ejecuta y proyecta `operation=publish`.

## 3. Binding literal de la invocation

| Campo | Valor normativo |
|---|---|
| `invocation_id` | `a11_000000000005_fd` |
| `case_id` | `A11-CON-03` |
| `phase` | `first_delivery` |
| `entrypoint_id` | `execute_phase` |
| `operation` | `publish` |
| `participant_id` | `a11p_A11-CON-03_first_delivery_create_01_02` |
| `participant_index` | `2` |
| `participant_role` | `create_01` |
| `productive_source` | `durable_retry_initial_authority_producer.produce_reconciliation` |

Cada igualdad es ASCII case-sensitive. El child resuelve la fila por `invocation_id` y `participant_id`; no deriva ninguna identidad desde sufijos, posición de spawn o nombre de rol.

## 4. Cadena productiva única

La cadena verificable es:

```text
create_01
→ operation=publish
→ DurableRetryInitialProductionRouter::routeReconciliation(
      int $reconciliationId,
      DateTimeImmutable $scheduledForUtc
  )
→ DurableRetryInitialAuthorityProducerInterface::produceReconciliation(
      DurableRetryInitialTransferRequest $request
  )
→ rama: autoridad clasificada legacy, activation allowsInitialTransfer=true
→ DurableRetryInitialTransferAuthorityInterface::transferReconciliation($request)
→ DurableRetryInitialTransferAuthority::transferReconciliation($request)
→ DurableRetryInitialTransferRepository::transferReconciliation($request)
→ DurableRetryInitialTransferRepository::insert($initial)
→ INSERT INTO <prefix>veciahorra_durable_retry_schedules (...)
→ DurableRetryInitialTransferResult
→ DurableRetryInitialAuthorityProductionResult::fromTransfer(...)
→ DurableRetryInitialAuthorityProductionResult observable
→ proyección EA6 de §13
```

Propietario de la ejecución publish: `VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter`. Función productiva invocada: `routeReconciliation()`. Propietario de la decisión de creación: `VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer`, bajo `DurableRetryInitialAuthorityProducerInterface`; función: `produceReconciliation()`.

La llamada que ordena la creación es literalmente `$this->transfer->transferReconciliation($request)`. La llamada que materializa el write es `DurableRetryInitialTransferRepository::insert($initial)`, cuyo SQL es `INSERT INTO ... durable_retry_schedules`. Nombrar otra función `create()` no acredita esta rama.

## 5. Rama productiva representada

La condición inicial congelada de A11-CON-03 es una reconciliación funcional elegible, generación durable 1 ausente en fixture, dos participantes publish liberados por el mismo barrier y la misma business identity. Esa condición autoriza a `create_01` a intentar el publish inicial; no garantiza que siga ausente cuando adquiera los locks.

La rama representada es la decisión de establecimiento de autoridad durable inicial. Se alcanza cuando `produceReconciliation()` recibe el request de reconciliation. Incluye sus salidas anteriores a la transferencia, la transferencia seleccionada y su convergencia concurrente. La subrama física de creación se alcanza solo si la autoridad se clasifica legacy, la policy selecciona transferencia y el transfer repository llega a `PRE_WRITE`/`WRITE_ATTEMPTED`.

La carrera no asigna un ganador por rol. `create_01` puede retornar `durable_created`, `durable_converged` o `durable_existing` según el orden productivo real. El rol identifica qué participante produjo la evidencia; el DTO acredita qué ocurrió.

## 6. Business identity

La identidad de negocio cerrada es:

```json
{"stage":"reconciliation","subject_id":123,"completion_id":123,"generation":1}
```

`123` es ilustrativo. En runtime, `subject_id` procede de `request.authority.subjectId`, `completion_id` de `request.completionId` y debe igualar al subject; `generation` debe ser `DurableRetryInitialTransferRequest::INITIAL_GENERATION`, valor `1`. El binding exige además que `reconciliation_id` de `routeReconciliation()` iguale ambos IDs.

No integran business identity: `public_id`, dispatch token, database ID, PID, timestamp de carrera, action ID, participant suffix o índice.

## 7. Frontera de observación autorizada

La frontera única es un decorator A11 process-local sobre `DurableRetryInitialAuthorityProducerInterface::produceReconciliation()`. El router ya recibe esa interfaz; por ello no se altera la firma productiva ni se crea un puerto productivo alternativo.

El decorator debe:

1. validar el binding de §3 y el request de §6;
2. marcar un único comienzo de llamada;
3. delegar exactamente una vez al productor real;
4. conservar la misma instancia retornada de `DurableRetryInitialAuthorityProductionResult`;
5. registrar una observación immutable con request, `state()`, `reason()`, `authorityResult()` y `transferResult()`;
6. retornar la misma instancia mediante identidad `===`;
7. permitir una única lectura y limpiar en `finally`.

El store vive solo en el proceso child. Cardinalidad requerida: cero antes de delegar y una tras retorno. Se prohíben consultas globales posteriores, comparación de snapshots generales de DB, polling, selección por timing y reconstrucción desde el resultado final del router.

## 8. Suficiencia del seam

`DurableRetryInitialAuthorityProductionResult` distingue de forma directa:

| Pregunta | Evidencia autorizada |
|---|---|
| ¿Se alcanzó la decisión de creación? | existe observation sellada para la llamada a `produceReconciliation()` |
| ¿Se alcanzó la transferencia? | `transferResult() !== null` |
| ¿Se creó autoridad nueva? | `state=durable_created`, transfer `state=transferred`, reason `initial_transfer_created` |
| ¿Existía autoridad al clasificar? | `state=durable_existing`, `transferResult()=null` |
| ¿Otra ejecución creó una equivalente? | `state=durable_converged`, transfer `state=already_transferred` |
| ¿Fue rechazada o no aplicable? | `legacy_allowed`, `legacy_in_flight`, `functionally_ineligible` o `configuration_invalid` |
| ¿Quedó indeterminada? | `authority_indeterminate`, `durable_inconsistency`, `persistence_error`, `outcome_uncertain` u `operational_failure` |

El router posterior combina autoridad y scheduling y puede colapsar distinciones. Su DTO sirve para la proyección de `publish_01`, pero no reemplaza este seam para `create_01`.

## 9. Separación observable de participantes

| Campo | `publish_01` | `create_01` |
|---|---|---|
| `participant_id` | `a11p_A11-CON-03_first_delivery_publish_01_01` | `a11p_A11-CON-03_first_delivery_create_01_02` |
| `participant_index` | `1` | `2` |
| `entrypoint_id` | `execute_phase` | `execute_phase` |
| `operation` | `publish` | `publish` |
| condición de entrada | request publish válido y binding del participant 1 | mismo business key, gen1 ausente en fixture y binding del participant 2 |
| rama representada | routing publish completo, incluida coordinación externa | decisión de autoridad inicial y transferencia/convergencia |
| seam | resultado de `routeReconciliation()` | resultado de `produceReconciliation()` |
| resultado observable | `DurableRetryInitialProductionRoutingResult` | `DurableRetryInitialAuthorityProductionResult` más transfer result opcional |
| business identity | reconciliation + subject + generation | reconciliation + subject + completion + generation |
| estados | catálogo del routing DTO | doce estados de §12 |
| efectos | routing/scheduling acreditado por su DTO | autoridad creada o equivalente acreditada por el production DTO |
| incertidumbre | routing uncertain/dependency failure | cinco clases indeterminadas de §13 |
| excepción | seam de router preserva Throwable | política de §14 |

La desigualdad normativa es `(participant_id,participant_index,productive_source,represented_branch,observation_type)`, no el texto `publish_01|create_01`. Intercambiar observations, DTO types o productive sources produce `a11_con_03_participant_projection_binding_mismatch`.

## 10. Envelope lógico EA6

La proyección de catálogo posee estas claves exactas:

```json
{"participant_id":"a11p_A11-CON-03_first_delivery_create_01_02","operation":"publish","result":{"state":"applied","reason":"initial_transfer_created","business_identity":{"stage":"reconciliation","subject_id":123,"completion_id":123,"generation":1}},"effects":[{"type":"durable_authority_created","stage":"reconciliation","subject_id":123,"generation":1}],"uncertainty":{"present":false,"reason":null},"exception":null}
```

Enums cerrados de `result.state`: `applied|already_applied|rejected|not_applicable|indeterminate`. `effects` es una lista ordenada de cero o un objeto. `uncertainty` tiene siempre `present:bool` y `reason:string|null`. `exception` es `null` para un retorno productivo; §14 define su único shape no nulo.

Esta es la proyección semántica EA6 consumida por el adapter de phase. No cambia el shape de transporte ya autorizado ni crea otro return envelope del child.

## 11. Catálogo fuente exhaustivo

Estados y reasons alcanzables del production result:

| Production state | Reasons productivos permitidos |
|---|---|
| `legacy_allowed` | `activation_policy_rejected` |
| `legacy_in_flight` | `legacy_claim_in_flight` |
| `durable_existing` | `durable_authority_already_exists` |
| `durable_created` | `initial_transfer_created` |
| `durable_converged` | `equivalent_transfer_exists` |
| `functionally_ineligible` | `functional_record_absent`, `functional_state_ineligible` |
| `authority_indeterminate` | `query_failed`, `incompatible_durable_state`, `persisted_duplicate`, `corrupt_identity`, `incomplete_result`, `unresolved_race`, `consistency_error` |
| `durable_inconsistency` | `existing_transfer_incompatible`, `duplicate_durable_identity` |
| `configuration_invalid` | `invalid_activation_configuration_value`, `invalid_percentage`, `unsupported_algorithm_version`, `invalid_configuration_snapshot` |
| `persistence_error` | `persistence_write_failed` |
| `outcome_uncertain` | `persistence_outcome_uncertain` |
| `operational_failure` | `dependency_failure`, `activation_configuration_source_unavailable` |

Cualquier pareja state/reason fuera de esta tabla es `a11_con_03_unknown_productive_result` y bloquea la invocation.

## 12. Acreditación de effects

Solo se proyectan estos efectos:

| Production state | `effects` |
|---|---|
| `durable_created` | un `durable_authority_created` con business identity |
| `durable_converged` | un `durable_authority_equivalent_confirmed` con business identity |
| `durable_existing` | un `durable_authority_preexisting_confirmed` con business identity |
| restantes nueve estados | `[]` |

`durable_created` acredita que esta llamada obtuvo `transferred`; `durable_converged` acredita una fila equivalente observada tras competir; `durable_existing` acredita autoridad durable detectada antes de transferir. Ninguna de esas afirmaciones se deriva de un diff posterior.

## 13. Tabla total de proyección EA6

`BI` significa el objeto exacto de §6; `created(BI)`, `equivalent(BI)` y `preexisting(BI)` son los efectos de §12.

| Production state/reason | `result.state` | `result.reason` | `result.business_identity` | `effects` | `uncertainty` | `exception` |
|---|---|---|---|---|---|---|
| `legacy_allowed/activation_policy_rejected` | `not_applicable` | `activation_policy_rejected` | `BI` | `[]` | `{present:false,reason:null}` | `null` |
| `legacy_in_flight/legacy_claim_in_flight` | `rejected` | `legacy_claim_in_flight` | `BI` | `[]` | `{present:false,reason:null}` | `null` |
| `durable_existing/durable_authority_already_exists` | `already_applied` | `durable_authority_already_exists` | `BI` | `[preexisting(BI)]` | `{present:false,reason:null}` | `null` |
| `durable_created/initial_transfer_created` | `applied` | `initial_transfer_created` | `BI` | `[created(BI)]` | `{present:false,reason:null}` | `null` |
| `durable_converged/equivalent_transfer_exists` | `already_applied` | `equivalent_transfer_exists` | `BI` | `[equivalent(BI)]` | `{present:false,reason:null}` | `null` |
| `functionally_ineligible/functional_record_absent` | `not_applicable` | `functional_record_absent` | `BI` | `[]` | `{present:false,reason:null}` | `null` |
| `functionally_ineligible/functional_state_ineligible` | `rejected` | `functional_state_ineligible` | `BI` | `[]` | `{present:false,reason:null}` | `null` |
| `authority_indeterminate/<reason de su catálogo>` | `indeterminate` | reason productivo idéntico | `BI` | `[]` | `{present:true,reason:<reason>}` | `null` |
| `durable_inconsistency/existing_transfer_incompatible` | `indeterminate` | `existing_transfer_incompatible` | `BI` | `[]` | `{present:true,reason:"existing_transfer_incompatible"}` | `null` |
| `durable_inconsistency/duplicate_durable_identity` | `indeterminate` | `duplicate_durable_identity` | `BI` | `[]` | `{present:true,reason:"duplicate_durable_identity"}` | `null` |
| `configuration_invalid/<reason de su catálogo>` | `rejected` | reason productivo idéntico | `BI` | `[]` | `{present:false,reason:null}` | `null` |
| `persistence_error/persistence_write_failed` | `indeterminate` | `persistence_write_failed` | `BI` | `[]` | `{present:true,reason:"persistence_write_failed"}` | `null` |
| `outcome_uncertain/persistence_outcome_uncertain` | `indeterminate` | `persistence_outcome_uncertain` | `BI` | `[]` | `{present:true,reason:"persistence_outcome_uncertain"}` | `null` |
| `operational_failure/dependency_failure` | `indeterminate` | `dependency_failure` | `BI` | `[]` | `{present:true,reason:"dependency_failure"}` | `null` |
| `operational_failure/activation_configuration_source_unavailable` | `indeterminate` | `activation_configuration_source_unavailable` | `BI` | `[]` | `{present:true,reason:"activation_configuration_source_unavailable"}` | `null` |

Las filas con `<reason de su catálogo>` se expanden cartesianamente solo sobre la lista de su state en §11. Así se cubren las 24 parejas válidas sin aceptar strings abiertas.

## 14. Política de excepciones

La implementación concreta normaliza las excepciones alcanzables:

| Origen | Tratamiento productivo observable |
|---|---|
| `authority->classify()` lanza `Throwable` | `operational_failure/dependency_failure` |
| configuration source inválida | `configuration_invalid/invalid_activation_configuration_value` |
| configuration source no disponible | `operational_failure/activation_configuration_source_unavailable` |
| policy lanza sus códigos válidos | `configuration_invalid` o `operational_failure/dependency_failure` según §11 |
| transfer o `fromTransfer()` lanza `Throwable` | `operational_failure/dependency_failure` |
| repository falla antes de write/commit | `persistence_error/persistence_write_failed` |
| commit/write no puede reconciliarse | `outcome_uncertain/persistence_outcome_uncertain` |

Por ello un productor concreto conforme retorna un DTO y `exception=null`. Si una implementación inyectada viola el puerto y deja escapar un `Throwable`, el decorator registra fuera de `operation_result`:

```json
{"class":"<FQCN exacto>","reason":"productive_exception_before_observation"}
```

Luego relanza la misma instancia; no fabrica resultado, effects ni certeza. Si el DTO ya fue observado y la proyección A11 falla, se conserva la observation sellada para diagnóstico, la invocation falla con `a11_con_03_projection_failed_after_observation` y tampoco se altera el resultado productivo. Excepciones del supervisor no se atribuyen al producto.

## 15. Codec y validación

El codec de la observation exige FQCN exacto `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult`, state/reason de §11 y coherencia estructural:

- states derivados de transferencia requieren `authorityResult.isLegacyAuthorized=true` y `transferResult!==null`;
- `durable_existing` requiere autoridad durable y transfer nulo;
- `authority_indeterminate` requiere autoridad indeterminada y transfer nulo;
- `legacy_allowed` y `configuration_invalid` requieren autoridad legacy y transfer nulo;
- `operational_failure` requiere transfer nulo;
- el request debe satisfacer stage reconciliation, IDs iguales, generation 1, attempt 0 y UTC sin microsegundos.

El codec no llama servicios, no consulta DB y no corrige datos. Una incoherencia produce `a11_con_03_invalid_productive_observation`.

## 16. Lifecycle, cardinalidad y concurrencia

Cada participante posee producer decorator, store y binding separados. Estados del store: `empty→open→invoking→recorded→sealed→consumed→cleared`; failure transita a `failed→cleared`. No hay store compartido entre processes.

El barrier libera ambos participantes solo tras verificar dos arrivals. La observation de `create_01` se asocia al participant 2 aun cuando el participant 1 cree la fila primero. Orden de retorno, PID y winner no cambian bindings. El assertion posterior admite exactamente una autoridad active compatible y usa ambos operation results, no reasigna roles.

## 17. Casos adversariales obligatorios

| Caso | Resultado requerido |
|---|---|
| descriptor `create_01` pide `operation=create` | rechazo antes de spawn |
| descriptor pide entrypoint distinto de `execute_phase` | rechazo de binding |
| observation de router usada para `create_01` | `a11_con_03_participant_projection_binding_mismatch` |
| observation de producer del participant 1 usada por participant 2 | mismo rechazo |
| dos llamadas al producer en un participante | cardinality failure |
| DTO sin transfer reporta `durable_created` | invalid productive observation |
| transfer `already_transferred` proyectado como `applied` | projection mismatch |
| `durable_existing` proyectado como creación propia | projection mismatch |
| DB cambia sin observation sellada | evidencia insuficiente |
| reason no listado | unknown productive result |
| outcome uncertain con `uncertainty.present=false` | projection mismatch |
| excepción convertida en success | protocol failure |
| store conserva binding después de cleanup | contamination failure |

## 18. Compatibilidad y alcance

Esta corrección autoriza exclusivamente identidad, observation y proyección de `A11-CON-03 create_01`. No implementa el catálogo EA6, no altera producto, no autoriza nuevos entrypoints y no modifica autoridades de expected actions, transport, action capture, fixture o assertions.

La seam previa del initial production router sigue vigente para participantes cuyo objeto observable sea routing. Para este rol concreto, la presente autoridad especializa el punto de observación al producer sin contradecirla: ambas capas observan objetos productivos distintos y no duplican effects.

## 19. Criterio de cierre

La cadena exigida queda determinada:

`create_01 → operation=publish → entrypoint_id=execute_phase → DurableRetryInitialProductionRouter::routeReconciliation() → DurableRetryInitialAuthorityProducerInterface::produceReconciliation() → rama legacy seleccionada o sus salidas previas/concurrentes → DurableRetryInitialTransferAuthorityInterface::transferReconciliation() → DurableRetryInitialTransferRepository::insert() → DurableRetryInitialAuthorityProductionResult → tabla total §13`.

Con esta autoridad, A11-CON-03 es proyectable. El catálogo exhaustivo 62/62 puede continuar a implementación cuando las restantes filas posean su propia cadena cerrada; esta corrección no certifica por sí sola esas 61 filas.
