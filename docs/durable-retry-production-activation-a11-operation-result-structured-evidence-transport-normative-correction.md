# A11 — corrección normativa de transporte de resultado estructurado y evidencia productiva

## 1. Veredicto y alcance

Esta autoridad append-only cierra exclusivamente el contrato estructural y de transporte bloqueado. No proyecta estados productivos, no define el agregador de `A11-CON-01`, no completa las 62 invocations y no implementa PHP.

Veredicto normativo: `A11 STRUCTURED OPERATION_RESULT EVIDENCE TRANSPORT IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`.

## 2. Precedencia

Esta corrección reemplaza solamente los subcontratos `phase_request`, `phase_result` y `operation_result` de `veciahorra-a11-action-transport/v1` para ejecuciones A11 EA6 normales. Conserva las autoridades de capture, action delta, participant-action-proposal, framing, supervisor, crash, loopback, atomicidad y ownership salvo los cambios expresos de las secciones 20 a 25.

## 3. Shape vigente reconstruido

El `phase_result` v1 tiene exactamente, en este orden, `schema`, `kind`, `case_id`, `ownership_token`, `phase`, `base_snapshot_hash`, `base_action_hash`, `capture_delta`, `action_deltas`, `operation_result`, `termination`, `result_hash`.

Su `operation_result` tiene exactamente `status`, `reason_code`, `effects_started`, `result_type`, `result`. Los catálogos vigentes son `success|controlled_failure|uncertain`, `none|controlled_failure|uncertain_result` y `none|boolean|positive_int|non_empty_string`. `result` es escalar o `null`. Este shape no representa evidencia participant-local ni agregado estructurado.

## 4. Arquitectura seleccionada

Se selecciona **Arquitectura B — evidencia separada**. `productive_observations` es una colección tipada del `phase_result`; `operation_result` permanece como agregado singular y referencia por hash toda evidencia consumida.

Se rechaza Arquitectura A porque mezclar evidencia primaria con el agregado reabriría su ownership, duplicaría evidencia al comparar el resultado y acoplaría auditoría con cada schema de resultado. Queda prohibido embeber evidencia en `operation_result`, `capture_delta`, `action_deltas`, participant-action-proposal, strings, Base64, archivos, environment, memoria compartida o canales adicionales.

## 5. Decisión única de versionamiento

El schema superior nuevo es `veciahorra-a11-action-transport/v2`. Versiona conjuntamente `phase_request` y `phase_result`; `operation_result` y cada observación llevan además sub-schema obligatorio. EA6 normal exige coincidencia literal con v2. Un request o result v1 en EA6 se rechaza antes de ejecutar o integrar con `action_transport_schema_mismatch`.

V1 permanece legible únicamente para evidencia histórica y perfiles anteriores. `loopback_request` y `loopback_result` conservan v1. No existe negociación, downgrade ni aceptación por similitud.

## 6. Canonicalización común

Rige JSON UTF-8 estricto, sin BOM, una sola línea, claves en el orden publicado, arrays en orden normativo, enteros decimales, booleanos JSON, `null` JSON y strings sin normalización implícita. Se prohíben floats, exponentes, claves duplicadas, bytes inválidos y campos extra. El frame termina con un único LF y EOF. Los hashes son SHA-256 hex lowercase de los bytes JSON canónicos del objeto indicado, sin su campo hash y sin LF.

## 7. `phase_request` v2 exacto

```json
{"schema":"veciahorra-a11-action-transport/v2","kind":"phase_request","invocation_id":"a11_000000000001_fd","case_id":"A11-CON-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","timeout_seconds":10,"capture_plan":{},"input_snapshot":{},"operation":{"name":"execute_phase","parameters":{}},"participant_binding":{"participant_id":"a11p_A11-CON-01_first_delivery_publish_01_01","operation":"publish","entrypoint_id":"execute_phase","productive_sources":["initial_production_router"]},"productive_observation_plan":[{"observation_type":"initial_production_routing_result/v1","productive_source":"initial_production_router","min_count":1,"max_count":1}]}
```

