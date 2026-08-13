# Segunda corrección normativa A11 del bootstrap de Action Capture

Estado: contrato normativo cerrado, fail-closed e implementable. Fecha: 2026-08-05.

## 1. Objeto, alcance y precedencia

Esta corrección resuelve exclusivamente dos contradicciones residuales de la primera corrección de transporte: el nombre de `operation.name` del `phase_request` y el bootstrap del endpoint HTTP loopback. Especializa además las consecuencias directas necesarias para comunicar el endpoint, demostrar readiness, tratar colisiones y terminar el stub.

Esta corrección prevalece únicamente sobre:

1. el ejemplo de `phase_request` que usaba `controlled_phase`;
2. los key sets de `phase_request`, `loopback_request` y `loopback_result`, exclusivamente para añadir `loopback_required`, `loopback_endpoint` y `readiness_challenge` según se define aquí;
3. la asignación abstracta de puerto efímero y el arranque del stub sin handshake de readiness;
4. el tratamiento de una colisión de bind como fallo terminal sin reintento de bootstrap.

Todo el resto de `durable-retry-production-activation-a11-action-capture-transport-normative-correction.md` permanece vigente: un request y un result por proceso, envelopes compuestos, catálogos EA5, ownership, fases S0–S4, canonical JSON, hashes, límites, captura Webpay, atomicidad, sellado, replay, `external_actions`, exits y cleanup. No se crea un protocolo alternativo ni se permite elegir entre dos shapes o mecanismos.

## 2. Decisiones cerradas

- El único nombre que ejecuta una fase es `execute_phase`.
- `controlled_phase` queda prohibido en todo `phase_request`.
- El coordinator elige el endpoint antes de iniciar el stub.
- El endpoint viaja por stdin dentro de ambos requests; nunca por environment, argumentos, archivos o stdout intermedio.
- El stub enlaza exactamente `http://127.0.0.1:<port>` y no cambia de puerto.
- Readiness usa únicamente HTTP sobre el mismo listener.
- Cada colisión usa un proceso stub nuevo, con un request y un result propios.
- Sin Webpay, `loopback_required=false`, `loopback_endpoint=null`, no existe stub y el bundle usa `loopback_result=null`.
- Con Webpay, `loopback_required=true`, ambos requests contienen el mismo endpoint completo y el bundle exige ambos results.

## 3. Catálogo cerrado de operaciones

| Envelope | Dirección | `operation.name` | Emisor | Receptor | Permitido | Propósito |
|---|---|---|---|---|:---:|---|
| `phase_request` | coordinator→child | `setup` | coordinator | child | sí | setup controlado |
| `phase_request` | coordinator→child | `execute_phase` | coordinator | child | sí | ejecutar first delivery o replay |
| `phase_request` | coordinator→child | `assertions` | coordinator | child | sí | assertions finales |
| `phase_request` | coordinator→child | `cleanup` | coordinator | child | sí | cleanup |
| `phase_request` | coordinator→child | `observe_woocommerce_payment_complete` | coordinator | child | sí, solo EA6 | cruce neutral literal |
| `phase_request` | coordinator→child | `observe_scheduler_action_schedule` | coordinator | child | sí, solo EA6 | cruce neutral literal |
| `phase_request` | coordinator→child | `observe_scheduler_action_cancel` | coordinator | child | sí, solo EA6 | cruce neutral literal |
| `phase_request` | coordinator→child | `observe_legacy_retry_schedule` | coordinator | child | sí, solo EA6 | cruce neutral literal |
| `phase_request` | coordinator→child | `observe_durable_worker_execute` | coordinator | child | sí, solo EA6 | cruce neutral literal |
| `phase_result` | child→coordinator | ninguna clave `operation` | child | coordinator | obligatorio | `kind=phase_result` significa fase completada |
| `loopback_request` | coordinator→stub | ninguna clave `operation` | coordinator | stub | obligatorio | `kind=loopback_request` significa iniciar loopback |
| `loopback_result` | stub→coordinator | ninguna clave `operation` | stub | coordinator | obligatorio | `kind=loopback_result` significa loopback terminado |

