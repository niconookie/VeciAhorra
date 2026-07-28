# Serie 37.4.3.1 — Autoridad durable y correlacionable de retries

## 1. Estado y alcance

Este documento diseña una autoridad durable para la programación técnica de
retries de las etapas que participan en la lectura administrativa de Orders.
Parte de `main` en
`b3e8967ac144b876026df157e54b584ec7a14486`.

Es exclusivamente documental. No introduce esquema, migraciones, código,
consultas, endpoints, UI, adaptadores ni cambios de datos. En particular:

- `OrderAdminActionPolicy` sigue cerrando con `insufficient_facts` cuando no
  recibe un `next_retry_at` durable;
- `mutable_actions` continúa siendo `[]`;
- no se publica ninguna capacidad mutable;
- no se cambia todavía el presupuesto certificado de tres statements del GET
  de detalle.

## 2. Resumen ejecutivo

Se recomienda una **tabla central local de programación durable**, coordinada
con Action Scheduler mediante un patrón outbox y una referencia externa. Es
una combinación controlada de las alternativas B y D:

- la tabla local es la única autoridad de identidad, generación, vigencia,
  estado y `scheduled_for`;
- Action Scheduler es autoridad únicamente de la entrega técnica de una acción;
- `scheduled_action_id` correlaciona ambos sistemas, pero no convierte las
  tablas internas de Action Scheduler en read model de Orders;
- el callback incluye identidad local, generación y token de dispatch, y
  revalida mediante CAS antes de tocar una etapa;
- solo un registro local `scheduled`, correlacionado y vigente puede producir
  `next_retry_at`; `dispatching` y `claimed` ocupan el slot pero no se proyectan;
- una acción externa sin correlación local ejecutable es obsoleta y no progresa.

El scheduler canónico calcula el backoff una sola vez. Persiste el instante UTC
en la autoridad local antes de que sea visible administrativamente. La lectura
no calcula ni reconstruye backoff.

## 3. Hechos certificados de partida

Ninguna tabla de etapa contiene hoy `next_retry_at`:

| Etapa | Tabla actual | Identificador procesado por el scheduler |
| --- | --- | --- |
| `reconciliation` | `payment_reconciliations` | reconciliation ID |
| `business_completion` | `business_completions` | reconciliation ID |
| `delivery_completion` | `delivery_completions` | business completion ID |
| `fulfillment_completion` | `fulfillment_completions` | business completion ID |

Action Scheduler persiste `scheduled_date_gmt`, pero actualmente:

- la etapa no conserva `action_id`;
- no hay generación, token CAS ni relación durable local;
- enqueue inmediato y retry usan los mismos hooks y argumentos;
- una acción externa puede quedar completada, fallida o cancelada sin cambiar
  el estado de la etapa;
- consultar sus tablas requeriría reglas de identidad y vigencia ajenas al read
  model;
- el callback no demuestra por sí solo que representa la generación actual.

Por ello `scheduled_date_gmt` no se adopta directamente como `next_retry_at`.

## 4. Evaluación de alternativas

### 4.1 A — Campos en cada tabla de etapa

Campos conceptuales:

```text
next_retry_at
scheduled_action_id
retry_generation
retry_schedule_version
```

Ventajas:

- lectura directa junto a la etapa;
- cardinalidad naturalmente uno a uno;
- una operación puede bloquear etapa y scheduling en la misma fila.

Desventajas:

- duplica esquema, índices, migraciones y CAS en cuatro tablas;
- BusinessCompletion y las completions posteriores pueden no existir cuando
  se programa el trabajo que las materializará;
- los identificadores que consume el scheduler no siempre son el ID de la fila
  completion;
- obliga a implementar cuatro variantes de recovery y retención;
- mezcla estado de negocio durable con detalles del transporte técnico;
- dificulta añadir una quinta etapa sin otra migración.

Decisión: descartada como modelo principal.

### 4.2 B — Tabla central de programación durable

Ventajas:

