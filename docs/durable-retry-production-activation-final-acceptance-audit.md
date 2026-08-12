# Auditoría final de aceptación productiva end-to-end de Durable Retry

## 1. Veredicto

**DURABLE RETRY PRODUCTIVO BLOQUEADO**

La implementación versionada conecta A1–A10 de forma coherente y la suite está
verde, pero la evidencia disponible no demuestra el comportamiento productivo
end-to-end con WordPress, WooCommerce, Action Scheduler y procesos concurrentes
reales. En particular, los harnesses A10 llamados `integration` y `concurrency`
son guardias de fuente/reflection; no ejecutan el flujo que sus nombres sugieren.

Bloqueadores: **3**. Riesgos no bloqueantes: **4**.

## 2. Base certificada

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `33b6e5a11384ca1ce6a2e3f08b77f7e98333d0cf` |
| Commit | `feat(orders): wire durable retry production activation` |
| Divergencia | `0 behind / 56 ahead` |
| Staging | vacío |
| Cambios tracked | 0 |
| Suite | `76/76`, 121 casos, 5.999 assertions |
| Diagnostics | 0 |
| `artifacts/` | 504 archivos |

## 3. Hallazgos priorizados

### B1 — A10 no tiene integración funcional real

`durable-retry-production-direct-wiring-integration-test.php:14-27` solo usa
`ReflectionMethod` y búsquedas de texto. No materializa evidencia controlada, no
invoca el router real A8 y no observa A5–A7, legacy ni persistencia. El harness
directo (`durable-retry-production-direct-wiring-test.php:9-43`) verifica texto,
no los once resultados durante ejecución. La afirmación end-to-end de A10 no está
demostrada funcionalmente.

### B2 — La concurrencia A10 no se ejecuta

`durable-retry-production-direct-concurrency-test.php:5-17` lee dos archivos y
aplica expresiones regulares. No crea dos conexiones MySQL, procesos, requests,
publicaciones ni callbacks. Por ello no prueba dos retornos simultáneos, dos
generation 1, callback/legacy concurrentes ni caídas entre efectos externos y
persistencia local.

### B3 — Falta aceptación con infraestructura productiva

Hay pruebas MySQL reales de repositorios y un harness de adapter denominado
`real`, pero no existe una prueba integrada con bootstrap WordPress real,
WooCommerce real, Action Scheduler real, MySQL real y callbacks repetidos en
procesos independientes. La suite no demuestra que el registro efectivo del hook,
la creación/consulta/cancelación de acciones y el claim durable converjan juntos.

### R1 — Excepción inesperada posterior a persistencia

`WebpayReconciliationMaterializer.php:246-249` no captura una excepción del
router. La reconciliation ya existe, pero la excepción se propaga por
`WebpayReturnService::finalize()` y puede transformar un retorno Webpay ya
persistido en error observable HTTP. Un reintento converge por fingerprint y
publica otra vez. A5–A7 están diseñados para converger, pero no existe prueba HTTP
real de esta secuencia. Riesgo no bloqueante de diseño; bloqueante solo como falta
de evidencia, ya contabilizada en B1/B3.

### R2 — Guardia global de hooks por proceso

`Application.php:105,401-407,573-576` evita doble registro dentro de un proceso,
no entre procesos PHP (donde cada proceso debe registrar sus hooks). Es correcto
para WordPress, pero no está probado con dos instancias reales y fallo parcial de
registro.

### R3 — Ventana externo/local de A7

`DurableRetryExternalScheduleCoordinator.php:99-161` agenda externamente antes
de asociar el ID local. Compensa y clasifica incertidumbre, pero una caída dura
entre ambos pasos no puede ejecutar compensación en ese proceso. `findPending()`
y las claves de identidad ofrecen convergencia; falta crash testing real.

### R4 — Workers legacy siguen registrados

`DurableCompletionOrchestration.php:12-17` registra workers históricos. La
exclusión depende de la autoridad A3–A8 y no de su desaparición física. Es una
convivencia intencional, pero requiere prueba real legacy/durable concurrente.

## 4. Trazabilidad productiva end-to-end

