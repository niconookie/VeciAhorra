# Cuarta corrección normativa A11 de la autoridad `loopback_required`

Estado: contrato documental cerrado, fail-closed e implementable. Fecha: 2026-08-05.

## 1. Objeto y precedencia limitada

Esta corrección resuelve exclusivamente la ausencia de autoridad readonly para determinar `loopback_required`. Precisa y sustituye la segunda corrección, sección 6, líneas 86 y 94, donde se atribuía la decisión a un «plan readonly» sin definir su estructura. En adelante ese término significa únicamente el `action_invocation_plan` definido aquí.

No modifica el capture plan EA5, los nueve campos de sus entries, snapshots, fixture IDs, expected actions ni business identifiers. Preserva las tres correcciones anteriores en endpoint, transporte, readiness, shutdown, autoridad temporal, bundles, atomicidad y cleanup. No crea un protocolo interproceso, canal o shape HTTP alternativo.

## 2. Fuente de autoridad única

La única fuente es un objeto readonly denominado `action_invocation_plan`, schema `veciahorra-a11-action-invocation-plan/v1`. El harness/coordinator padre lo crea declarativamente antes de bootstrap; el contrato EA6 lo valida completo; el coordinator lo conserva en memoria y resuelve una entry por `invocation_id`; child y stub reciben únicamente la proyección ya resuelta dentro de sus requests normativos.

No reside en capture plan, snapshot, expected actions, fixture IDs, case ID, environment, filesystem ni producto. No existe default. Ausencia o invalidez impide abrir procesos, sockets o listeners.

## 3. Schema exacto

Shape superior, claves obligatorias y sin adicionales:

```json
{"entries":{"a11_202608050001_fd":{"case_id":"A11-WR-06","entrypoint_id":"execute_phase","execution_id":"a11_20260803010101_2_0123456789abcdef","invocation_id":"a11_202608050001_fd","loopback_required":true,"phase":"first_delivery"}},"kind":"action_invocation_plan","schema":"veciahorra-a11-action-invocation-plan/v1"}
```

Orden lógico superior: `schema`, `kind`, `entries`; serialización real canónica ordena claves por bytes. `entries` es object map canónicamente ordenado por su key `invocation_id`, con 1..4096 entries.

Cada entry tiene exactamente seis claves: `invocation_id`, `execution_id`, `case_id`, `phase`, `entrypoint_id`, `loopback_required`. Serialización canónica las ordena por bytes. Tipos:

- `invocation_id`: string ASCII único, regex `^a11_[0-9]{12}_(?:setup|fd|replay|assertions|cleanup|observe_[a-z_]+)$`, 17..64 bytes;
- `execution_id`: string con la gramática EA5 vigente;
- `case_id`: string con la gramática A11 vigente;
- `phase`: enum `setup|first_delivery|replay|assertions_finales|cleanup`;
- `entrypoint_id`: enum cerrado `setup|execute_phase|assertions|cleanup|observe_woocommerce_payment_complete|observe_scheduler_action_schedule|observe_scheduler_action_cancel|observe_legacy_retry_schedule|observe_durable_worker_execute`;
- `loopback_required`: boolean JSON/PHP estricto, siempre presente, nunca `null`, sin coerción ni alias.

La key del map debe ser byte por byte igual a `entry.invocation_id`. Entries duplicadas, keys adicionales, objects parciales o valores no canónicos se rechazan. Tamaño máximo 1,048,576 bytes, profundidad máxima 8 y JSON canónico EA5.

## 4. Ampliación normativa sin alterar EA5

EA5 permanece intacto porque `action_invocation_plan` es un contrato EA6 separado, no un campo del capture plan ni snapshot. El constructor lógico EA6 de la invocation se amplía de:

```text
(executionId, entrypoint, timeoutSeconds)
```

a:

```text
(executionId, entrypoint, timeoutSeconds, invocationId, actionInvocationPlan)
```