- contrato único de identidad, generación, CAS y retención;
- soporta etapas todavía no materializadas;
- permite una única regla de unicidad activa;
- conserva historial append-like de supersession y consumo;
- permite incluir todos los retries del Order en el tercer statement;
- mantiene scheduling fuera de las tablas de estado comercial.

Desventajas:

- requiere coordinación con Action Scheduler;
- exige resolver cuidadosamente el sujeto canónico por etapa;
- necesita recovery para fallos parciales entre ambos sistemas.

Decisión: recomendada.

### 4.3 C — Action Scheduler como autoridad directa

Action Scheduler conoce hook, argumentos, grupo, estado y
`scheduled_date_gmt`. Sin embargo, no conoce el contrato de la etapa:

- no distingue enqueue inicial de retry salvo que cambie el payload;
- no posee una generación de la autoridad de negocio;
- sus estados no expresan `superseded` respecto de una completion;
- una acción completada no prueba que la etapa se completó;
- una acción cancelada externamente puede dejar la etapa retryable;
- sus tablas y consultas son implementación de una dependencia;
- buscar por hook/args/grupo no resuelve por sí solo carreras ni acciones
  históricas duplicadas;
- hacerla read model directo acoplaría Orders a su almacenamiento interno.

Decisión: descartada como autoridad administrativa directa. Se conserva como
motor técnico detrás de una correlación local.

### 4.4 D — Modelo híbrido

Un modelo híbrido es aceptable solo si las autoridades quedan explícitas:

| Dato | Autoridad |
| --- | --- |
| identidad, etapa, sujeto, generación | tabla local |
| intento representado | tabla local, copiado bajo CAS desde la etapa |
| instante programado | tabla local |
| vigencia administrativa | tabla local |
| ID de acción externa | tabla local después de confirmar enqueue |
| entrega, claim y ejecución técnica | Action Scheduler |
| estado final de la etapa | repositorio canónico de la etapa |

Esta es la forma elegida para implementar la alternativa B. Action Scheduler
no puede cambiar unilateralmente `scheduled_for` ni la generación local.

## 5. Modelo de datos propuesto

Nombre propuesto:

```text
{prefix}veciahorra_durable_retry_schedules
```

Columnas:

| Columna | Tipo conceptual | Regla |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | identidad interna |
| `public_id` | CHAR/VARCHAR(64) | opaco, único; no expuesto inicialmente |
| `stage` | VARCHAR(40) | allowlist cerrada |
| `subject_id` | BIGINT UNSIGNED | ID canónico que recibe el worker |
| `completion_id` | BIGINT UNSIGNED NULL | fila de etapa si ya existe |
| `generation` | INT UNSIGNED | monotónica por `(stage, subject_id)` |
| `attempt_number` | INT UNSIGNED | snapshot del intento que originó el retry |
| `scheduled_for` | DATETIME | instante UTC calculado una vez |
| `scheduled_action_id` | BIGINT UNSIGNED NULL | correlación Action Scheduler |
| `dispatch_token_hash` | CHAR(64) | token opaco estable por generación |
| `status` | VARCHAR(24) | catálogo cerrado |
| `active_slot` | TINYINT UNSIGNED NULL | `1` mientras ocupa el slot activo |
| `version` | INT UNSIGNED | versión CAS |
| `reason_code` | VARCHAR(50) | catálogo técnico seguro |
| `created_at` | DATETIME | UTC |
| `updated_at` | DATETIME | UTC |
| `dispatched_at` | DATETIME NULL | enqueue externo confirmado |
| `claimed_at` | DATETIME NULL | callback aceptó generación |
| `consumed_at` | DATETIME NULL | acción entregada/revalidada |
| `terminal_at` | DATETIME NULL | superseded/cancelled/failed/orphaned |

No se guardan owners de leases en el contrato administrativo, payloads
financieros, PII, tokens sin hash ni nombres de clases ejecutoras.

### 5.1 Allowlist de etapas

