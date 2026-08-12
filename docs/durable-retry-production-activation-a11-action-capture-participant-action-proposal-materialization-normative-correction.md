# Corrección normativa A11 EA6 de propuestas de acciones por participante

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Objeto, precedencia y sustitución limitada

Esta corrección gobierna exclusivamente la representación de acciones observadas por procesos participantes, su validación conjunta y su primera materialización como cadena canónica de `action_delta`.

Prevalece, solo en ese alcance, sobre las reglas de `durable-retry-production-activation-a11-action-capture-transport-normative-correction.md` que exigían que cada proceso EA6 emitiera directamente `action_delta`, y sobre la prohibición de reconstrucción de `durable-retry-production-activation-a11-action-capture-coordinator-api-normative-correction.md` únicamente cuando la entrada todavía consiste en propuestas y ningún delta canónico existe.

Permanecen vigentes el schema y algoritmo de `action_delta`, la cadena de `base_action_hash`, los seis puertos, las dos fases, los 372 counts, los límites, la atomicidad, el coordinator como único owner stateful y la única integración transaccional por invocation.

En toda invocation EA6, unimembre o multiproceso, los participantes emiten exclusivamente `participant_action_proposal`. No existe ruta directa participante→`action_delta`.

Definiciones cerradas:

- acción productiva observada: comienzo real de una operación perteneciente a uno de los seis puertos normativos;
- propuesta: evidencia inmutable de ese comienzo, todavía sin hash de cadena;
- delta canónico: envelope EA5 vigente materializado uno a uno desde una propuesta aceptada;
- cadena integrada: lista final de deltas con bases sucesivas, entregada una sola vez a la transacción.

Una propuesta no es un `action_delta`, no posee `base_action_hash`, no es integrable y no representa commit.

## 2. Schema y key set de la propuesta

Schema exacto: `veciahorra-a11-participant-action-proposal/v1`.

Key set exacto, sin claves adicionales:

```json
{"schema":"veciahorra-a11-participant-action-proposal/v1","kind":"participant_action_proposal","participant_id":"a11p_A11-CON-05_first_delivery_01","local_ordinal":1,"case_id":"A11-CON-05","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","port":"scheduler.action_cancel","action_kind":"cancel","productive_identity":{"type":"scheduled_action","value":"71"},"payload":{"shape":"scheduler_action_cancel/v1","values":{"action_id":71}},"provenance":{"operation":"execute_phase","role":"recovery","observation":"decorator_entry"}}
```

Claves y tipos:

| Clave | Tipo y regla |
|---|---|
| `schema` | string literal del schema |
| `kind` | string literal `participant_action_proposal` |
| `participant_id` | string con gramática de §3 |
| `local_ordinal` | integer 1..4096, consecutivo por participante |
| `case_id` | string con gramática A11 vigente |
| `ownership_token` | execution ID EA5 vigente |
| `phase` | `first_delivery` o `replay` |
| `port` | uno de los seis puertos vigentes |
| `action_kind` | literal fijado por la tabla de §4 |
| `productive_identity` | objeto exacto `{type:string,value:string}` |
| `payload` | objeto exacto `{shape:string,values:object}` |
| `provenance` | objeto exacto `{operation:string,role:string,observation:string}` |

Quedan prohibidos `base_action_hash`, hashes posteriores, timestamps, PID, estado combinado, snapshots del coordinator, flags de commit y referencias mutables.

Todos los strings son UTF-8, no vacíos, sin NUL. Los objects se canonicalizan mediante el JSON canónico EA5. Los integers JSON no admiten coerción.

## 3. Identidad de participante y ordinal local

La gramática exacta es:

```text
a11p_<case_id>_<phase>_<participant_index_2d>
```

`participant_index_2d` pertenece a `01..32`. `case_id` conserva mayúsculas y guiones; `phase` es `first_delivery|replay`. La corrección futura de topología publicará el catálogo exhaustivo y asignará un índice único por `(case_id, phase)`.

