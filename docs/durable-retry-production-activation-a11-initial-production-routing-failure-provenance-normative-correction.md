# A11 — corrección normativa de provenance del resultado productivo inicial

## 1. Veredicto y scope

Esta autoridad append-only cierra únicamente la información productiva que debe existir antes del codec A11. No define codec completo, `operation_result`, projector, agregado CON-01, replay, position 2 ni catálogo de 62.

Veredicto: `A11 INITIAL PRODUCTION ROUTING FAILURE PROVENANCE IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`.

## 2. Precedencia

Se conserva íntegro el transporte `veciahorra-a11-action-transport/v2`. Esta corrección complementa A8 en la semántica de efectos y supersede parcialmente solo el shape y factories de `DurableRetryInitialProductionRoutingResult`. A5, A6, A7, A9, A10, proposals, Coordinator y topología A11 conservan sus responsabilidades.

## 3. Arquitectura única

`ARCHITECTURE=A — DurableRetryInitialProductionRoutingResult enriquecido`.

El DTO productivo incorpora provenance, posibilidad de inicio de efecto, identidad tipada y certeza. Es el cambio mínimo porque el router ya es el único punto que conoce la rama y el decorator A11 ya observa su retorno.

Se rechaza el record composite porque duplicaría el resultado y rompería innecesariamente la firma del router. Se rechaza instrumentar todas las dependencias porque cuatro fronteras ya son distinguibles por el control flow del router; donde un provider opaco no confirma el efecto, el DTO conserva incertidumbre y una identidad lógica, sin inferencia.

## 4. Punto actual de pérdida

Las cuatro pérdidas ocurren en `DurableRetryInitialProductionRouter::routeReconciliation()` al ejecutar `DurableRetryInitialProductionRoutingResult::dependencyFailure($reconciliationId)` después de los catches de authority production, legacy scheduler, schedule resolution y schedule coordination. La llamada entrega solo el ID. Descarta qué dependencia falló, el punto de ejecución, la identidad lógica disponible y si el efecto quedó confirmado o indeterminado.

## 5. Catálogo de origins

`DEPENDENCY_FAILURE_ORIGINS_TOTAL=4`

| Valor | Componente y función | Operación en curso | Excepción | Efecto posible | Identidad disponible | Certeza |
|---|---|---|---|---|---|---|
| `authority_production` | initial authority producer, `produceReconciliation` | clasificar o producir autoridad inicial | `Throwable` escapado | ningún scheduling iniciado por el router | reconciliation | definitive failure |
| `legacy_scheduler` | legacy scheduler, `scheduleReconciliation` | consultar o crear action legacy | `Throwable` | enqueue pudo comenzar sin retornar ID | legacy logical action | uncertain |
| `schedule_resolution` | initial schedule resolver, `resolve` | leer y validar generation 1 durable | `Throwable` escapado | autoridad durable ya existe; resolver no crea action | durable logical schedule | definitive failure |
| `schedule_coordination` | initial schedule coordinator, `coordinate` | coordinar action externa y asociación | `Throwable` escapado | schedule o asociación pudo comenzar | durable schedule | uncertain |

Cada valor lo asigna literalmente el catch correspondiente. Solo puede aparecer con `state=dependency_failure` y `reason=dependency_failure`. Ningún caller, decorator o codec puede asignarlo.

## 6. Semántica de `effects_started`

`effects_started` es booleano y significa que, al emitir el outcome, existe autoridad para afirmar que al menos un efecto material de la operación ya existía o pudo comenzar. No afirma persistencia confirmada ni éxito.

- authority production dependency failure: false; el router no alcanzó una rama de scheduling.
- legacy scheduler dependency failure: true; el provider pudo fallar durante o después del enqueue.
- schedule resolution dependency failure: true; la rama solo se alcanza tras autoridad durable confirmada, aunque la resolución sea read-only.
- schedule coordination dependency failure: true; coordinación externa pudo comenzar.

El booleano es suficiente porque la confirmación se expresa separadamente mediante `outcome_certainty` y el kind de identity.

## 7. Certeza

`outcome_certainty` es `definitive|uncertain`.

