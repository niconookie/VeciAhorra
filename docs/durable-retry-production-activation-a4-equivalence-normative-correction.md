# Corrección normativa A4 — equivalencia de transferencia inicial durable

## 1. Autoridad, alcance y veredicto

Este documento es una corrección normativa complementaria de:

- `docs/durable-retry-production-activation-a4-readiness-audit.md`;
- `docs/durable-retry-production-activation-a1-contracts-spec.md`;
- la especificación de composición productiva de activación.

Corrige exclusivamente la definición de equivalencia de una fila durable
`generation = 1` existente. No modifica A1, sus firmas, sus siete resultados ni
el contenido de `DurableRetryInitialTransferRequest`.

**Veredicto: A4 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA DE EQUIVALENCIA.**

## 2. Base documental

La corrección se redactó sobre:

- rama `main`;
- HEAD `49475e1ae9a50693e7bf524dbd4926c570ab3ac5`;
- divergencia `0` atrás / `38` adelante;
- staging vacío;
- cero cambios tracked;
- seis archivos A4 nuevos no versionados intactos;
- 504 archivos en `artifacts/`;
- documentos protegidos no versionados preexistentes intactos.

No se modifican código productivo, pruebas, A1 ni la auditoría A4 original.

## 3. Contradicción resuelta

Las líneas 173–176 de la auditoría A4 declaran que una fila `generation = 1`
es compatible solo cuando su “snapshot completo, salvo el `id` autogenerado”
equivale al snapshot inicial solicitado, y que cualquier diferencia produce
`DURABLE_INCONSISTENCY`.

El snapshot descrito allí contiene:

- `public_id`;
- `dispatch_token_hash`;
- `created_at`;
- `updated_at`.

Las líneas 608–609 de A1 excluyen esos campos de
`DurableRetryInitialTransferRequest`. Son valores generados durante la
persistencia. Una reinvocación no puede reconstruir autoritativamente el
identificador, token ni instante usados por una escritura anterior.

Comparar una fila existente con nuevos valores aleatorios impediría la
convergencia idempotente a `ALREADY_TRANSFERRED`. Ignorar las diferencias sin
corregir la regla contradiría “snapshot completo” y “cualquier diferencia”.

Esta corrección elimina esa contradicción mediante dos vectores separados y
una tercera categoría de evidencia de procedencia.

## 4. Texto sustituido

Este documento sustituye normativamente, y solo para decidir compatibilidad,
las líneas 173–176 de
`docs/durable-retry-production-activation-a4-readiness-audit.md`.

Queda sin efecto la frase:

> Una fila `generation = 1` existente es compatible solo si su snapshot
> completo, salvo el `id` autogenerado, equivale al snapshot inicial
> solicitado. Cualquier diferencia produce `DURABLE_INCONSISTENCY`.

Se adopta en su lugar:

> Una fila `generation = 1` es compatible cuando existe exactamente una fila,
> todos los campos del vector de equivalencia determinista coinciden
> exactamente con la solicitud A1 y todos los campos del vector de validez
> persistente generada satisfacen sus invariantes estructurales y relacionales.
> Los campos generados no se comparan con nuevos valores aleatorios o
> temporales de una reinvocación.

Esta regla es obligatoria para:

- lectura preexistente normal;
- relectura después de duplicate key;
- relectura independiente después de escritura o commit incierto;
- recovery que necesite clasificar la misma transferencia inicial.

No puede existir una variante más permisiva para ninguna de esas rutas.

## 5. Tres categorías no intercambiables

### 5.1 Vector de equivalencia determinista

Contiene los valores transportados por A1 o derivados sin entropía ni reloj
adicional. Su igualdad exacta determina si la fila representa la misma
transferencia lógica solicitada.

### 5.2 Vector de validez persistente generada

Contiene valores creados por el repositorio durante el intento original. En una
reinvocación se validan estructural y relacionalmente, pero no se comparan
contra valores nuevos.

### 5.3 Evidencia de procedencia

Después de un commit incierto, A4 puede conservar en memoria los valores
generados por ese intento. Su coincidencia exacta en una conexión independiente
puede demostrar que la fila visible procede de esa invocación.

La evidencia de procedencia sirve para distinguir `TRANSFERRED` de
`ALREADY_TRANSFERRED`; no cambia la regla de compatibilidad. Una fila
compatible con valores generados diferentes sigue siendo compatible y
preexistente.

## 6. Vector de equivalencia determinista

El vector es cerrado y contiene exactamente:

| Campo | Autoridad | Valor esperado | Comparación | `NULL` | Diferencia |
|---|---|---|---|---|---|
| `stage` | `request->authority()` | `reconciliation` | string exacto, case-sensitive | prohibido | `DURABLE_INCONSISTENCY` |
| `subject_id` | `request->authority()` | ID positivo de `payment_reconciliations` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `completion_id` | `request->completionId()` | igual a `subject_id` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `generation` | A1 | `1` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `attempt_number` | A1 | `0` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `scheduled_for` | `request->scheduledForDatabase()` | UTC `Y-m-d H:i:s` | string canónico exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `scheduled_action_id` | estado inicial | `null` | identidad estricta con `NULL` SQL | obligatorio | `DURABLE_INCONSISTENCY` |
| `status` | estado inicial | `dispatching` | string exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `active_slot` | estado inicial | `1` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `version` | estado inicial | `1` | entero exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `reason_code` | `request->reasonCode()` | `retryable_failure` | string exacto | prohibido | `DURABLE_INCONSISTENCY` |
| `dispatched_at` | estado inicial | `null` | identidad estricta con `NULL` SQL | obligatorio | `DURABLE_INCONSISTENCY` |
| `claimed_at` | estado inicial | `null` | identidad estricta con `NULL` SQL | obligatorio | `DURABLE_INCONSISTENCY` |
| `consumed_at` | estado inicial | `null` | identidad estricta con `NULL` SQL | obligatorio | `DURABLE_INCONSISTENCY` |
| `terminal_at` | estado inicial | `null` | identidad estricta con `NULL` SQL | obligatorio | `DURABLE_INCONSISTENCY` |

`completion_id` es metadata de creación y no amplía la identidad de autoridad,
que permanece `(stage, subject_id, generation)`. Se compara porque A1 sí la
transporta y reconciliation exige `completion_id = subject_id`.

No se aceptan normalizaciones silenciosas. Los enteros hidratados desde MySQL
pueden convertirse desde su representación decimal canónica solo después de
rechazar signos, espacios, decimales, exponentes, overflow y valores vacíos.

## 7. Vector de validez persistente generada

El vector contiene exactamente:

| Campo | Formato y nulabilidad | Invariante relacional | Unicidad | Invalidez |
|---|---|---|---|---|
| `public_id` | string no nulo de 64 caracteres `[a-f0-9]` | identificador opaco generado antes del INSERT original | global mediante `durable_retry_public_unique` | `DURABLE_INCONSISTENCY` |
| `dispatch_token_hash` | string no nulo de 64 caracteres `[a-f0-9]` | SHA-256 hexadecimal de una preimagen aleatoria del intento original | no existe unique SQL; reutilización demostrada es inconsistencia | `DURABLE_INCONSISTENCY` |
| `created_at` | UTC no nulo `Y-m-d H:i:s`, segundos, sin offset escrito | `created_at <= scheduled_for` y `created_at = updated_at` en estado inicial | no aplica | `DURABLE_INCONSISTENCY` |
| `updated_at` | UTC no nulo `Y-m-d H:i:s`, segundos, sin offset escrito | igual a `created_at` en la fila inicial `version = 1` | no aplica | `DURABLE_INCONSISTENCY` |

No hay otros valores generados en la fila inicial. `id` se trata separadamente
porque es una clave técnica autoincremental, no integra ninguno de los dos
vectores.

La diferencia entre un campo generado persistido y un valor recién generado es
irrelevante para compatibilidad. No se debe generar un nuevo valor con el único
propósito de compararlo con una fila existente.

## 8. `public_id`

1. Lo genera exclusivamente el repositorio A4 para una inserción candidata.
2. Se genera antes de abrir la transacción, junto al snapshot candidato.
3. Su formato canónico es hexadecimal lowercase, exactamente 64 caracteres.
4. El alfabeto permitido es `[a-f0-9]`; la validación distingue mayúsculas.
5. Es no nulo y no vacío.
6. Debe ser globalmente único; la tabla lo garantiza mediante
   `durable_retry_public_unique`.
7. Una reinvocación no genera un `public_id` para decidir equivalencia.
8. Un `public_id` persistido diferente pero válido no impide
   `ALREADY_TRANSFERRED`.
9. Ausente, vacío, uppercase, mal formado o de longitud distinta produce
   `DURABLE_INCONSISTENCY`.
10. Una duplicidad global demostrada produce `DURABLE_INCONSISTENCY`; una
    lectura que no pueda demostrarla conserva `OUTCOME_UNCERTAIN` solo cuando
    proviene de una reconciliación posterior a efecto incierto.

## 9. `dispatch_token_hash`

1. El repositorio A4 genera una preimagen criptográficamente aleatoria para el
   intento candidato.
2. El repositorio calcula SHA-256 y persiste únicamente el hash.
3. El formato es hexadecimal lowercase `[a-f0-9]{64}`.
4. Es no nulo desde `generation = 1`.
5. La preimagen no se persiste en esta tabla, no forma parte de A1 y A4 no tiene
   permiso para recuperarla desde otro sistema.
6. A4 no expone, registra ni devuelve la preimagen.
7. Una reinvocación no genera un token para decidir equivalencia.
8. Un hash persistido diferente pero estructuralmente válido no impide
   `ALREADY_TRANSFERRED`.
9. Ausente, vacío, malformed o de longitud distinta produce
   `DURABLE_INCONSISTENCY`.
10. El schema actual no declara unicidad global para este campo. No se inventa
    una garantía SQL. Si la reutilización se demuestra mediante evidencia
    autoritativa disponible, se clasifica `DURABLE_INCONSISTENCY`; A4 no amplía
    su presupuesto con un scan global para intentar demostrarla.

## 10. Timestamps

### 10.1 Formato común

Todos los timestamps persistidos usan UTC, formato exacto
`Y-m-d H:i:s`, precisión de segundos y calendario válido. No se acepta offset,
microsegundos, zona local, fecha normalizada, valor cero ni tolerancia.

### 10.2 `created_at`

- fuente: reloj UTC del intento original de persistencia;
- no nulo;
- debe ser menor o igual que `scheduled_for`;
- en la fila inicial debe ser exactamente igual a `updated_at`;
- no se compara por igualdad con un timestamp generado en una reinvocación;
- valor inválido o relación imposible produce `DURABLE_INCONSISTENCY`.

### 10.3 `updated_at`

- fuente inicial: el mismo instante capturado para `created_at`;
- no nulo;
- en `version = 1`, `status = dispatching`, debe ser igual a `created_at`;
- no se compara por igualdad con la hora de una reinvocación;
- diferencia respecto de `created_at` o formato inválido produce
  `DURABLE_INCONSISTENCY`.

### 10.4 Timestamps de estado

`dispatched_at`, `claimed_at`, `consumed_at` y `terminal_at` pertenecen al
vector determinista inicial y deben ser `NULL`. Cualquier valor no nulo indica
que la fila no representa el snapshot inicial solicitado y produce
`DURABLE_INCONSISTENCY`.

## 11. Redefinición de snapshot inicial

“Snapshot inicial” tiene cuatro niveles:

1. **Snapshot lógico solicitado:** identidad y valores aportados o derivados
   determinísticamente desde A1.
2. **Valores deterministas de persistencia:** estado inicial, nulls, slot y
   versión exigidos por el dominio.
3. **Valores generados por la escritura:** `public_id`, token hash y timestamps
   de creación.
4. **Evidencia persistida:** fila completa relecta desde MySQL, incluido `id`.

“Snapshot completo” significa que toda columna debe estar presente y ser
validada conforme a su categoría. No significa comparar evidencia persistida
contra nueva entropía o un nuevo instante inexistentes en el request.

## 12. Tabla completa de columnas

| Campo | Origen | Determinista | En request | Igualdad | Validación estructural | Diferencia/invalidez |
|---|---|---:|---:|---:|---:|---|
| `public_id` | repositorio | No | No | No | Sí, hex lowercase 64 y unicidad SQL | `DURABLE_INCONSISTENCY` |
| `stage` | authority A1 | Sí | Sí | Sí | Sí | `DURABLE_INCONSISTENCY` |
| `subject_id` | authority A1 | Sí | Sí | Sí | entero positivo | `DURABLE_INCONSISTENCY` |
| `completion_id` | request A1 | Sí | Sí | Sí | entero positivo e igual a subject | `DURABLE_INCONSISTENCY` |
| `generation` | constante A1 | Sí | derivado | Sí | entero `1` | `DURABLE_INCONSISTENCY` |
| `attempt_number` | constante A1 | Sí | derivado | Sí | entero `0` | `DURABLE_INCONSISTENCY` |
| `scheduled_for` | request A1 | Sí | Sí | Sí | UTC canónico | `DURABLE_INCONSISTENCY` |
| `scheduled_action_id` | estado inicial | Sí | No | Sí contra `NULL` | nulabilidad | `DURABLE_INCONSISTENCY` |
| `dispatch_token_hash` | repositorio | No | No | No | Sí, SHA-256 hex lowercase 64 | `DURABLE_INCONSISTENCY` |
| `status` | estado inicial | Sí | No | Sí | `dispatching` | `DURABLE_INCONSISTENCY` |
| `active_slot` | estado inicial | Sí | No | Sí | entero `1` | `DURABLE_INCONSISTENCY` |
| `version` | estado inicial | Sí | No | Sí | entero `1` | `DURABLE_INCONSISTENCY` |
| `reason_code` | request A1 | Sí | derivado | Sí | `retryable_failure` | `DURABLE_INCONSISTENCY` |
| `dispatched_at` | estado inicial | Sí | No | Sí contra `NULL` | nulabilidad | `DURABLE_INCONSISTENCY` |
| `claimed_at` | estado inicial | Sí | No | Sí contra `NULL` | nulabilidad | `DURABLE_INCONSISTENCY` |
| `consumed_at` | estado inicial | Sí | No | Sí contra `NULL` | nulabilidad | `DURABLE_INCONSISTENCY` |
| `terminal_at` | estado inicial | Sí | No | Sí contra `NULL` | nulabilidad | `DURABLE_INCONSISTENCY` |
| `created_at` | reloj del intento original | No | No | No | UTC, `<= scheduled_for`, igual a updated | `DURABLE_INCONSISTENCY` |
| `updated_at` | reloj del intento original | No | No | No | UTC, igual a created | `DURABLE_INCONSISTENCY` |

