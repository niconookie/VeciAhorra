# Auditoría de preparación de composición productiva A2 + A2.1

## 1. Veredicto

**BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

A2 y A2.1 son compatibles y pueden componerse directamente entre sí, pero no
hay información normativa suficiente para conectar esa composición con tráfico
productivo. El productor consumidor sigue siendo una propuesta, su resultado no
existe, no están implementados A3, A4 ni A5 y la conducta productiva ante
excepciones de configuración o policy no está cerrada.

Implementar ahora exigiría decidir nombres, contratos, ownership de errores,
observabilidad y una frontera de integración que las autoridades reservan a
microhitos posteriores.

## 2. Base auditada

- Rama: `main`.
- Commit: `3b2027ace55c4b862b4349e5105053c2c729756b`.
- Divergencia: `0` atrás y `34` adelante de `origin/main`.
- Schema: `0.24.0` (`app/Core/Config.php:22`).
- Staging inicial: vacío.
- Modificaciones tracked iniciales: ninguna.
- Documentos protegidos untracked: 13.
- Archivos protegidos en `artifacts/`: 504.
- Especificación A2.1: 1193 líneas, 63 secciones sin contar el H1 y SHA-256
  `849F9D7749D66FE3CC55EBD97F1248A742D1E12F11FCC99608ECD10F8B7AB490`.

## 3. Autoridades documentales

Se revisaron íntegramente como autoridades principales:

- `docs/durable-retry-production-activation-design.md`;
- `docs/durable-retry-production-activation-a2-readiness-audit.md`;
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`;
- `docs/durable-retry-production-activation-configuration-source-spec.md`;
- `docs/durable-retry-production-activation-a3-configuration-source-readiness-audit.md`;
- `docs/durable-retry-production-wiring-design.md`;
- `docs/durable-retry-production-activation-a1-contracts-spec.md`;
- `docs/durable-retry-processing-lifecycle-design.md`.

La especificación A2 posterior cierra la firma que el readiness previo había
dejado abierta (`docs/durable-retry-production-activation-a2-readiness-audit.md:71-84`;
`docs/durable-retry-production-activation-a2-flag-policy-spec.md:364-389`).
La especificación A2.1 posterior cierra la fuente que la auditoría histórica
había declarado ambigua
(`docs/durable-retry-production-activation-a3-configuration-source-readiness-audit.md:3-14`;
`docs/durable-retry-production-activation-configuration-source-spec.md:161-205`).

La denominación histórica “A3” de esa auditoría no cambia la secuencia vigente:
A3 continúa reservado a lectura y clasificación del marcador
(`docs/durable-retry-production-activation-configuration-source-spec.md:25-40`).

## 4. Inventario de contratos reales

| Componente | FQCN real | Estado |
|---|---|---|
| Policy | `VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface` | Implementado |
| Policy determinista | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy` | Implementado, `final` |
| Source abstracto | `VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface` | Implementado |
| Source productivo | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource` | Implementado, `final` |
| Reader | `VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface` | Implementado |
| Reader WordPress | `VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader` | Implementado, `final` |
| Snapshot | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration` | Implementado, `final` |
| Valor crudo | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue` | Implementado, `final` |
| Identidad de cohorte | `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity` | Implementado, `final` |
| Transfer authority | `VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface` | Sólo contrato |
| Productor inicial | `DurableRetryReconciliationInitialScheduleProducer` | Sólo propuesto |
| Resultado del productor | `DurableRetryInitialScheduleResult` | Sólo propuesto |

No existe clase que implemente
`DurableRetryInitialTransferAuthorityInterface`: la búsqueda productiva sólo
encuentra su declaración. Tampoco existen el productor ni su resultado
propuestos por el diseño.

## 5. Firmas reales cerradas

La policy expone:

```php
public function allowsInitialTransfer(
    DurableRetryAuthorityIdentity $identity
): bool;
```

La firma está en
`app/Modules/Orders/Contracts/DurableRetryActivationPolicyInterface.php:9-14`
y su implementación en
`app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php:19-52`.

La fuente expone:

```php
public function snapshot(): DurableRetryActivationConfiguration;
```

Está definida en
`app/Modules/Orders/Contracts/DurableRetryActivationConfigurationSourceInterface.php:9-12`
y satisfecha por
`app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionActivationConfigurationSource.php:14-62`.

El reader expone:

```php
public function read(): DurableRetryActivationConfigurationValue;
```

La transferencia A1 sólo define:

```php
public function transferReconciliation(
    DurableRetryInitialTransferRequest $request
): DurableRetryInitialTransferResult;
```

(`app/Modules/Orders/Contracts/DurableRetryInitialTransferAuthorityInterface.php:10-15`).

## 6. Componente de composición

No está definido de forma implementable.

El diseño propone `DurableRetryReconciliationInitialScheduleProducer` y una
interfaz `DurableRetryReconciliationInitialScheduleProducerInterface::produce(
int $reconciliationId): DurableRetryInitialScheduleResult`
(`docs/durable-retry-production-activation-design.md:104-124`;
`docs/durable-retry-production-activation-design.md:377-401`).

Sin embargo, faltan:

- FQCN completo y namespace normativos;
- ruta exacta;
- confirmación de si la clase propuesta es el componente de composición;
- declaración `final`;
- constructor y dependencias exactas;
- definición de `DurableRetryInitialScheduleResult`;
- catálogo exacto de estados, factories, mensajes y excepciones;
- ciclo de vida en container;
- allowlist exacta.

Por tanto, no puede decidirse legítimamente si debe crearse una clase nueva o
extender una existente.

## 7. Compatibilidad directa A2 + A2.1

La composición interna sí está cerrada:

```text
WordPressOptionDurableRetryActivationConfigurationValueReader
→ DurableRetryProductionActivationConfigurationSource
→ DurableRetryDeterministicActivationPolicy
```

La propia A2.1 reserva para A10 los bindings:

```text
DurableRetryActivationConfigurationValueReaderInterface
→ WordPressOptionDurableRetryActivationConfigurationValueReader

