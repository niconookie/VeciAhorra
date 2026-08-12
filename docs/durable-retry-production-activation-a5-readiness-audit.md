# Auditoría de implementabilidad A5 — productor exclusivo de autoridad inicial

## 1. Veredicto ejecutivo

A5 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA

A1, A2, A2.1, A3 y A4 ofrecen datos y resultados suficientes para implementar
un selector determinista que clasifique primero la autoridad, evalúe activación
solo para autoridad legacy e invoque A4 como máximo una vez. No es necesario
modificar sus contratos.

La implementación no está autorizada todavía porque
`docs/durable-retry-production-activation-composition-spec.md` define A5 como un
productor de *schedule* con seis dependencias que agenda legacy y coordina
scheduling durable (líneas 176-278, 506-575 y 838-867). Eso contradice el
alcance auditado aquí: A5 decide autoridad, no agenda, no coordina y no ejecuta
procesamiento. La corrección mínima debe sustituir esas responsabilidades,
FQCN, resultados y allowlist antes de escribir código A5.

Además, la exclusividad productiva completa no puede quedar contenida solo en
A5: el materializador agenda legacy directamente, recovery vuelve a producir
trabajo legacy y el worker adquiere lease sin consultar autoridad durable. La
integración y las guardias legacy deben permanecer en microhitos separados ya
reservados por la especificación de composición.

## 2. Base Git verificada

- Rama: `main`.
- HEAD: `43f072b2948359658d55704f4bda0cad0751aab2`.
- Divergencia respecto de `origin/main`: `0` atrás / `40` adelante.
- Staging: vacío.
- Cambios tracked: cero.
- Schema: `0.24.0`.
- `artifacts/`: 504 archivos.
- Temporales: cero.
- Índices temporales: cero.
- El documento A5 no existía al comenzar.
- Los archivos no rastreados preexistentes se preservaron.

## 3. Fuentes inspeccionadas

### 3.1 Normativa

