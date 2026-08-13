# Auditoría de readiness A2: activación productiva Durable Retry

## 1. Veredicto ejecutivo

**BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

A2 tiene un nombre y una responsabilidad general inequívocos: **Política de flag
determinista** para permitir o impedir nuevas transferencias iniciales por stage
y cohorte, con default apagado. No obstante, las fuentes no cierran la API
posterior a A1, la fuente canónica de configuración, el tipo y rango del
porcentaje, el algoritmo de cohorting, el tratamiento de configuración inválida,
los FQCN concretos ni la allowlist de archivos. Implementarlo obligaría a tomar
decisiones arquitectónicas nuevas.

## 2. Base certificada

| Dato | Evidencia certificada |
|---|---|
| Rama | `main` |
| HEAD | `d04c8cca98362413b2f30f18c2e0ed3fba10784f` |
| Divergencia | `0 atrás / 30 adelante` frente a `origin/main` |
| Staging | Vacío |
| Estado tracked | Sin modificaciones |
| A1 | Versionado en HEAD: 13 PHP puros, 3 harnesses y guard histórico |
| Documentos protegidos | 11, presentes, intactos y untracked |
| `artifacts/` | 504 archivos |

El commit actual tiene mensaje
`feat(orders): add durable retry activation contracts`. Los tres harnesses A1 y
el catálogo histórico están versionados; esta auditoría no los modifica.

## 3. Definición exacta de A2

### Nombre normativo

`A2. Política de flag determinista`
(`docs/durable-retry-production-activation-design.md:464-473`).

### Objetivo normativo cerrado

A2 debe definir una política que:

- decide si una **nueva** transferencia inicial puede intentarse;
- opera por stage;
- parte apagada;
- asigna una cohorte estable por subject;
- no cambia autoridad de trabajo ya transferido;
- no se conecta todavía a una ruta productiva.

La sección de activación establece un flag canónico conceptual:

```text
durable_retry.initial_transfer.reconciliation = false
```

(`docs/durable-retry-production-activation-design.md:281-289`).

### Responsabilidad única

Convertir configuración válida y una identidad candidata en una decisión
determinista de permiso para **intentar** una transferencia futura. La decisión
no confirma autoridad, no crea filas y no programa acciones.

### Entradas

Documentalmente aparecen:

- stage;
- subject ID;
- configuración off/on o porcentaje.

La firma propuesta originalmente es:

```php
public function allowsInitialTransfer(string $stage, int $subjectId): bool;
```

(`docs/durable-retry-production-activation-design.md:351-354`).

La forma definitiva de estas entradas está ambigua después de A1: A1 creó
`DurableRetryAuthorityIdentity` para `(stage, subject_id)`, pero ninguna fuente
declara si A2 debe reemplazar los dos escalares por ese objeto.

### Salida

`bool`: `true` permite solicitar una transferencia nueva; `false` mantiene el
evento en la selección legacy. Un booleano aquí no representa autoridad
persistida. A1 prohíbe expresamente que un flag determine la autoridad de un
trabajo existente
(`docs/durable-retry-production-activation-a1-contracts-spec.md:539-545`).

### Dependencias permitidas

Por categoría, no por tipo concreto:

- contrato de política;
- adapter de configuración;
- configuración canónica aún no definida;
- identidad/stage puros;
- función determinista pura de cohorting;
- doubles dentro de harnesses.

### Dependencias prohibidas

- WordPress y Action Scheduler en dominio/contrato;
- repository durable o legacy;
- SQL, transacciones y locks;
- executor, callback, processors y scheduler;
- producer y wiring;
- consulta de autoridad;
- escritura o migración;
- reloj, aleatoriedad por invocación o memoria mutable como autoridad.

### Efectos permitidos

- leer configuración mediante un adapter futuro;
- validar configuración;
- calcular una decisión pura y estable.

### Efectos prohibidos

- transferir autoridad;
- crear generation 1;
- consultar o mutar estado persistido;
- programar/cancelar acciones;
- registrar hooks;
- activar tráfico por sí mismo.

### Dirección de autoridad

A2 sólo controla si un caller futuro puede solicitar `legacy → durable`. No
ejecuta esa transición y no admite `durable → legacy`.

### Relación con A1

