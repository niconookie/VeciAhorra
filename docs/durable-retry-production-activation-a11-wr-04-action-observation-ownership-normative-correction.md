# VeciAhorra Durable Retry A11-WR-04: corrección normativa de ownership de observación

Fecha normativa: 2026-08-05. Alcance exclusivo: `A11-WR-04`, fase `first_delivery`, invocation `a11_000000000057_fd`.

## 1. Veredicto

`A11-WR-04 ACTION OBSERVATION OWNERSHIP IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`

Esta autoridad cierra procesos materiales, grupo concurrente, dependencias, owners, eligibility, result kinds, lifecycle, transportes, futura incorporación al participant set y cleanup. No implementa EA6.

## 2. Autoridades inspeccionadas y precedencia de lectura

Se inspeccionaron conjuntamente las correcciones complementary, fixture contract, expected actions caso-específicas, action capture transport, HTTP action proposal relay transport, participant action proposal materialization, loopback requirement/transport, action invocation plan e ID catalog, coordinator API y bundle transaction; además del controller, service, materializer, initial router, external schedule coordinator, router `php -S`, requester children y stub Webpay.

La autoridad obligatoria de transporte es `durable-retry-production-activation-a11-wr-04-http-action-proposal-relay-transport-normative-correction.md`. Esta corrección consume literalmente sus schemas, bindings, challenges, observer slot, parser, store y lifecycle; no los redefine.

## 3. Inventario procesal cerrado

| Proceso | Cantidad | Función | Creator | Owner del proceso | PID | Entrypoint / operation | Clase | Grupo | Dependencia | Producción | Proposal ownership | Result kind | Lifecycle y cleanup |
|---|---:|---|---|---|---|---|---|---|---|---|---|---|---|
| coordinator | 1 | supervisar, validar y almacenar | harness A11-WR-04 | harness raíz | PID del harness | harness / `execute_invocation` | infraestructura | no | no | no ejecuta efecto | ninguno | ninguno | nace primero; espera hijos; rollback total; limpia stores, pipes y bindings |
| POST requester child | 2 | ready/release, originar un POST y relayear response | coordinator | coordinator | dos PID distintos supervisados | `durable-retry-a11-child-worker.php` / `execute_phase` | participante futuro | sí, exactamente ambos | no | origina request controlada | ninguno | `phase_result` | ready, release, POST, result, wait, exit 0, EOF; cerrar pipes/buffers |
| HTTP server/router `php -S` | 1 | ejecutar WordPress y observar schedule | coordinator | coordinator | PID único supervisado | `durable-retry-a11-http-router.php` / `execute_phase` | infraestructura observadora | no | servicio del grupo | sí: controller→service→materializer→router→coordinator de schedule | `scheduler.action_schedule` | respuesta HTTP, nunca stdout EA6 | readiness, dos responses, shutdown, wait, exit 0, EOF; listener y contexts a cero |
| loopback Webpay stub | 1 | recibir commit y observarlo directamente | coordinator | coordinator | PID único supervisado | `durable-retry-a11-http-webpay-stub.php` / `observe_webpay_commit` | dependencia loopback | no | sí | frontera productiva controlada de commit | `webpay.commit` | `loopback_result` | readiness, commits, shutdown, result, wait, exit 0, EOF; listener/buffers a cero |

Un PID se congela desde el handle supervisado; nunca llega desde datos no confiables. No se confunden los requester children con el server que ejecuta WordPress.

## 4. Grupo concurrente y dependencias

El grupo concurrente A11-WR-04 es exactamente el conjunto de los dos POST requester children esperados. Los dos alcanzan ready y el coordinator escribe release para ambos sin esperar actividad productiva entre escrituras; desde ese barrier ambos originan sus POST. El HTTP server atiende las requests solapables, pero es infraestructura observadora y no miembro. El stub es dependencia loopback externa al grupo. El coordinator supervisa y tampoco es miembro.