- `docs/durable-retry-production-activation-a1-contracts-spec.md`.
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`.
- `docs/durable-retry-production-activation-configuration-source-spec.md`.
- `docs/durable-retry-production-activation-a3-normative-correction.md`.
- `docs/durable-retry-production-activation-a4-readiness-audit.md`.
- `docs/durable-retry-production-activation-a4-equivalence-normative-correction.md`.
- `docs/durable-retry-production-activation-composition-spec.md`.
- `docs/durable-retry-production-activation-design.md`.
- `docs/durable-retry-production-wiring-design.md`.

### 3.2 Código y contratos

- identidad A1: `DurableRetryAuthorityIdentity`;
- request A1/A4: `DurableRetryInitialTransferRequest`;
- resultado y razones A4: `DurableRetryInitialTransferResult` y
  `DurableRetryInitialTransferReason`;
- política A2: `DurableRetryActivationPolicyInterface` y
  `DurableRetryDeterministicActivationPolicy`;
- fuente A2.1: `DurableRetryActivationConfigurationSourceInterface`,
  `DurableRetryProductionActivationConfigurationSource` y
  `WordPressOptionDurableRetryActivationConfigurationValueReader`;
- clasificación A3: `DurableRetryLegacyExclusionInterface`,
  `DurableRetryLegacyAuthorityRepository`,
  `DurableRetryLegacyAuthorityResult` y `DurableRetryIndeterminateReason`;
- autoridad A4: `DurableRetryInitialTransferAuthorityInterface`,
  `DurableRetryInitialTransferAuthority` y
  `DurableRetryInitialTransferRepository`;
- materialización: `WebpayReconciliationMaterializer`;
- productor, recovery y worker legacy: `DurableCompletionScheduler`,
  `DurableCompletionRecovery` y `DurableCompletionWorkers`;
- grafo durable: `Application`, executor, processors, callback, hook registrar,
  coordinator y adapter de Action Scheduler;
- schema y repositorio de `durable_retry_schedules`.

## 4. Arquitectura actual demostrada

`WebpayReconciliationMaterializer::materialize()` persiste o recupera el
`payment_reconciliations.id` y llama directamente:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

en `WebpayReconciliationMaterializer.php:125`. `resume()` repite la misma
producción en la línea 212. El scheduler legacy ejecuta comprobación
`as_has_scheduled_action()` y luego `as_schedule_single_action()` en
`DurableCompletionScheduler.php:27-35`; retorna `void`.

La producción legacy no está confinada al materializador:

- `DurableCompletionRecovery::recover()` selecciona reconciliaciones pendientes
  o retryable y llama al scheduler en líneas 13-20;
- `DurableCompletionWorkers::reconciliation()` adquiere el lease antes de
  cualquier lectura A3 en líneas 30-35;
- el mismo worker reprograma reconciliation en líneas 42-45.

El grafo durable existente construye repository, coordinator, executor,
processors, callback y hooks en `Application.php:174-306`, y registra ambos
sistemas en `Application.php:360-370`. A3, A2/A2.1 y A4 todavía no están
conectados a ese grafo.

## 5. Punto exacto de integración

El punto más estrecho para una futura invocación A5 está inmediatamente después
de obtener un `reconciliationId` persistido y antes de las llamadas actuales al
scheduler:

- `WebpayReconciliationMaterializer::materialize()`, entre líneas 118-125;
- `WebpayReconciliationMaterializer::resume()`, entre líneas 205-212.

A5 debe **preceder** al productor legacy. No debe envolver
`DurableCompletionScheduler`, porque eso incorporaría scheduling a su
responsabilidad. El llamador futuro interpretará el resultado A5:

- solo `LEGACY_ALLOWED` permite solicitar scheduling legacy;
- todo resultado durable, incierto, inconsistente o fallido prohíbe fallback;
- A5 nunca agenda por sí mismo.

La modificación del materializador y su wiring no pertenece a la futura
allowlist aislada A5. La especificación ya reserva integración y wiring para un
microhito posterior.

## 6. Contradicción normativa que debe corregirse

La especificación de composición vigente establece:

1. FQCN `DurableRetryReconciliationInitialScheduleProducer`;
2. request y resultado denominados `InitialSchedule`;
3. dependencias adicionales de resolver, coordinator y scheduler legacy;
4. scheduling legacy dentro del productor;
5. coordinación durable después de `TRANSFERRED`;
6. estado `SCHEDULING_FAILED`;
7. allowlist A5 de doce archivos orientados a scheduling.

El objetivo auditado exige lo contrario:

- productor exclusivo de **autoridad**, no de schedule;
- dependencias únicamente A3, A2/A2.1 encapsulada y A4;
- cero Action Scheduler, coordinator, resolver o scheduler legacy;
- cero scheduling durable o legacy;
- el resultado solo informa qué rama puede continuar.

Ambas normas no pueden implementarse simultáneamente. La corrección normativa
mínima debe sustituir las secciones 7, 9-12, 19-24, 34, 37 y la matriz de
pruebas A5 de `durable-retry-production-activation-composition-spec.md`, sin
alterar A1-A4 ni los microhitos posteriores ya reservados.

## 7. Contrato A5 propuesto tras la corrección

### 7.1 Interfaz

FQCN:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryInitialAuthorityProducerInterface
```

Ruta:

```text
app/Modules/Orders/Contracts/
DurableRetryInitialAuthorityProducerInterface.php
```

Firma:

```php
public function produceReconciliation(
    DurableRetryInitialTransferRequest $request
): DurableRetryInitialAuthorityProductionResult;
```

La interfaz es necesaria porque el materializador futuro debe depender del
selector, los harnesses necesitan doubles y el wiring posterior no debe
construir la implementación concreta.

### 7.2 Implementación

FQCN:

```text
VeciAhorra\Modules\Orders\Services\
DurableRetryInitialAuthorityProducer
```

Ruta:

```text
app/Modules/Orders/Services/
DurableRetryInitialAuthorityProducer.php
```

Constructor completo:

```php
public function __construct(
    private readonly DurableRetryLegacyExclusionInterface $authority,
    private readonly DurableRetryActivationPolicyInterface $activation,
    private readonly DurableRetryInitialTransferAuthorityInterface $transfer
);
```

No se admiten dependencias opcionales ni métodos públicos adicionales.

### 7.3 Resultado

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryInitialAuthorityProductionResult
```

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/
DurableRetryInitialAuthorityProductionResult.php
```

API mínima:

```php
public function state(): string;
public function reason(): string;
public function authorityResult(): ?DurableRetryLegacyAuthorityResult;
public function transferResult(): ?DurableRetryInitialTransferResult;
public function permitsLegacyProduction(): bool;
public function durableAuthorityConfirmed(): bool;
public function requiresRecovery(): bool;
```

Debe ser `final`, inmutable, con constructor privado y factories que validen las
combinaciones estado/razón/resultados anidados.

