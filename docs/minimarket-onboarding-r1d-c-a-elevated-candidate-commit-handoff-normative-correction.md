# Corrección normativa R1D-C-A: handoff elevado del candidate commit

## 1. Decisión normativa

La única autoridad permitida para el handoff elevado R1D-C-A es:

`R1DCA_ELEVATED_CANDIDATE_COMMIT_HANDOFF_AUTHORITY_V1`

Su arquitectura única es:

`LOCAL_IMMUTABLE_CANDIDATE_COMMIT_THEN_EXACT_PUSH`

No se autoriza una identidad basada solamente en el worktree, un tree provisional
o hashes sin commit.

## 2. Contradicción corregida

Esta autoridad corrige exclusivamente la contradicción entre «no crear un commit
antes del receipt elevado» y «ligar el receipt al candidate commit y su tree».

La regla cerrada es:

- antes del receipt se permite exactamente un candidate commit local;
- antes del receipt no se permite push;
- el candidate es definitivo e inmutable desde su creación;
- después de verificar el receipt se publica exactamente ese mismo commit.

Estas reglas superseden sólo las cláusulas anteriores incompatibles. Las
autoridades de named locks, dual-ledger e identidad de invocación estática, junto
con todos los demás requisitos R1D-C-A, permanecen vigentes.

## 3. Lifecycle cerrado

El orden obligatorio es:

1. `baseline_verified`;
2. `implementation_completed`;
3. `non_privileged_validation_completed`;
4. `allowlist_final_verified`;
5. `production_integrity_verified`;
6. `staging_prepared_from_exact_allowlist`;
7. `candidate_commit_created`;
8. `candidate_identity_captured`;
9. `staging_empty_after_candidate`;
10. `tracked_worktree_clean_after_candidate`;
11. `elevated_script_materialized`;
12. `elevated_validation_started`;
13. `elevated_candidate_identity_verified_before`;
14. `elevated_steps_completed`;
15. `elevated_candidate_identity_verified_after`;
16. `elevated_receipt_finalized`;
17. `receipt_verified_non_privileged`;
18. `candidate_unchanged_confirmed`;
19. `exact_candidate_pushed`;
20. `remote_identity_verified`;
21. `handoff_completed`.

Cada evento se registra inmediatamente después del hecho que representa. No se
reconstruye el lifecycle al final ni se anticipan estados.

## 4. Creación del candidate

Se autoriza un único commit local antes de la validación elevada sólo cuando:

- la implementación está completa;
- todas las validaciones no privilegiadas están verdes;
- el delta contiene exactamente las cuatro rutas autorizadas;
- producción y autoridades normativas permanecen intactas;
- staging contiene exactamente las cuatro rutas;
- bundles, patches, scripts, receipts y logs permanecen untracked;
- el subject definitivo ya está fijado.

El subject exacto es:

`test(minimarket): implement R1DCA static causal harness`

Desde `candidate_commit_created` quedan prohibidos amend, rebase, cherry-pick,
squash, commit adicional, cambio de autor, fecha o mensaje, modificación del
index, modificación o regeneración tracked, aplicación de patches, formatters que
escriban, restauración, sustitución por otro SHA y push anterior al receipt.

La identidad debe cumplir estrictamente:

```text
candidate_commit_before_validation
== candidate_commit_after_validation
== pushed_commit
== origin/main_after_push
```

## 5. Identidad completa del candidate

El receipt autentica como datos independientes:

- `candidate_commit`;
- `candidate_parent`;
- `candidate_tree`;
- `candidate_subject`;
- branch y HEAD;
- origin/main y divergencia previos;
- `ordered_set_paths` y `ordered_set_hash`;
- path, SHA-256, Git mode y Git blob de cada harness;
- diffstat y numstat contra el parent;
- canonical diff hash;
- staging vacío y tracked worktree limpio;
- hashes de producción protegidos;
- hashes de las autoridades normativas;
- identidad before e identidad after.

## 6. Ordered-set hash

El ordered set contiene exactamente las cuatro rutas autorizadas, ordenadas por
comparación bytewise ascendente de sus paths UTF-8. Para cada ruta se construye:

```text
record = UTF8(path)
       || 0x00
       || ASCII(git_mode)
       || 0x00
       || ASCII(lowercase_git_blob_hex)
       || 0x00
       || ASCII(uppercase_sha256_hex)
```

Los cuatro records se concatenan con un único byte `0x0A` entre records y sin
`0x0A` final. No hay BOM, normalización de slash, whitespace adicional ni
conversión de finales de línea.

```text
ordered_set_hash = UPPERCASE_HEX(SHA256(concatenated_records))
```

El verificador reconstruye rutas, modos, blobs y SHA-256 desde el candidate y
rechaza cualquier diferencia antes de confiar en el hash declarado.

## 7. Canonical diff hash

El stream canónico se obtiene con los cuatro paths en el mismo orden bytewise:

```text
git -c core.autocrlf=false -c core.safecrlf=false \
  diff --binary --full-index --no-color --no-ext-diff --no-renames \
  <candidate_parent> <candidate_commit> -- <ordered_paths>
```

No se ignora ni normaliza whitespace. El hash cubre exactamente los bytes stdout
emitidos por Git, sin transcodificación ni conversión EOL:

```text
canonical_diff_hash = UPPERCASE_HEX(SHA256(git_stdout_bytes))
```

Stderr queda fuera del material. En particular, warnings de CRLF no se mezclan
con stdout. El descriptor conserva comando, opciones, paths ordenados, ausencia
de normalización y algoritmo SHA-256.