Los nombres conceptuales `phase_completed`, `start_loopback` y `loopback_completed` no se materializan como campos: sus `kind` ya son la discriminación cerrada. Añadirlos violaría los envelopes existentes. `controlled_phase`, aliases, compatibilidad retroactiva, normalización y selección dinámica quedan prohibidos. Coordinator y child comparan `operation.name` byte por byte.

## 4. Endpoint loopback cerrado

`loopback_endpoint` tiene exactamente estas claves y tipos:

```json
{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"}
```

El hash del ejemplo es ilustrativo; nunca es fixture. Para todo endpoint real, `endpoint_sha256` es SHA-256 lowercase del JSON canónico UTF-8, sin BOM ni LF, del objeto con exactamente `commit_path`, `host`, `port`, `readiness_path`, `scheme`, excluyendo `endpoint_sha256`.

Reglas cerradas:

- `scheme` es exactamente `http`; `host` es exactamente `127.0.0.1`.
- `port` es integer JSON/PHP entre 49152 y 65535 inclusive.
- Los paths son exactamente los dos literales mostrados, empiezan con un slash, no terminan en slash y no contienen `//`, query, fragment, credenciales, percent-encoding ni caracteres fuera de ASCII.
- URL commit: `http://127.0.0.1:` + puerto decimal canónico + `/a11/webpay/commit`.
- URL readiness: `http://127.0.0.1:` + puerto decimal canónico + `/a11/readiness`.
- Se prohíben `localhost`, DNS, IPv6, `0.0.0.0`, interfaces públicas, HTTPS, redirects y claves adicionales.
- Todo string es UTF-8 exacto; no hay normalización ni defaults.

## 5. Selección determinista de puerto

El coordinator deriva candidatos antes de iniciar cada stub:

```text
material = case_id + NUL + ownership_token + NUL + phase + NUL + decimal_attempt_index
digest = SHA-256 lowercase hexadecimal de material UTF-8
unsigned32 = entero big-endian representado por los primeros 8 hex de digest
port = 49152 + (unsigned32 mod 16384)
```

`attempt_index` recorre 0..63. Se conservan en memoria los puertos ya derivados; un puerto duplicado se omite sin iniciar proceso y sin consumir uno de los 32 intentos de bind. Se intentan como máximo 32 puertos distintos. No intervienen PID, tiempo, aleatoriedad ni persistencia.

Una colisión con otro listener termina ese stub, se valida y limpia, y continúa con el siguiente candidato distinto. Dos casos concurrentes pueden derivar el mismo primer puerto: solo uno enlaza; el otro avanza determinísticamente. Tras 32 colisiones, o si 0..63 no producen 32 candidatos distintos y ninguno enlaza, el reason final es `loopback_port_exhausted`. El orden de terminación no cambia la lista derivada.

## 6. Requerimiento de Webpay

El `operation` y el escenario controlado, ya validados por el coordinator desde el plan readonly del caso, determinan un boolean `loopback_required`; no se deduce informalmente del case ID.

Ambos `phase_request` shapes contienen siempre `loopback_required` y `loopback_endpoint`:

- `false` exige `null`, prohíbe stub y prohíbe evidencia Webpay;
- `true` exige el objeto completo y exige stub/readiness/result;
- omisión, objeto parcial, `null` obligatorio u objeto prohibido produce `loopback_requirement_mismatch`.

Los nombres `observe_*` nunca requieren loopback. `execute_phase` puede requerirlo únicamente cuando el plan readonly lo declara. Setup, assertions y cleanup usan `false/null`.

## 7. Desafío y readiness HTTP

El `loopback_request` contiene `readiness_challenge`, exactamente 32 hex lowercase:

```text
challenge_material = "a11-readiness" + NUL + case_id + NUL + ownership_token + NUL + phase + NUL + endpoint_sha256
readiness_challenge = primeros 32 caracteres de SHA-256 lowercase(challenge_material UTF-8)
proof = SHA-256 lowercase("a11-ready" + NUL + readiness_challenge + NUL + endpoint_sha256)
```

No es ownership alternativo, no aparece en fixtures, no se registra y no revela el token completo. Las comparaciones de challenge y proof usan comparación constante.

Request exacto de readiness, con CRLF propio de HTTP:

```http
GET /a11/readiness HTTP/1.1
Host: 127.0.0.1:54321
Connection: close
X-A11-Readiness-Challenge: 0123456789abcdef0123456789abcdef
Content-Length: 0

```

Respuesta exacta en éxito:

```http
HTTP/1.1 204 No Content
Connection: close
Content-Length: 0
X-A11-Readiness-Proof: 9fe0a9ff02d7b8198f0f64ec51db8d57aa8f45678da96afabdc0b239f5d65ee8

```

Los valores hex del ejemplo son ilustrativos y deben recalcularse. Solo se permiten los headers mostrados, salvo orden de headers; nombres se comparan ASCII case-insensitive, valores exactos. No hay body. Host distinto, header repetido, transfer encoding, query, respuesta distinta, challenge/proof inválido u otro servicio producen `loopback_readiness_invalid`.

El coordinator intenta conexión después de iniciar el stub: timeout individual 100 ms, pausa monotónica de 25 ms y máximo 20 requests dentro del deadline absoluto de fase. Connection refused antes de bind permite el siguiente intento de readiness. Una respuesta HTTP incorrecta no es una colisión: termina y rechaza el stub. Si no llega readiness correcta, `loopback_readiness_timeout`.

Readiness es control-plane sobre el listener autorizado, no canal de Action Capture: nunca crea observation/action, no cambia counts, snapshot, hash, sellado ni `external_actions`. Dos readiness válidas responden igual y cuentan cero. Stdout permanece abierto y silencioso.

El cierre normal usa el mismo control-plane, sin nuevo path ni canal. Después de recibir un `phase_result` completo y de comprobar que el child terminó, el coordinator envía exactamente:

```http
POST /a11/readiness HTTP/1.1
Host: 127.0.0.1:54321
Connection: close
X-A11-Readiness-Challenge: 0123456789abcdef0123456789abcdef
X-A11-Control: shutdown
Content-Length: 0

```

El stub valida challenge, endpoint y estado, deja de aceptar nuevos commits, cierra conexiones, responde exactamente `HTTP/1.1 204 No Content` con `Connection: close`, `Content-Length: 0` y el mismo `X-A11-Readiness-Proof`, y solo entonces emite su único `loopback_result`, LF y EOF. Antes del `phase_result`, un shutdown se rechaza con HTTP 409 y no termina el listener. El coordinator conserva localmente el hecho de que el child terminó; el POST no transporta captures, actions, snapshots ni estado autoritativo. Repetición, challenge inválido o header adicional produce `loopback_request_invalid`. Un crash impide este cierre normal y se trata conforme al supervisor.

## 8. Orden de arranque y terminación

Con Webpay:

1. coordinator valida contexto, ownership, fase, plan, snapshot y deadline;
2. determina `loopback_required=true`;
3. deriva el siguiente candidato y construye endpoint/request;
4. inicia un stub, escribe un request completo con LF y cierra stdin;
5. stub valida, enlaza exactamente el endpoint y atiende readiness;
6. coordinator obtiene readiness válida;
7. construye el `phase_request` con el mismo endpoint, inicia child, escribe request con LF y cierra stdin;
8. child usa exclusivamente esa URL commit;
9. coordinator drena stdout/stderr de ambos sin bloqueo hasta deadline;
10. recibe `phase_result`, envía el POST de shutdown autenticado, espera su 204 y recibe `loopback_result`;
11. valida el bundle conjunto y solo entonces prepara y aplica el commit atómico.

