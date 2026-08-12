# Corrección normativa del modelo concurrente de transferencia A5

## 1. Alcance, precedencia y cierre

Esta autoridad append-only cierra el modelo transaccional/concurrente de los dos publishers de `A11-CON-01 / first_delivery / a11_000000000001_fd`. No genera la matriz de 300 pares ni clasifica compatibilidad A8.

Consume sin modificar:

```text
CON01_INITIAL_PROFILE_ID=a11_con_01_initial_reconciliation_v1
CON01_INITIAL_PROFILE_SHA=0721dd811c98b6238500eeb6459414e4e4b8f15f6bc3c44cb65e208b62973766
A5_PROVENANCE_SHA=cf325f63db5a7559fa9969d2266f08361585148a439621eb19edbda1f1ea0989
A8_FAILURE_PROVENANCE_SHA=0c643c6aaf8cb7f1dfde27baa44148d8be1cc6491aafe749cfeeaa7e18b64bf0
```

El perfil inicial comienza exactamente con fila funcional `pending`, intento `0`, lease nulo, claim ausente y cero filas durable. A8 y structured evidence transport v2 quedan intactos.

## 2. Política transaccional seleccionada

```text
CURRENT_EXPLICIT_A5_ISOLATION=absent
A5_TRANSFER_TRANSACTION_ISOLATION=READ COMMITTED
ISOLATION_OWNER=DurableRetryInitialTransferRepository
ISOLATION_SET_POINT=inmediatamente antes de START TRANSACTION y fuera de toda transacción activa, después de construir y validar deterministicSnapshot
EXACT_ENFORCEMENT=wpdb::query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') exactamente una vez, seguido por wpdb::query('START TRANSACTION') sobre la misma conexión
ISOLATION_FAILURE_BEHAVIOR=si el SET retorna false o lanza, no ejecutar START TRANSACTION y retornar DurableRetryInitialTransferResult::persistenceError()
```

La selección afecta solo la transacción siguiente de esa conexión. Queda prohibido sustituirla por un ajuste de sesión permanente o continuar tras su fallo.

Para neutralizar lock timeout en esta frontera se requiere además, en la misma conexión y antes del `SET TRANSACTION`, `SET SESSION innodb_lock_wait_timeout = 60`. Un fallo de ese statement retorna el mismo `persistenceError()` sin iniciar transacción. El valor 60 supera el timeout A11 de participante de 10 segundos; una ejecución que alcance el timeout del supervisor es inválida para CON-01 y no aporta un outcome productivo.

## 3. Comparativa material de isolation

| Candidato | Existing-row `FOR UPDATE` | Absent-row | Durable insert concurrente | Decisión |
|---|---|---|---|---|
| `READ COMMITTED` | record lock exclusivo; el segundo espera y luego lee el commit | no se exige gap lock para búsqueda exacta ausente | la fila funcional existente serializa antes de llegar al insert; unique identity queda como backstop | seleccionado: mínima restricción suficiente |
| `REPEATABLE READ` | misma serialización de la fila existente | next-key/gap locking puede bloquear inserts sobre rangos ausentes | añade contención que la fila funcional ya resuelve | rechazado por sobre-restricción |
| `SERIALIZABLE` | no fortalece el `FOR UPDATE` explícito relevante | locking más amplio, incluidas reads ordinarias | añade superficie de bloqueo ajena a la invariante | rechazado por sobre-restricción |

La propiedad decisiva no es un gap lock durable: toda transferencia de la misma reconciliation debe obtener primero el lock exclusivo de `payment_reconciliations.id`. Mientras el primer publisher lo conserva, el segundo no puede ejecutar su lectura durable.

## 4. Tablas, keys, índices y constraints

