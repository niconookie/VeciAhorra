# Diseño de resolución de procesadores durable retry

## Alcance y veredicto

Este documento define la integración futura entre `DurableRetryExecutor`,
`DurableRetryStageProcessorResolverInterface`, `DurableRetryProcessorRegistry` y
los cuatro procesadores durable retry. No autoriza wiring ni cambios de
comportamiento.

El diseño seleccionado es la **opción A**: el executor recibe un resolver
obligatorio y resuelve exactamente una vez, a partir de la etapa de la fila
autoritaria, antes de reclamarla. El executor conserva la autoridad sobre la
reclamación durable, attempts, policy, persistencia, scheduling y resultado. El
procesador ejecuta como máximo un intento funcional de su etapa.

## Arquitectura observada

La entrada del plugin crea `Application` desde `Bootstrap::boot()`.
`Application` mantiene un `Container`, registra dependencias en su constructor y
registra hooks en `run()`. El flujo legacy se conecta allí mediante
`DurableCompletionOrchestration`, que construye `DurableCompletionWorkers` y
registra cuatro hooks legacy y recuperación.

El pipeline durable retry nuevo ya dispone de:

- catálogo cerrado de cuatro etapas en `DurableRetryStage`;
- catálogo cerrado de cuatro hooks y payload `(schedule_id, generation)` en
  `DurableRetryExternalScheduleCatalog`;
- repositorio durable, policy, coordinador y adaptador de Action Scheduler;
- executor, hoy acoplado a un único `DurableRetryStageProcessorInterface`;
- cuatro adaptadores funcionales: reconciliation, business completion,
  delivery completion y fulfillment completion;
- resolver y registry inmutable con validación completa al construirlo.

No existe todavía una composición productiva de ese grafo ni un callback
registrado para los hooks de `DurableRetryExternalScheduleCatalog`. El callback
de `DurableCompletionOrchestration` pertenece al flujo legacy y no debe
reutilizarse como si ya fuera el callback nuevo.

## Autoridades y responsabilidades

### Callback

El callback sólo normaliza la identidad cerrada del hook y sus dos argumentos,
invoca una instancia compartida del executor y entrega su resultado al mecanismo
de observabilidad definido por la aplicación. No lee filas, no resuelve etapas,
no contiene ramas funcionales y no construye el grafo.

### Executor

El executor valida invocación, lee la fila autoritaria, valida generación, hook,
estado y consistencia, resuelve un procesador, reclama mediante la transición
CAS existente, construye el contexto, invoca una vez, valida el contrato del
resultado, consulta la policy, persiste terminalidad o próxima generación y
coordina el scheduler.

La tabla durable no implementa hoy una lease renovable: implementa una
reclamación `SCHEDULED -> CLAIMED` protegida por versión/CAS. En este documento,
“lease durable” significa esa autoridad de reclamación. Las leases funcionales
internas de reconciliation/completion siguen encapsuladas en las autoridades de
cada etapa; no son gestionadas por el registry y no sustituyen la reclamación
durable.

### Resolver y registry

El resolver selecciona en memoria por un valor canónico ya obtenido de la fila.
El registry valida al construirse catálogo completo, tipo, identidad, clave,
etapa declarada y duplicados. Después es inmutable. No consulta SQL, no reclama,
no incrementa attempts, no programa, no clasifica fallos funcionales y no
construye procesadores.

### Procesador

El procesador valida su contexto y adapta un único intento funcional al
`DurableRetryProcessingResult`. No elige backoff, no crea generaciones, no
programa acciones y no administra la reclamación durable. Las operaciones de
lease funcional que ya exige el servicio subyacente permanecen dentro de su
adaptación; no le otorgan autoridad sobre la fila durable.

## Secuencia exacta

1. **Callback:** recibe un hook registrado y `(schedule_id, generation)`;
   normaliza tipos y forma con el catálogo y llama al executor.
2. **Executor:** valida hook, identificadores y generación sin mutación.
3. **Repository/executor:** `findById()` obtiene el snapshot autoritario.
4. **Executor:** valida existencia, generación, correspondencia hook-etapa,
   estado `SCHEDULED` y `scheduled_action_id`. Una etapa corrupta debe haber
   fallado ya al hidratar el snapshot.
5. **Resolver/registry:** resuelve una sola vez `snapshot->stage()`.
6. **Executor:** verifica defensivamente
   `processor->stage() === snapshot->stage()`.
