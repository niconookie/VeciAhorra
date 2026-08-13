# A5 — corrección normativa de provenance de operational failure

## 1. Veredicto y alcance

Esta autoridad append-only preserva provenance dentro de `DurableRetryInitialAuthorityProducer::produceReconciliation()`. Mantiene decisiones, states y reasons productivos. No modifica A8, transport, codec, projector ni CON-01.

Veredicto: `A5 OPERATIONAL FAILURE PROVENANCE IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`.

## 2. Precedencia

Complementa la autoridad A5 y el shape de `DurableRetryInitialAuthorityProductionResult`. La corrección A8 de failure provenance conserva sus cuatro router origins. Una corrección posterior de `authority_closed` consumirá estos campos. Structured evidence transport v2 permanece intacto.

## 3. Autoridad productiva reconstruida

```text
A5_COMPONENT=DurableRetryInitialAuthorityProducer
A5_INTERFACE=DurableRetryInitialAuthorityProducerInterface
A5_METHOD=produceReconciliation
A5_RESULT_TYPE=DurableRetryInitialAuthorityProductionResult
A5_STATES_TOTAL=12
A5_REASONS_TOTAL=24
A5_REACHABLE_COMBINATIONS=24
```

Los 12 states son `legacy_allowed`, `legacy_in_flight`, `durable_existing`, `durable_created`, `durable_converged`, `functionally_ineligible`, `authority_indeterminate`, `durable_inconsistency`, `configuration_invalid`, `persistence_error`, `outcome_uncertain`, `operational_failure`.

## 4. Seis branches operational failure

`OPERATIONAL_FAILURE_BRANCHES_TOTAL=6`

| Branch | Frontera | Condición | Authority result | Transfer invocado | Reason |
|---|---|---|---|---:|---|
| `OF01` | request validation | request A5 inválido | null | no | dependency failure |
| `OF02` | authority classification | classify lanza Throwable | null | no | dependency failure |
| `OF03` | activation source | source unavailable tipado | legacy | no | activation configuration source unavailable |
| `OF04` | activation policy | unsupported stage tipado | legacy | no | dependency failure |
| `OF05` | activation policy | Throwable restante | legacy | no | dependency failure |
| `OF06` | initial transfer | transfer o conversión de su result lanza Throwable | legacy | sí | dependency failure |

## 5. Origins exactos

`OPERATIONAL_FAILURE_ORIGINS_TOTAL=4`

```text
input_validation
authority_classification
activation_policy
initial_transfer
```

El producer asigna el origin en el branch exacto. `OF03`, `OF04` y `OF05` comparten `activation_policy`; sus reasons mantienen la distinción disponible. Solo state operational failure admite origin no nulo. No existe reconstrucción downstream.

## 6. Punto de colapso vigente

En `OF04`, `OF05` y `OF06`, A5 llama hoy `operationalFailure($authority, dependency_failure)`. Antes del colapso conoce el catch activo, si transfer fue invocado y la generation identity del request. Después conserva state, reason, authority legacy y transfer result null. Pierde origin, progreso, certainty y generation identity.

```text
INFORMATION_AVAILABLE_BEFORE_COLLAPSE=branch, transfer invocation boundary, request generation identity
INFORMATION_PRESERVED_AFTER_COLLAPSE=state, reason, authority result, null transfer result
INFORMATION_LOST=operational failure origin, effect progress, outcome certainty, generation identity
```

## 7. Arquitectura seleccionada

`ARCHITECTURE=A — enriquecer DurableRetryInitialAuthorityProductionResult`.

A5 conoce el origin y la entrada al transfer. El Throwable inesperado se representa conservadoramente como frontera posiblemente iniciada. Se rechaza modificar el transfer result porque los fallos esperados ya retornan outcomes tipados. Se rechaza una combinación de cambios porque el puerto no necesita un segundo DTO para expresar la falta de confirmación.

## 8. Effect progress

El catálogo exacto es:

