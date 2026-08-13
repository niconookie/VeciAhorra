# Corrección normativa A11 del transporte compuesto de Action Capture

Estado: contrato normativo probatorio, cerrado y fail-closed. Fecha: 2026-08-03.

## 1. Objeto y veredicto de partida

Este documento resuelve exclusivamente el bloqueo `EA6 BLOQUEADO POR CONTRADICCIÓN NORMATIVA`. No implementa EA6, no modifica producto, no crea fixtures ni H1–H5 y no materializa los 372 counts.

El protocolo seleccionado es uno: un request JSON y un result JSON por proceso controlado. No quedan autorizados JSON-lines múltiples, envelopes sueltos alternativos ni transporte lateral.

## 2. Autoridades, vigencia y precedencia

Siguen vigentes sin modificación: case isolation; ownership `(execution_id, case_id)`; coordinator como única autoridad mutable; fases y snapshots S0–S4; JSON canónico y SHA-256; límites de 1,048,576 bytes y profundidad 8; timeout 1..30 segundos; catálogo EA5 de dos fases y seis puertos; counts densos; sellado; separación de `external_actions`; cleanup y cero persistencia runtime.

Se especializan para procesos Action Capture las cláusulas §8, §11, §12, §22 y §23 de `durable-retry-production-activation-a11-runtime-capture-transport-normative-correction.md`.

Se sustituyen, solo para EA6 y posteriores:

1. “stdout contiene exactamente un `capture_delta`” por “stdout contiene exactamente un `phase_result` o `loopback_result`”.
2. “el loopback no emite deltas” por “el loopback emite un único `loopback_result` que contiene evidencia y `action_delta` de `webpay.commit`”.
3. La integración separada de capture y actions por una integración transaccional de bundle.

Permanecen prohibidos: `capture_delta` o `action_delta` como envelope superior de un proceso EA6; varias líneas JSON; stderr autoritativo; archivos, DB, cache, variables de entorno, sockets laterales o pipes adicionales como transporte de captures/actions. El socket HTTP loopback es exclusivamente el puerto productivo controlado Webpay, no un canal coordinator–stub.

EA3 conserva autoridad absoluta sobre los 372 counts. EA4 conserva la secuencia EA5→EA6→EA7→EA8→EA9→EA10. EA5 conserva catálogo, mapas, hashes y validación de delta salvo la ampliación transaccional indicada en §17.

## 3. Protocolo único y roles

Cada proceso recibe exactamente un objeto canónico por stdin, LF y EOF. Cada proceso devuelve exactamente un objeto canónico por stdout, LF y EOF.

El child recibe `phase_request` y devuelve `phase_result`. El stub recibe `loopback_request` y devuelve `loopback_result` al terminar. Una ejecución sin Webpay no crea stub y el bundle contiene `loopback_result=null`. Una ejecución con Webpay exige ambos results completos.

El coordinator crea procesos, entrega requests, drena stdout/stderr, espera ambos sin depender del orden de finalización, valida el bundle completo, prepara una transacción en memoria y recién entonces integra. Child y stub solo observan y proponen; nunca aceptan counts ni sellan fases.

## 4. JSON canónico, framing y límites

Todos los envelopes usan UTF-8 sin BOM, JSON canónico EA5, una sola línea, LF `0x0A` obligatorio y EOF inmediatamente posterior. No se admite CRLF, whitespace previo/posterior, segunda línea ni bytes después del LF.

El límite es 1,048,576 bytes por request o result incluyendo LF; profundidad máxima 8. Los enteros son JSON integers, sin floats ni coerción. Timeout es el entero 1..30 del request; no se amplía ni reintenta.

Stdout vacío, JSON truncado, LF ausente, EOF prematuro, proceso que termina sin result, proceso que escribe y no termina, segunda línea, BOM, contenido no protocolario o exceso de tamaño producen `transport_framing_invalid`. El coordinator drena stdout y stderr concurrentemente para evitar deadlock.

En éxito protocolario stderr debe estar vacío. En exit no cero puede contener un único diagnóstico UTF-8 de hasta 4096 bytes, sin JSON completo, token completo, snapshot, count ni estado autoritativo. Stderr nunca se integra. Stderr no vacío con exit 0 produce `stderr_contaminated`.