DurableRetryActivationConfigurationSourceInterface
→ DurableRetryProductionActivationConfigurationSource

DurableRetryActivationPolicyInterface
→ DurableRetryDeterministicActivationPolicy
```

(`docs/durable-retry-production-activation-configuration-source-spec.md:841-859`).

No se necesita adapter entre A2 y A2.1. Se necesitan bindings productivos, pero
su edición está expresamente fuera de A2.1 y reservada a A10.

## 8. Manejo del snapshot

`DurableRetryDeterministicActivationPolicy::allowsInitialTransfer()` llama
directamente una vez a `source->snapshot()` y lo guarda en una variable local
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php:19-23`).

Cada decisión:

- obtiene exactamente un snapshot;
- conserva ese snapshot sólo durante esa invocación;
- pasa el objeto completo a la lógica interna, no escalares desde el caller;
- valida stage y algoritmo antes del cohorting;
- no comparte snapshot con otras decisiones.

A2.1 exige una lectura de reader y una lectura física de `get_option()` por
snapshot (`docs/durable-retry-production-activation-configuration-source-spec.md:621-653`).

No existe autoridad que permita compartir un snapshot entre varias decisiones
de una operación. La API actual provoca una lectura nueva por cada llamada a
`allowsInitialTransfer()`.

## 9. Identidad de cohorting

La identidad exacta de A2 es:

```text
(stage, subject_id)
```

`DurableRetryAuthorityIdentity` sólo almacena `stage` y `subjectId`
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentity.php:9-47`).
La factory pública actual sólo construye reconciliación.

El hash incluye exclusivamente:

```text
stage=reconciliation
subject_id=<decimal canónico positivo>
```

La cadena y algoritmo están cerrados en
`docs/durable-retry-production-activation-a2-flag-policy-spec.md:235-330`.

No participan:

- `completion_id`;
- `generation`;
- `attempt_number`;
- `schedule_id`;
- timestamps;
- estado durable.

La identidad persistente `(stage, subject_id, generation)` pertenece a
`DurableRetryGenerationIdentity`; cambiar generación no cambia autoridad
funcional (`docs/durable-retry-production-activation-a1-contracts-spec.md:60-110`).
`completion_id` es metadata write-once y, para reconciliación, debe igualar
`subject_id`, pero no es identidad ni input del hash
(`docs/durable-retry-production-activation-a1-contracts-spec.md:118-142`).

## 10. Scope de stages

Sólo `reconciliation` puede evaluarse.

La identidad pública sólo ofrece `reconciliation(int $subjectId)` y la policy
rechaza cualquier stage distinto con `UNSUPPORTED_STAGE`
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentity.php:17-26`;
`app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php:24-28`).

El snapshot A2.1 fija reconciliation y el algoritmo
`sha256-24bit-mod100-v1`
(`app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionActivationConfigurationSource.php:57-60`).

Business, delivery y fulfillment no quedan “desactivados”: no son entradas
válidas para esta policy.