El request de participante transporta literalmente el `participant_id`; el participante lo repite, nunca lo deriva. El coordinator valida el catálogo antes del spawn. La propuesta no contiene `invocation_id`.

`local_ordinal` comienza en 1 y aumenta exactamente uno por comienzo observado en ese participante. No se reutiliza tras error. Cero propuestas se representa mediante lista vacía.

## 4. Catálogo de puertos, kinds, identidad y payload

| Puerto | `action_kind` | `productive_identity.type` | `payload.shape` | `payload.values` exacto |
|---|---|---|---|---|
| `webpay.commit` | `commit` | `webpay_request` | `webpay_commit/v1` | `{request_fingerprint:string}` |
| `woocommerce.payment_complete` | `complete` | `order` | `woocommerce_payment_complete/v1` | `{order_id:positive-int}` |
| `scheduler.action_schedule` | `schedule` | `scheduled_action` | `scheduler_action_schedule/v1` | `{action_id:positive-int,hook:non-empty-string,group:non-empty-string}` |
| `scheduler.action_cancel` | `cancel` | `scheduled_action` | `scheduler_action_cancel/v1` | `{action_id:positive-int}` |
| `legacy.retry_schedule` | `schedule` | `legacy_authority` | `legacy_retry_schedule/v1` | `{authority_id:positive-int}` |
| `durable.worker_execute` | `execute` | `durable_schedule` | `durable_worker_execute/v1` | `{schedule_id:positive-int,generation:positive-int}` |

`productive_identity.value` es la representación decimal canónica del ID positivo, excepto `webpay_request`, que usa el fingerprint lowercase de 64 hex. Para schedule, cancel y execute la identidad se fija después de que la API productiva haya proporcionado el ID y antes de retornar del decorator.

## 5. Producción por participante

El decorator neutral propietario del puerto crea la propuesta inmediatamente después de confirmar que el comienzo productivo ocurrió. La propuesta se añade por valor a una lista local privada del proceso.

Un participante produce 0..4096 propuestas. La lista conserva ordinal local ascendente. La suma de todos los participantes no supera 4096.

Si la acción comenzó y el proceso falla antes de emitir su resultado, la invocation completa falla con `participant_result_missing_after_action`; no se materializa ni integra evidencia parcial. La estrategia externa de cleanup y posterior inspección productiva gobierna la recuperación; esta corrección no convierte un crash en éxito.

`operation_result` y `capture_delta` permanecen subobjetos separados. Una propuesta no altera ninguno.

## 6. Identidades pre-materialización

La serialización de identidad usa JSON canónico del objeto siguiente:

```json
{"case_id":"A11-CON-05","ownership_token":"a11_20260803010101_1_0123456789abcdef","phase":"first_delivery","port":"scheduler.action_cancel","action_kind":"cancel","productive_identity":{"type":"scheduled_action","value":"71"},"payload":{"shape":"scheduler_action_cancel/v1","values":{"action_id":71}},"provenance":{"operation":"execute_phase","role":"recovery","observation":"decorator_entry"}}
```

`proposal_identity` es SHA-256 lowercase de esos bytes. No incluye participante ni ordinal. Dos propuestas con el mismo `proposal_identity` describen el mismo comienzo semántico y son duplicadas, salvo multiplicidad autorizada por §11.

`conflict_identity` es SHA-256 lowercase del JSON canónico de `{case_id,ownership_token,phase,productive_identity}`. Mismo conflict identity con port, kind o payload distintos es `participant_action_proposal_conflict`.

Los objects se ordenan por bytes de clave, listas conservan orden, integers usan decimal JSON, `null` está prohibido en identidad y payload.

## 7. Orden canónico global

La tupla total es:

```text
(phase_rank, operation_rank, participant_index, port_rank,
 productive_identity.type, productive_identity.value,
 local_ordinal, proposal_identity)
```