El stub termina su accept loop únicamente tras el POST de shutdown válido. Si el coordinator necesita terminarlo por fallo, cualquier stdout parcial es no autoritativo.

Sin Webpay: valida; construye request `false/null`; inicia solo child; escribe/cierra stdin; drena; valida `phase_result` con `loopback_result=null`; prepara commit. Iniciar stub es `loopback_requirement_mismatch`.

## 9. Colisión de bind

Cada intento usa un proceso nuevo y conserva un request/result. Si bind falla porque el puerto ya está ocupado, el stub emite un único result diagnóstico canónico, LF y EOF, y termina 75. El coordinator solo reintenta si framing, endpoint, case, ownership, phase, hash y reason `loopback_bind_collision` son válidos. Ese result nunca se integra en el bundle.

Otro error de bind, input inválido o result inválido es terminal. Un stub nunca recibe segundo request ni cambia de puerto. Ningún intento fallido crea captures, actions, snapshot, hash o sellado.

## 10. Atribución de Webpay

Cada stub pertenece a un único `(case_id, ownership_token, phase, endpoint)`. Solo acepta `POST /a11/webpay/commit`, `Host` exacto, `Content-Type: application/json`, `Connection: close`, `Content-Length` decimal canónico y body JSON dentro de 65536 bytes. Se prohíben chunked, query, fragment, otro método/path y conexión externa.

No se añade header A11 al request productivo. La atribución procede del proceso dedicado y endpoint exclusivo. Readiness usa otro path y nunca cuenta. Cuando el request commit está completo, válido y atribuible, el stub crea exactamente una observation y un `action_delta`; cada retransmisión completa crea otro. Child y coordinator jamás emiten `webpay.commit`.

Request ilustrativo atribuible:

```http
POST /a11/webpay/commit HTTP/1.1
Host: 127.0.0.1:54321
Content-Type: application/json
Connection: close
Content-Length: 2

{}
```

## 11. Shapes canónicos corregidos

Los objetos siguientes están serializados con claves lexicográficamente ordenadas. Cada envelope transportado añade exactamente LF y EOF. Los hashes repetidos son ilustrativos, no fixtures, y se recalculan con los algoritmos normativos.

### 11.1 `phase_request` sin Webpay

```json
{"capture_plan":{},"case_id":"A11-OP-01","input_snapshot":{},"kind":"phase_request","loopback_endpoint":null,"loopback_required":false,"operation":{"name":"execute_phase","parameters":{}},"ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","schema":"veciahorra-a11-action-transport/v1","timeout_seconds":10}
```

### 11.2 `phase_request` con Webpay

```json
{"capture_plan":{},"case_id":"A11-WR-06","input_snapshot":{},"kind":"phase_request","loopback_endpoint":{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"},"loopback_required":true,"operation":{"name":"execute_phase","parameters":{}},"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","schema":"veciahorra-a11-action-transport/v1","timeout_seconds":10}
```

### 11.3 `loopback_request`

```json
{"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","case_id":"A11-WR-06","kind":"loopback_request","loopback_endpoint":{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"},"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","readiness_challenge":"0123456789abcdef0123456789abcdef","response_plan":{"body":"{}","delay_milliseconds":0,"http_status":200,"status":"success"},"schema":"veciahorra-a11-action-transport/v1","timeout_seconds":10}
```

### 11.4 `phase_result` sin Webpay

```json
{"action_deltas":[],"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","capture_delta":{"base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","captures":{},"case_id":"A11-OP-01","execution_id":"a11_20260803010101_1_0123456789abcdef","kind":"capture_delta","phase":"first_delivery","schema":"veciahorra-a11-capture/v1"},"case_id":"A11-OP-01","kind":"phase_result","operation_result":{"effects_started":false,"reason_code":"none","result":null,"result_type":"none","status":"success"},"ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","result_hash":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","schema":"veciahorra-a11-action-transport/v1","termination":{"exit_code":0,"status":"completed"}}
```

