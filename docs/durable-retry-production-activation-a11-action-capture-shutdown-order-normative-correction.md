# Tercera corrección normativa A11 del orden de shutdown de Action Capture

Estado: contrato normativo cerrado, fail-closed e implementable. Fecha: 2026-08-05.

## 1. Objeto y precedencia limitada

Esta corrección elimina exclusivamente la obligación imposible de que el stub conozca si el coordinator ya recibió un `phase_result`. Sustituye la oración de la segunda corrección, sección 7, línea 147, que exigía: «Antes del `phase_result`, un shutdown se rechaza con HTTP 409 y no termina el listener», y toda lectura equivalente que asigne al stub conocimiento del estado del child o coordinator.

La regla sustituta es:

> Un shutdown es prematuro cuando el coordinator intenta emitirlo antes de alcanzar su estado interno `PHASE_RESULT_RECEIVED_AND_STRUCTURALLY_VALIDATED`. El coordinator rechaza esa transición antes de realizar cualquier solicitud HTTP.

Se preserva íntegramente el resto de las dos correcciones anteriores: shapes, challenge/proof, endpoint, puerto, readiness, shutdown HTTP, un request/result por proceso, framing, bundles, atomicidad, resultados, ownership, phases, snapshots, hashes, sellado, cleanup y allowlist. No se añade campo, canal, stdout intermedio, estado compartido ni shape alternativo.

## 2. Autoridad temporal exclusiva

El coordinator es la única autoridad que conoce y controla esta secuencia externa:

```text
phase_result recibido y estructuralmente validado
→ shutdown solicitado
→ loopback_result recibido
→ bundle validado completamente
→ commit atómico
```

Antes de emitir shutdown debe haber recibido stdout completo del child, comprobado framing/LF/EOF, decodificado JSON, validado root `phase_result`, schema, case, ownership, phase, key set y exit compatible. Todavía no integra ni acepta el bundle.

El stub conoce únicamente su propio request inicial, endpoint, challenge, conexiones HTTP, observations/actions locales y estado del listener. La recepción o contenido de `phase_result`, el exit del child y el estado interno del coordinator no pertenecen al conocimiento autorizado del stub.

## 3. Responsabilidad local del stub

Ante `POST /a11/readiness` con `X-A11-Control: shutdown`, el stub valida exclusivamente:

- método, path, Host, headers, `Content-Length: 0`, body vacío y ausencia de bytes adicionales;
- challenge recibido y proof que el stub deriva para su response, ligados a case, ownership, phase y endpoint;
- que el request corresponde a su listener dedicado;
- que no procesó shutdown anteriormente;
- que está enlazado, no está cerrando y puede cerrar accept/conexiones.

El stub no valida ni infiere recepción, contenido, hash o framing del `phase_result`, exit del child, chronology externa o estado del coordinator. Un shutdown localmente válido se procesa aunque el stub ignore deliberadamente el estado del child. Esto no concede autoridad al stub ni implica commit.

Challenge incorrecto, endpoint/case/phase incompatibles, replay o segundo shutdown se rechazan localmente con `loopback_request_invalid`. El stub deriva el proof de response; el coordinator lo valida en comparación constante y una response con proof incorrecta produce `loopback_readiness_invalid`. El shape HTTP existente no cambia y no incorpora `phase_result_received`, digest, HMAC de result, timestamp o sequence del child.

## 4. Máquina de estados cerrada del coordinator

Reason común para una transición no autorizada: `coordinator_state_transition_invalid`. Ningún estado anterior a `COMMITTED` permite mutar Runtime Capture; la preparación usa copias sin autoridad.