| Transición | Responsable | Máximo | Entrada/derivación | Efectos y excepciones | Evidencia / riesgo |
|---|---|---:|---|---|---|
| Bootstrap → A9 | `Application::__construct()` → `registerDurableRetryGraph()` (`Application.php:181-225`) | 1 por Application | `$wpdb`, reader WP, scheduler externo singleton, legacy, reloj UTC | Compone antes del materializer; `wpdb`/composición fallan cerradamente | A9 composition; A10 bootstrap solo estructural |
| A9 → A8 | `DurableRetryProductionComposition::router()` (`:43-100`) | 1 construcción estable | A2.1, A2, repos A3/A4, A5–A7 | Publica router al completar; limpia tras fallo | harnesses A9 funcionales e integración |
| A8 → materializer | singleton de `Application.php:220-227` | 1 identidad por Application | router almacenado `===` transitivamente | Sin locator/fallback | A10 reflection; identidad productiva inferida |
| Persistencia → publicación | `materialize()`/`resume()` → `publishRetryAuthorityCandidate()` (`WebpayReconciliationMaterializer.php:122-129,209-221`) | 1 por retorno no nulo | DTO con reconciliation ID | Persistencia precede publicación; fallo se propaga sin rollback | orden estructural; falta inyección funcional A10 |
| Publicación → A8 | `publishRetryAuthorityCandidate()` (`:224-263`) | 1 | ID del DTO, una captura `gmdate`, UTC sin micros | Once estados descartados; timestamp imposible lanza `LogicException` | 16 assertions textuales |
| A8 → A5 | `DurableRetryInitialProductionRouter::routeReconciliation()` (`:27-45`) | 1 | identity reconciliation + instante | Excepción operacional → `dependency_failure` | router test/integration |
| A5 → legacy/A6 | router (`:47-85`) | 1 | autoridad tipada | Legacy solo si permitido; cierre sin fallback | A5/A8 harnesses |
| A6 → A7 | router (`:87-105`) | 1 si durable | resolución tipada | resolución fallida cierra; excepción → dependency | A6/A8 harnesses |
| A7 → Action Scheduler | coordinator + adapter | 1 intento externo | schedule/generation/hook/args/UTC | CAS, find pending, asociación, compensación | fixtures; adapter parcial/real aislado |
| Callback → executor | registrar/callback (`DurableRetryActionHookRegistrar.php:21-43`; callback `:19-50`) | 1 delegación por entrega | hook, schedule_id, generation | payload inválido lanza; sin SQL/scheduling previo | callback/registrar harnesses |
| Executor → processor | `DurableRetryExecutor::execute()` (`:37-130`) | 1 claim y 1 process | snapshot autoritativo y contexto | CAS perdido se clasifica; excepción del processor → uncertain | executor + cuatro integraciones |
| Processor → persistencia | processors de cuatro stages | 1 intento funcional | subject/completion/generation/attempt | success/retryable/terminal/uncertain tipados | processors e integraciones |
| Retryable → siguiente generation | executor `:179-228`, repository `:316-470` | 1 sucesor | generación/attempt +1 | transacción, supersede, insert único, rollback en fallo | next-generation unit/MySQL |
| Terminal | executor `close()` (`:231-282`) | 1 CAS | claimed snapshot | consumed/failed/orphaned; repetición lee estado | executor harnesses |

Efectos prohibidos a lo largo de la cadena: hooks iniciales, doble composición,
service locator en materializer, SQL A10 para reconstruir identidad, fallback
legacy después de una decisión durable, loops/sleeps locales y segunda decisión
de scheduling por publicación.

## 5. Bootstrap y construcciones

La búsqueda productiva encuentra una sola construcción A9 en
`Application.php:211`, una sola llamada `$composition->router()` en `:218` y un
solo `new WebpayReconciliationMaterializer` en la factory singleton `:222`.
`DurableRetryInitialProductionRouter` se construye únicamente dentro de A9
(`DurableRetryProductionComposition.php:91`). `WebpayReturnService` se resuelve
por autowiring; `WebpayReturnRecovery` se crea en `Application.php:416` con el
singleton.

En pruebas hay construcciones adicionales deliberadas de A9/A8 y numerosos
`WebpayReturnService`; son fixtures/doubles. Los cuatro harnesses históricos
obtienen el materializer desde `Application`. No existe construcción productiva
alternativa, registrar inicial, `do_action()` inicial ni fallback nullable.

