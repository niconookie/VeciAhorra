# Catálogo normativo de reachability de pares authority_closed A5 para CON-01

## 1. Alcance y autoridades consumidas

Esta autoridad append-only deriva exclusivamente la co-occurrence de las 24 familias A5 que el router cierra como `authority_closed` para `A11-CON-01 / first_delivery / a11_000000000001_fd`. No modifica sus tres fuentes:

```text
CONCURRENCY_MODEL_SHA=02ba7db7b499e836e735fdf46be51d72d99b35748542276c7fe06e4c77d13ab7
INITIAL_STATE_SHA=0721dd811c98b6238500eeb6459414e4e4b8f15f6bc3c44cb65e208b62973766
A5_PROVENANCE_SHA=cf325f63db5a7559fa9969d2266f08361585148a439621eb19edbda1f1ea0989
```

El initial profile es `a11_con_01_initial_reconciliation_v1`. Isolation permanece `READ COMMITTED`; G01–G10, el mundo sin terceros/faults/crash/delete y los timeouts cerrados son vinculantes. A8, transport v2, codec, projector y operation result quedan fuera de alcance.

## 2. Definición formal y universo

Un par unordered `{A,B}` es `reachable` si existe al menos un interleaving válido de los dos publishers desde el initial profile, conforme a READ COMMITTED, functional locking, durable lookup, G01–G10, publisher mutation set, cero terceros, cero faults inyectados, cero crash, cero delete funcional y el contrato de participant timeout, donde los participants terminan respectivamente en A y B.

Es `impossible` si ningún interleaving autorizado produce ambos outcomes. Reachability global de una family fuera de CON-01 no acredita reachability en este catálogo.

```text
SOURCE_FAMILIES_EXPECTED=24
SOURCE_FAMILIES_RECONSTRUCTED=24
DUPLICATE_FAMILY_IDS=0
DISTINCT_NON_SELF_PAIRS=276
SELF_PAIRS=24
PAIR_ROWS_EXPECTED=300
THEORETICAL_UNORDERED_PAIRS=300
```

Las cuatro families de continuación `legacy_allowed|durable_existing|durable_created|durable_converged` están excluidas. La matriz 24×24 responde co-occurrence entre cierres; no enumera combinaciones invocation que contengan una continuación.

## 3. Orden canónico y catálogo de families

El orden es el ordinal 05..28 del catálogo enriched A5. El `family_id` concatena ordinal, state, reason y el discriminante G/N u origin cuando se requiere unicidad. Generation/effect `generation_1` significa `(reconciliation,subject_id,1)`; `null` significa ausencia tipada.