`id` queda excluido porque MySQL lo asigna, no identifica autoridad y no puede
conocerse antes del INSERT. Debe existir, ser entero positivo y ser único como
primary key. Ausente o inválido hace corrupta la evidencia y produce
`DURABLE_INCONSISTENCY`.

## 13. Resultados exactos

### 13.1 `ALREADY_TRANSFERRED`

Se devuelve únicamente cuando:

- existe exactamente una fila para `(reconciliation, subject_id, 1)`;
- todas las columnas esperadas están presentes y legibles;
- coincide todo el vector determinista;
- es válido todo el vector generado;
- el `id` técnico es válido;
- no existe contradicción demostrada.

No se devuelve si hay cero filas, más de una fila, lectura parcial, columna
desconocida que impida validar la forma, corrupción o diferencia determinista.

### 13.2 `DURABLE_INCONSISTENCY`

Se devuelve ante evidencia autoritativa y completa de:

- diferencia en cualquier campo determinista;
- `public_id` o token hash inválido;
- timestamp inválido o relación temporal imposible;
- generación distinta cuando la evidencia se presenta como marcador inicial;
- estado, slot, versión, reason o metadata contradictoria;
- fila incompleta o con forma no reconocida;
- dos o más filas `generation = 1`;
- duplicidad o corrupción demostrada.

Una fila demostrablemente incompatible nunca se degrada a
`OUTCOME_UNCERTAIN`.

### 13.3 `OUTCOME_UNCERTAIN`

Se reserva para una escritura o commit potencialmente efectivo cuando una
lectura independiente no aporta evidencia autoritativa suficiente:

- conexión independiente no disponible;
- lectura externa fallida;
- resultado externo no legible;
- ausencia externa que no permite demostrar todavía el efecto final;
- evidencia visible únicamente en la conexión ambigua.

### 13.4 `PERSISTENCE_ERROR`

Se usa cuando el fallo de persistencia es conocido como no efectivo, el
rollback quedó confirmado y no existe autoridad durable creada por la
operación. No representa una fila preexistente incompatible ni un commit
incierto.

## 14. Reinvocación idempotente

Secuencia obligatoria:

1. llega el mismo request A1;
2. A4 construye sus valores deterministas sin generar entropía destinada solo
   a comparación;
3. bloquea la autoridad funcional;
4. lee todas las filas `generation = 1` bajo lock;
5. cero filas permite intentar el INSERT;
6. una fila exige comparar el vector determinista;
7. la misma fila exige validar el vector generado y el `id`;
8. una fila compatible devuelve `ALREADY_TRANSFERRED`;
9. diferencia o corrupción demostrada devuelve `DURABLE_INCONSISTENCY`;
10. evidencia ilegible después de un efecto incierto devuelve
    `OUTCOME_UNCERTAIN`.

La idempotencia no requiere reconstruir un secreto, identificador aleatorio ni
timestamp de persistencia.

## 15. Duplicate key

Después de un duplicate key se hace una sola relectura autoritativa y se aplica
exactamente la misma regla:

| Evidencia posterior | Resultado |
|---|---|
| Una fila determinísticamente equivalente y generada válida | `ALREADY_TRANSFERRED` |
| Una fila con diferencia determinista | `DURABLE_INCONSISTENCY` |
| Una fila corrupta o incompleta | `DURABLE_INCONSISTENCY` |
| Más de una fila | `DURABLE_INCONSISTENCY` |
| Lectura fallida o no concluyente | `OUTCOME_UNCERTAIN` |
| Ausencia inesperada con rollback confirmado y escritura demostrada no efectiva | `PERSISTENCE_ERROR` |
| Ausencia sin cierre/efecto demostrable | `OUTCOME_UNCERTAIN` |

No se reintenta el INSERT y no se modifica la fila ganadora.

## 16. Commit incierto y evidencia independiente

La conexión que ejecutó el commit incierto no constituye evidencia
confirmatoria, aunque todavía vea una fila.

Una conexión independiente, read-only y autoritativa realiza como máximo una
relectura:

| Evidencia externa | Resultado |
|---|---|
| Una fila compatible cuyos valores generados coinciden con los conservados por el intento ambiguo | `TRANSFERRED` |
| Una fila compatible con valores generados distintos | `ALREADY_TRANSFERRED` |
| Una fila determinísticamente incompatible | `DURABLE_INCONSISTENCY` |
| Una fila estructuralmente corrupta | `DURABLE_INCONSISTENCY` |
| Más de una fila | `DURABLE_INCONSISTENCY` |
| Cero filas | `OUTCOME_UNCERTAIN` |
| Lectura o conexión fallida | `OUTCOME_UNCERTAIN` |
| Evidencia solo local a la conexión ambigua | `OUTCOME_UNCERTAIN` |

La coincidencia de valores generados en esta tabla prueba procedencia, no
define compatibilidad. No se ejecuta un segundo INSERT, transacción o lock
durante la reconciliación externa.

## 17. Matriz normativa completa

