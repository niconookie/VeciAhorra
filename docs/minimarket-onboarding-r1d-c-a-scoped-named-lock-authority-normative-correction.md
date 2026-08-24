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
9. Inmediatamente después de abrirse y antes de cualquier otra operación SQL, ejecuta exactamente una vez `SELECT CONNECTION_ID()` mediante `InstrumentedWpdb` y registra la identidad observada.
10. Su primer uso queda registrado por esa consulta de identidad.
11. Su cierre queda registrado.
12. Cuando la conexión pertenece a `CONTROLLED_ACTORS`, el único actor `VERIFIER`, registrado dentro del mismo universo autenticado, realiza la verificación posterior.

Una conexión que incumpla una sola condición queda fuera del universo certificado y no puede citarse como evidencia positiva.

## 4. Regla de no bypass

`InstrumentedWpdb` debe ser la única ruta para ejecutar SQL en A, B, C, GUARD y VERIFIER. La implementación debe fallar cerrado ante:

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

## 5. Identidad causal de todas las conexiones autenticadas

Cada conexión de `AUTHENTICATED_CONNECTION_ACTORS` debe ejecutar, después de `connection_opened` y antes de cualquier otra operación SQL, exactamente:

`SELECT CONNECTION_ID()`

La consulta debe atravesar `InstrumentedWpdb`; no puede construirse `connection_identity_observed` desde un valor entregado por el llamador. El ledger append-only debe registrar `query_started`, `query_completed` y `connection_identity_observed`. Cada evento transporta plantilla SQL exacta, `scope_version`, `execution_id`, actor, `connection_token` y su sequence; `query_completed` transporta además el resultado tipado, y `connection_identity_observed` referencia las sequences exactas de ambos eventos. El resultado `connection_id` debe ser el entero decimal positivo, no nullable y no string devuelto realmente por `CONNECTION_ID()`; no puede ser aleatorio ni reutilizarse por otra conexión simultáneamente abierta del universo autenticado.

El evento `connection_identity_observed` debe contener exactamente el material causal necesario:

- `scope_version`;
- `execution_id`;
- `actor`;
- `connection_token`;
- `connection_id`;
- `registry_sequence`;
- `constructed_sequence`;
- `registered_sequence`;
- `opened_sequence`;
- `identity_observed_sequence`;
- `canonical_identity_material`;
- `connection_identity_fingerprint`.

La única serialización autorizada se define literalmente como:

```text
canonical_identity_material = canonical_json({
"scope_version": scope_version,
"execution_id": execution_id,
"actor": actor,
"connection_token": connection_token,
"connection_id": connection_id,
"registry_sequence": registry_sequence,
"constructed_sequence": constructed_sequence,
"registered_sequence": registered_sequence,
"opened_sequence": opened_sequence,
"identity_observed_sequence": identity_observed_sequence
})
```

Las claves deben aparecer en ese orden exacto. El resultado es JSON UTF-8, sin BOM, sin whitespace y **sin LF final**. Los enteros se serializan como números JSON; los strings siguen la normalización del canonical JSON ya autorizada. No se admiten serialización permisiva, variantes equivalentes ni campos adicionales. El material no incluye credenciales, host, puerto, usuario de base de datos, paths, SQL sensible ni nombres físicos dinámicos no sanitizados.

La fórmula única se define literalmente como:

```text
connection_identity_fingerprint =
UPPERCASE_HEX(
SHA256(canonical_identity_material)
)
```

`connection_identity_fingerprint` contiene exactamente 64 caracteres `[0-9A-F]`. No es un nonce ni un valor libre del productor. Debe cumplirse `constructed_sequence < registered_sequence <= registry_sequence < opened_sequence < identity_observed_sequence`. El registry impide identidad anterior al registro o posterior al cierre, una segunda identidad diferente para la misma conexión, cambio de actor o token, rebind y reutilización de `connection_id` dentro del conjunto simultáneamente abierto.

## 6. Autoridad de resultados SQL numéricos nativos

