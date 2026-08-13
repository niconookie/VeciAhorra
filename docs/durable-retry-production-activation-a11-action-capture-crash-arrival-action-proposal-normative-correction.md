# Corrección normativa A11 EA6 de propuesta de acción en crash arrival

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Veredicto y alcance

**A11 EA6 CRASH ARRIVAL ACTION PROPOSAL IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Esta corrección gobierna únicamente la supervivencia, antes del kill externo, de la única `participant_action_proposal` first-delivery observada por cada participante `A11-CR-01..05`. No implementa EA6, no define la topología completa y no modifica producto, expected actions ni materialización.

La solución única es un solo `barrier_arrival` ampliado que contiene exactamente una propuesta. Se prohíben un frame separado, una segunda línea, bytes intermedios, `phase_result`, `action_delta` producido por el participante e integración anterior al cierre del participant set.

## 2. Precedencia de autoridades

En este alcance prevalece este documento sobre `durable-retry-production-activation-a11-action-capture-intermediate-barrier-control-plane-normative-correction.md` §§4, 7, 13, 14 y 18 únicamente en: schema del arrival, key set, profundidad, límite de frame, DTO, parser y validación de la propuesta incluida.

Permanecen vigentes sin cambio: cinco ventanas, stdout como canal único, un objeto y un LF, flush, bloqueo, kill externo, deadlines, 17 reasons del control-plane, EOF posterior al kill, cleanup y cero residuos. También permanecen vigentes el schema completo de propuesta, el comparador, la materialización central, la cadena action hash, las 372 cuentas y la transacción de bundle.

## 3. Schema canónico ampliado

Schema literal: `veciahorra-a11-barrier-arrival-action-proposal/v1`.

Key set superior exacto:

```json
{"arrival_ordinal":1,"binding_challenge":"0123456789abcdef0123456789abcdef","case_id":"A11-CR-01","kind":"barrier_arrival","ownership_token":"a11_20260803010101_1_0123456789abcdef","participant_action_proposal":{"action_kind":"schedule","case_id":"A11-CR-01","kind":"participant_action_proposal","local_ordinal":1,"ownership_token":"a11_20260803010101_1_0123456789abcdef","participant_id":"a11p_A11-CR-01_first_delivery_01","payload":{"shape":"scheduler_action_schedule/v1","values":{"action_id":71,"group":"veciahorra-durable-retry","hook":"veciahorra_durable_retry_reconciliation"}},"phase":"first_delivery","port":"scheduler.action_schedule","productive_identity":{"type":"scheduled_action","value":"71"},"provenance":{"observation":"decorator_entry","operation":"execute_phase","role":"external_scheduler"},"schema":"veciahorra-a11-participant-action-proposal/v1"},"participant_id":"a11p_A11-CR-01_first_delivery_01","participant_index":1,"phase":"first_delivery","pid":1234,"schema":"veciahorra-a11-barrier-arrival-action-proposal/v1","window_id":"CRASH_AFTER_EXTERNAL_ACTION_CREATED"}
```

Las doce claves superiores obligatorias son: `schema:string`, `kind:string`, `ownership_token:string`, `case_id:string`, `phase:string`, `participant_id:string`, `participant_index:int`, `window_id:string`, `pid:int`, `binding_challenge:string`, `arrival_ordinal:int` y `participant_action_proposal:object`. `kind` es `barrier_arrival`; `phase` es `first_delivery`; `participant_index` y `arrival_ordinal` son `1`; PID pertenece a `1..PHP_INT_MAX`; challenge es 32 hex lowercase.

No hay claves opcionales. Toda clave adicional, ausente, duplicada o con alias es inválida. El orden lógico es el de la oración anterior; los bytes JSON reales usan claves ordenadas ascendentemente por bytes UTF-8 conforme a la canonicalización EA5. Codificación UTF-8 válida sin BOM, integers decimales JSON, cero coerción y profundidad máxima 5.

