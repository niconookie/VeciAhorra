# Auditoría de implementabilidad del wiring productivo Durable Retry

## 1. Veredicto ejecutivo

**WIRING PRODUCTIVO BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

La base contiene y certifica de forma aislada la fuente A2.1, la política A2, la
clasificación A3, la transferencia A4, el productor de autoridad A5, el
repositorio durable, el coordinador externo, el registro de procesadores, el
executor, el callback y el adaptador de Action Scheduler. También existe un
punto de composición productivo único en
`VeciAhorra\Core\Application::registerDurableRetryGraph()`.

No obstante, el wiring de producción no puede implementarse todavía sin tomar
decisiones normativas nuevas:

1. `docs/durable-retry-production-activation-composition-spec.md` conserva un
   A5 antiguo que crea y coordina schedules; la corrección normativa A5 vigente
   prohíbe ambos efectos y limita A5 a producir autoridad.
2. No existe un contrato autorizado que transforme el resultado A5/A4 en el
   par `schedule_id`/`generation` requerido por
   `DurableRetryExternalScheduleCoordinatorInterface::coordinate()`.
3. La especificación exige completar A6, A7, A8 y A9 antes del wiring A10, pero
   el scheduler, worker y recovery legacy todavía no consultan A3.
4. El punto productivo actual crea `DurableCompletionScheduler` directamente
   dentro de `WebpayReconciliationMaterializer`; no existe una firma normativa
   cerrada para sustituir esa llamada por una decisión única A5/legacy.
5. El bootstrap permite construir una nueva `Application` en cada llamada a
   `Bootstrap::boot()`. La guardia del registrar es por instancia y no resuelve
   el registro duplicado entre dos instancias.
6. No está fijada la semántica productiva de observabilidad y cierre para los
   resultados no exitosos de A5 ni para una excepción parcial de composición.

Decisiones ya resueltas:

- A2 solo decide nuevas transferencias y no revoca autoridad durable.
- A5 evalúa A2 únicamente después de que A3 devuelve `legacy`.
- A3 `durable` e `indeterminate` cierran sin fallback legacy.
- A4 es el único autorizado para crear `generation = 1`.
- A5 no ejecuta SQL, scheduling, hooks ni callbacks.
- Los hooks durables, grupo, payload, prioridad y argumentos están cerrados por
  `DurableRetryExternalScheduleCatalog` y `DurableRetryActionHookRegistrar`.
- El callback delega en un executor; el registry exige exactamente los cuatro
  procesadores.
- El wiring no puede contener SQL.

Siguiente microhito recomendado: corrección normativa A10 que sustituya el A5
obsoleto de la especificación de composición, defina el puente pos-A5 hacia el
coordinador y cierre la firma del punto de decisión del materializador.
Después deben implementarse y certificarse A6–A9; solo entonces procede una
nueva auditoría de allowlist para A10.

## 2. Estado base certificado

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `125f202058a2693555a879b41b5d6efb0d10a202` |
| Divergencia `origin/main...HEAD` | `0` atrás / `44` adelante |
| Staging | vacío |
| Cambios tracked | `0` |
| Suite Durable Retry | `60/60` harnesses, `4.560` assertions |
| Fallos / warnings / notices / deprecations | `0 / 0 / 0 / 0` |
| Integraciones de executor | cuatro verdes |
| `artifacts/` | `504` archivos, sin modificación |
| Temporales | `0` |
| Índices temporales | `0` |
| Push | no realizado |

Los documentos y archivos no versionados preexistentes se preservaron. Esta
auditoría no usa su presencia como autorización para modificarlos.

## 3. Fuentes normativas y técnicas inspeccionadas

Fuentes normativas principales:

- `docs/durable-retry-production-wiring-design.md`
- `docs/durable-retry-production-activation-design.md`
- `docs/durable-retry-production-activation-composition-spec.md`
- `docs/durable-retry-production-activation-composition-readiness-audit.md`
- `docs/durable-retry-production-activation-a1-contracts-spec.md`
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`
- `docs/durable-retry-production-activation-configuration-source-spec.md`
- `docs/durable-retry-production-activation-a3-normative-correction.md`
- `docs/durable-retry-production-activation-a4-readiness-audit.md`
- `docs/durable-retry-production-activation-a4-equivalence-normative-correction.md`
- `docs/durable-retry-production-activation-a5-readiness-audit.md`
- `docs/durable-retry-production-activation-a5-normative-correction.md`

Las afirmaciones se contrastaron con `app/Core/Application.php`,
`app/Core/Bootstrap.php`, `veciahorra.php`, contratos, dominio, infraestructura,
repositorios, servicios y harnesses `tests/manual/durable-retry-*.php`.

## 4. Inventario real de componentes A2.1–A5

| Autoridad | FQCN productivo | Firma pública relevante | Efectos permitidos | Estado |
|---|---|---|---|---|
| A2.1 | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource` | contrato `DurableRetryActivationConfigurationSourceInterface` | una lectura de configuración mediante reader | implementado, no compuesto |
| Reader A2.1 | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader` | lectura del valor WordPress | `get_option`; sin escritura | implementado, no compuesto |
| A2 | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy` | `allowsInitialTransfer(DurableRetryAuthorityIdentity): bool` | evaluación determinista | implementado, no compuesto |
| A3 | `VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository` | `classify(...)` por `DurableRetryLegacyExclusionInterface` | lectura batch de `va_durable_retry_schedules` | implementado, no compuesto |
| A4 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority` | `transferReconciliation(DurableRetryInitialTransferRequest): DurableRetryInitialTransferResult` | transacción y creación idempotente de generación 1 | implementado, no compuesto |
| Repo A4 | `VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository` | contrato de transferencia | SQL y locks autorizados | implementado, no compuesto |
| A5 | `VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer` | `produceReconciliation(DurableRetryInitialTransferRequest): DurableRetryInitialAuthorityProductionResult` | orquestar A3→A2→A4 | implementado, no compuesto |

A5 recibe exactamente
`DurableRetryLegacyExclusionInterface`,
`DurableRetryActivationPolicyInterface` y
`DurableRetryInitialTransferAuthorityInterface`. Captura fallos de dependencias
y no expone scheduling. Sus harnesses funcional y de infraestructura protegen
el orden, los estados cerrados y la ausencia de efectos ajenos.

## 5. Inventario real del runtime durable

| Componente | FQCN / ruta | Responsabilidad y dependencias |
|---|---|---|
| Repositorio | `...\Repositories\DurableRetryScheduleRepository` | lectura, CAS, transiciones y siguientes generaciones |
| Coordinador | `...\Services\DurableRetryExternalScheduleCoordinator` | recibe `(int $scheduleId, int $generation)`, lee snapshot, programa y asocia action id |
| Adaptador | `...\Infrastructure\DurableRetry\ActionSchedulerDurableRetryAdapter` | `as_schedule_single_action`, búsqueda pending y cancelación |
| Catálogo | `...\Domain\DurableRetry\DurableRetryExternalScheduleCatalog` | hooks, grupo, stage y timestamp |
| Resolver/registry | `...\Services\DurableRetryProcessorRegistry` | mapa completo y sin duplicados de cuatro stages |
| Executor | `...\Services\DurableRetryExecutor` | claim, processor, transición y coordinación de sucesor |
| Callback | `...\Infrastructure\DurableRetry\DurableRetryActionCallback` | valida hook/payload y delega al executor |
| Registrar | `...\Infrastructure\DurableRetry\DurableRetryActionHookRegistrar` | registra los cuatro callbacks una vez por instancia |
| Procesador reconciliation | `...\Services\DurableRetryReconciliationProcessor` | procesa reconciliation |
| Procesador business | `...\Services\DurableRetryBusinessCompletionProcessor` | procesa business completion |
| Procesador delivery | `...\Services\DurableRetryDeliveryCompletionProcessor` | procesa delivery completion |
| Procesador fulfillment | `...\Services\DurableRetryFulfillmentProcessor` | procesa fulfillment completion |

El repositorio y coordinador son los únicos puntos autorizados para persistencia
y coordinación externa. Callback, registry y processors no adquieren autoridad
de transferencia inicial.

## 6. Composición productiva encontrada

`VeciAhorra\Core\Application::__construct()` crea el contenedor y ejecuta
`registerDurableRetryGraph()`. Ese método privado registra repositorio,
adaptador, política de procesamiento, coordinador, registry, executor, callback
y registrar como singletons del contenedor.

`Application::run()` registra primero la orquestación legacy y después obtiene
el registrar durable del contenedor y llama `register()`. `Bootstrap::boot()`
crea una nueva `Application` y ejecuta `run()`. `veciahorra.php` carga el
autoload, registra instalación y llama `Bootstrap::boot()`.

El grafo A2.1–A5 no está registrado. El grafo efectivo actual es:

```text
veciahorra.php
→ Bootstrap::boot()
→ new Application()
→ Application::registerDurableRetryGraph()
→ repository + Action Scheduler adapter + processing policy
→ external coordinator
→ four processors → DurableRetryProcessorRegistry
→ DurableRetryExecutor
→ DurableRetryActionCallback
→ DurableRetryActionHookRegistrar
→ Application::run()
→ registrar::register()
```

## 7. Grafo futuro condicionado

El grafo normativamente compatible, una vez corregidos los bloqueos, debe
reutilizar instancias compartidas del contenedor:

```text
Application::registerDurableRetryGraph()
├─ WordPress option reader
├─ A2.1 configuration source(reader)
├─ A2 deterministic policy(A2.1)
├─ A3 legacy authority repository
├─ A4 initial transfer repository
├─ A4 initial transfer authority(repository)
├─ A5 initial authority producer(A3, A2, A4)
├─ schedule repository
├─ Action Scheduler adapter
├─ external coordinator(repository, adapter, utcNow)
├─ four stateless processors
├─ registry(four processors)
├─ executor(repository, registry, processing policy, coordinator, utcNow)
├─ callback(executor)
└─ hook registrar(callback)
```

A2.1 no debe construirse ni leerse durante el registro: la lectura debe ser
lazy dentro de la invocación A5 y solo cuando A3 sea `legacy`. Repositorios,
policy, A5, coordinator, registry, executor, callback y registrar deben ser
singletons por `Application`/request. Los objetos de resultado y request son
por invocación. Procesadores y registry deben permanecer stateless.

Falta normar el consumidor que une el materializador con A5 y, tras autoridad
durable, con el coordinador.

## 8. Punto único de wiring

El único archivo ya apto para composición de dependencias es
`app/Core/Application.php`, método privado
`Application::registerDurableRetryGraph()`. Crear otro composition root o
instanciar A2–A5 en el materializador duplicaría ownership y queda prohibido.

La carga debe continuar por `veciahorra.php → Bootstrap::boot() →
Application::run()`. El comportamiento debe ser uniforme en admin, frontend,
REST, cron y Action Scheduler porque todos cargan el mismo plugin.

Bloqueo: no hay guardia global que haga `Bootstrap::boot()` idempotente.
`DurableRetryActionHookRegistrar::$registered` evita duplicados solo en la
misma instancia. La corrección A10 debe elegir normativamente entre:

- una guardia estática privada en `Bootstrap::boot()`; o
- garantizar una instancia única de `Application` en el entry point.

Se propone la primera, con retorno inmediato en una segunda llamada, pero no
puede implementarse hasta que la norma la autorice.

## 9. Hooks exactos ya cerrados

Todos son `add_action`, prioridad `10`, `accepted_args = 2`, registrados por
`DurableRetryActionHookRegistrar::register()` y delegan a
`DurableRetryActionCallback::execute($hook, $scheduleId, $generation)`:

| Stage | Hook literal |
|---|---|
| reconciliation | `veciahorra_durable_retry_reconciliation` |
| business completion | `veciahorra_durable_retry_business_completion` |
| delivery completion | `veciahorra_durable_retry_delivery_completion` |
| fulfillment completion | `veciahorra_durable_retry_fulfillment_completion` |

Grupo exacto: `veciahorra-durable-retry`. Payload exacto:
`['schedule_id' => positive-int, 'generation' => positive-int]`.
Hook desconocido o payload inválido se cierra en el callback y no se delega.

No existe ni debe inventarse un hook para producir autoridad inicial: el punto
actual es una llamada directa del materializador tras persistir reconciliation.
La firma de reemplazo de esa llamada es un bloqueo documental.

## 10. Flujo legacy real

`WebpayReconciliationMaterializer::materialize()` y `resume()` persisten la
reconciliation y ejecutan:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

`DurableCompletionScheduler` usa el grupo `veciahorra-completion`, hook
`veciahorra_process_payment_reconciliation`, payload
`['authority_id' => $reconciliationId]`, consulta acciones existentes y agenda
una acción única. Los otros hooks legacy avanzan business, delivery y
fulfillment. `DurableCompletionWorkers` procesa y encadena etapas.
`DurableCompletionRecovery` vuelve a programar trabajo pendiente/reintentable.

Ninguno de scheduler, workers o recovery consulta A3. Por ello hoy no existe
exclusión productiva entre autoridad legacy y durable.

## 11. Regla de convivencia requerida

La decisión por identidad debe ser única:

| Resultado A5 | Rama autorizada |
|---|---|
| `legacy_allowed` | exactamente una llamada al scheduler legacy |
| `durable_created` | coordinar exactamente la generación 1 creada |
| `durable_existing` | no crear; coordinar solo si una regla de evidencia lo autoriza |
| `authority_indeterminate` | ninguna producción; intervención |
| `configuration_invalid` | ninguna producción; sin fallback |
| conflicto/incertidumbre A4 | ninguna producción externa; intervención |
| fallo operacional/dependencia | ninguna producción; sin fallback |

El nombre literal de todos los códigos debe conservar el catálogo real de
`DurableRetryInitialAuthorityProductionResult`; la tabla describe ramas, no
crea estados.

La misma invocación no puede llamar A3/A2/A4 fuera de A5 ni reevaluar A5. El
snapshot A2.1 se obtiene una sola vez cuando A3 fue `legacy`. Evidencia durable
persistida prevalece aunque el porcentaje actual sea cero.

Bloqueo: el resultado A5 no define de forma suficiente la evidencia requerida
para coordinar con `schedule_id`, especialmente en `durable_existing`.

## 12. Snapshot y orden real de evaluación

El orden pedido “configuración → política → A3 → A4 → A5” es incorrecto.
El orden certificado dentro de A5 es:

```text
validar request
→ A3 classify
  ├─ durable: cerrar sin A2/A4
  ├─ indeterminate: cerrar sin A2/A4
  └─ legacy
     → A2 allowsInitialTransfer
       → A2.1 obtiene una snapshot
       ├─ false: legacy_allowed
       └─ true: A4 transferReconciliation