A1 ya aporta identidad funcional, excepción de contratos, resultados de
autoridad y contrato de transferencia. A2 no puede ampliar sus catálogos ni
reinterpretar `legacy`, `durable` o `indeterminate`.

### Relación con microhitos posteriores

- A3 implementará lectura del marcador y clasificación batch
  (`production-activation-design.md:475-484`).
- A4 implementará transferencia transaccional
  (`production-activation-design.md:486-495`).
- A5 implementará el productor aislado
  (`production-activation-design.md:497-506`).
- A10 conectará el productor al materializador con default off
  (`production-activation-design.md:552-561`).
- A12 realizará el canario operativo
  (`production-activation-design.md:574-583`).

## 4. Frontera A1 → A2

### Resuelto definitivamente por A1

- `DurableRetryAuthorityIdentity`: `(reconciliation, subject_id)`.
- `DurableRetryGenerationIdentity`: identidad persistente de generación.
- colección tipada y límite de 500 para consultas batch.
- resultados `legacy`, `durable`, `indeterminate`.
- siete razones de indeterminación.
- solicitud y siete resultados de transferencia inicial.
- excepción segura `DurableRetryActivationContractException`.
- contratos de exclusión y transferencia.

La implementación está en:

- `app/Modules/Orders/Contracts/DurableRetryLegacyExclusionInterface.php`;
- `app/Modules/Orders/Contracts/DurableRetryInitialTransferAuthorityInterface.php`;
- `app/Modules/Orders/Domain/DurableRetry/`;
- `app/Modules/Orders/Exceptions/DurableRetryActivationContractException.php`.

### Lo que comienza conceptualmente en A2

- contrato de política de activación;
- representación/configuración del modo off/on/porcentaje;
- cálculo estable de cohorte;
- adapter que suministre configuración;
- pruebas de off, on, límite y estabilidad.

### Todavía inexistente después de A2

- lectura de tablas;
- marcador persistente;
- transferencia real;
- transacción y locks;
- producer;
- wiring;
- scheduling;
- activación de tráfico.

## 5. Inventario del código existente reutilizable

| Ruta / símbolo | Namespace | Responsabilidad actual | Reuso | Evidencia / límite |
|---|---|---|---|---|
| `Domain/DurableRetry/DurableRetryAuthorityIdentity.php` | `VeciAhorra\Modules\Orders\Domain\DurableRetry` | Identidad `(stage, subject_id)` | Posible, pero no autorizado explícitamente para la firma A2 | A1 spec: secciones 3.1 y 6.1 |
| `Domain/DurableRetry/DurableRetryStage.php` | mismo namespace | Cuatro stages canónicos | Sin cambios para validar stage | `DurableRetryStage.php:9-31` |
| `Exceptions/DurableRetryActivationContractException.php` | `VeciAhorra\Modules\Orders\Exceptions` | Ocho errores cerrados A1 | No puede ampliarse sin autorización | A1 spec: líneas 166-216 |
| `Core/Config.php` | `VeciAhorra\Core` | Versiones y prefijo de tablas | No contiene feature flags; no tocar sin especificación | `app/Core/Config.php:12-57` |
| `DurableRetryLegacyExclusionInterface.php` | `...\Orders\Contracts` | Consulta de autoridad | No debe ser invocado por la política | A1 spec: líneas 510-545 |
| `DurableRetryInitialTransferAuthorityInterface.php` | `...\Orders\Contracts` | Solicita transferencia tipada | Consumidor futuro, no dependencia interna de A2 | A1 spec: líneas 746-776 |
| `DurableRetryInitialTransferRequest.php` | `...\Domain\DurableRetry` | Datos válidos para A4/A5 | No pertenece al cálculo de flag | A1 spec: líneas 547-618 |
| `WebpayReconciliationMaterializer.php` | `...\Payments\Reconciliation\Service` | Produce el hecho funcional y hoy agenda legacy | No tocar en A2 | Diseño: líneas 105-124; código `:29`, `:125`, `:212` |
| `DurableCompletionScheduler.php` | `...\Fulfillment\Orchestration` | Scheduling legacy | No tocar | Diseño: A6, líneas 508-517 |
| `app/Core/Application.php` / `Application` | `VeciAhorra\Core` | Construcción productiva | No tocar; reservado a A10 | Diseño: líneas 552-561; código `:101` |