Ranks:

- phase: `first_delivery=0`, `replay=1`;
- operation: `setup=0`, `execute_phase=1`, `assertions=2`, `cleanup=3`, los cinco `observe_*` en el orden publicado por Action Transport con ranks 4..8;
- port: `webpay.commit=0`, `woocommerce.payment_complete=1`, `scheduler.action_schedule=2`, `scheduler.action_cancel=3`, `legacy.retry_schedule=4`, `durable.worker_execute=5`.

Ranks e índices se comparan numéricamente; strings por bytes UTF-8 unsigned. `local_ordinal` y `proposal_identity` eliminan todo empate residual. Una clave total repetida es `participant_action_order_collision`.

No participan tiempo de llegada, PID, orden de pipes, timestamps ni scheduling del sistema operativo. Cancel precede execute únicamente cuando sus operation ranks así lo establecen en el descriptor de topología; dentro de una misma operación rige port rank.

## 8. Validación local

`DurableRetryA11ParticipantActionProposalValidator::validate()` es puro. Valida key set, schema, tipos, gramáticas, descriptor, binding, ordinal, catálogo de §4, consistencia de identidad/payload y provenance.

Retorna la misma instancia validada. Nunca calcula hashes de cadena ni consulta counts globales.

Reasons cerrados: `participant_action_proposal_invalid`, `participant_action_participant_mismatch`, `participant_action_ordinal_invalid`, `participant_action_port_invalid`, `participant_action_payload_invalid`, `participant_action_provenance_invalid`.

## 9. Validación global

El set validator recibe descriptores esperados, propuestas, operation results, capture deltas, expected actions y action snapshot base. Valida en este orden:

1. participantes exactos;
2. listas y límite 4096;
3. validación local;
4. ordinales consecutivos por participante;
5. bindings comunes;
6. conflict identities;
7. duplicates y multiplicidad;
8. counts exactos por fase y puerto;
9. coherencia con operation results;
10. coherencia con captures;
11. ausencia de identidad ya incluida como acción aceptada en evidencia base;
12. orden total calculable.

Retorna la lista ordenada como valor nuevo. No muta propuestas ni estado.

Reasons adicionales: `participant_action_set_invalid`, `participant_action_participant_unknown`, `participant_action_participant_missing`, `participant_action_duplicate`, `participant_action_proposal_conflict`, `participant_action_count_mismatch`, `participant_action_unexpected`, `participant_action_missing`, `participant_action_base_snapshot_mismatch`, `participant_action_order_collision`, `participant_action_cardinality_overflow`.

## 10. Snapshot base único

El coordinator congela antes del spawn el `action_snapshot` de la invocation y su `snapshot_hash` vigente. Ese hash es el único `base_action_hash` de materialización.

El snapshot es inmutable durante recolección. Participantes no lo actualizan y ninguna propuesta transporta su hash. Ausencia produce `participant_action_base_snapshot_missing`; cambio produce `participant_action_base_snapshot_mismatch`.

Un hash emitido por un participante jamás reemplaza la base.

## 11. Multiplicidad y duplicados

Una propuesta aceptada produce exactamente un delta. No se agrupan ni eliminan propuestas.

La multiplicidad autorizada para `(case_id,phase,port,proposal_identity)` es el count exacto publicado en la autoridad de 372 counts. Cuando el count es mayor que uno, cada comienzo real debe tener ordinal local o participante distinto y se conservan hasta ese máximo. Exceso es duplicate; defecto es missing.