Todas las claves son required. `participant_binding` es un objeto para un proceso productivo y `null` para un proceso cuyo plan exige cero observaciones. `productive_observation_plan` es una lista de cero o un elemento en el catálogo actual. `min_count` y `max_count` son enteros entre 0 y 1, con `min_count <= max_count`. Si binding es `null`, la lista debe ser vacía. Identidades y tokens deben coincidir literalmente con los catálogos y descriptor ya validados por el Coordinator.

## 8. Dos scopes de `phase_result` v2

El mismo schema tiene scopes cerrados:

- `participant`: único frame que emite cada child normal por stdout; contiene evidencia local y `operation_result:null`.
- `invocation`: único bundle canónico que construye el Coordinator en memoria tras recibir la totalidad de los frames esperados; contiene la evidencia completa y exactamente un `operation_result` no nulo. No crea otro canal ni otro frame child.

## 9. `phase_result` participant exacto

```json
{"schema":"veciahorra-a11-action-transport/v2","kind":"phase_result","scope":"participant","invocation_id":"a11_000000000001_fd","participant_id":"a11p_A11-CON-01_first_delivery_publish_01_01","case_id":"A11-CON-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","capture_delta":{},"action_deltas":[],"productive_observations":[],"operation_result":null,"termination":{"status":"completed","exit_code":0},"result_hash":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}
```

Las 16 claves son exactas y required, en el orden mostrado. Los subobjetos capture y action conservan sus schemas completos vigentes; `{}` solo abrevia esos subobjetos en este ejemplo conceptual. `participant_id` es string catalogado. `productive_observations` contiene 0 o 1 elemento conforme al plan local. Un participant result válido tiene terminación completed y exit code 0. `result_hash` cubre las quince claves anteriores.

## 10. `phase_result` invocation exacto

```json
{"schema":"veciahorra-a11-action-transport/v2","kind":"phase_result","scope":"invocation","invocation_id":"a11_000000000001_fd","participant_id":null,"case_id":"A11-CON-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","capture_delta":{},"action_deltas":[],"productive_observations":[],"operation_result":{},"termination":{"status":"completed","exit_code":0},"result_hash":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"}
```

Las mismas 16 claves son exactas. `participant_id:null` expresa dominio invocation. Capture y actions son el bundle combinado por sus autoridades preexistentes. Observations contienen la unión completa ordenada. `operation_result` usa la sección 14. `result_hash` cubre las quince claves anteriores. Solo este scope entra a la transacción.

## 11. Productive observation exacta

```json
{"schema":"veciahorra-a11-productive-observation/v1","execution_id":"a11_20260803010101_1_0123456789abcdef","invocation_id":"a11_000000000001_fd","case_id":"A11-CON-01","phase":"first_delivery","participant_id":"a11p_A11-CON-01_first_delivery_publish_01_01","operation":"publish","entrypoint_id":"execute_phase","productive_source":"initial_production_router","observation_type":"initial_production_routing_result/v1","outcome_kind":"result","payload":{"schema":"initial_production_routing_result/v1","values":{"state":"example","reason":"example","reconciliation_id":null,"schedule_id":null,"generation":null,"scheduled_action_id":null,"legacy_scheduled_flag":false,"requires_intervention":false}},"observation_hash":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"}
```

Las trece claves son exactas y required. Cada binding debe igualar request, descriptor, invocation y catálogo. `execution_id` es el `ownership_token`. `outcome_kind` es `result|typed_exception`. `payload` es un record catalogado. `observation_hash` cubre las doce claves anteriores, por lo que liga evidencia a execution, invocation y participant.

## 12. Catálogo cerrado de observation types

`observation_type`, `payload.schema`, identity type, effect type, structured result schema, uncertainty code y conflict code usan tokens ASCII lowercase con segmentos `[a-z][a-z0-9_]{0,63}` separados por `/`, terminando en `v` y entero positivo. Longitud máxima 160 bytes.

El único tipo inicialmente publicado es `initial_production_routing_result/v1`. Su `values` posee exactamente, en orden, `state`, `reason`, `reconciliation_id`, `schedule_id`, `generation`, `scheduled_action_id`, `legacy_scheduled_flag`, `requires_intervention`. Los dos primeros son strings UTF-8 de 1 a 128 bytes; los cuatro IDs son `null` o enteros positivos de hasta 9,007,199,254,740,991; los dos últimos son booleanos.

