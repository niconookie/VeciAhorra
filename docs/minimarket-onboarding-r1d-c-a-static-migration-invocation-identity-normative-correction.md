# Corrección normativa R1D-C-A: identidad causal de invocación estática

## 1. Decisión normativa

La única arquitectura autorizada para identificar causalmente la invocación
productiva de migración R1D-C-A es:

`R1DCA_STATIC_MIGRATION_INVOCATION_IDENTITY_AUTHORITY_V1`

Esta autoridad sustituye exclusivamente la identidad de objeto
`MigrationManager`, sus tokens de objeto, `WeakMap<MigrationManager,...>` y todo
executor que exija `$manager->migrate()`.

## 2. Hechos productivos

La ruta real e inmutable es:

```text
veciahorra.php
→ Installer::install()
→ MigrationManager::migrate()
```

`veciahorra.php` registra `Installer::install()`. `Installer::install()` invoca
`MigrationManager::migrate()` estáticamente. `MigrationManager` es final,
`migrate()` es estático y la clase no posee constructor productivo, factory,
instancia, parámetro manager ni `$this`. La ruta productiva no contiene un objeto
`MigrationManager`.

Por tanto quedan prohibidos como autoridad de MigrationManager:

- object ID o `spl_object_id`;
- manager token o instance token de objeto;
- `WeakMap<MigrationManager,...>`;
- recuperación desde `$this`;
- unicidad de objetos manager;
- `$manager->migrate()`.

## 3. Entrypoint nominal

El único entrypoint nominal es la cadena productiva anterior. El harness invoca
realmente `Installer::install()`. No puede sustituirla por llamada directa a
`MigrationManager::migrate()`, llamada sobre objeto, Reflection, wrapper
presentado como Installer ni frames construidos manualmente.

## 4. Identidad causal autorizada

La identidad corresponde a una invocación estática real y contiene exactamente
el siguiente material mínimo:

- `authority_version`;
- `execution_id`;
- `installer_invocation_id`;
- `migration_invocation_id`;
- `entrypoint_id`;
- `connection_token`;
- `prefix_fingerprint`;
- `invocation_registered_sequence`;
- `installer_call_started_sequence`;
- `migration_frame_observed_sequence`;
- `first_effect_sequence`;
- `terminal_sequence`;
- `invocation_state`;
- `canonical_invocation_material`;
- `invocation_fingerprint`.

No contiene identidad, token, `$this`, factory ni instancia de
`MigrationManager`.

## 5. R1dcaStaticMigrationInvocationExecutor

Una clase exclusiva del harness equivalente a
`R1dcaStaticMigrationInvocationExecutor` es la única superficie autorizada para
iniciar el entrypoint nominal. Su secuencia cerrada es:

1. crear `execution_id`;
2. crear `installer_invocation_id`;
3. crear `migration_invocation_id`;
4. registrar la invocación;
5. preparar `InstrumentedWpdb`;
6. guardar el objeto `$wpdb` anterior e instalar temporalmente el wrapper;
7. registrar `installer_call_started` inmediatamente antes del entrypoint;
8. invocar `Installer::install()` real;
9. recibir eventos SQL y stacks reales desde el wrapper;
10. registrar `COMPLETED` después del retorno o `FAILED` dentro del catch;
11. restaurar exactamente el objeto `$wpdb` anterior en `finally`;
12. cerrar y sellar la evidencia.

El executor no invoca directamente `MigrationManager`, no crea un objeto manager
y no es llamado desde producción. Es el control-plane del harness alrededor de
la llamada productiva real.

## 6. R1dcaStaticInvocationRegistry

`R1dcaStaticInvocationRegistry` registra antes de `Installer::install()`:

- execution, installer invocation y migration invocation IDs;
- entrypoint ID;
- actor;
- connection token;
- prefix fingerprint;
- contrato esperado de frames productivos;
- lifecycle state.

