# Auditoría de autoridad global de valores `fixture_ids` A11

Estado: auditoría probatoria, global y fail-closed. Fecha: 2026-08-03.

Veredicto de partida adoptado sin reapertura:

```text
A11-WR-06 FIXTURE_IDS CONTINÚAN BLOQUEADOS POR VALORES NO ASIGNADOS
```

Esta auditoría no asigna enteros, no materializa `A11-WR-06`, no define una corrección normativa y no autoriza cambios de PHP, harness, fixture, manifest, schema, configuración ni artifacts.

## 1. Base, método y pregunta binaria

La base observada fue `main` en `847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`, staging vacío, sin `.git/index.lock`, sin `.a11-runtime`, 504 archivos en `artifacts/` y una aparición del accessor tipado. Los cinco antecedentes WR-06, los cinco hashes protegidos y los nueve antecedentes fueron revalidados sin diferencia material antes de crear este archivo.

Pregunta principal:

> ¿El contrato A11 actual permite que `fixture_ids` describa un plan tipado y que los valores positivos reales sean capturados durante setup o ejecución, sin perder determinismo, unicidad, repetibilidad ni aislamiento?

Respuesta binaria: **no, no de extremo a extremo en el estado actual**. Los documentos autorizan conceptualmente un manifest runtime con IDs capturados, pero el contrato ejecutable del harness no define ni implementa el transporte autoritativo de capturas entre setup, `first_delivery`, crash y replay. Esta respuesta no niega la viabilidad conceptual del Modelo B; impide declararlo implementable ahora.

Clasificación usada en las evidencias:

- `AP`: autoridad productiva.
- `CH`: contrato de harness.
- `ADN`: antecedente documental normativo.
- `EP`: evidencia de prueba.
- `INN`: inferencia no normativa.

## 2. Autoridades documentales y matriz de localización