Esta definición es una proyección normativa cerrada de la totalidad de los campos del DTO productivo, no serialización PHP. Preservar dos DTO significa transportar dos records completos, uno por participant, con bindings y hashes distintos. No se proyecta aquí el significado de sus valores.

Nuevos tipos solo pueden agregarse mediante corrección normativa append-only del catálogo de codecs. Un token no registrado se rechaza.

## 13. Grammar de records tipados

Cada `payload.values`, identity `values`, effect `values` y structured result `values` requiere un codec registrado que declare claves exactas, orden, tipos, nullability y límites. La grammar envolvente permite objetos, arrays, strings UTF-8, booleanos, `null` explícitamente admitido por el codec e enteros entre -9,007,199,254,740,991 y 9,007,199,254,740,991. Prohíbe floats y claves no declaradas.

Máximo por record: 64 claves, arrays de 64 elementos y profundidad relativa 4. La aceptación por grammar nunca sustituye al codec exacto; por ello no es JSON libre.

## 14. `operation_result` v2 exacto

```json
{"schema":"veciahorra-a11-operation-result/v2","status":"success","reason_code":"none","effects_started":false,"result_type":"none","result":null,"business_identity":null,"effects":[],"uncertainty":{"present":false,"code":null,"scope":null,"affected_refs":[],"requires_intervention":false},"conflicts":[],"observation_refs":[]}
```

Las once claves son exactas y required. `status` es `success|controlled_failure|uncertain`. `reason_code` es `none|controlled_failure|uncertain_result|evidence_incomplete|projection_conflict`. `effects_started` es booleano.

`result_type` es `none|boolean|positive_int|non_empty_string|structured`. Para `none`, result es `null`; para boolean es booleano; para positive_int es 1..9,007,199,254,740,991; para non_empty_string es UTF-8 de 1..1024 bytes; para structured usa `{"schema":"token/v1","values":{}}`, codec registrado, hasta 65,536 bytes y profundidad relativa 4.

`observation_refs` es la lista ordenada y sin duplicados de la totalidad de observation hashes consumidos. Debe igualar exactamente la colección del invocation scope. Cero refs solo es válido cuando el plan completo espera cero observaciones.

## 15. Business identity

`business_identity` es `null` o `{"type":"token/v1","values":{}}`. El type registra claves exactas y semántica; values sigue sección 13 y mide hasta 8,192 bytes. Este wrapper puede catalogar posteriormente reconciliation, schedule, generation y scheduled action identities sin convertir el contrato global en un map libre.

## 16. Effects

Cada effect es `{"type":"token/v1","disposition":"created","business_identity":null,"values":{},"observation_refs":[]}` con claves exactas. `disposition` es `created|existing|not_started|uncertain`. Identity sigue sección 15, values posee codec registrado y observation refs es lista ordenada no vacía, subconjunto de refs del resultado. Hay 0..32 effects; cada effect mide hasta 16,384 bytes.

Evidence registra qué observó un participant. Operation result es la conclusión singular. Effect describe impacto productivo concluido. Participant-action-proposal propone una acción externa a ejecutar o contabilizar. Ninguno se infiere del otro.

## 17. Uncertainty

Uncertainty tiene exactamente `present`, `code`, `scope`, `affected_refs`, `requires_intervention`. Si present es false, los demás son `null`, `null`, lista vacía y false. Si es true, code es token catalogado, scope es `operation|business_identity|effect|evidence`, affected refs es lista ordenada de 1..32 refs de identity types, effect ordinals u observation hashes autorizados, y requires intervention es booleano. Status debe ser uncertain.

## 18. Conflicts

Cada conflict es `{"code":"token/v1","field_path":"/business_identity/values/id","participant_ids":[],"observation_refs":[]}`. Code está catalogado por el projector; field path es JSON Pointer UTF-8 de 1..256 bytes; participant IDs y observation refs son listas ordenadas, únicas, de 2 participantes y refs pertenecientes al bundle. Hay 0..16 conflicts. Si existen, status es uncertain, reason code projection_conflict y uncertainty present es true.