| Escenario | Deterministas | Generados | Resultado |
|---|---|---|---|
| Fila plenamente compatible | iguales | válidos | `ALREADY_TRANSFERRED` |
| `public_id` diferente pero válido | iguales | válido | `ALREADY_TRANSFERRED` |
| Token diferente pero válido | iguales | válido | `ALREADY_TRANSFERRED` |
| `created_at` diferente pero válido | iguales | válido y relaciones cumplidas | `ALREADY_TRANSFERRED` |
| `updated_at` diferente pero válido | iguales | válido, igual a created | `ALREADY_TRANSFERRED` |
| Campo determinista diferente | distintos | válidos | `DURABLE_INCONSISTENCY` |
| `public_id` mal formado | iguales | inválido | `DURABLE_INCONSISTENCY` |
| Token corrupto | iguales | inválido | `DURABLE_INCONSISTENCY` |
| Timestamp inválido | iguales | inválido | `DURABLE_INCONSISTENCY` |
| `created_at > scheduled_for` | iguales | relación inválida | `DURABLE_INCONSISTENCY` |
| `created_at != updated_at` inicial | iguales | relación inválida | `DURABLE_INCONSISTENCY` |
| Dos filas generation 1 | cualquier valor | cualquier valor | `DURABLE_INCONSISTENCY` |
| Fila incompleta preexistente | desconocidos | desconocidos | `DURABLE_INCONSISTENCY` |
| Lectura preexistente fallida antes de escribir y rollback confirmado | desconocidos | desconocidos | `PERSISTENCE_ERROR` |
| Duplicate compatible | iguales | válidos | `ALREADY_TRANSFERRED` |
| Duplicate incompatible/corrupto | distintos o desconocidos | inválidos o contradictorios | `DURABLE_INCONSISTENCY` |
| Commit incierto, fila externa compatible y procedencia exacta | iguales | válidos e iguales al intento | `TRANSFERRED` |
| Commit incierto, fila externa compatible pero otra procedencia | iguales | válidos y distintos | `ALREADY_TRANSFERRED` |
| Commit incierto, fila externa incompatible | distintos | cualquier valor | `DURABLE_INCONSISTENCY` |
| Commit incierto sin evidencia externa | desconocidos | desconocidos | `OUTCOME_UNCERTAIN` |
| Rollback o cierre no demostrado | cualquier valor | cualquier valor | `OUTCOME_UNCERTAIN` |

No quedan celdas abiertas a interpretación.

## 18. Compatibilidad con A1

La corrección:

- no agrega campos a `DurableRetryInitialTransferRequest`;
- no cambia firmas A1;
- no transporta `public_id`, tokens ni timestamps de persistencia;
- conserva `completion_id` como metadata;
- conserva exactamente los siete resultados A1;
- conserva `generation = 1`;
- permite implementar la corrección dentro de la allowlist A4 existente.

A1 continúa siendo la autoridad del vector lógico; el repositorio es autoridad
de generación y validación de valores persistentes.

## 19. Impacto sobre la implementación A4 local

Sin modificar código en esta intervención, una corrección posterior deberá:

| Archivo | Cambio normativo obligatorio | Escenarios |
|---|---|---|
| `DurableRetryInitialTransferRepository.php` | separar comparación determinista, validación generada y evidencia de procedencia; no generar entropía solo para comparar; validar relaciones temporales; usar la misma clasificación tras duplicate y commit incierto | lectura preexistente, duplicate key y commit incierto |
| `DurableRetryInitialTransferRepository.php` | centralizar todas las salidas que requieren rollback y comprobar autoritativamente el cierre | todas las salidas no confirmatorias |
| `DurableRetryInitialTransferRepository.php` | implementar control privado de fases y clasificar cada excepción según la fase máxima alcanzada, sin cambiar firmas públicas | flujo transaccional completo |
| `durable-retry-initial-transfer-authority-test.php` | diferencias campo por campo; generated válido distinto; corrupción; procedencia exacta/distinta; lectura externa | equivalencia y procedencia |
| `durable-retry-initial-transfer-authority-test.php` | inyectar rollback `false` y excepcional con contadores de orden | fallo preescritura, lease ocupado, inelegible e inconsistencia |
| `durable-retry-initial-transfer-authority-test.php` | instrumentar contadores completos y transiciones de fase; registrar y comparar la fase máxima; probar cada operación permitida y la ausencia de cada operación prohibida; fallar ante actividad posterior a fase terminal; inyectar excepciones por fase | todos los escenarios funcionales |
| `durable-retry-initial-transfer-authority-mysql-test.php` | reinvocación con valores generados distintos; duplicate real; evidencia externa tras commit incierto | concurrencia y persistencia |
| `durable-retry-initial-transfer-authority-mysql-test.php` | confirmar rollback real y ausencia de efectos persistidos | todas las ramas de rollback ejercitables en MySQL |
| `durable-retry-initial-transfer-authority-mysql-test.php` | medir el presupuesto real de transacciones, locks, INSERT y conexiones independientes; relacionarlos con la fase máxima; permitir conexión externa solo en `COMMIT_UNCERTAIN`; demostrar cero escritura externa y cada prohibición de escenario | todos los escenarios MySQL |
| `durable-retry-initial-transfer-authority-infrastructure-test.php` | certificar que la clasificación es única y compartida entre rutas | repositorio productivo |
| `durable-retry-initial-transfer-authority-infrastructure-test.php` | impedir retornos antes del cierre y exigir tratamiento uniforme de rollback | repositorio productivo |
| `durable-retry-initial-transfer-authority-infrastructure-test.php` | aplicar guardia Git exacta de los seis archivos, staging, tracked, protegidos, artifacts y temporales | workspace completo |
| interfaz del repositorio | sin cambio | no aplica |
| servicio authority | sin cambio | no aplica |

Todos estos cambios permanecen dentro de la allowlist A4 de seis archivos.

### 19.1 Regla autoritativa de rollback

La implementación debe:

1. centralizar todas las salidas que requieren rollback;
2. ejecutar exactamente un intento de `ROLLBACK` por salida;
3. comprobar el valor retornado;
4. capturar cualquier excepción de `ROLLBACK`;
5. no devolver el resultado funcional previsto antes de confirmar el cierre;
6. tratar `ROLLBACK=false` como cierre no demostrado;
7. tratar una excepción de rollback como cierre no demostrado;
8. devolver `OUTCOME_UNCERTAIN` cuando el cierre no pueda demostrarse;
9. no reutilizar la conexión como si estuviera limpia tras un cierre incierto;
10. no ejecutar `COMMIT` después de intentar rollback;
11. no ejecutar un segundo rollback salvo exigencia normativa expresa;
12. eliminar retornos tempranos que puedan abandonar una transacción abierta.

La regla se aplica exhaustivamente a:

- registro funcional ausente;
- lease legacy vigente;
- estado funcional inelegible;
- durable duplicado;
- durable incompatible;
- durable corrupto;
- incompatibilidad detectada después de un intento de inserción;
- error seguro anterior al `INSERT`;
- cualquier excepción que abandone una transacción sin commit confirmado;
- cualquier otra salida no confirmatoria dentro de una transacción.

Ninguna de estas ramas puede quedar exceptuada por un helper, catch genérico o
retorno temprano.

### 19.2 Clasificación según el cierre

Los resultados `LEGACY_IN_FLIGHT`, `FUNCTIONALLY_INELIGIBLE`,
`DURABLE_INCONSISTENCY` y `PERSISTENCE_ERROR` solo pueden devolverse después de
demostrar el rollback o cierre correspondiente.

Un resultado funcional conocido no tiene prioridad sobre la incertidumbre del
cierre:

| Escenario | Cierre | Resultado |
|---|---|---|
| Excepción preescritura | rollback confirmado | `PERSISTENCE_ERROR` |
| Excepción preescritura | rollback falso o excepcional | `OUTCOME_UNCERTAIN` |
| Rama funcional no autorizada | rollback confirmado | resultado funcional A1 correspondiente |
| Rama funcional no autorizada | rollback falso o excepcional | `OUTCOME_UNCERTAIN` |
| Escritura potencial y cierre no demostrado | desconocido | `OUTCOME_UNCERTAIN` |

