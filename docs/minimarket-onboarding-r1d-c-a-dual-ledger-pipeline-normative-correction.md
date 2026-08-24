# Corrección normativa R1D-C-A: pipeline dual-ledger sin recursión

## 1. Decisión normativa

La única arquitectura autorizada para el lifecycle de evidencia y transporte del
harness R1D-C-A es:

`R1DCA_DUAL_LEDGER_PIPELINE_AUTHORITY_V1`

La autoridad define exactamente dos ledgers:

1. `ExecutionEvidenceLedger`.
2. `TransportEvidenceLedger`.

No existe un tercer ledger. Los eventos no pueden trasladarse, duplicarse ni
mezclarse entre ambos.

## 2. Contradicción cerrada

El ledger de ejecución debe sellarse antes de construir el manifest y el builder
sólo puede consumir evidencia de ejecución sellada. La inclusión, firma,
colección y consumo ocurren después y no pueden agregarse a ese ledger. Incluir
`manifest_signed` en el payload firmado produciría autorreferencia; incluir
`manifest_consumed` sería temporalmente imposible. Reconstruir eventos después
del consumo sería evidencia retrospectiva.

Esta autoridad separa la ejecución sellada del transporte posterior y deja la
firma final del receipt fuera de ambos ledgers.

## 3. ExecutionEvidenceLedger

Contiene exclusivamente hechos ocurridos antes de construir el manifest. Su
catálogo mínimo es:

- `execution_known`;
- `connection_constructed`;
- `connection_registered`;
- `native_numeric_option_configured`;
- `connection_open_started`;
- `connection_opened`;
- `connection_identity_query_started`;
- `connection_identity_query_completed`;
- `connection_identity_observed`;
- `manager_constructed`;
- `manager_registered`;
- `snapshot_before_started`;
- `snapshot_before_captured`;
- `invocation_started`;
- `database_effect_observed`;
- `invocation_failed`;
- `invocation_completed`;
- `snapshot_after_started`;
- `snapshot_after_captured`;
- `connection_close_started`;
- `connection_closed`;
- `local_validation_started`;
- `locally_validated`;
- `execution_bundle_finalized`.

Puede incorporar otros eventos causales previos exigidos por autoridades R1D-C-A,
pero nunca eventos de transporte.

Es append-only, usa `sequence` monótono, `previous_event_hash`, `event_hash` y
una state machine cerrada. Prohíbe replace, reorder y terminales incompatibles.
`execution_bundle_finalized` es su último evento.

Después de `locally_validated` y `execution_bundle_finalized`, y antes de
`manifest_collection_started`, el ledger se sella externamente. El descriptor
de sellado contiene exactamente:

- `execution_ledger_version`;
- `execution_id`;
- `final_sequence`;
- `final_event_hash`;
- `canonical_execution_ledger_sha256`;
- `sealed_at_ordinal`;
- `sealed=true`.

No se agrega un evento `sealed`. Cualquier append posterior falla con
`execution_ledger_append_after_seal`.

## 4. Manifest de ejecución

El manifest incluye la proyección completa y sellada de
`ExecutionEvidenceLedger`, bindings, snapshots, autoridades de conexión,
capacidad numérica nativa, failure evidence, residue measurements y el
descriptor de sellado del execution ledger.

No puede incluir eventos futuros, placeholders, un transport ledger incompleto,
`execution_bundle_included`, `manifest_signed`, `wire_collected` ni
`manifest_consumed`.

## 5. TransportEvidenceLedger

Comienza únicamente después del sellado válido del execution ledger. Su catálogo
y orden exactos son:

1. `manifest_collection_started`;
2. `execution_bundle_collected`;
3. `manifest_inclusion_started`;
4. `execution_bundle_included`;
5. `manifest_materialized`;
6. `manifest_signing_started`;
7. `manifest_signed`;
8. `wire_delivery_started`;
9. `wire_collected`;
10. `wire_verification_started`;
11. `wire_hmac_verified`;
12. `semantic_validation_started`;
13. `semantic_validation_completed`;
14. `manifest_consumed`;
15. `transport_receipt_finalized`.

Cada evento ocurre en su punto real y transporta:

- `execution_id`;
- `transport_id`;
- `execution_ledger_version`;
- `execution_ledger_final_sequence`;
- `execution_ledger_final_event_hash`;
- `canonical_execution_ledger_sha256`;
- `manifest_schema_version`;
- `sequence`;
- `previous_event_hash`;
- `event_hash`;
- `phase`;
- payload cerrado específico del evento.

El primer evento se vincula al descriptor sellado. Se rechazan cruces de
execution ID, hash, secuencia o SHA-256, inicio prematuro y reutilización no
autorizada de un execution ledger.

`manifest_materialized` ocurre después de colección e inclusión reales y de
construir el manifest canónico. Transporta `canonical_manifest_sha256`, byte
length, execution final hash y schema version, pero no material sensible.

`wire_hmac_verified` ocurre después de recolectar el wire y verificar realmente
canonicalización y HMAC. `semantic_validation_completed` ocurre después de
validar schema, tipos, bindings, execution ledger, snapshots, failure,
conexiones, tipado nativo, named locks y residuos. `manifest_consumed` requiere
HMAC y semántica válidas y cero finding interno.

`transport_receipt_finalized` es el último evento. Entonces el ledger se sella
externamente con:

- `transport_ledger_version`;
- `transport_id`;
- `execution_id`;
- `final_sequence`;
- `final_event_hash`;
- `canonical_transport_ledger_sha256`;
- `sealed=true`.

No se permite append después del sellado.

## 6. Firma del manifest y separación de dominios

La HMAC se calcula sobre el manifest canónico que contiene únicamente evidencia
de ejecución sellada. El orden real es `manifest_signing_started`, cálculo HMAC,
construcción del wire y `manifest_signed`. Este último pertenece sólo al
transport ledger y no modifica el manifest firmado.

Los dominios criptográficos literales son distintos:

- `R1DCA_MANIFEST_HMAC_V1`;
- `R1DCA_TRANSPORT_RECEIPT_HMAC_V1`.

La derivación conceptual obligatoria es:

```text
manifest_key = HMAC(master_key,
  "R1DCA_MANIFEST_HMAC_V1\0" || execution_id)

transport_key = HMAC(master_key,
  "R1DCA_TRANSPORT_RECEIPT_HMAC_V1\0" || execution_id || "\0" || transport_id)
```

Las claves son diferentes, efímeras, no impresas ni persistidas, sin fallback y
se comparan constant-time.

## 7. Receipt externo

Después de sellar `TransportEvidenceLedger` se construye un receipt separado con:

- receipt version;
- execution ledger seal descriptor;
- canonical manifest SHA-256;
- wire fingerprint;
- transport ledger seal descriptor;
- resultado final derivado;
- `completed_at_utc`;
- transport receipt HMAC.

La HMAC del receipt se calcula después del sellado. No existe evento
`receipt_signed`: agregarlo al ledger sellado reabriría la recursión. El parent
demuestra la firma mediante receipt canónico, dominio separado, HMAC y comparación
constant-time.

## 8. Pipeline normativo de siete fases

El mapping único es:

| Fase | Ledger | Autoridad causal |
|---|---|---|
| `known` | Execution | `execution_known` |
| `executed` | Execution | `invocation_started` y terminal real |
| `locally_validated` | Execution | `locally_validated` |
| `collected` | Transport | `execution_bundle_collected` |
| `included_in_manifest` | Transport | `execution_bundle_included` |
| `signed` | Transport | `manifest_signed` |
| `consumed` | Transport | `manifest_consumed` |

Cada fase proviene de un evento real con sequence propio. No se reconstruye
retrospectivamente.

El orden global obligatorio es: known, ejecución, validación local, finalización
del bundle, seal de ejecución, inicio del transporte, colección, inclusión,
materialización, firma, entrega, colección del wire, verificación HMAC,
validación semántica, consumo, finalización del receipt event, seal de transporte,
construcción y firma del receipt externo y verificación del receipt.

## 9. Mutaciones obligatorias

Todas parten de evidencia nominal, recalculan dependencias no objetivo,
canonicalización y HMAC, y alcanzan el guard semántico exacto.

Execution ledger:

- append después del seal;
- seal antes de `locally_validated`;
- evento transport dentro del execution ledger;
- descriptor, final hash o final sequence alterados.

Transport ledger:

- inicio antes del execution seal;
- hash o execution ID cruzados;
- collected ausente;
- included antes de collected;
- signed antes de materialized o dentro del manifest;
- wire verification antes de collection;
- semantic validation antes de HMAC;
- consumed antes de semantic completion;
- receipt finalized antes de consumed;
- append después del transport seal.

Receipt:

- execution seal, transport seal, manifest SHA-256 o wire fingerprint distintos;
- dominio incorrecto;
- reutilización de la HMAC del manifest;
- firma antes del transport seal;
- HMAC inválida;
- resultado derivado contradictorio.

Pipeline:

- cada fase ausente o falsa;
- fase en ledger incorrecto;
- fase adelantada o reordenada.

## 10. Reasons cerrados

El catálogo mínimo es:

- `execution_ledger_not_sealed`;
- `execution_ledger_append_after_seal`;
- `execution_ledger_transport_event_forbidden`;
- `execution_ledger_seal_descriptor`;
- `transport_started_before_execution_seal`;
- `transport_execution_binding`;
- `transport_phase_order`;
- `manifest_materialization_order`;
- `manifest_signing_order`;
- `manifest_signed_inside_payload`;
- `wire_verification_order`;
- `semantic_validation_order`;
- `manifest_consumption_order`;
- `transport_receipt_finalize_order`;
- `transport_ledger_append_after_seal`;
- `receipt_execution_binding`;
- `receipt_transport_binding`;
- `receipt_manifest_binding`;
- `receipt_domain`;
- `receipt_hmac`;
- `receipt_result_contradiction`;
- `pipeline_event_wrong_ledger`.

## 11. Autoridad final del parent

El parent verifica, en orden: execution ledger seal, manifest HMAC, transport
ledger seal, transport receipt HMAC, vínculos entre los tres artefactos, orden
causal global, semántica y resultado derivado. Stdout nunca es autoridad.

Esta corrección no modifica las autoridades de named locks acotados, VERIFIER,
tipado numérico nativo, snapshots, registry, failure, privacidad ni candidate
elevado. Autoriza los dos ledgers únicamente dentro del harness y no autoriza
cambios productivos, observers, callbacks, APIs productivas ni modificaciones de
`MigrationManager`.