Los tres primeros parámetros conservan semántica EA5. La ruta histórica EA5 continúa usando el transporte `capture_delta` y no activa Action Transport. Toda ruta EA6 que produzca `phase_request` exige los cinco parámetros; no acepta invocation v1, default o plan vacío.

El schema nuevo no forma parte de S0–S4, no cambia `plan_hash`, snapshot hash o action hash y no se persiste. El coordinator lo valida y congela antes de consultar el store. Los consumidores EA6 son únicamente contract y coordinator; child/stub consumen su proyección en los requests ya normados. No existe migración ni compatibilidad retroactiva dentro de Action Transport.

## 5. Vinculación con una invocation

La unión usa exclusivamente `invocationId` del objeto invocation contra la key exacta de `entries`. No usa posición ni case ID. Debe existir exactamente una entry y luego deben coincidir byte por byte `invocation_id`, `execution_id`, `case_id`, `phase` y `entrypoint_id` con invocation, store activo y operación solicitada.

La validación global exige keys únicas; toda entry debe ser consumida exactamente una vez dentro de la ejecución declarada. Entry no consumida al finalizar es `loopback_authority_orphan_entry`. Invocation sin entry es `loopback_authority_invocation_missing`. Un plan no puede reutilizarse para otra execution.

El orden del map no afecta resolución, pero el JSON debe ser canónico. El plan queda congelado inmediatamente después de validarse; se conserva su SHA-256 canónico en memoria exclusivamente para detectar mutación. Rehash diferente antes de iniciar child es `loopback_authority_mutated`.

## 6. Contrato de `loopback_required`

`loopback_required` es boolean estricto y obligatorio:

- `false`: no se crea stub, bind, readiness o shutdown; `phase_request.loopback_endpoint` es exactamente `null`; el bundle usa `loopback_result=null`.
- `true`: se crea stub antes del child y `loopback_endpoint` debe ser el objeto completo de la segunda corrección en `loopback_request`, `phase_request` y `loopback_result`.

`false` con endpoint no nulo o `true` con endpoint nulo/inválido es `loopback_requirement_mismatch`. No se permiten object vacío, string, integer, puerto cero, endpoint ficticio o listener no usado.

La decisión aplica a una sola combinación `(invocation_id, execution_id, case_id, phase, entrypoint_id)`. Queda inmutable desde la validación del plan hasta `CLEANED`. Dos fases del mismo caso son invocations distintas y pueden tener valores diferentes.

## 7. Relación con `execute_phase`

`execute_phase` permanece genérico. No codifica Webpay en su nombre y consulta únicamente la entry ya resuelta. Los cinco entrypoints `observe_*` tienen `loopback_required=false`. Setup, assertions y cleanup también exigen `false`. Solo `execute_phase` puede declarar `true`.

Un `true` con otro `entrypoint_id` produce `loopback_authority_operation_mismatch`. No se crean aliases de operation ni entrypoints diferenciados por Webpay.

## 8. Prohibición absoluta de inferencia

El coordinator no deriva la decisión desde case ID o sus segmentos, phase sin entry, expected actions, counts, business identifiers, token Webpay, endpoint, fixture IDs, snapshots, hardcode de 31 casos, archivos externos, comportamiento del child, timeout, error de conexión, intento de bind o presencia de acciones observadas.

Intentar decidir sin resolver la entry produce `loopback_authority_inference_forbidden` antes de efectos. La tabla de casos de esta corrección es material declarativo que los harnesses futuros convierten en entries; coordinator no la incorpora como lookup hardcoded.

## 9. Orden de bootstrap

Rama `false`:

1. recibir y canonicalizar plan readonly;
2. validar schema completo y congelar hash;
3. resolver por invocation ID y contrastar identificadores;
4. leer boolean `false`;
5. construir `phase_request` con `loopback_required=false` y endpoint `null`;
6. transitar `INITIAL→CHILD_RUNNING`, omitiendo `LOOPBACK_STARTING`, `LOOPBACK_READY`, `SHUTDOWN_ALLOWED`, `SHUTDOWN_SENT` y `LOOPBACK_RESULT_RECEIVED`;
7. iniciar child, recibir/validar `phase_result` y transitar directamente a `BUNDLE_VALIDATION` con `loopback_result=null`.

