# VeciAhorra 28.7.4.6.7 — Fulfillment Completion Processor

## 1. Propósito y límite

`FulfillmentCompletionProcessor` pertenece exclusivamente a la capa de
procesamiento durable. Su propósito es consumir una `BusinessCompletion`
completada y sellada, ejecutar una sola vez el fulfillment ya autorizado y
confirmar durablemente su resultado.

Su responsabilidad comienza al intentar adquirir una unidad durable de trabajo
y termina al cerrar esa unidad mediante compare-and-set (CAS). Solo realiza:

1. adquirir trabajo;
2. ejecutar el fulfillment autorizado;
3. comprobar el resultado durable;
4. cerrar el procesamiento y liberar el lease.

No decide qué fulfillment corresponde, no descubre Orders y no reconstruye
autoridad. No pertenece a Payment, Checkout, Delivery Tracking ni frontend.

## 2. Autoridad de entrada

La única autoridad de negocio es una `BusinessCompletion` ya materializada cuyo
estado durable es `completed`. Se asume que contiene o referencia de forma
sellada:

- `fulfillment_method`;
- el conjunto exacto `business_completion_orders`;
- el snapshot durable íntegro;
- la identidad financiera ya validada;
- una reconciliación ya completada.

El procesador lee esos datos; nunca los completa, corrige, sustituye ni contrasta
contra fuentes anteriores. En particular, está prohibido:

- consultar Payment para decidir;
- consultar PaymentSession, PaymentOrigin o Webpay;
- consultar Checkout;
- recalcular o inferir `fulfillment_method`;
- descubrir Orders por búsquedas alternativas;
- reconstruir el snapshot desde WooCommerce u otra fuente;
- mezclar información posterior con la autoridad sellada.

Una ausencia o contradicción no autoriza una reconstrucción. Se clasifica como
fallo permanente o revisión manual según la matriz de fallos.

### 2.1 Relación exacta con 28.7.4.6.6

El hito 28.7.4.6.6 deja una prueba durable de materialización:

- `not_required` para `pickup`, sin Delivery;
- `completed` para `delivery`, con exactamente una Delivery compatible por cada
  Order del snapshot.

Ese resultado terminal es una **precondición de etapa**, no una segunda
autoridad de negocio. `DeliveryCompletion` prueba que 28.7.4.6.6 terminó, pero no
puede aportar ni reemplazar `fulfillment_method`, Orders o identidad. Estos se
leen únicamente desde `BusinessCompletion` y su snapshot sellado.

La correspondencia admisible es exacta:

| Autoridad sellada | Resultado requerido de 28.7.4.6.6 |
|---|---|
| `pickup` | `DeliveryCompletion.not_required` y ninguna Delivery materializada por esa etapa |
| `delivery` | `DeliveryCompletion.completed` y conjunto durable de Deliveries correspondiente exactamente al snapshot |

Una combinación distinta es una contradicción durable y termina en
`manual_review`. Esperar mientras 28.7.4.6.6 está en `pending`, `processing` o
`retryable` termina el intento actual como `retryable`, sin ejecutar efectos.

El Fulfillment Completion Processor no vuelve a crear Deliveries. Consume la
materialización durable de 28.7.4.6.6 y ejecuta únicamente la operación de
fulfillment definida para esta etapa. El contrato concreto de esa operación debe
ser durable, transaccional e idempotente; no puede incluir tracking, asignación,
comunicación ni otra responsabilidad excluida en este documento.

## 3. Unidad durable de procesamiento

Existe conceptualmente una sola unidad `FulfillmentCompletion` por
`BusinessCompletion`, identificada por una clave idempotente determinista. La
persistencia exacta se decidirá en el hito de implementación; este diseño no crea
tablas ni migraciones.

La unidad conserva, como mínimo, estado, cantidad de intentos, resultado,
timestamps y los atributos de lease `lease_owner`, `lease_version`,
`lease_expires_at` y `lease_acquired_at`. Sigue la misma filosofía que Payment
Reconciliation: el reloj de la base de datos determina vigencia y expiración.