El frame es exactamente `canonical_json(arrival) + "\n"`. Hay una escritura completa, un LF, `fflush(STDOUT) === true`, ningún byte posterior y EOF únicamente después del kill.

## 4. Sustitución del shape anterior

Para las cinco ventanas queda sustituido `veciahorra-a11-barrier-control/v1` por `veciahorra-a11-barrier-arrival-action-proposal/v1`. El schema anterior, un arrival sin propuesta y la aceptación dual son inválidos. No existen otros `barrier_arrival` en A11: las otras 57 invocations y cualquier loopback tienen `barrier=null`. Ningún proceso puede negociar versión.

## 5. Matriz cerrada de cinco propuestas

| Caso | Invocation | Window | Participant / index / role | Port | Phase | Propuestas | Índice | Count FD |
|---|---|---|---|---|---|---:|---:|---:|
| `A11-CR-01` | `a11_000000000011_fd` | `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | `a11p_A11-CR-01_first_delivery_01` / 1 / `external_scheduler` | `scheduler.action_schedule` | `first_delivery` | 1 | 1 | 1 |
| `A11-CR-02` | `a11_000000000013_fd` | `CRASH_AFTER_LOCAL_CLAIM` | `a11p_A11-CR-02_first_delivery_01` / 1 / `stage_processor` | `durable.worker_execute` | `first_delivery` | 1 | 1 | 1 |
| `A11-CR-03` | `a11_000000000015_fd` | `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | `a11p_A11-CR-03_first_delivery_01` / 1 / `reconciliation_attempt` | `durable.worker_execute` | `first_delivery` | 1 | 1 | 1 |
| `A11-CR-04` | `a11_000000000017_fd` | `CRASH_AFTER_RESULT_PERSISTED` | `a11p_A11-CR-04_first_delivery_01` / 1 / `schedule_repository` | `durable.worker_execute` | `first_delivery` | 1 | 1 | 1 |
| `A11-CR-05` | `a11_000000000019_fd` | `CRASH_BEFORE_CALLBACK_RETURN` | `a11p_A11-CR-05_first_delivery_01` / 1 / `executor` | `durable.worker_execute` | `first_delivery` | 1 | 1 | 1 |

`local_ordinal=1` es el proposal index. Una lista no se usa: la clave contiene un único objeto. Una segunda propuesta, otro port, ordinal distinto o participante distinto se rechaza.

## 6. Observación exacta por ventana

| Window | Operación ya comenzada | Evidencia que autoriza la propuesta | Punto de serialización | Acción posterior prohibida |
|---|---|---|---|---|
| `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | `inner->schedule()` creó action ID positivo | retorno productivo con ID, hook y group exactos | tras validar `scheduler.action_schedule`, antes de devolver el ID | asociación local y otro schedule |
| `CRASH_AFTER_LOCAL_CLAIM` | `DurableRetryExecutorInterface::execute()` entró y el claim quedó persistido | schedule ID y generation positivos recibidos en `execute()` | tras validar `durable.worker_execute`, antes del processor interno | intento funcional |
| `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | `inner->process()` fue invocado | comienzo del executor ya registrado con schedule ID y generation | después del retorno funcional y antes de retornar al stage processor | segundo intento o retorno al caller |
| `CRASH_AFTER_RESULT_PERSISTED` | executor comenzó y transition `APPLIED` quedó persistida | comienzo registrado más transición terminal confirmada | después de persistir, antes de retornar al executor | nueva transición o nuevo execute |
| `CRASH_BEFORE_CALLBACK_RETURN` | executor terminó su única llamada productiva | comienzo registrado con schedule ID y generation | después del executor, antes del retorno callback | segunda ejecución o callback posterior |

En los cinco casos el decorator crea la propuesta inmediatamente al observar el comienzo, la añade por valor a su lista privada de cardinalidad uno y ejecuta el validator local. El crash decorator recupera esa misma instancia validada, construye el arrival, canonicaliza, comprueba tamaño, escribe, hace flush y se bloquea. La llegada nunca precede al comienzo productivo.

## 7. Semántica de evidencia

