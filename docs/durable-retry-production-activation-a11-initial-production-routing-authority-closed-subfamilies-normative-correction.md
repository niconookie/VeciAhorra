# Corrección normativa de subfamilias A8 authority_closed

## 1. Alcance y precedencia

Esta autoridad append-only cierra exclusivamente el mapping de las 24 families A5 que detienen routing hacia subfamilias canónicas de `DurableRetryInitialProductionRoutingResult::authority_closed`. Consume, sin modificar:

```text
A5_PROVENANCE_SHA=cf325f63db5a7559fa9969d2266f08361585148a439621eb19edbda1f1ea0989
CON01_INITIAL_STATE_SHA=0721dd811c98b6238500eeb6459414e4e4b8f15f6bc3c44cb65e208b62973766
A5_CONCURRENCY_SHA=02ba7db7b499e836e735fdf46be51d72d99b35748542276c7fe06e4c77d13ab7
CLOSED_PAIR_CATALOG_SHA=d90353d3ed2399b4dba998d471cb72bfff61eb3ed031c9310529b9d6acec6d1e
A8_FAILURE_PROVENANCE_SHA=0c643c6aaf8cb7f1dfde27baa44148d8be1cc6491aafe749cfeeaa7e18b64bf0
```

Supersede parcialmente la family estructural única `authority_closed` y el conteo 14 de la autoridad A8 precedente. Sus otras trece families, cinco outcome origins, cuatro dependency failure origins, effect identity y certainty permanecen vigentes. No cambia A5, A6, A7, A11 transport v2 ni reachability.

## 2. Reconstrucción A5

```text
A5_FAMILIES_RECONSTRUCTED=28
A5_CONTINUATION_FAMILIES=4
A5_AUTHORITY_CLOSING_FAMILIES=24
```

Las continuaciones son `legacy_allowed`, `durable_existing`, `durable_created` y `durable_converged`. Las otras 24 rows del §6 son el set exhaustivo que llega a `authorityClosed()`.

## 3. Cardinalidad canónica y regla de agrupación

```text
A5_SOURCE_FAMILIES=24
A8_AUTHORITY_CLOSED_FAMILIES=24
A5_TO_A8_MAPPING_ROWS=24
```

El mapping es 1:1. Cada source difiere de las demás en state, reason, authority/transfer evidence, recovery, operational origin, effect progress, certainty o generation identity. Esas propiedades afectan observation, identity, effects y futura proyección; por ello ninguna agrupación preserva la tupla completa.

IDs A8 canónicos:

`a8ac_legacy_in_flight_legacy_claim_in_flight`, `a8ac_functionally_ineligible_functional_record_absent`, `a8ac_functionally_ineligible_functional_state_ineligible`, `a8ac_authority_indeterminate_query_failed`, `a8ac_authority_indeterminate_incompatible_durable_state`, `a8ac_authority_indeterminate_persisted_duplicate`, `a8ac_authority_indeterminate_corrupt_identity`, `a8ac_authority_indeterminate_incomplete_result`, `a8ac_authority_indeterminate_unresolved_race`, `a8ac_authority_indeterminate_consistency_error`, `a8ac_durable_inconsistency_existing_transfer_incompatible`, `a8ac_durable_inconsistency_duplicate_durable_identity`, `a8ac_configuration_invalid_invalid_activation_configuration_value`, `a8ac_configuration_invalid_invalid_percentage`, `a8ac_configuration_invalid_unsupported_algorithm_version`, `a8ac_configuration_invalid_invalid_configuration_snapshot`, `a8ac_persistence_error_persistence_write_failed`, `a8ac_outcome_uncertain_persistence_outcome_uncertain_generation_1`, `a8ac_outcome_uncertain_persistence_outcome_uncertain_no_generation`, `a8ac_operational_failure_dependency_failure_input_validation`, `a8ac_operational_failure_dependency_failure_authority_classification`, `a8ac_operational_failure_activation_source_unavailable_activation_policy`, `a8ac_operational_failure_dependency_failure_activation_policy`, `a8ac_operational_failure_dependency_failure_initial_transfer`

## 4. Discriminante tipado

```text
AUTHORITY_CLOSED_DISCRIMINANT_FIELD=authority_outcome_family
AUTHORITY_CLOSED_DISCRIMINANT_TYPE=DurableRetryInitialAuthorityClosedFamily
AUTHORITY_CLOSED_DISCRIMINANT_CATALOG=los 24 family_id A8 de §3 y §6
```