Rama `true`:

1. validar/congelar/resolver la misma autoridad;
2. leer boolean `true`;
3. transitar `INITIAL→LOOPBACK_STARTING`;
4. seleccionar puerto, iniciar stub, bind y readiness;
5. transitar `LOOPBACK_READY→CHILD_RUNNING` con endpoint completo;
6. recibir y validar estructuralmente `phase_result`;
7. habilitar shutdown conforme a la tercera corrección;
8. recibir `loopback_result` y validar conjuntamente antes de preparar/commit.

Todo fallo de autoridad ocurre antes de proceso o listener. Todo fallo después del bind usa cleanup normativo.

## 10. Catálogo cerrado de reason codes

| Reason | Condición |
|---|---|
| `loopback_authority_missing` | plan ausente |
| `loopback_authority_structure_invalid` | JSON/estructura/key set/canonicalización/tamaño inválido |
| `loopback_authority_version_unsupported` | schema distinto |
| `loopback_authority_kind_invalid` | kind distinto |
| `loopback_authority_required_field_missing` | campo obligatorio ausente |
| `loopback_authority_type_invalid` | tipo estricto inválido, incluido boolean no boolean |
| `loopback_authority_identifier_invalid` | gramática/key distinta de invocation ID |
| `loopback_authority_duplicate_entry` | más de una entry semántica para invocation |
| `loopback_authority_invocation_missing` | invocation sin entry |
| `loopback_authority_orphan_entry` | entry no consumida al cierre |
| `loopback_authority_binding_mismatch` | execution/case/phase/entrypoint no coincide |
| `loopback_authority_operation_mismatch` | `true` fuera de `execute_phase` |
| `loopback_requirement_mismatch` | boolean y endpoint/result/stub incompatibles |
| `loopback_authority_inference_forbidden` | decisión por fuente distinta |
| `loopback_authority_mutated` | hash readonly cambia tras validación |
| `loopback_authority_conflict` | otra autoridad intenta declarar valor distinto |

Precedencia exacta: lectura/ausencia; JSON/estructura; versión; kind; claves desconocidas; campos ausentes; tipos; identificadores; duplicados; invocation ausente; binding; operation; contradicción boolean/endpoint; intento de bootstrap; mutación; orphan al cierre. Solo se reporta el primer error.

## 11. Asignación normativa de los 31 casos

La unidad es la invocation de fase. `true` se justifica por una operación controlada que invoca `WebpayReturnGatewayInterface::commit()`; `false` significa que esa invocation no cruza el recurso Webpay. La tabla no se deriva de counts y no es lookup de coordinator.

| Caso | First delivery | Replay | Autoridad operativa |
|---|:---:|:---:|---|
| A11-OP-01 | true | false | entrega nueva invoca commit; replay no lo reinvoca |
| A11-OP-02 | true | false | ruta legacy inicial invoca commit; replay no lo reinvoca |
| A11-OP-03 | false | false | indisponibilidad scheduler, sin operación commit |
| A11-OP-04 | false | false | redelivery terminal, sin operación commit |
| A11-OP-05 | false | false | successor/worker, sin operación commit |
| A11-CON-01 | false | false | schedule concurrente, sin commit |
| A11-CON-02 | false | false | executor, sin commit |
| A11-CON-03 | false | false | generación interna, sin commit |
| A11-CON-04 | false | false | generación vigente, sin commit |
| A11-CON-05 | false | false | cancel/execute, sin commit |
| A11-CR-01 | false | false | creación externa, sin commit |
| A11-CR-02 | false | false | reejecución claimed, sin commit |
| A11-CR-03 | false | false | recovery post-attempt, sin commit |
| A11-CR-04 | false | false | redelivery post-result, sin commit |
| A11-CR-05 | false | false | redelivery pre-return, sin commit |
| A11-WR-01 | true | false | token nuevo invoca commit una vez |
| A11-WR-02 | true | false | delivery inicial invoca commit; replay no |
| A11-WR-03 | true | false | excepción post-routing ocurre después de commit |
| A11-WR-04 | true | false | POST concurrente controlado cruza commit |
| A11-WR-05 | false | false | recovery desde return existente, sin nuevo commit |
| A11-WR-06 | true | false | single application invoca commit una vez |
| A11-EX-01 | false | false | autoridad legacy, sin commit |
| A11-EX-02 | false | false | durable noop |
| A11-EX-03 | false | false | ya scheduled |
| A11-EX-04 | false | false | indeterminate noop |
| A11-EX-05 | false | false | read exception antes de efecto |
| A11-EX-06 | false | false | consumed noop |
| A11-EX-07 | false | false | stale callback |
| A11-EX-08 | false | false | executor durable, sin commit |
| A11-EX-09 | false | false | replay noop |
| A11-EX-10 | false | false | legacy histórico, sin commit |