Membership describe quién compite como requester. Dependency describe un servicio requerido. Una dependencia puede aportar una propuesta a la misma invocation sin convertirse en requester concurrente. No se introducen group ID, participant ID, índices, winner ni cardinalidad colectiva.

## 5. Owner exclusivo de `webpay.commit`

El loopback Webpay stub es el único observation owner. Tiene acceso directo a la recepción del commit productivo y, después de su semántica de éxito, crea una propuesta `webpay.commit` dentro de su único `loopback_result`. Requesters, HTTP server y coordinator tienen prohibido emitirla. El coordinator no la sintetiza. Esta propuesta nunca usa el relay HTTP de schedule.

## 6. Owner exclusivo de `scheduler.action_schedule`

El HTTP server/router es observer y owner exclusivo mediante el slot ya publicado `a11o_A11-WR-04_first_delivery_http_schedule_server`. El capture point es el inicio productivo de `$this->scheduler->schedule(...)` en `DurableRetryExternalScheduleCoordinator`, sujeto a la semántica de éxito publicada por el relay.

El buffer request-local crea la propuesta solamente después de cumplir esa semántica. El requester no adquiere ownership: recibe el envelope, comprueba sus bytes y lo transporta literalmente. El coordinator conserva slot y PID del server. Status HTTP, resultado de negocio, expected counts o estado convergente jamás permiten inferir una propuesta.

## 7. Matriz cerrada de observation eligibility

| Proceso | `webpay.commit` | `woocommerce.payment_complete` | `scheduler.action_schedule` | `scheduler.action_cancel` | `legacy.retry_schedule` | `durable.worker_execute` |
|---|---|---|---|---|---|---|
| coordinator | forbidden | forbidden | forbidden | forbidden | forbidden | forbidden |
| POST requester child 1 | forbidden | forbidden | forbidden | forbidden | forbidden | forbidden |
| POST requester child 2 | forbidden | forbidden | forbidden | forbidden | forbidden | forbidden |
| HTTP server/router | forbidden | forbidden | eligible | forbidden | forbidden | forbidden |
| loopback Webpay stub | eligible | forbidden | forbidden | forbidden | forbidden | forbidden |

`eligible` autoriza observar directamente el capture point y crear propuesta. Relay eligibility autoriza al requester a copiar el envelope schedule recibido en su `phase_result`. Proposal ownership identifica al proceso que observó el efecto. Las tres propiedades son independientes: los requesters son relay-eligible y observation-forbidden; el server conserva ownership.

## 8. Result kinds y canales únicos

Cada requester produce exactamente un `phase_result`, transporta su resultado propio y contiene el campo autorizado `relayed_action_proposal_envelopes` con exactamente el relay recibido. No produce directamente schedule.

El server no produce stdout result EA6. Inserta `a11_action_proposal_relay` en la respuesta HTTP controlada y reserva stdout para lifecycle; stderr no es evidencia. El stub produce un `loopback_result` con Webpay. El coordinator no produce propuestas: valida ambos transportes y los almacena temporalmente.

No hay canal adicional: schedule usa response HTTP→requester→`phase_result`; Webpay usa `loopback_result`.

## 9. Causalidad remota versus observación

El requester causa remotamente la request. El HTTP server ejecuta el efecto interno. El stub observa commit y el server observa schedule. El requester solo relayea. Ownership pertenece al proceso con acceso directo al capture point; causalidad remota, envío, recepción, parsing o almacenamiento no transfieren ownership.

## 10. Simetría de las dos requests

Cualquiera de los request ordinals 1 o 2 puede transportar el único relay con una propuesta; el otro transporta un relay válido con `proposal_count=0` y lista vacía. Envelope ausente no equivale a cero. Orden de llegada, finalización o parsing no determina owner ni winner. El observer continúa siendo el mismo server; ordinal indica por cuál request viajó la observación. No se fija cardinalidad colectiva futura.

## 11. Binding integral de invocation