| # | Autoridad requerida | Evidencia localizada | Clase | Resultado |
|---:|---|---|---|---|
| 1 | Documento principal A11 | `docs/durable-retry-production-activation-a11-normative-correction.md`, documento completo | ADN | localizado |
| 2 | 31 casos | `docs/durable-retry-production-activation-a11-complementary-normative-correction.md:383-425` | ADN | 31 casos definidos |
| 3 | `fixture_ids` global | `docs/durable-retry-production-activation-a11-fixture-contract-normative-correction.md:78-93` | ADN | 15 listas, enteros positivos capturados |
| 4 | manifest | fixture contract `:57-76`, complementary `:136-165` | ADN/CH | runtime fuera del repo, ruta y hash |
| 5 | `.a11-runtime` | guardias de ausencia inicial/final en contratos A11; no es la ruta autorizada del manifest | ADN | residuo prohibido, no contenedor autorizado |
| 6 | cleanup | fixture contract `:241-256` | ADN/CH | selectivo por IDs; sin truncate |
| 7 | aislada/conjunta | complementary `:363-425`; fixture contract `:218-223` | ADN/CH | H1-H5 y manifest compartido |
| 8 | repetibilidad | complementary `:383-425`, `run_id` y ownership por caso | ADN | relacional/funcional, no PK histórica |
| 9 | unicidad intercaso | complementary `:383-425`; fixture contract `:241-256` | ADN | ownership y recursos del caso |
| 10 | IDs capturados | fixture contract `:78-93`, `:185-190`, `:207-214` | ADN/CH | captura exigida antes de continuar |
| 11 | aliases/references | documentos WR-06 y shape de fixture; no hay API ejecutable global | ADN | concepto presente, resolución incompleta |
| 12 | resources API | fixture contract `:225-256` | CH | allocate/cleanup descritos |
| 13 | shape del fixture | fixture contract `:78-93` y corrección WR-06 de `fixture_ids` | ADN | key set/cardinalidades, valores pendientes |
| 14 | shape del manifest | fixture contract `:57-76`; complementary `:136-165` | ADN/CH | revisiones documentales no totalmente coincidentes |
| 15 | loader/parser/validator | búsqueda en `tests/manual/support` | EP | no existen componentes separados |
| 16 | harness funcional | rutas H1-H5 en complementary `:363-381` | ADN/EP | archivos requeridos ausentes |
| 17 | harness infraestructura | mismas rutas H1-H5 | ADN/EP | archivos requeridos ausentes |
| 18 | setup/teardown | `tests/manual/support/durable-retry-a11-coordinator.php:83-190` | CH/EP | allocate/run/cleanup parciales |
| 19 | factories | búsquedas en pruebas manuales y contratos A11 | EP/INN | doubles locales; no allocator global A11 |
| 20 | repositorios autoincrement | repositorios Orders y tablas WP/WooCommerce referidos por fixtures | AP | inserciones productivas, sin rango A11 |
| 21 | APIs que retornan ID | `wpdb->insert_id`, APIs WooCommerce y scheduler según adaptadores/pruebas | AP/EP | capturables, no predeterminados globalmente |
| 22 | transacciones de setup | fixture contract `:241-256` | ADN/CH | SQL transaccional exigido donde aplique |
| 23 | rollback/delete/truncate | fixture contract `:241-256` | ADN/CH | delete selectivo; truncate prohibido |
| 24 | Action Scheduler double | correcciones WR-06 expected/actions y pruebas de scheduler | ADN/EP | puede devolver ID controlado; contrato global de valor ausente |
| 25 | Webpay gateway double | correcciones WR-06 de entorno/resultado | ADN/EP | business payload controlable; no allocator de PK |
| 26 | convenciones previas | matrices A11 y pruebas manuales anteriores | ADN/EP | IDs lógicos/doubles, no reserva literal 31 casos |
| 27 | captura runtime previa | coordinator y contratos de manifest | CH/ADN | prevista, no cerrada como API de aliases |
| 28 | igualdad entre fases | crash/replay y manifest path+hash | ADN/CH | obligación presente, transporte incompleto |
| 29 | guardias Git/filesystem | ausencia inicial/final de `.a11-runtime`, temporales fuera del repo | ADN | fail-closed |
| 30 | literales preejecución | corrección WR-06 `fixture_ids.values` | ADN | exige positivos materializados, pero reconoce bloqueo de asignación |

No se usa una inferencia como autoridad para asignar valores. Las referencias de línea son las observadas en la versión auditada; el contenido identificado, no el número por sí solo, es la autoridad.

## 3. Significado de determinismo

A11 exige de forma demostrable:

| Propiedad | Exigida | Lectura probatoria |
|---|---|---|
| A. Mismo entero en toda ejecución | no demostrada | autoincrement y APIs externas pueden variar |
| B. Misma estructura, cardinalidad y relación | sí | shape y matriz normativa |
| C. Misma asignación dentro de ejecución bifásica | sí | replay debe usar las capturas de esa corrida |
| D. Sin colisiones durante ejecución conjunta | sí | ownership/run/case y cleanup selectivo |
| E. Mismo resultado funcional | sí | expected actions/result de cada caso |

Por tanto, determinismo de valores físicos entre corridas no está exigido; sí lo están el determinismo relacional, el resultado funcional, la estabilidad `first_delivery`/replay y la identidad dentro de una corrida. Procesos independientes de una misma corrida deben observar el mismo snapshot autoritativo. Corridas distintas pueden producir enteros distintos. La unicidad histórica no está autorizada como requisito.

Conclusión: el significado de determinismo está cerrado documentalmente como B+C+D+E, no como A.

## 4. Significado de objeto literal y momentos de autoridad

Las autoridades no sostienen la Alternativa 1 para todas las PK. Sí sostienen una secuencia compatible con las Alternativas 2, 3 y 4:

| Momento | Estado permitido/exigido |
|---|---|
| antes de setup | plan declarativo: entidades, cardinalidades, aliases y fuentes |
| después de setup | snapshot con las PK ya creadas |
| antes de `first_delivery` | todas las referencias que esa fase consume deben estar capturadas |
| después de `first_delivery` | se añaden resultados externos o durable IDs producidos por esa fase |
| antes de replay | snapshot completo, persistido y validado por ruta/hash |
| después de replay | misma autoridad; solo se agregaría evidencia permitida, nunca se reemplazan IDs |

El término “objeto literal” no concede por sí solo autoridad para preasignar PK productivas. El objeto autoritativo de valores es un snapshot runtime materializado, no el plan declarativo inicial. Sin embargo, falta la operación normativa ejecutable que transforma y vuelve a sellar ese snapshot entre fases.

## 5. Manifest estático, manifest runtime y `.a11-runtime`

A11 reconoce con claridad un manifest runtime en `sys_get_temp_dir()/veciahorra-a11/<run_id>/manifest.json`, con directorio 0700, archivo 0600, escritura atómica y transporte por ruta+hash. No define de manera cerrada un segundo “manifest estático” versionado. El fixture/documentación cumple la función declarativa, pero llamarlo manifest estático sería una nueva decisión normativa.

La relación conceptual siguiente es compatible, pero todavía no es un contrato ejecutable completo:

```text
fixture versionado = contrato declarativo
manifest runtime = evidencia materializada y autoridad de valores de una corrida
```

`.a11-runtime` no es salida ni entrada autorizada. Su única semántica cerrada es la de ruta/residuo prohibido dentro del repositorio: debe estar ausente al inicio y al final. El runtime manifest autorizado vive fuera del repositorio, puede sobrevivir entre fases y procesos de una corrida, debe ser eliminado al terminar y nunca integra el fixture versionado.

Existe una tensión que debe resolver el harness: el manifest se describe como compartido/inmutable para hijos (`:218-223`) y, a la vez, debe incorporar atómicamente nuevas capturas (`:185-190`, `:207-214`). Puede resolverse con snapshots sellados por fase, pero esa solución no está contratada actualmente.

## 6. Autoincrement productivo y capturabilidad

La tabla siguiente es una clasificación conservadora. “No cerrado” significa que esta auditoría no convierte una inferencia de implementación en autoridad normativa.

| Entidad | Tabla/repositorio o API | Auto | ID retornado/legible | Preasignable | Capturable | Literal exigible |
|---|---|---:|---:|---:|---:|---:|
| User | WordPress users API | sí | sí | no autorizado | sí | no |
| Store | dominio/store repository | probable/según fixture | legible | no autorizado | sí | no |
| Product | WooCommerce post/product API | sí | sí | no autorizado | sí | no |
| Inventory | repositorio/metadata de inventario | según recurso | legible | no autorizado | sí | no |
| Cart/cart item | API de carrito/sesión | variable | legible por API | no cerrado | sí | no |
| Order/order item | WooCommerce order APIs | sí | sí | no autorizado | sí | no |
| Reservation | repositorio de reserva | sí | `insert_id`/lectura | no autorizado | sí | no |
| Checkout | servicio/repositorio checkout | sí | resultado/lectura | no autorizado | sí | no |
| Checkout order | relación checkout-order | sí/compuesta | resultado/lectura | no autorizado | sí | no |
| Payment session | repositorio payment session | sí | resultado/lectura | no autorizado | sí | no |
| Payment reconciliation | repositorio reconciliation | sí | resultado/lectura | no autorizado | sí | no |
| Business completion | repositorio completion | sí | resultado/lectura | no autorizado | sí | no |
| Durable schedule | `DurableRetryScheduleRepository` | sí | `insert_id`/snapshot | no autorizado | sí | no |

No existe autoridad para `ALTER TABLE AUTO_INCREMENT`, PK manuales, reset global ni truncate. La vía compatible es usar la API productiva, capturar el ID positivo devuelto o leído inmediatamente y mantener la creación/cleanup bajo ownership del caso. Que una API sea capturable no demuestra que el harness actual pueda transportar la captura.

## 7. Unicidad intercaso y modos de ejecución

A11 exige:

- unicidad lógica de alias y business identifier por `run_id`/case;
- unicidad de propiedad: cada recurso pertenece a un solo caso;
- ausencia de colisión durante la suite conjunta;
- conjuntos de recursos disjuntos mientras coexisten;
- estabilidad de external action ID dentro de una corrida y ausencia de segunda emisión en replay.

No exige que un entero nunca reaparezca históricamente. La unicidad numérica provista naturalmente por una tabla mientras las filas coexisten es suficiente para PK de esa tabla; no debe extrapolarse entre dominios ni entre bases limpias.

| Modo | Enteros exactos | Relaciones | Replay | Cleanup/manifest |
|---|---|---|---|---|
| WR-06 solo | pueden variar | deben ser estables | requiere sus capturas | selectivo; manifest de esa corrida |
| después de WR-05 | aumentan normalmente | estables por alias/ownership | no depende de WR-05 | recursos disjuntos |
| antes de WR-07 | no reserva rango | estables | no bloquea WR-07 | cleanup no global |
| 31 en una base | únicos mientras coexisten | namespaced | snapshot por caso/run | delete por IDs/ownership |
| transacción por caso | pueden variar tras rollback | estables dentro de transacción | crash puede invalidar rollback | no cubre scheduler externo por sí sola |
| cleanup físico | secuencia puede avanzar | estable | manifest válido hasta cierre | no reinicia autoincrement |
| proceso/base aislada | pueden repetirse entre bases | estables | debe transportar snapshot | aislamiento no sustituye validación |

La semántica de unicidad está cerrada; su enforcement integral sigue dependiendo del harness bloqueado.

## 8. IDs externos y business identifiers

`scheduled_action_id` pertenece al dominio del scheduler, no al autoincrement de tablas propias. Un double puede conceptualmente devolver un entero positivo configurado, pero no hay contrato global A11 que asigne un literal distinto a los 31 casos ni API de captura compartida. Debe capturarse una vez, persistirse en el snapshot de la corrida, reutilizarse para comparación y replay, y fallar ante cero, tipo incorrecto o segunda emisión.

`woocommerce_order_id` puede ser el ID runtime de una orden WooCommerce real o un recurso preparado por el harness; no debe asumirse double, alias o preexistencia sin la definición del caso. Cuando aplica, es captura runtime positiva. Los documentos WR-06 no autorizan mezclarlo con IDs propios.

`buy_order`, `session_id`, `token` y `transaction_reference` pueden ser literales deterministas y namespaced. Sirven para localización, reconciliación, replay, ownership y cleanup selectivo cuando el contrato del repositorio lo permite. No sustituyen PK en `fixture_ids`.

Fuente original, captura, conservación, exposición, validación y eliminación son roles distintos. El puerto/repositorio produce; el harness debería capturar; el manifest runtime debería conservar/exponer; validator/harness debería validar; teardown debería eliminar. Hoy los cinco últimos roles no están cerrados de extremo a extremo.

## 9. Evaluación de modelos y compatibilidad del harness

| Criterio | Modelo A: literales | Modelo B: runtime | Modelo C: híbrido |
|---|---|---|---|
| Compatible con autoincrement | no | sí | sí para rama runtime |
| Compatible con manifest | parcial | sí conceptualmente | no tipado actualmente |
| Compatible con harness actual | no | no | no |
| Repetible | solo forzando entorno | sí relacionalmente | posible, no cerrado |
| Aislable | frágil por rangos | sí por ownership | posible, no cerrado |
| Unicidad intercaso | requeriría tabla/rango | sí durante suite | posible con discriminante |
| Replay estable | sí si valores existen | sí con snapshot | sí con precedencia cerrada |
| Cleanup seguro | no si fuerza PK/global | sí por capturas | posible |
| Requiere cambio PHP | sí o manipulación de DB | sí, harness | sí, harness |
| Requiere corrección documental | sí | sí, del harness | sí, modelo y harness |
| Introduce segunda autoridad | probable | no si manifest es único | riesgo alto |

