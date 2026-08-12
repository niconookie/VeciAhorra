# Diseño de activación productiva del pipeline durable retry

## 1. Estado y propósito

Este documento define cómo activar en producción el pipeline `durable retry` sin
doble procesamiento con el pipeline histórico. Es una especificación de diseño:
no activa funcionalidad, no cambia esquema y no autoriza despliegue.

La base auditada es `main` en
`eb0e0e6a5340f618f8bf8de294be27b637af1873`, con esquema `0.24.0`
(`app/Core/Config.php:22`). El wiring durable ya separa construcción, registro,
activación y ejecución (`docs/durable-retry-production-wiring-design.md:382-401`);
la activación productiva, la coexistencia y la recuperación quedaron
explícitamente pendientes (`docs/durable-retry-production-wiring-design.md:470-475`).

Este diseño conserva estos contratos certificados:

- Action Scheduler recibe exactamente dos argumentos posicionales:
  `schedule_id` y `generation`.
- Se mantienen los cuatro hooks del catálogo, su grupo canónico, prioridad `10`
  y `accepted_args = 2`
  (`app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleCatalog.php:13-38`,
  `app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionHookRegistrar.php:11-39`).
- El catálogo es la única autoridad para hook, grupo y forma del payload.
- `DurableRetryExecutor` es el único coordinador de la ejecución.
- Los procesadores deciden resultados; nunca programan acciones.
- Sólo el executor puede persistir y coordinar una generación sucesora.
- Action Scheduler continúa aislado detrás de su adapter.

## 2. Decisión: primer estadio

Se activa primero `reconciliation`.

| Estadio | Nacimiento actual del trabajo | Dependencia legacy para producirlo | Riesgo de doble ejecución | Facilidad de rollback | Decisión |
|---|---|---:|---:|---:|---|
| `reconciliation` | `WebpayReconciliationMaterializer::materialize()` | Ninguna: el materializador conoce el ID persistido | Alto impacto financiero, pero frontera y llave inequívocas | Alta: se detienen transferencias nuevas y se drenan las existentes | **Primero** |
| `business_completion` | Worker de reconciliación | Sí | Requiere resolver antes la autoridad de reconciliación | Media | Posterior |
| `delivery_completion` | Worker de business completion | Sí, dos estadios aguas arriba | Mayor superficie de coexistencia | Media-baja | Posterior |
| `fulfillment_completion` | Worker de delivery/business completion | Sí | La fuente sigue dentro del pipeline legacy | Media-baja | Posterior |

El materializador tiene un punto productivo concreto y dos ramas que hoy llaman
directamente al scheduler histórico
(`app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:29`,
`:125`, `:212`). Para los otros estadios, el nacimiento del trabajo está
acoplado a workers legacy. Empezar por ellos dejaría al pipeline nuevo
dependiendo del pipeline que se quiere excluir.

El impacto financiero de reconciliación exige una exclusión más fuerte, no
posponer la decisión: bloqueo transaccional sobre la fila funcional, identidad
durable única y guardias legacy antes de cualquier claim.

La autoridad funcional ya está aislada por contratos de lectura y lease, y el
processor durable de reconciliación valida contexto, lease y resultado
(`app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php:21-169`).
Los processors de business, delivery y fulfillment también están cubiertos por
clasificación contractual
(`app/Modules/Orders/Services/DurableRetryBusinessCompletionProcessor.php:17-286`,
`app/Modules/Orders/Services/DurableRetryDeliveryCompletionProcessor.php:17-296`,
`app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php:17-288`), pero sus productores siguen
aguas abajo del worker legacy. Por ello reconciliación ofrece la mejor cobertura
existente junto con la frontera de exclusión más pequeña y recuperable.

## 3. Unidad de autoridad y marcador persistente

La unidad de autoridad es:

```text
(stage = reconciliation, subject_id = reconciliation_id)
```

La existencia de la fila durable de generación `1` para esa unidad constituye
el **marcador permanente de transferencia**. Cuenta como transferencia aunque la
fila esté `dispatching`, `scheduled`, `claimed`, terminal o inconsistente.

### Alternativas evaluadas