```text
not_started
preexisting
confirmed
possibly_started
presence_uncertain
```

- `not_started`: A5 confirma que la transferencia no comenzó y no reconoce un efecto durable o legacy previo.
- `preexisting`: A5 confirma una autoridad o claim material anterior, no creado por esta llamada.
- `confirmed`: el transfer result confirma creación de generation 1 por esta llamada.
- `possibly_started`: una operación de transferencia fue invocada pero no existe resultado que confirme commit o rollback.
- `presence_uncertain`: A3 no pudo confirmar si una autoridad material previa existe; A5 no invocó transfer.

Este catálogo evita forzar un booleano y diferencia efecto anterior, nuevo confirmado y frontera incierta.

## 9. Outcome certainty

El catálogo contiene exactamente `definitive` y `uncertain`. Definitive significa que el effect progress declarado está confirmado por control flow o resultado tipado. Uncertain significa que la frontera no confirma si el efecto material quedó aplicado o ya existía. `possibly_started` y `presence_uncertain` exigen uncertain. Cada outcome uncertain exige recovery.

## 10. Generation y effect identity

La identity A5 es `DurableRetryGenerationIdentity|null`. El request válido construye generation identity antes de transfer, con stage reconciliation, subject igual a completion ID y generation 1. Por ello `OF06` conserva esa identity aun sin transfer result.

`effect_identity` usa esa misma identity de dominio. No se introduce un map ni un nuevo tipo. Null es normativo en branches anteriores a transfer y en outcomes sin identidad durable confirmada. No se inventa schedule ID ni scheduled action ID, que pertenecen a A6/A7.

## 11. Contrato real del transfer

```text
TRANSFER_COMPONENT=DurableRetryInitialTransferRepository
TRANSFER_INTERFACE=DurableRetryInitialTransferAuthorityInterface
TRANSFER_METHOD=transferReconciliation(DurableRetryInitialTransferRequest): DurableRetryInitialTransferResult
TRANSFER_RESULT_TYPE=DurableRetryInitialTransferResult
TRANSFER_EXCEPTION_TYPES=unexpected Throwable only
TRANSACTION_MODEL=START TRANSACTION, reads and insert, COMMIT, rollback and independent reconciliation on uncertain commit
```

La implementación puede ejecutar una inserción. La generation identity existe antes de la llamada. Fallos esperados de lectura, escritura, rollback y commit se normalizan como persistence error, outcome uncertain, transferred, already transferred o durable inconsistency. Tras commit incierto usa una conexión independiente como reconciliación autoritativa.

Un Throwable que escape el puerto no garantiza punto anterior a escritura, rollback ni commit. El caller solo conoce que entró a la función. La semántica exacta es `possibly_started`, `uncertain`, generation identity del request y recovery true. No se consulta BD después desde A5, A8 o A11.

## 12. Tuplas operational failure

| Branch | Origin | Progress | Certainty | Generation identity | Effect identity | Recovery |
|---|---|---|---|---|---|---:|
| OF01 | input validation | not started | definitive | null | null | true |
| OF02 | authority classification | not started | definitive | null | null | true |
| OF03 | activation policy | not started | definitive | null | null | true |
| OF04 | activation policy | not started | definitive | null | null | true |
| OF05 | activation policy | not started | definitive | null | null | true |
| OF06 | initial transfer | possibly started | uncertain | request generation 1 | request generation 1 | true |

## 13. State y reason

State continúa `operational_failure`. Reasons continúan `dependency_failure|activation_configuration_source_unavailable`. Provenance tipada elimina la ambigüedad, por lo que ampliar reasons duplicaría información y queda prohibido.

## 14. Catálogo enriched machine-checkable

Las abreviaturas de identity son `N` null y `G` request generation identity. Recovery se expresa `R0|R1`.