7. **Executor/repository:** obtiene hora autoritaria de la ejecución y reclama
   con la transición CAS `SCHEDULED -> CLAIMED`. Un conflicto se reclasifica
   mediante la relectura que ya existe.
8. **Executor:** calcula `expectedAttempt = attempt_number + 1` y construye
   `DurableRetryExecutionContext` desde el snapshot reclamado.
9. **Processor:** ejecuta como máximo un intento funcional y realiza las
   relecturas de su autoridad funcional necesarias para confirmar el outcome.
10. **Executor:** valida clasificación y attempt confirmado. Una excepción del
    procesador conserva la semántica actual de outcome incierto.
11. **Policy:** decide terminalidad, incertidumbre, agotamiento o próximo
    intento; sólo se consulta después de un resultado funcional válido.
12. **Repository/executor:** cierra la generación o la supersede y crea la
    siguiente. Las relecturas posteriores a conflictos de persistencia siguen
    siendo autoritarias.
13. **Coordinator/scheduler:** sólo para un retry preparado, coordina exactamente
    la acción externa de la generación sucesora.
14. **Executor/callback:** devuelve el resultado seguro. La fila deja de ocupar
    el slot al cerrar o superseder; no existe una operación separada de
    “release”. Si se pierde el CAS, el worker no procesa.

La resolución ocurre después de todas las validaciones puras del snapshot y
antes de la reclamación, del incremento lógico del attempt y de construir el
contexto. Así un error de configuración no causa efectos laterales, no exige
liberar una reclamación y no se confunde con un intento funcional. Resolver
después de reclamar dejaría una fila `CLAIMED` que requeriría una nueva
semántica de recuperación.

## Etapas y configuración inválidas

### Etapa canónica sin procesador

El registry actual exige cobertura completa, por lo que el caso normal se
detecta al construir el grafo como
`DurableRetryProcessorConfigurationException::MISSING_PROCESSOR` (o
`INCOMPLETE_REGISTRY`). La aplicación debe fallar cerradamente al registrar el
pipeline: no registra los cuatro callbacks con un grafo parcial.

Como defensa, si un resolver alternativo falla durante `resolve()`, el executor
no reclama, no consume attempt, no persiste error en la fila y no reprograma.
El error no es retryable ni terminal de negocio: requiere intervención de
configuración. Persistir una razón exigiría mutar una fila que todavía no fue
reclamada y mezclaría configuración con negocio; por tanto, **no se persiste
ningún error**. La observabilidad sólo puede exponer un código seguro como
`processor_configuration_error`, identificadores numéricos y etapa canónica,
nunca mensaje interno, clase, stack trace ni dependencias.

Existe una brecha contractual: `DurableRetryExecutionResult` no tiene un código
específico de configuración. `PROCESSOR_MISMATCH` sólo representa la defensa de
etapa declarada y `PROCESSING_CONTRACT_ERROR` presupone procesamiento. Un
microhito debe decidir explícitamente entre añadir un código cerrado de
ejecución o propagar la excepción tipada al límite del callback. No debe
reutilizarse un código engañoso. Hasta cerrar esa brecha no se habilita wiring.
La acción externa consumida no se recrea automáticamente: tras corregir el
despliegue, una recuperación operativa explícita debe reconciliar la fila
`SCHEDULED`. Esto evita un retry infinito.

### Distinciones cerradas

| Caso | Autoridad y momento | Respuesta |
| --- | --- | --- |
| Registry incompleto | Constructor del registry | Excepción `MISSING_PROCESSOR` o `INCOMPLETE_REGISTRY`; no se registra el pipeline. |
| Duplicado de instancia o etapa | Constructor del registry | `DUPLICATE_PROCESSOR`; falla el grafo. |
| Mapa cuya clave difiere de `stage()` | Constructor del registry | `PROCESSOR_STAGE_MISMATCH`; falla el grafo. |
| Etapa canónica no resuelta | Resolver, antes del claim | Excepción tipada/configuración; sin attempt, mutación ni retry automático. |
| Valor desconocido o corrupto | `DurableRetryScheduleSnapshot`/repositorio al hidratar | Error de persistencia o inconsistencia, intervención; el resolver no lo “arregla”. |
| Procesador resuelto declara otra etapa | Executor, antes del claim | `PROCESSOR_MISMATCH`; sin attempt ni mutación. |

## Matriz de errores

“Attempt” significa intento funcional confirmado, no mera entrada al callback.