## 11. Frontera temporal normativa

La decisión futura ocurre:

1. después de confirmar y persistir la reconciliación funcional;
2. después de terminar la transacción de materialización;
3. antes de solicitar la transferencia atómica;
4. antes de crear generation 1;
5. antes de coordinar scheduling durable.

Este orden está definido en
`docs/durable-retry-production-activation-design.md:104-124`.

“Nueva transferencia” significa el intento futuro de crear por primera vez
autoridad durable generation 1 para la identidad funcional. A2 sólo habilita el
intento; `false` no demuestra autoridad legacy
(`docs/durable-retry-production-activation-a2-flag-policy-spec.md:567-612`).

No vuelven a consultar A2:

- retries de una fila ya transferida;
- reintentos de scheduling;
- generaciones posteriores;
- recovery de filas existentes;
- reclasificación de históricos.

Apagar el flag sólo detiene transferencias nuevas; las unidades marcadas se
drenan por durable (`docs/durable-retry-production-activation-design.md:261-271`;
`docs/durable-retry-production-activation-design.md:309-340`).

## 12. Punto productivo actual

La clase actual es:

```text
VeciAhorra\Modules\Payments\Reconciliation\Service\
WebpayReconciliationMaterializer
```

Sus métodos productivos relevantes son:

- `materialize(...): MaterializedReconciliation`;
- `resume(...): ?MaterializedReconciliation`.

Después de persistir o reencontrar la reconciliación, ambos construyen el
resultado y llaman directamente:

```php
(new DurableCompletionScheduler())->reconciliation($reconciliationId);
```

(`app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:118-126`;
`app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:205-213`).

Éste es el camino legacy actual. El camino durable de ejecución, callback,
coordinator y registry existe, pero no existe la frontera de transferencia
inicial que cree generation 1. El materializador además instancia el scheduler
directamente y no recibe un selector/productor por constructor
(`app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:21-26`).

## 13. Composition root existente

`VeciAhorra\Core\Application` es el composition root real. Su constructor crea
el container y llama `registerDurableRetryGraph()`
(`app/Core/Application.php:101-172`).

El grafo actual registra repository, scheduler externo, processing policy,
coordinator, registry, executor, callback y registrar
(`app/Core/Application.php:174-320`).

No registra:

- reader A2.1;
- source A2.1;
- policy A2;
- transfer authority A1;
- productor inicial;
- materializador como servicio reemplazable.

El diseño reserva bindings lazy a composition root
(`docs/durable-retry-production-wiring-design.md:382-406`) y A2.1 reserva los
bindings de activación a A10. Modificar `Application` será necesario para wiring,
pero la especificación exacta del grafo consumidor todavía no existe.

## 14. Secuencia actual

```text
WebpayReturnService
→ WebpayReconciliationMaterializer::materialize()/resume()
→ persistir o reencontrar reconciliation_id
→ new DurableCompletionScheduler()
→ reconciliation(reconciliation_id)
→ Action Scheduler legacy
```

No se consulta A2, A2.1, A1 authority ni marcador A3.

## 15. Secuencia futura propuesta: desactivado

```text
WebpayReconciliationMaterializer
→ componente de composición/productor [FQCN NO DEFINIDO]
→ DurableRetryDeterministicActivationPolicy
→ DurableRetryProductionActivationConfigurationSource::snapshot()
→ WordPress get_option() una vez
→ allowsInitialTransfer(identity) = false
→ DurableCompletionScheduler::reconciliation()
→ resultado LEGACY_SELECTED [TIPO NO IMPLEMENTADO]
```

El orden conceptual está definido; el contrato concreto del productor y el
tratamiento de excepciones no.

## 16. Secuencia futura propuesta: activado

```text
WebpayReconciliationMaterializer
→ componente de composición/productor [FQCN NO DEFINIDO]
→ policy A2 + snapshot A2.1
→ decisión true
→ DurableRetryInitialTransferAuthorityInterface::transferReconciliation()
→ implementación A4 [NO EXISTE]
→ commit de generation 1
→ DurableRetryExternalScheduleCoordinator::coordinate()
→ resultado cerrado del productor [TIPO NO IMPLEMENTADO]
```

La rama durable no puede construirse correctamente sólo con A2 y A2.1: depende
de A4 y A5.

## 17. Error de configuración y failure policy

```text
WebpayReconciliationMaterializer
→ componente de composición [NO DEFINIDO]
→ policy A2
→ snapshot A2.1
→ DurableRetryActivationConfigurationSourceException
→ conducta productiva: NO DEFINIDA
```