La única autoridad de tipado admitida es:

`R1DCA_NATIVE_NUMERIC_SQL_RESULT_AUTHORITY_V1`

Se aplica obligatoriamente a `SELECT CONNECTION_ID()` y al resultado no null de `SELECT IS_USED_LOCK(<LOCK_NAME>)`. La implementación debe usar una subclase de `wpdb` exclusiva del harness, denominada conceptualmente `InstrumentedWpdb`, sin modificar WordPress, producción ni configuración global de PHP o MariaDB.

### 6.1. Creación cerrada de la conexión

`InstrumentedWpdb` debe controlar directamente y en este orden causal la creación del handle:

1. comprobar que existe `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`;
2. ejecutar `mysqli_init()` y exigir una instancia `mysqli` válida;
3. ejecutar exactamente la configuración equivalente a:

```php
mysqli_options(
    $dbh,
    MYSQLI_OPT_INT_AND_FLOAT_NATIVE,
    1
)
```

4. exigir que `mysqli_options()` devuelva exactamente `true`;
5. sólo entonces iniciar `mysqli_real_connect()`;
6. exigir apertura correcta antes de ejecutar SQL.

La opción no puede configurarse después de conectar. No se puede reutilizar una conexión creada por WordPress sin esa opción, usar la conexión productiva, exponer el handle, alterar `wp-includes/class-wpdb.php`, instalar plugins ni cambiar configuración global. Cualquier incumplimiento debe detener el harness antes de producir evidencia con `native_numeric_driver_capability_unavailable`.

La subclase debe conservar `$dbh` bajo la visibilidad protegida heredada y no entregarlo a `MigrationManager`, callbacks, closures ni código externo. A `MigrationManager` se entrega exclusivamente el objeto `InstrumentedWpdb` registrado. La subclase intercepta `query`, `get_var` y toda ruta derivada aplicable, preserva la semántica productiva de `wpdb`, usa sólo la base desechable autorizada y cierra cada conexión. Queda prohibido usar Reflection para reemplazar, extraer o alterar `$dbh`.

### 6.2. Capability probe bloqueante

Antes de construir o ejecutar A, B, C, GUARD o VERIFIER, el harness debe abrir una conexión desechable exclusiva de probe mediante la misma subclase y secuencia nativa. Esta conexión no pertenece a `AUTHENTICATED_CONNECTION_ACTORS`, no puede producir evidencia positiva de locks, no se incorpora como actor al manifest nominal y debe cerrarse antes de construir el universo autenticado. Su ledger de preflight queda ligado al `execution_id` y a `capability_version=R1DCA_NATIVE_NUMERIC_SQL_RESULT_AUTHORITY_V1`.

El probe debe comprobar, en orden:

1. `PHP_INT_SIZE` suficiente para representar el rango de `connection_id` sin pérdida;
2. existencia de `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`;
3. retorno exactamente `true` de `mysqli_options()`;
4. apertura correcta;
5. resultado original de `SELECT CONNECTION_ID()` con `get_debug_type(...) === 'int'` e `is_int(...) === true`;
6. entero estrictamente positivo;
7. ausencia de transformación, conversión o normalización posterior;
8. cierre real del probe y residuo final cero.

String, float, bool, objeto, null o cualquier tipo distinto de int impiden publicar PASS. Ante ese resultado no se ejecutan migraciones, no se construye manifest, no se firma evidencia y no existe fallback; el reason cerrado es `native_numeric_connection_id_not_int` y el veredicto runtime futuro es:

`VERDICT=MINIMARKET_ONBOARDING_R1DCA_NATIVE_NUMERIC_RUNTIME_BLOCKED`

### 6.3. Prohibición absoluta de coerción

Antes de validar el tipo original, y también después de validarlo, quedan prohibidos `(int)`, `intval`, `filter_var`, `FILTER_VALIDATE_INT`, suma con cero, multiplicación por uno, comparación numérica permisiva, `is_numeric`, `ctype_digit`, regex seguida de cast, encode/decode JSON usado para cambiar el tipo, serialización intermedia y cualquier normalizador o helper equivalente.