No existe un servicio de feature flags ni una convención canónica de porcentajes
en `app/Modules/Orders` o `app/Core`. La similitud con una constante de `Config`
no autoriza convertir `Config` en fuente del flag.

## 6. Artefactos exactos de A2

El catálogo exacto **no puede derivarse documentalmente**.

Sólo están definidas estas categorías:

| Categoría | Fuente | Estado |
|---|---|---|
| Contrato de política | Diseño A2, línea 467; interfaz propuesta, líneas 351-354 | Nombre sugerido, firma previa a A1 |
| Adapter de configuración | Diseño A2, línea 467 | Sin nombre, namespace ni fuente |
| Tests | Diseño A2, línea 467 y casos línea 469 | Sin rutas ni harnesses exactos |

No se pueden enumerar responsablemente:

- ruta/FQCN definitivo del contrato;
- clase/FQCN del adapter;
- value object o catálogo de configuración;
- excepción aplicable;
- archivos modificados;
- harnesses exactos.

Crear una allowlist de implementación requeriría una especificación
complementaria.

## 7. Firmas públicas

| Firma | Clasificación | Evidencia / problema |
|---|---|---|
| `allowsInitialTransfer(string $stage, int $subjectId): bool` | Explícitamente propuesta | Diseño líneas 351-354 |
| `allowsInitialTransfer(DurableRetryAuthorityIdentity $identity): bool` | Derivable como opción, no normativa | A1 creó el VO, pero no ordena usarlo en A2 |
| Constructor de política | Ausente | No se define config object, adapter ni orden |
| API del adapter | Ausente | No hay método, resultado o failure mode |
| Factory de configuración | Ausente | No se define shape ni validación |
| API de porcentaje/cohorte | Ausente | No se define tipo, rango o algoritmo |
| Excepción por configuración inválida | Ambigua | El diseño permite excepción, pero A1 no contiene un código específico |

La tabla del diseño llama al namespace `Modules\Orders\Contracts` “sugerido”
(`production-activation-design.md:390-396`). No basta para fijar archivos y
FQCN posteriores sin otra norma.

## 8. Catálogos y resultados

A2 no necesita ampliar:

- estados de autoridad;
- razones de indeterminación;
- resultados de transferencia;
- identidades persistentes;
- transiciones durable.

Puede necesitar un catálogo de modo/configuración con, al menos:

- off;
- on;
- percentage/cohort.

Pero la fuente no decide si esos son estados públicos, un entero, un booleano
más porcentaje, una única tasa o configuración por stage. Tampoco define:

- unidad del porcentaje;
- precisión;
- mínimo/máximo;
- valor inválido;
- representación del 0 % y 100 %;
- error code/excepción;
- compatibilidad futura por stage.

Ampliar `DurableRetryActivationContractException` sería un cambio a A1 no
autorizado por el diseño A2.

## 9. Autoridad legacy

A2 **no consulta autoridad legacy**.

| Pregunta | Decisión |
|---|---|
| Fuente legacy consultada | Ninguna en A2 |
| Cómo se determina `legacy/durable/indeterminate` | No se determina; pertenece a A3 |
| Significado de exclusión legacy | Fuera de A2; A3 lee, A6-A8 aplican guardias |
| Datos a leer | Sólo configuración de activación aún no especificada |
| Datos que no debe leer | Filas funcionales, tabla durable, Action Scheduler |
| Ausencia de autoridad | No se interpreta |
| Inconsistencia/incertidumbre de autoridad | No se interpreta |
| Consulta individual/batch | Contratos A1 disponibles, implementaciones A3 |
| Orden y presupuesto SQL | No aplican |
| Resultado puro o persistido | La decisión A2 debe ser pura y no persistida |

Un `false` de A2 significa “no permitir nueva transferencia”, no
“autoridad legacy confirmada”. Sólo el resultado A1 `legacy()` autoriza legacy.

## 10. Transferencia inicial

A2 no incluye transferencia real. Quedan excluidos:

- precondiciones funcionales;
- locks y transacción;
- escritura de generation 1;
- detección persistente de existencia;
- CAS;
- carreras producer/worker;
- ventanas parciales;
- scheduling externo.