Cardinalidad: 31 casos únicos, 62 invocations de fase, 7 `true`, 55 `false`, cero omitidos y cero duplicados. Setup/assertions/cleanup adicionales son siempre false por sección 7.

## 12. Matriz adversarial exhaustiva

En todos los rechazos de autoridad: mutación no, procesos 0, listener 0, cleanup de memoria readonly.

| # | Precondición/autoridad | Decisión | Permitido | Prohibido | Reason | Resultado |
|---:|---|---|---|---|---|---|
| 1 | bool false, endpoint null | sin loopback | child | stub/bind/readiness/shutdown | none | bundle sin loopback |
| 2 | bool true, endpoint válido posterior al bind | con loopback | stub→readiness→child→shutdown | child previo | none | bundle conjunto |
| 3 | clave ausente | rechaza | nada | bootstrap | `loopback_authority_required_field_missing` | cleaned |
| 4 | valor null | rechaza | nada | bootstrap | `loopback_authority_type_invalid` | cleaned |
| 5 | integer 0 | rechaza | nada | coerción | `loopback_authority_type_invalid` | cleaned |
| 6 | integer 1 | rechaza | nada | coerción | `loopback_authority_type_invalid` | cleaned |
| 7 | string `false` | rechaza | nada | coerción | `loopback_authority_type_invalid` | cleaned |
| 8 | string `true` | rechaza | nada | coerción | `loopback_authority_type_invalid` | cleaned |
| 9 | array | rechaza | nada | bootstrap | `loopback_authority_type_invalid` | cleaned |
| 10 | object | rechaza | nada | bootstrap | `loopback_authority_type_invalid` | cleaned |
| 11 | clave desconocida | rechaza | nada | bootstrap | `loopback_authority_structure_invalid` | cleaned |
| 12 | entry duplicada semántica | rechaza | nada | selección arbitraria | `loopback_authority_duplicate_entry` | cleaned |
| 13 | invocation ausente | rechaza | nada | inferencia | `loopback_authority_invocation_missing` | cleaned |
| 14 | entrypoint mismatch | rechaza | nada | ejecución | `loopback_authority_binding_mismatch` | cleaned |
| 15 | execution mismatch | rechaza | nada | ejecución | `loopback_authority_binding_mismatch` | cleaned |
| 16 | endpoint no null con false | rechaza | nada | listener | `loopback_requirement_mismatch` | cleaned |
| 17 | endpoint null con true | rechaza | nada | child | `loopback_requirement_mismatch` | cleaned |
| 18 | endpoint inválido con true | rechaza | cleanup stub si bind ocurrió | child | loopback_endpoint_invalid | cleaned |
| 19 | mutación post-validación | rechaza | nada nuevo | procesos/commit | `loopback_authority_mutated` | cleaned |
| 20 | plan cambiado entre lectura/ejecución | rechaza por rehash | nada | ejecución | `loopback_authority_mutated` | cleaned |
| 21 | inferencia por case ID | rechaza | nada | lookup hardcoded | `loopback_authority_inference_forbidden` | cleaned |
| 22 | stub antes de resolver | rechaza | nada | process open | `loopback_authority_inference_forbidden` | cleaned |
| 23 | child antes de resolver | rechaza | nada | process open | `loopback_authority_inference_forbidden` | cleaned |
| 24 | rama false intenta shutdown | rechaza | validación bundle | HTTP | `loopback_requirement_mismatch` | cleaned |
| 25 | rama true omite shutdown | rechaza | cleanup forzado | commit | incomplete_bundle | cleaned |
| 26 | fallo previo al bind | rechaza | cleanup memoria | proceso/listener | error original | cleaned |
| 27 | fallo posterior al bind | rechaza | terminate/drain/close | commit | error original | cleaned |
| 28 | invocation true seguida por false | resuelve independientemente | ambos flujos secuenciales | reuse de entry | none | dos bundles aislados |
| 29 | plan ausente | rechaza | nada | bootstrap | `loopback_authority_missing` | cleaned |
| 30 | versión antigua/otra | rechaza | nada | fallback | `loopback_authority_version_unsupported` | cleaned |
| 31 | entry huérfana | rechaza al cierre | cleanup | certificación | `loopback_authority_orphan_entry` | cleaned |
| 32 | otra autoridad discrepa | rechaza | nada | elección de fuente | `loopback_authority_conflict` | cleaned |