Valores exactos:

```text
reconciliation
business_completion
delivery_completion
fulfillment_completion
```

No se aceptan etapas enviadas por cliente. Los adaptadores internos resuelven
el valor desde un comando tipado.

### 5.2 Identidad del sujeto

`subject_id` es el identificador canónico aceptado por el worker:

| Etapa | `subject_id` | `completion_id` |
| --- | --- | --- |
| reconciliation | `payment_reconciliations.id` | mismo ID |
| business_completion | `payment_reconciliations.id` | `business_completions.id` si existe |
| delivery_completion | `business_completions.id` | `delivery_completions.id` si existe |
| fulfillment_completion | `business_completions.id` | `fulfillment_completions.id` si existe |

Esto permite programar materialización ausente sin inventar una completion.
Cuando la fila aparece, `completion_id` puede enlazarse mediante CAS si todavía
es `NULL` y la identidad esperada coincide.

### 5.3 Identidad del retry

La identidad lógica es:

```text
(stage, subject_id, generation)
```

La identidad de una petición idempotente de scheduling es:

```text
(stage, subject_id, attempt_number, reason_code, expected_stage_version)
```

El fingerprint de esa petición se conserva junto al resultado. Repetir el mismo
fingerprint devuelve el registro existente; otro fingerprint requiere
supersession o rechazo explícito.

## 6. Estados

Catálogo:

| Estado | Significado |
| --- | --- |
| `dispatching` | slot local reservado; enqueue externo aún no confirmado |
| `scheduled` | acción externa correlacionada y retry vigente |
| `claimed` | callback validó identidad/generación y obtuvo CAS local |
| `consumed` | callback fue entregado y el retry dejó de estar pendiente |
| `superseded` | una generación posterior lo reemplazó |
| `cancelled` | cancelación local autorizada; no puede ejecutar |
| `failed` | no fue posible programar o entregar de forma recuperable según política |
| `orphaned` | divergencia durable no reconciliable automáticamente |

`dispatching`, `scheduled` y `claimed` usan `active_slot = 1`. Los demás usan
`active_slot = NULL`.

`claimed` no significa que la etapa se completó. `consumed` tampoco representa
éxito comercial: solo que esa programación dejó de ser vigente. El estado de la
etapa continúa en su tabla canónica.

## 7. Autoridad exacta de `next_retry_at`

`next_retry_at` para el read model es una proyección, no una columna nueva de la
etapa:

```text
retry_schedule.scheduled_for
```

solo cuando se cumplen todas estas condiciones:

```text
status = scheduled
active_slot = 1
scheduled_action_id IS NOT NULL
generation es la máxima generación vigente para stage + subject_id
completion/subject todavía corresponde al Order leído
```

Para `dispatching`, `claimed`, terminales, ausencia, duplicidad o divergencia:

```text
next_retry_at = no representable
policy reason = insufficient_facts
```

### 7.1 Semántica de NULL

- `scheduled_for` es NOT NULL: se calcula antes de crear la generación.
- `scheduled_action_id = NULL` en `dispatching` significa enqueue no
  confirmado; nunca se proyecta como retry disponible.
- ausencia de fila vigente significa “no existe hecho durable de próximo
  retry”, no “puede reintentarse ahora”.
- `completion_id = NULL` significa materialización aún ausente y solo es válido
  para las etapas cuyo sujeto upstream permite crearla.
- el DTO operacional puede omitir `next_retry_at` o conservarlo como no
  representable; no convierte ausencia en `NULL` con semántica de “sin backoff”.

## 8. Unicidad, generación y CAS

Índices mínimos:

```text
UNIQUE(public_id)
UNIQUE(stage, subject_id, generation)
UNIQUE(stage, subject_id, active_slot)
UNIQUE(scheduled_action_id) -- admite múltiples NULL
INDEX(status, scheduled_for)
INDEX(stage, completion_id, status)
INDEX(dispatch_token_hash)
INDEX(updated_at)
```