La propuesta transportada prueba una acción ya observada. No es `phase_result`, resultado productivo, integración, commit, snapshot, completion marker ni validación del conjunto. No modifica action state, capture state, counts, `base_action_hash` u operation result; no materializa delta ni sella snapshot.

El coordinator conserva bytes y DTO temporalmente. Solo después del kill válido, EOF, wait, recolección completa y validación del participant set puede entregar la propuesta al materializador. No existe integración parcial.

## 8. Schema reutilizado de propuesta

La clave `participant_action_proposal` usa literalmente `veciahorra-a11-participant-action-proposal/v1` de la autoridad de materialización §2. Su key set exacto es: `schema`, `kind`, `participant_id`, `local_ordinal`, `case_id`, `ownership_token`, `phase`, `port`, `action_kind`, `productive_identity`, `payload`, `provenance`.

Tipos y objetos anidados no cambian. `productive_identity` tiene exactamente `type,value`; `payload`, exactamente `shape,values`; `provenance`, exactamente `operation,role,observation`. `operation=execute_phase`, role es el de §5 y `observation=decorator_entry`. CR-01 usa kind `schedule`, type `scheduled_action`, value decimal del action ID, shape `scheduler_action_schedule/v1` y values `action_id,hook,group`. CR-02..05 usan kind `execute`, type `durable_schedule`, value decimal del schedule ID, shape `durable_worker_execute/v1` y values `schedule_id,generation`.

La comparación posterior es estructural estricta después de parsear y recanonicalizar; los bytes recanonicalizados deben ser idénticos a los bytes recibidos. Se prohíben `action_delta`, `base_action_hash`, PID, challenge, window, invocation ID, hashes posteriores, timestamps, flags de commit y campos nuevos dentro de la propuesta.

## 9. Binding único

Envelope y propuesta repiten y deben igualar byte a byte `ownership_token`, `case_id`, `phase` y `participant_id`. `participant_index=1` se proyecta desde el sufijo `_01` validado contra el descriptor, nunca se deriva para crear el ID. Window, PID y challenge existen solo en el envelope y se validan contra control-plane, process registry y request congelado.

El binding completo es `(ownership_token,case_id,phase,participant_id,participant_index,window_id,pid,binding_challenge)`. Una desigualdad invalida arrival y propuesta. El participant no conoce ni transporta `invocation_id`; el coordinator lo obtiene del descriptor congelado y valida la fila §5.

## 10. Límite de frame y prueba aritmética

El nuevo máximo literal es **32768 bytes incluyendo el LF**. Se cuenta `strlen($canonicalJson . "\n")`, no caracteres. El writer rechaza antes de `fwrite` si el valor supera 32768; el reader rechaza al recibir el byte 32769 o EOF antes de LF.

Límites de entrada para estos cinco arrivals: ownership/run ID 40..64 bytes ASCII; case 9 bytes; participant ID 37..38 bytes; window 23..35 bytes; challenge 32 bytes; phase 14 bytes; PID y cada ID positivo, `1..PHP_INT_MAX`; hook y group, 1..1024 bytes UTF-8 sin NUL; operation, role, observation, schemas, kinds, ports, shapes y identity types son los literales de §§3, 5 y 8. El coordinator rechaza ownership mayor a 64 antes del spawn con `crash_arrival_frame_too_large`; no hay truncamiento.

Prueba de peor caso: cada byte de hook o group puede requerir como máximo seis bytes JSON (`\u00XX`), luego `2 × 1024 × 6 = 12288`. La suma de nombres de clave, delimitadores, comillas y literales fijos de envelope y propuesta es menor que 2048 bytes al enumerar el key set cerrado. Los demás valores variables acotados suman como máximo `64+9+38+35+32+14+(5×19)=287` bytes; la repetición de ownership, case, phase y participant dentro de la propuesta añade como máximo `64+9+14+38=125`. LF añade 1. Por tanto `12288+2048+287+125+1=14749 < 32768`. El margen no autoriza campos o longitudes mayores.