Estos elementos pertenecen a A4 y posteriores
(`production-activation-design.md:486-506`). A2 sólo decide si un caller futuro
puede llegar a solicitar la operación.

La dirección conceptual sigue siendo `legacy → durable`; apagar el flag no
revierte trabajos transferidos
(`production-activation-design.md:313-330`).

## 11. Persistencia y SQL

| Operación | A2 |
|---|---|
| SQL | Excluido |
| Repository concreto | Excluido |
| Lectura de tablas legacy | Excluida |
| Lectura de tabla durable | Excluida |
| Escritura durable | Excluida |
| Transacciones/locks | Excluidos |
| Migración/schema | Excluidos |
| Lectura de configuración | Incluida por categoría, mecanismo ambiguo |

No hay, por tanto, tablas, columnas, predicados, índices ni presupuesto SQL que
especificar para A2. Si se eligiera WordPress Options como fuente de
configuración, esa sería una decisión nueva: no está autorizada por las fuentes.

## 12. Integración productiva

| Elemento | Clasificación |
|---|---|
| Contrato de política | Incluido conceptualmente |
| Adapter concreto de configuración | Incluido conceptualmente, indefinido |
| Resolver productivo | Excluido |
| Service container/bootstrap | Reservado para A10 |
| Callback/registrador/hooks | Excluidos |
| Action Scheduler/scheduling | Excluidos |
| Producer | Reservado para A5 |
| Conexión del producer | Reservada para A10 |
| Activación automática | Excluida |
| Configuración operativa canaria | Reservada para A12 |
| REST/WP-CLI/UI | Excluidos |

## 13. Matriz de decisiones

| Tema | Decisión normativa | Fuente | Estado | Impacto abierto | Acción documental |
|---|---|---|---|---|---|
| Identidad | Stage+subject | A1 | Cerrado en dominio, ambiguo en firma A2 | API incompatible | Elegir VO o escalares |
| Autoridad | Flag no determina autoridad | A1 líneas 539-545 | Cerrado | Ninguno | Preservar |
| Consulta individual | Fuera de A2 | A3 | Cerrado | Ninguno | Excluir |
| Consulta batch | Fuera de A2 | A3 | Cerrado | Ninguno | Excluir |
| Fuente legacy | Ninguna | A2/A3 | Cerrado | Ninguno | Excluir |
| Fuente durable | Ninguna | A2/A3 | Cerrado | Ninguno | Excluir |
| Transferencia | No se ejecuta | A4 | Cerrado | Ninguno | Excluir |
| Generación | No se crea | A4 | Cerrado | Ninguno | Excluir |
| Idempotencia | Estabilidad por subject; algoritmo ausente | A2 línea 469 | Ambiguo | Cohortes incompatibles | Fijar algoritmo |
| Atomicidad | No aplica a A2 | A4 | Cerrado | Ninguno | Excluir |
| Concurrencia | Misma identidad debe decidir igual | Sección 9 diseño | Inferible | Cachés/config reload | Fijar snapshot config |
| SQL | Prohibido | A2 fuera de scope | Cerrado | Ninguno | Excluir |
| Excepciones | Config inválida puede lanzar | Diseño líneas 347-348, 392 | Ambiguo | Failure mode divergente | Fijar tipo/código |
| Resultados | Booleano propuesto | Diseño línea 353 | Cerrado si firma se conserva | Confusión con autoridad | Documentar semántica |
| Wiring | No | A10 | Cerrado | Ninguno | Excluir |
| Scheduling | No | A5/A10 | Cerrado | Ninguno | Excluir |
| Observabilidad | No definida para A2 | Diseño sección 16 | Ambiguo/no bloquea pureza | Operación futura | Reservar A11/A12 |
| Rollback | Default off | A2 línea 473 | Cerrado en principio | Config source ausente | Fijar mecanismo |

## 14. Ambigüedades y contradicciones

### A2-AMB-01 — Firma posterior a A1

- Fuentes: diseño líneas 351-354; A1 spec secciones 3.1 y 6.1.
- Conflicto: la firma propuesta usa `string, int`; A1 creó una identidad tipada
  precisamente para `(stage, subject_id)`.