MySQL no ofrece índices parciales generales. `active_slot` implementa un slot
único: vale `1` para estados activos y `NULL` para históricos.

La generación comienza en 1 y solo aumenta. Nunca se reutiliza, incluso si la
programación anterior falló.

Toda transición actualiza:

```text
WHERE id = :id
  AND version = :expected_version
  AND generation = :expected_generation
  AND status IN (:expected_statuses)
```

y hace `version = version + 1`.

### 8.1 CAS al programar

1. Bloquear la etapa/sujeto canónico.
2. Revalidar status, lease, terminalidad e intentos.
3. Bloquear el slot `(stage, subject_id)`.
4. Reutilizar una petición idempotente equivalente o terminar la activa previa.
5. Calcular una sola vez `scheduled_for` en el scheduler autoritativo.
6. Insertar generación `dispatching`, `active_slot=1`.
7. Commit local.
8. Crear acción externa con `(schedule_id, generation, dispatch_token)`.
9. Adjuntar `scheduled_action_id` y pasar a `scheduled` mediante CAS.

La fila `dispatching` no es visible como `next_retry_at`.

### 8.2 CAS al reemplazar

En una transacción local:

1. lock de etapa;
2. lock de schedule activo;
3. revalidación;
4. `scheduled|dispatching → superseded`, liberando `active_slot`;
5. inserción de generación siguiente en `dispatching`;
6. commit.

La cancelación externa de la generación anterior es best effort. Su callback no
puede progresar porque la generación local ya no está activa.

### 8.3 CAS al consumir

El callback presenta `schedule_id`, `generation` y token:

```text
lock schedule
verify status = scheduled
verify active_slot = 1
verify generation and token
lock stage in canonical order
revalidate non-terminal, lease, attempts and relationships
scheduled → claimed
delegate only after canonical authority accepts its own lease/CAS
claimed → consumed and active_slot = NULL
```

Si la autoridad de etapa rechaza por lease activo, el callback no roba el
lease. La decisión de conservar, reprogramar o consumir debe ser un outcome
tipado del coordinador, no una actualización improvisada.

## 9. Orden de locks y transacciones

Orden global obligatorio:

```text
1. fila raíz de la autoridad upstream
2. fila completion, si existe
3. fila retry_schedule activa
4. relaciones/snapshot estrictamente necesarios
```

Nunca se bloquean tablas de Action Scheduler dentro de una transacción de
negocio. No se mantiene una transacción local abierta durante una llamada a su
API.

La creación externa usa patrón outbox de dos pasos. La ejecución usa inbox/CAS
local antes de delegar.

## 10. Coordinación con Action Scheduler

Payload cerrado propuesto:

```text
retry_schedule_id
generation
dispatch_token
```

No incluye IDs arbitrarios de etapa enviados por navegador. El callback carga
la programación local y obtiene `stage` y `subject_id` desde ella.

Hooks pueden continuar siendo específicos por etapa, pero el payload local es
la identidad decisiva. El grupo sigue siendo técnico y no es autoridad.

Action Scheduler es responsable de:

- almacenar y reclamar la acción;
- entregar el callback aproximadamente una vez;
- registrar sus propios intentos técnicos.

La autoridad local es responsable de:

- decidir si esa entrega representa la generación vigente;
- permitir o rechazar consumo;
- conservar el instante que ve administración;
- registrar supersession, cancelación y divergencias.

## 11. Fallos parciales

### 11.1 Registro local creado, acción externa no creada

Queda `dispatching`. No se proyecta `next_retry_at`. Un reconciliador:

- reintenta el mismo dispatch token de forma acotada;
- adjunta la acción encontrada por payload si la creación fue incierta;
- marca `failed` si existe evidencia concluyente de rechazo;
- marca `orphaned` si no puede demostrar ausencia o identidad.

Nunca crea una generación nueva solo por timeout.

### 11.2 Acción creada, referencia local no persistida

