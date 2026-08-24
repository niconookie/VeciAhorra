# Corrección normativa R1D-C-A: autoridad acotada de named locks

## 1. Decisión normativa

La única arquitectura autorizada para demostrar cleanup de named locks en el harness R1D-C-A es:

`R1DCA_SCOPED_CONTROLLED_CONNECTION_LOCK_AUTHORITY_V1`

Su salida canónica de alcance es:

`R1DCA_NAMED_LOCK_AUTHORITY_SCOPE=CONTROLLED_DISPOSABLE_CONNECTIONS_ONLY`

El contrato define literalmente tres conjuntos cerrados:

- `CONTROLLED_ACTORS={A,B,C,GUARD}`: conexiones que ejecutan MigrationManager o la prueba positiva de adquisición de GUARD.
- `OBSERVER_ACTORS={VERIFIER}`: única conexión read-only que mide ownership y libertad de los nombres observados.
- `AUTHENTICATED_CONNECTION_ACTORS={A,B,C,GUARD,VERIFIER}`: universo completo de conexiones autenticadas por registry, ledger y manifest.

La autoridad se limita exclusivamente a conexiones desechables creadas por el harness, entregadas mediante `InstrumentedWpdb`, vinculadas a `AUTHENTICATED_CONNECTION_ACTORS`, ejecutadas sobre la base desechable autorizada y abiertas y cerradas dentro del mismo lifecycle del harness. Sólo `CONTROLLED_ACTORS` puede ejecutar migraciones u operaciones de adquisición/liberación; `VERIFIER` sólo puede observar conforme a su catálogo read-only cerrado.

Esta autoridad no afirma ni certifica:

- ausencia global de named locks en MariaDB;
- locks de conexiones ajenas al harness;
- locks de WordPress productivo;
- locks de otros procesos;
- locks creados fuera del harness;
- estado global del servidor.

## 2. Justificación y capacidad del entorno

El entorno validado usa MariaDB `10.4.32`. En él no están disponibles `RELEASE_ALL_LOCKS()`, `performance_schema.metadata_locks` ni `information_schema.METADATA_LOCK_INFO`, y no existe una autoridad equivalente para enumerar todos los user locks de una conexión.

Instalar o habilitar `METADATA_LOCK_INFO`, plugins del servidor o nueva configuración modificaría el entorno y queda fuera de alcance. Un contrato de enumeración global no es implementable dentro de la allowlist del harness.

`MigrationManager`, `CreateStoreOnboardingActivationSessionFoundation` y `CreateStoreOnboardingRateLimitFoundation` no ejecutan named locks, no abren conexiones alternativas y no acceden directamente a mysqli, PDO o `$wpdb->dbh`. El named-lock residual es una propiedad del cleanup del harness, no una propiedad funcional productiva de esas migraciones.

## 3. Universo observable cerrado

Una conexión pertenece al universo certificado sólo si cumple simultáneamente estas condiciones:

1. Es creada por una factory privada del harness.
2. Recibe un `connection_token` antes de su primer uso.
3. El objeto se entrega exclusivamente a `InstrumentedWpdb`.
4. El handle nativo no queda expuesto después de construir el wrapper.
5. No reutiliza una conexión de WordPress productivo.
6. Se vincula exactamente a un actor de `AUTHENTICATED_CONNECTION_ACTORS`.
7. Usa la base y el prefijo desechables autorizados.
8. Su apertura queda registrada en el ledger causal.
9. Su primer uso queda registrado.
10. Su cierre queda registrado.
11. Cuando la conexión pertenece a `CONTROLLED_ACTORS`, el único actor `VERIFIER`, registrado dentro del mismo universo autenticado, realiza la verificación posterior.

Una conexión que incumpla una sola condición queda fuera del universo certificado y no puede citarse como evidencia positiva.

## 4. Regla de no bypass

`InstrumentedWpdb` debe ser la única ruta para ejecutar SQL en A, B, C y GUARD. La implementación debe fallar cerrado ante:

- acceso directo a mysqli;
- acceso a `$wpdb->dbh`;
- uso de PDO;
- creación de otra instancia `wpdb` no registrada;
- conexión secundaria no registrada;
- `call_user_func`, closure u otra indirección que oculte SQL;
- `GET_LOCK` o `RELEASE_LOCK` por una ruta no interceptada;
- actor sin `connection_token`;
- query anterior al registro de la conexión;
- query posterior a su cierre.