| Caso | Detecta | Resultado/failure | Attempt | Mutación y scheduling | Estado | Mensaje/prueba |
| --- | --- | --- | --- | --- | --- | --- |
| Configuración inválida al construir registry | Registry/composition root | `DurableRetryProcessorConfigurationException` existente | No | Ninguna; no registrar | Sin cambio | Razón cerrada; tests de registry y arranque fallido |
| Etapa canónica no registrada al resolver | Resolver/executor | Brecha de código seguro de ejecución; excepción tipada existente | No | Ninguna; no reprogramar | `SCHEDULED`, intervención | `processor_configuration_error`; test resolver que lanza |
| Etapa corrupta | Snapshot/repository | `PERSISTENCE_ERROR` o `DURABLE_INCONSISTENCY` existente | No | Ninguna | Sin cambio, intervención | Sin valor crudo; test de hidratación corrupta |
| Excepción de infraestructura al resolver | Executor | Misma brecha, diferenciada de fallo funcional | No | Ninguna; no retry automático | Sin cambio, intervención | Código seguro; test de resolver explosivo |
| Processor de etapa distinta | Executor | `PROCESSOR_MISMATCH` existente | No | Ninguna | `SCHEDULED` | Código cerrado; test aislado |
| Excepción del processor | Executor | `OUTCOME_UNCERTAIN` existente | Sólo si el processor confirma número; si no, nullable | Cierra `ORPHANED` según policy actual | Terminal/intervención | Razón técnica cerrada; test existente más resolver |
| Resultado funcional retryable | Processor/policy | `RETRYABLE_FAILURE` existente | Sí | Supersede, crea y coordina sucesora | Pendiente o agotado | Failure code cerrado; tests policy/executor |
| Resultado funcional terminal | Processor/policy | `TERMINAL_FAILURE` existente | Sí | Cierra `FAILED`, sin scheduler | Terminal | Failure code cerrado; tests por etapa |
| Outcome incierto sin attempt | Processor/executor | `OUTCOME_UNCERTAIN` con attempt `null` | No confirmado | Cierra `ORPHANED`, no retry | Terminal/intervención | `technical_outcome_uncertain`; tests nullable |
| Pérdida de claim durable | Repository/executor | `ALREADY_CLAIMED`, `CLAIM_CONFLICT` o estado actual | No | Sólo relectura | Autoritario | Código cerrado; carrera de dos workers |
| Pérdida de lease funcional | Processor/autoridad funcional | Clasificación confirmada por relectura | Según attempt confirmado | Policy sólo con resultado válido | Según policy | Nunca token/owner; tests de cada processor |
| Error al persistir resultado | Repository/executor | `PERSISTENCE_ERROR` o `DURABLE_INCONSISTENCY` | Puede estar procesado | No fingir cierre ni programar | Autoritario/intervención | Código cerrado; test de transición fallida |
| Error al programar sucesor | Coordinator/executor | `RETRY_PREPARED` o `COORDINATION_ERROR` existente | Sí | Sucesor durable permanece preparado | Pendiente/intervención | Sin excepción interna; tests coordinador |

## Composition root futuro

`Application` es el composition root global observado, pero no debe acumular la
construcción detallada. Se propone una clase de composición/registro del módulo
Orders, por ejemplo `DurableRetryOrchestration`, construida por el `Container` y
llamada una vez desde `Application::run()`. Esa clase recibe el executor ya
compuesto y registra los cuatro hooks del catálogo sobre un único callback
delgado.

En las factories de `Application`/módulo se construyen repositorio, policy,
adaptador, coordinador y las autoridades funcionales; luego exactamente una
instancia de cada processor; con las cuatro se construye un único
`DurableRetryProcessorRegistry`; el mismo resolver se inyecta en un único
executor por request; y ese executor se comparte entre los cuatro callbacks.
El registry es un singleton lógico dentro del grafo de la request de WordPress,
no un singleton global ni una instancia por invocación. El callback no usa
service locator ni construye dependencias.

Esto evita grafos por etapa, dependencias circulares, llamadas estáticas,
reflexión y construcción tardía. La selección sigue siendo sólo por constantes
de `DurableRetryStage`, nunca por input de clase.

## Cambio contractual mínimo del executor

El constructor actual recibe, en orden: repository, policy, coordinator, un
`DurableRetryStageProcessorInterface` y `Closure $utcNow`. El cambio mínimo
futuro sustituye el cuarto argumento obligatorio por
`DurableRetryStageProcessorResolverInterface $processorResolver`, conservando
su posición antes de `$utcNow`. No se añade `null`, overload, registry por
defecto ni compatibilidad silenciosa.