El registry es por ejecución, no se comparte globalmente y falla ante ID
duplicado, execution/connection/actor cruzado, registro tardío, rebind, segunda
terminal, invocación desconocida, evento posterior al cierre o concurrencia no
autorizada.

Estados cerrados:

1. `REGISTERED`;
2. `INSTALLER_STARTED`;
3. `MIGRATION_FRAME_OBSERVED`;
4. `EFFECTS_OBSERVED`;
5. `FAILED` o `COMPLETED`;
6. `CLOSED`.

`migration_invocation_id` se asigna antes del entrypoint, pero sólo se activa al
capturar el primer evento real cuyo stack contiene `MigrationManager::migrate`.
No se declara `COMPLETED` antes del retorno ni `FAILED` fuera del catch.

## 7. Contexto de InstrumentedWpdb

Antes del primer query, cada conexión recibe un contexto inmutable con:

- `execution_id`;
- `installer_invocation_id`;
- `migration_invocation_id`;
- `entrypoint_id`;
- actor;
- connection token;
- prefix fingerprint.

El wrapper impide reemplazar el contexto, consultar sin contexto, consultar tras
el cierre y enrutar eventos a otra invocación. Cada evento SQL conserva el mismo
contexto y se incorpora al `ExecutionEvidenceLedger`. El handle nativo permanece
encapsulado.

## 8. Stack real

En cada efecto autorizado, `InstrumentedWpdb` captura
`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` y conserva únicamente pares
sanitizados `class`/`function`.

La ruta nominal demuestra el orden real de:

1. `Installer::install`;
2. `MigrationManager::migrate`;
3. migración productiva aplicable;
4. seam/query observado.

No se transportan argumentos, paths, líneas, objetos ni traces completos. El
parent deriva cardinalidades, orden, first observed sequence y activation real.
Un frame declarado, fabricado o reordenado no constituye autoridad.

## 9. Material canónico

`canonical_invocation_material` usa exactamente este orden de claves:

1. `authority_version`;
2. `execution_id`;
3. `installer_invocation_id`;
4. `migration_invocation_id`;
5. `entrypoint_id`;
6. `actor`;
7. `connection_token`;
8. `prefix_fingerprint`;
9. `invocation_registered_sequence`;
10. `installer_call_started_sequence`;
11. `migration_frame_observed_sequence`;
12. `first_effect_sequence`;
13. `terminal_sequence`;
14. `invocation_state`.

Se serializa como JSON UTF-8, sin BOM, whitespace ni LF final, con strings
normalizados y secuencias enteras. El fingerprint es:

`UPPERCASE_HEX(SHA256(canonical_invocation_material))`

El parent reconstruye material y fingerprint desde los eventos existentes.

## 10. Causalidad no retrospectiva

Los IDs nacen antes del entrypoint; el registry y contexto quedan fijados antes
del primer query; el frame real activa la identidad; los efectos posteriores
usan los mismos IDs; la terminal ocurre después; y el material canónico sólo
proyecta eventos ya observados. Queda prohibido agregar IDs después de ejecutar.

## 11. A, B y C

A, B y C invocan realmente `Installer::install()` mediante el executor. Cada una
usa conexión, contexto, IDs, token y prefix fingerprint distintos, snapshots y
efectos reales, terminal real y restauración exacta de `$wpdb`. A falla dentro
del catch real; B y C completan después del retorno. No se permite contaminación
entre invocaciones.

## 12. Ruta G11

G11 es `CONTROLLED_MANAGER_OMISSION`: ejecuta directamente las dos migraciones
productivas, omite deliberadamente Installer y MigrationManager, usa
`InstrumentedWpdb`, conserva execution ID y efectos reales, y se rechaza por
`migration_manager_frame_missing`.

G11 nunca puede declararse `NOMINAL_STATIC_INVOCATION`. El parent distingue ambos
tipos de ruta materialmente.

## 13. Global `$wpdb`