| Estado | Entrada permitida y operación productora | Transición permitida | Operaciones prohibidas | Mutación | Cleanup |
|---|---|---|---|:---:|:---:|
| `INITIAL` | contexto validado | sin Webpay→`CHILD_RUNNING`; con Webpay→`LOOPBACK_STARTING` | child/stub/shutdown prematuros | no | al fallo |
| `LOOPBACK_STARTING` | candidato y request válidos; inicia stub | bind/readiness→`LOOPBACK_READY`; colisión→mismo estado con proceso nuevo; error→`FAILED` | child, shutdown, commit | no | intento fallido |
| `LOOPBACK_READY` | readiness autenticada | iniciar child→`CHILD_RUNNING` | shutdown, commit | no | al fallo |
| `CHILD_RUNNING` | stdin child cerrado; streams drenándose | stdout+exit completos→`PHASE_RESULT_RECEIVED`; fallo→`FAILED` | shutdown, integración | no | al fallo |
| `PHASE_RESULT_RECEIVED` | bytes completos y exit disponible | validación estructural→`PHASE_RESULT_RECEIVED_AND_STRUCTURALLY_VALIDATED`; error→`FAILED` | HTTP shutdown, integración | no | al fallo |
| `PHASE_RESULT_RECEIVED_AND_STRUCTURALLY_VALIDATED` | validación mínima completa | con stub→`SHUTDOWN_ALLOWED`; sin stub→`BUNDLE_VALIDATION` | integración directa | no | al fallo |
| `SHUTDOWN_ALLOWED` | guardia temporal satisfecha | emitir HTTP→`SHUTDOWN_SENT` | segundo envío, commit | no | al fallo |
| `SHUTDOWN_SENT` | request HTTP completo escrito | 204 y stdout stub completo→`LOOPBACK_RESULT_RECEIVED`; error→`FAILED` | retry, commit | no | sí |
| `LOOPBACK_RESULT_RECEIVED` | result/exit disponibles | `BUNDLE_VALIDATION` | integración parcial | no | al fallo |
| `BUNDLE_VALIDATION` | todos los results requeridos | válido→`BUNDLE_VALIDATED`; error→`FAILED` | mutación viva | no | al fallo |
| `BUNDLE_VALIDATED` | bundle completo, replay/overflow/late comprobados | preparar copias→`PREPARED` | sellar o integrar por elemento | no | al fallo |
| `PREPARED` | nuevo store/snapshot/hashes calculados en copias | commit único→`COMMITTED`; error→`FAILED` | commit parcial | no | al fallo |
| `COMMITTED` | swap único completado | sellado permitido→`CLEANED` según resultado | segundo commit | sí, una vez | normal |
| `FAILED` | cualquier fallo determinista | terminación forzada y cleanup→`CLEANED` | shutdown normal nuevo, commit, sellado | no | obligatorio |
| `CLEANED` | streams/PIDs/listeners cerrados | terminal | toda operación | no | completo |

`SHUTDOWN_ALLOWED` solo es alcanzable desde `PHASE_RESULT_RECEIVED_AND_STRUCTURALLY_VALIDATED`. La función que emite HTTP exige igualdad estricta del estado antes de abrir el socket. Un test puede invocarla anticipadamente y debe obtener `coordinator_state_transition_invalid` con cero bytes HTTP emitidos.

## 5. Validación estructural previa a shutdown

La validación mínima cerrada comprende:

1. stdout completo y proceso child terminado;
2. exactamente un JSON canónico UTF-8, sin BOM;
3. LF único obligatorio y EOF inmediato;
4. sin prefijo, segunda línea o bytes posteriores;
5. root object con key set exacto de `phase_result`;
6. schema y kind normativos;
7. case, ownership y phase exactos;
8. campos obligatorios, tipos y ausencia de extras;
9. hash sintácticamente válido y endpoint consistente con el request cuando corresponda;
10. exit del proceso compatible con `termination` y el resultado operativo.

Antes de shutdown aún quedan pendientes: validación y comparación de `loopback_result`, cadena cruzada de actions, multiplicidad, doble captura child/stub, overflow final, replay global, base hashes conjuntos, late result, preparación atómica, snapshot, hash nuevo y sellado. Por ello `SHUTDOWN_SENT` no significa que `phase_result` esté aceptado.

## 6. Shutdown normal

El request y response HTTP permanecen exactamente como en la segunda corrección. El coordinator lo emite una sola vez desde `SHUTDOWN_ALLOWED`. El challenge demuestra que el control proviene del coordinator autorizado y corresponde al stub, endpoint, case, ownership y phase; no demuestra chronology externa.

El stub valida localmente, deja de aceptar commits, termina conexiones en curso conforme al deadline, responde 204, cierra listener y emite su único `loopback_result`, LF y EOF. El coordinator drena la respuesta HTTP y stdout/stderr sin deadlock, espera el proceso y avanza solo al recibir el result completo.

Un tercero sin challenge válido recibe rechazo. Replay, challenge incorrecto o endpoint incompatible no cierran el listener. Una response con proof incorrecta es rechazada por el coordinator. Si un coordinator defectuoso elude su propia máquina y envía anticipadamente un shutdown localmente válido, el stub puede procesarlo: es `coordinator_state_transition_invalid`, la corrida falla, no llega a commit y la certificación del coordinator debe detectar el defecto.

## 7. Terminación forzada

Child crash, timeout, stdout/framing/JSON inválido, case/ownership/phase mismatch o exit incompatible impiden shutdown normal. El coordinator entra en `FAILED`, clasifica el reason original y termina stub y child supervisados:

1. deja de iniciar operaciones y cierra stdin aún abierto;
2. invoca `proc_terminate` sobre cada proceso vivo;
3. drena stdout/stderr no bloqueantes durante una gracia máxima de 250 ms;
4. si continúa vivo, invoca terminación forzada mediante `proc_terminate($process, 9)`; en Windows `proc_terminate` usa la terminación de proceso disponible aunque el número de signal no tenga semántica POSIX;
5. espera como máximo 1 segundo dentro del deadline absoluto, cierra pipes/handles y ejecuta `proc_close`;
6. verifica PID ausente y que el puerto ya no escucha; una conexión de prueba solo verifica rechazo y no transporta estado.

La terminación forzada es lifecycle cleanup, no canal autoritativo. Todo stdout completo o parcial del stub se clasifica diagnóstico no aceptado. No produce actions/captures aceptadas, snapshot, hash o sellado. La clasificación conserva `child_crash`, `timeout`, `transport_framing_invalid`, `wrong_owner`, `wrong_phase`, `unexpected_exit` o el reason original; si el proceso no termina, se añade fallo terminal `cleanup_process_residual` sin reemplazar el causal.

## 8. Atomicidad y resultados

Secuencia única:

```text
phase_result estructuralmente válido
→ shutdown normal
→ loopback_result
→ validación conjunta
→ preparación sobre copias
→ commit atómico
```

Un `loopback_result` inválido después del shutdown descarta todo: cero captures/actions, snapshot/hash nuevo o sellado. Un `phase_result` estructuralmente inválido nunca habilita shutdown normal; causa terminación forzada y cleanup. El orden de recepción física puede variar, pero el estado lógico no: si el stub termina prematuramente, su result se conserva sin aceptar hasta que el child resulte válido o se descarte todo.

Resultados posteriores a S2/S3 siguen siendo `late_result`. Controlled failure o uncertain completos siguen la transacción de fallo de la primera corrección, sin sellado. Ninguna evidencia se integra por elemento.

## 9. Requests adversariales y conocimiento

Un request externo no autenticado se rechaza sin cambiar estado. Un request autenticado repetido se rechaza por shutdown ya consumido. Un request con referencia incorrecta se rechaza antes de cerrar listener.

La amenaza de un coordinator legítimo deliberadamente defectuoso no se resuelve asignando conocimiento imposible al stub. Se prueba directamente la guardia de transición y la ausencia de bytes HTTP antes de `SHUTDOWN_ALLOWED`. Si esa guardia falla, la implementación EA6 no es certificable y el bundle nunca puede comprometerse.

## 10. Matriz adversarial de shutdown