`registerDurableRetryGraph()` se llama al final del constructor, después de
bindings generales (`Application.php:181`). Su primera guardia (`:186-188`) hace
una segunda llamada no-op. El router se asigna antes de registrar la factory del
materializer, impidiendo resolverlo sin A8 en una Application construida.

## 6. Frontera de publicación

Los dos caminos no nulos construyen primero `MaterializedReconciliation` y luego
publican exactamente una vez. Los fallos de validación, materialización o retorno
`null` no alcanzan publicación. El ID proviene exclusivamente de
`reconciliationId()`; las consultas `originId()` y `returnId()` son anteriores y
no reconstruyen esa identidad.

El método captura una vez `gmdate('Y-m-d H:i:s')`, parsea con formato estricto y
UTC, comprueba round-trip, nombre de timezone, offset cero y microsegundos cero.
Llama una vez a A8 y enumera once constantes sin `default`. No hay rollback,
scheduling adicional, log, métrica o hook.

Si A8 lanzara inesperadamente, la excepción atraviesa materializer, service y
controller. La evidencia funcional permanece, pero el caller puede observar un
error y repetir. La repetición encuentra fingerprint/reconciliation existente y
vuelve a publicar; la convergencia durable es una propiedad de A3–A7, no una
garantía HTTP demostrada por A10.

## 7. Matriz cerrada de entradas productivas

| Entrada | Materializa/publica | Máximo por ejecución | Convergencia | Cobertura |
|---|---|---:|---|---|
| REST retorno Webpay nuevo | `finalize()` → `materialize()` | 1 | claim inbox + fingerprint | foundation/rest; infraestructura real parcial |
| Retorno repetido/completado | `repeated()` → `resume()` y, si falta, `materialize()` | resume 1 + materialize 1 condicionado | estado almacenado y fingerprint | foundation/histórico |
| Reejecución HTTP tras fallo técnico | vuelve a `process()` | 1 publicación si finaliza | `WebpayReturnRepository::retry()` | foundation; no A8-exception real |
| WooCommerce return | mismo controller/service con origin durable | 1 | origin/token/fingerprint | WooCommerce histórico con WP/MySQL |
| Backend public payment | mismo service | 1 | session/origin | histórico backend |
| Recovery programado | `WebpayReturnRecovery::recover()` → `resume()` | 1 por fila, hasta 100 | left join sin reconciliation + fingerprint | histórico; scheduler real no integrado |
| Callback durable repetido | registrar → executor | 0 materialización; 1 execute | status/generation/CAS | callback/executor fixtures |
| Worker legacy | orchestration histórica | 0 publicación A10 | autoridad debe impedir convivencia | cobertura aislada; carrera real pendiente |

No se encontró otra ruta productiva que construya el materializer o publique A8.

## 8. Exclusión y convivencia legacy

Referencias productivas relevantes:

- `scheduleReconciliation`: puerto, `DurableCompletionScheduler.php:29` y única
  llamada decisoria en router `DurableRetryInitialProductionRouter.php:53`;
- `DurableCompletionScheduler::reconciliation()` permanece para orchestration
  histórica, pero el materializer ya no lo llama;
- scheduler externo: `ActionSchedulerDurableRetryAdapter.php:15-72`;
- hook durable: catálogo `DurableRetryExternalScheduleCatalog.php:14`, registro
  genérico único en registrar `:28-42`;
- `as_schedule_single_action`: legacy scheduler, adapter durable y recovery de
  creación Webpay; son hooks/grupos distintos;
- `wp_schedule_single_event`, `wp_schedule_event` y `do_action`: cero referencias
  productivas pertinentes;
- `add_action`: múltiples registros WordPress legítimos; durable efectivo se
  centraliza en el registrar.

A3/A4 y los índices únicos `(stage,subject_id,generation)` y
`(stage,subject_id,active_slot)` (`DurableRetryScheduleSchema.php:39-47`) impiden
dos autoridades durable activas y dos generation equivalentes. Executor rechaza
generación vieja, status no elegible y hook distinto. No obstante, la exclusión
simultánea frente a un worker legacy ya iniciado no ha sido ejecutada con dos
procesos; permanece B2/B3.