El payload externo contiene schedule ID, generación y token. El reconciliador
busca por esos argumentos mediante la API estable de Action Scheduler y adjunta
el action ID por CAS. Si aparecen múltiples acciones:

- selecciona ninguna automáticamente;
- cancela best effort si la política lo permite;
- marca `orphaned`;
- mantiene `insufficient_facts`.

### 11.3 Cancelación externa fallida

La fila local pasa primero a `cancelled` o `superseded` y libera el slot. Una
acción externa tardía será rechazada por generación/estado. El fallo externo se
registra para cleanup, pero no restaura vigencia.

### 11.4 Acción antigua después de reprogramar

Su generación no coincide o su fila está `superseded`. El callback devuelve un
outcome seguro `stale_generation`, no adquiere lease y no ejecuta worker.

### 11.5 Completion terminal antes de ejecutar

El callback bloquea y relee la etapa. Marca la programación `consumed` con
`reason_code=stage_already_terminal`, libera el slot y no reabre la etapa.

### 11.6 Action Scheduler completa pero el cierre local falla

La entrega puede repetirse o recovery puede observar `claimed`. La idempotencia
de generación y el CAS de la etapa impiden repetir efectos. Recovery determina
si la etapa ya progresó y termina localmente como `consumed`; si no puede
demostrarlo, usa `orphaned`.

## 12. Secuencias esperadas

### 12.1 Primer fallo retryable

```text
processor clasifica retryable
  → coordinador bloquea etapa
  → scheduler calcula scheduled_for UTC
  → inserta generación 1 / dispatching
  → commit
  → enqueue externo
  → CAS scheduled_action_id / scheduled
```

### 12.2 Programación exitosa

```text
dispatching(v1, action=NULL)
  → Action Scheduler devuelve action_id
  → UPDATE ... WHERE version=v1 AND status=dispatching
  → scheduled(v2, action=ID)
```

Solo después del último CAS aparece `next_retry_at`.

### 12.3 Reprogramación con backoff

```text
lock etapa + generación N
  → scheduler calcula una vez el nuevo instante
  → N pasa a superseded
  → insertar N+1 dispatching
  → commit
  → enqueue y attach
```

No se actualiza `scheduled_for` de N ni se reutiliza N.

### 12.4 Acción duplicada

```text
callback(schedule=N)
  → primer callback CAS scheduled→claimed
  → segundo callback no satisface status=scheduled
  → duplicate_delivery; cero trabajo
```

### 12.5 Acción antigua tras reprogramación

```text
callback generation=N
current active generation=N+1
  → stale_generation
  → cero lease
  → cero worker
```

### 12.6 Lease activo

```text
callback acepta generación
  → autoridad de etapa informa busy
  → no roba lease
  → outcome tipado
  → coordinador decide reprogramación nueva o consumo según contrato futuro
```

No se cambia `lease_expires_at` desde scheduling.

### 12.7 Etapa terminal antes del retry

```text
lock schedule
  → lock etapa
  → terminal
  → schedule consumed / stage_already_terminal
  → liberar active_slot
```

### 12.8 Cancelación administrativa futura

```text
autorización + versión esperada
  → lock etapa
  → lock schedule
  → scheduled|dispatching → cancelled por CAS
  → commit
  → cancelación externa best effort
  → GET autoritativo
```

Este diseño no habilita ese comando. Requiere microhito propio, capability,
auditoría e idempotencia.

### 12.9 Recuperación huérfana

```text
scan acotado de dispatching/claimed vencidos
  → buscar payload exacto en Action Scheduler
  → 0 acciones: retry dispatch o failed
  → 1 acción: attach por CAS
  → >1 o evidencia contradictoria: orphaned
```

### 12.10 Lectura administrativa

```text
statement operacional existente
  → facts de etapa
  → schedule local vigente correlacionado
  → next_retry_at = scheduled_for o ausencia no representable
  → assembler
  → resolver
  → policy privada
```

La lectura no llama Action Scheduler.