## 5. Shape exacto de `phase_request`

Schema superior: `veciahorra-a11-action-transport/v1`. Claves exactas, sin adicionales:

```json
{"schema":"veciahorra-a11-action-transport/v1","kind":"phase_request","case_id":"A11-OP-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","timeout_seconds":10,"capture_plan":{},"input_snapshot":{},"operation":{"name":"controlled_phase","parameters":{}}}
```

Orden lógico: `schema`, `kind`, `case_id`, `ownership_token`, `phase`, `timeout_seconds`, `capture_plan`, `input_snapshot`, `operation`; la serialización real reordena claves canónicamente.

`case_id` usa la gramática A11; `ownership_token` es el `execution_id` EA5; `phase` pertenece a `setup|first_delivery|replay|assertions_finales|cleanup`; timeout 1..30. `capture_plan` e `input_snapshot` son exactamente los objetos EA5 readonly.

`operation` tiene exactamente `name` y `parameters`. `parameters` es siempre el objeto vacío `{}`. `name` pertenece al catálogo cerrado `setup|execute_phase|assertions|cleanup|observe_woocommerce_payment_complete|observe_scheduler_action_schedule|observe_scheduler_action_cancel|observe_legacy_retry_schedule|observe_durable_worker_execute`. Los cinco nombres `observe_*` existen solo para certificación neutral EA6 y cada uno cruza una vez el decorator literal correspondiente; no son aliases de puertos ni pueden aparecer en fixtures. `execute_phase` ejecuta el flujo seleccionado por el entrypoint controlado y por el case/snapshot, sin código o callback recibido. No se permiten callbacks, clases, código, rutas, secretos, IDs runtime ni nombres dinámicos de puertos.

## 6. Shapes exactos de `capture_delta` y `action_delta`

`capture_delta` conserva sin cambio EA5:

```json
{"schema":"veciahorra-a11-capture/v1","kind":"capture_delta","case_id":"A11-OP-01","execution_id":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","captures":{}}
```

Claves exactas: `schema`, `kind`, `case_id`, `execution_id`, `phase`, `base_snapshot_hash`, `captures`. `captures` es objeto canónicamente ordenado; vacío se representa `{}`.

`action_delta` conserva sin cambio EA5:

```json
{"schema":"veciahorra-a11-capture/v1","kind":"action_delta","case_id":"A11-OP-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","port":"scheduler.action_schedule","delta":1,"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}
```

Claves exactas: `schema`, `kind`, `case_id`, `ownership_token`, `phase`, `port`, `delta`, `base_action_hash`. En transporte EA6 `delta` es exactamente `1`, aunque EA5 siga aceptando enteros positivos para integración directa histórica. El puerto pertenece literalmente al catálogo EA5.

## 7. Multiplicidad y cadena de actions

La única representación autorizada es una lista JSON ordenada de envelopes `action_delta`. Cero actions es `[]`; uno es una lista de longitud uno; varios conservan el orden real de comienzo observado por ese proceso.

Cada elemento tiene `delta=1`. Dos comienzos reales del mismo puerto son dos elementos; no se agrupan ni deduplican. El máximo es 4096 actions por result y el count acumulado no puede superar `2147483647`.

El primer elemento usa el `base_action_hash` del snapshot de entrada. Cada siguiente elemento usa el hash resultante de aplicar conceptualmente todos los elementos anteriores. Así, la lista constituye una cadena determinista. Índice de lista es la secuencia; no se añade `event_sequence` al delta EA5.

Reenviar el mismo result después de aceptación falla por base hash antiguo con `result_replayed`. Duplicados con hashes encadenados diferentes representan cruces reales distintos. Dos capas que informan el mismo comienzo producen `double_capture_detected` antes de mutar.

## 8. Shape exacto de `operation_result`

Objeto cerrado con claves exactas:

```json
{"status":"success","reason_code":"none","effects_started":true,"result_type":"none","result":null}
```

`status` pertenece a `success|controlled_failure|uncertain`. `reason_code` pertenece a `none|controlled_failure|uncertain_result`. `effects_started` es boolean. `result_type` pertenece a `none|boolean|positive_int|non_empty_string`; `result` debe corresponder estrictamente o ser `null` para `none`, sin secretos y máximo 1024 bytes si string.