Todas las instanciaciones en pruebas manuales del executor y las cuatro pruebas
de integración executor-processor deben proporcionar un resolver/registry o un
stub explícito. Las pruebas aisladas de cada processor no cambian. El wiring
productivo sólo se modifica en microhitos posteriores.

## Alternativas

| Criterio | A: resolver en executor | B: resolver en callback | C: executor por etapa/factory |
| --- | --- | --- | --- |
| Cohesión | Alta: etapa, claim y attempt quedan coordinados | Baja: el callback conoce dominio | Fragmenta una autoridad única |
| Acoplamiento | A una interfaz cerrada | Callback acoplado a snapshot/registry | Factory acoplada a cuatro grafos |
| Testabilidad | Stub de resolver y carreras en un sujeto | Requiere probar lógica duplicada en callbacks | Multiplica suites y fixtures |
| Claim/attempt | Orden único antes del CAS | Riesgo de leer/resolver fuera de la autoridad | Riesgo de semánticas divergentes |
| Error de configuración | Un límite consistente | Cada callback debe clasificarlo | Puede fallar sólo una etapa |
| Composition root | Un registry y un executor | Callback recibe más dependencias | Cuatro executors/factory |
| Autoridades duplicadas | No | Callback invade al executor | Alta |
| Impacto en pruebas | Constructor y casos de resolución | Callback más executor | Cuatro grupos de tests |
| Extensión controlada | Catálogo + registry explícito | Fácil añadir ramas en callback | Fácil divergir por etapa |

Se selecciona A. B se descarta porque obliga al callback a conocer la fila o a
aceptar una etapa externa no autoritaria. C se descarta porque duplica
repository, policy, coordinación y semántica de claim, además de incentivar
ramas por etapa.

## Invariantes

- Una etapa se resuelve exactamente una vez por ejecución elegible.
- Un processor ejecuta como máximo un intento funcional.
- El registry es inmutable, no muta estado, no consulta SQL y no programa.
- El processor no administra el claim durable ni el backoff.
- Las leases funcionales permanecen bajo sus autoridades funcionales existentes.
- El executor no contiene ramas por etapa.
- No existe processor por defecto ni fallback.
- Los cuatro processors se registran explícitamente.
- Duplicados y configuración incompleta fallan al construir el grafo.
- Resolver no reclama ni incrementa attempts.
- Un error de configuración no genera retry automático ni infinito.
- Ninguna excepción, stack trace, clase, token, owner o payload interno se
  persiste.
- El callback no contiene lógica funcional.
- Sólo la etapa de la fila autoritaria selecciona processor.
- Resolver y processor no pueden programar ni crear generaciones.

## Presupuesto operativo

La resolución agrega cero consultas SQL, cero claims y cero relecturas: una
búsqueda en un array de cuatro elementos. Se mantiene una lectura inicial, un
CAS de claim, las relecturas ya previstas ante conflicto y las relecturas
funcionales propias de cada processor. Hay una resolución y como máximo una
invocación de processor.

Un éxito o terminalidad no llama al scheduler. Un retry llama una vez al
coordinador después de crear la sucesora; sus comprobaciones idempotentes se
mantienen. Por request se instancian una vez cuatro processors, un registry, un
executor y sus dependencias compartidas, no un grafo por callback ni por etapa.

## Concurrencia

- Dos workers leen la misma fila: ambos pueden resolver en memoria, pero sólo
  uno gana el CAS. El perdedor relee y no invoca processor.
- Pérdida de autoridad entre resolución y claim: el CAS decide; resolver no
  concede autoridad.
- Pérdida durante procesamiento: el claim durable ya quedó registrado; la
  autoridad funcional y su relectura clasifican lease perdida/outcome. El
  registry no interviene.
- Resolución correcta y persistencia posterior fallida: no se vuelve a resolver
  ni procesar; se devuelve error/inconsistencia con intervención.
- Dos procesos con configuración diferente: cada registry valida su grafo. Un
  despliegue parcial sin las cuatro etapas debe fallar al componer y no registrar
  callbacks; no hay negociación distribuida.
- Una versión vieja que ya registró una acción puede consumirla sin procesar si
  su grafo falla. La fila queda para reconciliación operativa tras completar el
  despliegue, nunca para un loop automático de configuración.