- Bloqueo: elegir cualquiera fija una API pública distinta.
- Decisión mínima: declarar la firma definitiva.
- Opciones: conservar escalares; recibir `DurableRetryAuthorityIdentity`.
- Afecta: A2, A5 y A10.

### A2-AMB-02 — Fuente canónica de configuración

- Fuentes: diseño líneas 283-289 y 466-467.
- Vacío: se nombra un adapter, no la fuente ni su contrato.
- Bloqueo: no puede implementarse el adapter ni sus tests.
- Decisión mínima: fijar fuente, shape, lifecycle y seguridad.
- Opciones: configuración inyectada pura; constante de despliegue; adapter de
  options; otra fuente explícita.
- Afecta: A2, A10, A12 y rollback.

### A2-AMB-03 — Representación del rollout

- Fuente: diseño línea 469 (“off, on, porcentaje límite”).
- Vacío: no hay tipo, rango, precisión ni normalización.
- Bloqueo: 1 puede significar 1 % o 100 %; límites y errores divergen.
- Decisión mínima: tipo y dominio exactos.
- Opciones: entero 0..100; basis points 0..10000; modo cerrado con tasa.
- Afecta: A2 y A12.

### A2-AMB-04 — Algoritmo de cohorte

- Fuentes: diseño líneas 297-302 y 466.
- Vacío: sólo dice hash estable de reconciliation ID.
- Bloqueo: algoritmo, canonicalización, salt, módulo y umbral cambian miembros.
- Decisión mínima: bytes de entrada, hash, extracción, bucket y comparación.
- Opciones: SHA-256 sin salt; hash versionado con salt estable; otro algoritmo
  expresamente versionado.
- Afecta: A2, despliegues y rollback.

### A2-AMB-05 — Scope por stage

- Fuentes: flag por stage, línea 283; primera etapa reconciliation, líneas 32-59.
- Vacío: no se decide si A2 acepta cuatro stages apagados o sólo reconciliation.
- Bloqueo: catálogo/configuración y tratamiento de stage válido pero no activable.
- Decisión mínima: scope exacto y resultado para otros stages.
- Opciones: API sólo reconciliation; API genérica con otros stages siempre off.
- Afecta: A2 y migraciones posteriores de stages.

### A2-AMB-06 — Configuración inválida

- Fuentes: diseño líneas 347-348 y 392; excepción A1 tiene catálogo cerrado.
- Vacío: no existe excepción/código de flag inválido ni regla fail-off/throw.
- Bloqueo: implementaciones pueden habilitar, apagar o lanzar de forma distinta.
- Decisión mínima: excepción exacta, mensajes y frontera.
- Opciones: nueva excepción A2; resultado de carga cerrado; throw al construir.
- Afecta: A2, bootstrap A10 y operación.

### A2-AMB-07 — Snapshot y cambio durante una invocación

- Fuente: diseño línea 291 exige estabilidad durante una invocación.
- Vacío: no define quién captura el snapshot ni política de cache/reload.
- Bloqueo: dos lecturas podrían decidir distinto en el mismo flujo.
- Decisión mínima: constructor immutable snapshot o método de evaluación atómico.
- Opciones: política inmutable por request; config snapshot tipado.
- Afecta: A2, A5 y A10.

### A2-AMB-08 — Artefactos y harnesses exactos

- Fuente: A2 líneas 467-469.
- Vacío: sólo categorías y casos.
- Bloqueo: no existe allowlist revisable.
- Decisión mínima: FQCN, rutas y nombres de harness.
- Afecta: implementación/commit A2.

No se detecta una contradicción técnica que haga imposible A2; el bloqueo es
falta de decisiones normativas.

## 15. Riesgo de contaminación de alcance

- Conectar la política al materializador antes de A10.
- Tratar `false` como prueba de autoridad legacy.
- Consultar marcador durable dentro de la política.
- Implementar A3/A4 junto con el flag.
- Elegir WordPress Options sin norma.
- Introducir Action Scheduler o hooks.
- Añadir rollback, delete, force transfer o transfer-back.
- Ampliar resultados/excepción A1 para acomodar configuración.
- Cambiar schema o crear migración.
- Reutilizar scheduler legacy con efectos secundarios.
- Mezclar clasificación batch y configuración.
- Introducir WordPress en objetos de dominio.
- Activar una cohorte en el mismo commit que define la política.