## 13. Backoff, tiempo y formato

Solo el scheduler autoritativo calcula backoff. Recibe un reloj explícito o usa
la abstracción temporal canónica del backend mutable. Devuelve un value object:

```text
RetrySchedulePlan(stage, subject, generation, attempt, scheduledForUtc)
```

El instante se persiste como MySQL `DATETIME` UTC, precisión de segundos, en
formato:

```text
Y-m-d H:i:s
```

El assembler lo normaliza a ISO 8601 UTC:

```text
Y-m-d\TH:i:s\Z
```

El read model y `OrderAdminActionPolicy` tienen prohibido:

- aplicar `30 * 2^attempt`;
- usar `last_attempt_at`;
- sumar duraciones;
- consultar `time()`, `current_time()` o “now” oculto;
- sustituir un valor ausente por el instante observado.

## 14. Consultas y rendimiento

La opción preferida mantiene el detalle en tres statements. El tercer statement
operacional puede añadir un brazo al `UNION ALL` existente:

```text
SELECT 'durable_retry_schedules', resolved_order_id, JSON_OBJECT(...)
FROM retry_schedules rs
JOIN relaciones canónicas según rs.stage y rs.subject_id
WHERE resolved_order_id IN (...)
  AND rs.active_slot = 1
```

La implementación debe evitar un `OR` de joins con cardinalidad difícil. Se
prefieren cuatro brazos `UNION ALL`, uno por etapa, dentro del mismo statement,
proyectando el mismo payload cerrado. No es una consulta por etapa: es un único
statement ya presupuestado.

Cardinalidad:

- máximo un schedule activo por `(stage, subject_id)`;
- máximo cuatro schedules candidatos por Order antes de que la selección de
  etapa de la policy aplique;
- las relaciones Checkout/Payment/BusinessCompletion existentes resuelven el
  Order sin N+1.

Índices de `stage, subject_id, active_slot` y `stage, completion_id, status`
evitan scans globales.

Alternativa de cuarta consulta:

- cargar schedules para todos los sujetos del lote en una sola consulta;
- nunca consultar por Order ni por etapa;
- presupuesto de detalle pasaría explícitamente de 3 a 4.

Se descarta inicialmente porque el `UNION` puede integrarse al statement
operacional. Si `EXPLAIN` demuestra regresión material, un microhito futuro debe
comparar ambos planes y recertificar el presupuesto; este documento no cambia
el presupuesto real.

## 15. Migración y compatibilidad

La migración futura:

1. crea la tabla vacía;
2. crea catálogos/checks soportados por el builder;
3. añade índices y uniques;
4. no altera las cuatro tablas de etapa;
5. es reversible mediante eliminación de la tabla solo antes de uso productivo;
6. una vez existan registros, rollback debe deshabilitar writers y conservar
   datos para auditoría antes de retirar lectura.

No se hará backfill desde Action Scheduler ni desde timestamps de etapa. No
existe correlación suficiente para demostrar generación e identidad.

Retries históricos permanecen sin `next_retry_at` y la policy responde
`insufficient_facts`. Solo programaciones creadas por el nuevo coordinador
adquieren autoridad.

## 16. Limpieza y retención

- estados activos no se eliminan;
- `consumed`, `superseded` y `cancelled` se conservan por un período auditable
  definido antes de implementación;
- `failed` y `orphaned` tienen retención mayor y requieren métricas/alertas;
- cleanup opera por lotes, con cutoff UTC, nunca por Order desde UI;
- logs de Action Scheduler pueden rotar independientemente sin borrar la
  autoridad local;
- no se reutilizan IDs, generaciones ni tokens tras cleanup.

La duración exacta de retención es una decisión operativa abierta; no debe
codificarse sin política de privacidad y soporte.

## 17. Observabilidad y auditoría

Eventos estructurados, sin PII:

```text
retry_schedule_created
retry_dispatch_confirmed
retry_dispatch_failed
retry_claimed
retry_consumed
retry_superseded
retry_cancelled
retry_stale_generation
retry_orphaned
```