Crash, protocol failure, timeout y exit failure no se representan falsamente como `operation_result`: son resultados del supervisor definidos en §13.

## 9. Shape exacto de `phase_result`

Claves exactas:

```json
{"schema":"veciahorra-a11-action-transport/v1","kind":"phase_result","case_id":"A11-OP-01","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","capture_delta":{"schema":"veciahorra-a11-capture/v1","kind":"capture_delta","case_id":"A11-OP-01","execution_id":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","captures":{}},"action_deltas":[],"operation_result":{"status":"success","reason_code":"none","effects_started":false,"result_type":"none","result":null},"termination":{"status":"completed","exit_code":0},"result_hash":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}
```

Claves superiores exactas: `schema`, `kind`, `case_id`, `ownership_token`, `phase`, `base_snapshot_hash`, `base_action_hash`, `capture_delta`, `action_deltas`, `operation_result`, `termination`, `result_hash`.

`termination` tiene exactamente `status=completed` y `exit_code=0`. `result_hash` es SHA-256 lowercase del JSON canónico del objeto superior sin `result_hash`. El capture y todos los actions deben repetir case, ownership/execution, phase y bases compatibles. `capture_delta` nunca es `null`, incluso vacío.

## 10. Shapes exactos del loopback Webpay

`loopback_request` tiene claves exactas:

```json
{"schema":"veciahorra-a11-action-transport/v1","kind":"loopback_request","case_id":"A11-WR-06","ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","timeout_seconds":10,"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","response_plan":{"status":"success","http_status":200,"delay_milliseconds":0,"body":"{}"}}
```

`response_plan` tiene exactamente `status`, `http_status`, `delay_milliseconds`, `body`. Status pertenece a `success|controlled_failure|uncertain`; HTTP status 100..599; delay 0..30000 y nunca extiende timeout; body UTF-8 máximo 65536 bytes, sin secretos.

`loopback_result` tiene claves exactas:

```json
{"schema":"veciahorra-a11-action-transport/v1","kind":"loopback_result","case_id":"A11-WR-06","ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","observations":[{"sequence":1,"request_fingerprint":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","response_status":"success"}],"action_deltas":[{"schema":"veciahorra-a11-capture/v1","kind":"action_delta","case_id":"A11-WR-06","ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","port":"webpay.commit","delta":1,"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}],"server_result":{"status":"success","reason_code":"none","requests_observed":1},"termination":{"status":"completed","exit_code":0},"result_hash":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"}
```

Claves exactas conforme al ejemplo. Cada observation tiene exactamente `sequence`, `request_fingerprint`, `response_status`; sequence es 1..4096 continua, fingerprint SHA-256 lowercase de método, path y cuerpo sintéticos canónicos sin headers secretos, y status pertenece a `success|controlled_failure|uncertain|connection_closed`.

`server_result` tiene exactamente `status`, `reason_code`, `requests_observed`; status y reason siguen §8, count 0..4096 e iguala longitudes de observations y action_deltas. Todo action usa exclusivamente `port=webpay.commit` y `delta=1`. Result hash se calcula como en §9.

## 11. Propiedad exclusiva de `webpay.commit`

El comienzo normativo ocurre cuando el stub acepta y comienza a procesar una solicitud HTTP commit completa, después del framing HTTP suficiente para atribuir case/ownership/phase y antes de producir respuesta. El stub es el único observador autorizado de este puerto; child y coordinator tienen prohibido emitirlo.

El stub crea observation y action simultáneamente en memoria local. Se cuenta aunque la respuesta planeada falle, sea incierta, cierre conexión o exceda tiempo después del comienzo. Una conexión que no completa el request atribuible vale cero. Una retransmisión HTTP real completa produce otro par observation/action. No hay deduplicación por token en EA6.

Case, ownership y phase llegan por stdin; el HTTP request porta solo una referencia sintética no secreta cuyo fingerprint se contrasta con la configuración. El stub preserva request, timing, status, body y cierre planeados; observar no cambia el resultado. Su memoria deja de tener autoridad al salir: únicamente el coordinator puede aceptar el result.

## 12. Validación y atomicidad