## 8. Script elevado

El script se ejecuta desde PowerShell administrador con `ExecutionPolicy Bypass`
y exige `IS_ADMIN=True`. Cambia al repositorio exacto y, antes de las suites,
verifica HEAD, parent, tree, subject, ordered set, blobs, modos, hashes, diff,
staging vacío y tracked worktree limpio.

El script no ejecuta `git add`, no crea commits, no hace push, no modifica index
ni archivos tracked y no regenera el candidate. Ejecuta symlink real y las demás
suites elevadas, vuelve a verificar toda la identidad y falla ante cualquier
diferencia.

El receipt se escribe primero en un archivo temporal, se flush/sincroniza, y se
publica mediante rename atómico. Permanece fuera del commit. Repo, temporales,
USERPROFILE, base, host, credenciales, connection IDs y secretos se sanitizan. El
resultado exige residuo cero.

## 9. Receipt elevado

El receipt es append-only durante la ejecución y sólo se publica completo. Su
schema cerrado contiene:

- `schema_version` y `authority_version`;
- receipt ID criptográfico;
- identidad completa del candidate;
- timestamps UTC de inicio y término;
- steps únicos y ordenados;
- command descriptors, stdout y stderr sanitizados;
- exit code por step;
- resultados causales separados de symlink y junction;
- named-lock residual y cleanup;
- identidad before y after;
- marcas terminales.

Las marcas terminales exactas son:

```text
ELEVATED_VALIDATION_COMPLETE=True
CANDIDATE_IDENTITY_EXACT_MATCH=True
NO_ADDITIONAL_COMMIT_PERFORMED=True
NO_PUSH_PERFORMED=True
FINAL_STAGING_EMPTY=True
FINAL_TRACKED_WORKTREE_CLEAN=True
FINAL_ZERO_RESIDUE=True
```

El receipt no afirma publicación: el push ocurre después en el entorno no
privilegiado.

## 10. Verificación no privilegiada

Antes del push, el verificador recalcula y exige:

- receipt completo y schema cerrado;
- catálogo y orden exactos de steps, sin duplicados;
- todos los exit codes iguales a cero;
- identidad before igual a after;
- commit, tree, blobs, modos, hashes, ordered set y diff iguales al estado actual;
- staging vacío y tracked worktree limpio;
- producción intacta y marcas no contradictorias;
- symlink real y junction separada aprobados;
- named-lock residual y cleanup en cero.

Si falla cualquier guard, no se permite push, amend ni commit adicional. Se
preservan candidate y receipt y se publica el bloqueo exacto.

## 11. Push exacto

Sólo tras verificar el receipt se autoriza:

`git push origin main`

Antes del push, HEAD sigue siendo el candidate, no existen commits posteriores,
staging y tracked worktree están limpios, y `candidate_parent` coincide con
origin/main. El push debe ser fast-forward.

Después del push se exige HEAD igual a origin/main, divergencia `0/0`, tree remoto
igual a `candidate_tree`, staging vacío y tracked worktree limpio.

Se prohíben force, force-with-lease, amend, rebase, merge intermedio, segundo
commit, reconstrucción con el mismo tree y publicación de cualquier otro SHA.

## 12. Mutaciones y reasons cerrados

Cada mutación parte de evidencia nominal, recalcula dependencias y debe fallar
primero con su reason exclusivo:

| Mutación | First-failure reason |
|---|---|
| candidate commit omitido | `elevated_candidate_commit_missing` |
| candidate commit cambiado | `elevated_candidate_commit_changed` |
| parent cambiado | `elevated_candidate_parent_changed` |
| tree cambiado | `elevated_candidate_tree_changed` |
| subject cambiado | `elevated_candidate_subject_changed` |
| ordered set truncado | `elevated_candidate_ordered_set_truncated` |
| ordered set reordenado | `elevated_candidate_ordered_set_order` |
| ordered-set hash cambiado | `elevated_candidate_ordered_set_hash` |
| hash de archivo cambiado | `elevated_candidate_file_hash` |
| blob cambiado | `elevated_candidate_blob` |
| modo cambiado | `elevated_candidate_mode` |
| diff hash cambiado | `elevated_candidate_diff_hash` |
| identity before distinta | `elevated_candidate_identity_before` |
| identity after distinta | `elevated_candidate_identity_after` |
| lifecycle reordenado | `elevated_handoff_lifecycle_order` |
| receipt incompleto | `elevated_handoff_receipt_incomplete` |
| step omitido | `elevated_handoff_step_missing` |
| step duplicado | `elevated_handoff_step_duplicate` |
| exit code no cero | `elevated_handoff_step_exit_code` |
| staging no vacío | `elevated_handoff_staging_not_empty` |
| tracked worktree sucio | `elevated_handoff_worktree_dirty` |
| commit adicional | `elevated_candidate_additional_commit` |
| amend posterior | `elevated_candidate_amended` |
| push anterior al receipt | `elevated_handoff_push_before_receipt` |
| pushed commit distinto | `elevated_handoff_pushed_commit` |
| remote tree distinto | `elevated_handoff_remote_tree` |
| divergencia final no 0/0 | `elevated_handoff_final_divergence` |

El catálogo contiene 27 identifiers únicos. El parent recalcula todas las
comparaciones; ninguna conclusión booleana declarada es autoridad primaria.

## 13. Inmutabilidad final

El candidate validado es el único publicable. Cualquier cambio posterior, aunque
produzca archivos o tree equivalentes, invalida el handoff. No existe una ruta de
reparación automática: un reinicio requiere autorización expresa y una nueva
identidad completa.