`definitive` significa que el outcome y la presencia o ausencia declarada de efectos son concluyentes. `uncertain` significa que una llamada con capacidad de efecto comenzó y su terminación material no quedó confirmada. Rollback confirmado usa definitive y efecto no materializado. No se deriva de `requiresIntervention`.

Legacy scheduler y schedule coordination dependency failures son uncertain. Authority production y schedule resolution dependency failures son definitive. `durable_coordination_uncertain` es siempre uncertain. Los demás estados son definitive.

## 8. Unión tipada `effect_identity`

`effect_identity` es `null` o exactamente uno de estos records readonly:

```text
reconciliation/v1:
  reconciliation_id: positive_int

legacy_reconciliation_action/v1:
  reconciliation_id: positive_int
  hook: veciahorra_process_payment_reconciliation
  group: veciahorra-completion
  authority_id: positive_int equal to reconciliation_id
  scheduled_action_id: positive_int|null

durable_reconciliation_schedule/v1:
  reconciliation_id: positive_int
  schedule_id: positive_int|null
  generation: positive_int fixed to 1
  scheduled_action_id: positive_int|null
```

A `null` identity es legítima únicamente para `invalid_input`, cuyo reconciliation ID es 0. Una excepción legacy antes de retorno usa la identidad lógica completa con scheduled action ID nulo; el null representa falta autoritativa de ID, no ausencia de identidad. En resolution failure sin schedule ID retornado, el record durable conserva reconciliation y generation 1 con schedule ID nulo.

## 9. Relación con `requiresIntervention`

`requiresIntervention` conserva su semántica de recuperación humana u operacional. Puede ser true con certeza definitive, como resolution failure o dependency failure de authority production. Uncertain exige requires intervention true en `durable_coordination_uncertain` y en los dos dependency failures inciertos. Definitive no implica false y intervention true no implica uncertainty.

## 10. Cobertura de los once estados

| Estado | Provenance | effects started | Identity | Certainty |
|---|---|---:|---|---|
| `legacy_scheduled` | `legacy_scheduler` | true | legacy action, action ID nullable por contrato booleano | definitive |
| `legacy_unavailable` | `legacy_scheduler` | false | legacy logical action | definitive |
| `durable_synchronized` | `schedule_coordination` | true | durable schedule con tres IDs positivos | definitive |
| `durable_already_synchronized` | `schedule_coordination` | true | durable schedule con tres IDs positivos | definitive |
| `durable_external_unavailable` | `schedule_coordination` | true | durable schedule; action ID nullable | definitive |
| `durable_coordination_failed` | `schedule_coordination` | true | durable schedule; action ID nullable | definitive |
| `durable_coordination_uncertain` | `schedule_coordination` | true | durable schedule; action ID nullable | uncertain |
| `authority_closed` | `authority_production` | false para cierre sin durable materializado; true para transfer outcome con efecto posible | reconciliation o durable schedule logical | definitive o uncertain según el authority result preservado |
| `resolution_failed` | `schedule_resolution` | true | durable schedule logical; schedule ID nullable | definitive |
| `invalid_input` | `input_validation` | false | null | definitive |
| `dependency_failure` | uno de cuatro failure origins | tabla §5 | tabla §5 | tabla §5 |

El campo general se denomina `outcome_origin`, no `failure_origin`, para que los once estados posean semántica cerrada. Su catálogo es `input_validation|authority_production|legacy_scheduler|schedule_resolution|schedule_coordination`. El getter `failureOrigin()` retorna el origin solo para dependency failure y null en los otros estados.

## 11. Catorce familias machine-checkable

Existen diez familias para estados distintos de dependency failure y cuatro para dependency failure por origin. `VALID_ENRICHED_OUTCOME_COMBINATIONS=14` mide familias estructurales. Dentro de cada familia, reason conserva el catálogo cerrado de su autoridad A5, A6 o A7; no se admite un reason ajeno al factory fuente.