## 8. Identidad de entrada

A5 debe reutilizar `DurableRetryInitialTransferRequest`; no necesita otro DTO.
Ese tipo ya fija:

- `stage = reconciliation`;
- `subject_id` mediante `DurableRetryAuthorityIdentity`;
- `completion_id = subject_id`;
- `generation = 1`;
- `attempt_number = 0`;
- `reason_code = retryable_failure`;
- `scheduled_for` UTC sin microsegundos.

El stage no se recibe como string libre. Se obtiene de
`$request->authority()` y el factory A1 lo restringe a reconciliation.

La fuente futura de `scheduledForUtc` debe ser estable entre reinvocaciones.
`PaymentReconciliation::createdAt()` existe y es evidencia persistida; usar
“ahora” de cada invocación haría que A4 clasifique una fila durable previa como
incompatible. La corrección normativa debe ordenar que el llamador convierta el
`created_at` persistido a `DateTimeImmutable` UTC sin microsegundos.

A5 no recibe porcentaje, bucket, generation, attempt, resultado financiero ni
el objeto completo `PaymentReconciliation`.

## 9. Dependencias y snapshot único

### 9.1 A3

Se invoca `DurableRetryLegacyExclusionInterface::classify()` exactamente una vez
con `$request->authority()`.

### 9.2 A2 y A2.1

A5 depende solo de `DurableRetryActivationPolicyInterface`. La implementación
A2 llama `snapshot()` exactamente una vez en
`DurableRetryDeterministicActivationPolicy.php:19-51`; por tanto A2 ya encapsula
A2.1 y A5 no debe inyectar ni consultar la fuente.

### 9.3 A4

Se invoca `DurableRetryInitialTransferAuthorityInterface::
transferReconciliation($request)` como máximo una vez.

### 9.4 Dependencias prohibidas

A5 no depende de coordinator, resolver de schedules, repository funcional,
Action Scheduler, executor, callback, hook registrar, processor registry,
processors, scheduler legacy, WordPress APIs, `wpdb`, logger, métricas ni reloj.

## 10. Catálogo cerrado de resultados

Se requiere resultado A5 propio porque A3, A2 y A4 no comparten tipo, y el
llamador necesita una decisión inequívoca sobre legacy. Los estados propuestos
son:

| Estado A5 | Razón | Conducta observable |
|---|---|---|
| `LEGACY_ALLOWED` | `activation_policy_rejected` | el llamador puede producir legacy una vez |
| `LEGACY_IN_FLIGHT` | razón A4 homónima | no producir otro legacy ni durable |
| `DURABLE_EXISTING` | `durable_authority_already_exists` | no-op durable; bloquear legacy |
| `DURABLE_CREATED` | razón A4 preservada | A4 creó generation 1; bloquear legacy |
| `DURABLE_CONVERGED` | razón A4 preservada | generation 1 compatible; bloquear legacy |
| `FUNCTIONALLY_INELIGIBLE` | razón A4 preservada | no producir ninguna rama |
| `AUTHORITY_INDETERMINATE` | razón A3 preservada | cierre seguro; bloquear ambas ramas |
| `DURABLE_INCONSISTENCY` | razón A4 preservada | cierre seguro y recovery/intervención |
| `CONFIGURATION_INVALID` | reason code A2/A2.1 preservado | cero A4 y cero fallback |
| `PERSISTENCE_ERROR` | razón A4 preservada | fallo conocido; cero fallback |
| `OUTCOME_UNCERTAIN` | razón A4 preservada | efecto no demostrable; cero fallback |
| `OPERATIONAL_FAILURE` | código A5 `dependency_failure` | excepción no catalogada; cero fallback |

No se incluye `SCHEDULING_FAILED`: A5 no agenda. `LEGACY_ALLOWED` es el único
estado cuyo `permitsLegacyProduction()` retorna `true`.

`CONFIGURATION_INVALID` cubre `INVALID_VALUE`, `INVALID_PERCENTAGE`,
`UNSUPPORTED_ALGORITHM_VERSION` e `INVALID_CONFIGURATION_SNAPSHOT`.
`SOURCE_UNAVAILABLE` y excepciones inesperadas se clasifican
`OPERATIONAL_FAILURE`; nunca se convierten en porcentaje cero.

## 11. Algoritmo exacto

1. Validar que el request sea A1 válido. Un fallo contractual termina como
   excepción A1; ninguna dependencia se invoca.