La clave idempotente no incorpora datos recalculados. Deriva únicamente de la
identidad durable e inmutable de `BusinessCompletion` y de una versión estable
del propósito del procesador.

## 4. Estados y transiciones

### 4.1 Estados

| Estado | Significado |
|---|---|
| `pending` | Estado inicial; nunca se ha adquirido o está disponible para adquirir. |
| `processing` | Un owner posee un lease vigente para una versión concreta. |
| `completed` | El fulfillment autorizado y su verificación durable terminaron. Es terminal. |
| `retryable` | El intento no dejó efectos parciales y puede repetirse. |
| `permanent_failure` | La autoridad no permite completar automáticamente y repetir no cambiaría el resultado. Es terminal operativo. |
| `manual_review` | Existe una contradicción durable o resultado ambiguo que requiere intervención explícita. Es terminal automático. |

No se introduce `not_required`: esa condición pertenece a la materialización de
Delivery de 28.7.4.6.6. Para esta etapa, un `pickup` autorizado también es una
unidad de fulfillment que debe ejecutar y confirmar su operación propia, sin
crear Delivery.

### 4.2 Transiciones permitidas

| Origen | Destino | Condición |
|---|---|---|
| inexistente | `pending` | Creación idempotente de la unidad. |
| `pending` | `processing` | Adquisición CAS exitosa. |
| `retryable` | `processing` | Nuevo intento CAS exitoso. |
| `processing` expirado | `processing` | Recuperación con nuevo owner o nueva versión. |
| `processing` | `completed` | Ejecución y verificación durable exitosas; CAS final. |
| `processing` | `retryable` | Fallo transitorio tras rollback completo; CAS de cierre. |
| `processing` | `permanent_failure` | Autoridad definitivamente inválida; CAS de cierre. |
| `processing` | `manual_review` | Contradicción o ambigüedad durable; CAS de cierre. |

`completed`, `permanent_failure` y `manual_review` no se readquieren
automáticamente. Cualquier reapertura administrativa futura queda fuera de este
hito. Una ejecución sobre `completed` devuelve el resultado durable existente y
no repite efectos.

## 5. Lease

### 5.1 Adquisición

La adquisición es un UPDATE CAS único. Solo puede reclamar `pending`,
`retryable` o `processing` cuyo lease haya expirado según el reloj de la base de
datos. En una adquisición exitosa:

- establece `processing`;
- asigna un `lease_owner` no vacío e impredecible por worker;
- incrementa monotónicamente `lease_version`;
- fija adquisición y expiración usando tiempo de base de datos;
- incrementa el contador de intentos.

Cero filas afectadas significa que no se adquirió autoridad. El worker no puede
ejecutar nada.

### 5.2 Heartbeat y renovación

El heartbeat renueva solo si coinciden simultáneamente identidad de la unidad,
`processing`, `lease_owner`, `lease_version` y lease aún vigente. No revive un
lease expirado. Cero filas afectadas obliga a releer para distinguir lease
perdido, expirado o renovación idempotente sin cambio observable.

La renovación utiliza el reloj de base de datos. La duración debe dejar margen
para rollback y CAS final, pero no puede usarse como sustituto de operaciones
acotadas.

### 5.3 Expiración, recuperación y release

Un worker que detecta expiración o pérdida deja de ejecutar inmediatamente y no
puede cerrar el trabajo. Tras una caída, otro worker recupera la unidad mediante
el CAS de adquisición y recibe una `lease_version` superior. El nuevo intento
relee toda la autoridad durable; nunca confía en memoria del worker anterior.

El release normal forma parte del CAS de cierre: limpia owner y timestamps del
lease al establecer el estado final. No existe un release previo al cierre ni un
release incondicional. Si el proceso cae, la expiración permite recuperación.

## 6. Compare-And-Set final

El cierre exige en una sola escritura:

- identidad exacta de `FulfillmentCompletion`;
- estado actual `processing`;
- `lease_owner` exacto;
- `lease_version` exacta;
- lease todavía vigente;
- transición de destino permitida.