El coordinator no muta al recibir cada proceso. Conserva bytes crudos y results decodificados como evidencia observada no aceptada. Cuando todos los procesos requeridos terminaron, valida en este orden:

1. framing, tamaño, JSON canónico, schema, kind, hash y exit;
2. key sets y tipos de ambos results;
3. case, ownership, phase, snapshot/action bases y proceso esperado;
4. operation, termination y cardinalidades;
5. cada capture sin mutar;
6. lista child de actions y su cadena;
7. observations y lista stub de actions y su cadena;
8. propiedad exclusiva: stub solo Webpay; child nunca Webpay;
9. concatenación canónica: actions child en orden, seguidas de actions stub ordenadas por observation sequence, recalculando una cadena única;
10. overflow, replay, doble captura y estado no sellado;
11. preparación transaccional del nuevo capture state, action state, snapshot y hashes;
12. commit único en memoria.

Si cualquier validación falla, se descarta todo el bundle: cero captures aceptados, cero counts aceptados, snapshot/hash previo intacto y fase sin sellar. No existe mutación parcial por un action válido seguido de uno inválido.

Si el child y stub parten del mismo `base_action_hash`, el coordinator rebasa determinísticamente la lista stub sobre el hash final conceptual del child; los hashes originales del stub prueban su cadena local, no se aplican directamente. Esta regla fija un único orden canónico independiente del orden de terminación.

`success` permite commit y sellado. `controlled_failure` o `uncertain` con results completos y válidos permite aceptar evidencia/captures en una transacción de fallo, pero prohíbe sellar y obliga cleanup. Crash o ausencia de cualquier result requerido conserva evidencia solo como diagnóstico no autoritativo: no muta store ni snapshot.

Un result posterior a S2 para first delivery, posterior a S3 para replay o posterior al sellado del caso falla `late_result`; nunca reabre fase.

## 13. Catálogo cerrado de resultados y exits

| Resultado | Exit | Envelope | Integra actions/capture | Sella | Reason obligatorio | Cleanup |
|---|---:|---|---|:---:|---|:---:|
| success | 0 | result completo | sí, bundle atómico | sí | `none` | normal |
| controlled failure | 0 | result completo | sí, transacción de fallo | no | `controlled_failure` | sí |
| uncertain result | 0 | result completo | sí, transacción de fallo | no | `uncertain_result` | sí |
| protocol failure | 64 o 65 | ninguno autoritativo | no | no | `protocol_failure` | sí si hubo efectos |
| crash child | 70 | ausente/incompleto | no | no | `child_crash` | sí |
| crash stub | 70 | ausente/incompleto | no | no | `stub_crash` | sí |
| child exit failure | 75 | opcional, no autoritativo | no | no | `child_exit_failure` | sí |
| stub exit failure | 75 | opcional, no autoritativo | no | no | `stub_exit_failure` | sí |
| timeout | 124 impuesto por coordinator | cualquiera no autoritativo | no | no | `timeout` | sí |

Todo otro exit es `unexpected_exit`, sin integración ni sellado. Un exit 0 sin envelope válido es protocol failure. Exit no cero nunca se convierte silenciosamente en operation result.

## 14. Concurrencia, lifecycle y cleanup

El coordinator inicia primero el stub cuando la fase lo requiere, escribe y cierra su stdin; confirma únicamente que el proceso sigue controlado, luego inicia child, escribe y cierra stdin. Sin Webpay inicia solo child.

Stdout y stderr de cada proceso son pipes dedicados estándar 1/2; no existen pipes de datos extra. El coordinator drena los cuatro streams de forma no bloqueante, alternada y con límites independientes. Después de child espera stub solo hasta el mismo deadline absoluto de fase. El orden inverso de terminación es válido.

Al deadline termina child, stub y descendientes, drena bytes restantes, cierra handles y registra exit 124. Ante fallo temprano termina el otro proceso. El coordinator padre es responsable de todos los PIDs y verifica cero hijos. No hay retry de transporte.

El servidor HTTP loopback escucha exclusivamente `127.0.0.1` en un puerto efímero asignado por el coordinator. Esa conexión es efecto productivo controlado, no transporte de autoridad. No persiste request, result ni action.

## 15. Separación de `external_actions`

