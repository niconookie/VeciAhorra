# Corrección normativa A11-WR-04 del relay HTTP de propuesta action

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Veredicto y alcance

**A11-WR-04 HTTP ACTION PROPOSAL RELAY TRANSPORT IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Esta autoridad gobierna exclusivamente el transporte request-local de la observación `scheduler.action_schedule` para `execution_id` activo, `invocation_id=a11_000000000057_fd`, `case_id=A11-WR-04` y `phase=first_delivery`.

El proceso HTTP server/router es observer; uno de los dos requester children es relay; el coordinator es consumer. El canal único es la respuesta HTTP existente. No existen stdout/stderr del server como evidencia, persistencia, canal lateral, ownership transferido, participant ID, participant index, winner ni cardinalidad colectiva.

## 2. Precedencia

Esta corrección amplía, solo para A11-WR-04 bajo activation binding válido, el body de la respuesta HTTP publicada por la autoridad complementaria. Prevalece sobre cualquier lectura que prohíba al requester transportar evidencia observada por otro proceso. Conserva el framing de `phase_result`, el loopback independiente, los 62 invocation IDs, las 372 cuentas, expected `webpay.commit=1` y `scheduler.action_schedule=1`, materialización serial, hashes y transacción.

`webpay.commit` continúa perteneciendo exclusivamente al stub. Esta autoridad no cambia producto ordinario: sin activation A11 el body y status permanecen byte-equivalentes al contrato vigente.

## 3. Roles cerrados

| Rol | Proceso | Función | Ownership action |
|---|---|---|---|
| `http_schedule_observer` | `php -S` router supervisado | atiende POST, carga WordPress, ejecuta producto y `schedule()` | `scheduler.action_schedule` |
| `http_post_relay_01` | requester child ordinal 1 | origina POST, valida y copia relay | ninguno |
| `http_post_relay_02` | requester child ordinal 2 | origina POST, valida y copia relay | ninguno |
| `webpay_loopback_observer` | stub supervisado | atiende el commit gateway controlado | `webpay.commit` |
| `action_capture_consumer` | coordinator | valida, almacena y coordina | ninguno |

Requester y observer son identidades protocolarias distintas. Causalidad HTTP, status o business result no confieren ownership.

## 4. Canal HTTP único

Se selecciona un outer body JSON. Bajo activation A11, la respuesta tiene `Content-Type: application/json; charset=utf-8`, conserva el status HTTP productivo y usa exactamente:

```json
{"a11_action_proposal_relay":{"binding_challenge":"0123456789abcdef0123456789abcdef","case_id":"A11-WR-04","envelope_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","execution_id":"a11_20260803010101_1_0123456789abcdef","invocation_id":"a11_000000000057_fd","kind":"http_action_proposal_relay","observer_entrypoint":"durable-retry-a11-http-router.php","observer_operation":"execute_phase","observer_pid":1234,"observer_role":"http_schedule_observer","observer_slot":"a11o_A11-WR-04_first_delivery_http_schedule_server","operation":"execute_phase","ownership_token":"a11_20260803010101_1_0123456789abcdef","participant_action_proposals":[],"phase":"first_delivery","port":"scheduler.action_schedule","proposal_count":0,"relay_role":"http_post_relay_01","request_ordinal":1,"schema":"veciahorra-a11-http-action-proposal-relay/v1"},"product_response":{"data":{},"success":true}}
```

Key set outer exacto: `a11_action_proposal_relay:object`, `product_response:object`. No hay aliases ni tercera clave. `product_response` es exactamente el array que `WebpayReturnController::process()` habría retornado sin activation. El injector no cambia contenido, status ni semántica productiva.

Un header dedicado y un segundo body quedan prohibidos. HTTP usa CRLF propio; el JSON no lleva LF de framing stdout.

## 5. Request control binding

Los dos POST conservan verbo HTTP, endpoint y body productivos. Añaden exactamente estos headers ASCII, una vez cada uno:

| Header | Regla |
|---|---|
| `X-VeciAhorra-A11-Execution-Id` | execution ID EA5, 1..64 bytes |
| `X-VeciAhorra-A11-Invocation-Id` | literal `a11_000000000057_fd` |
| `X-VeciAhorra-A11-Case-Id` | literal `A11-WR-04` |
| `X-VeciAhorra-A11-Phase` | literal `first_delivery` |
| `X-VeciAhorra-A11-Ownership-Token` | byte-identical a execution ID |
| `X-VeciAhorra-A11-Requester-Slot` | `http_post_relay_01|http_post_relay_02` |
| `X-VeciAhorra-A11-Request-Ordinal` | `1|2`, coherente con slot |
| `X-VeciAhorra-A11-Binding-Challenge` | 32 lowercase hex |

Solo `POST /wp-json/veciahorra/v1/payments/webpay/return` sobre el endpoint supervisado acepta estos headers cuando `VECIAHORRA_A11_CERTIFICATION=1` ya activó el graph aislado al bootstrap. Los headers no entran al payload productivo, no se persisten ni se loguean. Duplicado, folding, whitespace exterior, coma combinada o header A11 fuera de activation son inválidos.

## 6. Challenges

El coordinator ejecuta `bin2hex(random_bytes(16))` dos veces después de congelar invocation y antes de spawn. Los resultados son distintos, 32 lowercase hex, propiedad temporal del coordinator y keyed por request ordinal. Viajan en el header y se repiten en el relay.

Requester conserva solo su challenge readonly. Server lo valida y lo copia, nunca lo genera. Coordinator usa `hash_equals()`, marca consumo una vez y elimina ambos valores en cleanup. Ausente, cruzado, reutilizado o inválido aborta la invocation.

## 7. Schema relay

Schema único: `veciahorra-a11-http-action-proposal-relay/v1`.

Key set exacto: `schema`, `kind`, `execution_id`, `invocation_id`, `case_id`, `phase`, `operation`, `ownership_token`, `request_ordinal`, `binding_challenge`, `observer_role`, `observer_slot`, `observer_pid`, `observer_entrypoint`, `observer_operation`, `relay_role`, `port`, `proposal_count`, `participant_action_proposals`, `envelope_hash`.

`kind=http_action_proposal_relay`; operation y observer operation son `execute_phase`; observer role es `http_schedule_observer`; relay role coincide con el request; port es `scheduler.action_schedule`; proposal count es integer `0|1`; proposals es lista de igual longitud. PID pertenece a `1..PHP_INT_MAX`.

`envelope_hash` es SHA-256 lowercase del JSON canónico del mismo objeto sin `envelope_hash`. Claves se ordenan por bytes UTF-8; arrays conservan orden; integers son JSON; no hay coerción, BOM, floats, null o extensiones.

## 8. Observer slot

Literal único: `a11o_A11-WR-04_first_delivery_http_schedule_server`.

Regex: `^a11o_A11-WR-04_first_delivery_http_schedule_server$`; longitud 50 bytes ASCII; case-sensitive. Se asigna al descriptor del server antes del spawn. Lookup usa igualdad byte a byte. PID se obtiene de `proc_get_status()` y debe coincidir con el registry supervisor.

El futuro catálogo de identidades debe mapear este slot uno-a-uno a un participant ID sin cambiar el slot. Timing, request ganador y PID no intervienen en el literal.

## 9. Propuesta relayed

Mientras no exista participant ID, la lista contiene objetos schema `veciahorra-a11-observer-action-proposal/v1`. Key set exacto:

```json
{"action_kind":"schedule","case_id":"A11-WR-04","kind":"observer_action_proposal","local_ordinal":1,"observer_slot":"a11o_A11-WR-04_first_delivery_http_schedule_server","ownership_token":"a11_20260803010101_1_0123456789abcdef","payload":{"shape":"scheduler_action_schedule/v1","values":{"action_id":71,"group":"veciahorra-durable-retry","hook":"veciahorra_durable_retry_reconciliation"}},"phase":"first_delivery","port":"scheduler.action_schedule","productive_identity":{"type":"scheduled_action","value":"71"},"proposal_identity":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","provenance":{"observation":"decorator_entry","operation":"execute_phase","role":"http_schedule_observer"},"schema":"veciahorra-a11-observer-action-proposal/v1"}
```