La ausencia de bypass debe probarse combinando inspección estática acotada a las rutas ejecutadas y guards conductuales. Cada superficie SQL autorizada debe resolver el objeto registrado, su actor, token, estado abierto y sequence antes de delegar al driver. El wrapper debe rechazar uso del handle antes del registro o después del cierre. El harness debe demostrar que todos los objetos de conexión observados pertenecen al registry y que ningún handle nativo fue publicado.

## 5. Actor autenticado VERIFIER

`VERIFIER` pertenece al universo autenticado, al connection registry, al ledger, al manifest, a la validación del parent, al cleanup y al catálogo de mutaciones. No es una conexión auxiliar externa al scope. Debe existir exactamente un actor `VERIFIER`; cualquier verificador adicional queda prohibido.

Antes del primer uso, la factory privada debe registrar causalmente y autenticar:

- `actor=VERIFIER`;
- `verifier_connection_token` único;
- base desechable autorizada;
- `connection_open_sequence`;
- `registry_sequence`;
- `execution_id`;
- `purpose=SCOPED_NAMED_LOCK_OBSERVATION`;
- `connection_identity_fingerprint`;
- `binding_event_hash`.

El handle nativo queda encapsulado por el wrapper y registry. `VERIFIER` no puede reutilizar una conexión de `CONTROLLED_ACTORS` ni compartir su identity fingerprint.

El rol de `VERIFIER` es exclusivamente read-only. Su catálogo SQL permitido contiene únicamente:

- `IS_USED_LOCK` sobre un `name_fingerprint` presente en el catálogo sellado de `CONTROLLED_ACTORS`;
- `IS_FREE_LOCK` sobre esos mismos nombres;
- `CONNECTION_ID` para probar identidad y distinción;
- un healthcheck cerrado que no modifica estado;
- cierre de la conexión.

`VERIFIER` no puede ejecutar `GET_LOCK`, `RELEASE_LOCK`, DDL, DML, migraciones, transacciones mutantes ni consultas sobre nombres ajenos al catálogo observado. Tampoco puede actuar como A, B, C o GUARD. Toda operación fuera del catálogo read-only debe fallar antes de delegarse al driver y producir el reason cerrado correspondiente.

Su lifecycle append-only debe registrar en el punto real de cada operación:

1. `verifier_connection_constructed`;
2. `verifier_connection_registered`;
3. `verifier_connection_opened`;
4. `verifier_first_use`;
5. uno o más pares `verifier_measurement_started` / `verifier_measurement_completed`;
6. `verifier_connection_close_started`;
7. `verifier_connection_closed`.

Cada medición debe ocurrir después de `opened` y `first_use`, antes de `close_started`, y transportar ambos tokens: `verifier_connection_token` y `subject_controlled_connection_token`. También debe transportar `subject_actor`, `name_fingerprint`, expected ownership, observed ownership y `measurement_sequence`. El lifecycle no puede reconstruirse después de las consultas.

El parent debe recalcular que el token e identity fingerprint de `VERIFIER` son únicos y distintos de A, B, C y GUARD, que el actor no fue cruzado, que la conexión fue registrada antes de usarse, que no fue reutilizada después del cierre y que cada medición se atribuye al único verificador autenticado.

## 6. Catálogo exhaustivo dentro del scope

`InstrumentedWpdb` debe interceptar todas las superficies SQL autorizadas, incluidas `query`, `get_var`, `get_row` y cualquier método adicional que finalmente ejecute SQL. El parser cerrado debe reconocer `GET_LOCK`, `RELEASE_LOCK` y, si llegara a estar disponible, `RELEASE_ALL_LOCKS`, con independencia de casing, whitespace y comentarios SQL.

Cada operación de lock debe producir un evento append-only con exactamente:

- `sequence`;
- `execution_id`;
- `actor`;
- `connection_token`;
- `operation`;
- `name_fingerprint`;
- `result`;
- `lifecycle_phase`.

El catálogo es exhaustivo únicamente dentro del scope porque el wrapper encapsula la conexión, no expone un handle alternativo, intercepta todas las superficies SQL permitidas y el contrato prueba y rechaza bypass. No detecta ni pretende detectar locks de conexiones ajenas.

## 7. Autoridad de cierre