| Ordinal | family_id | state | reason | authorityResult | transferResult | requiresRecovery | operational_failure_origin | effect_progress | outcome_certainty | generation_identity | effect_identity | CON01 individual | proof |
|---:|---|---|---|---|---|---:|---|---|---|---|---|---|---|
| 5 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `legacy_in_flight` | `legacy_claim_in_flight` | `legacy` | `legacy_in_flight` | true | `null` | `preexisting` | `definitive` | `null` | `null` | `impossible` | `I01` |
| 6 | `a5f06_functionally_ineligible_functional_record_absent` | `functionally_ineligible` | `functional_record_absent` | `legacy` | `functionally_ineligible/functional_record_absent` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I02` |
| 7 | `a5f07_functionally_ineligible_functional_state_ineligible` | `functionally_ineligible` | `functional_state_ineligible` | `legacy` | `functionally_ineligible/functional_state_ineligible` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I03` |
| 8 | `a5f08_authority_indeterminate_query_failed` | `authority_indeterminate` | `query_failed` | `indeterminate/query_failed` | `null` | true | `null` | `presence_uncertain` | `uncertain` | `null` | `null` | `impossible` | `I04` |
| 9 | `a5f09_authority_indeterminate_incompatible_durable_state` | `authority_indeterminate` | `incompatible_durable_state` | `indeterminate/incompatible_durable_state` | `null` | true | `null` | `preexisting` | `uncertain` | `null` | `null` | `impossible` | `I05` |
| 10 | `a5f10_authority_indeterminate_persisted_duplicate` | `authority_indeterminate` | `persisted_duplicate` | `indeterminate/persisted_duplicate` | `null` | true | `null` | `preexisting` | `uncertain` | `null` | `null` | `impossible` | `I06` |
| 11 | `a5f11_authority_indeterminate_corrupt_identity` | `authority_indeterminate` | `corrupt_identity` | `indeterminate/corrupt_identity` | `null` | true | `null` | `preexisting` | `uncertain` | `null` | `null` | `impossible` | `I05` |
| 12 | `a5f12_authority_indeterminate_incomplete_result` | `authority_indeterminate` | `incomplete_result` | `indeterminate/incomplete_result` | `null` | true | `null` | `presence_uncertain` | `uncertain` | `null` | `null` | `impossible` | `I04` |
| 13 | `a5f13_authority_indeterminate_unresolved_race` | `authority_indeterminate` | `unresolved_race` | `indeterminate/unresolved_race` | `null` | true | `null` | `presence_uncertain` | `uncertain` | `null` | `null` | `impossible` | `I05` |
| 14 | `a5f14_authority_indeterminate_consistency_error` | `authority_indeterminate` | `consistency_error` | `indeterminate/consistency_error` | `null` | true | `null` | `presence_uncertain` | `uncertain` | `null` | `null` | `impossible` | `I05` |
| 15 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `durable_inconsistency` | `existing_transfer_incompatible` | `legacy` | `durable_inconsistency/existing_transfer_incompatible` | true | `null` | `preexisting` | `definitive` | `generation_1` | `generation_1` | `reachable` | `I07` |
| 16 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `durable_inconsistency` | `duplicate_durable_identity` | `legacy` | `durable_inconsistency/duplicate_durable_identity` | true | `null` | `preexisting` | `definitive` | `generation_1` | `generation_1` | `impossible` | `I06` |
| 17 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `configuration_invalid` | `invalid_activation_configuration_value` | `legacy` | `null` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 18 | `a5f18_configuration_invalid_invalid_percentage` | `configuration_invalid` | `invalid_percentage` | `legacy` | `null` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 19 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `configuration_invalid` | `unsupported_algorithm_version` | `legacy` | `null` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 20 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `configuration_invalid` | `invalid_configuration_snapshot` | `legacy` | `null` | false | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 21 | `a5f21_persistence_error_persistence_write_failed` | `persistence_error` | `persistence_write_failed` | `legacy` | `persistence_error` | true | `null` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I09` |
| 22 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `outcome_uncertain` | `persistence_outcome_uncertain` | `legacy` | `outcome_uncertain/generation_1` | true | `null` | `possibly_started` | `uncertain` | `generation_1` | `generation_1` | `impossible` | `I09` |
| 23 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `outcome_uncertain` | `persistence_outcome_uncertain` | `legacy` | `outcome_uncertain/null_identity` | true | `null` | `possibly_started` | `uncertain` | `null` | `null` | `impossible` | `I09` |
| 24 | `a5f24_operational_failure_dependency_failure_input_validation` | `operational_failure` | `dependency_failure` | `null` | `null` | true | `input_validation` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I10` |
| 25 | `a5f25_operational_failure_dependency_failure_authority_classification` | `operational_failure` | `dependency_failure` | `null` | `null` | true | `authority_classification` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I09` |
| 26 | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `operational_failure` | `activation_configuration_source_unavailable` | `legacy` | `null` | true | `activation_policy` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 27 | `a5f27_operational_failure_dependency_failure_activation_policy` | `operational_failure` | `dependency_failure` | `legacy` | `null` | true | `activation_policy` | `not_started` | `definitive` | `null` | `null` | `impossible` | `I08` |
| 28 | `a5f28_operational_failure_dependency_failure_initial_transfer` | `operational_failure` | `dependency_failure` | `legacy` | `exception/no_result` | true | `initial_transfer` | `possibly_started` | `uncertain` | `generation_1` | `generation_1` | `impossible` | `I09` |

```text
SOURCE_FAMILIES=24
CONTINUATION_FAMILY_REFS=0
CON01_INDIVIDUALLY_REACHABLE_FAMILIES=[a5f15_durable_inconsistency_existing_transfer_incompatible]
CON01_INDIVIDUALLY_IMPOSSIBLE_FAMILIES=[a5f05_legacy_in_flight_legacy_claim_in_flight,a5f06_functionally_ineligible_functional_record_absent,a5f07_functionally_ineligible_functional_state_ineligible,a5f08_authority_indeterminate_query_failed,a5f09_authority_indeterminate_incompatible_durable_state,a5f10_authority_indeterminate_persisted_duplicate,a5f11_authority_indeterminate_corrupt_identity,a5f12_authority_indeterminate_incomplete_result,a5f13_authority_indeterminate_unresolved_race,a5f14_authority_indeterminate_consistency_error,a5f16_durable_inconsistency_duplicate_durable_identity,a5f17_configuration_invalid_invalid_activation_configuration_value,a5f18_configuration_invalid_invalid_percentage,a5f19_configuration_invalid_unsupported_algorithm_version,a5f20_configuration_invalid_invalid_configuration_snapshot,a5f21_persistence_error_persistence_write_failed,a5f22_outcome_uncertain_persistence_outcome_uncertain_g,a5f23_outcome_uncertain_persistence_outcome_uncertain_n,a5f24_operational_failure_dependency_failure_input_validation,a5f25_operational_failure_dependency_failure_authority_classification,a5f26_operational_failure_activation_source_unavailable_activation_policy,a5f27_operational_failure_dependency_failure_activation_policy,a5f28_operational_failure_dependency_failure_initial_transfer]
```

## 4. Pruebas de reachability individual

| Rule | Cierre |
|---|---|
| `I01` | F0 no tiene claim y G01–G10 no lo crean. |
| `I02` | F0 contiene la fila funcional y ningún edge la elimina. |
| `I03` | F0 permanece pending/attempt 0/lease null/version 0. |
| `I04` | query failure e incomplete result requieren un failure excluido por `CON01_ALLOWED_FAULTS=[]`. |
| `I05` | S0–S5 no contienen identidad corrupta, durable incompatible en A3, unresolved race ni consistency error. |
| `I06` | Los unique constraints y la serialización funcional excluyen persisted duplicate y duplicate durable identity. |
| `I07` | Witness individual `IW01`: ambos clasifican legacy desde S0; first_locker ejecuta G01–G04 y luego G06–G07 hasta S5; second_locker reevalúa T05, ejecuta G09 y `classifyExisting` rechaza `scheduled_action_id` asociado frente al snapshot esperado null. |
| `I08` | El fixture/config sellado y fault model vacío excluyen invalid configuration y activation failures. |
| `I09` | Persistence/exception/uncertainty outcomes requieren failures excluidos; DB/supervisor environment failures invalidan la ejecución. |
| `I10` | El request case-specific válido excluye input validation failure. |

`a5f15` es alcanzable como outcome de un solo participant. Su co-participant first_locker termina `durable_created`, una family de continuación fuera de este universo.

## 5. First/second-locker sets

```text
FIRST_LOCKER_POSSIBLE_FAMILIES=[]
SECOND_LOCKER_AFTER_COMMIT_POSSIBLE_FAMILIES=[a5f15_durable_inconsistency_existing_transfer_incompatible]
SECOND_LOCKER_AFTER_ROLLBACK_POSSIBLE_FAMILIES=[]
```

El first locker fault-free avanza S0→S4 y obtiene `durable_created`. El second locker desde S4 obtiene una continuación compatible; desde S5 puede obtener `a5f15`. No existe rollback normal productor de una family closed bajo el fault model vacío. Cualquiera de los dos participant IDs ocupa cada rol.

## 6. Proof-rule catalog pairwise

| Rule | Predicado suficiente |
|---|---|
| `P01` | El pair contiene al menos una family marcada `impossible` por I01–I06 o I08–I10; por definición no existe ejecución conjunta. |
| `P02` | Ambos elementos son `a5f15`; esta family exige el rol second_locker después de S5, mientras el único first_locker termina en la family de continuación `durable_created`; dos second lockers no existen en una ejecución de dos participants serializada por functional X. |

## 7. Witness catalog pairwise

```text
WITNESSES=[]
```

No hay pair reachable y, por tanto, no existe witness pairwise. `IW01` acredita solamente reachability individual de `a5f15` y no es un witness de dos cierres.

## 8. Algoritmo de posiciones

Families se indexan `f[0..23]` según §3. Se enumeran `i=0..23`; para cada `i`, `j=i..23`. Cada fila contiene `family_a=f[i]`, `family_b=f[j]`, por lo que `family_a<=family_b` en el orden canónico. `pair_position` incrementa desde 1. El algoritmo produce `Σ(24-i)=300` filas, incluidas 24 self-pairs. Participant identity, PID y arrival order no intervienen.

## 9. Matriz machine-checkable de 300 filas

`witness_id=null` significa ausencia normativa de witness para un pair impossible.

| pair_position | family_a | family_b | reachability | proof_rule | witness_id |
|---:|---|---|---|---|---|
| 1 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `impossible` | `P01` | `null` |
| 2 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f06_functionally_ineligible_functional_record_absent` | `impossible` | `P01` | `null` |
| 3 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f07_functionally_ineligible_functional_state_ineligible` | `impossible` | `P01` | `null` |
| 4 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f08_authority_indeterminate_query_failed` | `impossible` | `P01` | `null` |
| 5 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f09_authority_indeterminate_incompatible_durable_state` | `impossible` | `P01` | `null` |
| 6 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 7 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 8 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 9 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 10 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 11 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 12 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 13 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 14 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 15 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 16 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 17 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 18 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 19 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 20 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 21 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 22 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 23 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 24 | `a5f05_legacy_in_flight_legacy_claim_in_flight` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 25 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f06_functionally_ineligible_functional_record_absent` | `impossible` | `P01` | `null` |
| 26 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f07_functionally_ineligible_functional_state_ineligible` | `impossible` | `P01` | `null` |
| 27 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f08_authority_indeterminate_query_failed` | `impossible` | `P01` | `null` |
| 28 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f09_authority_indeterminate_incompatible_durable_state` | `impossible` | `P01` | `null` |
| 29 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 30 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 31 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 32 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 33 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 34 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 35 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 36 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 37 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 38 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 39 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 40 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 41 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 42 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 43 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 44 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 45 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 46 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 47 | `a5f06_functionally_ineligible_functional_record_absent` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 48 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f07_functionally_ineligible_functional_state_ineligible` | `impossible` | `P01` | `null` |
| 49 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f08_authority_indeterminate_query_failed` | `impossible` | `P01` | `null` |
| 50 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f09_authority_indeterminate_incompatible_durable_state` | `impossible` | `P01` | `null` |
| 51 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 52 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 53 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 54 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 55 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 56 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 57 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 58 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 59 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 60 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 61 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 62 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 63 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 64 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 65 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 66 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 67 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 68 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 69 | `a5f07_functionally_ineligible_functional_state_ineligible` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 70 | `a5f08_authority_indeterminate_query_failed` | `a5f08_authority_indeterminate_query_failed` | `impossible` | `P01` | `null` |
| 71 | `a5f08_authority_indeterminate_query_failed` | `a5f09_authority_indeterminate_incompatible_durable_state` | `impossible` | `P01` | `null` |
| 72 | `a5f08_authority_indeterminate_query_failed` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 73 | `a5f08_authority_indeterminate_query_failed` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 74 | `a5f08_authority_indeterminate_query_failed` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 75 | `a5f08_authority_indeterminate_query_failed` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 76 | `a5f08_authority_indeterminate_query_failed` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 77 | `a5f08_authority_indeterminate_query_failed` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 78 | `a5f08_authority_indeterminate_query_failed` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 79 | `a5f08_authority_indeterminate_query_failed` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 80 | `a5f08_authority_indeterminate_query_failed` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 81 | `a5f08_authority_indeterminate_query_failed` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 82 | `a5f08_authority_indeterminate_query_failed` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 83 | `a5f08_authority_indeterminate_query_failed` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 84 | `a5f08_authority_indeterminate_query_failed` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 85 | `a5f08_authority_indeterminate_query_failed` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 86 | `a5f08_authority_indeterminate_query_failed` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 87 | `a5f08_authority_indeterminate_query_failed` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 88 | `a5f08_authority_indeterminate_query_failed` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 89 | `a5f08_authority_indeterminate_query_failed` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 90 | `a5f08_authority_indeterminate_query_failed` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 91 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f09_authority_indeterminate_incompatible_durable_state` | `impossible` | `P01` | `null` |
| 92 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 93 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 94 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 95 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 96 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 97 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 98 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 99 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 100 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 101 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 102 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 103 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 104 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 105 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 106 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 107 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 108 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 109 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 110 | `a5f09_authority_indeterminate_incompatible_durable_state` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 111 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f10_authority_indeterminate_persisted_duplicate` | `impossible` | `P01` | `null` |
| 112 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 113 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 114 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 115 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 116 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 117 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 118 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 119 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 120 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 121 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 122 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 123 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 124 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 125 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 126 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 127 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 128 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 129 | `a5f10_authority_indeterminate_persisted_duplicate` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 130 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f11_authority_indeterminate_corrupt_identity` | `impossible` | `P01` | `null` |
| 131 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 132 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 133 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 134 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 135 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 136 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 137 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 138 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 139 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 140 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 141 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 142 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 143 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 144 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 145 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 146 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 147 | `a5f11_authority_indeterminate_corrupt_identity` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 148 | `a5f12_authority_indeterminate_incomplete_result` | `a5f12_authority_indeterminate_incomplete_result` | `impossible` | `P01` | `null` |
| 149 | `a5f12_authority_indeterminate_incomplete_result` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 150 | `a5f12_authority_indeterminate_incomplete_result` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 151 | `a5f12_authority_indeterminate_incomplete_result` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 152 | `a5f12_authority_indeterminate_incomplete_result` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 153 | `a5f12_authority_indeterminate_incomplete_result` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 154 | `a5f12_authority_indeterminate_incomplete_result` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 155 | `a5f12_authority_indeterminate_incomplete_result` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 156 | `a5f12_authority_indeterminate_incomplete_result` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 157 | `a5f12_authority_indeterminate_incomplete_result` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 158 | `a5f12_authority_indeterminate_incomplete_result` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 159 | `a5f12_authority_indeterminate_incomplete_result` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 160 | `a5f12_authority_indeterminate_incomplete_result` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 161 | `a5f12_authority_indeterminate_incomplete_result` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 162 | `a5f12_authority_indeterminate_incomplete_result` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 163 | `a5f12_authority_indeterminate_incomplete_result` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 164 | `a5f12_authority_indeterminate_incomplete_result` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 165 | `a5f13_authority_indeterminate_unresolved_race` | `a5f13_authority_indeterminate_unresolved_race` | `impossible` | `P01` | `null` |
| 166 | `a5f13_authority_indeterminate_unresolved_race` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 167 | `a5f13_authority_indeterminate_unresolved_race` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 168 | `a5f13_authority_indeterminate_unresolved_race` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 169 | `a5f13_authority_indeterminate_unresolved_race` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 170 | `a5f13_authority_indeterminate_unresolved_race` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 171 | `a5f13_authority_indeterminate_unresolved_race` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 172 | `a5f13_authority_indeterminate_unresolved_race` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 173 | `a5f13_authority_indeterminate_unresolved_race` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 174 | `a5f13_authority_indeterminate_unresolved_race` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 175 | `a5f13_authority_indeterminate_unresolved_race` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 176 | `a5f13_authority_indeterminate_unresolved_race` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 177 | `a5f13_authority_indeterminate_unresolved_race` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 178 | `a5f13_authority_indeterminate_unresolved_race` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 179 | `a5f13_authority_indeterminate_unresolved_race` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 180 | `a5f13_authority_indeterminate_unresolved_race` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 181 | `a5f14_authority_indeterminate_consistency_error` | `a5f14_authority_indeterminate_consistency_error` | `impossible` | `P01` | `null` |
| 182 | `a5f14_authority_indeterminate_consistency_error` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P01` | `null` |
| 183 | `a5f14_authority_indeterminate_consistency_error` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 184 | `a5f14_authority_indeterminate_consistency_error` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 185 | `a5f14_authority_indeterminate_consistency_error` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 186 | `a5f14_authority_indeterminate_consistency_error` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 187 | `a5f14_authority_indeterminate_consistency_error` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 188 | `a5f14_authority_indeterminate_consistency_error` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 189 | `a5f14_authority_indeterminate_consistency_error` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 190 | `a5f14_authority_indeterminate_consistency_error` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 191 | `a5f14_authority_indeterminate_consistency_error` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 192 | `a5f14_authority_indeterminate_consistency_error` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 193 | `a5f14_authority_indeterminate_consistency_error` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 194 | `a5f14_authority_indeterminate_consistency_error` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 195 | `a5f14_authority_indeterminate_consistency_error` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 196 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `impossible` | `P02` | `null` |
| 197 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 198 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 199 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 200 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 201 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 202 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 203 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 204 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 205 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 206 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 207 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 208 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 209 | `a5f15_durable_inconsistency_existing_transfer_incompatible` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 210 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f16_durable_inconsistency_duplicate_durable_identity` | `impossible` | `P01` | `null` |
| 211 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 212 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 213 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 214 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 215 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 216 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 217 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 218 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 219 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 220 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 221 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 222 | `a5f16_durable_inconsistency_duplicate_durable_identity` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 223 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `impossible` | `P01` | `null` |
| 224 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 225 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 226 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 227 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 228 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 229 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 230 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 231 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 232 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 233 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 234 | `a5f17_configuration_invalid_invalid_activation_configuration_value` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 235 | `a5f18_configuration_invalid_invalid_percentage` | `a5f18_configuration_invalid_invalid_percentage` | `impossible` | `P01` | `null` |
| 236 | `a5f18_configuration_invalid_invalid_percentage` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 237 | `a5f18_configuration_invalid_invalid_percentage` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 238 | `a5f18_configuration_invalid_invalid_percentage` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 239 | `a5f18_configuration_invalid_invalid_percentage` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 240 | `a5f18_configuration_invalid_invalid_percentage` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 241 | `a5f18_configuration_invalid_invalid_percentage` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 242 | `a5f18_configuration_invalid_invalid_percentage` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 243 | `a5f18_configuration_invalid_invalid_percentage` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 244 | `a5f18_configuration_invalid_invalid_percentage` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 245 | `a5f18_configuration_invalid_invalid_percentage` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 246 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f19_configuration_invalid_unsupported_algorithm_version` | `impossible` | `P01` | `null` |
| 247 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 248 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 249 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 250 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 251 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 252 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 253 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 254 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 255 | `a5f19_configuration_invalid_unsupported_algorithm_version` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 256 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `impossible` | `P01` | `null` |
| 257 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 258 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 259 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 260 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 261 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 262 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 263 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 264 | `a5f20_configuration_invalid_invalid_configuration_snapshot` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 265 | `a5f21_persistence_error_persistence_write_failed` | `a5f21_persistence_error_persistence_write_failed` | `impossible` | `P01` | `null` |
| 266 | `a5f21_persistence_error_persistence_write_failed` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 267 | `a5f21_persistence_error_persistence_write_failed` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 268 | `a5f21_persistence_error_persistence_write_failed` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 269 | `a5f21_persistence_error_persistence_write_failed` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 270 | `a5f21_persistence_error_persistence_write_failed` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 271 | `a5f21_persistence_error_persistence_write_failed` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 272 | `a5f21_persistence_error_persistence_write_failed` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 273 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `impossible` | `P01` | `null` |
| 274 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 275 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 276 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 277 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 278 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 279 | `a5f22_outcome_uncertain_persistence_outcome_uncertain_g` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 280 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `impossible` | `P01` | `null` |
| 281 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 282 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 283 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 284 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 285 | `a5f23_outcome_uncertain_persistence_outcome_uncertain_n` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 286 | `a5f24_operational_failure_dependency_failure_input_validation` | `a5f24_operational_failure_dependency_failure_input_validation` | `impossible` | `P01` | `null` |
| 287 | `a5f24_operational_failure_dependency_failure_input_validation` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 288 | `a5f24_operational_failure_dependency_failure_input_validation` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 289 | `a5f24_operational_failure_dependency_failure_input_validation` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 290 | `a5f24_operational_failure_dependency_failure_input_validation` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 291 | `a5f25_operational_failure_dependency_failure_authority_classification` | `a5f25_operational_failure_dependency_failure_authority_classification` | `impossible` | `P01` | `null` |
| 292 | `a5f25_operational_failure_dependency_failure_authority_classification` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 293 | `a5f25_operational_failure_dependency_failure_authority_classification` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 294 | `a5f25_operational_failure_dependency_failure_authority_classification` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 295 | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `impossible` | `P01` | `null` |
| 296 | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 297 | `a5f26_operational_failure_activation_source_unavailable_activation_policy` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 298 | `a5f27_operational_failure_dependency_failure_activation_policy` | `a5f27_operational_failure_dependency_failure_activation_policy` | `impossible` | `P01` | `null` |
| 299 | `a5f27_operational_failure_dependency_failure_activation_policy` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |
| 300 | `a5f28_operational_failure_dependency_failure_initial_transfer` | `a5f28_operational_failure_dependency_failure_initial_transfer` | `impossible` | `P01` | `null` |