Campos seguros:

```text
schedule public_id/fingerprint
stage
subject_id
completion_id nullable
generation
attempt_number
status anterior/nuevo
reason_code
scheduled_for
scheduled_action_id
actor tipo e ID solo para futura acción administrativa
timestamps UTC
```

Nunca tokens, payload financiero, SQL, stack traces, lease owner ni datos del
comprador.

Métricas:

- schedules activos por etapa;
- latencia `scheduled_for → claimed`;
- dispatching vencidos;
- stale callbacks;
- orphaned;
- fallos de cancelación;
- terminalidad previa a ejecución.

## 18. Seguridad y capabilities

Los workers internos usan identidad firmada por token de dispatch y CAS; no
confían en argumentos arbitrarios.

Una futura acción administrativa requiere:

- autenticación y `manage_options`;
- nonce REST;
- allowlist única `retry_durable_processing`;
- versión operacional esperada;
- idempotency key durable;
- revalidación de policy y autoridad;
- rate limit;
- auditoría sin PII;
- GET autoritativo posterior.

La tabla no se expone directamente por REST. `scheduled_action_id`,
generaciones y tokens no se aceptan desde JavaScript.

## 19. Fronteras de autoridad

| Componente | Puede | No puede |
| --- | --- | --- |
| etapa durable | decidir status, lease, attempts y terminalidad | definir UI o leer AS |
| retry scheduler | calcular backoff y crear plan | marcar completion completa |
| retry schedule local | conservar identidad, vigencia e instante | ejecutar efectos de negocio |
| Action Scheduler | entregar callback técnico | decidir generación vigente |
| policy administrativa | interpretar facts persistidos | programar, cancelar o ejecutar |
| read service | proyectar hechos | corregir o derivar backoff |
| futura fachada admin | revalidar y delegar | escribir tablas de etapa directamente |

## 20. Invariantes

1. Como máximo un slot activo por etapa y sujeto.
2. Una generación nunca disminuye ni se reutiliza.
3. Una acción obsoleta no adquiere lease ni ejecuta trabajo posterior.
4. Una etapa terminal no conserva un retry ejecutable.
5. `next_retry_at` nunca se calcula en lectura.
6. El scheduler calcula backoff una sola vez.
7. `scheduled_for` se persiste antes de ser visible.
8. Solo `scheduled` correlacionado se proyecta.
9. Fallo de enqueue no se presenta como retry programado.
10. Ausencia o divergencia produce `insufficient_facts`.
11. La policy solo consume hechos persistidos.
12. Action Scheduler no sustituye el CAS local.
13. `mutable_actions=[]` hasta un microhito explícito.
14. Este esquema no habilita por sí solo un POST.
15. Ninguna autoridad de Orders escribe las tablas de completion.

## 21. Plan de implementación por microhitos

### 21.1 37.4.3.2 — Esquema y contrato

- Alcance: schema, migración, enum/DTO de identidad y estados.
- Archivos probables: `app/Database/Schemas`, `Migrations`, DTO de scheduling.
- Pruebas: migración idempotente, índices, uniques, catálogos, rollback vacío.
- Autoridad modificable: solo nueva tabla.
- Exclusiones: Action Scheduler, Orders read model, REST.
- Rollback: eliminar tabla solo si no tiene uso/datos productivos.
- Commit selectivo: sí.

### 21.2 37.4.3.3 — Repository y CAS

- Alcance: insert dispatching, attach, claim, consume, supersede, cancel.
- Pruebas: carreras, doble insert, stale version/generation, active slot.
- Autoridad modificable: nueva tabla.
- Exclusiones: workers reales, REST, UI.
- Rollback: deshabilitar repository/writer; conservar tabla.
- Commit selectivo: sí.

### 21.3 37.4.3.4 — Planificador autoritativo