| Alternativa | Atomicidad/bloqueo | Schema y consultas | Rollback/históricos | Riesgo de falso positivo o doble autoridad | Veredicto |
|---|---|---|---|---|---|
| Cualquier fila durable | Atómica si se crea bajo el lock funcional | Sin schema; lookup por identidad | Distingue históricos por ausencia | Una fila de otra generación no prueba por sí sola el origen de la transferencia | Rechazada sin fijar generación |
| Sólo fila durable activa | Atómica, pero pierde señal al terminalizar | Sin schema; consulta por `active_slot` | Rollback aparentemente simple, pero inseguro | Falso negativo terminal y resurrección legacy | Rechazada |
| Marca en autoridad funcional | Puede escribirse con el claim funcional | Nueva columna, migración y lectura en todas las rutas | Rollback explícito, histórico nullable | Baja ambigüedad, pero duplica la verdad y exige sincronización | Rechazada para el primer stage |
| Registro separado de migración | Puede ser transaccional | Nueva tabla, repository y joins | Excelente auditoría | Más estados parciales y dos persistencias que reconciliar | Rechazada por complejidad no justificada |
| Identidad funcional + generación durable 1 | Misma transacción y lock funcional; índice único | Sin schema; lookup exacto indexado | Ausencia = histórico legacy; presencia = durable permanente | Falla cerrada ante corrupción; no hay falso negativo terminal | **Aprobada** |

No se puede usar “hay una fila durable activa” como marcador: cuando la fila
terminal perdiera `active_slot`, el recovery legacy podría resucitar el mismo
trabajo. Tampoco se puede borrar la generación 1. Una fila durable corrupta
mantiene la exclusión legacy y exige remediación durable; nunca habilita un
fallback implícito.

El esquema existente basta para el primer estadio:

- la identidad `(stage, subject_id, generation)` es única
  (`app/Database/Schemas/DurableRetryScheduleSchema.php:41`);
- sólo puede existir una fila activa por `(stage, subject_id, active_slot)`
  (`app/Database/Schemas/DurableRetryScheduleSchema.php:45`);
- `scheduled_action_id` es único
  (`app/Database/Schemas/DurableRetryScheduleSchema.php:48`).

Por tanto, **no se requiere bump de esquema** para la primera activación. Esta
conclusión depende de conservar toda la historia durable. Una política futura de
purga requerirá antes un marcador separado y su correspondiente migración.

## 4. Productor inicial y punto exacto

Se propone `DurableRetryReconciliationInitialScheduleProducer`, invocado por
`WebpayReconciliationMaterializer::materialize()` en las dos ramas que hoy
llaman a `DurableCompletionScheduler::reconciliation()`.

El orden obligatorio es:

1. El materializador confirma y persiste la reconciliación funcional.
2. Finaliza cualquier transacción que haya originado esa materialización.
3. Invoca al productor con el `reconciliation_id`.
4. El productor consulta la activación de `reconciliation`.
5. Si está desactivada, llama al scheduler legacy y termina como
   `LEGACY_SELECTED`.
6. Si está activada, solicita la transferencia atómica.
7. Sólo después del commit de transferencia coordina la fila mediante
   `DurableRetryExternalScheduleCoordinator::coordinate()`
   (`app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php:29`).

El materializador no construye payloads, hooks, tokens ni filas. Tampoco llama a
Action Scheduler. La sustitución es de un único punto de decisión por rama:
legacy o durable, nunca ambos.

## 5. Snapshot inicial completo

La transferencia crea exactamente estos campos, en el orden contractual de
`DurableRetryScheduleRepository::create()`:

| Campo | Valor inicial / autoridad |
|---|---|
| `public_id` | Hexadecimal aleatorio criptográficamente seguro, generado por una factoría durable |
| `stage` | `DurableRetryStage::RECONCILIATION` |
| `subject_id` | `reconciliation_id` positivo |
| `completion_id` | El mismo `reconciliation_id`; reconciliación exige igualdad (`DurableRetryScheduleSnapshot.php:134-138`) |
| `generation` | `1` |
| `attempt_number` | `0`; aún no hubo claim durable |
| `scheduled_for` | Instante UTC capturado una sola vez por el reloj inyectado |
| `scheduled_action_id` | `null` |
| `dispatch_token_hash` | SHA-256 hexadecimal de un token aleatorio no persistido en claro |
| `status` | `dispatching` |
| `active_slot` | `1` |
| `version` | `1` |
| `reason_code` | `retryable_failure`, única causa actualmente válida para `dispatching` (`DurableRetryReason.php:11-27`) |
| `dispatched_at` | `null` |
| `claimed_at` | `null` |
| `consumed_at` | `null` |
| `terminal_at` | `null` |
| `created_at` | El mismo instante UTC de la transferencia |
| `updated_at` | El mismo instante UTC de la transferencia |