`ROLLBACK=false`, una excepción de rollback, un estado transaccional no
demostrable o la imposibilidad de acreditar el cierre producen
`OUTCOME_UNCERTAIN`.

El resultado de rollback no demuestra por sí mismo qué ocurrió con un commit
incierto anterior. Rollback y reconciliación de commit incierto son problemas
distintos.

### 19.3 Presupuesto operacional de rollback

Para una rama que requiere rollback:

- exactamente un `START TRANSACTION`;
- exactamente un intento de `ROLLBACK`;
- cero `COMMIT`;
- cero nuevos inserts después del rollback;
- cero reconciliaciones externas cuando nunca hubo escritura potencial;
- cero retries;
- cero sleeps;
- cero loops de recuperación;
- resultado funcional únicamente después de cierre confirmado.

Si rollback falla:

- se conserva exactamente un intento;
- no existe segundo rollback;
- el resultado es `OUTCOME_UNCERTAIN`;
- no se ejecuta ninguna operación posterior que suponga conexión limpia.

### 19.4 Coherencia con commit incierto

Un rollback posterior a `COMMIT=false` o a una excepción de commit no prueba
que el commit no haya ocurrido. Cuando el commit pudo tener efecto:

- continúa siendo obligatoria la reconciliación externa independiente;
- rollback no reemplaza esa reconciliación;
- la conexión ambigua no constituye evidencia autoritativa;
- un rollback fallido mantiene o refuerza `OUTCOME_UNCERTAIN`;
- no se ejecuta un segundo INSERT.

### 19.5 Regresiones de cierre obligatorias

Los harnesses deben demostrar:

- rollback confirmado tras registro funcional ausente;
- rollback confirmado tras lease legacy ocupado;
- rollback confirmado tras estado inelegible;
- rollback confirmado tras durable incompatible;
- rollback confirmado tras durable corrupto o duplicado;
- rollback `false` en cada familia relevante;
- excepción durante rollback;
- ausencia de retorno funcional antes del intento de rollback;
- ausencia de `COMMIT` posterior;
- ausencia de un segundo rollback;
- conexión no reutilizada como limpia;
- `PERSISTENCE_ERROR` solo con rollback confirmado;
- `OUTCOME_UNCERTAIN` cuando el cierre no puede demostrarse.

### 19.6 Control interno de fases

El repositorio debe mantener un estado privado, explícito y determinista. Los
nombres internos pueden variar, pero estas ocho fases deben ser distinguibles y
observables por los harnesses:

| Fase | Condición de entrada | Operaciones permitidas | Operaciones prohibidas | Clasificación de errores | Efecto sobre rollback | Efecto sobre reconciliación externa | Transiciones permitidas |
|---|---|---|---|---|---|---|---|
| `PRE_TRANSACTION` | Request recibido; validación en curso o completa; cero transacciones, locks y escrituras. | Validación cerrada del request; construcción del snapshot lógico; validación de tipos, rangos y nulabilidad; salida por request inválido antes de abrir transacción. | `START TRANSACTION`; lecturas con lock; lectura durable decisoria; `INSERT`; COMMIT; ROLLBACK; reconciliación externa; scheduling; hooks; callbacks. | Error de validación: resultado o excepción A1 exacta según contrato. Excepción interna sin transacción ni escritura: `PERSISTENCE_ERROR` solo si A1 lo permite. Nunca `OUTCOME_UNCERTAIN` por efecto de persistencia. | Prohibido: no existe transacción. | Prohibida. | Solo `TRANSACTION_STARTED`, o salida pretransaccional por entrada inválida. |
| `TRANSACTION_STARTED` | `START TRANSACTION` confirmado; ningún lock funcional o durable confirmado; ningún intento de escritura. | Iniciar adquisición del lock funcional; preparar lecturas transaccionales. | `INSERT`; COMMIT antes de las comprobaciones obligatorias; lectura durable decisoria antes del lock funcional; reconciliación externa; segundo `START TRANSACTION`. | Fallo anterior a escritura: rollback confirmado produce `PERSISTENCE_ERROR`; rollback falso o excepcional produce `OUTCOME_UNCERTAIN`. | Obligatorio para abandonar sin COMMIT; exactamente un intento cuyo retorno o excepción debe comprobarse. | Prohibida: todavía no hubo escritura posible. | `READS_AND_LOCKS`, o `CLOSE_CONFIRMED` mediante rollback confirmado. |
| `READS_AND_LOCKS` | Transacción activa; lock funcional adquirido o en proceso; lecturas funcionales y durable autorizadas; ningún `INSERT` intentado. | `SELECT ... FOR UPDATE` funcional; validar estado y lease; lectura bloqueada de `generation = 1`; clasificar evidencia compatible, incompatible, corrupta, duplicada o ausente. | Lectura durable decisoria antes del lock funcional; `INSERT` antes de terminar validaciones; COMMIT anticipado; reconciliación externa; escritura funcional o legacy; modificación del lease. | Error de lectura o lock: rollback confirmado produce `PERSISTENCE_ERROR`; cierre no confirmado produce `OUTCOME_UNCERTAIN`. Un resultado funcional conocido solo puede devolverse tras rollback confirmado. | Obligatorio ante registro ausente, lease ocupado, estado inelegible, durable incompatible, corrupto o duplicado y error de lectura; exactamente un intento comprobado. | Prohibida: no se ha intentado escribir. | `PRE_WRITE`, o `CLOSE_CONFIRMED` mediante rollback confirmado. |
| `PRE_WRITE` | Locks y lecturas completos; transferencia autorizada; ausencia durable confirmada; `INSERT` todavía no ejecutado. | Construir valores generados; preparar la sentencia; validación final anterior al único `INSERT`. | Segundo snapshot; nueva lectura decisoria no autorizada; COMMIT; reconciliación externa; retries; loops; scheduling. | Error anterior al `INSERT`: rollback confirmado produce `PERSISTENCE_ERROR`; cierre no confirmado produce `OUTCOME_UNCERTAIN`. | Obligatorio si se abandona la transferencia; exactamente un intento comprobado. | Prohibida: no hubo escritura intentada. | `WRITE_ATTEMPTED`, o `CLOSE_CONFIRMED` mediante rollback confirmado. |
| `WRITE_ATTEMPTED` | Se inició o ejecutó el único `INSERT`; puede existir efecto de escritura; queda prohibido reclasificar el fallo como preescritura. | Comprobar el resultado del `INSERT`; identificar duplicate key mediante el mecanismo autorizado; única relectura durable transaccional autorizada; clasificar fila compatible o incompatible; preparar COMMIT o cierre. | Segundo `INSERT`; retry; retorno a `PRE_WRITE`; reconciliación externa mientras una transacción no ambigua pueda resolver el efecto; scheduling; modificación de la fila vencedora. | Escritura demostrablemente fallida más rollback confirmado produce `PERSISTENCE_ERROR`; efecto no demostrable produce `OUTCOME_UNCERTAIN`; duplicate key compatible produce el resultado A1 normativo; incompatibilidad demostrada produce `DURABLE_INCONSISTENCY` solo tras cierre confirmado. | Exactamente un intento cuando la rama no vaya a COMMIT; retorno falso o excepción produce `OUTCOME_UNCERTAIN`. | Permitida como máximo una vez solo si el efecto queda incierto y la conexión original dejó de ser evidencia autoritativa; debe ser independiente y read-only. | `COMMIT_ATTEMPTED`; `CLOSE_CONFIRMED` mediante rollback confirmado; o `COMMIT_UNCERTAIN` si el efecto no puede resolverse. |
| `COMMIT_ATTEMPTED` | Comenzó el único intento de COMMIT; no existe rollback previo confirmado. | Evaluar retorno o excepción del COMMIT; marcar cierre confirmado; marcar commit incierto. | Segundo COMMIT; segundo `INSERT`; usar ROLLBACK como prueba automática de que no hubo commit; reutilizar como evidencia autoritativa la conexión ambigua. | COMMIT confirmado más fila compatible produce `TRANSFERRED` cuando esta invocación insertó o `ALREADY_TRANSFERRED` si preexistía; retorno falso o excepción transiciona a `COMMIT_UNCERTAIN`; la conexión original ambigua nunca permite clasificación definitiva. | Un rollback posterior no demuestra que el COMMIT no ocurrió; solo puede intentarse conforme a la regla normativa ya definida y no sustituye evidencia externa. | Obligatoria ante commit incierto; read-only, independiente, máximo una conexión y una reconciliación. | `CLOSE_CONFIRMED` si el COMMIT se confirma, o `COMMIT_UNCERTAIN`. |
| `COMMIT_UNCERTAIN` | COMMIT falso, excepcional o no demostrable; conexión original marcada como ambigua. | Crear como máximo una conexión externa; efectuar una única relectura read-only; aplicar sin variación la regla normal de equivalencia. | Reutilizar la conexión original como evidencia; segundo `INSERT`; segunda reconciliación; nueva transacción de escritura; locks externos; scheduling; reparación de filas. | Externa compatible produce el resultado A1 exacto según procedencia; externa incompatible, corrupta o duplicada produce `DURABLE_INCONSISTENCY`; ausencia, lectura fallida o conexión fallida produce `OUTCOME_UNCERTAIN`. | No resuelve por sí mismo la incertidumbre; retorno falso o excepción mantiene `OUTCOME_UNCERTAIN`; nunca habilita un segundo rollback. | Permitida y obligatoria exactamente una vez como intento; exclusivamente read-only e independiente. | Solo salida final tras clasificar la evidencia; nunca regresa a una fase transaccional normal. |
| `CLOSE_CONFIRMED` | COMMIT o ROLLBACK confirmado; ninguna operación transaccional pendiente. | Construir y devolver el resultado A1; liberar recursos no transaccionales. | Nuevo COMMIT; nuevo ROLLBACK; nuevo `INSERT`; nueva lectura decisoria; nueva reconciliación; reutilizar la conexión para continuar la transferencia. | Un error posterior no altera retrospectivamente el efecto transaccional confirmado ni puede exponer información interna. | Prohibido: el cierre ya fue confirmado. | Prohibida. Si la fase previa fue `COMMIT_UNCERTAIN`, no se modela `CLOSE_CONFIRMED`: se clasifica la evidencia y se sale desde aquella fase. | Terminal. |