El schema identifica divergencia sin definir reglas ni códigos de CON-01. El catálogo de proyección futuro publica esos códigos antes de habilitar una invocation.

## 19. Orden, unicidad y cardinalidad

Observations se ordenan por bytes de `participant_id`, luego `observation_type`, luego `observation_hash`. Este orden no concede precedencia semántica. La tupla execution, invocation, participant, source, type debe ser única.

Para el catálogo de 62 invocations vigente, `MAX_PARTICIPANTS=2` y cada participant tiene `MAX_LOCAL_OBSERVATIONS=1`; por tanto `MAX_OBSERVATIONS=2`. El plan concreto determina 0, 1 o 2 esperadas. Colección vacía es válida solo con total esperado cero. Falta, exceso, duplicado, participant ajeno, source o type incorrectos invalidan el bundle antes de proyección.

## 20. Ownership único

`OBSERVATION_OWNER=child process-local productive decorator and observation store`

`PROJECTION_OWNER=DurableRetryA11OperationResultProjectorRegistry selected projector`

`AGGREGATION_OWNER=DurableRetryA11Coordinator`

`VALIDATION_OWNER=DurableRetryA11Coordinator`

`TRANSACTION_OWNER=DurableRetryA11Coordinator`

El child solo captura, codifica, valida localmente y transporta su evidence. El dispatcher supervisa framing y proceso; no proyecta. El Coordinator posee el conjunto completo de results, valida membresía y completitud, ordena evidence, invoca exactamente un projector registrado, construye el invocation scope y lo integra atómicamente. No existe aggregate child alternativo.

## 21. Límite multiproceso

Cada child emite exactamente un `phase_result` participant scope por el stdout ya autorizado. El dispatcher entrega esos frames sin reinterpretar evidencia. El Coordinator espera el conjunto de participant IDs del invocation plan, valida cada result hash y observation hash, y solo con el conjunto completo crea el único invocation scope. No usa PID, timing, índice, proposal winner ni canal lateral.

## 22. Validación por capa

El child rechaza codec local, cardinalidad local y binding contra su request. El dispatcher rechaza tamaño, UTF-8, LF, EOF, JSON, schema, kind, hash, timeout, exit y framing. El phase bundle validator rechaza shape y reglas cruzadas de cada participant result. El Coordinator rechaza membresía, duplicados, bases distintas y completitud global. El projector registrado valida semántica de observation types y produce el único aggregate. El Coordinator valida el aggregate contra schemas, refs, límites y plan antes del commit.

## 23. Supervisor y exceptions

Crash, timeout, protocol failure, invalid framing y abnormal exit son supervisor results, nunca productive outcomes. Si cualquier participant falla, no se construye invocation `operation_result` ni se integra bundle. Frames u observations previamente válidos pueden conservarse solo en diagnóstico no autoritativo.

Así, observation A más protocol failure B, cero observations más timeout, o observations completas más framing failure producen supervisor failure y ausencia de aggregate. Un crash participant conserva su barrier arrival vigente y no produce normal `phase_result`.

Una excepción normalizada dentro de un DTO usa `outcome_kind=result`. Una excepción productiva tipada usa `outcome_kind=typed_exception` y payload codec con exactamente `exception_type`, `outcome_code`, `values`; no contiene stack trace ni mensaje libre. Un unexpected Throwable falla el child y pertenece al supervisor.

## 24. Transacción y storage

El combined state agrega `productive_observations: array` junto a `operation_result`. La integración phase futura recibe, en orden, current state, capture delta, action deltas, productive observations y operation result. Valida el bundle completo antes de asignar; luego fija ambos por igualdad estricta sobre arrays normalizados desde JSON canónico. No hay commit parcial.

El Coordinator API futuro agrega ambos argumentos contiguos a `integrateActionCapturePhaseBundle`. La transaction almacena evidence y aggregate juntos para auditoría. El loopback posterior conserva ambos sin modificarlos. `result_hash` y hashes de observation se recalculan, no se confían ciegamente.

## 25. Compatibilidades cerradas