## 9. Callback durable

Existe una registración productiva efectiva por proceso: `Application::run()`
resuelve un singleton registrar y la guardia estática global impide una segunda
registración. El registrar además tiene guardia propia. Usa prioridad 10, dos
argumentos y payload posicional `(schedule_id,generation)`. Callback normaliza
identidad y delega una vez al executor, sin SQL o scheduling previo.

Dos entregas de la misma acción compiten por transición `pending → claimed`; una
gana, la otra clasifica pérdida de claim o estado ya cerrado. Acción consumida o
generación vieja no repite el intento funcional. Argumentos inválidos producen
`InvalidArgumentException`. Esta conducta está probada con doubles/CAS, no con
redelivery real de Action Scheduler.

## 10. Executor y cuatro procesadores

| Stage | `subject_id` / `completion_id` | Claim e intento | Cierres |
|---|---|---|---|
| reconciliation | reconciliation ID / nullable según snapshot | claim schedule + lease de reconciliation; un `process()` | consumed, retryable, failed u orphaned |
| business_completion | business completion identity | claim schedule + processor con lease 30 s; un intento | success/retryable/terminal/uncertain |
| delivery_completion | delivery completion identity | claim schedule + lease 600 s; un intento | success/retryable/terminal/uncertain |
| fulfillment_completion | fulfillment completion identity | claim schedule + lease 600 s; un intento | success/retryable/terminal/uncertain |

El contexto lleva schedule ID, stage, subject ID, completion ID, generation,
attempt number, dispatch hash y claimed-at. Cada processor relee evidencia
autoritativa después del intento y solo confirma un número de intento coherente.
Excepciones se convierten en outcome uncertain. Policy produce terminal,
uncertain o retry; el executor persiste consumed/failed/orphaned o crea una sola
generation siguiente mediante transacción y CAS.

Los estados `pending`, `claimed`, `consumed`, `superseded`, `cancelled`, `failed`
y `orphaned` tienen clasificación productiva. `claimed` abandonado depende del
lease/recuperación del dominio funcional; no hay loop ni sleep en Durable Retry.

## 11. Matriz de carreras

| Carrera | Autoridad/CAS y máximo | Resultado | Evidencia / riesgo |
|---|---|---|---|
| 1. Dos retornos simultáneos | claim inbox + fingerprint; 1 evidence/reconciliation | repetido converge | MySQL histórico indirecto; proceso real pendiente |
| 2. Dos materializaciones | unique fingerprint + `DuplicateReconciliation` | mismo ID | repos real; A10 concurrente no ejecutado |
| 3. Dos publicaciones | A3/A4 + active slot; 1 autoridad | durable/legacy cerrado | fixtures; B2 |
| 4. Worker legacy vs durable | transferencia A4 | una autoridad | no carrera multiproceso |
| 5. Callback durable vs legacy | autoridad + claims funcionales | uno debe actuar | no carrera multiproceso |
| 6. Dos callbacks mismo schedule | version CAS pending→claimed | 1 intento | executor doubles/MySQL repo |
| 7. Callback generation vieja | generation/status check | stale_generation | executor test |
| 8. Acción externa sin asociación | findPending/compensate | synchronized o uncertain | coordinator fixtures; crash real pendiente |
| 9. Commit local incierto tras externo | reread/CAS/compensate | uncertain, sin legacy | coordinator fixtures |
| 10. HTTP retry tras persistencia y excepción A8 | fingerprint + nueva publicación | evidencia estable; routing converge | no test funcional, R1 |
| 11. Duplicate key generation 1 | índices únicos + clasificación collision | una activa | repos MySQL |
| 12. Acción pending preexistente | `findPending()` | asociación convergente | adapter/coordinator fixtures |
| 13. Cancelación vs ejecución | provider lookup + CAS local | cancel o ejecución reclamada | no integración real |
| 14. Caída tras claim | lease/estado claimed | no doble claim inmediato | recovery E2E no demostrado |
| 15. Caída tras intento antes de persistir | relectura de evidencia en processor | success confirmado o uncertain | unit/integration, no kill process |

## 12. Consistencia A1–A10