Los envelopes, observations y operation results no son `external_actions`. No se insertan ni proyectan allí; tampoco se derivan counts desde esa colección. El stub no la lee ni escribe. El coordinator integra únicamente Action Capture y conserva `actions` separado de cualquier evidencia histórica `external_actions` en snapshots y hashes.

Puede existir action sin `external_actions`, `external_actions` sin action o ambos en el mismo caso sin equivalencia automática.

## 16. Matriz adversarial normativa

En la columna mutación, “sí-fallo” significa transacción válida de evidencia seguida de cleanup, sin sellado.

| # | Escenario | Decisión | Mutación | Reason | Snapshot/sellado | Cleanup |
|---:|---|---|---|---|---|:---:|
| 1 | request válido | acepta | no aún | none | no/no | no |
| 2 | result válido sin actions | acepta | sí | none | sí/sí | normal |
| 3 | result con un action | acepta | sí | none | sí/sí | normal |
| 4 | dos comienzos mismo puerto encadenados | acepta | sí | none | sí/sí | normal |
| 5 | múltiples puertos child | acepta | sí | none | sí/sí | normal |
| 6 | child termina antes del stub | acepta al tener ambos | sí | none | sí/sí | normal |
| 7 | stub termina antes del child | acepta al tener ambos | sí | none | sí/sí | normal |
| 8 | JSON inválido | rechaza bundle | no | transport_framing_invalid | no/no | sí |
| 9 | segunda línea | rechaza | no | transport_framing_invalid | no/no | sí |
| 10 | campo adicional | rechaza | no | protocol_failure | no/no | sí |
| 11 | versión incorrecta | rechaza | no | protocol_failure | no/no | sí |
| 12 | case mismatch | rechaza | no | wrong_owner | no/no | sí |
| 13 | ownership mismatch | rechaza | no | wrong_owner | no/no | sí |
| 14 | phase mismatch | rechaza | no | wrong_phase | no/no | sí |
| 15 | puerto inválido | rechaza | no | actions_port_invalid | no/no | sí |
| 16 | delta cero | rechaza | no | actions_delta_invalid | no/no | sí |
| 17 | delta mayor que uno | rechaza | no | actions_delta_invalid | no/no | sí |
| 18 | overflow | rechaza | no | actions_overflow | no/no | sí |
| 19 | stdout vacío | rechaza | no | transport_framing_invalid | no/no | sí |
| 20 | LF o EOF ausente | rechaza | no | transport_framing_invalid | no/no | sí |
| 21 | timeout | rechaza | no | timeout | no/no | sí |
| 22 | crash child | rechaza | no | child_crash | no/no | sí |
| 23 | crash stub | rechaza | no | stub_crash | no/no | sí |
| 24 | stderr con exit 0 | rechaza | no | stderr_contaminated | no/no | sí |
| 25 | exit incompatible | rechaza | no | unexpected_exit | no/no | sí |
| 26 | evidencia parcial por proceso faltante | rechaza | no | incomplete_bundle | no/no | sí |
| 27 | action válido seguido por inválido | rechaza todo | no | error del segundo | no/no | sí |
| 28 | result first delivery después de S2 | rechaza | no | late_result | no/no | sí |
| 29 | result replay después de S3 | rechaza | no | late_result | no/no | sí |
| 30 | replay del mismo envelope | rechaza | no | result_replayed | no/no | sí |
| 31 | request Webpay real repetido | acepta dos actions | sí | none | sí/sí | normal |
| 32 | Webpay emitido por child y stub | rechaza todo | no | double_capture_detected | no/no | sí |
| 33 | operation result ausente | rechaza | no | incomplete_bundle | no/no | sí |
| 34 | capture presente, operation ausente | rechaza | no | incomplete_bundle | no/no | sí |
| 35 | operation presente, capture ausente | rechaza | no | incomplete_bundle | no/no | sí |
| 36 | controlled failure completo | acepta evidencia | sí-fallo | controlled_failure | no/no | sí |
| 37 | uncertain completo | acepta evidencia | sí-fallo | uncertain_result | no/no | sí |
| 38 | stub responde y luego falla al reportar | rechaza bundle | no | stub_crash | no/no | sí |