```text
FUNCTIONAL_RECORD_TABLE={$wpdb->prefix}{Config::TABLE_PREFIX}payment_reconciliations
FUNCTIONAL_RECORD_KEY=PRIMARY(id)
FUNCTIONAL_LOOKUP=WHERE id = %d LIMIT 1 FOR UPDATE
FUNCTIONAL_LOOKUP_INDEX=PRIMARY(id)
DURABLE_TABLE={$wpdb->prefix}{Config::TABLE_PREFIX}durable_retry_schedules
DURABLE_BUSINESS_KEY=(stage,subject_id,generation)
DURABLE_LOOKUP_INDEX=durable_retry_identity_unique(stage,subject_id,generation)
```

Constraints durable vigentes:

```text
UNIQUE public_id AS durable_retry_public_unique
UNIQUE (stage,subject_id,generation) AS durable_retry_identity_unique
UNIQUE (stage,subject_id,active_slot) AS durable_retry_active_unique
UNIQUE scheduled_action_id AS durable_retry_action_unique
```

El lookup exacto usa las tres columnas, con `stage='reconciliation'`, `subject_id=/fixture_ids/payment_reconciliations/0` y `generation=1`. Su cardinalidad válida es cero o uno; dos filas solo son representables ante schema corrupto o constraint ausente.

## 5. Flujo productivo completo

```text
TRANSACTION_STEPS=15
```

| Step | Tabla | Operación | Lock | Precondición | Failure/cierre |
|---|---|---|---|---|---|
| T01 | ninguna | `deterministicSnapshot(request)` | no | request válido | `persistenceError` si lanza |
| T02 | conexión | fijar `innodb_lock_wait_timeout=60` | no | fuera de tx | `persistenceError`, no tx |
| T03 | conexión | `SET TRANSACTION ... READ COMMITTED` | no | fuera de tx | `persistenceError`, no tx |
| T04 | conexión | `START TRANSACTION` | inicia tx | T02–T03 confirmados | `persistenceError` |
| T05 | functional | `functionalForUpdate(subjectId)` | record X si existe | tx abierta | rollback; persistence/uncertain según cierre |
| T06 | memoria | absent/claim/eligibility | conserva lock | T05 válida | outcome definitivo + rollback o continúa |
| T07 | durable | `durableForUpdate(request)` | record X si existe; ausencia sin gap requerido | functional X retenido | rollback ante error/inconsistencia |
| T08 | memoria | cardinalidad y `classifyExisting` | conserva locks | T07 válida | existing compatible va a commit; incompatible rollback |
| T09 | memoria | `initialSnapshot(expected)` | conserva locks | durable ausente | exception path |
| T10 | durable | `INSERT` generation 1 | row/unique locks | T09 válida | reconciliación de write failure |
| T11 | durable | reread `durableForUpdate` | X sobre fila propia | insert intentado | rollback o continúa |
| T12 | memoria | validar identidad/persistencia exacta | conserva locks | T11 cardinalidad uno | inconsistency + rollback si difiere |
| T13 | conexión | `COMMIT` | libera todos al confirmarse | ruta existing o inserted | si falla, `COMMIT_UNCERTAIN` |
| T14 | conexión | `ROLLBACK` | revierte writes y libera locks | cualquier cierre pre-commit | resultado original si confirma; uncertain si falla |
| T15 | durable | `durableWithoutLock` en conexión independiente | non-locking read | commit caller-uncertain | transferred/existing/incompatible/uncertain |

Un `Throwable` antes de commit ejecuta rollback y produce persistence error; si rollback no se confirma produce outcome uncertain. Un `Throwable` desde commit attempted usa reconciliación independiente. `reconcileAfterWriteFailure()` relee bajo la misma tx: compatible confirma transferred/already-transferred por commit; incompatible hace rollback; segunda read fallida intenta rollback.

## 6. Locking read funcional

```text
FUNCTIONAL_EXISTING_ROW_LOCK_RULE=SELECT por PRIMARY(id) FOR UPDATE adquiere record lock X; solo un publisher lo posee; el otro espera sin ejecutar su durable lookup; tras commit o rollback del owner, la locking read esperando se reevalúa bajo READ COMMITTED y devuelve la versión committed entonces existente
FUNCTIONAL_ABSENT_ROW_LOCK_RULE=una búsqueda PRIMARY(id)=X ausente retorna null y no constituye autoridad de gap para impedir un insert posterior; bajo CON-01 no existe actor autorizado para borrar o insertar esa fila, por lo que esta rama solo describe semántica productiva general y no una transición del caso
```