- `capture_delta`: preservado y separado; no porta evidence.
- `action_deltas`: preservado y separado; no porta evidence.
- participant-action-proposal: preservado; se valida por su autoridad y no determina aggregate.
- phase bundle: extendido atómicamente con observations antes de operation result.
- Coordinator: único recolector, agregador, validador e integrador stateful.
- loopback: conserva v1 y su shape; no recibe productive observations.
- crash control-plane: preserva intermediate barrier y crash-arrival proposal; no emite normal phase result.
- supervisor failures: preservados fuera de operation result.

## 26. Límites exactos

`MAX_PARTICIPANTS=2`

`MAX_OBSERVATIONS=2`

`MAX_OBSERVATION_BYTES=65536`

`MAX_OPERATION_RESULT_BYTES=262144`

`MAX_PHASE_RESULT_FRAME_BYTES=1048576`

Además rigen 65,536 bytes para structured result, 8,192 para identity, 32 effects de 16,384 cada uno y 16 conflicts. El límite de frame preexistente no aumenta. Dos observations consumen como máximo 131,072 bytes y el operation result 262,144; quedan 655,360 bytes para envelope, capture y actions. El frame limit es siempre el límite final, aunque máximos de subobjetos aislados permitan una suma mayor.

## 27. APIs normativas futuras

Estas declaraciones vivirán en `VeciAhorra\Tests\Manual\Support`, dentro del support file EA6 autorizado, sin crear un canal ni owner nuevo.

```php
final class DurableRetryA11ProductiveObservationCodecRegistry
{
    public function decodeAndValidate(string $observationType, array $payload): array;
}

interface DurableRetryA11OperationResultProjector
{
    public function project(array $invocationPlan, array $productiveObservations): array;
}

final class DurableRetryA11OperationResultProjectorRegistry
{
    public function projectorFor(string $invocationId): DurableRetryA11OperationResultProjector;
}

final class DurableRetryA11StructuredEvidenceTransportValidator
{
    public function validateParticipantPhaseResult(array $request, array $result): array;
    public function validateInvocationPhaseResult(array $invocationPlan, array $result): array;
}
```

Los constructores reciben por inyección los catálogos inmutables que usan; no descubren tipos. Las funciones retornan arrays canónicos validados. Violaciones lanzan `InvalidArgumentException`; ausencia de codec o projector lanza `LogicException` durante preflight. El projector concreto y su truth table pertenecen a la corrección posterior.

## 28. Migración normativa

| old authority | old field or shape | new authority | status |
|---|---|---|---|
| action transport v1 | phase request | action transport v2 §7 | superseded for EA6 normal |
| action transport v1 | phase result 12 keys | action transport v2 §§8-10 | superseded for EA6 normal |
| action transport v1 | scalar operation result | operation result v2 §§14-18 | superseded for EA6 normal |
| capture transport | capture delta | §§9-10 and 25 | preserved |
| action transport | action deltas | §§9-10 and 25 | preserved |
| proposal materialization | participant-action-proposal | §25 | preserved |
| phase bundle transaction | three phase values | §24 five phase values | extended |
| loopback transaction | loopback result v1 | §§5 and 25 | preserved |
| crash control-plane | barrier arrival and crash proposal | §§23 and 25 | preserved |
| supervisor authority | process failures | §23 | preserved |

## 29. Preflight y habilitación

Una invocation EA6 solo se habilita cuando su plan fija participants, observation cardinality y types; la totalidad de codecs está registrada; existe exactamente un projector; y sus result, identity, effect, uncertainty y conflict tokens están catalogados. Falta de cualquiera es preflight failure, no runtime inference.

## 30. Suficiencia y tareas posteriores

Este schema ya puede transportar dos records completos de routing, identidad tipada, effects, uncertainty, conflicts y un único aggregate verificable sin otra modificación de transporte. Una corrección posterior debe publicar la proyección de estados, reglas y códigos de `A11-CON-01`; después podrá ampliar append-only el catálogo de 62 invocations. Ninguna de esas decisiones queda anticipada aquí.

## 31. Invariantes finales

Hay un solo schema EA6 normal, un solo canal, un solo aggregate por invocation, un solo aggregation owner y un solo projector seleccionado por invocation. Evidence nunca es aggregate; effect nunca es proposal; supervisor failure nunca es productive outcome. La transacción conserva evidence y aggregate con atomicidad e igualdad estricta.