Conductas internas cerradas:

| Fallo | Conducta cerrada |
|---|---|
| Valor presente inválido | `INVALID_VALUE`; no degrada a cero |
| API WordPress ausente | `SOURCE_UNAVAILABLE`, causa preservada |
| Reader lanza | `SOURCE_UNAVAILABLE`, causa preservada |
| Stage no admitido | `DurableRetryActivationPolicyException::UNSUPPORTED_STAGE` |
| Algoritmo incompatible | `UNSUPPORTED_ALGORITHM_VERSION` |
| Snapshot/stage incompatible | `INVALID_CONFIGURATION_SNAPSHOT` |
| Identidad no positiva | `DurableRetryActivationContractException::INVALID_AUTHORITY_IDENTITY` |
| Error interno de source | Se propaga desde A2 |

A2 especifica que errores internos de source se propagan y no se convierten en
`false` (`docs/durable-retry-production-activation-a2-flag-policy-spec.md:542-560`).
A2.1 prohíbe fallback silencioso
(`docs/durable-retry-production-activation-configuration-source-spec.md:499-541`).

Lo no definido es qué hace el futuro productor/materializador al recibir esas
excepciones: abortar, devolver `ERROR`, seleccionar legacy de forma cerrada,
envolver o propagar. Elegir cualquiera sería una nueva política productiva.
Tampoco está definida una excepción específica para hash no calculable o una
excepción inesperada de la policy.

## 18. Resultado de decisión

A2 devuelve únicamente `bool`. No devuelve:

- stage;
- porcentaje;
- bucket;
- algoritmo;
- versión;
- razón;
- identidad evaluada.

Esos datos existen internamente en snapshot, identidad y cálculo de cohorte,
pero no forman un resultado público de decisión. Los digests, enteros de 24 bits
y buckets son vectores diagnósticos de harness, no payload contractual.

La capa productiva propuesta consume el booleano y pretende devolver un
`DurableRetryInitialScheduleResult`, pero ese tipo no está implementado ni
especificado con suficiente detalle.

## 19. Observabilidad

A2.1 no autoriza logs, métricas, eventos, hooks ni trazas
(`docs/durable-retry-production-activation-configuration-source-spec.md:781-792`).
A2 tampoco tiene efectos laterales.

El diseño global exige antes del canario métricas y logs estructurados por stage,
subject, schedule, generation y resultado
(`docs/durable-retry-production-activation-design.md:629-644`).

No están definidos para el componente de composición:

- logger o interfaz de métricas;
- eventos y códigos emitidos;
- ownership;
- cardinalidad;
- conducta si falla la observabilidad;
- archivos o bindings.

No se autoriza inventarlos. Esta carencia es bloqueante para una composición
productiva completa, aunque no para componer A2 y A2.1 de forma aislada.

## 20. Separación respecto de A3

A3 pertenece exclusivamente a:

- lectura individual y batch del marcador generation 1;
- clasificación legacy, durable o indeterminate;
- evidencia de autoridad persistida;
- tratamiento fail-closed de filas corruptas o consultas fallidas;
- soporte a scheduler, worker y recovery legacy.

A2 no decide autoridad y A2.1 sólo lee configuración. La decisión inicial no
debe consultar A3 para calcular cohorte.

Sin embargo, conectar tráfico real antes de A3, A4 y las guardias A6-A8 no es
correcto: `false` no confirma autoridad legacy y `true` sólo habilita intentar
una transferencia. La exclusión de trabajo ya marcado depende de A3 y de sus
consumidores. Por ello la composición puramente constructiva A2+A2.1 es
independiente de A3, pero la integración productiva solicitada no puede
habilitar tráfico de forma segura sin los microhitos reservados.

## 21. Matriz de compatibilidad

| Dimensión | A2 | A2.1 | Clasificación |
|---|---|---|---|
| Source | Requiere interface de snapshot | La implementa | Composición directa |
| Snapshot | Objeto inmutable | Devuelve el mismo tipo | Composición directa |
| Stage | string validado, reconciliation | reconciliation fijo | Composición directa |
| Porcentaje | int `0..100` | normaliza a int `0..100` | Composición directa |
| Algoritmo | constante v1 | misma constante v1 | Composición directa |
| Identidad | AuthorityIdentity | No participa | Composición directa |
| Errores | policy/source propagados | excepción tipada | Compatible internamente |
| Ciclo de vida | source por constructor | reader por constructor | Bindings triviales |
| Consumidor productivo | No definido | Fuera de alcance | Ambigüedad documental |
| Resultado productivo | bool interno | snapshot | Nuevo contrato pendiente |
| Failure policy externa | No definida | No puede hacer fallback | Ambigüedad documental |