| Familia | State | Reason source | Origin | Identity | Certainty | Intervention |
|---:|---|---|---|---|---|---|
| 1 | legacy scheduled | activation policy rejected | legacy scheduler | legacy | definitive | false |
| 2 | legacy unavailable | activation policy rejected | legacy scheduler | legacy | definitive | false |
| 3 | durable synchronized | A7 success code | schedule coordination | durable | definitive | false |
| 4 | durable already synchronized | already synchronized | schedule coordination | durable | definitive | false |
| 5 | durable external unavailable | external unavailable | schedule coordination | durable | definitive | false |
| 6 | durable coordination failed | A7 non-success definitive code | schedule coordination | durable | definitive | false |
| 7 | durable coordination uncertain | A7 uncertain code | schedule coordination | durable | uncertain | true |
| 8 | authority closed | exact A5 reason | authority production | reconciliation or durable logical | copied from authority evidence | copied recovery |
| 9 | resolution failed | exact A6 failure reason | schedule resolution | durable logical | definitive | true |
| 10 | invalid input | invalid input | input validation | null | definitive | false |
| 11 | dependency failure | dependency failure | authority production | reconciliation | definitive | true |
| 12 | dependency failure | dependency failure | legacy scheduler | legacy | uncertain | true |
| 13 | dependency failure | dependency failure | schedule resolution | durable logical | definitive | true |
| 14 | dependency failure | dependency failure | schedule coordination | durable | uncertain | true |

## 12. Authority production

La implementación productiva A5 normaliza fallos de classify, activation y transfer a `DurableRetryInitialAuthorityProductionResult`; esos retornos llegan como authority closed y deben conservar state, reason, transfer generation identity y recovery. El dependency failure origin authority production queda reservado a un Throwable que escape la frontera de interfaz. El router aún no inició legacy, resolution ni coordination; por eso effects started es false y la identidad mínima es reconciliation.

## 13. Legacy scheduler

El puerto booleano llama `as_has_scheduled_action` y después `as_schedule_single_action`. Un throw no informa si Action Scheduler persistió antes de fallar ni entrega action ID. La representación correcta es effects started true, certainty uncertain e identidad lógica legacy con action ID null. Ésta conserva la verdad disponible sin afirmar commit.

## 14. Schedule resolution

A6 es read-only y normalmente normaliza errores de repositorio a resolution failed. Un Throwable escapado no crea action. La rama, sin embargo, ya posee autoridad durable confirmada. Conserva reconciliation, generation 1 y, cuando A5 transfer evidence lo aporta, schedule ID. Certeza del failure es definitive; no existe nueva incertidumbre de scheduling causada por A6.

## 15. Schedule coordination

A7 recibe schedule ID y generation positivos. Su implementación normaliza fallos externos esperados a coordination failed o uncertain. Un Throwable escapado cruza una frontera que pudo ejecutar schedule externo, compensación o asociación; usa effects started true, certainty uncertain, durable identity con schedule ID y generation, y action ID null si no fue confirmado.

`durable_coordination_failed` es retorno normal definitivo. `durable_coordination_uncertain` es retorno normal incierto con metadata A7. Dependency failure de coordination es excepción inesperada incierta; los tres siguen siendo outcomes distintos.

## 16. Invariantes constructor-level

- State, reason y origin pertenecen a una de las catorce familias.
- `failure_origin` es no nulo exactamente para dependency failure y debe igualar outcome origin.
- Uncertain implica effects started true y requires intervention true.
- Effects started false prohíbe scheduled action ID.
- Legacy identity prohíbe schedule ID y generation durable.
- Durable identity exige reconciliation positivo y generation 1; schedule ID puede ser null solo antes de una resolución válida o durante su fallo.
- Scheduled action ID positivo exige effects started true.
- Invalid input exige reconciliation 0, identity null, definitive, no intervention y effects started false.
- Los IDs presentes son positivos; no se sintetizan IDs ausentes.

## 17. API futura exacta

En `VeciAhorra\Modules\Orders\Domain\DurableRetry`:

```php
final readonly class DurableRetryInitialProductionEffectIdentity
{
    private function __construct(string $type, int $reconciliationId, int|null $scheduleId, int|null $generation, int|null $scheduledActionId);
    public static function reconciliation(int $reconciliationId): self;
    public static function legacyAction(int $reconciliationId, int|null $scheduledActionId): self;
    public static function durableSchedule(int $reconciliationId, int|null $scheduleId, int $generation, int|null $scheduledActionId): self;
    public function type(): string;
    public function reconciliationId(): int;
    public function scheduleId(): int|null;
    public function generation(): int|null;
    public function scheduledActionId(): int|null;
    public function toArray(): array;
}
```

`DurableRetryInitialProductionRoutingResult` añade constructor privado properties `string $outcomeOrigin`, `bool $effectsStarted`, `DurableRetryInitialProductionEffectIdentity|null $effectIdentity`, `string $outcomeCertainty`, getters homónimos y `failureOrigin(): string|null`. Publica constantes para los cinco origins y dos certainty values.

`dependencyFailure` cambia a:

```php
public static function dependencyFailure(
    int $id,
    string $failureOrigin,
    bool $effectsStarted,
    DurableRetryInitialProductionEffectIdentity $effectIdentity,
    string $outcomeCertainty
): self;
```

Cada named constructor existente fija internamente los cuatro campos. `authorityClosed` extrae y conserva evidencia de `DurableRetryInitialAuthorityProductionResult`; `resolutionFailed` recibe además la identity durable lógica ya disponible. La firma pública de `routeReconciliation(int, DateTimeImmutable): DurableRetryInitialProductionRoutingResult` no cambia, ni tampoco la interfaz.

## 18. Observation seam y codec futuro

El decorator/store sigue observando exactamente un DTO retornado, read-once y cleanup. El codec futuro conserva, además de los ocho campos actuales: `outcome_origin`, `failure_origin`, `effects_started`, `effect_identity` y `outcome_certainty`. Los records tipados caben en `payload.values` del productive observation v1 y no requieren cambiar transport v2.

## 19. Compatibilidad

| Componente | Clasificación |
|---|---|
| production router | factory update required |
| router interface | unchanged |
| routing result | constructor and factory update required |
| Webpay materializer | unchanged |
| A11 decorator and store | type-compatible change |
| future A11 codec | consumer update required |
| A8 | partially superseded result shape |
| A9 and A10 | unchanged |
| structured evidence transport v2 | unchanged |
| participant-action-proposal | unchanged and independent |
| Coordinator | unchanged in this correction |

Tests que llamen dependency failure directamente deben proporcionar el origin y metadata exactos. Tests que solo inspeccionen accessors existentes permanecen compatibles.

## 20. Prohibición de inferencia retrospectiva

Ningún consumidor A11 puede reconstruir `failure_origin`, `effects_started`, `effect_identity` u `outcome_certainty` desde estado posterior de BD, proposals, PID, timing, participant index, logs o heurísticas. Los valores proceden exclusivamente del outcome productivo emitido en la frontera autorizada.

## 21. Flujo futuro

El router selecciona el origin en la rama o catch exacto, construye la identity con datos ya poseídos, fija certeza conforme al contrato de la dependencia y retorna el DTO enriquecido. El decorator preserva ese DTO. El codec posterior realiza una conversión literal tipada. Ninguna capa vuelve a decidir provenance.

## 22. Cierre

`ROUTING_STATES=11`

`DEPENDENCY_FAILURE_BRANCHES=4`

`CLASSIFIED_BRANCHES=4`

`VALID_ENRICHED_OUTCOME_COMBINATIONS=14`

`UNRESOLVED=0`

La frontera productiva distingue los cuatro failures antes de serialización, conserva identidad incluso cuando el provider no confirmó un ID y representa explícitamente certeza. El projector y CON-01 permanecen fuera de alcance.

## 23. Matriz de validación normativa

`ALL_DEPENDENCY_FAILURE_BRANCHES_CLASSIFIED=PASS`

`ALL_STATES_ENRICHED=PASS`

`EFFECTS_STARTED_COMPLETE=PASS`

`EFFECT_IDENTITY_COMPLETE=PASS`

`OUTCOME_CERTAINTY_COMPLETE=PASS`

`FAILURE_ORIGIN_COMPLETE=PASS`

`NO_POST_HOC_INFERENCE=PASS`

`TRANSPORT_V2_COMPATIBLE=PASS`