El executor guarda la identidad exacta del objeto anterior, instala el wrapper
antes del entrypoint, prueba que Installer y MigrationManager consumen ese mismo
wrapper, impide su sustitución durante la ejecución y lo restaura en `finally`,
incluso ante `Throwable`.

Falla ante wrapper ausente o sustituido, restauración ausente o hacia otro objeto
y contaminación A/B/C. La sustitución es temporal y no modifica producción.

## 14. Concurrencia y ownership

La ejecución nominal es secuencial: existe exactamente una invocación activa por
`InstrumentedWpdb`; el contexto es inmutable; no existe registry global
compartido; A/B/C usan wrappers distintos.

Si un harness futuro habilita concurrencia, cada conexión conserva ownership por
execution ID y connection token. Se prohíbe un `current invocation` estático
mutable sin ownership.

## 15. Manifest y ledgers

El manifest sustituye bindings de manager/instance object por
`static_invocation_bindings`. Cada binding transporta material causal, eventos,
stack summary, contexto de conexión, lifecycle, fingerprint y terminal.

El parent recalcula identidad, activación por frame, continuidad de IDs, efectos,
terminal, entrypoint e aislamiento. `ExecutionEvidenceLedger` contiene los
eventos reales de invocación estática y rechaza object binding, object ID, token
retrospectivo, frame fabricado, activación sin frame, efecto sin invocación activa
y terminal anticipada. `TransportEvidenceLedger` no cambia.

## 16. Mutaciones obligatorias

Cada mutación parte de evidencia nominal, recalcula dependencias, hashes,
canonicalización y HMAC, y alcanza su first-failure reason específico:

- invocation ID omitido, duplicado o generado tras installer start;
- installer o migration invocation ID cruzado;
- execution ID, connection token o prefix fingerprint cruzado;
- entrypoint ID incorrecto;
- wrapper sin contexto;
- query antes del registro o con invocación desconocida;
- frame Installer o MigrationManager ausente;
- frame MigrationManager fabricado;
- frames reordenados;
- efecto antes del migration frame;
- estado activado sin frame;
- completed antes del retorno;
- failed sin catch;
- completed/failed simultáneos;
- terminal ausente;
- `$wpdb` no restaurado o restaurado a otro objeto;
- contexto A reutilizado por B;
- G11 declarado nominal;
- object manager binding, `spl_object_id` o manager WeakMap inesperados.

## 17. Reasons cerrados

El catálogo mínimo exacto es:

- `static_invocation_missing`;
- `static_invocation_duplicate`;
- `static_invocation_registered_late`;
- `static_invocation_execution_crossed`;
- `static_invocation_connection_crossed`;
- `static_invocation_entrypoint`;
- `static_invocation_context_missing`;
- `static_invocation_unknown`;
- `static_invocation_installer_frame`;
- `static_invocation_migration_frame`;
- `static_invocation_fabricated_frame`;
- `static_invocation_frame_order`;
- `static_invocation_effect_before_frame`;
- `static_invocation_state`;
- `static_invocation_terminal_order`;
- `static_invocation_terminal_conflict`;
- `static_invocation_terminal_missing`;
- `static_invocation_wpdb_restore`;
- `static_invocation_context_reuse`;
- `static_invocation_omission_claimed_nominal`;
- `static_invocation_object_identity_forbidden`.

## 18. Supersesión y límites

Esta autoridad supersede toda instrucción previa que exija para
`MigrationManager`: objeto, `WeakMap`, manager token de objeto, instance token de
objeto, `$manager->migrate()` o executor invocado desde producción.

No supersede registries de otros objetos reales. Tampoco modifica la autoridad
dual-ledger, snapshots, named locks acotados, VERIFIER, tipado numérico nativo,
pipeline, privacidad ni receipt elevado.

La arquitectura se implementa íntegramente dentro de los cuatro harnesses, sin
modificar producción, sin objeto manager, sin frame sintetizado y preservando
Installer y MigrationManager reales.