2. Invocar A3 una vez con `request->authority()`.
3. Si A3 retorna `durable`, devolver `DURABLE_EXISTING`; A2 y A4 no se invocan.
4. Si A3 retorna `indeterminate`, devolver `AUTHORITY_INDETERMINATE` con su
   reason code; A2 y A4 no se invocan.
5. Solo si A3 retorna `legacy`, invocar A2 una vez.
6. A2 `false`: devolver `LEGACY_ALLOWED`; A4 no se invoca.
7. Excepción catalogada de configuración A2/A2.1: devolver
   `CONFIGURATION_INVALID`; no legacy y no A4.
8. Excepción de fuente o dependencia no catalogada: devolver
   `OPERATIONAL_FAILURE`; no fallback.
9. A2 `true`: invocar A4 exactamente una vez con el mismo request.
10. Mapear A4 sin reinterpretar:
    - `TRANSFERRED` → `DURABLE_CREATED`;
    - `ALREADY_TRANSFERRED` → `DURABLE_CONVERGED`;
    - `LEGACY_IN_FLIGHT` → `LEGACY_IN_FLIGHT`;
    - `FUNCTIONALLY_INELIGIBLE` → `FUNCTIONALLY_INELIGIBLE`;
    - `DURABLE_INCONSISTENCY` → `DURABLE_INCONSISTENCY`;
    - `PERSISTENCE_ERROR` → `PERSISTENCE_ERROR`;
    - `OUTCOME_UNCERTAIN` → `OUTCOME_UNCERTAIN`.
11. Terminar. No existe segundo classify, segunda decisión, segundo A4,
    scheduling, retry, sleep ni loop.

A3 precede A2 porque una autoridad durable es permanente y no debe volver a
someterse a porcentaje; también evita una lectura física A2.1 innecesaria.

## 12. Matriz de decisión

| A3 | A2 | A4 | Resultado A5 | Legacy permitido |
|---|---|---|---|---:|
| durable | no invocado | no invocado | `DURABLE_EXISTING` | no |
| indeterminate | no invocado | no invocado | `AUTHORITY_INDETERMINATE` | no |
| legacy | false | no invocado | `LEGACY_ALLOWED` | sí |
| legacy | configuración inválida | no invocado | `CONFIGURATION_INVALID` | no |
| legacy | fallo de fuente/inesperado | no invocado | `OPERATIONAL_FAILURE` | no |
| legacy | true | transferred | `DURABLE_CREATED` | no |
| legacy | true | already transferred | `DURABLE_CONVERGED` | no |
| legacy | true | legacy in flight | `LEGACY_IN_FLIGHT` | no |
| legacy | true | functionally ineligible | `FUNCTIONALLY_INELIGIBLE` | no |
| legacy | true | inconsistency | `DURABLE_INCONSISTENCY` | no |
| legacy | true | persistence error | `PERSISTENCE_ERROR` | no |
| legacy | true | outcome uncertain | `OUTCOME_UNCERTAIN` | no |

## 13. Presupuesto operacional

Por invocación A5:

| Operación | Máximo |
|---|---:|
| clasificación A3 | 1 |
| consultas físicas A3 | 1 |
| decisiones A2 | 1, solo si A3 legacy |
| snapshots A2.1 | 1, dentro de A2 |
| lecturas físicas de option | 1 |
| invocaciones A4 | 1, solo si A3 legacy y A2 true |
| transacciones indirectas A4 | 1 |
| locks funcionales indirectos A4 | 1 |
| inserts durable indirectos A4 | 1 |
| commits indirectos A4 | 1 |
| rollbacks indirectos A4 | 1 |
| llamadas al productor legacy | 0 |
| scheduling legacy o durable | 0 |
| coordinator, executor o processors | 0 |
| SQL directo A5 | 0 |
| hooks, logs, métricas o eventos | 0 |
| retries, loops o sleeps | 0 |
| escritura funcional | 0 |

Las escrituras indirectas solo pueden ocurrir dentro de A4. A5 no abre ni
administra su transacción.

## 14. Concurrencia y fallos