## 13. Compatibilidad con transportes y máquina temporal

Child conserva `phase_request→phase_result`; stub conserva `loopback_request→loopback_result`. No cambian stdin/stdout, shutdown, challenge/proof o endpoint. El coordinator sigue siendo autoridad temporal y valida el plan antes de `INITIAL` efectivo.

La rama false omite estados exclusivos del listener y entra directamente en `CHILD_RUNNING`; esto ya estaba permitido por la primera corrección para fases sin Webpay. La rama true conserva los 15 estados y el orden de la tercera corrección. Ninguna rama integra antes de `BUNDLE_VALIDATED/PREPARED`.

## 14. Allowlist futura EA6

La implementación cabe exclusivamente en:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` — schema/validator readonly y reason codes;
2. `tests/manual/support/durable-retry-a11-coordinator.php` — invocation v2, resolución/hash/consumo y branching;
3. `tests/manual/support/durable-retry-a11-child-worker.php` — valida la proyección del request;
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php` — solo se inicia para true.

Los valores declarativos de futuras H1–H5 se materializarán cuando esos harnesses sean autorizados en EA8; EA6 certifica el contrato con invocations controladas sin editar harnesses EA5. No hace falta un quinto archivo ni cambio productivo.

## 15. Auditoría de implementabilidad

- Existe una sola autoridad: sí, action invocation plan v1.
- Está disponible antes del stub: sí, argumento readonly obligatorio de invocation EA6.
- Vinculación inequívoca: sí, invocation ID más cinco campos contrastados.
- Inferencia por case ID: prohibida y testable.
- Rama false sin stub/listener: cerrada.
- Rama true preserva protocolo: sí.
- Schema, errors y precedencia: cerrados.
- EA5: no se amplía; contrato paralelo EA6 explícito.
- 31 casos: 31/31, 62 invocations, 7 true y 55 false.
- Allowlist: cuatro archivos suficientes.
- Decisiones materiales delegadas: ninguna.

## 16. Límites

Este documento no implementa EA6, EA7–EA10, fixtures o H1–H5; no materializa 372 counts y no modifica producto, tests o autoridades anteriores.

## 17. Veredicto documental

La fuente, schema, tipo, cardinalidad, inmutabilidad, binding, asignación completa, branching, errors, precedencia, compatibilidad y allowlist quedan definidos sin alternativa.

`A11 ACTION CAPTURE LOOPBACK REQUIREMENT IMPLEMENTABLE TRAS CUARTA CORRECCIÓN NORMATIVA`