El mecanismo autoritario sigue siendo el snapshot versionado y sus transiciones
CAS, junto con las autoridades funcionales de cada etapa. El registry no
resuelve carreras, expiraciones, recuperación ni consistencia distribuida.

## Seguridad y límites

El diseño no expone ni deriva nombres de clase, no usa reflexión, callbacks
externos, filtros para sustituir processors, contenedores globales ni mutación
post-construcción. Hook y etapa pertenecen a catálogos cerrados; el input sólo
contiene identificadores enteros y nunca selecciona una clase.

No se agregan REST, administración, CLI, UI, schema, migraciones, hooks, estados
de negocio, backoff, loops, sleeps ni procesamiento múltiple. No se integran
servicios ajenos a durable retry. Observabilidad usa códigos cerrados y datos
mínimos; jamás stack traces o mensajes de excepciones persistidos.

## Secuencia propuesta de microhitos

### 1. Contrato del executor

- Permitidos: executor y sus pruebas unitarias/manuales directas.
- Prohibidos: callback, bootstrap/Application, registry, processors, schema.
- Pruebas: resolver invocado una vez, orden antes de CAS, mismatch y carreras.
- Riesgo: alterar semántica nullable-attempt.
- Detención: cualquier mutación antes de resolver o regresión durable.
- Commit: `refactor(orders): inject durable retry processor resolver`.
- Wiring productivo: no.

### 2. Fallo cerrado de configuración

- Permitidos: resultado/contrato mínimo que cierre la brecha, executor y tests.
- Prohibidos: composición, scheduler, processors, schema.
- Pruebas: missing/unknown/explosive resolver; cero claim, attempt y scheduling.
- Riesgo: confundir configuración con fallo funcional.
- Detención: retry automático o persistencia de excepción.
- Commit: `fix(orders): fail closed on durable retry resolution`.
- Wiring productivo: no.

### 3. Registry en composition root

- Permitidos: bindings/factory modular y tests de composición sin hooks.
- Prohibidos: callback productivo, scheduler, lógica de processors y schema.
- Pruebas: cuatro instancias explícitas, singleton lógico, fallo incompleto.
- Riesgo: service locator o dependencia circular.
- Detención: construcción tardía o grafo por etapa.
- Commit: `refactor(orders): compose durable retry processor registry`.
- Wiring productivo: composición solamente; hooks aún ausentes.

### 4. Wiring de cuatro processors

- Permitidos: factories/bindings de autoridades existentes y tests de grafo.
- Prohibidos: lógica interna de processors, callback, schema y backoff.
- Pruebas: identidad de cada stage y dependencias reales resolubles.
- Riesgo: duplicar repositorios o adaptadores.
- Detención: cualquier dependencia implícita/global.
- Commit: `refactor(orders): wire durable retry stage processors`.
- Wiring productivo: grafo completo, todavía no invocable por hooks.

### 5. Callback y scheduler

- Permitidos: orchestration/callback dedicado, registro en `Application::run()`
  y pruebas de integración de hooks existentes.
- Prohibidos: nuevos hooks, lógica funcional, schema, UI/REST/CLI.
- Pruebas: payload cerrado, cuatro hooks al mismo executor, una invocación.
- Riesgo: coexistencia o doble consumo con orchestration legacy.
- Detención: hook nuevo, construcción en callback o doble registro.
- Commit: `feat(orders): register durable retry execution callbacks`.
- Wiring productivo: sí, por primera vez.

### 6. Regresión durable completa

- Permitidos: sólo correcciones mínimas demostradas por fallos; idealmente cero
  cambios.
- Prohibidos: expansión funcional o refactor oportunista.
- Pruebas: suite durable completa, negativas de concurrencia/configuración y
  convivencia legacy.
- Riesgo: ocultar un fallo ampliando allowlists.
- Detención: cualquier fallo no explicado o diff no selectivo.
- Commit: sólo si existe corrección aislada; nunca agruparla con wiring.
- Wiring productivo: ya presente, sin ampliación.

### 7. Certificación documental final

- Permitidos: documento de certificación específico.
- Prohibidos: todo PHP, tests, schema y configuración.
- Pruebas: suite durable, lint aplicable, `diff --check`, inspección de commit.
- Riesgo: documentar un comportamiento distinto del certificado.
- Detención: árbol tracked sucio o suite incompleta.
- Commit: `docs(orders): certify durable retry processor wiring`.
- Wiring productivo: no cambia.

Cada microhito requiere staging selectivo y commit propio. La integración no debe
colapsarse en un solo commit.