La escritura fija resultado y timestamps, cambia el estado y limpia el lease.
Cero filas afectadas nunca equivale a éxito: se considera pérdida de autoridad,
se revierte la transacción y el worker no publica `completed`.

La versión monotónica evita ABA: aunque un owner anterior reaparezca después de
una expiración, su versión ya no coincide. La combinación owner-versión-vigencia
impide cierres de workers concurrentes y cierres inconsistentes.

## 7. Transacción y orden de locks

Después de adquirir el lease, toda ejecución que pueda producir efectos se hace
en una única transacción InnoDB. El orden global es:

1. `FulfillmentCompletion` propia, por clave primaria, `FOR UPDATE`, validando
   owner, versión y vigencia;
2. `BusinessCompletion`, por clave primaria, `FOR UPDATE` o lectura bloqueada;
3. filas de `business_completion_orders`, siempre en `order_id` ascendente;
4. `DeliveryCompletion` de 28.7.4.6.6, por clave primaria;
5. para `delivery`, Orders exactas del snapshot, en `order_id` ascendente;
6. para `delivery`, Deliveries exactas, en `order_id` ascendente;
7. registros propios del efecto durable de fulfillment, en el mismo orden
   determinista de `order_id` cuando existan efectos por Order;
8. verificación durable;
9. CAS final de `FulfillmentCompletion`.

No se adquieren locks para fuentes no autoritativas. Ningún paso toma un lock de
orden inferior después de haber tomado uno superior. Las colecciones se bloquean
ordenadas y nunca en el orden accidental devuelto por una consulta. Esto reduce
ciclos de espera entre workers; el lock inicial serializa intentos para la misma
unidad y el orden por `order_id` evita inversión entre unidades relacionadas.

Si otro componente necesita las mismas filas, debe respetar este orden global.
Un deadlock detectado por la base de datos provoca rollback total y resultado
`retryable`; nunca se reintenta parcialmente dentro de la transacción fallida.

## 8. Algoritmo conceptual

1. Asegurar idempotentemente la unidad de trabajo.
2. Si está `completed`, devolver el resultado durable sin ejecutar.
3. Si es terminal no exitoso, devolver su estado sin adquirir.
4. Adquirir lease mediante CAS; si no se adquiere, no ejecutar.
5. Abrir transacción y bloquear en el orden definido.
6. Revalidar owner, versión y vigencia.
7. Verificar que `BusinessCompletion` está `completed` y que el snapshot está
   presente, íntegro y sellado. Verificar significa comprobar persistencia y
   consistencia interna; nunca reconstruir.
8. Verificar la precondición terminal correspondiente de 28.7.4.6.6.
9. Ejecutar el efecto durable autorizado usando exclusivamente los valores del
   snapshot.
10. Releer bajo lock y comprobar el conjunto exacto esperado, identidades y
    ausencia de duplicados. La cardinalidad por sí sola no basta.
11. Cerrar `completed` mediante CAS dentro de la misma transacción.
12. Commit. Solo después del commit se informa éxito.

Para `pickup`, los pasos 8 a 10 nunca crean, modifican ni buscan crear una
Delivery. Para `delivery`, solo se aceptan las Deliveries ya materializadas por
28.7.4.6.6 que correspondan exactamente al snapshot; este procesador no las
reconstruye.

## 9. Idempotencia

Ejecutar dos veces significa presentar la misma identidad de
`BusinessCompletion` al procesador, simultánea o secuencialmente. El resultado
observable debe equivaler a una única ejecución.

La demostración se apoya en cuatro barreras:

1. una sola unidad durable por clave idempotente;
2. un único lease vigente por unidad y generación;
3. efectos con identidad durable única derivada de la autoridad sellada, nunca
   de timestamps o datos redescubiertos;
4. verificación exacta y CAS final en la misma transacción que los efectos.