| Condición | Decisión | Reason |
|---|---|---|
| mismo participante, mismo ordinal | rechazar | `participant_action_duplicate` |
| participantes distintos, misma identidad, multiplicidad 1 | rechazar | `participant_action_duplicate` |
| misma identidad con multiplicidad normativa mayor que 1 | conservar hasta el count | `none` |
| mismo conflict identity y payload distinto | rechazar | `participant_action_proposal_conflict` |
| identidad presente en evidencia base | rechazar | `participant_action_replayed` |
| cancel y execute esperados sobre un schedule | conservar ambos | `none` |
| cancel o execute no esperado | rechazar | `participant_action_unexpected` |
| propuesta repetida en replay sin count replay | rechazar | `participant_action_replayed` |
| propuesta tardía | rechazar invocation | `participant_action_late` |
| propuesta posterior al fallo | rechazar | `participant_action_after_failure` |

## 12. Materialización canónica

Firma normativa:

```text
public static function materialize(
    array $participantDescriptors,
    array $proposals,
    array $operationResults,
    array $captureDeltas,
    array $expectedActions,
    array $baseActionSnapshot
): DurableRetryA11ActionProposalMaterializationResult;
```

Algoritmo exacto:

1. validar el snapshot y obtener su hash vigente;
2. validar globalmente y ordenar propuestas;
3. fijar `current_hash` al hash base;
4. iniciar counts como copia del mapa base;
5. por cada propuesta ordenada construir un `action_delta` con schema vigente, kind, case, ownership, phase, port, `delta=1` y `base_action_hash=current_hash`;
6. aplicar conceptualmente el delta al mapa de counts mediante el algoritmo EA5 vigente;
7. calcular `current_hash=DurableRetryA11ActionCapture::hashMap($counts)`;
8. añadir el delta sin modificarlo posteriormente;
9. retornar propuestas ordenadas, deltas, hash final y counts.

Fallo de canonicalización produce `participant_action_canonicalization_failed`; fallo de hash produce `participant_action_hashing_failed`; cadena no validable produce `participant_action_chain_invalid`.

El método es puro, sin I/O, WordPress, procesos, estado estático mutable ni integración.

## 13. Preservación de los 372 counts

Para cada celda `(case_id,phase,port)` de la tabla normativa, el set validator exige:

```text
count(propuestas aceptadas de la celda) = expected_actions[case][phase][port]
```

La relación propuesta→delta es uno a uno; cada delta tiene `delta=1`. Por inducción, materialización conserva cada una de las 372 celdas, no crea actions, no elimina actions y no altera multiplicidad al ordenar.

A11-CON-05 first delivery exige exactamente una propuesta `scheduler.action_cancel` y una `durable.worker_execute`; ambas se conservan y se materializan según la tupla total. Rechazos producen cero integración.

## 14. Representación en resultado de participante

En todo resultado EA6 de participante, la clave anterior `action_deltas` queda sustituida por:

```json
"participant_action_proposals":[]
```

Es una lista de 0..4096 propuestas, ordenada por `local_ordinal`, con un único `participant_id`. `action_deltas` queda prohibida en resultados de participante. El shape superior completo será publicado por la corrección de topología; esta clave y su semántica no admiten reinterpretación.

El resultado no es integrable, no contiene cadena y exige recolección global.

## 15. Resultado agregado

Schema exacto: `veciahorra-a11-action-proposal-materialization/v1`.

Propiedades exactas del DTO:

- `invocationId:string` conocido solo por coordinator;
- `baseActionHash:string` lowercase 64 hex;
- `orderedProposalIdentities:list<string>`;
- `actionDeltas:list<array-shape-action-delta>`;
- `finalActionHash:string` lowercase 64 hex;
- `counts:array-shape-action-counts`;
- `validated:bool`, siempre `true` en una instancia construida.

Las propuestas completas son evidencia efímera diagnóstica del coordinator y no forman parte del bundle integrado. La transacción recibe exclusivamente `actionDeltas` canónicos.

No existe DTO de fallo; todo fallo lanza `DurableRetryA11ParticipantActionProposalException` antes de crear resultado.

## 16. Ownership y reconstrucción

El participante observa y propone; no ordena globalmente, no calcula cadena, no integra y no conoce estado agregado.