Commit del primer publisher no altera la fila funcional, por lo que el segundo observa los siete valores iniciales. Rollback tampoco los altera. Propias escrituras funcionales no existen en este path.

## 7. Locking de identidad durable

```text
DURABLE_IDENTITY_LOCK_RULE=el publisher que posee functional X ejecuta el lookup exacto por durable_retry_identity_unique; inicialmente obtiene cero filas, inserta generation 1 y conserva los locks hasta cierre; el segundo publisher no alcanza este lookup hasta liberarse functional X y entonces READ COMMITTED reevalúa y ve la fila committed, o ve cero si el primero hizo rollback
```

La unique identity y el unique active slot son backstops frente a un writer fuera de contrato. Entre los dos publishers conformes no hay dos inserts concurrentes: el lock funcional anterior los serializa. Si una ruta perdiera esa precondición, duplicate-key sería write failure y seguiría `reconcileAfterWriteFailure()`; esa pérdida no es una ejecución autorizada de CON-01.

## 8. Regla global de visibilidad

```text
SAME_RECONCILIATION_VISIBILITY_RULE=cada locking read observa su propia escritura y la última versión committed al momento efectivo de adquirir el lock; una read que espera se reevalúa después de la liberación; nunca observa una fila durable no committed; commit publica atómicamente generation 1 y libera locks; rollback elimina el insert no committed y libera locks; caller uncertainty no altera la visibilidad DB real
```

Tras commit de P1, P2 ve generation 1. Tras rollback de P1, P2 ve identidad durable ausente y puede insertarla. Si el caller de P1 no sabe si commit ocurrió, DB está en uno de esos dos estados concretos; la conexión independiente y P2 solo pueden observar el estado committed efectivo.

## 9. Lifecycle funcional productivo y restricción CON-01

Operaciones productivas reales capaces de mutar la fila:

```text
FUNCTIONAL_MUTATION_OPERATIONS=6
```

| # | Actor/componente | Método | Mutación | Frontera |
|---:|---|---|---|---|
| 1 | materializer / `PaymentReconciliationRepository` | `create` | crea `pending`, attempt 0, lease nulo, version 0 | create repository |
| 2 | reconciliation worker / claim repository | `acquireLease` | status processing, owner/timestamps, version+1, attempt+1 | UPDATE atómico |
| 3 | lease owner / claim repository | `renewLease` | extiende expires_at | UPDATE CAS |
| 4 | lease owner / claim repository | `releaseLease` | limpia owner y timestamps, conserva processing/version | UPDATE CAS |
| 5 | processor / claim repository | `compareAndSetStatus` | processing a terminal/retryable; limpia lease | UPDATE CAS |
| 6 | claim repository | `markAttemptsExhausted` | manual_review y lease nulo | UPDATE condicional |

No existe delete productivo de `payment_reconciliations`; los deletes encontrados pertenecen a cleanup de tests. Durante CON-01 ninguno de los seis mutators es invocado por `publish_01` o `publish_02`.

State machine funcional limitada al caso:

```text
F0=(present,pending,attempt=0,lease=null,version=0)
F0 -- publish A5 locking read/no functional write --> F0
F0 -- publish commit|rollback --> F0
```

No hay transición del caso a processing, claim expired, released, terminal, retryable o ausencia.

## 10. Claim legacy

Estados productivos relevantes:

```text
no_claim=status pending|retryable con owner/acquired/expires null
claim_active=status processing, owner worker_[a-f0-9]{32}, acquired non-null, expires > DB UTC, version>0
claim_expired=status processing, owner non-null, expires <= DB UTC, version>0
released=status processing, owner/acquired/expires null, version>0
closed=status completed|permanent_failure|manual_review, lease null
```