| Family | A5 state | Reason | Origin | Progress | Certainty | Identity | Recovery |
|---:|---|---|---|---|---|---|---|
| 01 | legacy allowed | activation policy rejected | null | not started | definitive | N | R0 |
| 02 | durable existing | durable authority already exists | null | preexisting | definitive | G | R0 |
| 03 | durable created | initial transfer created | null | confirmed | definitive | G | R0 |
| 04 | durable converged | equivalent transfer exists | null | preexisting | definitive | G | R0 |
| 05 | legacy in flight | legacy claim in flight | null | preexisting | definitive | N | R1 |
| 06 | functionally ineligible | functional record absent | null | not started | definitive | N | R0 |
| 07 | functionally ineligible | functional state ineligible | null | not started | definitive | N | R0 |
| 08 | authority indeterminate | query failed | null | presence uncertain | uncertain | N | R1 |
| 09 | authority indeterminate | incompatible durable state | null | preexisting | uncertain | N | R1 |
| 10 | authority indeterminate | persisted duplicate | null | preexisting | uncertain | N | R1 |
| 11 | authority indeterminate | corrupt identity | null | preexisting | uncertain | N | R1 |
| 12 | authority indeterminate | incomplete result | null | presence uncertain | uncertain | N | R1 |
| 13 | authority indeterminate | unresolved race | null | presence uncertain | uncertain | N | R1 |
| 14 | authority indeterminate | consistency error | null | presence uncertain | uncertain | N | R1 |
| 15 | durable inconsistency | existing transfer incompatible | null | preexisting | definitive | G | R1 |
| 16 | durable inconsistency | duplicate durable identity | null | preexisting | definitive | G | R1 |
| 17 | configuration invalid | invalid activation configuration value | null | not started | definitive | N | R0 |
| 18 | configuration invalid | invalid percentage | null | not started | definitive | N | R0 |
| 19 | configuration invalid | unsupported algorithm version | null | not started | definitive | N | R0 |
| 20 | configuration invalid | invalid configuration snapshot | null | not started | definitive | N | R0 |
| 21 | persistence error | persistence write failed | null | not started | definitive | N | R1 |
| 22 | outcome uncertain | persistence outcome uncertain | null | possibly started | uncertain | G | R1 |
| 23 | outcome uncertain | persistence outcome uncertain | null | possibly started | uncertain | N | R1 |
| 24 | operational failure | dependency failure | input validation | not started | definitive | N | R1 |
| 25 | operational failure | dependency failure | authority classification | not started | definitive | N | R1 |
| 26 | operational failure | activation configuration source unavailable | activation policy | not started | definitive | N | R1 |
| 27 | operational failure | dependency failure | activation policy | not started | definitive | N | R1 |
| 28 | operational failure | dependency failure | initial transfer | possibly started | uncertain | G | R1 |

`A5_ENRICHED_FAMILIES=28`. Families 22 y 23 separan la identity opcional realmente producida. Families 24 a 28 separan branches antes colapsados. Las 24 combinaciones state/reason se conservan.

## 15. Invariantes de constructor

- Operational failure exige origin no nulo; los demás states exigen origin null.
- Initial transfer origin exige dependency failure, possibly started, uncertain, G y R1.
- Input validation y authority classification exigen dependency failure, not started, definitive, N y R1.
- Activation policy exige not started, definitive, N y R1.
- Possibly started exige initial transfer, uncertain y R1.
- Presence uncertain exige authority indeterminate, identity N y R1.
- Confirmed exige durable created y G.
- Preexisting exige uno de durable existing, durable converged, legacy in flight, authority indeterminate con evidencia previa o durable inconsistency.
- G siempre representa stage reconciliation, subject positivo y generation 1.
- N está prohibido cuando el transfer result contiene generation identity.

## 16. API futura exacta

`DurableRetryInitialAuthorityProductionResult` añade constants para cuatro origins, cinco progress values y dos certainty values; properties `string|null $operationalFailureOrigin`, `string $effectProgress`, `string $outcomeCertainty`, `DurableRetryGenerationIdentity|null $generationIdentity`; y accessors homónimos.