Modelo A: no viable; contradice la ausencia de autorización para forzar PK y no tolera autoincrement variable.

Modelo B: viable conceptualmente y consistente con el manifest runtime documentado, pero **no implementable con el contrato/harness actual**.

Modelo C: no viable ahora; los campos no están discriminados globalmente y fixture literal más snapshot runtime puede crear dos autoridades. Que un double pueda controlar un ID no basta para autorizar el híbrido.

El archivo `tests/manual/support/durable-retry-a11-coordinator.php` solo crea un JSON desde arrays iniciales (`:83-111`), ejecuta workers esperados (`:113-181`) y elimina archivos temporales (`:184-190`). No ofrece `capture`, `resolve`, `assertSame`, mutation/snapshot por fase, rehash y redistribución; tampoco implementa cleanup de DB/Action Scheduler. Los child worker, HTTP stub, loader, parser, validator y H1-H5 exigidos no existen. `runConcurrent()` itera procesos secuencialmente (`:149-153`).

Por ello no puede demostrarse que el harness:

- capture PK creadas ni les asigne aliases normativos;
- transporte setup → first delivery → replay/crash;
- selle un nuevo hash tras cada incorporación atómica;
- rechace uso antes de captura, duplicados o tipos inválidos;
- mantenga namespace por case/run entre procesos;
- limpie recursos productivos y externos tras fallo parcial.

Se alcanza aquí la primera condición de detención: **el harness no puede transportar capturas entre fases con un contrato suficiente**. Las secciones siguientes registran consecuencias probatorias, no diseñan una implementación.

## 10. Autoridad runtime y reglas de captura: estado no cerrado

La fuente original del valor puede identificarse (API/repositorio/puerto), y el manifest runtime está nominado como autoridad conservada de la corrida. Falta autoridad mínima para el componente que captura, sella, expone, valida y actualiza el path+hash entre fases.

Las operaciones conceptuales:

```text
capture(alias, positive-int value, source)
resolve(alias): positive-int
assertSame(alias, observed value)
```

son compatibles con Modelo B, pero no están autorizadas como API. Tampoco están cerrados el vocabulario global de aliases, fase de captura, idempotencia de segunda captura igual, rechazo de segunda captura distinta, rechazo de `0`, negativo, string o `null`, persistencia cross-process, namespace ni lifecycle de eliminación. Esta auditoría no completa esas reglas silenciosamente.

No debe haber valores divergentes entre fixture y runtime manifest: el fixture debe declarar referencias, no duplicar valores capturados. Esta regla es una conclusión de consistencia; requiere corrección normativa del harness antes de implementarse.

## 11. Cleanup, aislamiento y reproducibilidad

La estrategia autorizada es rollback donde sea seguro más delete selectivo por IDs capturados/business identifiers, usando APIs productivas y orden referencial. Truncate y reset de autoincrement están prohibidos. Proceso aislado no equivale a base aislada; base/schema aislados no están autorizados por defecto. Action Scheduler exige su API/ownership y no queda necesariamente cubierto por una transacción SQL local.

En fallo intermedio, el manifest debe conservar los IDs conocidos para cleanup/recovery. El coordinator actual elimina archivos, no demuestra limpieza de filas ni acciones. La compatibilidad de cleanup queda documentalmente definida en intención, pero no cerrada operacionalmente por el bloqueo de capturas.

Al repetir WR-06 en entornos limpios deben repetirse exactamente:

- key set, cardinalidades, relaciones y aliases;
- business identifiers según su regla namespaced;
- expected actions y expected result;
- estabilidad interna first delivery/replay.

No tienen que repetirse las PK físicas ni IDs externos no configurados literalmente. Esta reproducibilidad es compatible con Modelo B, pero aún no certificable.

## 12. Impacto sobre WR-06

Modelo literal requeriría tabla global, rango reservado, todos los IDs, action ID y autoridad para forzar PK; no existe y no se recomienda.