| Microhito | Contrato | Implementación productiva | Evidencia | Desviación |
|---|---|---|---|---|
| A1 | identidades/resultados cerrados | dominio DurableRetry | contratos | ninguna |
| A2 | flag determinista | activation policy | policy/vectors | ninguna |
| A2.1 | opción WP única | WordPress option reader/source | source + WP | ninguna |
| A3 | legacy/durable/indeterminate | legacy authority repository | unit/MySQL | ninguna |
| A4 | transferencia exclusiva | initial transfer repository/authority | unit/MySQL | ninguna |
| A5 | producción autoridad | initial authority producer | unit/infra | ninguna |
| A6 | resolución | initial schedule resolver | unit/infra | ninguna |
| A7 | coordinación externa | coordinator + adapter | fixtures/adapter aislado | falta crash E2E |
| A8 | routing único | initial production router | unit/integration | ninguna contractual |
| A9 | grafo estable | production composition | unit/integration | ninguna |
| A10 | wiring directo post-persistencia | Application + materializer | textual/reflection | B1/B2/B3 |

La corrección normativa A10 reemplazó explícitamente el modelo inicial por action;
no existe contradicción vigente entre microhitos.

## 13. Inventario y calidad de pruebas

Los 76 harnesses se clasifican así (un harness puede aportar a más de una capa):

- dominio/contratos/configuración: `activation-authority-contract`,
  `activation-contract-infrastructure`, `activation-transfer-contract`, los seis
  `activation-configuration/flag-policy*`, `schedule-domain`, `processing-policy*`
  y `processing-nullable-attempt*`;
- autoridad/transferencia: `legacy-authority*`, `initial-transfer-authority*`,
  `initial-authority-producer*`;
- resolución/coordinación/routing: `initial-schedule-resolver*`,
  `initial-schedule-coordinator*`, `initial-production-router*`,
  `external-schedule-coordinator*`, `external-scheduler*`;
- composición/wiring: `composition*`, `production-composition*` y los cinco
  `production-direct-*`;
- callback/executor: cuatro `action-callback/action-hook-registrar*`, cuatro
  `executor*` y cuatro `*-executor-integration`;
- procesadores: pares `*-processor-test`/`*-processor-infrastructure` para
  reconciliation, business, delivery y fulfillment, más registry;
- persistencia/MySQL: `schedule-repository*`, `schedule-schema`,
  `next-generation-repository*`, `legacy-authority-repository-mysql` e
  `initial-transfer-authority-mysql`;
- integración/infraestructura: todos los sufijos `integration`, `infrastructure`,
  `mysql`, scheduler partial/real/unavailable y los cuatro harnesses históricos
  ejecutados fuera del patrón Durable Retry.

Conductas importantes sin prueba: materializer real→A8 real con 11 estados;
bootstrap/run con WordPress+AS reales; dos procesos de publicación; legacy y
durable concurrentes; redelivery AS real; kill después de scheduling externo,
claim e intento funcional; HTTP retry tras excepción post-persistencia.

Harnesses necesarios, sin implementarlos:

1. `durable-retry-production-acceptance-wordpress-action-scheduler-test.php`;
2. `durable-retry-production-acceptance-multiprocess-test.php`;
3. `durable-retry-production-acceptance-crash-recovery-test.php`;
4. `durable-retry-production-acceptance-webpay-replay-test.php`;
5. `durable-retry-production-acceptance-legacy-exclusion-test.php`.

### 13.1 Inventario literal de los 76 harnesses