`proposal_identity` usa exactamente SHA-256 del objeto de identidad publicado por la autoridad de propuestas: case, ownership, phase, port, action kind, productive identity, payload y provenance. No incluye observer slot, PID, request ordinal o challenge. Productive value es decimal del action ID positivo.

La contribución semántica futura es una unidad; no existe clave `delta`. Se prohíben `participant_id`, `participant_index`, `action_delta`, `base_action_hash`, snapshot, expected counts, integrated state, PID, challenge y request ordinal dentro de la propuesta.

## 10. Cero o una propuesta

El relay siempre está presente en toda respuesta A11 atribuible y usa exclusivamente:

- `proposal_count=0` y `participant_action_proposals=[]`; o
- `proposal_count=1` y una lista con `local_ordinal=1`.

Ausencia del outer relay no significa cero: es `http_relay_missing`. Esto distingue request válida sin schedule, truncamiento, activation ausente y fallo server. Más de una propuesta es inválido. La validación colectiva entre ambos relays queda fuera de esta corrección.

## 11. Capture point

El capture point es la entrada a la función decorada `DurableRetryExternalSchedulerInterface::schedule(string $hook,array $arguments,string $group,string $scheduledFor): DurableRetryExternalScheduleResult`, inmediatamente antes de delegar a inner, después de validar context y argumentos.

El comienzo productivo ocurre al invocar inner. Si inner retorna un resultado con scheduled action ID positivo, la propuesta usa ese ID y se sella. Si inner lanza, la acción ya comenzó: el decorator conserva propuesta solo cuando el adapter entrega en un callback interno request-local el action ID positivo antes de lanzar; sin ID atribuible el request falla con `http_schedule_observation_incomplete` y no inventa propuesta. Resultado sin ID válido es fallo.

El decorator retorna o relanza exactamente el valor/excepción inner. No altera guardias, timing, retries o producto.

## 12. Buffer request-local

Owner: `DurableRetryA11HttpActionProposalRequestContext`, instancia nueva por request creada después de validar headers. Vive en el mismo object graph request-local del router, decorator e injector. No es static, global, singleton, service container compartido ni persistente.

Key única: observer slot. Cardinalidad 0..1. `record()` inserta una vez; `seal()` impide escritura; `readOnce()` exige seal, retorna copia y marca consumo. Un `finally` ejecuta `clear()` aun cuando controller o injector fallen. Una segunda request recibe otra instancia.

## 13. Decorator

Path futuro: `tests/manual/support/durable-retry-a11-http-router.php`. Namespace `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11HttpScheduleCaptureDecorator implements
    \VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface
{
    public function __construct(
        private readonly \VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface $inner,
        private readonly DurableRetryA11HttpActionProposalRequestContext $context,
        private readonly DurableRetryA11HttpActivationBinding $binding
    ) {}

    public function schedule(string $hook, array $arguments, string $group, string $scheduledFor):
        \VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult
    {
        throw new \LogicException('a11_http_relay_contract_only');
    }
}
```

Las funciones `findPending()` y `cancel()` conservan firmas de la interfaz, delegan sin proposal y permanecen forbidden para el port de esta corrección. El graph A11 se construye por request después del binding, antes de controller; producto ordinario conserva composición normal.

## 14. Response injector

`DurableRetryA11HttpActionProposalResponseInjector::inject(array $productResponse, DurableRetryA11HttpActionProposalRequestContext $context, DurableRetryA11HttpActivationBinding $binding, int $observerPid): array` sella y consume el buffer después de `WebpayReturnController::process()` y antes de emitir headers/body.

Retorna exclusivamente el outer §4. Errores productivos atribuibles también llevan relay vacío o proposal ya observada; error anterior a binding válido no usa outer. Fallo al injectar invalida request e invocation, retorna HTTP 500 controlado sin evidence aceptable y limpia context.

## 15. Parser requester

Path futuro: `tests/manual/support/durable-retry-a11-child-worker.php`. Secuencia cerrada: enviar binding; recibir status, headers y body completos; exigir content type; separar dos keys; validar product response sin inferir action; parsear relay; recanonicalizar; comprobar bytes; validar hash, identities, challenge, slot, PID, ordinal, port y propuesta/lista vacía; conservar JSON canónico original; añadirlo al resultado requester; limpiar buffers.