### 11.5 `phase_result` con Webpay

```json
{"action_deltas":[],"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","capture_delta":{"base_snapshot_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","captures":{},"case_id":"A11-WR-06","execution_id":"a11_20260803010101_2_0123456789abcdef","kind":"capture_delta","phase":"first_delivery","schema":"veciahorra-a11-capture/v1"},"case_id":"A11-WR-06","kind":"phase_result","operation_result":{"effects_started":true,"reason_code":"none","result":null,"result_type":"none","status":"success"},"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","result_hash":"2222222222222222222222222222222222222222222222222222222222222222","schema":"veciahorra-a11-action-transport/v1","termination":{"exit_code":0,"status":"completed"}}
```

No contiene endpoint: la correlación se prueba entre ambos requests y `loopback_result`, evitando duplicación innecesaria.

### 11.6 `loopback_result` exitoso

```json
{"action_deltas":[{"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","case_id":"A11-WR-06","delta":1,"kind":"action_delta","ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","port":"webpay.commit","schema":"veciahorra-a11-capture/v1"}],"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","case_id":"A11-WR-06","kind":"loopback_result","loopback_endpoint":{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"},"observations":[{"request_fingerprint":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","response_status":"success","sequence":1}],"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","result_hash":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","schema":"veciahorra-a11-action-transport/v1","server_result":{"reason_code":"none","requests_observed":1,"status":"success"},"termination":{"exit_code":0,"status":"completed"}}
```

### 11.7 Bind collision

```json
{"action_deltas":[],"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","case_id":"A11-WR-06","kind":"loopback_result","loopback_endpoint":{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"},"observations":[],"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","result_hash":"ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff","schema":"veciahorra-a11-action-transport/v1","server_result":{"reason_code":"loopback_bind_collision","requests_observed":0,"status":"controlled_failure"},"termination":{"exit_code":75,"status":"completed"}}
```

### 11.8 Fallo protocolario diagnóstico

```json
{"action_deltas":[],"base_action_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","case_id":"A11-WR-06","kind":"loopback_result","loopback_endpoint":{"commit_path":"/a11/webpay/commit","endpoint_sha256":"207fe5d879406c893185abb9600589eac04bb6ff4b78c5d611c94136480a04b0","host":"127.0.0.1","port":54321,"readiness_path":"/a11/readiness","scheme":"http"},"observations":[],"ownership_token":"a11_20260803010101_2_0123456789abcdef","phase":"first_delivery","result_hash":"1111111111111111111111111111111111111111111111111111111111111111","schema":"veciahorra-a11-action-transport/v1","server_result":{"reason_code":"protocol_failure","requests_observed":0,"status":"controlled_failure"},"termination":{"exit_code":64,"status":"completed"}}
```

Los results 11.7/11.8 son diagnósticos no autoritativos conforme a la primera corrección. Solo 11.7 habilita el reintento de bootstrap; ninguno integra evidencia.

## 12. Framing, límites y reason codes

Se preservan UTF-8 sin BOM, JSON canónico, máximo 1,048,576 bytes incluyendo LF, profundidad 8, un único JSON por stdin/stdout, LF y EOF inmediatos. No existen JSON-lines, readiness o endpoint por stdout intermedio ni texto humano. Stderr nunca es autoritativo.

Reason codes añadidos al catálogo cerrado: `loopback_requirement_mismatch`, `loopback_endpoint_invalid`, `loopback_bind_collision`, `loopback_port_exhausted`, `loopback_readiness_invalid`, `loopback_readiness_timeout`, `loopback_endpoint_mismatch`, `loopback_request_invalid`. Solo `loopback_bind_collision` permite reintento de bootstrap.

HTTP limita headers a 8192 bytes, línea a 2048, body commit a 65536, conexiones simultáneas a 1 por stub y requests completos a 4096 por fase. Accept/read/write usan el deadline absoluto, con cada operación bloqueante limitada a 100 ms. No se impone expected count.