CON-01 permanece en `no_claim` con pending/version 0. Publishers no adquieren, renuevan, liberan ni cierran claims. Reconciliation worker y legacy callback están prohibidos durante la ventana; un publisher no puede observar un claim creado por el otro.

## 11. Mundo cerrado, actores y ventana

```text
CON01_THIRD_PARTY_MUTATION=forbidden
FUNCTIONAL_RECORD_DELETE_DURING_CON01=forbidden
CONCURRENT_WINDOW_START=Coordinator emite el release colectivo del grupo a11g_A11-CON-01_first_delivery_01 después del freeze point validado
CONCURRENT_WINDOW_END=Coordinator acepta y valida exactamente los dos phase_result completed correspondientes a publish_01 y publish_02, antes de assertions y cleanup
```

Third party significa todo actor distinto de esos dos participants, aun cuando use una API productiva.

| Actor | Durante la ventana |
|---|---|
| durable worker | forbidden |
| legacy scheduler callback | forbidden |
| reconciliation worker | forbidden |
| cron | forbidden para la reconciliation y chain del caso |
| admin | forbidden para esos recursos |
| unrelated HTTP request | forbidden para esos recursos |
| cleanup | forbidden hasta window end |
| fault injector | forbidden |
| other test participant | forbidden |

La topología posee exactamente dos members y cero dependencies externas para este grupo. El Coordinator preserva ownership y no libera callbacks/workers/cleanup sobre sus recursos durante la ventana.

## 12. Mutaciones autorizadas de publishers

```text
PUBLISHER_MUTATION_SET=[durable generation-1 INSERT by initial transfer winner, external scheduler action creation by routing winner, scheduled_action_id association CAS on that same durable row]
```

Ambos publishers tienen la misma capacidad; constraints/CAS determinan cuál materializa cada efecto. Ninguno modifica o elimina la fila funcional, crea claim, crea otra generation o ejecuta worker. La creación/association de action ocurre después de que el A5 transfer owner confirmó commit; por ser operación del mismo participant no es third-party mutation.

La association autorizada transforma la fila durable committed de `dispatching,scheduled_action_id=null,version=1` a la variante productiva asociada con `scheduled_action_id` positivo y version posterior. Si ocurre antes del durable lookup A5 del otro publisher que ya había clasificado legacy fuera de la tx, ese segundo A5 observa la variante committed y aplica su clasificación exacta existente; esta intercalación queda en el grafo, sin inferencia desde tiempo/PID.

## 13. Fault model

```text
CON01_ALLOWED_FAULTS=[]
CON01_PROCESS_CRASH=forbidden
CON01_COMMIT_UNCERTAINTY_INJECTION=forbidden
```

| Evento artificial | CON-01 |
|---|---|
| activation exception | forbidden |
| transfer unexpected Throwable | forbidden |
| persistence fault | forbidden |
| commit uncertainty injection | forbidden |
| scheduler fault | forbidden |
| external deletion | forbidden |

Una family A5 puede ser globalmente productiva y ser imposible dentro del fixture fault-free CON-01. Error espontáneo de infraestructura, timeout supervisor, conexión perdida o commit caller-uncertain invalida la ejecución CON-01; sigue siendo normalizable por producción conforme a A5 provenance, pero no acredita reachability del caso.

## 14. Deadlock, timeout y knobs

```text
A5_SAME_RECONCILIATION_DEADLOCK_MODEL=impossible_by_lock_order
A5_LOCK_WAIT_TIMEOUT_MODEL=neutralized_by_60_second_session_value_and_10_second_A11_participant_timeout; supervisor timeout invalidates the run before DB lock timeout can become an A5 outcome
```

Todos adquieren locks en orden `functional PRIMARY(id)` y después `durable identity`. Solo el owner del functional lock puede pedir el durable lock. La association posterior solo pide la fila durable y nunca vuelve a pedir functional; puede esperar tras P2, pero no forma ciclo. No existe selección normativa de deadlock victim porque una ejecución conforme no crea ciclo.