Un ejemplo adversarial mínimo es `{"kind":"phase_result","extra":true}`: carece de schema/ownership y añade campo; se rechaza antes de mutar. Otro es un action con `"delta":2`; aunque EA5 directo admita positivos, el perfil EA6 lo rechaza.

## 17. Compatibilidad EA5 y allowlist definitiva de EA6

La allowlist EA6 original de tres paths es insuficiente. EA5 permite integrar un action y un capture separadamente, pero no ofrece rollback del objeto Action Capture si falla una integración posterior ni commit atómico de actions+captures+snapshot.

EA6 debe añadir al contrato una única operación transaccional, conceptualmente `integratePhaseBundle(array $captureDelta, array $actionDeltas, array $operationResult): array`, que valide sobre copias, prepare hashes, haga commit único y restaure captures, actions, snapshots y phase ante cualquier excepción. También debe añadir validadores cerrados de los nuevos envelopes. No cambia catálogo, counts, comparación ni semántica de integración directa histórica.

Allowlist implementable definitiva, exactamente cuatro paths:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` — transacción y validadores compuestos.
2. `tests/manual/support/durable-retry-a11-coordinator.php` — lifecycle de child/stub, framing, staging y commit.
3. `tests/manual/support/durable-retry-a11-child-worker.php` — decorators neutrales de cinco puertos y `phase_result`.
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php` — propietario exclusivo de `webpay.commit` y `loopback_result`.

Los dos harnesses EA5 permanecen protegidos. EA6 debe probarse con comandos controlados y luego ejecutar esos harnesses sin modificarlos. Si la nueva operación no puede implementarse en el primer path, EA6 se detiene; no se replica un store en coordinator.

## 18. Instrumentación neutral futura

Child define decorators de pruebas que emiten antes de delegar exactamente para `woocommerce.payment_complete`, `scheduler.action_schedule`, `scheduler.action_cancel`, `legacy.retry_schedule` y `durable.worker_execute`. Conservan argumentos, orden, retorno y excepción; una excepción después del cruce conserva action. No emiten si la delegación no comienza.

El stub define el sexto puerto conforme §11. No hay stores definitivos, globals autoritativos, persistencia, inference retrospectiva, compensación de counts ni proyección a expected values.

## 19. Secuencia posterior obligatoria

1. Esta corrección normativa de transporte.
2. EA6 dentro de la allowlist definitiva de cuatro paths.
3. Certificación neutral de los seis puertos, atomicidad, adversariales y residuos.
4. EA7: convergencia productiva de nueve casos.
5. EA8: creación de H1–H5.
6. EA9: materialización literal de 372 counts.
7. EA10: certificación sin cambios.

EA7 está prohibido hasta que EA6 obtenga veredicto verde. Esta corrección no afirma convergencia productiva, fixtures ejecutables ni A11 completo.

## 20. Condiciones de implementación y certificación

EA6 solo es implementable si respeta shapes, un result por proceso, lista de deltas, propietario único Webpay, bundle atómico, allowlist de cuatro paths, cero producto/fixtures/H1–H5, pruebas EA5 verdes, R2/R4/R5 verdes, regresión Durable Retry verde, cero timeout/residuos y staging vacío.

Todo envelope alternativo, framing multilínea, integración parcial, store paralelo, cuarto puerto de transporte, modificación productiva o ampliación no autorizada produce fallo cerrado.

## 21. Cierre de contradicciones

Quedan cerrados: request único; result compuesto único; framing de varios actions dentro de una lista; shapes simultáneos de capture/actions/operation; expectativa del coordinator; resultado operativo child; capacidad probatoria del loopback; punto de captura Webpay; prohibición de canales laterales; autoridad única; atomicidad ante crash/EOF/orden; multiplicidad; exits; sellado; replay; compatibilidad y allowlist.

No quedan dos protocolos igualmente válidos: `capture_delta` y `action_delta` son exclusivamente subobjetos; `phase_result` y `loopback_result` son los únicos envelopes superiores EA6.

## 22. Veredicto documental

El protocolo, shapes, framing, ownership, roles, propiedad Webpay, multiplicidad, resultado operativo, atomicidad, concurrencia, sellado, failure semantics, allowlist y matriz adversarial quedan definidos sin alternativa pendiente.

`A11 ACTION CAPTURE TRANSPORT IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`
