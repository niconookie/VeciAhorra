# Corrección normativa R1D-C-A: autoridad acotada de named locks

## 1. Decisión normativa

La única arquitectura autorizada para demostrar cleanup de named locks en el harness R1D-C-A es:

`R1DCA_SCOPED_CONTROLLED_CONNECTION_LOCK_AUTHORITY_V1`

Su salida canónica de alcance es:

`R1DCA_NAMED_LOCK_AUTHORITY_SCOPE=CONTROLLED_DISPOSABLE_CONNECTIONS_ONLY`

La autoridad se limita exclusivamente a conexiones desechables creadas por el harness, entregadas mediante `InstrumentedWpdb`, vinculadas a los actores A, B, C y GUARD, ejecutadas sobre la base desechable autorizada y abiertas y cerradas dentro del mismo lifecycle del harness.

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
6. Se vincula exactamente a A, B, C o GUARD.
7. Usa la base y el prefijo desechables autorizados.
8. Su apertura queda registrada en el ledger causal.
9. Su primer uso queda registrado.
10. Su cierre queda registrado.
11. Una conexión verificadora separada realiza la verificación posterior.

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

## 5. Catálogo exhaustivo dentro del scope

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

## 6. Autoridad de cierre

Para cada nombre realmente observado, una conexión verificadora separada debe consultar `IS_USED_LOCK` o `IS_FREE_LOCK` y vincular el resultado al mismo `name_fingerprint`.

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
10. Cerrar la conexión verificadora.
11. Exigir residuo temporal cero.

La medición previa debe observar el ownership esperado cuando un lock está retenido. La medición posterior debe observar libertad después del release y del cierre. No se permite usar la conexión controlada como su propio verificador.

## 7. Catálogo vacío de A, B o C

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

## 8. Guard positivo obligatorio

GUARD debe demostrar conductualmente:

1. `GET_LOCK` real sobre una conexión controlada.
2. Registro del acquire por el wrapper.
3. Ownership observado desde un verificador separado.
4. Catálogo con exactamente el lock adquirido.
5. `RELEASE_LOCK` real y registrado.
6. Cierre de la conexión controlada.
7. Libertad del nombre observada por el verificador.
8. Cierre de ambas conexiones y residuo temporal cero.

Las únicas salidas positivas autorizadas son:

`R1DCA_SCOPED_NAMED_LOCK_GUARD=PASS`

`R1DCA_SCOPED_NAMED_LOCK_RESIDUE=0/PASS`

En ambas, el universo se limita a los nombres observados en conexiones desechables controladas.

## 9. Manifest y recálculo del parent

El manifest debe autenticar un esquema cerrado que incluya:

- scope version exacta;
- actores A, B, C y GUARD;
- connection tokens únicos;
- lifecycle completo de cada conexión;
- catálogo observado y sellado;
- fingerprints de nombres y catálogo;
- mediciones del verificador;
- cierre de conexiones;
- resultados posteriores al cierre.

El parent debe recalcular el catálogo por conexión, acquisitions, releases, diferencia de conjuntos, locks observados pendientes, mediciones posteriores y residuo acotado. No puede aceptar:

- catálogo truncado;
- conexión o actor omitido;
- evento sin token;
- lock observado sin verificación posterior;
- conexión sin cierre;
- conexión verificadora igual a la controlada;
- scope distinto;
- afirmación de ausencia global.

## 10. Mutaciones obligatorias futuras

La implementación posterior debe recanonicalizar, recalcular dependencias internas, generar HMAC válida y alcanzar el guard semántico específico para cada una de estas mutaciones:

- conexión A, B, C o GUARD omitida individualmente;
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

Una mutación no satisface el contrato si cae primero por framing, JSON no canónico, HMAC o un fingerprint que debía recalcularse.

## 11. Invalidez automática y revisión futura

Esta autoridad queda invalidada y exige revisión previa si:

- producción incorpora named locks en `MigrationManager` o las migraciones R1D-C-A;
- aparece otra conexión durante `migrate()`;
- `InstrumentedWpdb` deja de encapsular todas las rutas;
- cambia la versión o capacidad de MariaDB;
- se habilita una autoridad completa independiente;
- el harness intenta certificar ausencia global.

## 12. Relación con los demás findings

Esta corrección normativa sólo desbloquea el diseño de autoridad de named locks acotada a conexiones controladas. No certifica R1D-C-A ni autoriza cambios productivos.

La implementación posterior sigue obligada a incorporar:

- ledger causal real y append-only;
- `ManagerRegistry` mediante `WeakMap`, `SplObjectStorage` o equivalente;
- snapshots estructurales completos;
- failure A cerrado;
- binding real entre managers, instancias, conexiones y eventos;
- siete fases reales y separadas;
- mutaciones dependency-aware que alcancen el guard correcto.

## 13. Workflow privilegiado preservado

Se conserva sin cambio conceptual el workflow ya certificado:

1. Crear un commit candidato local.
2. Ejecutar la validación elevada contra ese candidate exacto.
3. Ligar el receipt a commit, tree, blobs, SHA-256 y tamaños before/after.
4. Validar symlink y junction por separado.
5. Rechazar cualquier cambio posterior.
6. Publicar exactamente el mismo candidate validado.

Los resultados futuros de lock deberán incorporarse al receipt sin debilitar su escritura atómica ni su binding de identidad.