`DurableRetryInitialAuthorityClosedFamily` es un final readonly value object con constructor privado, 24 constantes string, un named constructor por valor, `fromAuthority(DurableRetryInitialAuthorityProductionResult $authority): self`, `value(): string` y validación exhaustiva de la tupla A5. No acepta string libre, alias ni valor no catalogado.

La property `DurableRetryInitialAuthorityClosedFamily|null $authorityOutcomeFamily` es no nula exactamente con routing state `authority_closed`; los otros diez states exigen null.

## 5. Projection rules comunes

Para las 24 rows:

```text
routing_state=authority_closed
routing_reason=A5.reason literal
authority_outcome_discriminant=A8 family_id exacto
outcome_origin=authority_production
failure_origin=null
operational_failure_origin=A5.operational_failure_origin
effect_progress=A5.effect_progress
outcome_certainty=A5.outcome_certainty
requiresIntervention=A5.requiresRecovery
reconciliationId=route request positive ID
scheduleId=null
scheduledActionId=null
legacyScheduledFlag=false
```

Mapping total de progress:

| A5 effect_progress | A8 effects_started | A8 effect_progress preservado |
|---|---:|---|
| `not_started` | false | `not_started` |
| `preexisting` | true | `preexisting` |
| `confirmed` | true | `confirmed` |
| `possibly_started` | true | `possibly_started` |
| `presence_uncertain` | true | `presence_uncertain` |

`effects_started` declara existencia o posibilidad material; no reemplaza progress. `requiresIntervention` es idéntico a `requiresRecovery` para las 24 rows, sin excepción.

Generation/effect identity:

```text
A5 generation_identity=null
  -> A8 generation_identity=null
  -> generation=null
  -> effect_identity=reconciliation/v1(reconciliationId)

A5 generation_identity=(reconciliation,reconciliationId,1)
  -> A8 generation_identity=mismo value object
  -> generation=1
  -> effect_identity=durable_reconciliation_schedule/v1(reconciliationId,scheduleId=null,generation=1,scheduledActionId=null)
```

No se sintetiza schedule ID ni action ID.

## 6. Catálogo machine-checkable A5 -> A8

Cada row también fija: `routing_state=authority_closed`, `routing_reason=reason`, `outcome_origin=authority_production`, `failure_origin=null`, `scheduleId=null`, `scheduledActionId=null`, `legacyScheduledFlag=false`; `requiresIntervention` es la columna recovery.