El uso de `retryable_failure` es una compatibilidad con el catálogo actual, no
una afirmación de que ya hubo un intento. Si se desea distinguir “primera
entrega”, debe ampliarse el dominio y certificarse antes; no se debe escribir un
valor desconocido.

El snapshot satisface `validateInitial()`: `dispatching`, generación mínima 1,
intento mínimo 0, versión mínima 1 y `active_slot = 1`
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php:153-168`).

## 6. Transferencia atómica de autoridad

La operación `transferReconciliation()` usa una transacción de base de datos y
este orden de locks, común a productor, workers y recovery:

```text
1. SELECT reconciliation ... FOR UPDATE
2. comprobar elegibilidad funcional
3. buscar marcador durable generation = 1
4. crear snapshot inicial si no existe
5. COMMIT
6. coordinar scheduling externo
```

Elegibilidad inicial significa: fila existente, estado procesable por el worker
legacy, sin terminal funcional, sin lease/claim vigente y sin intento ya
consumido. Las condiciones concretas se deben reutilizar desde la autoridad
funcional; no se duplican como literales divergentes.

Resultados cerrados de transferencia:

- `TRANSFERRED`: se creó generación 1; durable es autoridad.
- `ALREADY_TRANSFERRED`: existe una generación 1 compatible; durable sigue
  siendo autoridad y el productor puede reconvergir la coordinación.
- `LEGACY_IN_FLIGHT`: el claim legacy ganó el lock; no se creó fila durable.
- `FUNCTIONALLY_INELIGIBLE`: terminal, ausente o no procesable; no se programa.
- `DURABLE_INCONSISTENCY`: existe marcador incompatible/corrupto; se excluye
  legacy y se alerta.
- `PERSISTENCE_ERROR`: outcome de commit conocido como fallido; no se programa.
- `OUTCOME_UNCERTAIN`: no se puede demostrar commit o rollback; no se llama al
  legacy y se relee/remedia por identidad.

La unicidad es la última defensa frente a dos productores. Un duplicate key se
resuelve releyendo la identidad y sólo converge como `ALREADY_TRANSFERRED` si
todos los campos inmutables son compatibles. Nunca se crea generación 2 desde
el productor.

## 7. Exclusión mutua con el pipeline legacy

### 7.1 Scheduler legacy

Antes de `as_has_scheduled_action()` y de
`as_schedule_single_action()`, cada método legacy consulta la autoridad. Para
`reconciliation`:

- sin marcador de generación 1: puede programar legacy;
- con marcador: retorna `DURABLE_OWNS` sin programar;
- lectura fallida o inconsistente: retorna `AUTHORITY_UNKNOWN`, no programa y
  alerta.

La API debe devolver un resultado cerrado; no se oculta una exclusión en un
`void`. Los otros tres estadios conservan conducta legacy hasta su microhito.

### 7.2 Worker legacy

El worker de reconciliación debe:

1. consultar autoridad antes de intentar claim;
2. adquirir el mismo lock funcional usado por la transferencia;
3. volver a consultar el marcador dentro de esa transacción;
4. sólo entonces hacer claim y procesar.

Una acción legacy ya encolada para una unidad transferida termina
`DURABLE_OWNS`, sin incrementar intentos ni tocar la fila funcional. La segunda
lectura cierra la carrera entre la primera consulta y el claim.

Después de un resultado legacy, cualquier scheduling aguas abajo continúa como
hoy. El worker no puede crear ni coordinar generaciones durable.

### 7.3 Recovery legacy

La selección del recovery excluye en SQL mediante `NOT EXISTS` toda
reconciliación con marcador durable de generación 1. La query debe aprovechar la
identidad indexada y conservar límites y orden actuales.

Además, un filtro de autoridad por lote y la guardia transaccional del worker
cierran la carrera entre selección y ejecución. No se admite una consulta N+1.
Los otros tres selectores permanecen sin cambio hasta ser activados.

### 7.4 Recovery durable

Activar requiere un recovery durable registrado que, con lotes acotados:

- retome `dispatching` sin acción asociada;
- reconcilie `scheduled` cuya acción externa falta;
- detecte `claimed` con lease vencido conforme a la política certificada;
- clasifique mismatch/corrupción como remediación, no como fallback legacy;
- llame sólo al coordinator para reparar scheduling;
- respete generación, CAS, token, hook, grupo y payload canónicos.

No se aprueba el flag productivo mientras este recovery no esté implementado,
registrado y probado. El recovery legacy actual busca trabajo funcional y lo
programa en el pipeline histórico; no sustituye esta obligación.

## 8. Ventanas parciales y conducta exigida

| # | Ventana | Autoridad / estado persistido | Recuperador y acción | ¿Legacy? | Resultado / invariante |
|---:|---|---|---|---|---|
| 1 | Fila creada y scheduling falla | Durable / `dispatching` generation 1 | Recovery durable crea o encuentra acción | No | `EXTERNAL_*`; fila antes que efecto externo |
| 2 | Acción creada y asociación falla | Durable / `dispatching`, action ID aún nulo | Coordinator/recovery busca por hook+payload+group; asocia, compensa o alerta | No | Nunca crea otra identidad a ciegas |
| 3 | Muerte tras transferir y antes de programar | Durable / `dispatching` | Recovery durable programa | No | Marcador basta para excluir legacy |
| 4 | Dos productores simultáneos | Durable / una generation 1 | Uno crea; otro relee compatible y converge | No | Lock + unique identity |
| 5 | Legacy y durable observan simultáneamente | Ganador del lock / claim legacy **o** generation 1, nunca ambos | Perdedor retorna resultado cerrado | Sólo si legacy ganó antes de transferir | Mismo lock funcional |
| 6 | Action Scheduler ejecuta antes de asociación | Durable / `dispatching` | Executor rechaza; recovery vuelve a coordinar | No | Sólo `scheduled` elegible se procesa |
| 7 | Scheduler devuelve outcome incierto | Durable / `dispatching` | Coordinator busca identidad externa antes de repetir | No | A lo sumo una acción autoritativa |
| 8 | Acción `scheduled` perdida/cancelada | Durable / `scheduled` hasta CAS de reparación | Recovery confirma ausencia, transiciona y reprograma | No | Ausencia externa no cambia autoridad |
| 9 | Evento funcional revertido tras crear fila | Durable / generation 1 persiste | Processor clasifica terminal/manual/retryable | No | No hay fallback; la reversión previa al commit revierte también la creación |
| 10 | Flag apagado durante ejecución | Durable para marcados / estado vigente | Executor y recovery drenan; no hay acciones nuevas iniciales | Sólo trabajos nunca transferidos | Flag no es autoridad por trabajo |
| 11 | Marcador corrupto/incompatible | Durable fail-closed / fila observada | Remediación y alerta; ninguna nueva acción si identidad no es demostrable | No | Incertidumbre jamás habilita legacy |
| 12 | Deploy viejo frente a acción durable | Durable / estado vigente | Rollback mantiene runtime compatible hasta drenaje | No | Los hooks no se retiran con trabajo vivo |

El diseño requiere transacción y estado intermedio `dispatching`, ambos ya
compatibles con la persistencia actual. No requiere outbox porque la fila se
confirma antes del efecto externo y el coordinator/recovery reconcilia el
intervalo. Tampoco requiere compensar hacia legacy ni añadir columna/tabla. La
compensación permitida se limita a una acción externa duplicada no autoritativa,
cuando su cancelación pueda confirmarse.

## 9. Activación gradual e históricos

Se propone un flag por estadio, con default `false`:

```text
durable_retry.initial_transfer.reconciliation = false
```

El flag sólo gobierna nuevas transferencias en el productor. No desregistra
hooks, no detiene executor, no cancela filas y no cambia recovery. Debe leerse
mediante una política inyectada y ser estable durante una invocación.

Primera activación:

1. desplegar wiring, guardias y recovery con flag apagado;
2. certificar callbacks y observabilidad;
3. encender para una fracción determinista de nuevas reconciliaciones;
4. ampliar por escalones con métricas y pausa entre ellos;
5. declarar el estadio plenamente durable sólo tras drenaje legacy.

La fracción se decide de forma estable con hash del `reconciliation_id`; un
mismo sujeto nunca alterna por request.

Las filas históricas pendientes permanecen legacy. No hay backfill ni barrido
implícito en este microhito. Un migrador histórico futuro deberá adquirir el
mismo lock, demostrar ausencia de claim/acción legacy, cancelar de forma
confirmada la acción antigua y sólo entonces crear el marcador durable.

## 10. Rollback

### Antes de una transferencia

Apagar el flag hace que nuevos eventos sigan legacy. Es reversible y no requiere
migración.

### Después de una transferencia

Apagar el flag impide transferencias nuevas, pero toda unidad ya marcada sigue
durable hasta estado terminal. Deben permanecer desplegados hooks, executor,
processors, coordinator, adapter y recovery.

Está prohibido:

- borrar la fila durable;
- cancelar la acción y reprogramar legacy automáticamente;
- considerar terminal durable como “libre para legacy”;
- desplegar una versión que no entienda los hooks mientras existan filas
  activas o acciones pendientes.

Una transferencia inversa requeriría un protocolo persistente nuevo, cancelación
externa confirmada, prueba de no claim y probablemente esquema. No forma parte
del rollback operativo inicial.

| Estado al apagar | Conducta de rollback |
|---|---|
| Sin generation 1 | Permanece legacy; el productor queda apagado |
| `dispatching` sin acción | Recovery durable coordina |
| `scheduled` | La acción existente se conserva y ejecuta |
| `claimed`/processing | Se completa o recupera el lease por reglas durable |
| Terminal | Permanece terminal y marcada; nunca vuelve a legacy |
| Generación sucesora activa | Executor/recovery durable la drenan |
| Acción externa huérfana o incierta | Coordinator/recovery reconcilia o alerta |
| Transferencia parcial con commit incierto | Relectura por identidad; fail-closed hasta demostrar outcome |

## 11. Interfaces propuestas

Todas devuelven objetos de resultado cerrados; no lanzan para decisiones de
negocio esperables y sí pueden lanzar por programación/configuración inválida.

```php
interface DurableRetryActivationPolicyInterface
{
    public function allowsInitialTransfer(string $stage, int $subjectId): bool;
}