No hay incompatibilidad entre contratos existentes. El bloqueo es documental y
de componentes aún ausentes.

## 22. Decisiones ya cerradas

1. Sólo reconciliation.
2. Identidad de cohorte `(stage, subject_id)`.
3. `completion_id` y generation no participan en el hash.
4. Cohorte `sha256-24bit-mod100-v1`.
5. Snapshot nuevo y una lectura por decisión.
6. Default apagado sólo ante ausencia real.
7. Configuración inválida e indisponible lanzan.
8. A2 controla únicamente intentos de transferencia nuevos.
9. A2 no prueba autoridad legacy.
10. El marcador generation 1 es autoridad persistida permanente.
11. Trabajo transferido no vuelve a consultar activación.
12. El punto actual son las dos llamadas del materializador al scheduler legacy.
13. El composition root es `Application`.
14. No se requiere cambio de schema para esta composición.

## 23. Decisiones pendientes y bloqueantes

1. FQCN, namespace, ruta y carácter `final` del productor/compositor.
2. Interfaz definitiva del productor.
3. Definición completa de `DurableRetryInitialScheduleResult`.
4. Constructor y dependencias exactas.
5. Implementación productiva de transfer authority A4.
6. Ownership y representación del reloj requerido por la solicitud A1.
7. Conducta ante cada excepción A2/A2.1.
8. Si `ERROR` representa configuración inválida o sólo fallos esperables de A4.
9. Observabilidad exacta del compositor.
10. Ciclo de vida de bindings en `Application`.
11. Forma de inyectar el productor en el materializador sin romper callers.
12. Harnesses nominales y allowlist exacta.
13. Precondiciones A3 y guardias legacy requeridas antes de conectar tráfico.

## 24. Allowlist futura

**NO PROCEDE PROPONER UNA ALLOWLIST EXACTA.**

El veredicto bloqueado impide convertir candidatos en autorización. Como mínimo
una especificación posterior deberá decidir si incluye:

- contrato y resultado del productor;
- clase productora;
- implementación A4 o una dependencia ya versionada;
- `WebpayReconciliationMaterializer`;
- `Application`;
- harnesses unitarios, de integración y de composición.

Archivos explícitamente prohibidos para un microhito aislado de composición:

- schema y migraciones;
- A3 y repositories de clasificación;
- processors;
- executor y callback;
- hooks y registrar;
- Action Scheduler adapter;
- JavaScript, UI, REST y WP-CLI;
- documentos normativos existentes.

Si el siguiente microhito necesita crear A3 o A4, debe separarse o admitir que no
es sólo composición A2+A2.1.

## 25. Matriz de pruebas requerida

Una especificación habilitante deberá exigir al menos:

| Área | Casos |
|---|---|
| Construcción | bindings exactos, lazy, identidades de instancia |
| Configuración | ausencia, 0, 100, inválido, source unavailable |
| Policy | una lectura por decisión, stage/algoritmo, cohort estable |
| Rama legacy | false llama una vez sólo al scheduler legacy |
| Rama durable | true no llama legacy y solicita una sola transferencia |
| Transfer result | transferred, converged, legacy in flight, ineligible, inconsistency, uncertain, error |
| Coordinación | sólo después de commit y sólo para resultados autorizados |
| Repetición | materialize/resume idempotentes, nunca ambos schedulers |
| Errores | catálogo exacto y failure policy por excepción |
| A3 | demostrar que no se simula clasificación en compositor |
| Efectos | constructor/bindings sin SQL, hooks ni scheduling |
| Regresión | pipeline durable y legacy completos |

## 26. Criterio de aprobación del siguiente microhito

El siguiente microhito sólo será implementable cuando una especificación
versionada:

1. cierre los trece puntos pendientes;
2. ubique el microhito respecto de A3-A10;
3. defina si es composición aislada sin tráfico o wiring productivo;
4. prohíba que `false` se interprete como autoridad legacy;
5. cierre la failure policy sin degradar errores A2.1 a porcentaje cero;
6. defina resultado y observabilidad;
7. autorice una allowlist nominal exacta;
8. proporcione harnesses y criterios de rollback.

Hasta entonces sólo es segura la composición interna A2+A2.1 en tests; no su
conexión al materializador productivo.