| # | a5_family_id | a8_family_id | A5 state | A5 reason | authorityResult | transferResult | recovery/intervention | operational_failure_origin | effect_progress | effects_started | certainty | generation_identity | effect_identity |
|---:|---|---|---|---|---|---|---:|---|---|---:|---|---|---|
| 1 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a8ac_legacy_in_flight_legacy_claim_in_flight` | `legacy_in_flight` | `legacy_claim_in_flight` | `legacy` | `legacy_in_flight` | true | `null` | `preexisting` | true | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 2 | `a5f06_functionally_ineligible_functional_record_absent` | `a8ac_functionally_ineligible_functional_record_absent` | `functionally_ineligible` | `functional_record_absent` | `legacy` | `functionally_ineligible/functional_record_absent` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 3 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a8ac_functionally_ineligible_functional_state_ineligible` | `functionally_ineligible` | `functional_state_ineligible` | `legacy` | `functionally_ineligible/functional_state_ineligible` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 4 | `a5f08_authority_indeterminate_query_failed` | `a8ac_authority_indeterminate_query_failed` | `authority_indeterminate` | `query_failed` | `indeterminate/query_failed` | `null` | true | `null` | `presence_uncertain` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 5 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a8ac_authority_indeterminate_incompatible_durable_state` | `authority_indeterminate` | `incompatible_durable_state` | `indeterminate/incompatible_durable_state` | `null` | true | `null` | `preexisting` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 6 | `a5f10_authority_indeterminate_persisted_duplicate` | `a8ac_authority_indeterminate_persisted_duplicate` | `authority_indeterminate` | `persisted_duplicate` | `indeterminate/persisted_duplicate` | `null` | true | `null` | `preexisting` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 7 | `a5f11_authority_indeterminate_corrupt_identity` | `a8ac_authority_indeterminate_corrupt_identity` | `authority_indeterminate` | `corrupt_identity` | `indeterminate/corrupt_identity` | `null` | true | `null` | `preexisting` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 8 | `a5f12_authority_indeterminate_incomplete_result` | `a8ac_authority_indeterminate_incomplete_result` | `authority_indeterminate` | `incomplete_result` | `indeterminate/incomplete_result` | `null` | true | `null` | `presence_uncertain` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 9 | `a5f13_authority_indeterminate_unresolved_race` | `a8ac_authority_indeterminate_unresolved_race` | `authority_indeterminate` | `unresolved_race` | `indeterminate/unresolved_race` | `null` | true | `null` | `presence_uncertain` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 10 | `a5f14_authority_indeterminate_consistency_error` | `a8ac_authority_indeterminate_consistency_error` | `authority_indeterminate` | `consistency_error` | `indeterminate/consistency_error` | `null` | true | `null` | `presence_uncertain` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 11 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a8ac_durable_inconsistency_existing_transfer_incompatible` | `durable_inconsistency` | `existing_transfer_incompatible` | `legacy` | `durable_inconsistency/existing_transfer_incompatible` | true | `null` | `preexisting` | true | `definitive` | `generation_1` | `durable_reconciliation_schedule(reconciliationId,null,1,null)` |
| 12 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a8ac_durable_inconsistency_duplicate_durable_identity` | `durable_inconsistency` | `duplicate_durable_identity` | `legacy` | `durable_inconsistency/duplicate_durable_identity` | true | `null` | `preexisting` | true | `definitive` | `generation_1` | `durable_reconciliation_schedule(reconciliationId,null,1,null)` |
| 13 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a8ac_configuration_invalid_invalid_activation_configuration_value` | `configuration_invalid` | `invalid_activation_configuration_value` | `legacy` | `null` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 14 | `a5f18_configuration_invalid_invalid_percentage` | `a8ac_configuration_invalid_invalid_percentage` | `configuration_invalid` | `invalid_percentage` | `legacy` | `null` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 15 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a8ac_configuration_invalid_unsupported_algorithm_version` | `configuration_invalid` | `unsupported_algorithm_version` | `legacy` | `null` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 16 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a8ac_configuration_invalid_invalid_configuration_snapshot` | `configuration_invalid` | `invalid_configuration_snapshot` | `legacy` | `null` | false | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 17 | `a5f21_persistence_error_persistence_write_failed` | `a8ac_persistence_error_persistence_write_failed` | `persistence_error` | `persistence_write_failed` | `legacy` | `persistence_error` | true | `null` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 18 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a8ac_outcome_uncertain_persistence_outcome_uncertain_generation_1` | `outcome_uncertain` | `persistence_outcome_uncertain` | `legacy` | `outcome_uncertain/generation_1` | true | `null` | `possibly_started` | true | `uncertain` | `generation_1` | `durable_reconciliation_schedule(reconciliationId,null,1,null)` |
| 19 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a8ac_outcome_uncertain_persistence_outcome_uncertain_no_generation` | `outcome_uncertain` | `persistence_outcome_uncertain` | `legacy` | `outcome_uncertain/null_identity` | true | `null` | `possibly_started` | true | `uncertain` | `null` | `reconciliation(reconciliationId)` |
| 20 | `a5f24_operational_failure_dependency_failure_input_validation` | `a8ac_operational_failure_dependency_failure_input_validation` | `operational_failure` | `dependency_failure` | `null` | `null` | true | `input_validation` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 21 | `a5f25_operational_failure_dependency_failure_authority_classification` | `a8ac_operational_failure_dependency_failure_authority_classification` | `operational_failure` | `dependency_failure` | `null` | `null` | true | `authority_classification` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 22 | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `a8ac_operational_failure_activation_source_unavailable_activation_policy` | `operational_failure` | `activation_configuration_source_unavailable` | `legacy` | `null` | true | `activation_policy` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 23 | `a5f27_operational_failure_dependency_failure_activation_policy` | `a8ac_operational_failure_dependency_failure_activation_policy` | `operational_failure` | `dependency_failure` | `legacy` | `null` | true | `activation_policy` | `not_started` | false | `definitive` | `null` | `reconciliation(reconciliationId)` |
| 24 | `a5f28_operational_failure_dependency_failure_initial_transfer` | `a8ac_operational_failure_dependency_failure_initial_transfer` | `operational_failure` | `dependency_failure` | `legacy` | `exception/no_result` | true | `initial_transfer` | `possibly_started` | true | `uncertain` | `generation_1` | `durable_reconciliation_schedule(reconciliationId,null,1,null)` |

## 7. a5f15

```text
A5F15_A8_FAMILY_ID=a8ac_durable_inconsistency_existing_transfer_incompatible
```

Tupla completa:

```text
family_id=a8ac_durable_inconsistency_existing_transfer_incompatible
source_a5_family=a5f15_durable_inconsistency_existing_transfer_incompatible
routing_state=authority_closed
routing_reason=existing_transfer_incompatible
authority_outcome_discriminant=a8ac_durable_inconsistency_existing_transfer_incompatible
outcome_origin=authority_production
failure_origin=null
operational_failure_origin=null
effect_progress=preexisting
effects_started=true
effect_identity=durable_reconciliation_schedule/v1(reconciliationId,null,1,null)
outcome_certainty=definitive
requiresIntervention=true
generation_identity=(reconciliation,reconciliationId,1)
reconciliationId=route request positive ID
scheduleId=null
generation=1
scheduledActionId=null
legacyScheduledFlag=false
```

## 8. Operational failure preservation

Las cinco operational families permanecen separadas:

```text
input_validation/not_started/definitive/null_generation
authority_classification/not_started/definitive/null_generation
activation_policy+activation_source_unavailable/not_started/definitive/null_generation
activation_policy+dependency_failure/not_started/definitive/null_generation
initial_transfer/possibly_started/uncertain/generation_1
```

`operational_failure_origin` solo es no nulo en esas cinco subfamilias y coincide literalmente con A5. Legacy in flight, both functionally ineligible variants, siete authority indeterminate variants, dos durable inconsistency variants, cuatro configuration invalid variants, persistence error y las dos outcome uncertain variants también conservan rows separadas.

```text
OPERATIONAL_FAILURE_PROVENANCE_PRESERVED=PASS
EFFECT_PROGRESS_PRESERVED=PASS
OUTCOME_CERTAINTY_PRESERVED=PASS
GENERATION_IDENTITY_PRESERVED=PASS
```

## 9. Corrected routing family count

La autoridad precedente contiene catorce families: trece permanecen canónicas y la family estructural authority closed se reemplaza por 24.

```text
PREVIOUS_ROUTING_FAMILIES=14
UNCHANGED_ROUTING_FAMILIES=13
A5_AUTHORITY_CLOSING_FAMILIES=24
A8_AUTHORITY_CLOSED_FAMILIES=24
CORRECTED_ROUTING_FAMILIES=37
ROUTING_STATES=11
```

Cálculo: `14 - 1 + 24 = 37`.

## 10. Concurrency closed × closed

La autoridad de reachability contiene 300 source pairs, todos impossible. Esa propiedad se proyecta sin expansión al catálogo A8 1:1.

```text
AUTHORITY_CLOSED_THEORETICAL_SOURCE_PAIRS=300
AUTHORITY_CLOSED_SOURCE_REACHABLE_PAIRS=0
AUTHORITY_CLOSED_SOURCE_IMPOSSIBLE_PAIRS=300
A8_AUTHORITY_CLOSED_REACHABLE_PAIRS=0
AUTHORITY_CLOSED_CONCURRENT_SEMANTIC_CLASSIFICATION_ROWS=0
A8_AUTHORITY_CLOSED_EQUIVALENT_PAIRS=0
A8_AUTHORITY_CLOSED_COMPATIBLE_PROGRESSION_PAIRS=0
A8_AUTHORITY_CLOSED_CONFLICTING_PAIRS=0
AUTHORITY_CLOSED_PAIRWISE_UNCERTAINTY_RESOLUTION=not_applicable_due_to_empty_reachable_domain
```

Los 300 impossible no son conflicts. El cierre por vacuidad solo afirma que dos authority closed no coexisten en esta invocation; no equipara sus semánticas globales.

## 11. API futura exacta

`DurableRetryInitialProductionRoutingResult` añade estas private readonly properties:

```php
DurableRetryInitialAuthorityClosedFamily|null $authorityOutcomeFamily;
string|null $operationalFailureOrigin;
string|null $effectProgress;
DurableRetryGenerationIdentity|null $generationIdentity;
string $outcomeOrigin;
string|null $failureOrigin;
bool $effectsStarted;
DurableRetryInitialProductionEffectIdentity|null $effectIdentity;
string $outcomeCertainty;
```

Factory exacto:

```php
public static function authorityClosed(
    int $reconciliationId,
    DurableRetryInitialAuthorityProductionResult $authority
): self;
```

El factory valida que A5 cierre routing, obtiene `DurableRetryInitialAuthorityClosedFamily::fromAuthority($authority)`, copia reason/origin/progress/certainty/recovery/generation identity, construye effect identity conforme a §5 y fija los IDs no disponibles en null.

Accessors exactos:

```php
public function authorityOutcomeFamily(): DurableRetryInitialAuthorityClosedFamily|null;
public function operationalFailureOrigin(): string|null;
public function effectProgress(): string|null;
public function generationIdentity(): DurableRetryGenerationIdentity|null;
public function outcomeOrigin(): string;
public function failureOrigin(): string|null;
public function effectsStarted(): bool;
public function effectIdentity(): DurableRetryInitialProductionEffectIdentity|null;
public function outcomeCertainty(): string;
```