interface DurableRetryInitialTransferAuthorityInterface
{
    public function transferReconciliation(
        int $reconciliationId,
        DateTimeImmutable $scheduledForUtc
    ): DurableRetryInitialTransferResult;
}

interface DurableRetryLegacyExclusionInterface
{
    public function classify(
        string $stage,
        int $subjectId
    ): DurableRetryLegacyAuthorityResult;

    public function classifyBatch(
        string $stage,
        array $subjectIds
    ): DurableRetryLegacyAuthorityBatchResult;
}

interface DurableRetryReconciliationInitialScheduleProducerInterface
{
    public function produce(
        int $reconciliationId
    ): DurableRetryInitialScheduleResult;
}

interface DurableRetryDispatchRecoveryInterface
{
    public function recover(int $limit): DurableRetryRecoveryResult;
}
```

| Contrato / namespace sugerido | Resultado y excepciones | Responsabilidad / implementador | Consumidores | Prohibiciones |
|---|---|---|---|---|
| `Modules\Orders\Contracts\DurableRetryActivationPolicyInterface` | `bool`; excepción sólo por configuración inválida | Permitir transferencia nueva por stage+subject; adapter de config | Productor inicial | No decide autoridad existente ni accede a AS |
| `Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface` | `DurableRetryInitialTransferResult`; error técnico capturado, excepción sólo por argumento/programación inválidos | Lock, elegibilidad y generation 1; repository transaccional de Orders/Reconciliation | Productor | No programa, no crea next generation |
| `Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface` | Resultado individual/batch cerrado; lectura fallida = desconocido | Clasificar marcador; repository durable de lectura | Scheduler, workers y recovery legacy | No muta ni interpreta AS como autoridad |
| `Modules\Orders\Contracts\DurableRetryReconciliationInitialScheduleProducerInterface` | `DurableRetryInitialScheduleResult`; excepción sólo por contrato inválido | Selección exclusiva, transferencia y coordinación post-commit; servicio de aplicación | Materializador | No construye hook/payload libre ni crea generation > 1 |
| `Modules\Orders\Contracts\DurableRetryDispatchRecoveryInterface` | `DurableRetryRecoveryResult`; errores por item dentro del resultado | Reconciliar lotes parciales; servicio durable + coordinator | Cron/bootstrap durable | No procesa funcionalmente, no llama legacy |

`produce()` devuelve `LEGACY_SELECTED`, `DURABLE_CREATED`,
`DURABLE_CONVERGED`, `LEGACY_IN_FLIGHT`, `INELIGIBLE`,
`DURABLE_INCONSISTENCY`, `OUTCOME_UNCERTAIN` o `ERROR`. Sólo
`DURABLE_CREATED`/`DURABLE_CONVERGED` permiten llamar al coordinator.

La implementación concreta de activación pertenece a infraestructura/config; la
transferencia y exclusión pertenecen a persistencia; el productor es servicio de
aplicación. Ninguna interfaz expone Action Scheduler.

## 12. Matriz de ownership

| Operación | Autoridad legacy | Autoridad durable | Antes de migración | Después de migración | Responsable | Explícitamente prohibido |
|---|---|---|---|---|---|---|
| Creación inicial | Scheduler legacy, si flag no selecciona durable | Transfer authority, si flag selecciona durable | Sin marcador | Generation 1 o acción legacy, excluyentes | Productor como selector único | Callback, registrar, processor |
| Deduplicación | Identidad funcional/hook legacy | Unique identity + coordinator | Sólo reglas legacy | Marcador generation 1 | Repositories/coordinator | Memoria, opción WP, mera acción AS |
| Transferencia | Puede ganar claim antes del lock | Escritura generation 1 | Elegible legacy | Durable permanente | Transfer authority | Scheduler externo |
| Programación externa | Scheduler legacy sólo sin marcador | Coordinator + adapter sólo con fila | Acción legacy posible | Acción durable canónica | Scheduler correspondiente a la autoridad | Ambos schedulers para una unidad |
| Claim | Worker funcional legacy sin marcador | Executor sobre fila `scheduled` | Claim legacy | Claim durable | Ganador autorizado bajo guardia | Producer, callback directo, recovery |
| Procesamiento | Worker legacy | Processor convocado por executor | Ruta legacy | Ruta durable | Worker o executor, mutuamente excluyentes | Scheduler/recovery |
| Persistencia | Repository funcional legacy | Repository durable + autoridad funcional | Intento legacy | CAS durable y outcome funcional | Repository del pipeline dueño | Action Scheduler |
| Next generation | Ninguna tras transferencia; retry legacy sólo antes | Sólo executor | Reprogramación legacy | Successor durable | Executor + repository | Producer, processor, callback, recovery |
| Recuperación de acción perdida | Legacy sólo sin marcador | Recovery durable + coordinator | Acción legacy reparable | Acción durable reparable | Recovery del dueño | Fallback cruzado |
| Recovery funcional | Selecciona sólo ausencia de marcador | No selecciona trabajo funcional para scheduling; repara durable | Histórico/pending legacy | Transferido excluido | `DurableCompletionRecovery` con exclusión | Reinsertar transferido |
| Rollback | Recibe sólo nunca transferidos | Drena todos los marcados | Flag puede apagar productor | Autoridad persistida no cambia | Operación + ambos recoveries acotados | Borrar marcador o devolver automáticamente |

## 13. Invariantes numerados

1. Una unidad funcional tiene en cada instante una sola autoridad: legacy o
   durable.
2. Generación 1 existente significa autoridad durable para siempre.
3. Ningún estado terminal durable devuelve autoridad al legacy.
4. El productor jamás crea generación mayor que 1.
5. Sólo el executor origina una generación sucesora.
6. Producer y legacy claim se serializan con el mismo lock funcional.
7. Ante autoridad desconocida o inconsistente, se falla cerrado.
8. Una unidad transferida no puede ser seleccionada, programada, claimed ni
   procesada por legacy.
9. La creación de fila durable ocurre antes de cualquier efecto externo.
10. El scheduling externo ocurre sólo después del commit durable.
11. Hook, grupo y payload proceden exclusivamente del catálogo.
12. El payload conserva dos argumentos posicionales: `schedule_id`,
    `generation`.
13. Los cuatro hooks conservan prioridad 10 y dos argumentos aceptados.
14. El executor es el único coordinador de callbacks.
15. Los processors no programan acciones ni conocen Action Scheduler.
16. Una respuesta externa incierta se reconcilia por identidad antes de repetir.
17. Apagar el flag no abandona trabajo ya transferido.
18. El histórico no se migra sin protocolo explícito.
19. Las queries de recovery son acotadas y no introducen N+1.
20. No se elimina historia durable mientras sea el marcador de transferencia.

## 14. Microhitos de implementación

Cada microhito termina sin staging ni commit hasta su recertificación específica.

### A1. Contratos y resultados de activación

- Alcance: interfaces y value objects cerrados.
- Archivos: `app/Modules/Orders/Contracts/`, `Domain/DurableRetry/`.
- Fuera: wiring, SQL, flags.
- Pruebas: forma, catálogo de resultados, inputs inválidos.
- Stop: contratos certificados.
- Aprobación: no.
- Schema: no.
- Rollback: borrar sólo archivos nuevos antes de integrar.

### A2. Política de flag determinista

- Alcance: flag por stage, default off, cohort estable.
- Archivos: contrato, adapter de configuración y tests.
- Fuera: cambiar `Config::DB_VERSION`.
- Pruebas: off, on, porcentaje límite y estabilidad por subject.
- Stop: ninguna ruta productiva lo consume.
- Aprobación: no.
- Schema: no.
- Rollback: default off.

### A3. Lectura de marcador y clasificación batch

- Alcance: lookup generation 1 individual y por lote.
- Archivos: repository/adapter de exclusión y tests.
- Fuera: modificar scheduler/workers/recovery.
- Pruebas: ausente, activo, terminal, corrupto, error, sin N+1.
- Stop: read-only.
- Aprobación: no.
- Schema: no.
- Rollback: retirar consumidor futuro.

### A4. Transferencia transaccional de reconciliación

- Alcance: lock funcional, elegibilidad, creación generation 1 y resultados.
- Archivos: servicio/repository de transferencia y tests de infraestructura.
- Fuera: scheduling externo.
- Pruebas: carreras, duplicate compatible/incompatible, rollback/timeout.
- Stop: API no conectada.
- Aprobación: no.
- Schema: no, salvo que la prueba de plan revele índice insuficiente.
- Rollback: no existen filas productivas porque flag y wiring siguen ausentes.

### A5. Productor inicial aislado

- Alcance: construir snapshot y coordinar tras commit.
- Archivos: producer, factory de identidad/reloj, tests.
- Fuera: materializador.
- Pruebas: los siete resultados, repetición y cero llamadas externas indebidas.
- Stop: productor no invocado.
- Aprobación: no.
- Schema: no.
- Rollback: retirar servicio.

### A6. Guardia del scheduler legacy

- Alcance: resultado explícito y exclusión `reconciliation`.
- Archivos: scheduler legacy, contrato/adapter, tests.
- Fuera: otros estadios.
- Pruebas: programa sin marcador; no programa con cualquier marcador/error.
- Stop: flag apagado y productor aún no conectado.
- Aprobación: no.
- Schema: no.
- Rollback: revertir guardia sólo si nunca se activó.

### A7. Guardia transaccional del worker legacy

- Alcance: precheck, lock, recheck antes de claim.
- Archivos: worker/repository funcional y tests de carrera.
- Fuera: processors durable.
- Pruebas: acción stale, productor ganador, worker ganador, error fail-closed.
- Stop: ninguna unidad productiva transferida.
- Aprobación: no.
- Schema: no.
- Rollback: sólo antes de encender flag.

### A8. Exclusión SQL del recovery legacy

- Alcance: `NOT EXISTS` para reconciliation y filtro batch.
- Archivos: recovery/query legacy y tests de infraestructura.
- Fuera: business/delivery/fulfillment.
- Pruebas: activo/terminal/corrupto excluidos, límites, plan, carrera.
- Stop: flag apagado.
- Aprobación: no.
- Schema: sólo si `EXPLAIN` exige un índice nuevo; en ese caso separar microhito.
- Rollback: restaurar query antes de activar.

### A9. Recovery de dispatch durable

- Alcance: reparar estados parciales con lotes y CAS.
- Archivos: recovery durable, wiring y tests.
- Fuera: transferencias históricas.
- Pruebas: las ventanas 2, 3, 6, 7, 8 y 11.
- Stop: no cron productivo hasta certificar observabilidad.
- Aprobación: no.
- Schema: no previsto.
- Rollback: mantenerlo desplegado si existen filas durable.

### A10. Wiring del productor en materializador

- Alcance: reemplazar las dos llamadas directas por una selección exclusiva.
- Archivos: materializador y composición.
- Fuera: activar flag.
- Pruebas: ambas ramas, legacy seleccionado, durable seleccionado, jamás ambos.
- Stop: default off.
- Aprobación: no para deploy apagado; sí antes de encender.
- Schema: no.
- Rollback: flag off; no retirar wiring mientras haya filas transferidas.

### A11. Certificación integral de coexistencia

- Alcance: carreras, callbacks, recovery, observabilidad y rollback ensayado.
- Archivos: sólo harnesses/documentación de certificación.
- Fuera: mutaciones productivas.
- Pruebas: 12 ventanas, invariantes 1-20, hooks/payload, suites históricas.
- Stop: informe firmado.
- Aprobación: **sí**, puerta obligatoria.
- Schema: verificar `0.24.0`.
- Rollback: ensayo flag off + drenaje.

### A12. Canario de reconciliación nueva

- Alcance: porcentaje mínimo determinista y sólo nuevos IDs.
- Archivos: configuración operativa fuera del commit de código.
- Fuera: históricos y otros stages.
- Pruebas: smoke, métricas, ausencia de doble claim/acción.
- Stop: ventana de observación definida.
- Aprobación: **sí**, operativa.
- Schema: no.
- Rollback: flag off; durable existente continúa.

### A13. Ampliación y cierre de legacy para reconciliation

- Alcance: escalones hasta 100 %, drenaje y declaración de autoridad.
- Archivos: configuración y runbook.
- Fuera: borrar pipeline legacy o historia durable.
- Pruebas: contadores de exclusión, cola legacy drenada, recovery estable.
- Stop: reconciliación certificada.
- Aprobación: **sí** por escalón.
- Schema: no.
- Rollback: detener nuevas transferencias, no transferir hacia atrás.

### A14. Diseño separado de migración histórica

- Alcance: protocolo de cancelación confirmada y transferencia de pendientes.
- Archivos: documento nuevo; implementación posterior.
- Fuera: ejecutar backfill.
- Pruebas: modelo de carreras y dry-run.
- Stop: sólo diseño.
- Aprobación: **sí** antes de implementar.
- Schema: reevaluar marcador/purga/auditoría.
- Rollback: por lote con checkpoint, nunca implícito.

## 15. Condiciones de aprobación productiva

La activación no es implementable/aprobable hasta que sean inequívocos y
verificados estos doce puntos:

1. stage inicial;
2. productor y método exactos;
3. frontera transaccional;
4. identidad y snapshot inicial completos;
5. marcador permanente de transferencia;
6. guardia del scheduler legacy;
7. guardia del worker legacy;
8. exclusión del recovery legacy;
9. recovery durable;
10. comportamiento de todas las ventanas parciales;
11. activación, históricos y rollback;
12. ownership y observabilidad.

Este documento los define, pero la aprobación productiva permanece bloqueada
hasta completar A1-A11. El primer cambio de tráfico requiere aprobación explícita
en A12.

## 16. Observabilidad mínima

Antes del canario deben existir métricas y logs estructurados por `stage`,
`subject_id`, `schedule_id`, `generation` y resultado, sin tokens:

- transferencias creadas/convergentes/rechazadas;
- exclusiones del scheduler, worker y recovery legacy;
- callbacks aceptados/rechazados por razón;
- filas `dispatching` y `claimed` envejecidas;
- acción externa ausente/mismatch;
- intentos de doble autoridad (objetivo: cero);
- latencia materialización→scheduled→claimed→terminal.

Alertas bloqueantes: cualquier procesamiento legacy de una unidad marcada,
creación de dos acciones externas para la misma generación, inconsistencia
durable o crecimiento sostenido de estados parciales.

## 17. Referencias de auditoría

- El wiring previo declara intacto el pipeline legacy hasta disponer de un plan
  explícito (`docs/durable-retry-production-wiring-design.md:64-68`).
- El callback durable no debe actuar como productor
  (`docs/durable-retry-production-wiring-design.md:459-468`).
- La secuencia recomendada es adapters, callbacks, recovery y aislamiento legacy
  tras paridad (`docs/durable-retry-processing-lifecycle-design.md:635-654`).
- La orquestación legacy registra workers y recovery
  (`app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php:7-25`);
  su scheduler concentra los cuatro hooks y retry
  (`app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php:7-35`).
- Los nacimientos downstream están en los cuatro métodos de workers
  (`app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php:30-75`);
  el recovery legacy los vuelve a seleccionar
  (`app/Modules/Fulfillment/Orchestration/DurableCompletionRecovery.php:13-38`).
- Las autoridades funcionales auditadas son
  `app/Modules/Payments/Reconciliation/Contracts/PaymentReconciliationReadAuthorityInterface.php:9-11`,
  `app/Modules/Payments/Reconciliation/Contracts/PaymentReconciliationLeaseAuthorityInterface.php:9-24`,
  `app/Modules/Payments/BusinessCompletion/Contracts/BusinessCompletionReadAuthorityInterface.php:7-12`,
  `app/Modules/Delivery/Completion/Contracts/DeliveryCompletionReadAuthorityInterface.php:7-12`
  y
  `app/Modules/Fulfillment/Completion/Contracts/FulfillmentCompletionReadAuthorityInterface.php:7-12`;
  sus implementaciones concretas son los repositories de cada módulo.
- El repository crea y reconverge por identidad
  (`app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:95-139`).
- El coordinator agenda mediante adapter
  (`app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php:99`).

Conclusión: `reconciliation` puede activarse primero sin un cambio de esquema,
pero sólo mediante transferencia transaccional, marcador histórico permanente,
triple exclusión legacy y recovery durable ya operativo. Un simple cambio de
hook o un flag que elija dos schedulers no satisface la exclusión mutua.