Si la transacción no hace commit, no queda ningún efecto. Si hace commit, también
queda `completed`. Por tanto, no existe una ventana confirmada entre el efecto y
el estado final. Una reejecución sobre `completed` solo relee. Una recuperación
tras expiración vuelve a encontrar o vuelve a producir idempotentemente el mismo
efecto bajo restricciones únicas; jamás añade un segundo efecto.

Si una integración futura no puede participar en la misma transacción durable,
no puede incorporarse directamente a este procesador: requeriría un patrón de
outbox/inbox o un hito separado. Esa integración queda fuera del alcance actual.

## 10. Invariantes

1. `BusinessCompletion` es la única autoridad de negocio.
2. Solo se procesa una `BusinessCompletion.completed`.
3. `BusinessCompletion` no cambia durante ni como consecuencia del proceso.
4. `fulfillment_method` nunca cambia ni se infiere.
5. El conjunto `business_completion_orders` nunca cambia ni se redescubre.
6. Las Orders del snapshot no cambian: el procesador no las modifica, sustituye
   ni amplía.
7. La identidad financiera no se recalcula ni revalida contra Payment.
8. `DeliveryCompletion` solo prueba finalización de 28.7.4.6.6; no aporta
   autoridad nueva.
9. `pickup` nunca materializa Delivery.
10. `delivery` no crea Deliveries en este hito; exige el conjunto exacto ya
    materializado.
11. Ningún efecto se conserva si falla la verificación o el CAS final.
12. `completed` implica efecto durable exacto, verificado y sin duplicados.
13. Cero filas afectadas en adquisición, heartbeat o cierre nunca se interpreta
    silenciosamente como éxito.
14. Un worker sin lease vigente no ejecuta ni cierra.
15. Cada nueva adquisición incrementa `lease_version`.
16. No se mezcla información nueva con el snapshot sellado.
17. No se realizan efectos externos no transaccionales.

## 11. Clasificación de fallos

`retryable` se usa únicamente cuando el rollback total está confirmado y repetir
puede resolver el problema. `permanent_failure` representa una imposibilidad
estable derivada de autoridad ausente o inválida. `manual_review` se reserva para
contradicciones, compatibilidad incierta o commit ambiguo que no debe repetirse a
ciegas.

| Caso | Resultado y tratamiento |
|---|---|
| Error antes de crear/leer la unidad | Sin efectos; retry del caller. |
| Error antes de adquirir lease | Sin efectos; no se cambia estado; retry con backoff. |
| Lease no adquirido | No ejecutar; otro worker tiene autoridad; retry con backoff. |
| Error después del lease y antes de transacción | Sin efectos; cerrar `retryable` por CAS si aún se posee; de lo contrario dejar expirar. |
| Error al abrir transacción | Sin efectos; `retryable`, cierre CAS si sigue siendo seguro. |
| BusinessCompletion ausente o no completada cuando fue presentada como definitiva | Rollback; `permanent_failure` si la referencia es imposible, o `retryable` si la etapa anterior aún no terminó. |
| Snapshot ausente o estructuralmente inválido | Rollback; `permanent_failure` si falta autoridad requerida; `manual_review` si existe contradicción durable. Nunca reconstruir. |
| 28.7.4.6.6 aún no terminal | Rollback; `retryable`; no ejecutar. |
| Resultado 28.7.4.6.6 incompatible con `fulfillment_method` | Rollback; `manual_review`. |
| Delivery faltante, duplicada o incompatible para `delivery` | Rollback; `manual_review`; no crear ni reparar. |
| Delivery presente para una materialización `pickup` atribuible a esta etapa | Rollback; `manual_review`; no borrar. |
| Error durante heartbeat | Detener nuevas operaciones; si no puede demostrarse lease vigente, rollback y no cerrar. |
| Heartbeat expirado o owner/version perdidos | Rollback; autoridad perdida; recuperación por otro worker. |
| Timeout SQL o deadlock | Rollback total; `retryable` solo si la base confirma rollback. |
| Error SQL antes del commit | Rollback total; `retryable`; ningún efecto parcial. |
| Fallo de verificación posterior | Rollback total; `retryable` si es transitorio, `manual_review` si hay contradicción durable. Nunca `completed`. |
| Cero filas en CAS final | Rollback total; lease perdido; no informar éxito. |
| Caída del worker antes de efectos | La transacción se revierte; el lease expira y otro worker recupera. |
| Caída durante efectos | La conexión cerrada revierte la transacción; recuperación tras expiración. |
| Caída después de CAS pero antes de commit | CAS y efectos se revierten juntos; recuperación idempotente. |
| Caída después de commit | `completed` y efectos ya son durables; reejecución solo relee. |
| Commit con respuesta ambigua | No reintentar a ciegas; releer con una nueva conexión. Si no puede demostrarse resultado único, `manual_review`. |
| Dos workers concurrentes | Solo uno adquiere la generación vigente; el otro no ejecuta. |
| Reentrega duplicada del mismo trabajo | Misma clave; terminal replay o contención de lease; sin efectos duplicados. |
| Duplicidad durable preexistente | Rollback; `manual_review`; no consolidar ni borrar automáticamente. |
| Crash entre operaciones | Al estar todas dentro de una transacción, rollback completo o commit completo; nunca estado intermedio durable. |
| Error al cerrar estado de fallo | No liberar incondicionalmente; dejar expirar. El siguiente worker reclasifica desde autoridad durable. |