| # | Escenario | Responsable | Estado anterior→siguiente | Decisión/reason | HTTP | Mutación | Snapshot/sellado | Cleanup |
|---:|---|---|---|---|:---:|:---:|:---:|:---:|
| 1 | shutdown tras phase válido | coordinator | `SHUTDOWN_ALLOWED→SHUTDOWN_SENT` | acepta/`none` | sí | no | no/no | posterior |
| 2 | antes de leer stdout | coordinator | `CHILD_RUNNING→FAILED` | rechaza/`coordinator_state_transition_invalid` | no | no | no/no | sí |
| 3 | stdout parcial | coordinator | `CHILD_RUNNING→FAILED` | rechaza/`transport_framing_invalid` | no | no | no/no | sí |
| 4 | JSON child inválido | coordinator | `PHASE_RESULT_RECEIVED→FAILED` | rechaza/`transport_framing_invalid` | no | no | no/no | sí |
| 5 | otro case | coordinator | `PHASE_RESULT_RECEIVED→FAILED` | rechaza/`wrong_owner` | no | no | no/no | sí |
| 6 | ownership incorrecto | coordinator | `PHASE_RESULT_RECEIVED→FAILED` | rechaza/`wrong_owner` | no | no | no/no | sí |
| 7 | phase incorrecta | coordinator | `PHASE_RESULT_RECEIVED→FAILED` | rechaza/`wrong_phase` | no | no | no/no | sí |
| 8 | exit incompatible | coordinator | `PHASE_RESULT_RECEIVED→FAILED` | rechaza/`unexpected_exit` | no | no | no/no | sí |
| 9 | tras estructura, antes de bundle conjunto | coordinator | `SHUTDOWN_ALLOWED→SHUTDOWN_SENT` | acepta/`none` | sí | no | no/no | posterior |
| 10 | loopback result inválido | coordinator | `LOOPBACK_RESULT_RECEIVED→FAILED` | rechaza/error específico | ya | no | no/no | sí |
| 11 | shutdown duplicado | stub/coordinator | `SHUTDOWN_SENT→FAILED` | rechaza/`loopback_request_invalid` | no segundo conforme | no | no/no | sí |
| 12 | replay HTTP | stub | cerrando→cerrando | rechaza/`loopback_request_invalid` | adversarial | no | no/no | sí |
| 13 | challenge incorrecto | stub | listener→listener | rechaza/`loopback_request_invalid` | adversarial | no | no/no | no |
| 14 | proof incorrecta en response | coordinator | `SHUTDOWN_SENT→FAILED` | rechaza/`loopback_readiness_invalid` | sí | no | no/no | sí |
| 15 | endpoint incorrecto | stub | listener→listener | rechaza/`loopback_endpoint_mismatch` | adversarial | no | no/no | no |
| 16 | externo no autenticado | stub | listener→listener | rechaza/`loopback_request_invalid` | adversarial | no | no/no | no |
| 17 | transición coordinator inválida | coordinator | cualquiera→`FAILED` | rechaza/`coordinator_state_transition_invalid` | no | no | no/no | sí |
| 18 | stub recibe válido sin conocer child | stub | listener→cerrando | acepta localmente/`none` | adversarial | no | no/no | sí |
| 19 | child crash | coordinator | `CHILD_RUNNING→FAILED` | terminación forzada/`child_crash` | no | no | no/no | sí |
| 20 | child timeout | coordinator | `CHILD_RUNNING→FAILED` | terminación forzada/`timeout` | no | no | no/no | sí |
| 21 | stub crash antes de shutdown | coordinator | cualquier activo→`FAILED` | rechaza/`stub_crash` | no | no | no/no | sí |
| 22 | stub timeout tras shutdown | coordinator | `SHUTDOWN_SENT→FAILED` | rechaza/`timeout` | sí | no | no/no | sí |
| 23 | cierre listener normal | stub | cerrando→terminado | acepta/`none` | sí | no | no/no | normal |
| 24 | proceso residual | coordinator | `FAILED→FAILED` | rechaza/`cleanup_process_residual` | no | no | no/no | obligatorio |
| 25 | listener residual | coordinator | `FAILED→FAILED` | rechaza/`cleanup_listener_residual` | no | no | no/no | obligatorio |
| 26 | stub termina primero | coordinator | activo→espera child o `FAILED` | no acepta anticipadamente | según child | no | no/no | según bundle |
| 27 | commit atómico exitoso | coordinator | `PREPARED→COMMITTED` | acepta/`none` | previo | sí una vez | sí/sí | normal |
| 28 | rechazo conjunto | coordinator | `BUNDLE_VALIDATION→FAILED` | rechaza/error específico | previo | no | no/no | sí |
| 29 | tardío tras S2 | coordinator | validación→`FAILED` | rechaza/`late_result` | no normal | no | no/no | sí |
| 30 | tardío tras S3 | coordinator | validación→`FAILED` | rechaza/`late_result` | no normal | no | no/no | sí |

## 11. Auditoría integral de implementabilidad

Cadena auditada:

```text
coordinator → puerto → stub → bind → readiness → child → phase_result
→ shutdown → loopback_result → validación conjunta → commit → snapshot
→ sellado → cleanup
```

Cada dato tiene emisor, receptor y canal único: endpoint/challenge por stdin; requests productivos y control por el listener HTTP dedicado; results finales por stdout. El stub ya no necesita conocer datos sin canal. La guardia temporal pertenece al coordinator, que sí observa stdout y exit del child. No existe ciclo: readiness precede child, validación estructural precede shutdown y ambos results preceden commit.

El POST autenticado permite terminación normal; `proc_terminate` permite cleanup cuando no existe result estructural válido. Drenaje no bloqueante y deadlines cerrados evitan deadlock. Case, ownership, phase, endpoint y hashes correlacionan resultados. No hay autoridad duplicada ni decisión técnica pendiente derivada de shutdown.

## 12. Allowlist de EA6

La implementación continúa limitada a:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`;
2. `tests/manual/support/durable-retry-a11-coordinator.php`;
3. `tests/manual/support/durable-retry-a11-child-worker.php`;
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php`.

La máquina y guardia viven en coordinator; el contrato valida estados/reasons/bundles; child no cambia; stub conserva solo validaciones locales. No requiere modificar harnesses EA5, producto, fixtures, documentación durante EA6 ni un quinto archivo.

## 13. Límites

Esta corrección no implementa EA6, no cambia producto, no corrige los nueve casos EA7, no crea fixtures, child, stub o H1–H5 y no materializa counts. No altera expected actions ni `external_actions`.

## 14. Veredicto documental

La autoridad temporal, validación estructural, shutdown normal, requests adversariales, terminación forzada, atomicidad, matriz y allowlist quedan cerrados. El stub queda liberado de conocimiento imposible sin añadir canales ni shapes.

`A11 ACTION CAPTURE SHUTDOWN ORDER IMPLEMENTABLE TRAS TERCERA CORRECCIÓN NORMATIVA`