```

Máximos: una clasificación A3, una evaluación A2, una snapshot A2.1 y una
transferencia A4. No hay retry, loop, sleep ni segunda lectura de configuración
en A5. Si una dependencia lanza, A5 devuelve un resultado cerrado. El caller no
puede degradarlo a legacy.

## 13. Identidad y payload

Identidad inicial reconciliation:

- `stage = reconciliation`;
- `subject_id = payment_reconciliations.id`;
- `completion_id = subject_id`;
- `generation = 1`;
- `attempt_number = 0`;
- `scheduled_for` UTC, precisión de segundos;
- estado inicial y reason code según A4;
- `schedule_id` es generado por persistencia y nunca aceptado desde entrada.

Identidad durable persistida: `schedule_id`, stage, subject, completion,
generation, attempt, status, active slot, reason, timestamps y version.

Payload Action Scheduler para cada stage:

```text
reconciliation:      hook veciahorra_durable_retry_reconciliation
business_completion: hook veciahorra_durable_retry_business_completion
delivery_completion: hook veciahorra_durable_retry_delivery_completion
fulfillment:         hook veciahorra_durable_retry_fulfillment_completion
arguments:           schedule_id + generation
group:               veciahorra-durable-retry
```

El usuario externo no puede elegir stage, generation, attempt, hook, group,
status, active slot, reason, version ni action id.

## 14. Scheduling inicial

A5 solo decide/proporciona autoridad; no agenda. El único componente actual que
puede agendar durable es `DurableRetryExternalScheduleCoordinator`, mediante
`DurableRetryExternalSchedulerInterface`.

El coordinador requiere `coordinate(int $scheduleId, int $generation)`. Lee la
fila, exige generación coincidente y estado elegible, busca/crea una acción,
asocia `scheduled_action_id` por CAS y compensa cuando corresponde. El adaptador
usa `as_schedule_single_action(..., true)`.

Bloqueo normativo: A4/A5 no entregan un contrato estable de `schedule_id` para
todas las ramas durables ni se define si `durable_existing` debe coordinar,
verificar pending o quedar no-op. Tampoco está fijado el `scheduled_for` que el
materializador debe pasar a `DurableRetryInitialTransferRequest` a partir de
sus timestamps reales.

## 15. Exclusión e idempotencia

| Garantía | Dueño existente | Brecha |
|---|---|---|
| generación 1 única | A4 + repositorio/índices | certificada |
| un INSERT inicial | A4 | certificada |
| transferencia concurrente | A4 locks y convergencia | certificada |
| action durable única | coordinator + adapter + CAS | certificada por identidad |
| callback repetido | executor state/generation checks | certificada |
| registry completo | `DurableRetryProcessorRegistry` | certificada |
| hooks una vez | registrar | solo por instancia |
| legacy excluido | debería ser A6–A8 consultando A3 | ausente |
| una decisión inicial | futuro caller de A5 | sin contrato |

Dos procesos PHP deben converger por A4 y coordinator, no mediante memoria.
Un cambio de porcentaje durante una invocación no afecta la snapshot ya leída.

## 16. Transacciones y SQL

- A3: solo SELECT batch autorizado.
- A2.1: lectura de option por API WordPress; cero SQL escrito por wiring.
- A4/repository: inicio transaccional, locks, lectura, INSERT máximo uno,
  commit/rollback y relectura ante incertidumbre.
- Schedule repository: lecturas y CAS de lifecycle.
- Coordinator: no SQL directo; usa repository.
- A5, policy, callback, registry, processors de composición y wiring: cero SQL.

Duplicate key compatible converge a autoridad ya durable; incompatible cierra
conflicto/inconsistencia. Commit incierto exige relectura dentro del repositorio
A4. Wiring y caller no pueden repetir INSERT ni abrir transacción.

## 17. Matriz de errores

| Caso | Cierre requerido | Scheduling | Fallback legacy |
|---|---|---|---|
| configuración inválida | resultado A5 inválido | ninguno | prohibido |
| A3 indeterminate | resultado cerrado | ninguno | prohibido |
| A4 incertidumbre | resultado incierto/intervención | ninguno | prohibido |
| inconsistencia durable | intervención | ninguno nuevo | prohibido |
| duplicate compatible | convergencia durable | según regla pos-A5 pendiente | prohibido |
| duplicate incompatible | conflicto | ninguno | prohibido |
| scheduling fallido | resultado coordinator | no reintento local | prohibido |
| scheduling incierto | intervención/convergencia posterior | no duplicar | prohibido |
| excepción del registro | bootstrap no debe quedar medio registrado | ninguno nuevo | prohibido |
| excepción processor | executor clasifica outcome uncertain | según lifecycle | no aplica |
| payload inválido | callback cierra | ninguno | no aplica |
| hook desconocido | callback cierra | ninguno | no aplica |
| dependencia no disponible | fallo operacional | ninguno | prohibido |
| Action Scheduler ausente | coordinator/adapter unavailable | ninguno | prohibido |

No hay contrato normativo de logging para estos cierres. Hasta corregirlo, el
wiring no debe añadir logs, métricas o hooks ad hoc.

## 18. Activación inicialmente apagada

Con porcentaje `0`, instalar el grafo es compatible con construir servicios y
registrar los cuatro callbacks durables. La lectura de configuración solo debe
ocurrir al procesar una nueva identidad A3 `legacy`.

- una autoridad ya durable sigue durable y puede ejecutar callbacks pendientes;
- una identidad legacy nueva recibe `legacy_allowed` y usa exactamente el flujo
  legacy;
- no se crea fila durable;
- no se coordina una acción durable nueva;
- el porcentaje cero no desregistra callbacks ni reinterpreta filas durables.

Esta semántica depende de que A6–A8 excluyan legacy para identidades durables,
guardas hoy ausentes.

## 19. Activación gradual y rollback

| Configuración | Nueva identidad legacy | Identidad durable |
|---|---|---|
| 0% | legacy | permanece durable |
| parcial | cohorte determinista A2 | permanece durable |
| 100% | candidata A4 | permanece durable |
| inválida | cierre sin producción | permanece durable por A3 |

Cambiar el porcentaje entre invocaciones afecta solo nuevas transferencias.
Rollback operativo significa fijarlo en 0; no se eliminan hooks durables,
acciones pending, filas, generaciones ni callbacks. Los schedules durables ya
existentes continúan. El productor legacy solo vuelve a actuar donde A5 haya
devuelto permiso explícito y A3 demuestre legacy.

## 20. Presupuesto operacional cerrado

### Instalación del wiring

- lecturas de configuración/SQL/locks/escrituras: `0`;
- registros de hooks durables: `4` por bootstrap efectivo;
- scheduling: `0`;
- llamadas A5/legacy: `0`.

### Producción inicial por identidad

- A5: exactamente `1`;
- A3: máximo `1` clasificación batch;
- A2/A2.1: `0` si A3 no es legacy; en caso contrario máximo `1`;
- A4: máximo `1`;
- INSERT durable: máximo `1`;
- llamada legacy: máximo `1`, solo `legacy_allowed`;
- coordinación durable: máximo `1`, pendiente de cierre normativo;
- loops, sleeps, retries locales: `0`.

### Callback durable

- callback y executor: `1`;
- processor: máximo `1`;
- claim y transición según lifecycle;
- coordinación de sucesor: máximo `1`;
- llamada legacy: `0`.

Los máximos SQL precisos de A3/A4/repository permanecen en sus especificaciones
y harnesses; A10 no puede ampliarlos.

## 21. Allowlist futura: no autorizable todavía

No es correcto declarar hoy una allowlist final de wiring. La secuencia cerrada
de documentos/microhitos debe ser:

1. nueva corrección normativa A10, solo documental;
2. A6: guardia del scheduler legacy;
3. A7: guardia del worker legacy;
4. A8: exclusión del recovery legacy;
5. A9: recuperación/dispatch durable;
6. nueva readiness de A10;
7. implementación A10 con allowlist derivada de firmas ya versionadas.

Allowlist candidata, expresamente no autorizada hasta ese cierre:

| Archivo | Tipo | Cambio candidato |
|---|---|---|
| `app/Core/Application.php` | modificado | registrar A2.1–A5 y consumidor |
| `app/Core/Bootstrap.php` | modificado | idempotencia global si la norma la elige |
| ruta real de `WebpayReconciliationMaterializer.php` | modificado | inyectar un único puerto de decisión |
| contrato nuevo del consumidor A10 | nuevo | una sola operación reconciliation |
| servicio consumidor A10 | nuevo | rama A5→legacy/coordinator |
| harness funcional A10 | nuevo | matriz cerrada |
| harness infraestructura A10 | nuevo | firmas/ausencias/allowlist |
| harness integración A10 | nuevo | runtime real |
| harness de bootstrap | nuevo/modificado | registro exactamente una vez |

No se autoriza “resolver” la ruta, nombre o firma pendiente durante código.

## 22. Firmas propuestas, no definitivas

La composición debe permanecer privada:

```php
private function registerDurableRetryGraph(): void;
```

El registrar existente permanece:

```php
public function register(): void;
```

El callback durable existente permanece:

```php
public function execute(
    mixed $hook,
    mixed $scheduleId,
    mixed $generation
): DurableRetryExecutionResult;
```

Firma normativa propuesta para el puerto del materializador:

```php
public function afterReconciliationPersisted(
    int $reconciliationId,
    DateTimeImmutable $scheduledForUtc
): DurableRetryProductionRoutingResult;
```

Constructor propuesto del consumidor:

```php
public function __construct(
    DurableRetryInitialAuthorityProducerInterface $producer,
    DurableRetryExternalScheduleCoordinatorInterface $coordinator,
    DurableCompletionSchedulerInterface $legacyScheduler
);
```

Estas firmas no son definitivas: `DurableCompletionSchedulerInterface` y
`DurableRetryProductionRoutingResult` no existen ni están autorizados, y el
resultado A5 no expone todavía toda la evidencia del coordinador. Esa ausencia
es precisamente un bloqueo, no una invitación a crear APIs durante A10.

## 23. Harness funcional futuro

Se propone una matriz exacta de **24 casos independientes**:

1. bootstrap sin producción;
2. 0% + legacy;
3. parcial fuera de cohorte;
4. parcial dentro de cohorte;
5. 100% + transferencia nueva;
6. A3 durable;
7. A3 indeterminate;
8. configuración inválida;
9. fallo A2.1;
10. fallo A3;
11. A4 creada;
12. A4 ya durable;
13. A4 conflicto;
14. A4 resultado incierto;
15. A4 persistencia fallida;
16. legacy exactamente una vez;
17. durable prohíbe legacy;
18. cierre prohíbe ambos productores;
19. A5 exactamente una vez;
20. snapshot A2.1 exactamente una vez;
21. coordinator exactamente una vez;
22. coordinator fallido sin fallback;
23. dos llamadas convergen;
24. orden estricto A5→rama única.

Cada caso debe usar doubles nuevos, journal ordenado, contadores exactos,
resultado cerrado, operaciones permitidas/prohibidas y limpieza completa.

## 24. Harness de infraestructura futuro

Debe certificar:

- rutas y FQCN exactos de la allowlist final;
- constructor y único método público del consumidor;
- bindings por interfaz y singletons;
- cuatro hooks literales, prioridad 10, dos argumentos;
- A5 sin SQL/scheduling/hooks;
- wiring sin SQL, loops, sleeps, retries ni fallback;
- materializador sin `new DurableCompletionScheduler()`;
- única llamada A5 y una única rama;
- A6–A9 presentes;
- bootstrap idempotente;
- allowlist de mantenimiento explícita, sin inventario global rígido;
- cero archivos ajenos.

## 25. Harnesses de integración futuros

Se requieren **10 integraciones**:

1. materializador/wiring → A5;
2. resultado legacy → scheduler legacy;
3. resultado durable creado → coordinator;
4. A5 → A4 con MySQL real;
5. coordinator → Action Scheduler → callback;
6. callback → executor;
7. registry → cuatro processors;
8. exclusión A3 en scheduler/worker/recovery legacy;
9. Action Scheduler disponible y ausente;
10. dos procesos concurrentes y cohorte parcial determinista.

Fixtures: reconciliation persistida, tabla durable limpia por identidad,
opciones WordPress restaurables y cola Action Scheduler aislada. Assertions:
filas, generaciones, pending actions, payload, journal, cero fallback y cero
duplicados. La limpieza debe restaurar opción, acciones y fixtures incluso ante
excepción.

## 26. Matriz de trazabilidad

| Regla | Origen | Clase/método real | Harness existente | Futuro | Estado |
|---|---|---|---|---|---|
| A2 solo tras legacy | corrección A5 | `A5::produceReconciliation` | A5 funcional | A10 funcional | resuelto |
| config snapshot única | A2/A2.1 | policy/source | A2/A2.1 | A10 funcional | resuelto |
| gen1 única | A4 | `transferReconciliation` | A4 + MySQL | concurrencia | resuelto |
| A5 sin scheduling | corrección A5 | A5 | infraestructura A5 | infraestructura A10 | resuelto |
| hooks durables | activation design/catalog | registrar/catalog | callback/registrar | bootstrap | resuelto |
| puente A5→coordinator | composición antigua vs corrección | inexistente | ninguno | A10 | ambiguo |
| schedule id pos-A5 | no definido | coordinator exige id | coordinator | A10 | ausente |
| exclusión scheduler | A6 | legacy scheduler | ninguno | A6/integración | ausente |
| exclusión worker | A7 | legacy workers | ninguno | A7/integración | ausente |
| exclusión recovery | A8 | legacy recovery | ninguno | A8/integración | ausente |
| recovery durable | A9 | no compuesto | ninguno | A9 | ausente |
| bootstrap único | wiring | Bootstrap/registrar | registrar por instancia | bootstrap | ambiguo |

## 27. Riesgos

| Riesgo | Nivel | Control requerido |
|---|---|---|
| doble registro de hooks | alto | bootstrap idempotente global |
| doble scheduling | crítico | una rama y coordinator idempotente |
| autoridad híbrida | crítico | A3 en A6–A8 |
| snapshot inconsistente | alto | A5 única invocación |
| dependencia circular | medio | composition root por interfaces |
| construcción prematura | medio | lectura lazy A2.1 |
| Action Scheduler ausente | alto | cierre unavailable, sin fallback |
| bootstrap repetido | alto | guardia normativa |
| tests sensibles a Git | medio | allowlist local, no inventarios globales |
| rollback incorrecto | crítico | durable permanente |
| contaminación entre requests | alto | servicios stateless y resultados locales |
| upgrades previos | alto | A3 evidencia persistida y migrations intactas |

## 28. Bloqueos documentales detallados

### B1. A5 con dos responsabilidades incompatibles

La especificación de composición describe un productor A5 que crea y coordina
schedules. `docs/durable-retry-production-activation-a5-normative-correction.md`
declara A5 authority-only y prohíbe scheduling. Implementar cualquiera de las
dos lecturas viola la otra. Debe corregirse la especificación de composición y
asignarse la coordinación a A10, no a A5.

### B2. Evidencia insuficiente para `coordinate()`

El método real requiere `(scheduleId, generation)`. El resultado productivo A5
no cierra cómo obtener esos valores en todas las ramas durables ni qué hacer
con una fila existente en `dispatching` o `scheduled`. La norma A10 debe definir
un resultado/consulta autorizada, ownership y presupuesto exacto.

### B3. A6–A9 son precondiciones ausentes

La propia composición exige sus harnesses verdes antes de A10. El código legacy
actual no excluye autoridad durable. Conectar A5 ahora puede dejar pending
legacy y durable para la misma identidad. Deben implementarse en orden.

### B4. Firma del punto de producción

El materializador instancia el scheduler legacy directamente. No existe puerto
inyectable, resultado del routing ni regla cerrada para `scheduled_for`. La
corrección A10 debe fijar ruta, FQCN, constructor, método y timestamp.

### B5. Idempotencia del bootstrap

La guardia del registrar no cruza instancias. La norma debe autorizar una
guardia estática en Bootstrap o una instancia global única y definir conducta
ante una segunda llamada y ante excepción parcial.

### B6. Resultado operacional

No está definido si el caller devuelve, propaga o registra los cierres de A5 y
coordinator. Debe fijarse un catálogo sin fallback, junto con la política de
logs permitidos. No puede inventarse silenciosamente.

## 29. Secuencia obligatoria de futuros microhitos

1. Verificar rama, HEAD, divergencia, staging, tracked, suite y filesystem.
2. Versionar corrección normativa A10 que resuelva B1, B2, B4, B5 y B6.
3. Implementar y certificar A6.
4. Implementar y certificar A7.
5. Implementar y certificar A8.
6. Implementar y certificar A9.
7. Ejecutar nueva readiness A10 y obtener allowlist/firma definitivas.
8. Implementar solo esa allowlist.
9. Ejecutar harness funcional de 24 casos.
10. Ejecutar infraestructura y las 10 integraciones.
11. Ejecutar suite Durable Retry completa sin diagnostics.
12. Revisar diff, temporales, artifacts y staging.
13. Hacer staging selectivo y commit solo tras recertificación separada.
14. Repetir certificación posterior; no hacer push.

Detenerse ante cualquier diferencia de base, estado nuevo, SQL en wiring,
fallback legacy no explícito, hook nuevo, doble llamada, A6–A9 no verdes,
allowlist excedida o diagnostic PHP.

## 30. Criterio de aceptación

El wiring futuro solo estará completado cuando:

- la activación comience en 0;
- exista un único composition root;
- el bootstrap y los hooks sean idempotentes;
- A2–A5 conserven sus autoridades y firmas;
- legacy actúe solo con `legacy_allowed`;
- A6–A9 estén implementados y verdes;
- no exista scheduling doble;
- A4 sea el único creador de generación 1;
- wiring, policy, callback y A5 no contengan SQL;
- las cuatro integraciones de processors y las diez de wiring estén verdes;
- los 24 casos funcionales estén verdes;
- la suite completa permanezca verde;
- el commit contenga solo la allowlist recertificada;
- no se realice push.

Hasta que B1–B6 se resuelvan normativamente y A6–A9 existan, ninguna firma,
allowlist o implementación A10 puede considerarse autorizada.

**WIRING PRODUCTIVO BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**