| Caso | Resultado A5 | ¿Legacy continúa? | ¿A4? | Máximo de escrituras | Idempotencia / razón |
|---|---|---:|---:|---:|---|
| invocación única, flag false | `LEGACY_ALLOWED` | sí, por llamador | 0 | 0 | `activation_policy_rejected` |
| invocación única, flag true | `DURABLE_CREATED` | no | 1 | 1 | razón `INITIAL_TRANSFER_CREATED` |
| reinvocación tras durable | `DURABLE_EXISTING` | no | 0 | 0 | marcador generation 1 |
| dos productores concurrentes | created + converged o durable existing | no | ≤1 cada uno | una fila total | lock A4 + unique |
| legacy toma lease primero | `LEGACY_IN_FLIGHT` | no nueva producción | 1 | 0 durable | `LEGACY_CLAIM_IN_FLIGHT` |
| A4 confirma antes que legacy | `DURABLE_CREATED` | no | 1 | 1 | worker futuro debe bloquearse |
| duplicate key compatible | `DURABLE_CONVERGED` | no | 1 | ≤1 intento | razón A4 preservada |
| duplicate key incompatible | `DURABLE_INCONSISTENCY` | no | 1 | ≤1 intento | razón A4 preservada |
| fallo antes de A4 | config u operational failure | no | 0 | 0 | reason code de dependencia |
| fallo dentro de A4 con rollback | `PERSISTENCE_ERROR` | no | 1 | ≤1 | razón A4 |
| commit confirmado | created/converged | no | 1 | ≤1 | autoridad durable |
| commit incierto, fila visible compatible | created/converged según A4 | no | 1 | ≤1 | evidencia externa A4 |
| commit incierto, fila incompatible | `DURABLE_INCONSISTENCY` | no | 1 | ≤1 | evidencia A4 |
| commit incierto, fila ausente | `OUTCOME_UNCERTAIN` | no | 1 | ≤1 | sin fallback |
| configuración cambia durante invocación | una snapshot única | según resultado | ≤1 | ≤1 | decisión estable por invocación |
| A3 indeterminado | `AUTHORITY_INDETERMINATE` | no | 0 | 0 | reason A3 |
| dependencia lanza inesperada | `OPERATIONAL_FAILURE` | no | ≤1 según punto | ≤1 | causa no expuesta al resultado |

## 15. Exclusividad legacy/durable

A5 garantiza localmente:

- una sola decisión A3→A2→A4;
- máximo una invocación A4;
- ausencia de fallback legacy después de A4;
- cierre seguro de incertidumbre e inconsistencia;
- reinvocación convergente mediante A3/A4;
- decisión legacy explícita solo con A3 legacy y A2 false.

A5 **no puede** garantizar por sí solo exclusividad productiva global:

1. el materializador actual agenda sin A5;
2. recovery agenda reconciliaciones directamente;
3. el worker adquiere lease sin consultar A3;
4. `as_has_scheduled_action()` y el enqueue no forman una transacción con A4;
5. una acción legacy ya creada puede ejecutar después de confirmar generation 1.

Por eso, aunque A5 aislado sea implementable tras corregir su norma, no puede
recibir tráfico hasta que los microhitos de guardias legacy e integración
condicionen materializer, recovery, retry y worker. Una incertidumbre jamás debe
autorizar legacy.

## 16. Allowlist propuesta para A5 aislado

### Productivos nuevos

```text
app/Modules/Orders/Contracts/DurableRetryInitialAuthorityProducerInterface.php
app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialAuthorityProductionResult.php
app/Modules/Orders/Services/DurableRetryInitialAuthorityProducer.php
```

### Productivos modificados

Ninguno.

### Pruebas funcionales

```text
tests/manual/durable-retry-initial-authority-producer-test.php
```

### Pruebas de infraestructura

```text
tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php
```

### Pruebas MySQL

Ninguna nueva: A5 no usa SQL. Se repiten los harnesses MySQL A3 y A4.

### Integraciones existentes a ampliar

Ninguna dentro de A5 aislado. Materializer, recovery, worker, scheduler,
`Application` e integraciones productivas pertenecen a los microhitos
posteriores ya reservados.

Total A5 propuesto: cinco archivos nuevos, cero modificados.

## 17. Plan de harnesses

### 17.1 Funcional

`durable-retry-initial-authority-producer-test.php` debe probar:

- catálogo completo de doce estados;
- reason codes exactos y resultados A3/A4 anidados;
- orden A3 antes que A2 y A2 antes que A4;
- A2 solo para legacy;
- snapshot A2.1 exactamente uno mediante policy real y reader double;
- A4 cero o uno, nunca dos;
- mismo request A1 delegado sin reconstrucción;
- configuración inválida y source unavailable sin fallback;
- excepción A3, A2 y A4;
- cero scheduler, coordinator, executor, processor, hooks y SQL;
- reinvocación con durable;
- dos invocaciones convergentes usando double A4 stateful;
- `permitsLegacyProduction()` true únicamente para `LEGACY_ALLOWED`.