Requester no recalcula proposal identity salvo para validarla, no modifica JSON y no crea propuesta.

## 16. Campo de `phase_result`

Campo reservado autoritativo: `relayed_action_proposal_envelopes`.

Tipo exacto en cada requester: lista de longitud uno con el objeto relay decodificado y sus bytes canónicos conservados en el DTO de transporte. Los bytes no aparecen como segunda clave JSON: el codec prueba igualdad al serializar el objeto. Propuestas observadas directamente continúan en `participant_action_proposals`; capture y operation result permanecen separados.

Dos envelopes, envelope duplicado, vacío o port distinto son inválidos. La futura topología debe incluir esta clave en el shape superior de `phase_result`.

## 17. Validación coordinator

El coordinator valida primero lifecycle requester y luego envelope: requester esperado, invocation, case, phase, ownership, ordinal, challenge, slot, PID server registrado, entrypoint, operations, port, count, proposal, hash, source exclusivo y ausencia de proposal directa contradictoria.

Al aceptar, almacena por `(invocation_id,observer_slot,request_ordinal)`, conserva relay role y bytes, y marca challenge consumido. Ownership sigue en observer slot. No incorpora al participant set, materializa o avanza hash antes de freeze.

## 18. Dos responses

Las combinaciones `[1,0]` y `[0,1]` conservan una propuesta y distinto relay role; `[0,0]`, `[1,1]`, duplicadas o diferentes se almacenan como evidencia estructural y se entregan al posterior validator colectivo. Esta corrección no las declara colectivamente válidas.

Orden de llegada, primero/último, PID requester y duración no alteran bytes, ownership ni validación individual. Ningún relay se cancela al recibir el otro.

## 19. Separación del stub

Stub usa observer slot futuro distinto, `loopback_result`, listener gateway y proposal `webpay.commit`. Schedule usa outer HTTP de la respuesta productiva, relay requester y `phase_result`. Stores temporales y lifecycles son distintos; ambos convergen solo en el participant set global posterior.

Server nunca emite Webpay; stub nunca emite schedule; respuesta productiva nunca combina proposal Webpay; requester no copia `loopback_result`.

## 20. Framing y límites

UTF-8 sin BOM, JSON canónico compacto, `Content-Length` decimal exacto, sin chunked. Relay máximo 32768 bytes antes de insertarlo; outer completo máximo 65536 bytes. Request headers A11 combinados máximo 4096 bytes.

Peor caso: hook y group admiten 1024 bytes y cada byte requiere hasta seis bytes JSON: `2×1024×6=12288`; keys/literales relay y proposal menos de 4096; binding/IDs/PID/hash menos de 1024; product response máximo 32768. Total `12288+4096+1024+32768=50176<65536`. LF stdout no se incluye; CRLF pertenece a framing HTTP y `Content-Length` cuenta solo body.

Content-Length ausente/inexacto, chunked, truncamiento, body extra, duplicate key, whitespace no canónico del relay, múltiples outer values o exceso se rechazan.

## 21. Lifecycle

Orden: validar plan y bindings; iniciar stub y obtener readiness; iniciar HTTP server y readiness; congelar PID/slot; spawn dos requesters; recibir ready; release; POST concurrentes; context por request; producto; captures; inject responses; requester parse; requester `phase_result`; wait/exit/EOF de ambos; validar relays; shutdown server; wait/EOF server; shutdown stub; obtener `loopback_result`; wait/EOF stub; verificar listener cerrado; preparar freeze global.

Requests pueden solaparse desde release hasta response. Cada capture precede a su injector. Server permanece vivo hasta recibir ambas responses y relays. Ninguna proposal es globalmente aceptable si requester, response, server, listener o cleanup falla.

## 22. Atomicidad y rollback

Cualquier fallo invalida toda invocation. Se descartan contexts, relays, requester relay state, observer-slot store, proposals, participant set parcial y candidatos de hash. Action snapshot base, combined state, hashes integrados y expected counts permanecen idénticos.

La evidencia Webpay válida del stub no se integra si falla schedule relay. Cleanup termina requesters, server y stub; drena/cierra pipes; cierra listeners; limpia challenges, contexts y stores.