```text
durable-retry-action-callback-infrastructure-test.php
durable-retry-action-callback-test.php
durable-retry-action-hook-registrar-infrastructure-test.php
durable-retry-action-hook-registrar-test.php
durable-retry-activation-authority-contract-test.php
durable-retry-activation-configuration-source-infrastructure-test.php
durable-retry-activation-configuration-source-test.php
durable-retry-activation-configuration-wordpress-test.php
durable-retry-activation-contract-infrastructure-test.php
durable-retry-activation-flag-policy-infrastructure-test.php
durable-retry-activation-flag-policy-test.php
durable-retry-activation-flag-policy-vectors-test.php
durable-retry-activation-transfer-contract-test.php
durable-retry-business-completion-executor-integration-test.php
durable-retry-business-completion-processor-infrastructure-test.php
durable-retry-business-completion-processor-test.php
durable-retry-composition-infrastructure-test.php
durable-retry-composition-test.php
durable-retry-delivery-completion-executor-integration-test.php
durable-retry-delivery-completion-processor-infrastructure-test.php
durable-retry-delivery-completion-processor-test.php
durable-retry-executor-infrastructure-test.php
durable-retry-executor-nullable-attempt-test.php
durable-retry-executor-test.php
durable-retry-external-schedule-coordinator-infrastructure-test.php
durable-retry-external-schedule-coordinator-test.php
durable-retry-external-scheduler-infrastructure-test.php
durable-retry-external-scheduler-partial-test.php
durable-retry-external-scheduler-real-test.php
durable-retry-external-scheduler-test.php
durable-retry-external-scheduler-unavailable-test.php
durable-retry-fulfillment-executor-integration-test.php
durable-retry-fulfillment-processor-infrastructure-test.php
durable-retry-fulfillment-processor-test.php
durable-retry-initial-authority-producer-infrastructure-test.php
durable-retry-initial-authority-producer-test.php
durable-retry-initial-production-router-infrastructure-test.php
durable-retry-initial-production-router-integration-test.php
durable-retry-initial-production-router-test.php
durable-retry-initial-schedule-coordinator-infrastructure-test.php
durable-retry-initial-schedule-coordinator-integration-test.php
durable-retry-initial-schedule-coordinator-test.php
durable-retry-initial-schedule-resolver-infrastructure-test.php
durable-retry-initial-schedule-resolver-test.php
durable-retry-initial-transfer-authority-infrastructure-test.php
durable-retry-initial-transfer-authority-mysql-test.php
durable-retry-initial-transfer-authority-test.php
durable-retry-legacy-authority-infrastructure-test.php
durable-retry-legacy-authority-repository-mysql-test.php
durable-retry-legacy-authority-repository-test.php
durable-retry-next-generation-infrastructure-test.php
durable-retry-next-generation-repository-mysql-test.php
durable-retry-next-generation-repository-test.php
durable-retry-processing-nullable-attempt-infrastructure-test.php
durable-retry-processing-nullable-attempt-test.php
durable-retry-processing-policy-infrastructure-test.php
durable-retry-processing-policy-test.php
durable-retry-processor-registry-infrastructure-test.php
durable-retry-processor-registry-test.php
durable-retry-production-composition-infrastructure-test.php
durable-retry-production-composition-integration-test.php
durable-retry-production-composition-test.php
durable-retry-production-direct-bootstrap-test.php
durable-retry-production-direct-concurrency-test.php
durable-retry-production-direct-wiring-infrastructure-test.php
durable-retry-production-direct-wiring-integration-test.php
durable-retry-production-direct-wiring-test.php
durable-retry-reconciliation-executor-integration-test.php
durable-retry-reconciliation-processor-infrastructure-test.php
durable-retry-reconciliation-processor-test.php
durable-retry-schedule-domain-test.php
durable-retry-schedule-infrastructure-test.php
durable-retry-schedule-repository-infrastructure-test.php
durable-retry-schedule-repository-mysql-test.php
durable-retry-schedule-repository-test.php
durable-retry-schedule-schema-test.php
```

## 14. Infraestructura: demostrado e inferido

- Fixtures/doubles: A5–A10, estados, fallos, callback, executor, processors y A7.
- MySQL real: repositorios schedule, next generation, legacy authority e initial
  transfer; algunos flujos históricos de payment/reconciliation.
- Integración real parcial: WordPress/WooCommerce en harnesses históricos y
  adapter Action Scheduler aislado según disponibilidad.
- Inferido estructuralmente: singleton A8, ausencia de hook inicial, orden de
  publicación y catálogo A8 en A10.
- No demostrado: combinación simultánea WordPress + WooCommerce + AS + MySQL,
  HTTP repetido y concurrencia/crash en procesos independientes.

## 15. Presupuestos e invariantes