## 10. Resultado agregado de reachability

```text
PAIR_ROWS=300
REACHABLE_PAIRS=0
IMPOSSIBLE_PAIRS=300
REACHABLE_PLUS_IMPOSSIBLE=300
SELF_PAIRS=24
SELF_PAIRS_REACHABLE=0
SELF_PAIRS_IMPOSSIBLE=24
MISSING_PAIRS=0
DUPLICATE_PAIRS=0
UNKNOWN_FAMILY_REFS=0
```

Los 276 pairs no-self y los 24 self-pairs son impossible. El conjunto reachable vacío es una conclusión sobre dos cierres simultáneos; no contradice `IW01`, donde el otro participant continúa.

## 11. Pair histórico

```text
PAIR=a5f05_legacy_in_flight_legacy_claim_in_flight + a5f06_functionally_ineligible_functional_record_absent
REACHABILITY=impossible
PROOF_RULE=P01(I01,I02)
```

La fila única es `pair_position=2`. F0 permanece presente y sin claim.

## 12. Certificación machine-checkable

```text
PAIR_POSITIONS=1..300
PAIR_ROWS=300
DUPLICATE_PAIRS=0
MISSING_PAIRS=0
UNKNOWN_FAMILY_REFS=0
SELF_PAIRS=24
REACHABLE_PLUS_IMPOSSIBLE=300
EVERY_REACHABLE_PAIR_HAS_WITNESS=PASS
EVERY_IMPOSSIBLE_PAIR_HAS_PROOF_RULE=PASS
NO_REACHABLE_PAIR_REQUIRES_FORBIDDEN_FAULT=PASS
NO_REACHABLE_PAIR_REQUIRES_THIRD_PARTY=PASS
NO_REACHABLE_PAIR_REQUIRES_FUNCTIONAL_DELETE=PASS
NO_REACHABLE_PAIR_VIOLATES_LOCK_SERIALIZATION=PASS
HISTORICAL_BLOCKED_PAIR_PRESENT=PASS
HISTORICAL_BLOCKED_PAIR_REACHABILITY=impossible
REACHABLE_PAIR_SET_CLOSED=PASS
READY_FOR_A8_CONCURRENT_SEMANTIC_CLASSIFICATION=PASS
UNRESOLVED=0
```

## 13. Downstream handoff

La autoridad downstream consume el conjunto exacto `reachable=[]`. No debe reabrir reachability ni ampliar el universo con las cuatro continuation families. Este documento no asigna aggregate status, reason code, effects, uncertainty, conflict ni clasificación semántica.

## 14. Veredicto

**A5 CON-01 AUTHORITY_CLOSED PAIR REACHABILITY CATALOG IMPLEMENTABLE**