## 13. Atomicidad, concurrencia y cleanup

Seleccionar puerto, iniciar listener y obtener readiness no muta Runtime Capture. Un bind fallido tampoco. Readiness exitosa no acepta el bundle.

Solo results completos requeridos permiten preparar copias de captures, actions, snapshots y hashes y ejecutar el commit único ya definido. Child crash, stub crash, endpoint mismatch, request tardío, timeout o result inválido descarta todo.

Cada ejecución concurrente tiene endpoint, stub y store propios. El coordinator limita a un stub activo por fase y execution, y como máximo un stub por invocación concurrente; el máximo global es el número de invocaciones coordinadas, nunca un pool compartido. Colisiones se resuelven por la secuencia de candidatos, no compartiendo autoridad.

Ante fallo o muerte del coordinator, el supervisor/cleanup termina child, stub y descendientes, cierra listener/conexiones/pipes y verifica ausencia de PIDs y listeners. El listener solo enlaza IPv4 loopback y no es accesible externamente. Un resultado posterior a S2/S3 sigue siendo `late_result`.

## 14. Matriz adversarial complementaria

En `actions`, `0` significa ninguna; `real` permite únicamente comienzos Webpay completos reales. Mutación/snapshot/sellado se refieren a autoridad Runtime Capture.

| # | Escenario | Decisión / reason | Retry | Actions | Mutación | Snapshot/sellado | Cleanup |
|---:|---|---|:---:|---:|:---:|:---:|:---:|
| 1 | `execute_phase` válido | acepta / `none` | no | real | al bundle | sí/sí | normal |
| 2 | `controlled_phase` | rechaza / `protocol_failure` | no | 0 | no | no/no | sí |
| 3 | operation desconocida | rechaza / `protocol_failure` | no | 0 | no | no/no | sí |
| 4 | endpoint requerido presente | acepta / `none` | no | real | al bundle | sí/sí | normal |
| 5 | endpoint requerido ausente | rechaza / `loopback_requirement_mismatch` | no | 0 | no | no/no | sí |
| 6 | endpoint prohibido presente | rechaza / `loopback_requirement_mismatch` | no | 0 | no | no/no | sí |
| 7 | endpoint parcial | rechaza / `loopback_endpoint_invalid` | no | 0 | no | no/no | sí |
| 8 | host distinto | rechaza / `loopback_endpoint_invalid` | no | 0 | no | no/no | sí |
| 9 | esquema distinto | rechaza / `loopback_endpoint_invalid` | no | 0 | no | no/no | sí |
| 10 | puerto string/fuera de rango | rechaza / `loopback_endpoint_invalid` | no | 0 | no | no/no | sí |
| 11 | path/hash incorrecto | rechaza / `loopback_endpoint_invalid` | no | 0 | no | no/no | sí |
| 12 | colisión primer candidato | descarta intento / `loopback_bind_collision` | sí | 0 | no | no/no | sí |
| 13 | 32 colisiones | rechaza / `loopback_port_exhausted` | no | 0 | no | no/no | sí |
| 14 | stub enlazado | espera readiness / `none` | no | 0 | no | no/no | pendiente |
| 15 | readiness antes de bind | reintenta HTTP / connection refused | sí HTTP | 0 | no | no/no | no |
| 16 | readiness correcta | acepta bootstrap / `none` | no | 0 | no | no/no | no |
| 17 | token incorrecto/otro proceso | rechaza / `loopback_readiness_invalid` | no | 0 | no | no/no | sí |
| 18 | readiness intenta action | rechaza / `double_capture_detected` | no | 0 | no | no/no | sí |
| 19 | child antes de readiness | rechaza / `protocol_failure` | no | 0 | no | no/no | sí |
| 20 | endpoint child distinto | rechaza / `loopback_endpoint_mismatch` | no | 0 | no | no/no | sí |
| 21 | bind y child crash | rechaza / `child_crash` | no | 0 | no | no/no | sí |
| 22 | bind y stub crash | rechaza / `stub_crash` | no | 0 | no | no/no | sí |
| 23 | commit antes de readiness | rechaza HTTP / `loopback_readiness_invalid` | no | 0 | no | no/no | sí |
| 24 | commit en readiness path | rechaza HTTP / `loopback_request_invalid` | no | 0 | no | no/no | sí |
| 25 | readiness en commit path | rechaza HTTP / `loopback_request_invalid` | no | 0 | no | no/no | sí |
| 26 | doble readiness | acepta ambas / `none` | no | 0 | no | no/no | no |
| 27 | retransmisión Webpay real | acepta / `none` | no | 2 | al bundle | sí/sí | normal |
| 28 | otro servicio ocupa puerto | colisión o readiness inválida | según bind | 0 | no | no/no | sí |
| 29 | stdout/readiness intermedio | rechaza / `transport_framing_invalid` | no | 0 | no | no/no | sí |
| 30 | cambio silencioso de puerto | rechaza / `loopback_endpoint_mismatch` | no | 0 | no | no/no | sí |
| 31 | proceso/listener residual | rechaza certificación / `protocol_failure` | no | 0 | no | no/no | sí |
| 32 | dos casos, puertos distintos | acepta aislados / `none` | no | real | por bundle | sí/sí | normal |
| 33 | dos casos, mismo candidato | uno colisiona y avanza | sí uno | real | por bundle | sí/sí | normal |
| 34 | results en orden inverso | acepta tras ambos / `none` | no | real | al bundle | sí/sí | normal |
| 35 | request Webpay incompleto | rechaza HTTP / `loopback_request_invalid` | no | 0 | no | no/no | sí |
| 36 | readiness timeout | rechaza / `loopback_readiness_timeout` | no | 0 | no | no/no | sí |
| 37 | endpoint correcto, result distinto | rechaza / `loopback_endpoint_mismatch` | no | 0 | no | no/no | sí |
| 38 | readiness válida, bundle inválido | rechaza bundle / error original | no | 0 aceptadas | no | no/no | sí |
| 39 | bind collision result inválido | rechaza / `protocol_failure` | no | 0 | no | no/no | sí |