Ninguna fase puede definirse por remisión a “las prohibiciones generales”. Las
reglas generales solo complementan estas celdas, nunca las sustituyen ni las
contradicen. Ante una diferencia prevalece la regla más restrictiva, y cada fila
debe poder implementarse sin inferencias externas.

Transiciones permitidas:

```text
PRE_TRANSACTION → TRANSACTION_STARTED
TRANSACTION_STARTED → READS_AND_LOCKS
READS_AND_LOCKS → PRE_WRITE
PRE_WRITE → WRITE_ATTEMPTED
WRITE_ATTEMPTED → COMMIT_ATTEMPTED
COMMIT_ATTEMPTED → CLOSE_CONFIRMED
COMMIT_ATTEMPTED → COMMIT_UNCERTAIN
fase transaccional → CLOSE_CONFIRMED mediante rollback confirmado
```

Queda prohibido:

- volver de `WRITE_ATTEMPTED` a una fase preescritura;
- ejecutar un segundo INSERT;
- ejecutar COMMIT después de rollback;
- ejecutar un segundo rollback;
- reutilizar la conexión en `COMMIT_UNCERTAIN`;
- clasificar como preescritura un fallo posterior a `WRITE_ATTEMPTED`;
- reconciliar externamente si nunca hubo escritura potencial;
- devolver un resultado funcional antes de `CLOSE_CONFIRMED`, salvo
  `OUTCOME_UNCERTAIN` porque el cierre no pudo demostrarse.

Matriz de clasificación por fase:

| Fase máxima | Cierre | Evidencia | Resultado |
|---|---|---|---|
| anterior a `WRITE_ATTEMPTED` | rollback confirmado | sin fila atribuible | `PERSISTENCE_ERROR` |
| anterior a `WRITE_ATTEMPTED` | no demostrado | insuficiente | `OUTCOME_UNCERTAIN` |
| `WRITE_ATTEMPTED` | rollback confirmado | escritura demostrablemente no persistida | `PERSISTENCE_ERROR` |
| `WRITE_ATTEMPTED` | efecto no demostrable | insuficiente | `OUTCOME_UNCERTAIN` |
| `COMMIT_ATTEMPTED` | commit confirmado | fila compatible del intento | `TRANSFERRED` |
| `COMMIT_UNCERTAIN` | externa compatible y procedencia exacta | autoritativa | `TRANSFERRED` |
| `COMMIT_UNCERTAIN` | externa compatible de otra procedencia | autoritativa | `ALREADY_TRANSFERRED` |
| `COMMIT_UNCERTAIN` | externa incompatible o corrupta | autoritativa | `DURABLE_INCONSISTENCY` |
| `COMMIT_UNCERTAIN` | insuficiente, ausente o fallida | no autoritativa | `OUTCOME_UNCERTAIN` |

El mecanismo cabe íntegramente como detalle privado de
`DurableRetryInitialTransferRepository.php`.

### 19.7 Contadores operacionales obligatorios

Cada escenario debe exponer contadores verificables provenientes de doubles o
conexiones controladas, nunca inferidos por búsqueda textual:

- validaciones de request;
- llamadas al servicio authority;
- llamadas al repositorio;
- `START TRANSACTION`;
- lecturas funcionales;
- locks funcionales;
- lecturas durable;
- locks durable;
- intentos de INSERT;
- inserts aceptados;
- inserts fallidos;
- duplicate key;
- relecturas posteriores al INSERT;
- intentos de COMMIT;
- commits confirmados;
- commits inciertos;
- intentos de ROLLBACK;
- rollbacks confirmados;
- rollbacks falsos;
- rollbacks excepcionales;
- conexiones externas creadas;
- lecturas externas;
- reconciliaciones externas;
- retries;
- sleeps;
- loops de recuperación;
- hooks;
- callbacks;
- scheduling;
- ejecución de processors.