Cada actor queda vinculado a `execution_id`, `invocation_id=a11_000000000057_fd`, `case_id=A11-WR-04`, `phase=first_delivery`, ownership token y operation. Cada requester valida su endpoint, ordinal y challenge. El server valida headers de control, endpoint, operation, ordinal, challenge y binding común antes de WordPress; copia esos valores al relay y añade su slot/PID supervisado. El stub valida su endpoint y binding loopback. El coordinator valida cada campo, PID de cada handle, unicidad, fuentes y hashes con comparación constante cuando corresponda.

Proyección canónica de ownership:

```json
{"case_id":"A11-WR-04","execution_id":"a11_20260803010101_1_0123456789abcdef","invocation_id":"a11_000000000057_fd","owners":{"scheduler.action_schedule":"a11o_A11-WR-04_first_delivery_http_schedule_server","webpay.commit":"loopback_webpay_stub"},"ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","process_counts":{"coordinator":1,"http_server":1,"requester":2,"webpay_stub":1},"schema":"veciahorra-a11-wr04-observation-ownership/v1"}
```

## 12. Flujo normativo completo

1. Congelar invocation plan.
2. Crear bindings y challenges distintos para los dos requesters.
3. Iniciar stub y registrar PID.
4. Iniciar HTTP server y registrar PID/slot.
5. Validar readiness de ambos servicios.
6. Iniciar exactamente dos requester children.
7. Recibir dos ready válidos.
8. Liberarlos simultáneamente.
9. Cada requester origina un POST.
10. El server valida el control binding antes de producto.
11. El server ejecuta ambas rutas productivas.
12. El stub observa directamente `webpay.commit`.
13. El server observa directamente `scheduler.action_schedule`.
14. El server usa un buffer request-local aislado.
15. El injector incorpora el relay en la response correspondiente.
16. El requester parsea, valida y conserva literalmente el relay.
17. Cada requester emite su único `phase_result`.
18. Tras shutdown válido, el stub emite su único `loopback_result`.
19. El coordinator valida results, sources y lifecycle.
20. Almacena propuestas temporalmente bajo sus observers.
21. No materializa, integra ni avanza hash.
22. Ejecuta shutdown del server y del stub en el orden publicado.
23. Espera exit, EOF y ejecuta cleanup.
24. Confirma cero procesos, listeners, pipes, challenges, contexts, buffers y stores residuales.

## 13. Lifecycle, aceptación y rollback

La aceptación requiere dos ready/release, dos `phase_result`, exactamente dos relays válidos, un `loopback_result`, procesos esperados, waits/exit/EOF válidos y listeners cerrados. Cualquier fallo invalida toda la invocation aunque la otra propuesta sea válida. Rollback descarta stores temporales, conserva combined state y cero hash advances. Cleanup termina procesos vivos, drena/cierra pipes, cierra listeners y elimina bindings, challenges, response bodies, contexts y buffers.

## 14. API normativa exacta

Namespace futuro: `VeciAhorra\Tests\Manual\DurableRetryA11`. Estas firmas consumen `DurableRetryA11HttpActionProposalRelay` y el observer slot ya publicados; no alteran sus clases.