Se reemplaza el factory genérico en branches operational por:

```php
public static function operationalFailureBeforeTransfer(
    DurableRetryLegacyAuthorityResult|null $authority,
    string $reason,
    string $origin
): self;

public static function operationalFailureDuringTransfer(
    DurableRetryLegacyAuthorityResult $authority,
    DurableRetryGenerationIdentity $generationIdentity
): self;
```

El primero acepta origins input validation, authority classification o activation policy y fija not started más definitive. El segundo fija initial transfer, dependency failure, possibly started, uncertain y recovery. Los factories no operational fijan internamente los campos según §14.

## 17. Pseudocódigo A5

```text
request inválido -> operationalFailureBeforeTransfer(null, dependency_failure, input_validation)
classify Throwable -> operationalFailureBeforeTransfer(null, dependency_failure, authority_classification)
activation source unavailable -> operationalFailureBeforeTransfer(authority, activation_configuration_source_unavailable, activation_policy)
unsupported stage -> operationalFailureBeforeTransfer(authority, dependency_failure, activation_policy)
activation Throwable -> operationalFailureBeforeTransfer(authority, dependency_failure, activation_policy)
transfer Throwable -> operationalFailureDuringTransfer(authority, request.generationIdentity)
```

Los returns tipados restantes conservan state/reason y reciben metadata de §14 mediante sus factories existentes.

## 18. Exception model

Expected domain y persistence failures siguen retornando `DurableRetryInitialTransferResult`. Unexpected Throwable sigue siendo capturado por A5 y se normaliza con provenance tipada. No se transportan class, message, trace ni provider data. La decisión productiva no cambia.

## 19. Prohibición de recuperación post-hoc

A8, A11 y otros consumidores no reconstruyen origin, progress, certainty o generation identity desde BD posterior, PID, timing, logs, proposals, retries, scheduler ni heurísticas. La metadata procede exclusivamente de A5 y del request poseído en la frontera de transfer.

## 20. Mapping disponible para A8

A8 recibirá state, reason, authority result, transfer result, operational failure origin, effect progress, certainty y generation identity. Es información suficiente para que otra corrección divida `authority_closed` sin volver a decidir A5. Este documento no publica ese mapping A8.

## 21. Compatibilidad

| Componente | Impacto |
|---|---|
| authority producer | factory update |
| authority producer interface | unchanged |
| authority production result | result-shape enrichment |
| transfer port and interface | unchanged |
| transfer repository | unchanged |
| A6 and A7 | unchanged |
| A8 router | consumer update in separate correction |
| A9 and A10 | unchanged |
| A11 transport v2 | unchanged |

## 22. Validación normativa

```text
A5_STATES_TOTAL=12
A5_REASONS_TOTAL=24
A5_REACHABLE_COMBINATIONS=24
OPERATIONAL_FAILURE_BRANCHES_TOTAL=6
OPERATIONAL_FAILURE_ORIGINS_TOTAL=4
ALL_OPERATIONAL_FAILURE_BRANCHES_CLASSIFIED=PASS
EFFECT_PROGRESS_COMPLETE=PASS
OUTCOME_CERTAINTY_COMPLETE=PASS
GENERATION_IDENTITY_COMPLETE=PASS
ALL_A5_STATES_NEW_FIELDS_CLOSED=PASS
NO_POST_HOC_INFERENCE=PASS
A8_CAN_CONSUME_WITHOUT_NEW_A5_DECISION=PASS
A5_ENRICHED_FAMILIES=28
A5_ENRICHED_FAMILIES_CLOSED=28
UNRESOLVED=0
```

## 23. Cierre

Dos histories antes byte-equivalent quedan separadas por activation policy versus initial transfer, not started versus possibly started, definitive versus uncertain y generation identity null versus generation 1. El puerto transfer conserva su contrato porque la incertidumbre conservadora es completa y productivamente verdadera.