Los accessors existentes de state, reason, reconciliationId, scheduleId, generation, scheduledActionId, legacyScheduledFlag y requiresIntervention continúan.

## 12. Constructor invariants

1. `authorityOutcomeFamily`, `operationalFailureOrigin`, `effectProgress` y `generationIdentity` deben reproducir exactamente la row §6 seleccionada.
2. Authority closed exige outcome origin `authority_production`, failure origin null, reconciliation positiva, schedule/action IDs null y legacy flag false.
3. Progress `not_started` exige effects false; los otros cuatro progress values exigen effects true.
4. Certainty `uncertain` exige intervention true; definitive conserva el recovery exacto de la row.
5. Generation identity presente exige stage reconciliation, subject igual a reconciliationId, generation 1, effect identity durable con los mismos valores y generation accessor 1.
6. Generation identity null exige generation accessor null y effect identity reconciliation.
7. Operational origin es no nulo exactamente para las cinco operational families; `initial_transfer` exige possibly started, uncertain y generation 1.
8. Cualquier discriminant que no corresponda byte por byte a state/reason/provenance A5 es rechazado por `InvalidArgumentException`.
9. Fuera de authority closed, authority outcome family, operational origin y effect progress son null salvo la provenance general ya definida por la autoridad A8 precedente.

## 13. Productive observation y prohibición post-hoc

Las 24 rows pueden serializarse literalmente en un futuro payload de `veciahorra-a11-productive-observation/v1`: todos los discriminantes, provenance e identities existen en el DTO antes del codec.

A11 no reconstruye family, progress, certainty u origin desde DB posterior, proposals, logs, PID, participant, arrival order ni el segundo result.

```text
ALL_AUTHORITY_CLOSED_FAMILIES_OBSERVABLE_WITHOUT_POST_HOC_INFERENCE=PASS
STRUCTURED_TRANSPORT_V2_CHANGE_REQUIRED=no
```

Frame, cardinality, ownership y operation result v2 no cambian; un codec posterior solo proyectará el payload específico.

## 14. Compatibility

```text
A5_PROVENANCE_CHANGE_REQUIRED=no
A5_CONTINUATION_BEHAVIOR_CHANGED=no
A6_CHANGE_REQUIRED=no
A7_CHANGE_REQUIRED=no
STRUCTURED_TRANSPORT_V2_CHANGE_REQUIRED=no
```

El routing result y su authority-closed value object requieren implementación futura. Router, interface pública de routing, initial-state profile, reachability y las trece routing families restantes conservan conducta.

## 15. Downstream handoff

```text
NEXT_REQUIRED_SCOPE=A11-CON-01 mixed routing outcome reachability/projection: authority_closed + A6/A7-derived routing outcome
FIRST_KNOWN_MIXED_SOURCE=a5f15_durable_inconsistency_existing_transfer_incompatible
FIRST_KNOWN_MIXED_A8_FAMILY=a8ac_durable_inconsistency_existing_transfer_incompatible
```

`a5f15` es reachable como second locker y su first locker continúa mediante `durable_created`. Esta autoridad no sigue esa continuación por A6/A7 ni decide el routing state mixto.

## 16. Validación normativa

```text
A5_SOURCE_FAMILIES=24
A5_TO_A8_MAPPING_ROWS=24
MISSING_A5_MAPPING=0
DUPLICATE_A5_MAPPING=0
EACH_A8_FAMILY_SINGLE_TUPLE=PASS
OPERATIONAL_FAILURE_PROVENANCE_PRESERVED=PASS
EFFECT_PROGRESS_PRESERVED=PASS
OUTCOME_CERTAINTY_PRESERVED=PASS
GENERATION_IDENTITY_PRESERVED=PASS
CORRECTED_ROUTING_FAMILY_COUNT_CLOSED=PASS
SOURCE_PAIR_CATALOG_SHA_MATCH=PASS
AUTHORITY_CLOSED_REACHABLE_PAIRS=0
VACUOUS_SEMANTIC_CLASSIFICATION_CLOSED=PASS
ALL_AUTHORITY_CLOSED_FAMILIES_OBSERVABLE_WITHOUT_POST_HOC_INFERENCE=PASS
A5_PROVENANCE_CHANGE_REQUIRED=no
STRUCTURED_TRANSPORT_V2_CHANGE_REQUIRED=no
UNRESOLVED=0
```

## 17. Veredicto

**A11 INITIAL PRODUCTION ROUTING AUTHORITY_CLOSED SUBFAMILIES IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**