```php
<?php
declare(strict_types=1);
namespace VeciAhorra\Tests\Manual\DurableRetryA11;

enum DurableRetryA11Wr04ProcessRole: string { case COORDINATOR='coordinator'; case REQUESTER='requester'; case HTTP_SERVER='http_server'; case WEBPAY_STUB='webpay_stub'; }
enum DurableRetryA11Wr04ProcessClass: string { case PARTICIPANT='participant'; case INFRASTRUCTURE='infrastructure'; case DEPENDENCY='dependency'; }
enum DurableRetryA11Wr04Eligibility: string { case ELIGIBLE='eligible'; case FORBIDDEN='forbidden'; }

final readonly class DurableRetryA11Wr04ProcessSpec {
    public function __construct(public DurableRetryA11Wr04ProcessRole $role, public int $count, public DurableRetryA11Wr04ProcessClass $class, public bool $concurrentGroupMember, public bool $externalDependency, public string $entrypoint, public string $operation, public string $resultKind) {}
}
final readonly class DurableRetryA11Wr04ObservationOwner {
    public function __construct(public string $port, public DurableRetryA11Wr04ProcessRole $role, public string $observerSlot) {}
}
final readonly class DurableRetryA11Wr04Topology {
    /** @param list<DurableRetryA11Wr04ProcessSpec> $processes @param list<DurableRetryA11Wr04ObservationOwner> $owners */
    public function __construct(public string $executionId, public string $invocationId, public string $ownershipToken, public array $processes, public array $owners) {}
}
interface DurableRetryA11Wr04OwnershipCatalog {
    public function topology(string $executionId, string $ownershipToken): DurableRetryA11Wr04Topology;
    public function ownerForPort(string $port): DurableRetryA11Wr04ObservationOwner;
    public function eligibility(DurableRetryA11Wr04ProcessRole $role, string $port): DurableRetryA11Wr04Eligibility;
}
interface DurableRetryA11Wr04OwnershipValidator {
    /** @param list<array<string,mixed>> $phaseResults */
    public function validate(DurableRetryA11Wr04Topology $topology, array $phaseResults, array $loopbackResult, int $serverPid, int $stubPid): void;
    /** @return array<string,mixed> */
    public function canonicalProjection(DurableRetryA11Wr04Topology $topology): array;
}
final class DurableRetryA11Wr04OwnershipException extends \RuntimeException {
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
```

No existe parámetro participant ID, index, cardinality ni winner.

## 15. Failure semantics específicas

Requester ausente/extra, server o stub ausente, owner duplicado/incorrecto, propuesta directa requester, síntesis coordinator, relay schedule ausente/inválido, Webpay fuera del stub, lifecycle inválido, challenge cruzado, PID observer incorrecto, envelope duplicado, response truncada, frame adicional, wait/EOF inválido o residuo invalidan completamente la invocation. Ningún fallo admite aceptación parcial.

El orden de evaluación es binding→topología/roles→membership/dependency→owner/eligibility→relay/loopback y framing→lifecycle→residuos. La primera categoría aplicable determina reason. El efecto común es rechazo, cero materialización, cero integración y cero hash advances; rollback y cleanup siguen §13.

## 16. Catálogo cerrado de reasons