El coordinator conserva base y descriptores, recolecta, invoca validadores/materializador, valida el bundle y llama una sola vez al integrador.

El materializador es puro: no crea procesos, no conoce transporte, no carga WordPress, no conserva estado, no integra y produce valores nuevos.

Sigue prohibido modificar, rebasar, reordenar o regenerar un `action_delta` recibido como canónico. Los participantes no emiten deltas. Crear por primera vez deltas desde propuestas no es reconstrucción. Tras materializar, la lista es inmutable y no puede materializarse otra vez con otra base u orden.

## 17. Fallos y atomicidad

Todos los reasons de §§8–12 pertenecen al catálogo cerrado y se representan mediante `DurableRetryA11ParticipantActionProposalException`.

Además:

| Fallo | Detector | Reason | Materializa | Integra | Procesos restantes |
|---|---|---|:---:|:---:|---|
| propuesta inválida | local/global validator | `participant_action_proposal_invalid` | no | no | supervisor cancela |
| participante desconocido | set validator | `participant_action_participant_unknown` | no | no | supervisor cancela |
| acción ausente | set validator | `participant_action_missing` | no | no | ya drenados, cleanup |
| count incorrecto | set validator | `participant_action_count_mismatch` | no | no | ya drenados, cleanup |
| base mismatch | materializer | `participant_action_base_snapshot_mismatch` | no | no | supervisor cancela |
| canonicalización | materializer | `participant_action_canonicalization_failed` | no | no | cleanup |
| hashing | materializer | `participant_action_hashing_failed` | no | no | cleanup |
| cadena inválida | materializer | `participant_action_chain_invalid` | no | no | cleanup |
| overflow | set validator | `participant_action_cardinality_overflow` | no | no | supervisor cancela |
| hash final mismatch posterior | coordinator | `participant_action_final_hash_mismatch` | resultado descartado | no | cleanup |
| delta directo de participante | transport validator | `participant_action_direct_delta_forbidden` | no | no | supervisor cancela |

En todos los fallos el estado combinado previo permanece `===`, no existe compensación ni integración parcial. Diagnóstico solo en memoria del coordinator y stderr conforme al supervisor.

## 18. API PHP normativa literal

Ubicación futura de todas las clases: `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`.

Namespace: `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11ParticipantActionProposal
{
    public function __construct(
        public readonly string $participantId,
        public readonly int $localOrdinal,
        public readonly string $caseId,
        public readonly string $ownershipToken,
        public readonly string $phase,
        public readonly string $port,
        public readonly string $actionKind,
        public readonly array $productiveIdentity,
        public readonly array $payload,
        public readonly array $provenance
    ) {}

    public function toArray(): array
    {
        throw new \LogicException('participant_action_api_contract_only');
    }

    public function proposalIdentity(): string
    {
        throw new \LogicException('participant_action_api_contract_only');
    }

    public function conflictIdentity(): string
    {
        throw new \LogicException('participant_action_api_contract_only');
    }
}

final class DurableRetryA11ParticipantActionProposalException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

final class DurableRetryA11ParticipantActionProposalValidator
{
    public static function validate(
        DurableRetryA11ParticipantActionProposal $proposal,
        array $participantDescriptor
    ): DurableRetryA11ParticipantActionProposal {
        throw new \LogicException('participant_action_api_contract_only');
    }
}

final class DurableRetryA11ParticipantActionProposalSetValidator
{
    public static function validateAndOrder(
        array $participantDescriptors,
        array $proposals,
        array $operationResults,
        array $captureDeltas,
        array $expectedActions,
        array $baseActionSnapshot
    ): array {
        throw new \LogicException('participant_action_api_contract_only');
    }
}

final class DurableRetryA11ActionProposalMaterializationResult
{
    public function __construct(
        public readonly string $invocationId,
        public readonly string $baseActionHash,
        public readonly array $orderedProposalIdentities,
        public readonly array $actionDeltas,
        public readonly string $finalActionHash,
        public readonly array $counts,
        public readonly bool $validated
    ) {}
}

final class DurableRetryA11ActionProposalMaterializer
{
    public static function materialize(
        string $invocationId,
        array $participantDescriptors,
        array $proposals,
        array $operationResults,
        array $captureDeltas,
        array $expectedActions,
        array $baseActionSnapshot
    ): DurableRetryA11ActionProposalMaterializationResult {
        throw new \LogicException('participant_action_api_contract_only');
    }
}
```