Modelo runtime requeriría shape declarativo, alias por entidad, fuente y fase de captura, manifest runtime sellado, resolución cross-process, igualdad en replay, unicidad por ownership y cleanup selectivo. Los documentos cubren partes, pero el harness no cierra su ejecución.

Modelo híbrido requeriría discriminante por campo, literales de doubles, PK runtime y precedencia única. No existe esa clasificación y no debe introducirse para WR-06 aisladamente.

Impacto directo: las PK de setup y los IDs producidos en `first_delivery`, incluido `scheduled_action_id` cuando corresponda, no pueden convertirse hoy en valores autoritativos transportables y verificables por replay. `fixture_ids.values` de WR-06 continúa bloqueado y no se publica su objeto.

## 13. Consecuencias globales para los 31 casos

La regla debe ser **global para los 31 casos**, con variación declarativa por dominio dentro de un solo modelo, no una excepción improvisada para WR-06. Adoptar dos shapes o dos autoridades causaría divergencia de loader, aliases, cleanup y orden de ejecución.

Modelo B puede abarcar valores producidos por doubles: el valor sigue siendo runtime aunque su fuente esté configurada. Así no hace falta un Modelo C para distinguir origen controlado de origen autoincremental. Los 31 casos pueden declarar fuentes y capturar resultados bajo el mismo protocolo.

La migración documental mínima debe cerrar el harness global antes de reabrir cualquier fixture individual. No debe cambiar matrices, fixtures, manifest ni PHP productivo durante esa corrección.

## 14. Pruebas conceptuales 1–10

| # | Escenario | Modelo/guardia necesaria | Determinismo, colisión y fail-closed |
|---:|---|---|---|
| 1 | auto distinto por corrida | B; aliases+capture | relaciones iguales; enteros distintos permitidos |
| 2 | WR-06 solo | B; namespace run/case | sin dependencia de orden; fail si falta captura |
| 3 | WR-06 tras 20 casos | B; ownership | PK cambian, relación no; no colisión lógica |
| 4 | fallo tras payment session | B; snapshot atómico inmediato | cleanup por capturas; fail si no fue sellada |
| 5 | fallo tras schedule interno | B; capturar antes de siguiente efecto | recovery usa mismo ID; fail si snapshot incompleto |
| 6 | scheduler devuelve ID distinto | B; capture result, no expectativa literal | estable dentro de corrida; tipo/positividad obligatorios |
| 7 | replay mismo proceso | B; resolve desde autoridad | no usar memoria como autoridad paralela |
| 8 | replay otro proceso | B; ruta+hash y namespace | fail si hash/path no validan |
| 9 | falta manifest antes de replay | B | fail-closed; replay no ejecuta efectos |
| 10 | manifest contiene ID ajeno | B; ownership validator | fail-closed; no resolve ni cleanup ajeno |

## 15. Pruebas conceptuales 11–20

| # | Escenario | Modelo/guardia necesaria | Determinismo, colisión y fail-closed |
|---:|---|---|---|
| 11 | alias recapturado distinto | B; single assignment | fail-closed; nunca reemplazar |
| 12 | business ID duplicado | B; namespace/unique check | fail antes de crear efectos dependientes |
| 13 | cleanup parcial deja durable | B; postcondition por ownership | caso falla y residuo se reporta |
| 14 | cleanup no reinicia auto | B | permitido; próxima corrida conserva relaciones |
| 15 | dos casos concurrentes | B; manifest/namespace por caso | sin aliases/rutas compartidos; fail ante cruce |
| 16 | fixture preasigna PK prohibida | A rechazado; B | validación rechaza literal antes de setup |
| 17 | double devuelve cero | B | rechazo `positive-int`, sin persistir captura |
| 18 | ID serializado como string | B | validator rechaza; no coerción silenciosa |
| 19 | `.a11-runtime` al iniciar | todos | guardia fail-closed antes de efectos |
| 20 | `.a11-runtime` al finalizar | todos | certificación falla; limpieza obligatoria |