El orden obligatorio es:

1. recibir el resultado original del driver;
2. registrar su tipo original mediante `get_debug_type()` o una autoridad cerrada equivalente que no transforme el valor;
3. exigir `is_int()`;
4. exigir valor positivo;
5. incorporar exactamente el mismo entero, sin cast ni copia reconstruida, al evento causal.

### 6.4. Lifecycle de configuración nativa

Para cada conexión autenticada, el ledger registra en el punto real `native_numeric_option_configured` con exactamente:

- `execution_id`;
- `connection_token`;
- `actor`;
- `option_constant`;
- `requested_value`;
- `option_result`;
- `configured_sequence`;
- `connect_started_sequence`;
- `connection_opened_sequence`.

El parent debe comprobar que `option_constant` identifica exactamente `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`, `requested_value === 1`, `option_result === true` y `configured_sequence < connect_started_sequence < connection_opened_sequence`. Un booleano aislado no es autoridad: deben existir el evento, las sequences y el lifecycle causal completo de configuración y conexión.

### 6.5. Resultado original y binding estricto

`query_completed` de `SELECT CONNECTION_ID()` debe transportar exactamente `sql_template`, `raw_result_type`, `raw_result`, `connection_token`, `actor`, `execution_id`, `query_started_sequence` y `query_completed_sequence`. `raw_result_type` debe ser literalmente `int`; `raw_result` debe ser el entero positivo original recibido con la opción nativa y debe satisfacer `is_int()`. No pueden existir `normalized_result`, `cast_result` ni un entero reconstruido.

`connection_identity_observed` reutiliza el mismo valor sin transformación. El parent debe comprobar literalmente, con igualdad estricta de tipo y valor:

```text
query_completed.raw_result ===
connection_identity_observed.connection_id
```

Para `SELECT IS_USED_LOCK(<LOCK_NAME>)`, `query_completed` transporta `raw_result_type`, `raw_result`, verifier connection token, subject connection token, `name_fingerprint`, `measurement_phase` y sus sequences. En `PRE_CLEANUP`, `raw_result_type` debe ser `int`, el valor original debe satisfacer `is_int()`, ser positivo, ser estrictamente igual al `connection_id` nativo del subject y estrictamente distinto del `connection_id` de VERIFIER. En `POST_CLOSE`, `raw_result_type` debe ser exactamente `null` y `raw_result === null`; no se puede convertir null en cero, string vacío o false.

El mismo valor nativo queda vinculado, con mismo tipo, valor, `connection_token`, `execution_id` y orden causal, entre `query_completed`, `connection_identity_observed`, `canonical_identity_material`, `connection_identity_fingerprint`, mediciones `PRE_CLEANUP` y la identidad del subject. El parent recalcula y compara cada relación; un entero equivalente reconstruido desde texto no satisface identidad causal.

### 6.6. Manifest y orden de guards

El manifest autentica `capability_version`, lifecycle de la opción, `raw_result_type`, `raw_result`, query event, identity event, measurement, actor, tokens, sequences, material canónico y fingerprint. El parent rechaza bloque de capacidad ausente, opción posterior al connect, resultado false, resultado original string o convertido, tipo declarado contradictorio, valor diferente entre eventos, null convertido, entero reconstruido, conexión sin capacidad nativa y actor o token cruzado.

Los guards se evalúan después de framing, canonicalización, firma, shape y tipos estructurales, y antes de fingerprints o conclusiones dependientes, en este orden semántico: capacidad y presencia; lifecycle y orden de opción; correspondencia entre tipo declarado y tipo real; prohibición de coerción; rango del valor; binding entre eventos; ownership y fase. Las mutaciones deben recalcular todas las dependencias no objetivo para alcanzar el reason previsto.

### 6.7. Mutaciones nativas obligatorias

Cada caso parte de evidencia nominal válida, cambia sólo el objetivo, recalcula dependencias no objetivo, canonical JSON y HMAC, llega al consumidor, supera framing y firma y falla por su reason semántico cerrado:

| ID de mutación | Objetivo | Reason exacto |
|---|---|---|
| `NATIVE_OPTION_CONSTANT_MISSING` | constante ausente | `native_numeric_driver_capability_unavailable` |
| `NATIVE_OPTION_RESULT_FALSE` | `mysqli_options()` retorna false | `native_numeric_option_result` |
| `NATIVE_OPTION_AFTER_CONNECT` | opción configurada después de connect | `native_numeric_option_order` |
| `NATIVE_OPTION_EVENT_MISSING` | evento de configuración omitido | `native_numeric_event_missing` |
| `NATIVE_OPTION_REQUESTED_VALUE_NOT_ONE` | requested value distinto de 1 | `native_numeric_driver_capability_unavailable` |
| `CONNECTION_ID_RAW_NUMERIC_STRING` | string numérico | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_WHITESPACE_STRING` | string con whitespace | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_SIGNED_STRING` | string con signo | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_FLOAT` | float | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_BOOL` | bool | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_NULL` | null | `native_connection_id_raw_type` |
| `CONNECTION_ID_RAW_ZERO` | entero nativo igual a cero | `native_connection_id_raw_value` |
| `CONNECTION_ID_RAW_NEGATIVE` | entero nativo negativo | `native_connection_id_raw_value` |
| `CONNECTION_ID_DECLARED_INT_RAW_STRING` | tipo declarado int y valor string | `native_numeric_declared_type_mismatch` |
| `CONNECTION_ID_DECLARED_STRING_RAW_INT` | tipo declarado string y valor int | `native_numeric_declared_type_mismatch` |
| `CONNECTION_ID_CONVERTED_EVENT_VALUE` | identity event usa entero convertido | `native_numeric_coercion_detected` |
| `LOCK_OWNER_RAW_NUMERIC_STRING` | PRE_CLEANUP retorna string numérico | `native_lock_owner_raw_type` |
| `LOCK_OWNER_RAW_FLOAT` | PRE_CLEANUP retorna float | `native_lock_owner_raw_type` |
| `LOCK_OWNER_RAW_BOOL` | PRE_CLEANUP retorna bool | `native_lock_owner_raw_type` |
| `LOCK_OWNER_NOT_SUBJECT_NATIVE_ID` | owner entero distinto del subject | `native_lock_owner_raw_value` |
| `POST_CLOSE_RAW_EMPTY_STRING` | POST_CLOSE retorna string vacío | `native_post_close_raw_type` |
| `POST_CLOSE_RAW_ZERO` | POST_CLOSE retorna cero | `native_post_close_raw_value` |
| `POST_CLOSE_RAW_FALSE` | POST_CLOSE retorna false | `native_post_close_raw_type` |
| `POST_CLOSE_DECLARED_TYPE_NOT_NULL` | raw result type no es null | `native_post_close_raw_type` |
| `LOCK_RESULT_QUERY_MEASUREMENT_MISMATCH` | valor cambia entre query y measurement | `native_lock_owner_event_mismatch` |
| `CONNECTION_ID_QUERY_IDENTITY_MISMATCH` | valor cambia entre query e identity event | `native_connection_id_event_mismatch` |

Los reasons cerrados de esta autoridad son: `native_numeric_driver_capability_unavailable`, `native_numeric_connection_id_not_int`, `native_numeric_option_order`, `native_numeric_option_result`, `native_numeric_event_missing`, `native_connection_id_raw_type`, `native_connection_id_raw_value`, `native_connection_id_event_mismatch`, `native_lock_owner_raw_type`, `native_lock_owner_raw_value`, `native_lock_owner_event_mismatch`, `native_post_close_raw_type`, `native_post_close_raw_value`, `native_numeric_declared_type_mismatch` y `native_numeric_coercion_detected`.

### 6.8. Inspección anti-coerción y factibilidad

La implementación debe inspeccionar mediante tokens exclusivamente el pipeline de resultados de `CONNECTION_ID` e `IS_USED_LOCK` y rechazar `T_INT_CAST`, llamadas a `intval`, `filter_var`, `is_numeric` o `ctype_digit` usadas como autoridad, helpers de normalización y conversiones equivalentes. Esta inspección se complementa obligatoriamente con pruebas conductuales; por sí sola no prueba ausencia de coerción.

La arquitectura es implementable con una subclase exclusiva de `wpdb`, sin modificar producción, WordPress, MariaDB, plugins ni configuración global. Si el entorno no entrega enteros nativos después de configurar correctamente `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`, la implementación queda bloqueada con el veredicto runtime publicado y sin fallback por cast.

## 7. Actor autenticado VERIFIER

`VERIFIER` pertenece al universo autenticado, al connection registry, al ledger, al manifest, a la validación del parent, al cleanup y al catálogo de mutaciones. No es una conexión auxiliar externa al scope. Debe existir exactamente un actor `VERIFIER`; cualquier verificador adicional queda prohibido.

Antes del primer uso, la factory privada debe registrar causalmente y autenticar:

- `actor=VERIFIER`;
- `verifier_connection_token` único;
- base desechable autorizada;
- `constructed_sequence`, `registered_sequence` y `opened_sequence`;
- `registry_sequence`;
- `execution_id`;
- `purpose=SCOPED_NAMED_LOCK_OBSERVATION`;
- `binding_event_hash`.

Durante el primer uso SQL, `InstrumentedWpdb` debe añadir el evento real `connection_identity_observed`, `connection_id`, `canonical_identity_material` y `connection_identity_fingerprint` sin permitir que el llamador los suministre.

El handle nativo queda encapsulado por el wrapper y registry. `VERIFIER` no puede reutilizar una conexión de `CONTROLLED_ACTORS` ni compartir su token, `connection_id` o identity fingerprint. VERIFIER y el subject medido deben permanecer simultáneamente abiertos durante `PRE_CLEANUP`.

El rol de `VERIFIER` es exclusivamente read-only. Su catálogo SQL contiene exactamente dos plantillas y ninguna otra:

1. `SELECT CONNECTION_ID()`, exactamente una vez, inmediatamente después de abrir la conexión y antes de cualquier otro SQL.
2. `SELECT IS_USED_LOCK(<LOCK_NAME>)`, donde `<LOCK_NAME>` pertenece previamente al catálogo sellado del subject controlado, está ligado al mismo `execution_id` y usa exclusivamente el escaping o preparación cerrada ya autorizada.

VERIFIER no puede inventar nombres ni consultar nombres de otro actor, conexión o ejecución. Quedan prohibidos `SELECT 1`, `IS_FREE_LOCK`, `SHOW`, `DESCRIBE`, `EXPLAIN`, consultas a `information_schema` o `performance_schema`, healthchecks, SQL adicional, funciones equivalentes, comentarios SQL, statements múltiples, procedimientos almacenados, variantes libres y consultas supuestamente necesarias.

`VERIFIER` no puede ejecutar `GET_LOCK`, `RELEASE_LOCK`, DDL, DML, migraciones, transacciones mutantes ni consultas sobre nombres ajenos al catálogo observado. Tampoco puede actuar como A, B, C o GUARD. Toda operación fuera del catálogo read-only debe fallar antes de delegarse al driver y producir el reason cerrado correspondiente.

Su lifecycle append-only debe registrar en el punto real de cada operación:

1. `verifier_connection_constructed`;
2. `verifier_connection_registered`;
3. `verifier_connection_opened`;
4. `verifier_first_use`, inmediatamente al comenzar la primera consulta;
5. `query_started` para `SELECT CONNECTION_ID()`;
6. `query_completed` para `SELECT CONNECTION_ID()`;
7. `connection_identity_observed`, vinculado a esa consulta;
8. uno o más pares `verifier_measurement_started` / `verifier_measurement_completed`;
9. `verifier_connection_close_started`;
10. `verifier_connection_closed`.

Cada medición transporta y autentica exactamente `measurement_phase`, `verifier_connection_token`, `verifier_connection_id`, `subject_actor`, `subject_connection_token`, `subject_connection_id`, `name_fingerprint`, `measurement_started_sequence`, `measurement_completed_sequence`, `observed_owner_connection_id` y `execution_id`. `measurement_phase` es obligatorio y su enum cerrado es `PRE_CLEANUP|POST_CLOSE`; no se deduce sólo desde sequence. El lifecycle no puede reconstruirse después de las consultas.

En `PRE_CLEANUP`, la medición ocurre después del acquire real y del sellado del catálogo, antes de `RELEASE_LOCK` y antes del cierre del subject. Su resultado tipado debe cumplir literalmente:

`observed_owner_connection_id === subject_connection_identity.connection_id`

Ese entero positivo no puede ser el `connection_id` de VERIFIER. En `POST_CLOSE`, la medición ocurre después de `subject_connection_closed`, antes de `verifier_connection_close_started` y antes de `verifier_connection_closed`; debe cumplir literalmente:

`observed_owner_connection_id === null`

El parent debe validar el evento real `connection_identity_observed`, reconstruir literalmente `canonical_identity_material`, recalcular SHA-256 y comparar el fingerprint de cada actor. Debe comprobar actor, token, lifecycle, resultado tipado de la consulta, identidad en mediciones posteriores y unicidad de `connection_id` entre conexiones simultáneamente abiertas. También recalcula que token, `connection_id` y fingerprint de VERIFIER son distintos de los del subject, que `IS_USED_LOCK` identifica al subject durante `PRE_CLEANUP` y nunca a VERIFIER, y que devuelve null durante `POST_CLOSE`. Cualquier contradicción entre material, fingerprint, token, actor, lifecycle, execution ID o medición falla cerrada; formato, presencia, `distinct=true` o una conclusión booleana declarada nunca constituyen autoridad primaria.

## 8. Catálogo exhaustivo dentro del scope

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

## 9. Autoridad de cierre

Para cada nombre realmente observado, el único `VERIFIER` registrado y autenticado debe consultar `IS_USED_LOCK` y vincular el resultado al mismo `name_fingerprint`, `execution_id`, identidades causales y tokens del verifier y subject.

La secuencia obligatoria es:

1. Construir y registrar la conexión controlada y su `connection_token`.
2. Abrirla y ejecutar `SELECT CONNECTION_ID()` como su primer y único SQL de identidad.
3. Construir, registrar y abrir VERIFIER; ejecutar `SELECT CONNECTION_ID()` como su primer y único SQL de identidad.
4. Ejecutar las demás operaciones controladas mediante `InstrumentedWpdb`.
5. Sellar el catálogo.
6. Medir cada nombre observado desde VERIFIER con `measurement_phase=PRE_CLEANUP` mientras ambas conexiones permanecen abiertas.
7. Liberar explícitamente los locks observados cuando corresponda.
8. Cerrar obligatoriamente la conexión controlada, aunque `RELEASE_LOCK` haya pasado.
9. Medir nuevamente cada nombre observado desde VERIFIER con `measurement_phase=POST_CLOSE`.
10. Exigir resultado null para cada nombre observado.
11. Registrar `verifier_connection_close_started`, cerrar VERIFIER y registrar `verifier_connection_closed`.
12. Exigir residuo temporal cero.

La medición previa debe observar el ownership esperado cuando un lock está retenido. La medición posterior debe observar libertad después del release y del cierre. No se permite usar una conexión de `CONTROLLED_ACTORS` como `VERIFIER`, ni atribuir mediciones a otro token o a un verificador no registrado.

## 10. Catálogo vacío de A, B o C

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

## 11. Guard positivo obligatorio

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

## 12. Manifest y recálculo del parent

El manifest debe autenticar un esquema cerrado que incluya:

- scope version exacta;
- conjunto exacto `AUTHENTICATED_CONNECTION_ACTORS={A,B,C,GUARD,VERIFIER}`;
- actor y `connection_token` único de cada conexión;
- registry binding, base desechable y purpose de VERIFIER;
- para A, B, C, GUARD y VERIFIER: evento real `connection_identity_observed`, `connection_id`, la totalidad del material causal, `canonical_identity_material`, `connection_identity_fingerprint`, actor, token, lifecycle, execution ID y vínculo con mediciones posteriores;
- lifecycle completo de cada conexión, incluido el lifecycle real de VERIFIER;
- catálogo observado y sellado;
- fingerprints de nombres y catálogo;
- catálogo read-only autorizado de VERIFIER y ausencia material de operaciones prohibidas;
- cada medición con todos los campos cerrados definidos en la sección 7, incluida `measurement_phase`;
- cierre de A, B, C, GUARD y VERIFIER;
- resultados posteriores al cierre.

El parent debe recalcular identidades, catálogo por conexión, acquisitions, releases, diferencia de conjuntos, locks observados pendientes, mediciones posteriores y residuo acotado. Debe validar fase, orden, actor, subject, nombre, ownership, resultado y lifecycle. No puede aceptar:

- catálogo truncado;
- conexión o actor omitido;
- evento sin token;
- VERIFIER omitido, adicional, no registrado o con actor distinto;
- token de VERIFIER ausente, duplicado o compartido con A, B, C o GUARD;
- base, purpose, identidad causal, `connection_id`, material canónico o identity fingerprint inválidos;
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

## 13. Mutaciones obligatorias futuras

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

Además de los casos de actor, token, registro, base, lifecycle incompleto, first use, ventana de medición, cierre, conexión reutilizada, nombre desconocido, operaciones prohibidas, verifier adicional y atribución incorrecta ya enumerados, el catálogo exige estas mutaciones independientes con first-failure reason exacto:

| ID de mutación | Cambio semántico único | Reason exacto |
|---|---|---|
| `CONNECTION_ID_MISSING` | `connection_id` ausente | `connection_identity_connection_id_missing` |
| `CONNECTION_ID_STRING` | `connection_id` con tipo string | `connection_identity_connection_id_type` |
| `CONNECTION_ID_ZERO` | `connection_id` igual a cero | `connection_identity_connection_id_range` |
| `CONNECTION_ID_NEGATIVE` | `connection_id` negativo | `connection_identity_connection_id_range` |
| `CONNECTION_ID_INVENTED` | valor no respaldado por el resultado tipado de `CONNECTION_ID()` | `connection_identity_result_binding` |
| `CONNECTION_ID_DUPLICATED_OPEN` | `connection_id` duplicado entre conexiones simultáneamente abiertas | `connection_identity_open_set_duplicate` |
| `CONNECTION_ID_QUERY_RESULT_CHANGED` | resultado de `CONNECTION_ID()` alterado | `connection_identity_query_result` |
| `CANONICAL_IDENTITY_MATERIAL_CHANGED` | material canónico alterado | `connection_identity_material` |
| `CONNECTION_IDENTITY_FINGERPRINT_CONTRADICTORY` | fingerprint contradictorio | `connection_identity_fingerprint` |
| `CONNECTION_IDENTITY_ACTOR_CHANGED` | actor cambiado y fingerprint recalculado | `connection_identity_actor_binding` |
| `CONNECTION_IDENTITY_TOKEN_CHANGED` | token cambiado y fingerprint recalculado | `connection_identity_token_binding` |
| `CONNECTION_IDENTITY_LIFECYCLE_SEQUENCE_CHANGED` | sequence de lifecycle cambiado y fingerprint recalculado | `connection_identity_lifecycle_binding` |
| `VERIFIER_CONNECTION_ID_EQUALS_SUBJECT` | `connection_id` de VERIFIER igual al subject | `verifier_connection_identity_distinct` |
| `PRE_CLEANUP_OWNER_NOT_SUBJECT` | owner distinto del subject | `verifier_pre_cleanup_owner` |
| `PRE_CLEANUP_OWNER_EQUALS_VERIFIER` | owner igual a VERIFIER | `verifier_pre_cleanup_owner_is_verifier` |
| `POST_CLOSE_OWNER_NOT_NULL` | owner posterior no null | `verifier_post_close_owner` |
| `MEASUREMENT_PHASE_MISSING` | `measurement_phase` ausente | `verifier_measurement_phase_missing` |
| `MEASUREMENT_PHASE_WRONG_TYPE` | `measurement_phase` con tipo incorrecto | `verifier_measurement_phase_type` |
| `MEASUREMENT_PHASE_OUTSIDE_ENUM` | fase fuera de `PRE_CLEANUP|POST_CLOSE` | `verifier_measurement_phase_enum` |
| `PRE_CLEANUP_AFTER_SUBJECT_CLOSE` | PRE_CLEANUP después del cierre | `verifier_pre_cleanup_order` |
| `POST_CLOSE_BEFORE_SUBJECT_CLOSE` | POST_CLOSE antes del cierre | `verifier_post_close_order` |
| `MEASUREMENT_EXECUTION_ID_CROSSED` | execution ID cruzado | `verifier_measurement_execution` |
| `MEASUREMENT_NAME_FROM_OTHER_SUBJECT` | name fingerprint de otro subject | `verifier_measurement_subject_catalog` |
| `VERIFIER_LIFECYCLE_REORDERED` | conserva todos los eventos y altera únicamente su orden causal | `verifier_lifecycle_order` |

`VERIFIER_LIFECYCLE_REORDERED` debe recalcular la cadena posterior de eventos, fingerprints dependientes, canonical JSON y HMAC; debe llegar a `R1dcaManifestChannel::consume()`, superar framing, canonicalización, firma, presencia y tipos, y ser rechazado específicamente por `verifier_lifecycle_order`, nunca primero por `manifest_hmac`, `manifest_noncanonical`, evento ausente, identity fingerprint, schema genérico o catálogo incompleto.

Cada mutación parte de evidencia nominal válida, modifica sólo su objetivo semántico, recalcula todas las dependencias no objetivo, fingerprints, cadena posterior, JSON canónico y HMAC, alcanza el consumer, supera los guards anteriores y falla primero en el reason cerrado publicado. Una mutación no satisface el contrato si cae antes por framing, JSON no canónico, HMAC, presencia, tipo o un fingerprint que debía recalcularse.

## 14. Invalidez automática y revisión futura

Esta autoridad queda invalidada y exige revisión previa si:

- producción incorpora named locks en `MigrationManager` o las migraciones R1D-C-A;
- aparece otra conexión durante `migrate()`;
- `InstrumentedWpdb` deja de encapsular todas las rutas;
- cambia la versión o capacidad de MariaDB;
- se habilita una autoridad completa independiente;
- el harness intenta certificar ausencia global.

## 15. Relación con los demás findings

Esta corrección normativa sólo desbloquea el diseño de autoridad de named locks acotada a conexiones controladas. No certifica R1D-C-A ni autoriza cambios productivos.

La implementación posterior sigue obligada a incorporar:

- ledger causal real y append-only;
- `ManagerRegistry` mediante `WeakMap`, `SplObjectStorage` o equivalente;
- snapshots estructurales completos;
- failure A cerrado;
- binding real entre managers, instancias, conexiones y eventos;
- siete fases reales y separadas;
- mutaciones dependency-aware que alcancen el guard correcto.

## 16. Workflow privilegiado preservado

Se conserva sin cambio conceptual el workflow ya certificado:

1. Crear un commit candidato local.
2. Ejecutar la validación elevada contra ese candidate exacto.
3. Ligar el receipt a commit, tree, blobs, SHA-256 y tamaños before/after.
4. Validar symlink y junction por separado.
5. Rechazar cualquier cambio posterior.
6. Publicar exactamente el mismo candidate validado.

Los resultados futuros de lock deberán incorporarse al receipt sin debilitar su escritura atómica ni su binding de identidad.