Para cada nombre realmente observado, el único `VERIFIER` registrado y autenticado debe consultar `IS_USED_LOCK` o `IS_FREE_LOCK` y vincular el resultado al mismo `name_fingerprint`, al `verifier_connection_token` y al `subject_controlled_connection_token`.

La secuencia obligatoria es:

1. Abrir la conexión controlada.
2. Registrar su `connection_token`.
3. Ejecutar las operaciones mediante `InstrumentedWpdb`.
4. Sellar el catálogo.
5. Medir cada nombre observado desde la conexión verificadora.
6. Liberar explícitamente los locks observados cuando corresponda.
7. Cerrar obligatoriamente la conexión controlada, aunque `RELEASE_LOCK` haya pasado.
8. Medir nuevamente cada nombre observado desde el verificador.
9. Exigir que cada nombre observado esté libre.
10. Registrar `verifier_connection_close_started`, cerrar `VERIFIER` y registrar `verifier_connection_closed`.
11. Exigir residuo temporal cero.

La medición previa debe observar el ownership esperado cuando un lock está retenido. La medición posterior debe observar libertad después del release y del cierre. No se permite usar una conexión de `CONTROLLED_ACTORS` como `VERIFIER`, ni atribuir mediciones a otro token o a un verificador no registrado.

## 8. Catálogo vacío de A, B o C

A, B o C pueden publicar un catálogo vacío únicamente cuando:

- toda su ejecución atravesó `InstrumentedWpdb`;
- se ejecutaron todos los paths previstos;
- los guards no detectaron bypass;
- no se observó ninguna operación `GET_LOCK` o `RELEASE_LOCK`;
- la conexión quedó cerrada;
- el manifest conserva el catálogo vacío y el lifecycle completo de la conexión.

El significado exclusivo de ese resultado es:

`NO_NAMED_LOCK_OPERATIONS_OBSERVED_ON_CONTROLLED_CONNECTION`

No significa ausencia global de locks. Queda prohibido publicar `named_locks_count=0` como conclusión global.

## 9. Guard positivo obligatorio

GUARD debe demostrar conductualmente:

1. `GET_LOCK` real sobre una conexión controlada.
2. Registro del acquire por el wrapper.
3. Ownership observado desde el único `VERIFIER` registrado, autenticado y separado.
4. Catálogo con exactamente el lock adquirido.
5. `RELEASE_LOCK` real y registrado.
6. Cierre de la conexión controlada.
7. Libertad del nombre observada por el verificador.
8. Cierre causal de GUARD y VERIFIER, ausencia de mediciones pendientes y residuo temporal cero.

Las únicas salidas positivas autorizadas son:

`R1DCA_SCOPED_NAMED_LOCK_GUARD=PASS`

`R1DCA_SCOPED_NAMED_LOCK_RESIDUE=0/PASS`

En ambas, el universo se limita a los nombres observados en conexiones desechables controladas.

## 10. Manifest y recálculo del parent

El manifest debe autenticar un esquema cerrado que incluya:

- scope version exacta;
- conjunto exacto `AUTHENTICATED_CONNECTION_ACTORS={A,B,C,GUARD,VERIFIER}`;
- actor y `connection_token` único de cada conexión;
- registry binding, base desechable, purpose e identity fingerprint de VERIFIER;
- lifecycle completo de cada conexión, incluido el lifecycle real de VERIFIER;
- catálogo observado y sellado;
- fingerprints de nombres y catálogo;
- catálogo read-only autorizado de VERIFIER y ausencia material de operaciones prohibidas;
- cada medición con verifier token, subject actor, subject controlled token, name fingerprint, expected state, observed state y sequence;
- cierre de A, B, C, GUARD y VERIFIER;
- resultados posteriores al cierre.

El parent debe recalcular el catálogo por conexión, acquisitions, releases, diferencia de conjuntos, locks observados pendientes, mediciones posteriores y residuo acotado. No puede aceptar:

- catálogo truncado;
- conexión o actor omitido;
- evento sin token;
- VERIFIER omitido, adicional, no registrado o con actor distinto;
- token de VERIFIER ausente, duplicado o compartido con A, B, C o GUARD;
- base, purpose o identity fingerprint de VERIFIER inválidos;
- medición anterior a `verifier_connection_opened` o posterior a `verifier_connection_close_started`;
- subject connection token desconocido o name fingerprint no observado;
- operación fuera del catálogo read-only, incluido `GET_LOCK` o `RELEASE_LOCK` desde VERIFIER;
- lock observado sin verificación posterior;
- conexión sin cierre;
- conexión verificadora igual a la controlada;
- medición atribuida a un verifier token diferente del registrado;
- scope distinto;
- afirmación de ausencia global.

El cleanup sólo puede aprobarse cuando A, B, C, GUARD y VERIFIER tienen cierre real registrado, no existen mediciones pendientes, no hay operaciones posteriores al cierre y los temporales del harness son cero. Un VERIFIER abierto invalida el cleanup completo.

## 11. Mutaciones obligatorias futuras

La implementación posterior debe recanonicalizar, recalcular dependencias internas, generar HMAC válida y alcanzar el guard semántico específico para cada una de estas mutaciones:

- conexión A, B, C, GUARD o VERIFIER omitida individualmente;
- `connection_token` duplicado;
- actor cruzado;
- catálogo truncado;
- `GET_LOCK` omitido;
- `RELEASE_LOCK` inventado;
- fingerprint cambiado;
- medición pre-cleanup cambiada;
- medición post-close cambiada;
- verificador igual a la conexión controlada;
- `connection_closed` ausente;
- query anterior al registro;
- query posterior al cierre;
- bypass declarado;
- scope global falso;
- catálogo vacío acompañado por un evento de lock;
- lock observado sin prueba posterior de libertad.

Además, VERIFIER exige casos independientes para:

- actor de VERIFIER incorrecto;
- `verifier_connection_token` ausente;
- verifier token duplicado individualmente con A, B, C y GUARD;
- conexión VERIFIER no registrada;
- base desechable incorrecta;
- lifecycle incompleto;
- `verifier_first_use` ausente;
- medición anterior a `verifier_connection_opened`;
- medición posterior a `verifier_connection_close_started` o `verifier_connection_closed`;
- cierre de VERIFIER ausente;
- conexión de `CONTROLLED_ACTORS` reutilizada como VERIFIER;
- nombre no observado;
- `subject_controlled_connection_token` cambiado;
- `GET_LOCK` ejecutado por VERIFIER;
- `RELEASE_LOCK` ejecutado por VERIFIER;
- operación read-only no autorizada;
- verifier adicional inesperado;
- medición atribuida a un verifier token distinto del registrado.

Cada mutación debe recalcular sus dependencias internas, fingerprints, JSON canónico y HMAC, alcanzar el consumer y fallar primero en su guard semántico objetivo. Una mutación no satisface el contrato si cae antes por framing, JSON no canónico, HMAC o un fingerprint que debía recalcularse.

## 12. Invalidez automática y revisión futura

Esta autoridad queda invalidada y exige revisión previa si:

- producción incorpora named locks en `MigrationManager` o las migraciones R1D-C-A;
- aparece otra conexión durante `migrate()`;
- `InstrumentedWpdb` deja de encapsular todas las rutas;
- cambia la versión o capacidad de MariaDB;
- se habilita una autoridad completa independiente;
- el harness intenta certificar ausencia global.

## 13. Relación con los demás findings

Esta corrección normativa sólo desbloquea el diseño de autoridad de named locks acotada a conexiones controladas. No certifica R1D-C-A ni autoriza cambios productivos.

La implementación posterior sigue obligada a incorporar:

- ledger causal real y append-only;
- `ManagerRegistry` mediante `WeakMap`, `SplObjectStorage` o equivalente;
- snapshots estructurales completos;
- failure A cerrado;
- binding real entre managers, instancias, conexiones y eventos;
- siete fases reales y separadas;
- mutaciones dependency-aware que alcancen el guard correcto.

## 14. Workflow privilegiado preservado

Se conserva sin cambio conceptual el workflow ya certificado:

1. Crear un commit candidato local.
2. Ejecutar la validación elevada contra ese candidate exacto.
3. Ligar el receipt a commit, tree, blobs, SHA-256 y tamaños before/after.
4. Validar symlink y junction por separado.
5. Rechazar cualquier cambio posterior.
6. Publicar exactamente el mismo candidate validado.

Los resultados futuros de lock deberán incorporarse al receipt sin debilitar su escritura atómica ni su binding de identidad.