Por Application/publicación: A9 1, `router()` 1, publicación 1 por retorno no
nulo, A8 1, A5 1, A6 0–1, A7 0–1, legacy 0–1 solo autorizado, scheduling externo
0–1, callbacks 0 durante publicación. Por callback: lectura 1, claim CAS 1,
processor 0–1, intento funcional 0–1, cierre o sucesor 1. Generation creada: 0–1.
No hay transacción A10 ni rollback funcional; los SQL pertenecen a repositorios
A3–A7 y dominio funcional.

Invariantes verificables:

1. Para una identidad durable no puede existir simultáneamente una autoridad
   legacy ejecutable: diseñado por A3/A4 e índices; pendiente prueba multiproceso.
2. Una reconciliation persistida no se revierte por routing inicial: demostrado
   por orden y ausencia de transacción envolvente; pendiente observación HTTP.
3. Una publicación produce como máximo una decisión de scheduling: demostrado
   estructuralmente y en A8.
4. Una generación se consume funcionalmente una vez: claim/version CAS; probado
   en executor/repositorio, pendiente redelivery AS real.
5. Una incertidumbre no degrada a legacy: resultados cerrados A7/A8; probado con
   fixtures.

## 16. Búsquedas estructurales reproducibles

```text
rg -n "new (DurableRetryProductionComposition|DurableRetryInitialProductionRouter|WebpayReconciliationMaterializer|WebpayReturnService|WebpayReturnRecovery)" app tests/manual
rg -n "registerDurableRetryGraph|->router\(\)|routeReconciliation" app tests/manual
rg -n "scheduleReconciliation|DurableRetryLegacySchedulerInterface" app
rg -n "veciahorra_durable_retry_reconciliation|as_schedule_single_action|wp_schedule_(single_)?event|do_action\(|add_action\(" app
rg -n "\b(for|foreach|while)\s*\(|\b(sleep|usleep)\s*\(|\bretry\s*\(" app/Modules/Orders app/Modules/Payments
rg -n "durable_retry_schedules|generation|active_slot|supersed|orphan|cancel|consum" app/Modules/Orders app/Database
```

Resultados relevantes: una A9 productiva, un router productivo dentro de A9, un
materializer productivo singleton, una llamada A8 desde publicación, un puerto
legacy y una llamada decisoria desde A8, un registro durable efectivo por proceso,
Action Scheduler separado por catálogos/grupos, cero loops/sleeps A10 y tres
repositorios con acceso a `durable_retry_schedules`. Dependencias de terceros se
excluyeron porque la auditoría evalúa callers y contratos propios; su conducta
interna exige la prueba real B3.

## 17. Microhito final requerido

Se requiere **A11 — aceptación operacional y concurrencia real de Durable Retry**.
No debe cambiar producto salvo que una prueba reproduzca un defecto.

Allowlist inicialmente determinable:

```text
tests/manual/durable-retry-production-acceptance-wordpress-action-scheduler-test.php
tests/manual/durable-retry-production-acceptance-multiprocess-test.php
tests/manual/durable-retry-production-acceptance-crash-recovery-test.php
tests/manual/durable-retry-production-acceptance-webpay-replay-test.php
tests/manual/durable-retry-production-acceptance-legacy-exclusion-test.php
```

Si una falla exige producto, la auditoría debe detenerse y definir una corrección
normativa con allowlist separada; no se autoriza improvisar cambios dentro de A11.

Criterios de aceptación: flujo completo real verde; dos procesos convergentes;
cero doble scheduling/attempt/generation; redelivery idempotente; exclusión
legacy/durable observada; crashes inyectados convergentes; replay HTTP conserva
evidencia y autoridad; 0 diagnostics y suite histórica intacta.

## 18. Conclusión

La arquitectura versionada es coherente y no se identificó una contradicción
normativa o un defecto productivo confirmado. Sin embargo, tres vacíos de evidencia
impiden aceptar producción end-to-end. Cobertura demostrada: contratos, políticas,
repositorios MySQL, routing, executor y procesadores aislados. Cobertura inferida:
wiring A10, identidad singleton y orden post-persistencia. Cobertura pendiente:
infraestructura combinada, concurrencia multiproceso, crash/replay y convivencia
legacy real.

Se creó exclusivamente este documento. No se modificaron producto ni pruebas y
no se realizó commit ni push.