Los cuerpos `participant_action_api_contract_only` son marcadores fail-closed del bloque documental y no pertenecen a la implementación futura. La implementación sustituye únicamente esos cuerpos por los algoritmos exhaustivos de §§6–12 y conserva byte por byte declaraciones, firmas, tipos y visibilidades.

Los `array` de esta API corresponden exclusivamente a los shapes cerrados en §§2, 4, 9, 10 y 15. No admiten objetos alternativos.

## 19. Allowlist futura exacta

Modificables:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` — DTOs, validators y materializer;
2. `tests/manual/support/durable-retry-a11-coordinator.php` — recolección e invocación pura;
3. `tests/manual/support/durable-retry-a11-child-worker.php` — producción/transporte de propuestas;
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php` — propuesta `webpay.commit` cuando la topología lo disponga;
5. los siete harnesses EA6 ya autorizados — certificación.

No se autoriza archivo adicional para DTO, validator, materializer, topology, dispatcher o runner. Topology y dispatcher deberán residir en los cuatro soportes ya permitidos conforme a sus futuras correcciones. Producto, fixtures, EA5 y documentación quedan fuera de la implementación.

## 20. Matriz de compatibilidad

| Autoridad | Preservado | Sustituido | Nueva regla | Cierre |
|---|---|---|---|---|
| Action Capture transport | delta final y cadena | delta directo por proceso | propuesta por todo participante | una sola cadena |
| Action delta EA5 | schema, `delta=1`, hash | nada en integración | materialización previa | byte-compatible |
| Coordinator API | owner y commit | no reconstrucción de entradas no canónicas | primera creación pura | deltas finales intactos |
| Bundle transaction | firma y atomicidad | nada | recibe lista materializada | una integración |
| Runtime Capture | snapshot/hash base | nada | base congelada única | sin canal nuevo |
| Expected actions | 372 counts | nada | bijección propuesta-delta | counts idénticos |
| Invocation plan | 62 invocations | nada | invocation solo en resultado agregado | child no recibe plan |
| Loopback | coordinator integra | emisión directa de delta por stub multiproceso | propuesta Webpay | una cadena por fase |
| Casos multiproceso | concurrencia real | cadenas independientes | propuestas sin base | agregables |
| A11-CON-05 | cancel+execute | bases paralelas | dos propuestas ordenadas | count 2 |
| Shared memory | prohibida | nada | recolección por results | sin canal lateral |
| Única integración | preservada | nada | materializar y luego integrar | exactamente una |

No existe contradicción residual: participantes no necesitan hashes actualizados; el coordinator no modifica deltas recibidos; el materializador crea una sola vez la cadena que ya espera la transacción.

## 21. Criterios de implementación

- todo participante emite propuestas;
- ninguna propuesta contiene base hash;
- identidad, conflicto y orden usan algoritmos de §§6–7;
- validación local y global son puras;
- el coordinator aporta el único snapshot base;
- una propuesta aceptada produce un delta;
- los 372 counts permanecen exactos;
- ninguna lista materializada se reordena o rebasa;
- un fallo produce cero integración;
- no existen canales, persistencia o owners adicionales;
- la transacción recibe únicamente `action_delta[]` canónico;
- existe una sola integración por invocation.

## 22. Veredicto

`A11 EA6 PARTICIPANT ACTION PROPOSAL MATERIALIZATION IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`