Frame parcial, JSON inválido, profundidad mayor a 5, segundo LF, prefix, suffix o bytes tras el LF producen los reasons de §§16–17.

## 11. Escritura única

Orden obligatorio:

1. observar la acción;
2. construir la propuesta;
3. validarla localmente;
4. construir el arrival;
5. canonicalizarlo;
6. medirlo;
7. ejecutar una llamada `fwrite(STDOUT, $json . "\n")` y exigir longitud total;
8. confirmar el LF incluido;
9. ejecutar `fflush(STDOUT)` y exigir `true`;
10. entrar al loop de bloqueo publicado;
11. permanecer hasta kill externo;
12. no escribir otro byte.

Una escritura corta invalida la evidencia y termina por cleanup supervisor.

## 12. Recepción del coordinator

El coordinator lee stdout no bloqueante hasta LF dentro del deadline vigente y buffer máximo 32768. Valida UTF-8, objeto único, canonicalidad, schema y key set; valida envelope; parsea la propuesta con el parser normativo; ejecuta validator local contra descriptor; valida binding y matriz §5; calcula proposal identity; detecta duplicado; inserta una copia en almacenamiento temporal sin mutar action state.

Después valida arrival, ejecuta kill, fallback y wait vigentes, drena stdout/stderr, exige EOF sin bytes, registra exit de crash esperado y marca `arrival_validated_with_action_evidence`. Solo entonces la propuesta queda elegible para el participant set. Un fallo posterior elimina la copia y aborta toda la invocation.

## 13. Estado temporal del coordinator

Owner: `DurableRetryA11Coordinator`, propiedad privada exacta:

```php
final class DurableRetryA11Coordinator
{
/** @var array<string, array<string, DurableRetryA11ParticipantActionProposal>> */
private array $crashArrivalActionProposals = [];
}
```

Primera clave: `invocation_id`; segunda: `participant_id`. El map se crea vacío al congelar la invocation. Se inserta una vez tras validación estructural y binding, antes del kill. Lookup requiere ambas claves exactas. No se expone por referencia y no se persiste.

Tras kill, EOF y wait válidos se entrega una copia al observed participant set. En fallo se elimina primero el participant y luego el map vacío; tras integración válida se elimina al terminar el commit; cleanup idempotente elimina cualquier resto. Rollback conserva action state inicial y descarta el map.

## 14. Kill, exit y validez

Una propuesta validada no se invalida por el kill requerido. Sí se invalida toda la invocation si falla `proc_terminate`, fallback, wait, exit esperado, drain, EOF o cleanup. Salida autónoma antes del bloqueo, EOF anterior al kill, bytes adicionales, segundo frame o propuesta recibida después de iniciar kill son fallos.

La propuesta nunca se integra cuando el lifecycle de kill no termina satisfactoriamente. El orden válido es proposal validada → arrival validado → kill → exit → EOF → set completo.

## 15. Contrato para el participant set

Cada participante §5 tiene exactamente: procesos 1; requests 1; `phase_result` 0; `barrier_arrival` 1; propuestas 1; writes stdout 1; LF 1; flush 1; kill 1; EOF 1; wait 1; exit de crash esperado 1; cierres stdin/stdout/stderr 3.

El expected set conserva al participante aunque no tenga result. Su observed record es `(descriptor,arrival,proposal,killed=true,eof=true,waited=true,phase_result_absent=true)`. Un arrival válido con una propuesta satisface la fuente de evidencia de acción, pero no completa por sí solo la invocation. Complete collection exige igualdad del expected participant set completo, lifecycle terminal de cada proceso y cero resultados/arrivals inesperados.

## 16. Materialización posterior y counts

Tras completar y validar el set, la propuesta se combina con todas las propuestas normales, se valida y ordena con el mismo comparador total, se somete a multiplicidad, duplicate y conflict checks y participa en la bijección acción–propuesta–delta. Produce exactamente un delta solo si el bundle entero es válido. El materializador central asigna `base_action_hash`; el participante nunca lo conoce.