Todos los doubles deben contar llamadas y registrar un journal ordenado.

### 17.2 Infraestructura

`durable-retry-initial-authority-producer-infrastructure-test.php` debe
certificar:

- FQCN, final, firmas y constructor exactos;
- solo tres dependencias;
- ningún acceso directo A2.1;
- cero referencias a Action Scheduler, coordinator, resolver, executor,
  callbacks, hooks, processors, `wpdb`, logger o scheduler legacy;
- ausencia de loops, sleeps y retries;
- allowlist exacta de cinco archivos;
- staging vacío, tracked intacto, protegidos intactos, 504 artifacts y cero
  temporales.

### 17.3 Regresiones

Repetir A1, A2, A2.1, A3, A4, schedules, next-generation, cuatro integraciones
de executors y la suite Durable Retry aislada completa. Los harnesses A3/A4
MySQL conservan la certificación de concurrencia y persistencia indirecta.

## 18. Riesgos y ambigüedades

1. **Contradicción A5 vigente:** schedule producer versus authority producer.
2. **Timestamp estable:** la norma actual del request no fija qué valor entrega
   el futuro llamador; debe cerrarse a `payment_reconciliations.created_at`.
3. **Recovery legacy:** puede producir trabajo sin pasar por A5.
4. **Worker legacy:** adquiere lease antes de consultar autoridad.
5. **Retry legacy:** puede volver a agendar después de una transferencia.
6. **Scheduler void:** no ofrece resultado contractual de deduplicación.
7. **Ventana config/race:** dos invocaciones con snapshots distintas pueden
   seleccionar ramas distintas; A4 serializa el lease, pero una acción legacy
   ya en cola requiere guardia de ejecución.
8. **Wiring actual:** A2/A2.1/A3/A4/A5 no están registrados en `Application`.
9. **Resultado A4:** es suficiente para A5; no debe ampliarse.
10. **A1-A4:** no contienen contradicción interna que obligue a modificarlos.

## 19. Exclusiones

A5 aislado no incluye:

- modificación del materializador;
- registro o wiring global;
- scheduler legacy o durable;
- coordinator externo;
- Action Scheduler;
- guards de recovery, retry o worker legacy;
- callbacks, hooks o processors;
- rollout, canario o porcentaje real en tráfico;
- observabilidad, panel operacional o alertas;
- eliminación de legacy;
- schema, migraciones o `Config`;
- generaciones posteriores o ejecución durable.

No se redefine la numeración de microhitos posteriores: se conserva la ya
versionada en la especificación de composición.

## 20. Secuencia de implementación autorizable

1. Versionar una corrección normativa de la especificación de composición que
   redefina A5 como authority producer sin scheduling.
2. Recertificar FQCN, catálogo, timestamp estable, presupuesto y allowlist.
3. Implementar los tres archivos productivos nuevos.
4. Implementar los dos harnesses A5.
5. Ejecutar regresiones A1-A4 y suite Durable Retry.
6. Versionar A5 de forma selectiva, todavía sin invocarlo.
7. Implementar las guardias legacy ya reservadas para scheduler, recovery,
   retry y worker.
8. Integrar materializer y wiring en el microhito ya reservado, con flag cero.
9. Certificar exclusividad integral antes de tráfico.

## 21. Criterio de certificación final

A5 solo será certificable cuando:

- exista la corrección normativa previa;
- FQCN, firma, request, resultado y tres dependencias coincidan exactamente;
- A3 se invoque una vez y antes de A2;
- A2/A2.1 se invoquen solo para legacy y produzcan una snapshot;
- A4 se invoque como máximo una vez;
- el catálogo tenga exactamente los estados y razones corregidos;
- solo `LEGACY_ALLOWED` permita producción legacy;
- incertidumbre, inconsistencia y fallos bloqueen fallback;
- no exista scheduling, SQL, hooks, loops, retries ni efectos laterales;
- los cinco archivos sean el único alcance;
- los harnesses y regresiones estén verdes.

La certificación de A5 aislado no autoriza tráfico: la exclusividad global exige
además las guardias legacy y la integración posterior.

## 22. Veredicto final

A5 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