## 12. Alcance negativo

Quedan explícitamente fuera:

- Delivery Tracking;
- Courier Assignment;
- notificaciones;
- emails;
- push;
- webhooks;
- frontend;
- endpoints REST;
- Panel Cliente;
- Panel Courier;
- Analytics;
- eventos;
- métricas;
- logs funcionales;
- cambios en Payment, Checkout, Webpay o WooCommerce;
- creación o modificación de BusinessCompletion;
- reconstrucción de Orders o Deliveries;
- cualquier responsabilidad distinta del procesamiento durable del fulfillment.

Los errores técnicos mínimos necesarios para operación segura no constituyen un
flujo funcional ni autorizan telemetría de negocio dentro del componente.

## 13. Criterios para la futura implementación

La implementación futura será conforme solo si demuestra mediante pruebas:

- adquisición exclusiva y recuperación de lease expirado;
- heartbeat condicionado por owner, versión y vigencia;
- protección anti-ABA;
- orden determinista de locks;
- rollback de todos los efectos ante cualquier fallo previo al commit;
- CAS final dentro de la transacción de efectos;
- replay terminal sin ejecución;
- conjunto exacto, no solo cardinalidad;
- cero efectos duplicados bajo concurrencia, caída y reintento;
- ausencia de dependencias y responsabilidades excluidas.

Este apartado define criterios, no autoriza código, tablas, migraciones ni APIs en
el presente hito.

## 14. Auditoría arquitectónica final

Se aplicó una primera revisión buscando mezcla de responsabilidades,
reconstrucción de autoridad, dependencias innecesarias, ambigüedades e
idempotencia incompleta. Se detectó una ambigüedad potencial entre la autoridad
de negocio (`BusinessCompletion`) y la prueba de etapa de 28.7.4.6.6
(`DeliveryCompletion`). Se corrigió estableciendo que:

- `BusinessCompletion` es la única fuente de método, Orders e identidad;
- `DeliveryCompletion` es únicamente una precondición terminal;
- ninguna discrepancia habilita inferencia o reparación.

La segunda revisión comprobó cada sección contra las invariantes y el alcance
negativo. Resultado: sin observaciones pendientes.

- Responsabilidades mezcladas: ninguna.
- Reconstrucción o redescubrimiento de autoridad: ninguno.
- Dependencias innecesarias: ninguna.
- Inferencia de información sellada: ninguna.
- Ventanas de efectos parciales: ninguna; efectos, verificación y CAS comparten
  transacción.
- Riesgo de doble ejecución o ABA: cubierto por clave única, lease y versión.
- Ambigüedad en pickup: resuelta; nunca crea Delivery.
- Responsabilidades posteriores: expresamente excluidas.