## 23. API PHP exacta

Paths: DTOs/codecs/validators en `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`; router context/decorator/injector en `tests/manual/support/durable-retry-a11-http-router.php`; requester parser en `tests/manual/support/durable-retry-a11-child-worker.php`; coordinator validator/store en `tests/manual/support/durable-retry-a11-coordinator.php`. Namespace común `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11HttpActivationBinding
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $invocationId,
        public readonly string $caseId,
        public readonly string $phase,
        public readonly string $ownershipToken,
        public readonly string $requesterSlot,
        public readonly int $requestOrdinal,
        public readonly string $bindingChallenge
    ) {}
}

final class DurableRetryA11HttpActionProposalRequestContext
{
    public function record(DurableRetryA11ObserverActionProposal $proposal): void { throw new \LogicException('a11_http_relay_contract_only'); }
    public function seal(): void { throw new \LogicException('a11_http_relay_contract_only'); }
    public function readOnce(): array { throw new \LogicException('a11_http_relay_contract_only'); }
    public function clear(): void { throw new \LogicException('a11_http_relay_contract_only'); }
}

final class DurableRetryA11HttpActionProposalRelayCodec
{
    public static function encode(DurableRetryA11HttpActionProposalRelay $relay): string { throw new \LogicException('a11_http_relay_contract_only'); }
    public static function parse(string $json): DurableRetryA11HttpActionProposalRelay { throw new \LogicException('a11_http_relay_contract_only'); }
}

final class DurableRetryA11HttpActionProposalRelayValidator
{
    public static function validate(DurableRetryA11HttpActionProposalRelay $relay, DurableRetryA11HttpActivationBinding $binding, int $observerPid): DurableRetryA11HttpActionProposalRelay { throw new \LogicException('a11_http_relay_contract_only'); }
}

final class DurableRetryA11HttpActionProposalRelayStore
{
    public function put(string $invocationId, string $observerSlot, int $requestOrdinal, DurableRetryA11HttpActionProposalRelay $relay): void { throw new \LogicException('a11_http_relay_contract_only'); }
    public function all(string $invocationId, string $observerSlot): array { throw new \LogicException('a11_http_relay_contract_only'); }
    public function cleanup(string $invocationId): void { throw new \LogicException('a11_http_relay_contract_only'); }
}
```

DTO `DurableRetryA11ObserverActionProposal` contiene las trece propiedades §9. DTO `DurableRetryA11HttpActionProposalRelay` contiene las veinte propiedades §7. `DurableRetryA11HttpActionProposalResponseInjector::inject()` usa la firma §14. `DurableRetryA11HttpActionProposalRequesterParser::parse(int $status,array $headers,string $body,DurableRetryA11HttpActivationBinding $binding,int $observerPid): DurableRetryA11HttpActionProposalRelay`. `DurableRetryA11HttpActionProposalCoordinatorValidator::validateAndStore(...)` retorna void y hace un put atómico. Cada clase lanza `DurableRetryA11HttpActionProposalRelayException` con reason §24.

## 24. Reasons cerrados

| Reason | Condición |
|---|---|
| `http_relay_activation_binding_missing` | header requerido ausente |
| `http_relay_activation_binding_invalid` | binding o duplicado inválido |
| `http_relay_challenge_invalid` | challenge ausente, cruzado o consumido |
| `http_relay_missing` | outer relay ausente |
| `http_relay_duplicated` | relay/header/body repetido |
| `http_relay_malformed` | JSON/schema/key set/tipo inválido |
| `http_relay_noncanonical` | bytes distintos de recanonicalización |
| `http_relay_oversized` | límite excedido |
| `http_relay_truncated` | Content-Length/body incompleto |
| `http_relay_observer_slot_invalid` | slot desigual |
| `http_relay_observer_pid_invalid` | PID no es server supervisado |
| `http_relay_observer_entrypoint_invalid` | entrypoint desigual |
| `http_relay_requester_invalid` | relay role/requester desigual |
| `http_relay_request_ordinal_invalid` | ordinal desigual |
| `http_relay_schedule_port_invalid` | port no schedule |
| `http_relay_schedule_proposal_duplicated` | count mayor a uno o duplicado |
| `http_relay_schedule_proposal_unexpected` | shape/identity/payload inválido |
| `http_relay_schedule_proposal_forged_by_requester` | child declara ownership |
| `http_relay_schedule_proposal_emitted_by_coordinator` | coordinator sintetiza |
| `http_relay_schedule_proposal_emitted_by_stub` | stub emite schedule |
| `http_relay_request_context_contaminated` | evidencia entre requests |
| `http_relay_request_context_unsealed` | lectura anterior a seal |
| `http_relay_response_construction_failed` | injector falla |
| `http_relay_response_status_incompatible` | status no pertenece al router controlado |
| `http_schedule_observation_incomplete` | acción comenzó sin identidad atribuible |
| `http_relay_server_lifecycle_invalid` | server exit/wait/EOF inválido |
| `http_relay_requester_lifecycle_invalid` | requester exit/wait/EOF inválido |
| `http_relay_residual_listener` | listener sigue abierto |
| `http_relay_residual_process` | proceso sigue vivo |
| `http_relay_residual_buffer` | context/store/challenge persiste |