## 16. Estrategia de harnesses

Estos nombres son **propuestas para la especificación complementaria**, no
allowlist autorizada:

| Harness propuesto | Ruta propuesta | Alcance | Matrices |
|---|---|---|---:|
| Policy domain | `tests/manual/durable-retry-activation-policy-test.php` | off/on, boundaries, estabilidad, stages, invalid config | 8-12 |
| Config adapter | `tests/manual/durable-retry-activation-policy-adapter-test.php` | fuente, parseo, ausente, inválido, snapshot | 8-10 |
| Infrastructure/purity | `tests/manual/durable-retry-activation-policy-infrastructure-test.php` | firmas, allowlist, sin SQL/WP/AS/wiring | 6-8 |

Casos positivos mínimos:

- off siempre false;
- on verdadero para reconciliation válida;
- 0 % equivale a off y 100 % a on;
- mismo subject produce la misma decisión;
- orden/proceso/reinicio no alteran bucket;
- subjects en ambos lados del límite.

Casos negativos:

- stage desconocido/no activable;
- porcentaje fuera de rango o tipo no canónico;
- config ausente/corrupta;
- algoritmo/version desconocidos;
- strings numéricos, floats, null y arrays;
- intento de usar el flag como autoridad.

Dependencias: política pura con config object/double; adapter probado con double de
la fuente que la especificación autorice. No usar base real ni Action Scheduler.

Regresiones:

- tres harnesses A1;
- guard histórico de dominio;
- 39 harnesses Durable Retry aislados;
- composición productiva debe permanecer sin cambios.

## 17. Secuencia recomendada

A2 no es grande; está incompleto. Tras una especificación complementaria cerrada,
una implementación puede mantenerse como un solo microhito con:

1. contrato definitivo;
2. value object/config catálogo si se autoriza;
3. algoritmo de cohorting versionado;
4. adapter exacto;
5. tres harnesses.

No se recomienda dividir A2.1/A2.2 antes de resolver las ocho ambigüedades:
fragmentarlas no elimina decisiones y podría fijar APIs incompatibles.

## 18. Alcance negativo definitivo

Durante A2 no implementar:

- A3 o posteriores;
- repositories, SQL, tabla durable o legacy;
- transferencia real, generation 1, locks o transacción;
- producer o modificación de `WebpayReconciliationMaterializer`;
- wiring, container, bootstrap;
- hooks, callback, Action Scheduler o scheduling;
- activación automática o canario;
- observabilidad productiva;
- rollback persistente, delete, force-transfer o retorno a legacy;
- REST, WP-CLI, UI o JavaScript;
- schema o migraciones;
- modificación de A1;
- documentación ajena a la especificación complementaria A2.

## 19. Condiciones de entrada a implementación

- [ ] Esta auditoría está versionada.
- [ ] Existe una especificación normativa complementaria A2 versionada.
- [ ] Se resolvieron A2-AMB-01 a A2-AMB-08.
- [ ] La firma de policy es definitiva.
- [ ] Fuente y contrato del adapter son definitivos.
- [ ] Shape, rango y error de configuración son definitivos.
- [ ] Algoritmo/version/bucket de cohorte son definitivos.
- [ ] Scope exacto de stages está definido.
- [ ] Snapshot/reload durante invocación está definido.
- [ ] Excepción y mensajes están cerrados.
- [ ] Existe allowlist exacta de archivos y harnesses.
- [ ] A1 permanece sin cambios y sus 533 aserciones pasan.
- [ ] Los 39 harnesses Durable Retry pasan.
- [ ] Base Git futura exacta, staging vacío y tracked limpio.
- [ ] Commit selectivo sin protected docs ni artifacts.
- [ ] No push salvo instrucción posterior explícita.

## 20. Recomendación final

**CREAR ESPECIFICACIÓN COMPLEMENTARIA DE A2**

El diseño vigente define suficientemente la responsabilidad y los límites de A2,
pero no una API implementable ni un adapter determinista reproducible. La
especificación complementaria debe cerrar las ocho ambigüedades, especialmente
firma tras A1, fuente/config shape, algoritmo de cohorte, error handling y
allowlist. Esta auditoría no debe continuar con esa especificación ni con código.