| Reason | Condición | Precedencia | Efecto | Rollback | Cleanup |
|---|---|---:|---|---|---|
| `wr04_ownership_invocation_binding_invalid` | invocation/case/phase/token/operation/endpoint/ordinal/challenge desigual | 1 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_topology_invalid` | conteos o procesos distintos | 2 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_role_missing_or_duplicated` | rol requerido ausente o multiplicidad inválida | 3 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_membership_invalid` | miembro del grupo distinto de los dos requesters | 4 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_dependency_invalid` | stub/server clasificado incorrectamente | 5 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_owner_missing` | puerto esperado sin owner | 6 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_owner_duplicated` | más de un owner para un puerto | 7 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_owner_forbidden` | source no autorizado crea propuesta | 8 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_port_eligibility_mismatch` | celda no coincide con §7 | 9 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_relay_role_mismatch` | requester/relay role u ordinal desigual | 10 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_observer_relay_conflation` | relay se declara observer/owner | 11 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_unexpected_proposal_source` | requester/coordinator/server/stub emite puerto ajeno | 12 | invalida invocation | descarta stores | total §13 |
| `wr04_ownership_server_lifecycle_invalid` | readiness/shutdown/wait/exit/EOF/listener server inválido | 13 | invalida invocation | descarta stores | termina server |
| `wr04_ownership_requester_lifecycle_invalid` | ready/release/result/wait/exit/EOF requester inválido | 14 | invalida invocation | descarta stores | termina requesters |
| `wr04_ownership_stub_lifecycle_invalid` | readiness/shutdown/result/wait/exit/EOF stub inválido | 15 | invalida invocation | descarta stores | termina stub |
| `wr04_ownership_cleanup_residual` | proceso/listener/pipe/challenge/context/buffer/store residual | 16 | invalida invocation | descarta stores | reintenta cleanup acotado y reporta |

Response truncada, frame adicional, relay ausente/duplicado, challenge cruzado y observer PID inválido se rechazan primero como `wr04_ownership_invocation_binding_invalid` si rompen binding; si el binding ya es válido pero la fuente/rol contradice ownership, aplica la reason específica de menor número posterior.

## 17. Precedencia normativa

Esta corrección consume obligatoriamente el relay transport y resuelve ownership A11-WR-04. Prevalece para process roles, membership, dependency classification y port eligibility del caso. No modifica relay schema, loopback transport, expected counts, materialization ni bundle transaction. No define winner, cardinalidades, IDs, índices o canales.

Será consumida por concurrent participant identity catalog, concurrent proposal group cardinality y multiprocess topology; esas autoridades posteriores no podrán reasignar owners.

## 18. Allowlist futura exacta

1. DTOs específicos de roles, topology y ownership A11-WR-04.
2. Catalog y validator específicos A11-WR-04.
3. Integración de lectura con el relay contract existente, sin cambio de schema.
4. Wiring específico A11-WR-04 para asociar handles supervisados.
5. Harness específico de ownership A11-WR-04.

Quedan fuera: materialización, catálogo global, cardinalidad colectiva, participant IDs, índices, winner, topology EA6 completa y cualquier canal nuevo.

## 19. Matriz adversarial cerrada

| Escenario | Resultado normativo |
|---|---|
| R01 topología válida | acepta temporalmente dos owners |
| R02 requester 1 relayea schedule | acepta, owner server |
| R03 requester 2 relayea schedule | acepta, owner server |
| R04 responses en orden inverso | acepta por ordinal, sin winner |
| R05 owner Webpay stub | acepta |
| R06 owner schedule server | acepta |
| R07 requester intenta observar | `wr04_ownership_observer_relay_conflation` |
| R08 coordinator sintetiza | `wr04_ownership_unexpected_proposal_source` |
| R09 stub emite schedule | `wr04_ownership_owner_forbidden` |
| R10 server emite Webpay | `wr04_ownership_owner_forbidden` |
| R11 server ausente | topology invalid |
| R12 stub ausente | topology invalid |
| R13 requester ausente | topology invalid |
| R14 requester excedente | topology invalid |
| R15 challenge cruzado | invocation binding invalid |
| R16 observer PID incorrecto | invocation binding invalid |
| R17 relay duplicado | invocation binding invalid |
| R18 loopback duplicado | unexpected proposal source |
| R19 lifecycle coordinator inválido | topology invalid y cleanup total |
| R20 lifecycle requester 1 inválido | requester lifecycle invalid |
| R21 lifecycle requester 2 inválido | requester lifecycle invalid |
| R22 lifecycle server inválido | server lifecycle invalid |
| R23 lifecycle stub inválido | stub lifecycle invalid |
| R24 response truncada | invocation binding invalid |
| R25 frame adicional | invocation binding invalid |
| R26 wait inválido | lifecycle del proceso correspondiente |
| R27 EOF inválido | lifecycle del proceso correspondiente |
| R28 listener residual | cleanup residual |
| R29 proceso residual | cleanup residual |
| R30 buffer/pipe/context residual | cleanup residual |
| R31 expected counts preservados | acepta sin reinterpretarlos |
| R32 cero hash advances | obligatorio antes de autoridad posterior |
| R33 cero canales adicionales | obligatorio |
| R34 relay válido con cero propuestas | acepta como presencia explícita |
| R35 envelope schedule ausente | invocation binding invalid |
| R36 owner duplicado | owner duplicated |

## 20. Cierre normativo

Los procesos publicados son 1 coordinator, 2 requesters, 1 HTTP server y 1 stub. Los owners quedan cerrados: stub para `webpay.commit`, server slot para `scheduler.action_schedule`. Los dos transportes convergen únicamente en almacenamiento temporal del coordinator; una autoridad posterior podrá incorporarlos al participant set sin alterar provenance. Tras éxito o fallo quedan cero procesos, listeners, pipes, challenges, request-local buffers y stores residuales.