## 15. Auditoría de implementabilidad

Cadena cerrada:

```text
coordinator → loopback_request → bind → readiness HTTP → phase_request con endpoint
→ child → POST commit → captura stub → phase_result + loopback_result
→ validación conjunta → commit atómico → snapshot → sellado → cleanup
```

Todos los datos tienen canal autorizado: contexto, endpoint y challenge viajan por el único stdin de cada proceso; readiness viaja por el mismo puerto HTTP productivo controlado; resultados viajan una vez por stdout. No hay ciclo: endpoint precede al stub, readiness precede al child y ambos results preceden al commit. El deadline absoluto y drenaje concurrente evitan espera ilimitada. Endpoint, challenge, requests y results correlacionan case, ownership, phase y hash. No queda decisión técnica abierta.

## 16. Allowlist EA6 e integridad

El modelo es implementable exclusivamente en:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`;
2. `tests/manual/support/durable-retry-a11-coordinator.php`;
3. `tests/manual/support/durable-retry-a11-child-worker.php`;
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php`.

El contrato valida shapes/hashes/transacción; coordinator deriva endpoint y supervisa; child consume el endpoint; stub enlaza, responde readiness y captura Webpay. No requiere producto, harnesses EA5, fixtures, H1–H5, artifacts ni otro canal.

## 17. Veredicto documental

Quedan cerrados `operation.name`, endpoint, selección de puerto, colisiones, requests, readiness, orden, comunicación al child, atribución Webpay, shapes, framing, atomicidad, concurrencia, cleanup y allowlist, sin alternativa pendiente.

`A11 ACTION CAPTURE TRANSPORT BOOTSTRAP IMPLEMENTABLE TRAS SEGUNDA CORRECCIÓN NORMATIVA`