- Alcance: cálculo único de backoff con reloj explícito y plan durable.
- Pruebas: límites, determinismo, attempt limit, sin read-model derivation.
- Autoridades: scheduler y retry schedule; no completion directa.
- Exclusiones: endpoint administrativo.
- Rollback: feature flag interna y recuperación de dispatching.
- Commit selectivo: sí.

### 21.4 37.4.3.5 — Coordinación Action Scheduler

- Alcance: outbox dispatch, payload generacional, attach y reconciliación.
- Pruebas: fallos parciales, duplicados, cancelación fallida, callbacks tardíos.
- Autoridades: scheduling local y entrega técnica.
- Exclusiones: ejecución de negocio no revalidada, REST/UI.
- Rollback: detener dispatcher; schedules quedan recuperables.
- Commit selectivo: sí.

### 21.5 37.4.3.6 — Callback y adaptadores de etapa

- Alcance: inbox/CAS, orden de locks y delegación a autoridad canónica.
- Pruebas: terminal, lease activo, stale generation, doble entrega.
- Autoridades: cada adaptador solo delega; processors conservan autoridad.
- Exclusiones: administración pública.
- Rollback: desregistrar nuevos callbacks, conservar historial.
- Commit selectivo: sí, separado por adaptador si resulta necesario.

### 21.6 37.4.3.7 — Propagación read-only

- Alcance: brazo(s) del tercer statement, assembler y facts.
- Pruebas: budget=3, cardinalidad, cuatro etapas, timestamps, ausencia segura.
- Autoridad modificable: read model únicamente.
- Exclusiones: DTO REST público de acciones, UI, POST.
- Rollback: retirar proyección; scheduling sigue operando.
- Commit selectivo: sí.

### 21.7 37.4.3.8 — Integración privada con policy

- Alcance: consumir exclusivamente schedules locales correlacionados.
- Pruebas: available/backoff/insufficient, no reloj oculto, no SQL adicional.
- Autoridad: ninguna mutable.
- Exclusiones: `mutable_actions`, endpoints y UI.
- Rollback: volver a cierre `insufficient_facts`.
- Commit selectivo: sí.

### 21.8 37.4.4+ — Proyección y ejecución administrativa futura

- Alcance futuro: DTO privado, ledger de comando, REST, transporte y UI, cada
  uno en microhitos independientes.
- Pruebas: auth, nonce, idempotencia, concurrencia, accesibilidad y privacidad.
- Exclusión hasta aprobación: no publicar `retry_durable_processing`.
- Rollback: deshabilitar fachada manteniendo workers automáticos.
- Commit selectivo: sí por microhito.

## 22. Riesgos y decisiones abiertas

- política exacta de retención;
- mecanismo estable para buscar una acción por payload tras timeout;
- límites y outcomes al encontrar múltiples acciones externas;
- transacción/aislamiento requeridos para active slot bajo MySQL;
- estrategia de feature flag para rollout;
- catálogo final de `reason_code`;
- si `claimed` debe liberar el slot antes o después de adquirir lease de etapa;
- outcome correcto ante lease ocupado;
- plan `EXPLAIN` del `UNION` de lectura;
- compatibilidad de tipos de `scheduled_action_id` entre versiones de Action
  Scheduler;
- proceso operativo para resolver `orphaned`.

Estas decisiones deben cerrarse antes del código correspondiente; ninguna
autoriza derivar timestamps en Orders.

## 23. Decisión final

Se adopta como diseño una tabla central local con generación y CAS, coordinada
con Action Scheduler mediante outbox/inbox. La tabla local es autoridad de
`next_retry_at`; Action Scheduler es autoridad de entrega técnica.

No se hace backfill. Los retries históricos y cualquier programación sin
correlación durable continúan como `insufficient_facts`. El GET puede conservar
tres statements integrando la proyección en el statement operacional, sujeto a
certificación de plan y cardinalidad.

Este diseño no cambia `mutable_actions`, no habilita endpoint y no concede a
Orders autoridad para ejecutar o modificar completions.