Los escenarios demuestran la suficiencia conceptual de B y, simultáneamente, las funciones ausentes que impiden autorizarlo hoy. No constituyen una especificación implementable.

## 16. Primer bloqueo y recomendación normativa única

Primer bloqueo, únicamente:

```text
scope: A11 global
category: harness
field: tests/manual/support/durable-retry-a11-coordinator.php::runtime_capture_transport
reason: el contrato exige incorporar IDs capturados al manifest antes de continuar y reutilizarlos por ruta+hash en crash/replay, pero el coordinator solo serializa fixture_ids iniciales; no define captura/resolución por alias, snapshots sellados por fase, rehash, propagación cross-process ni validación de ownership/tipo.
required_authority: contrato normativo global del harness que defina single-assignment namespaced, captura por fase, resolución, igualdad, escritura atómica, rehash y transporte a hijos/replay, validación fail-closed y cleanup por valores capturados.
impact_on_wr_06: sus PK de setup y sus IDs de first_delivery no pueden adquirir autoridad única verificable para replay y cleanup; fixture_ids.values permanece sin materializar.
```

Recomendación única: **D. Corregir primero el harness para soportar capturas.**

- Documento siguiente: `docs/durable-retry-production-activation-a11-runtime-capture-harness-normative-correction.md`.
- Alcance: global para los 31 casos.
- Contrato a cerrar: lifecycle declarativo→captura→snapshot sellado→replay→cleanup, API conceptual tipada, ownership, fases, path+hash y errores fail-closed.
- Siguen prohibidos: PHP productivo, harnesses ejecutables, fixtures PHP/JSON, manifest estático/runtime, `.a11-runtime`, matriz, asignaciones de IDs, factories/allocators, schema, configuración y artifacts.
- Condición para reanudar WR-06: corrección normativa global aprobada y auditoría posterior que demuestre autoridad única, transporte entre fases y cleanup sin asignar PK productivas.

## 17. Resultado de cierre por dimensión

| Dimensión | Cerrada/viable | Fundamento |
|---|---|---|
| Modelo A viable | no | forzaría/reservaría PK sin autoridad |
| Modelo B viable | no actualmente; sí conceptual | bloqueado por harness |
| Modelo C viable | no | discriminante y precedencia ausentes |
| significado de determinismo | sí | B+C+D+E, no entero histórico |
| autoridad de valores | no | productor identificable, conservador/transporte incompleto |
| manifest estático | no | no hay artefacto separado cerrado |
| manifest runtime | no de extremo a extremo | ruta/shape existen, lifecycle de capturas no |
| semántica de `.a11-runtime` | sí | residuo de repo prohibido, no manifest autorizado |
| compatibilidad del harness | no | coordinator parcial y H1-H5 ausentes |
| capturas entre fases | no | no capture/resolve/rehash/propagación |
| unicidad intercaso | sí semánticamente | ownership lógico y durante suite; no histórica |
| IDs externos | no globalmente | fuente controlable/capturable, regla global ausente |
| business identifiers | sí conceptualmente | literales namespaced, no sustituyen PK |
| cleanup | no operacionalmente | intención selectiva, transporte/teardown incompletos |
| reproducibilidad | sí semánticamente | estructura/relaciones/resultados, no PK |
| aplicación a 31 casos | sí como alcance | debe ser global; mecanismo aún bloqueado |

No se evalúan estas respuestas como certificación de implementación. Toda dimensión dependiente del primer bloqueo permanece fail-closed.

## 18. Veredicto

El Modelo A contradice las restricciones de PK productivas. El Modelo C no está tipado y arriesga dos autoridades. El Modelo B responde correctamente al determinismo relacional y funcional, a la estabilidad intra-corrida y al aislamiento por ownership, pero el harness vigente no puede materializar ni transportar su autoridad de valores. La corrección mínima siguiente debe ser exclusivamente normativa y global sobre el harness de capturas.

```text
A11 FIXTURE ID VALUES BLOQUEADOS POR CONTRATO DE HARNESS INSUFICIENTE
```