Permanecen literalmente: CR-01 first-delivery `scheduler.action_schedule=1`; CR-02, CR-03, CR-04 y CR-05 first-delivery `durable.worker_execute=1`. Las 372 cuentas no cambian, no representan intentos y no se descuentan por kill. Cualquier fallo posterior provoca cero integración y rollback total.

## 17. Inconsistencias y reasons cerrados

| Condición | Reason |
|---|---|
| propuesta ausente | `crash_arrival_proposal_missing` |
| cardinalidad distinta de uno | `crash_arrival_proposal_count_mismatch` |
| shape, payload u ordinal inválido | `crash_arrival_proposal_invalid` |
| binding envelope/propuesta/descriptor desigual | `crash_arrival_proposal_binding_mismatch` |
| port, kind, identity, role o window incompatibles | `crash_arrival_proposal_action_mismatch` |
| proposal identity o `(participant,ordinal)` repetido | `crash_arrival_proposal_duplicate` |
| frame mayor a 32768 o ownership pre-spawn mayor a 64 | `crash_arrival_frame_too_large` |
| segundo frame, segundo LF, suffix o bytes tras LF | `crash_arrival_extra_bytes` |
| propuesta/frame recibido después de iniciar kill | `crash_arrival_proposal_after_kill` |
| kill, exit, EOF, wait o cleanup inválido | `crash_arrival_evidence_lifecycle_invalid` |

Propuesta vacía usa `crash_arrival_proposal_missing`; dos propuestas usan count mismatch; proposal index distinto de uno usa invalid; delta incluido usa invalid. Frame repetido y propuesta en segundo frame usan extra bytes. Los 17 reasons anteriores siguen aplicando a challenge, PID, timeout, framing base, kill y cleanup cuando no colisionan con una fila anterior.

Cualquier reason nuevo aborta invocation, descarta evidencia temporal, ejecuta kill/cleanup, conserva snapshots y action state iniciales y produce cero materialización e integración.

## 18. API normativa del arrival

Archivo futuro: `tests/manual/support/durable-retry-a11-coordinator.php`. Namespace: `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11BarrierArrival
{
    public function __construct(
        public readonly string $ownershipToken,
        public readonly string $caseId,
        public readonly string $phase,
        public readonly string $participantId,
        public readonly int $participantIndex,
        public readonly string $windowId,
        public readonly int $pid,
        public readonly string $bindingChallenge,
        public readonly int $arrivalOrdinal,
        public readonly DurableRetryA11ParticipantActionProposal $participantActionProposal
    ) {}

    public function toArray(): array
    {
        throw new \LogicException('crash_arrival_api_contract_only');
    }

    public function participantActionProposal(): DurableRetryA11ParticipantActionProposal
    {
        return $this->participantActionProposal;
    }
}

final class DurableRetryA11BarrierArrivalCodec
{
    public const SCHEMA = 'veciahorra-a11-barrier-arrival-action-proposal/v1';
    public const MAX_FRAME_BYTES = 32768;

    public static function encode(DurableRetryA11BarrierArrival $arrival): string
    {
        throw new \LogicException('crash_arrival_api_contract_only');
    }

    public static function parse(string $frame): DurableRetryA11BarrierArrival
    {
        throw new \LogicException('crash_arrival_api_contract_only');
    }
}

final class DurableRetryA11BarrierArrivalValidator
{
    public static function validate(
        DurableRetryA11BarrierArrival $arrival,
        array $participantDescriptor,
        int $observedPid
    ): DurableRetryA11BarrierArrival {
        throw new \LogicException('crash_arrival_api_contract_only');
    }
}
```

Codec es puro: `encode()` retorna JSON canónico más LF y mide el string completo; `parse()` exige un frame completo, recanonicaliza y compara bytes. Ambos lanzan `DurableRetryA11BarrierControlException` con reason §17 o el reason previo específico.

## 19. API del coordinator y participant set

En el mismo archivo y namespace:

```php
final class DurableRetryA11Coordinator
{
    private array $crashArrivalActionProposals = [];

    private function storeCrashArrivalActionProposal(
        string $invocationId,
        DurableRetryA11BarrierArrival $arrival
    ): void {
        throw new \LogicException('crash_arrival_api_contract_only');
    }

    private function registerValidatedCrashArrival(
        string $invocationId,
        DurableRetryA11BarrierArrival $arrival,
        int $expectedExitCode
    ): void {
        throw new \LogicException('crash_arrival_api_contract_only');
    }

    private function crashArrivalActionProposalsForParticipantSet(
        string $invocationId
    ): array {
        throw new \LogicException('crash_arrival_api_contract_only');
    }

    private function cleanupCrashArrivalActionProposals(string $invocationId): void
    {
        unset($this->crashArrivalActionProposals[$invocationId]);
    }
}
```

Las funciones son instance/private, no static, no retornan referencias. `store` inserta atómicamente una vez; `register` solo se llama tras kill, wait y EOF; `ForParticipantSet` retorna copia readonly únicamente cuando collection está completa; cleanup admite repetición segura.

## 20. API del crash barrier y decorators

Archivo futuro: `tests/manual/support/durable-retry-a11-child-worker.php`. Namespace idéntico.

```php
final class DurableRetryA11CrashBarrier
{
    public function arriveAndAwaitKill(
        array $barrierControl,
        DurableRetryA11ParticipantActionProposal $validatedProposal
    ): never {
        throw new \LogicException('crash_arrival_api_contract_only');
    }
}

interface DurableRetryA11CrashActionProposalSink
{
    public function arriveAndAwaitKill(
        DurableRetryA11ParticipantActionProposal $validatedProposal
    ): never;
}
```

Cada uno de los cinco decorators recibe por constructor un `DurableRetryA11CrashActionProposalSink`. Después de validar su única propuesta llama una vez `arriveAndAwaitKill($proposal)`. Sin crash plan conserva la propuesta para el `phase_result` normal y no llama al sink. Con crash plan, cero o más de una propuesta falla antes de stdout. Ningún decorator escribe stdout directamente.

## 21. Atomicidad, rollback y cleanup

Antes de observar acción no existe propuesta. Observar y crear solo muta memoria privada. Escribir y validar arrival solo muta buffers supervisor y el map temporal. Kill, EOF y wait solo mutan lifecycle supervisor. Completar set, ordenar y materializar crean candidatos por valor. Solo la integración transaccional válida sustituye action state y sella el snapshot.

Cualquier error anterior al commit deja el action state inicial estrictamente idéntico. Parsing fallido no inserta; kill fallido elimina; fallo de set o materialización descarta candidatos; transacción fallida conserva estado anterior.

Cleanup exacto: cancelar/terminar process tree; fallback Windows vigente; drenar stdout y stderr; exigir o registrar fallo de EOF; cerrar tres pipes; `proc_close`; quitar PID; eliminar propuesta temporal; eliminar estado barrier; verificar cero handles, processes, listeners, sockets, archivos y `.a11-runtime`. No se persisten propuestas.

## 22. Allowlist posterior exacta

Únicos paths modificables en la implementación posterior:

1. `docs/durable-retry-production-activation-a11-action-capture-multiprocess-topology-normative-correction.md` — consumir el shape y las cinco cardinalidades;
2. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` — DTO/validator de propuesta ya autorizado;
3. `tests/manual/support/durable-retry-a11-coordinator.php` — DTO, codec, validator, almacenamiento y control-plane;
4. `tests/manual/support/durable-retry-a11-child-worker.php` — crash barrier, sink y wiring de cinco decorators;
5. `tests/manual/durable-retry-a11-action-capture-test.php`;
6. `tests/manual/durable-retry-a11-action-capture-infrastructure-test.php`;
7. `tests/manual/durable-retry-a11-ea6-matrix-test.php`;
8. `tests/manual/durable-retry-a11-child-protocol-test.php`;
9. `tests/manual/durable-retry-a11-action-invocation-plan-test.php`;
10. `tests/manual/durable-retry-a11-orphan-closure-test.php`.

No se autoriza producto, fixture, stub HTTP, octavo harness, archivo de soporte adicional, documento adicional ni infraestructura histórica.

## 23. Matriz obligatoria de pruebas

| ID | Harness | Caso | Aserciones mínimas y PASS |
|---|---|---|---|
| T01–T05 | `durable-retry-a11-ea6-matrix-test.php` | CR-01..05 válido | un frame, port §5, proposal 1, arrival 1, kill/EOF válidos |
| T06 | mismo | ports cruzados | cinco ports exactos; cada cruce rechaza action mismatch |
| T07 | `durable-retry-a11-child-protocol-test.php` | escritura | una llamada lógica, LF único, flush, cero bytes posteriores |
| T08 | mismo | frame máximo válido | 32768 bytes aceptados con fixture canónico válido |
| T09 | mismo | 32769 bytes | frame too large, cero write/integración |
| T10 | mismo | propuesta ausente | proposal missing |
| T11 | mismo | dos propuestas | proposal count mismatch |
| T12 | mismo | ordinal 2 | proposal invalid |
| T13 | mismo | payload inválido | proposal invalid |
| T14 | mismo | ownership/participant desigual | binding mismatch |
| T15 | mismo | ventana incompatible | action mismatch |
| T16 | mismo | segunda línea/frame | extra bytes |
| T17 | mismo | suffix antes de kill | extra bytes |
| T18 | mismo | EOF antes de kill | lifecycle reason previo de EOF |
| T19 | `durable-retry-a11-action-capture-test.php` | kill exitoso | propuesta temporal preservada, exit/EOF terminales |
| T20 | mismo | kill/fallback fallido | lifecycle invalid, map vacío, cero integración |
| T21 | mismo | estado temporal | lookup exacto por invocation+participant, copia sin referencia |
| T22 | mismo | atomicidad previa | action state `===` antes de commit |
| T23 | `durable-retry-a11-action-invocation-plan-test.php` | incorporación al set | solo tras collection completa, cinco descriptors exactos |
| T24 | `durable-retry-a11-ea6-matrix-test.php` | materialización | cada crash proposal produce exactamente un delta |
| T25 | mismo | fallo posterior | rollback total, cero integración parcial |
| T26 | mismo | expected actions | cinco counts FD y 372 celdas preservadas |
| T27 | `durable-retry-a11-orphan-closure-test.php` | cleanup | map, PID, pipes, listeners, archivos y runtime en cero |
| T28 | `durable-retry-a11-action-capture-infrastructure-test.php` | API | clases, namespace, firmas, visibilidad, constantes y properties exactas |
| T29 | `durable-retry-a11-child-protocol-test.php` | Windows/PHP | `proc_terminate`, fallback `taskkill /F /T /PID`, wait y EOF |
| T30 | mismo | propuesta después de kill | after kill, evidencia descartada |

PASS global exige T01–T30, cinco ventanas, cinco proposals, un frame por ventana, cero `phase_result`, cinco kills, cinco EOF posteriores, cinco deltas tras materialización válida, cero mutación previa y cero residuos.

## 24. Cierre y precedencia final

Este documento sustituye del control-plane anterior solo el schema/key set sin propuesta, el límite 1024, profundidad 2 y las firmas afectadas para los cinco arrivals. Conserva canal, framing único, un LF, flush, ausencia de `phase_result`, bloqueo, kill externo, deadlines, fallback, wait, EOF y cleanup.

Consume sin modificar `participant_action_proposal/v1`, comparador, bijección y materialización central. Conserva los cinco counts first-delivery y las 372 cuentas. La corrección de topología debe consumir este arrival ampliado y no puede volver a decidir transporte, cardinalidad, schema, límite, binding o lifecycle de estas propuestas.

No existe segundo frame, shape alternativo, delta participante, integración parcial ni decisión de transporte pendiente.

**A11 EA6 CRASH ARRIVAL ACTION PROPOSAL IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