| Knob | Regla |
|---|---|
| `transaction_isolation` | fijado por tx a READ COMMITTED |
| `innodb_lock_wait_timeout` | fijado a 60 en la conexión; timeout A11=10 invalida antes |
| `autocommit` | neutralizado por START TRANSACTION y COMMIT/ROLLBACK explícitos |
| deadlock handling/retry | irrelevante por orden acíclico; repository no reintenta |

## 15. State machine de una invocation

| State | Locks | Visibilidad | Siguiente |
|---|---|---|---|
| `before_transaction` | ninguno | committed state | `timeout_fixed` |
| `timeout_fixed` | ninguno | igual | `isolation_selected` |
| `isolation_selected` | ninguno | próxima tx=RC | `transaction_started` |
| `transaction_started` | ninguno | snapshot por statement | `functional_lock_acquired` o rollback |
| `functional_lock_acquired` | functional X | F0 committed | `durable_lock_acquired` o rollback |
| `durable_lock_acquired` | functional X + durable X si existe | D0 o D1 committed | commit, `mutation_attempted` o rollback |
| `mutation_attempted` | functional X + inserted durable/unique locks | propia D1 visible | commit o rollback |
| `commit_attempted` | locks retenidos hasta cierre DB | propia state | committed o commit_reconciled |
| `committed` | ninguno | effects públicos | terminal A5 |
| `rolled_back` | ninguno | pre-tx committed state | terminal A5 |
| `commit_reconciled` | conexión original no autoritativa; read externa | committed DB state | terminal A5 |

## 16. Composición de dos publishers

1. P1 y P2 pueden ejecutar validación, legacy classification y activation antes de la tx en paralelo.
2. El primero que obtiene functional X se vuelve transfer owner provisional; el otro espera en T05.
3. El owner provisional ejecuta T07–T13 sin competencia del otro transfer sobre la identity.
4. Commit desbloquea T05 del waiter, cuya locking read se reevalúa; rollback hace lo mismo sin publicar D1.
5. Tras commit, el primer publisher puede avanzar a resolver/schedule/associate mientras el segundo ya ejecuta A5; durable row locks/CAS serializan esa interacción sin ciclo.
6. Un insert de generation 1 solo procede desde D0; unique identity y active slot rechazan cualquier writer fuera del orden.
7. Ninguna decisión usa arrival order, PID, timestamps del harness o wall clock.

## 17. Grafo normativo compartido

Estados:

```text
S0=F0 + D0 + A0
S1(P)=S0 + functional_X_owner=P
S2(P)=S1 + durable_absence_observed
S3(P)=S2 + D1_uncommitted_by_P
S4=F0 + D1_committed(dispatching,action=null,version=1) + A0
S5=F0 + D1_committed(action associated) + A1
```

Edges:

| ID | Actor | Pre | Operación | Commit | Post | Consecuencia visible |
|---|---|---|---|---|---|---|
| G01 | P1 o P2 | S0 | lock functional | no | S1(P) | otro espera |
| G02 | owner P | S1 | durable lookup zero | no | S2(P) | solo P observa D0 |
| G03 | owner P | S2 | insert generation 1 | no | S3(P) | solo P ve D1 |
| G04 | owner P | S3 | commit | sí | S4 | waiter se reevalúa y puede ver D1 |
| G05 | owner P | S1/S2/S3 | rollback | no | S0 | waiter ve F0+D0 |
| G06 | routing owner P | S4 | create external action | externo confirmado | S4+A1-unassociated | action aún no cambia snapshot durable |
| G07 | routing owner P | S4+A1-unassociated | association CAS | commit del repository | S5 | siguientes durable reads ven action asociada |
| G08 | waiter Q | S4 | functional lock + durable read | no | S4 con locks Q | Q clasifica D1 committed |
| G09 | waiter Q | S5 | functional lock + durable read | no | S5 con locks Q | Q clasifica variante asociada committed |
| G10 | waiter Q | S4/S5 con locks Q | commit/rollback read-only | cierre | mismo shared state | libera association si esperaba |