Precedencia: binding→framing/size→schema/canonicalidad→identity/challenge→source/port/proposal→lifecycle→residuos. Cada reason aborta invocation, produce cero materialización/integración, ejecuta cleanup total y conserva combined state.

## 25. Allowlist futura exacta

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`;
2. `tests/manual/support/durable-retry-a11-http-router.php`;
3. `tests/manual/support/durable-retry-a11-child-worker.php`;
4. `tests/manual/support/durable-retry-a11-coordinator.php`;
5. `tests/manual/durable-retry-a11-wr-04-http-action-proposal-relay-test.php`.

No se autorizan producto, materializer general, catálogo de IDs, cardinalidad colectiva, topología global ni suites históricas.

## 26. Matriz adversarial

| ID | Escenario | Resultado |
|---|---|---|
| R01/R02 | requester 1 / requester 2 transporta | relay individual válido, ownership server |
| R03 | responses inversas | mismo store por ordinal |
| R04 | lista vacía | relay individual válido |
| R05/R06 | ambas vacías / ambas con proposal | evidencia entregada a validator colectivo posterior |
| R07/R08 | duplicada / diferentes | evidencia preservada, decisión colectiva posterior |
| R09/R10 | challenge cruzado / reutilizado | `http_relay_challenge_invalid` |
| R11/R12 | invocation / ownership incorrecto | binding invalid |
| R13/R14 | PID / slot incorrecto | reason específico |
| R15 | requester se declara observer | forged by requester |
| R16 | coordinator sintetiza | emitted by coordinator |
| R17 | stub emite schedule | emitted by stub |
| R18 | server emite Webpay | owner Webpay previo rechaza |
| R19 | canal ausente | missing |
| R20–R22 | malformed / noncanonical / truncated | reason homónimo |
| R23 | oversized | oversized |
| R24 | outer o key duplicado | duplicated |
| R25 | context contaminado | context contaminated |
| R26 | schedule lanza sin ID | observation incomplete |
| R27 | schedule retorna ID | una proposal sellada |
| R28 | status incompatible | status incompatible |
| R29/R30 | requester exit no cero / sin EOF | requester lifecycle invalid |
| R31 | shutdown server inválido | server lifecycle invalid |
| R32–R34 | listener / proceso / buffer residual | reason específico |
| R35 | antes de freeze | cero hashes avanzados |
| R36 | matriz expected | 372 counts intactos |

PASS exige R01–R36, simetría por request ordinal, server como observer, requester como relay, un único outer HTTP y cero residuos.

## 27. Cierre

Quedan cerrados: canal outer body, request binding, challenges, relay/proposal schemas, observer slot, cero/una proposal, capture point, context, decorator, injector, parser, campo futuro de phase result, coordinator store, separación stub, framing, lifecycle, atomicidad, APIs, reasons, allowlist y matriz.

No se crean participant IDs, indices, cardinalidad colectiva o winner. Server conserva ownership; requester solo relayea bytes validados; coordinator nunca sintetiza.

**A11-WR-04 HTTP ACTION PROPOSAL RELAY TRANSPORT IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
