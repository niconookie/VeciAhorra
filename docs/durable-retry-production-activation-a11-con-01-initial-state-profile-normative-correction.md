# Corrección normativa del perfil inicial A11-CON-01

## 1. Alcance y precedencia

Esta autoridad append-only cierra exclusivamente el estado compartido inmediatamente anterior al release de los dos participantes de `A11-CON-01 / first_delivery / a11_000000000001_fd`. Consume sin modificar la corrección A5 de provenance cuyo SHA-256 es `cf325f63db5a7559fa9969d2266f08361585148a439621eb19edbda1f1ea0989`.

Para este caso y esta fase, las descripciones históricas `recon`, `reconciliation fresca` y `recon fresca` quedan sustituidas por `a11_con_01_initial_reconciliation_v1`, definido en §8. No se extiende esta sustitución a otros casos ni fases.

Esta autoridad no selecciona isolation, visibility posterior al release, actores terceros, fault model concurrente ni reachability de outcomes.

## 2. Schema funcional vigente

```text
FUNCTIONAL_TABLE={$wpdb->prefix}{Config::TABLE_PREFIX}payment_reconciliations
PRIMARY_KEY=PRIMARY(id)
COLUMNS_TOTAL=26
```

| # | Columna | Tipo SQL | Null | Valor implícito SQL | Significado productivo | Lectura/escritura principal |
|---:|---|---|---|---|---|---|
| 1 | `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | no | sin valor implícito | identidad canónica | A5 lookup; repository create |
| 2 | `public_id` | `VARCHAR(64)` | no | sin valor implícito | identidad pública | reconciliation repository |
| 3 | `webpay_return_id` | `BIGINT UNSIGNED` | no | sin valor implícito | retorno financiero | materializer/repository |
| 4 | `origin_context_id` | `BIGINT UNSIGNED` | no | sin valor implícito | origen durable | materializer/repository |
| 5 | `provider` | `VARCHAR(30)` | no | sin valor implícito | proveedor financiero | materializer/repository |
| 6 | `fingerprint_version` | `INT UNSIGNED` | no | sin valor implícito | versión del fingerprint | materializer/repository |
| 7 | `financial_fingerprint` | `VARCHAR(64)` | no | sin valor implícito | fingerprint financiero | materializer/repository |
| 8 | `site_scope` | `VARCHAR(64)` | no | sin valor implícito | scope del sitio | materializer/repository |
| 9 | `origin` | `VARCHAR(30)` | no | sin valor implícito | clase de origen | materializer/repository |
| 10 | `origin_resource_id` | `VARCHAR(64)` | no | sin valor implícito | recurso de origen | materializer/repository |
| 11 | `gateway_id` | `VARCHAR(64)` | no | sin valor implícito | gateway | materializer/repository |
| 12 | `payment_attempt_id` | `VARCHAR(64)` | no | sin valor implícito | intento de pago | materializer/repository |
| 13 | `origin_key` | `VARCHAR(64)` | no | sin valor implícito | identidad idempotente de origen | materializer/repository |
| 14 | `reconciliation_status` | `VARCHAR(30)` | no | sin valor implícito | lifecycle funcional | A5 y claim repository |
| 15 | `business_result_code` | `VARCHAR(50)` | sí | `NULL` | resultado de negocio | processor/repository |
| 16 | `attempt_count` | `INT UNSIGNED` | no | `0` | intentos funcionales iniciados | A5 y claim repository |
| 17 | `lease_owner` | `VARCHAR(64)` | sí | `NULL` implícito por columna nullable | owner del claim | A5 y claim repository |
| 18 | `lease_acquired_at` | `DATETIME` | sí | `NULL` implícito por columna nullable | adquisición del claim | A5 y claim repository |
| 19 | `lease_expires_at` | `DATETIME` | sí | `NULL` implícito por columna nullable | expiración del claim | A5 y claim repository |
| 20 | `lease_version` | `INT UNSIGNED` | no | `0` | versión del claim | A5 y claim repository |
| 21 | `last_error_code` | `VARCHAR(50)` | sí | `NULL` implícito por columna nullable | último error | processor/repository |
| 22 | `last_error_at` | `DATETIME` | sí | `NULL` implícito por columna nullable | fecha del último error | processor/repository |
| 23 | `created_at` | `DATETIME` | no | sin valor implícito | creación | materializer/repository |
| 24 | `last_attempt_at` | `DATETIME` | sí | `NULL` implícito por columna nullable | último intento | processor/repository |
| 25 | `reconciled_at` | `DATETIME` | sí | `NULL` implícito por columna nullable | cierre funcional | processor/repository |
| 26 | `updated_at` | `DATETIME` | no | sin valor implícito | última actualización | repositories |

Los índices son `PRIMARY(id)`; únicos `public_id`, `webpay_return_id`, `origin_key` y `(provider,fingerprint_version,financial_fingerprint)`; e índices no únicos `(site_scope,origin,origin_resource_id)` y `reconciliation_status`. No hay foreign keys declaradas por este schema.

## 3. Inputs funcionales que deciden A5

`DurableRetryInitialAuthorityProducer` entrega la reconciliation a la autoridad inicial. `DurableRetryInitialTransferRepository::functionalForUpdate()` usa `authority.subjectId` como `id` y selecciona exactamente seis columnas físicas. El valor `lease_active` es derivado por SQL y no una columna persistida.

```text
A5_FUNCTIONAL_INPUT_FIELDS=7
```

| Campo | Uso A5 |
|---|---|
| `id` | clave exacta de la locking read y `subject_id` durable |
| `reconciliation_status` | distingue claim activo y elegibilidad |
| `attempt_count` | elegibilidad exige `0` |
| `lease_owner` | claim activo exige string no vacío; elegibilidad exige `null` |
| `lease_acquired_at` | elegibilidad exige `null` |
| `lease_expires_at` | elegibilidad exige `null`; deriva `lease_active` |
| `lease_version` | elegibilidad exige `0` |

Ningún campo de completion, reason, public/business status, timestamps de creación/error o retry marker adicional es leído por esa clasificación. Stage, generation, completion, scheduled time y reason pertenecen al request A4, no a la fila funcional leída.

## 4. Definición productiva seleccionada

El materializer productivo crea una reconciliation candidata con `STATUS_PENDING`, `attempt_count=0`, los tres campos del lease en `null` y `lease_version=0`. Ese estado satisface exactamente `functionallyEligible()` y no satisface `activeLegacyClaim()`.

Para CON-01, esa combinación, junto con cero schedules de la misma cadena durable, es la única definición normativa de estado inicial. Es case-specific y no formaliza una categoría global.

El catálogo productivo de status es `pending|processing|completed|retryable|permanent_failure|manual_review`. CON-01 selecciona `pending` porque es la única combinación con intento cero y lease nulo que la guardia A5 acepta para crear la autoridad durable inicial.

## 5. Binding de identidad runtime

```text
CON01_RECONCILIATION_ID_SOURCE=manifest JSON Pointer /fixture_ids/payment_reconciliations/0
```

El binding es el único entero positivo de `fixture_ids.payment_reconciliations`; su cardinalidad debe ser exactamente uno. No se introduce alias numérico. Este mismo valor debe ser `functional_record.id`, `authority.subject_id`, `request.completion_id` y el `subject_id` usado para comprobar schedules.

## 6. Estado durable inicial

La tabla durable es `{$wpdb->prefix}{Config::TABLE_PREFIX}durable_retry_schedules`. Antes del release deben cumplirse simultáneamente:

```text
stage=reconciliation
subject_id=/fixture_ids/payment_reconciliations/0
generation=1
matching_rows=0
all_rows_for_stage_and_subject=0
scheduled_action_id_for_generation_1=absent
action_scheduler_actions_owned_by_A11-CON-01=0
```

La ausencia de toda fila de la cadena, no solo de generation 1, impide que residuos de otra generación alteren la prueba de autoridad inicial. Ausencia de schedule y ausencia de reconciliation son propiedades distintas: la fila funcional existe.

## 7. Legacy authority inicial

El claim legacy leído por A5 reside en la propia fila funcional y exige conjuntamente status `processing`, lease no expirado y `lease_owner` no vacío. El perfil fija status `pending` y los tres campos del lease en `null`; por ello:

```text
CON01_INITIAL_LEGACY_CLAIM=absent
legacy_claim_evidence=reconciliation_status:pending,lease_owner:null,lease_acquired_at:null,lease_expires_at:null,lease_version:0
```

No existe otra claim row consultada por A5 para esta decisión.

## 8. Bloque canónico machine-checkable

```json
{
  "profile_id": "a11_con_01_initial_reconciliation_v1",
  "case_id": "A11-CON-01",
  "phase": "first_delivery",
  "invocation_id": "a11_000000000001_fd",
  "reconciliation_id": {"json_pointer": "/fixture_ids/payment_reconciliations/0", "type": "positive_int"},
  "functional_record": {
    "present": true,
    "id": {"equals_json_pointer": "/fixture_ids/payment_reconciliations/0"},
    "reconciliation_status": "pending",
    "attempt_count": 0,
    "lease_owner": null,
    "lease_acquired_at": null,
    "lease_expires_at": null,
    "lease_version": 0
  },
  "legacy_claim": {"present": false},
  "durable_chain": {
    "stage": "reconciliation",
    "subject_id": {"equals_json_pointer": "/fixture_ids/payment_reconciliations/0"},
    "all_generations_cardinality": 0,
    "generation_1_cardinality": 0,
    "scheduled_action_id": null
  },
  "owned_action_scheduler_actions_cardinality": 0,
  "pre_release_mutation": "forbidden"
}
```

El JSON es completo para inputs de branch A5. Los restantes campos no se omiten del registro físico: se materializan conforme al contrato productivo de fixture, pero no participan en la clasificación inicial A5 y no quedan redefinidos por esta autoridad.

## 9. Freeze point y prohibición previa al release

```text
CON01_INITIAL_STATE_FREEZE_POINT=después de materializar el fixture, persistir manifest+hash, congelar el invocation plan y completar exitosamente la validación de §10; inmediatamente antes de que el Coordinator emita el release de a11g_A11-CON-01_first_delivery_01
CON01_PRE_RELEASE_MUTATION=forbidden
```

Desde el inicio de la validación hasta el release colectivo, coordinator, bootstrap, harness y participants no pueden escribir la fila funcional, la cadena durable ni actions del caso. Una discrepancia invalida el bootstrap; no se repara dentro de esa ventana.

## 10. Validador futuro y residuos

Antes del release, el Coordinator/harness deberá, sobre la conexión de fixture y usando el manifest sellado:

1. exigir un solo ID positivo en `/fixture_ids/payment_reconciliations/0`;
2. leer por `PRIMARY(id)` exactamente una fila funcional;
3. comparar byte/tipo exactamente los seis valores A5 del §8;
4. derivar `lease_active=0` porque `lease_expires_at=null`;
5. acreditar claim legacy ausente por la conjunción del §7;
6. contar cero filas para `(stage='reconciliation',subject_id=<binding>)` y, por inclusión, cero para generation 1;
7. acreditar cero Action Scheduler actions bajo ownership exacto de este caso;
8. releer las mismas propiedades inmediatamente antes del release y exigir igualdad.

Toda discrepancia es `fixture/bootstrap failure`, produce cero releases y cero ejecución productiva.

El cleanup pre-bootstrap solo puede eliminar recursos registrados por el mismo execution ownership mediante IDs capturados y las APIs/orden de cleanup ya autorizados. Un schedule generation 1 u otra generación con ownership propio puede limpiarse y debe verificarse ausente antes de materializar el perfil. Un residuo ajeno no se elimina: causa `fixture/bootstrap failure`. Una fila funcional propia con claim inesperado se descarta mediante cleanup íntegro del recurso propio y se vuelve a materializar antes de la validación; una fila ajena o una discrepancia después del freeze falla sin mutación.

## 11. Sanity check de publisher aislado

Con la activation decision de CON-01 ya autorizada y un único publisher aislado, la fila funcional pasa `functionallyEligible()`, la consulta durable retorna cardinalidad cero y la transferencia inserta generation 1.

```text
SINGLE_PUBLISHER_INITIAL_A5_CLASSIFICATION=durable_created / initial_transfer_created
SINGLE_PUBLISHER_INITIAL_A5_BRANCH=functional eligible -> durable identity absent -> initial transfer insert -> transferred
```

Esto acredita consistencia con `operation=publish`, dos participants y `scheduler.action_schedule=1` colectivo. No prescribe el resultado del segundo publisher ni interpreta replay.

## 12. Cobertura field-level

```text
A5_BRANCH_RELEVANT_INITIAL_FIELDS=7
INITIAL_FIELDS_CLOSED=7
```

La presencia funcional, claim derivado, cardinalidades durable y action son assertions adicionales cerradas del perfil, no columnas funcionales adicionales. Las siete entradas funcionales son exactamente las enumeradas en §3.

Invariantes:

```text
reconciliation_status=pending
lease_owner=null
lease_acquired_at=null
lease_expires_at=null
lease_version=0
lease_active=0
attempt_count=0
all_generations_cardinality=0
generation_1_cardinality=0
generation_1_cardinality=0 => scheduled_action_id_for_generation_1=absent
```

## 13. Compatibilidad e implementación futura

| Componente | Impacto |
|---|---|
| fixture matrix | complementada solo para A11-CON-01 first_delivery |
| expected-actions authority | intacta; el perfil materializa su precondición |
| complementary A11 authority | wording histórico sustituido solo en el scope declarado |
| A5 producer/result/provenance | sin cambio |
| A8 | sin cambio |
| structured evidence transport v2 | sin cambio |
| Coordinator | futura validación pre-release requerida |
| bootstrap/harness | futura materialización, cleanup owned y validación requerida |
| producción PHP/SQL | sin cambio |

## 14. Cierre normativo

```text
TARGET_CASES=1
TARGET_PHASES=1
CON01_INITIAL_FUNCTIONAL_RECORD_CLOSED=PASS
CON01_RECONCILIATION_STATUS_CLOSED=PASS
CON01_ATTEMPT_COUNT_CLOSED=PASS
CON01_LEASE_FIELDS_CLOSED=PASS
CON01_LEASE_VERSION_CLOSED=PASS
CON01_LEGACY_CLAIM_CLOSED=PASS
CON01_GENERATION_1_CLOSED=PASS
CON01_SCHEDULED_ACTION_CLOSED=PASS
CON01_FREEZE_POINT_CLOSED=PASS
A5_BRANCH_RELEVANT_FIELDS_COMPLETE=PASS
SINGLE_PUBLISHER_SANITY_CLOSED=PASS
INITIAL_STATE_BLOCKER_RESOLVED=PASS
UNRESOLVED=0
```

**A11 CON-01 INITIAL STATE PROFILE IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