No existen edges a functional absent, claim active, delete, otra generation, crash o injected failure.

## 18. Pair históricamente bloqueado

```text
PAIR=legacy_in_flight/legacy_claim_in_flight + functionally_ineligible/functional_record_absent
REACHABILITY_CONSEQUENCE=impossible
PROOF_RULE=G01-G10 preserve F0 present and no edge creates a claim or deletes the functional row; T05 serializes reads of the same PRIMARY(id)
```

El estado inicial contiene fila y no claim; publisher mutations no cambian esa fila; terceros y delete están prohibidos. Por tanto ninguna ejecución autorizada produce uno de esos estados, menos aún ambos.

## 19. Sanity e implementation delta

```text
SINGLE_PUBLISHER_SANITY_PRESERVED=PASS
IMPLEMENTATION_DELTA_REQUIRED=yes
FILE=app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php
CLASS=DurableRetryInitialTransferRepository
METHOD=transferReconciliation(...)
INSERTION_POINT=después de deterministicSnapshot(request) exitoso e inmediatamente antes del START TRANSACTION existente
REQUIRED_ISOLATION_BEHAVIOR=ejecutar SET SESSION innodb_lock_wait_timeout = 60; ejecutar SET TRANSACTION ISOLATION LEVEL READ COMMITTED; ante cualquier fallo retornar persistenceError sin iniciar tx; después ejecutar el START TRANSACTION existente en la misma wpdb
```

No se requiere cambiar query, schema o índice. Un publisher aislado obtiene F0, observa D0, inserta D1 y confirma `durable_created / initial_transfer_created`.

## 20. Compatibilidad

| Componente | Impacto |
|---|---|
| `DurableRetryInitialTransferRepository` | implementation adjustment required |
| `DurableRetryInitialTransferAuthorityInterface` | unchanged |
| `DurableRetryInitialAuthorityProducer` | unchanged |
| `DurableRetryInitialAuthorityProductionResult` | unchanged |
| A6 | unchanged |
| A7 | unchanged |
| A8 | unchanged |
| A9 | unchanged |
| A10 | unchanged |
| A11 initial-state profile | unchanged |
| A11 transport v2 | unchanged |
| Coordinator | transaction constraint only: fixture run validity |
| participant-action-proposal | unchanged |

## 21. Validaciones normativas

```text
CON01_INITIAL_PROFILE_SHA_MATCH=PASS
A5_TRANSFER_TRANSACTION_ISOLATION_CLOSED=PASS
ISOLATION_ENFORCEMENT_CLOSED=PASS
FUNCTIONAL_EXISTING_ROW_LOCK_CLOSED=PASS
FUNCTIONAL_ABSENT_ROW_LOCK_CLOSED=PASS
DURABLE_IDENTITY_LOCK_CLOSED=PASS
SAME_RECONCILIATION_VISIBILITY_CLOSED=PASS
CON01_THIRD_PARTY_MODEL_CLOSED=PASS
CON01_FUNCTIONAL_MUTATION_MODEL_CLOSED=PASS
CON01_FUNCTIONAL_DELETE_MODEL_CLOSED=PASS
CON01_LEGACY_CLAIM_MODEL_CLOSED=PASS
CON01_FAULT_MODEL_CLOSED=PASS
DEADLOCK_MODEL_CLOSED=PASS
LOCK_TIMEOUT_MODEL_CLOSED=PASS
CONCURRENT_WINDOW_CLOSED=PASS
TRANSACTION_STATE_MACHINE_CLOSED=PASS
SHARED_STATE_TRANSITION_GRAPH_CLOSED=PASS
SINGLE_PUBLISHER_SANITY_PRESERVED=PASS
LEGACY_CLAIM_PLUS_ABSENT_MODEL_CLOSED=PASS
CON01_WORLD_MODEL_CLOSED=PASS
UNRESOLVED=0
```

**A5 TRANSFER CONCURRENCY MODEL IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