El harness funcional debe fallar si falta un contador, se excede un presupuesto,
aparece una operación prohibida o la fase máxima contradice las operaciones
observadas. También debe contar exactamente una llamada al repositorio por
delegación del servicio.

El harness MySQL debe medir sobre MySQL real transacciones, locks, el único
INSERT, commits, rollbacks, conexiones independientes y visibilidad externa.
Debe distinguir evidencia observada de supuestos inferidos, demostrar ausencia
de filas parciales, una sola generation 1 en la carrera compatible y cero
escrituras posteriores del perdedor.

### 19.8 Presupuestos por escenario

Leyenda: `≤1` significa máximo uno; `1` obligatorio; `0` prohibido. Cada
alternativa de resultado indicada queda cerrada por la evidencia descrita en la
misma celda y por las matrices de las secciones 19.3, 19.4 y 19.6.

| Escenario | Resultado A1 | Fase máxima | Transacciones | Lecturas funcionales | Locks funcionales | Lecturas durable | Locks durable | INSERT | Commit | Rollback | Reconciliación externa | Operaciones prohibidas |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| request inválido | resultado o excepción A1 exacta | `PRE_TRANSACTION` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | transacción; locks; `INSERT`; COMMIT; ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| registro funcional ausente, rollback confirmado | `FUNCTIONALLY_INELIGIBLE` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| registro funcional ausente, rollback no confirmado | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| lease ocupado, rollback confirmado | `LEGACY_IN_FLIGHT` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; modificar lease; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| lease ocupado, rollback no confirmado | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; modificar lease; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| estado inelegible, rollback confirmado | `FUNCTIONALLY_INELIGIBLE` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; escritura funcional; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| durable compatible preexistente | `ALREADY_TRANSFERRED` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 1 | 1 | 0 | 1 | 0 | 0 | `INSERT`; segundo COMMIT; ROLLBACK; conexión o reconciliación externa; modificar fila; retries; sleeps; loops; hooks; scheduling; processors |
| durable incompatible, rollback confirmado | `DURABLE_INCONSISTENCY` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; reparar fila; retries; sleeps; loops; hooks; scheduling; processors |
| durable incompatible, rollback no confirmado | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; reparar fila; retries; sleeps; loops; hooks; scheduling; processors |
| durable corrupto | `DURABLE_INCONSISTENCY` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; reparar fila; retries; sleeps; loops; hooks; scheduling; processors |
| durable duplicado | `DURABLE_INCONSISTENCY` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; reparar fila; retries; sleeps; loops; hooks; scheduling; processors |
| transferencia creada | `TRANSFERRED` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 2 | 2 | 1 | 1 | 0 | 0 | segundo `INSERT`; segundo COMMIT; ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| duplicate key compatible | `ALREADY_TRANSFERRED` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 2 | 2 | 1 | 1 | 0 | 0 | segundo `INSERT`; segundo COMMIT; ROLLBACK; conexión o reconciliación externa; modificar ganadora; retries; sleeps; loops; hooks; scheduling; processors |
| duplicate key incompatible | `DURABLE_INCONSISTENCY` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 2 | 2 | 1 | 0 | 1 | 0 | segundo `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; modificar ganadora; retries; sleeps; loops; hooks; scheduling; processors |
| duplicate key no concluyente | `OUTCOME_UNCERTAIN` | `WRITE_ATTEMPTED` | 1 | 1 | 1 | ≤2 | ≤2 | 1 | 0 | ≤1 | ≤1 | segundo `INSERT`; COMMIT no sustentado; segundo ROLLBACK; segunda conexión o reconciliación; modificar ganadora; retries; sleeps; loops; hooks; scheduling; processors |
| fallo de lectura funcional, rollback confirmado | `PERSISTENCE_ERROR` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| fallo de lectura funcional, rollback no confirmado | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 1 | 0 | lectura durable; `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| fallo de lectura durable | `PERSISTENCE_ERROR` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 1 | 1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| fallo en `PRE_WRITE` | `PERSISTENCE_ERROR` | `CLOSE_CONFIRMED` | 1 | ≤1 | ≤1 | ≤1 | ≤1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| `INSERT=false`, efecto conocido | `PERSISTENCE_ERROR` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | 2 | 2 | 1 | 0 | 1 | 0 | segundo `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| excepción durante `INSERT`, efecto incierto | `OUTCOME_UNCERTAIN` | `WRITE_ATTEMPTED` | 1 | 1 | 1 | ≤2 | ≤2 | 1 | 0 | ≤1 | ≤1 | segundo `INSERT`; COMMIT no sustentado; segundo ROLLBACK; segunda conexión o reconciliación; retries; sleeps; loops; hooks; scheduling; processors |
| rollback falso preescritura | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | ≤1 | ≤1 | ≤1 | ≤1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| rollback excepcional preescritura | `OUTCOME_UNCERTAIN` | `READS_AND_LOCKS` | 1 | ≤1 | ≤1 | ≤1 | ≤1 | 0 | 0 | 1 | 0 | `INSERT`; COMMIT; segundo ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| commit confirmado | `TRANSFERRED` | `CLOSE_CONFIRMED` | 1 | 1 | 1 | ≤2 | ≤2 | 1 | 1 | 0 | 0 | segundo `INSERT`; segundo COMMIT; ROLLBACK; conexión o reconciliación externa; retries; sleeps; loops; hooks; scheduling; processors |
| commit falso, externa compatible | `TRANSFERRED` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | 1 | 1 | 0 | 1 | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda conexión o reconciliación; lock o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |
| commit falso, externa incompatible | `DURABLE_INCONSISTENCY` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | ≤1 | 1 | 0 | 1 | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda conexión o reconciliación; lock, reparación o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |
| commit falso, sin evidencia externa | `OUTCOME_UNCERTAIN` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | ≤1 | 1 | 0 | 1 | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda conexión o reconciliación; lock o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |
| excepción de commit | `OUTCOME_UNCERTAIN` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | ≤1 | 1 | 0 | 1 | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda conexión o reconciliación; lock o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |
| fallo de creación de conexión externa | `OUTCOME_UNCERTAIN` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | ≤1 | 1 | 0 | 1 intento | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda conexión o reconciliación; locks o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |
| fallo de lectura externa | `OUTCOME_UNCERTAIN` | `COMMIT_UNCERTAIN` | 1 | 1 | 1 | ≤2 | ≤2 | ≤1 | 1 | 0 | 1 | segundo `INSERT`; segundo COMMIT; ROLLBACK probatorio; segunda lectura, conexión o reconciliación; locks o escritura externa; retries; sleeps; loops; hooks; scheduling; processors |

La matriz contiene treinta escenarios independientes. La fase máxima debe
coincidir con las operaciones observadas; ninguna fila puede permitir una
operación prohibida por su fase; presupuestos, resultado, rollback y evidencia
deben coincidir con A1. Los harnesses deben fallar ante cualquier divergencia.

Presupuestos absolutos por transferencia:

- máximo un START TRANSACTION, INSERT, COMMIT, ROLLBACK, reconciliación externa
  y conexión externa;
- cero reinserciones, retries, sleeps, loops de recuperación, hooks, callbacks,
  scheduling y processors;
- cero consultas A2, A2.1 o A3;
- cero escrituras funcionales o legacy.

### 19.9 Guardia Git y filesystem exacta

El harness de infraestructura debe ejecutar y validar resultados equivalentes a:

```text
git status --short
git diff --name-only
git diff --cached --name-only
git ls-files --others --exclude-standard
git diff --check
git diff --cached --check
```

Debe comparar el inventario contra estos seis paths exactos:

```text
app/Modules/Orders/Contracts/DurableRetryInitialTransferRepositoryInterface.php
app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php
app/Modules/Orders/Services/DurableRetryInitialTransferAuthority.php
tests/manual/durable-retry-initial-transfer-authority-test.php
tests/manual/durable-retry-initial-transfer-authority-mysql-test.php
tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php
```

La guardia debe demostrar:

1. existencia de exactamente esos seis archivos A4;
2. inexistencia de un séptimo archivo A4;
3. staging vacío;
4. cero archivos tracked modificados;
5. cero archivos A4 añadidos fuera de allowlist;
6. `Application` intacto;
7. `Config` intacto;
8. schema y migraciones intactos;
9. scheduler legacy intacto;
10. worker legacy intacto;
11. A1, A2, A2.1 y A3 intactos;
12. documentos protegidos intactos;
13. exactamente 504 archivos en `artifacts/`;
14. cero temporales residuales;
15. cero índices temporales persistentes.

Debe fallar ante cualquier negación de esas quince condiciones, incluido un
séptimo archivo simulado, staging no vacío, tracked modificado, protegido
alterado o cantidad incorrecta de artifacts.

Si usa un índice temporal, debe residir fuera del índice real, limpiarse en
`finally`, no alterar staging y comprobar su eliminación.

### 19.10 Regresiones adicionales

Máquina de fases:

- excepción inyectada en cada fase;
- fase máxima observada;
- cada transición permitida y una transición prohibida;
- cero reconciliación externa antes de escritura;
- ningún fallo posterior a `WRITE_ATTEMPTED` clasificado como preescritura;
- conexión no reutilizada en `COMMIT_UNCERTAIN`.

Contadores:

- presupuesto completo por escenario;
- máximos unitarios de INSERT, commit, rollback y reconciliación externa;
- ceros de retries, sleeps, hooks, callbacks, scheduling y processors.

Allowlist:

- seis archivos exactos;
- detección de séptimo archivo simulado;
- detección de tracked modificado;
- detección de staging no vacío;
- detección de protegido alterado;
- detección de cantidad incorrecta de artifacts;
- limpieza demostrada de temporales.

Regresiones obligatorias:

- una prueba por cada una de las ocho fases;
- excepción inyectada en cada fase no terminal;
- validación de operaciones permitidas y prohibidas por fase;
- validación del efecto de rollback y reconciliación externa por fase;
- comparación de fase máxima observada contra la matriz;
- detección de una fila sin `Fase máxima`;
- detección de una fila sin `Operaciones prohibidas`;
- detección de términos abiertos en ambas columnas;
- A1 request y siete resultados;
- A2/A2.1 y separación de A4;
- A3;
- schedules y next-generation;
- reinvocación idempotente;
- concurrencia;
- duplicate key;
- commit incierto mediante conexión independiente;
- rollback confirmado, falso y excepcional en todas las familias anteriores;
- presupuesto exacto de un único intento de rollback;
- cero commit y cero reutilización normal después de rollback incierto;
- cada una de las 19 columnas persistidas.

## 20. Suficiencia de allowlist

La allowlist existente de seis archivos sigue siendo suficiente. La conexión
independiente y la clasificación pueden implementarse como detalles privados
del repositorio y sus doubles/harnesses autorizados, sin cambiar firmas
públicas ni A1.

No se autoriza modificar:

- DTOs o resultados A1;
- schema o migraciones;
- Config;
- A2, A2.1 o A3;
- wiring, scheduler o worker legacy;
- documentos previos durante la implementación.

## 21. Criterios de aceptación posteriores

A4 será recertificable únicamente cuando:

1. exista una sola función privada de clasificación semántica reutilizada por
   lectura normal, duplicate key y reconciliación externa;
2. el vector determinista se compare campo por campo;
3. el vector generado se valide sin compararlo con nueva entropía;
4. procedencia y compatibilidad permanezcan conceptos distintos;
5. MySQL real demuestre idempotencia y duplicate key;
6. commit incierto no use la conexión ambigua como prueba;
7. los siete resultados A1 permanezcan cerrados;
8. todas las ramas de rollback estén centralizadas;
9. ninguna rama ignore un rollback falso o excepcional;
10. ningún resultado funcional se devuelva antes de confirmar el cierre;
11. ninguna transacción pueda abandonarse abierta;
12. una conexión de estado incierto no vuelva al flujo normal;
13. `PERSISTENCE_ERROR` requiera cierre confirmado;
14. exista cobertura funcional de rollback falso y excepcional;
15. exista control privado de las ocho fases;
16. toda excepción pueda clasificarse por fase máxima;
17. los harnesses observen fase máxima y transición;
18. existan todos los contadores operacionales enumerados;
19. cada escenario tenga presupuesto verificable;
20. ningún contador sea sustituido por búsqueda textual;
21. infraestructura compare la allowlist Git exacta;
22. un séptimo archivo cause fallo;
23. staging no vacío o tracked modificado cause fallo;
24. alteración de protegidos o artifacts cause fallo;
25. los seis archivos de la allowlist sean el único alcance;
26. ninguna fase carezca de operaciones permitidas o prohibidas;
27. ninguna fase carezca de clasificación de errores;
28. ninguna fase carezca de regla específica de rollback;
29. ninguna fase carezca de regla específica de reconciliación externa;
30. ninguna fila de presupuesto carezca de fase máxima;
31. ninguna fila de presupuesto carezca de operaciones prohibidas;
32. ninguna de esas columnas use términos abiertos;
33. ningún escenario combine fases incompatibles;
34. la matriz no contradiga la tabla de fases.

Esta ampliación preserva sin cambio los quince campos deterministas, los cuatro
campos generados, el catálogo de siete resultados, la regla de equivalencia,
rollback centralizado, duplicate key, commit incierto, evidencia externa,
presupuestos absolutos, guardia Git/filesystem, compatibilidad con A1 y
suficiencia de la allowlist.

## 22. Veredicto final

**A4 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA DE EQUIVALENCIA.**

El vector determinista está cerrado, el vector generado está cerrado, todos los
resultados relevantes están definidos, A1 no requiere cambios y la allowlist
de seis archivos continúa siendo suficiente.
