# Corrección normativa global del transporte de capturas runtime A11

Estado: contrato normativo probatorio y fail-closed. Fecha: 2026-08-03.

Veredicto de partida adoptado sin reapertura:

```text
A11 FIXTURE ID VALUES BLOQUEADOS POR CONTRATO DE HARNESS INSUFICIENTE
```

Este documento define el contrato futuro del harness para los 31 casos. No lo implementa, no asigna IDs y no materializa WR-06.

## 1. Base y autoridades inspeccionadas

La corrección se inició sobre `main`, HEAD `847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`, staging vacío, sin `.git/index.lock`, sin `.a11-runtime`, sin PHP residual, 504 archivos en `artifacts/` y una aparición del accessor tipado.

Los seis antecedentes exigidos conservaron sus SHA-256: cinco documentos WR-06 y `docs/durable-retry-production-activation-a11-fixture-id-value-authority-readiness-audit.md`. También se revalidaron 5/5 hashes protegidos y 9/9 antecedentes.

Clasificación: `AP` autoridad productiva; `CEH` contrato ejecutable del harness; `ADN` antecedente documental normativo; `EP` evidencia de prueba; `INN` inferencia no normativa.

| # | Autoridad | Ruta, símbolo/sección y líneas | Clase | Hallazgo |
|---:|---|---|---|---|
| 1 | coordinador | `tests/manual/support/durable-retry-a11-coordinator.php:1-198` | CEH | implementación parcial actual |
| 2 | entrada principal | `DurableRetryA11Coordinator::run()`, `:113-147` | CEH | recibe invocation en padre |
| 3 | hijos | `run() :120-146`, `startHttpServer() :164-181` | CEH | `proc_open`, PID y terminación |
| 4 | protocolo I/O | `run() :124`, `startHttpServer() :170-175` | CEH | args/ruta+hash; stdout/stderr pipes o archivos |
| 5 | stdout/stderr | `run() :131-146` | CEH | captura no bloqueante en memoria |
| 6 | exit codes | `run() :143-146` | CEH | `proc_close`; contrato semántico incompleto |
| 7 | serialización | `allocate() :91-108`, `lastResult() :196` | CEH | JSON manifest y JSON-lines permisivo |
| 8 | orden de fases | documentos A11 de fixture/crash/replay; no orquestado en coordinator | ADN/CEH | secuencia normativa, implementación ausente |
| 9 | excepciones | `:62-73`, `:85-89`, `:115-126`, `:193-196` | CEH | fail-fast parcial |
| 10 | timeout | `:133-143`, invocation `:13-20` | CEH | deadline y kill, sin hijos observados |
| 11 | residuos | `$processes :51-52`, stop `:178-181` | CEH | registro parcial, sin guardia terminal completa |
| 12 | `.a11-runtime` | auditoría de autoridad §5, §17 y contratos A11 | ADN | ruta del repo prohibida |
| 13 | manifest estático | fixture contract `:57-93`; complementary `:136-165` | ADN | fixture declarativo y manifest runtime histórico |
| 14 | shape fixture | fixture contract `:78-93` y correcciones WR-06 | ADN | key set/cardinalidades cerradas |
| 15 | `fixture_ids` | fixture contract `:78-93`; auditoría de autoridad §§3-10 | ADN | valores runtime, 15 listas |
| 16 | IDs runtime | fixture contract `:185-190`, `:207-214` | ADN | incorporación atómica antes de continuar |
| 17 | ownership | complementary `:383-425`; fixture contract `:241-256` | ADN | run/case y cleanup selectivo |
| 18 | ejecución aislada | complementary matriz `:383-425` | ADN | independencia de orden |
| 19 | ejecución conjunta | fixture contract `:218-223`; H1-H5 `:363-381` | ADN | autoridad por caso, suite común |
| 20 | repetibilidad | auditoría de autoridad §§3, 7 y 11 | ADN | relaciones/resultados, no PK histórica |
| 21 | cleanup/rollback | fixture contract `:241-256`; coordinator `:184-190` | ADN/CEH | norma selectiva; código solo limpia archivos |
| 22 | Scheduler doubles | pruebas `durable-retry-external-scheduler-*` y WR-06 expected/actions | EP/ADN | ID controlable pero siempre validable |
| 23 | Webpay doubles | correcciones WR-06 financiera/resultado/Webpay | ADN/EP | datos controlados, dominio separado |
| 24 | helpers con PK | repositorios/APIs usados por pruebas manuales | AP/EP | PK devuelta o inmediatamente legible |
| 25 | contexto entre fases | manifest por ruta+hash `:116-124`; `$manifests :49-50` | CEH | filesystem, no store tipado en memoria |
| 26 | procesos independientes | complementary y crash/replay contract | ADN | fase puede ejecutar en hijo distinto |
| 27 | temporales | fixture contract y auditoría anterior | ADN | fuera del repo antes; este contrato elimina filesystem runtime |
| 28 | validadores | `assertInvocation/assertCase/assertRun :193-196`; docs de hashes | CEH/ADN | insuficientes para captures |
| 29 | Git/filesystem | base/guardias A11 | ADN/EP | staging y rutas protegidas |
| 30 | 31 casos | complementary `:383-425` | ADN | catálogo completo y cerrado |

## 2. Respuesta binaria y autoridad única

Respuesta: **sí**. Puede definirse un contrato ejecutable y determinista en el que el coordinador conserva en memoria la autoridad runtime, entrega snapshots sellados y recibe únicamente deltas validados mediante `stdin/stdout`, sin `.a11-runtime` ni manifest runtime persistente.

Regla central:

```text
el coordinador A11 es la única autoridad de conservación,
resolución y transporte de valores runtime del caso
```

| Responsabilidad | Autoridad |
|---|---|
| crear PK | repositorio/API productiva |
| producir ID externo | puerto/double correspondiente |
| observar | ejecutor de fase |
| validar captura | coordinador A11 |
| conservar entre fases | coordinador A11 |
| resolver para replay | coordinador A11 |
| proporcionar a cleanup | coordinador A11 |
| eliminar estado runtime | coordinador A11 |

El fixture declara el plan, nunca los enteros observados. Un hijo solo observa y propone un delta; al terminar pierde toda autoridad. La memoria del coordinador es la autoridad viva de una corrida. Si el coordinador muere, la corrida no es reanudable.

## 3. Ciclo y fases normativas

```text
bootstrap → setup → first_delivery → replay → assertions_finales
→ cleanup → guardias_finales
```

| Fase | Entrada | Captura permitida | Prohibida | Salida/sellado | Fallo |
|---|---|---|---|---|---|
| bootstrap | fixture+plan | solo business IDs literales del plan | toda PK/ID runtime | S0 sellado antes de setup | no inicia hijo |
| setup | plan+S0 readonly | aliases con `source_phase=setup` | aliases de otras fases/casos | delta D1; coordinador sella S1 | intenta cleanup parcial desde S0+D1 válido |
| first_delivery | plan+S1 readonly | fase first_delivery | redefinir setup | D2; sella S2 | cleanup con último snapshot válido y delta válido parcial |
| replay | plan+S2 readonly | solo aliases declarados replay | reemplazar identidad previa | D3; sella S3 | cleanup desde S2 más capturas validadas |
| assertions_finales | plan+S3 readonly | ninguna captura persistente | mutación | delta vacío; sella S4 | cleanup obligatorio |
| cleanup | plan+S4 readonly | ninguna | crear/reemplazar captura | reporte de eliminación, sin snapshot nuevo | suite falla cerradamente |
| guardias_finales | estado vacío esperado | ninguna | efectos | verifica procesos/rutas/datos | certificación falla |

Cada snapshot queda disponible únicamente después de integrar y validar el delta anterior. Ninguna fase posterior comienza tras fallo. Bootstrap contiene exclusivamente plan declarativo, business identifiers predefinidos y metadatos no secretos.

## 4. Plan declarativo estático

Shape exacto global:

```php
'capture_plan' => [
    '<alias>' => [
        'type' => '<closed-type>',
        'owner' => '<case-id>',
        'source_phase' => '<setup|first_delivery|replay>',
        'source' => '<closed-source>',
        'cardinality' => '<exactly-zero|exactly-one|exactly-N|zero-or-one|one-or-more>',
        'required_before' => '<first_delivery|replay|assertions_finales|cleanup>',
        'immutable' => true,
        'equality' => '<none|same-on-replay>',
        'cleanup' => true,
    ],
]
```

Para `exactly-N`, se agrega exclusivamente `'count' => positive-int`; para toda otra cardinalidad `count` está prohibido. El mapa está ordenado canónicamente por alias. `source` pertenece al catálogo cerrado del fixture de cada caso y no admite clase/callback arbitrario.

No contiene valores runtime, PK inventadas, placeholders `AUTO/TBD/RUNTIME`, `null`, cero, negativos, código, callbacks, filesystem ni secretos. El plan se valida y hashea antes de S0; luego es inmutable.

## 5. Namespace y catálogo de aliases

Gramática exacta:

```text
^A11-(?:OP|CON|CR|WR|EX)-[0-9]{2}\.[a-z][a-z0-9_]{0,31}\.[a-z][a-z0-9_]{0,47}(?:\.(?:[0-9]+|[a-z][a-z0-9_]{0,31}))?$
```

El alias identifica case, domain, logical name y, cuando aplica, índice/rol. Usa punto como separador, no contiene espacios, es sensible a mayúsculas, exige case ID uppercase y segmentos lowercase, y no supera 128 bytes UTF-8. El índice es decimal canónico: `0` o dígitos sin cero inicial. Los aliases se ordenan por bytes UTF-8 ascendentes.

Prefijos `A11-system`, dominios `system`, `coordinator`, `snapshot` y nombres que comiencen `_` están reservados y prohibidos a fixtures. Cada alias es único en el catálogo global por su nombre completo y su prefijo debe coincidir exactamente con `owner`. Alias desconocido o ajeno produce fallo antes de resolver/integrar. No existen aliases globales sin propietario.

## 6. Catálogo cerrado de tipos

| Tipo | Representación/validación | Serialización/comparación |
|---|---|---|
| `positive-int` | entero PHP/JSON, `1..PHP_INT_MAX`; no coerción | decimal JSON; identidad numérica estricta |
| `non-empty-string` | string UTF-8 válido, 1..1024 bytes, sin NUL | JSON string; bytes exactos |
| `utc-second-timestamp` | string `YYYY-MM-DDTHH:MM:SSZ`, fecha UTC real | forma exacta; igualdad byte a byte |
| `sha256-lowercase-hex` | string `/^[a-f0-9]{64}$/D` | lowercase obligatorio; igualdad exacta |
| `boolean` | solo `true` o `false` | literal JSON; identidad estricta |

`positive-int` rechaza cero, negativos, floats, strings numéricos, notación científica, booleanos, `null`, arrays y objetos. No se normalizan valores capturados. UTF-8 inválido, tipo desconocido o canonicalización imposible produce `wrong_type`/`invalid_delta` y no altera estado.

No se agregan otros tipos hasta que un caso de la matriz lo exija mediante corrección normativa.

## 7. Operaciones normativas

| Operación | Precondición y resultado | Repetición/efecto |
|---|---|---|
| `capture(alias,value,source)` | plan conocido, owner/fase/source/tipo correctos; conserva valor | primera válida acepta; misma fuente+valor converge sin evento/contador nuevo; distinto falla |
| `resolve(alias)` | capturado, owner vigente, antes de cleanup | devuelve valor tipado readonly; ausente/limpio falla |
| `assertSameCapture(alias,observed)` | captura existente y tipo válido | igualdad estricta acepta sin mutar; diferencia falla |
| `seal(phase)` | delta válido, cardinalidades `required_before` satisfechas | crea una vez el siguiente snapshot; segundo sellado falla |
| `snapshot(phase)` | snapshot ya sellado y case/run coincidentes | representación readonly; no concede mutabilidad |

Toda operación valida ownership, fase, fuente y tipo antes de efectos. No hay overwrite, coerción, fallback ni alias implícito. Una segunda captura idéntica conserva el evento original, su fuente y fase; no incrementa métricas de captura.

## 8. Delta de captura

Envelope de salida exacto, versión `veciahorra-a11-capture/v1`:

```json
{"schema":"veciahorra-a11-capture/v1","kind":"capture_delta","case_id":"A11-WR-06","execution_id":"...","phase":"first_delivery","base_snapshot_hash":"<64 lowercase hex>","captures":{"<alias>":{"type":"positive-int","value":123,"source":"<closed-source>"}}}
```

Key set superior exacto y en orden canónico: `schema`, `kind`, `case_id`, `execution_id`, `phase`, `base_snapshot_hash`, `captures`. Cada capture tiene exactamente `type`, `value`, `source`. `captures` es mapa ordenado por alias; no una lista. No admite claves extra, objetos PHP serializados, rutas, código ni secretos.

El hash base es obligatorio. Alias no declarado, overwrite, salida JSON inválida, más de una línea no vacía, prefijo/sufijo contaminante o envelope duplicado produce `invalid_delta` o `unexpected_child_output`. El límite codificado es 1,048,576 bytes por envelope, incluyendo LF final; excederlo termina y rechaza el hijo. Profundidad JSON máxima: 8. No se confía en orden recibido: se valida y se vuelve a canonicalizar.

## 9. Snapshots sellados

Son necesarios S0, S1, S2, S3 y S4. Shape exacto:

```text
schema, kind, case_id, execution_id, snapshot_name, phase,
plan_hash, previous_snapshot_hash, captures, sealed, snapshot_hash
```

`schema=veciahorra-a11-capture/v1`, `kind=capture_snapshot`, `sealed=true`. S0 usa `previous_snapshot_hash=null`; los demás usan el hash exacto del anterior. `captures` ordena aliases e incluye para cada uno `type`, `value`, `owner`, `source`, `source_phase`. El plan no se duplica: se referencia por `plan_hash`.

Una vez sellado no se modifica, reemplaza ni elimina antes de cleanup. La fase siguiente recibe copia serializada readonly. Todo delta declara el hash exacto del snapshot de entrada. Capturas anteriores se copian sin reinterpretación; nuevas capturas aparecen solo en el siguiente snapshot.

## 10. JSON canónico, hash y rehash

Algoritmo: SHA-256 lowercase sobre JSON canónico UTF-8 sin BOM ni LF.

Reglas exactas:

- objetos: claves ordenadas lexicográficamente por bytes UTF-8;
- listas: preservan orden normativo; nunca se reordenan como sets;
- strings: UTF-8 válido, sin normalización Unicode, escapes JSON obligatorios solo para comillas, backslash y controles;
- slash y Unicode no se escapan;
- enteros: decimal canónico, sin signo positivo ni ceros iniciales;
- boolean/null: literales JSON lowercase; floats están prohibidos;
- sin espacios ni saltos de línea;
- `snapshot_hash` se excluye al calcular el propio hash;
- `previous_snapshot_hash` y `plan_hash` sí se incluyen;
- al transportar, el LF delimitador no integra el hash.

El coordinador canonicaliza y recalcula plan, snapshots y deltas. Nunca confía en un hash producido por el hijo. Tras integrar un delta crea un nuevo objeto, calcula su hash y conserva ambos snapshots; no “rehash” mutable.

## 11. Canal interproceso y envelopes

Canal único seleccionado: **stdin/stdout** mediante pipes anónimos de `proc_open`. Argumentos solo pueden identificar el entrypoint PHP; no transportan plan, snapshot ni valores. Variables de entorno, archivos y pipes dedicados adicionales quedan prohibidos para capturas.

Entrada stdin: exactamente un JSON canónico seguido de LF y EOF:

```text
schema, kind=phase_request, case_id, execution_id, phase,
timeout_seconds, capture_plan, input_snapshot
```

Salida stdout: exactamente un `capture_delta` canónico seguido de LF y EOF. Stdout es solo datos. Logs pueden ir a stderr durante fallo; éxito exige stderr vacío. Codificación UTF-8, versión `veciahorra-a11-capture/v1`, máximo 1,048,576 bytes por envelope y profundidad 8.

Timeout proviene del caso/invocation validado, mínimo 1 y máximo 30 segundos; no se amplía ni reintenta. Exit `0` exige delta válido y stderr vacío; exit `64` input/contrato inválido; `65` error de datos/delta; `70` fallo interno del hijo; `75` fallo productivo transitorio observado pero no reintentado; `124` timeout impuesto por coordinador. Todo otro exit es fallo no clasificado y cleanup obligatorio.

Case, execution, phase y base hash deben coincidir exactamente. EOF prematuro, truncamiento, segunda línea, bytes tras LF, salida duplicada o stdout contaminado falla. El coordinador drena stdout/stderr sin bloqueo, limita bytes, termina árbol al timeout y no inicia siguiente fase.

## 12. Propagación por fase

Setup: el coordinador entrega plan+S0; el hijo crea recursos y devuelve D1; el coordinador valida, integra y sella S1.

First delivery: recibe S1, resuelve referencias desde esa copia, ejecuta una vez, devuelve solo capturas nuevas D2; el coordinador sella S2.

Replay: recibe S2, reutiliza IDs y no redefine identidades; devuelve solo observaciones declaradas para replay D3; el coordinador sella S3.

Assertions: recibe S3, devuelve delta vacío y evidencia por su contrato separado; el coordinador sella S4 si todas las igualdades pasan.

Cleanup: recibe plan+S4 readonly, o el último snapshot válido más capturas de un delta parcial que sí logró validarse. No produce capturas. Reporta recursos eliminados por el protocolo de cleanup; solo tras verificarlo el coordinador descarta S0-S4, plan y execution state.

## 13. Ownership y ejecución

Cada corrida genera en memoria un `execution_id` con gramática existente de `run_id`; se crea una vez por coordinator y case. Toda captura pertenece exactamente a `(execution_id, case_id)` y el alias contiene el case ID.

El coordinador rechaza:

- alias cuyo prefijo no coincide con owner/case;
- envelope o snapshot de otra ejecución;
- valor observado cuya fila no pueda relacionarse mediante ID y business identifier/owner esperado cuando el caso exige esa prueba;
- uso cruzado aunque el entero sea igual.

En aislado se aplican las mismas reglas. En suite conjunta cada caso tiene store de memoria independiente. En concurrencia entre casos no se comparte snapshot ni mapa. No se exige unicidad histórica de PK. Un mismo entero en tablas/dominios distintos no colisiona; el mismo recurso físico atribuido a dos casos falla ownership.

## 14. Listas y cardinalidad

Las quince claves finales de `fixture_ids` son listas ordenadas de `positive-int`. Cardinalidades:

- `exactly-zero`: lista declarada vacía, sin aliases capturables;
- `exactly-one`: exactamente un alias;
- `exactly-N`: exactamente `count` aliases indexados `0..N-1`;
- `zero-or-one`: cero o un alias, sellado al llegar a `required_before`;
- `one-or-more`: al menos uno y conjunto cerrado por la fase fuente antes del sellado.

La captura es por alias indexado, no reemplazo atómico de lista. El orden final es índice numérico ascendente; para exactly-one usa el único alias lógico. Duplicar un entero dentro de la misma clave está prohibido. Cardinalidad incompleta o exceso falla al sellar. Se puede resolver un elemento por alias o la lista proyectada completa por key. Ninguna lista cambia después de su fase fuente sellada.

## 15. Relación con `fixture_ids`

Se adoptan nombres no ambiguos:

```text
fixture_id_plan = key set, orden, cardinalidades y aliases declarativos
resolved_fixture_ids = proyección validada de capturas positive-int selladas
```

`resolved_fixture_ids` es la única representación final de valores y se construye por el coordinador cuando todas las capturas requeridas para una fase existen. Fuente: store de capturas sellado, nunca fixture literal ni hijo. Conserva el orden normativo de las 15 claves y el orden por índice de cada lista.

Puede exponerse dentro del phase request readonly, reporte terminal en memoria y evidencia stdout final, pero no se versiona ni escribe a filesystem. Replay recibe la proyección de S2. Cleanup obtiene la proyección del último snapshot válido. Cardinalidad/tipo se revalidan en toda proyección.

## 16. IDs externos

`scheduled_action_id` y `woocommerce_order_id` usan el mismo mecanismo de alias, tipo `positive-int`, ownership case/execution y snapshot. Su `source` distingue el puerto/double/API correspondiente.

Cuando una autoridad de caso incluye el ID en una de las 15 claves, se proyecta a `resolved_fixture_ids`; de lo contrario pertenece a `resource_references`, otro mapa readonly del snapshot derivado de aliases, nunca una autoridad paralela. La clasificación exacta la determina el fixture contract del caso.

Los doubles pueden producir valores configurados, pero el hijo debe observarlos y el coordinador capturarlos. Replay resuelve el alias previo. Nueva emisión o valor diferente bajo `scheduled_action_id` produce `duplicate_capture_conflict`; una segunda programación queda prohibida.

## 17. Business identifiers

`buy_order`, `session_id`, `token` y `transaction_reference` pertenecen a `business_identifiers`, fuera de `fixture_ids`. Pueden ser literales `non-empty-string` en el plan, namespaced por case/execution cuando la autoridad del caso lo permita, y quedan incluidos por valor en S0 para replay y ownership.

Son inmutables, se validan con el catálogo de tipos y pueden localizar recursos para cleanup. No se capturan como PK, no se proyectan a las 15 listas y no sustituyen IDs físicos. Secretos reales quedan prohibidos; tokens son exclusivamente sintéticos según contrato de Webpay.

## 18. Replay y single application

Replay recibe exactamente S2, sellado tras first delivery. Resuelve los mismos aliases y falla ante captura requerida ausente, hash base diferente, snapshot ajeno o fase distinta. No depende de archivos, del proceso anterior ni de que un worker permanezca vivo.

No puede reemplazar `schedule_id`, `scheduled_action_id` ni identidad inicial. `assertSameCapture` verifica observaciones repetidas. Capturas nuevas solo se admiten si el plan declara `source_phase=replay`; no pueden redefinir aliases anteriores.

Single application se demuestra sobre la misma autoridad S2/S3: mismo schedule/action, cero segunda programación y mismos recursos funcionales. Una divergencia impide S3 y activa cleanup.

## 19. Cleanup conceptual

Cleanup recibe copia readonly del último snapshot válido y el plan. Elimina solo recursos del case/execution usando IDs capturados o business identifiers normados, respeta integridad referencial y APIs de Action Scheduler, y puede operar tras fallo parcial.

No exige todas las capturas: trabaja con el subconjunto validado existente, pero el reporte distingue recursos declarados no creados, creados/eliminados y no verificables. No reinicia autoincrement, no trunca, no elimina datos ajenos, no crea capturas ni deja hijos o `.a11-runtime`.

El coordinador descarta estado únicamente tras postcondiciones de cleanup y guardias. Ante cleanup incompleto la suite falla y conserva evidencia en memoria/stdout del coordinador hasta emitir el reporte; nunca persiste snapshot. El orden exacto de tablas/APIs queda como dependencia de una auditoría posterior de teardown y no bloquea este contrato conceptual.

## 20. Manifest estático y ausencia de persistencia runtime

El fixture/manifest estático puede declarar `capture_plan`, aliases, tipos, fases, cardinalidades, fuentes, igualdad, expected actions/result e invariantes. No puede contener PK finales, snapshot/hash runtime, estado de corrida ni residuos.

Agregar `capture_plan` requiere incrementar el schema estático futuro de `veciahorra-a11/v1` a `veciahorra-a11/v2`; v1 no se interpreta silenciosamente como v2. Esa modificación queda restringida a fixtures/schema de test solo si una ejecución futura la autoriza expresamente; no se realiza aquí.

No existe manifest runtime persistente en filesystem. Snapshots son objetos efímeros en memoria del coordinador, transportados por stdin/stdout, descartados después de cleanup, no versionados ni artifacts. `.a11-runtime` permanece prohibido. Si muere el coordinador, la corrida falla, un supervisor elimina hijos y ejecuta la estrategia externa de cleanup disponible; jamás reanuda desde residuos.

## 21. Catálogo de errores cerrado

| Error | Condición/momento | Exit hijo | Cleanup | Resultado |
|---|---|---:|---|---|
| `unknown_alias` | delta/resolve no declarado | 65 | sí si hubo efectos | fail-closed |
| `wrong_owner` | case/execution/alias ajeno | 65 | sí | fail-closed |
| `wrong_phase` | operación fuera de fase | 65 | sí | fail-closed |
| `wrong_type` | tipo/valor no exacto | 65 | sí | fail-closed |
| `missing_capture` | resolve/sellado requerido | 65 | sí | fail-closed |
| `duplicate_capture_conflict` | segunda captura distinta | 65 | sí | fail-closed |
| `cardinality_mismatch` | sellado/proyección | 65 | sí | fail-closed |
| `base_hash_mismatch` | recepción delta | 65 | sí | fail-closed |
| `invalid_snapshot` | antes de entregar fase | 64 | según efectos previos | fail-closed |
| `invalid_delta` | parse/key set/hash/size | 65 | sí | fail-closed |
| `unexpected_child_output` | stdout extra/stderr en éxito | 65 | sí | fail-closed |
| `cleanup_incomplete` | postcondición teardown | 70 | ya intentado | fail-closed |

Los códigos son resultado de protocolo, no nombres futuros de excepciones PHP. Ningún error permite siguiente fase ni reintento automático.

## 22. Presupuesto y límites

- aliases por caso: exactamente los declarados en el plan validado; no se aceptan dinámicos;
- capturas: máximo igual a la suma cerrada de cardinalidades del plan al finalizar sus fases;
- envelope: 1,048,576 bytes incluyendo LF;
- profundidad JSON: 8;
- procesos: uno activo por caso y fase; servidor loopback, cuando el caso lo exige, es recurso auxiliar explícito y no emite deltas;
- sellados: exactamente cinco, S0-S4, salvo fallo temprano;
- deltas: máximo uno por fase ejecutora, D1-D3, más uno vacío de assertions;
- reintentos de transporte: cero;
- filesystem runtime/capturas: cero escrituras;
- creaciones `.a11-runtime`: cero.

Timeout es configurable por invocation dentro de 1..30 segundos. Los máximos de aliases/capturas no inventan una cifra: derivan del plan cerrado y cardinalidades de cada caso.

## 23. Concurrencia

A11 permite concurrencia entre casos con coordinadores/stores aislados. Dentro de un caso, setup, first delivery, replay, assertions y cleanup son estrictamente secuenciales; un solo hijo ejecutor puede estar activo.

| Escenario | Regla |
|---|---|
| dos casos concurrentes | permitido con execution/store separados |
| dos hijos del mismo caso | prohibido |
| dos deltas mismo base | primero válido puede sellar; segundo se rechaza como snapshot antiguo |
| replay antes de S2 | prohibido `missing_capture/wrong_phase` |
| cleanup concurrente con replay | prohibido |
| alias textual igual entre casos | imposible por prefijo; falla catálogo |
| mismo entero físico dos casos | se valida recurso/ownership; recurso compartido falla |
| scheduler devuelve mismo ID | segundo caso falla ownership/colisión externa |
| hijo termina tras timeout | output se descarta; árbol terminado y cleanup |
| stdout después de cierre | se descarta y reporta unexpected output |

`runConcurrent()` actual es secuencial; este contrato no obliga paralelismo intra-case ni transforma esa función.

## 24. Matriz adversarial 1–15

| # | Escenario | Punto de rechazo/aceptación | Cleanup | Resultado |
|---:|---|---|---|---|
| 1 | captura positiva válida | aceptada al validar delta | normal | continúa y sella |
| 2 | string numérico | validar delta | si hubo efectos | fail-closed |
| 3 | cero | validar delta | si hubo efectos | fail-closed |
| 4 | alias desconocido | validar delta | si hubo efectos | fail-closed |
| 5 | alias otro caso | validar delta | sí | fail-closed |
| 6 | fuente incorrecta | validar delta | sí | fail-closed |
| 7 | fase incorrecta | validar delta | sí | fail-closed |
| 8 | segunda captura igual | aceptada como convergencia, sin nuevo evento | normal | continúa |
| 9 | segunda distinta | validar delta | sí | fail-closed |
| 10 | resolve prematuro | antes de ejecutar efecto dependiente | según previos | fail-closed |
| 11 | hash snapshot incorrecto | antes de ejecutar fase | según previos | fail-closed |
| 12 | delta con base antigua | validar delta | sí | fail-closed |
| 13 | JSON inválido | validar salida | sí | fail-closed |
| 14 | stdout con logs | validar salida | sí | fail-closed |
| 15 | stderr no vacío, exit 0 | validar resultado | sí | fail-closed |

## 25. Matriz adversarial 16–30 y WR-06

| # | Escenario | Punto de rechazo/aceptación | Cleanup | Resultado |
|---:|---|---|---|---|
| 16 | timeout hijo | coordinador al deadline | obligatorio | exit 124, fail-closed |
| 17 | muere tras crear fila | exit/protocolo incompleto | obligatorio con capturas conocidas/business IDs | fail-closed |
| 18 | setup incompleto | sellar S1 | obligatorio | fail-closed |
| 19 | replay sin S2 | antes de ejecutar | obligatorio | fail-closed |
| 20 | cleanup con captura faltante | cleanup usa subconjunto y verifica ownership | obligatorio | falla si queda recurso |
| 21 | lista cardinalidad errónea | sellar/proyectar | sí | fail-closed |
| 22 | IDs duplicados en lista | sellar/proyectar | sí | fail-closed |
| 23 | scheduled action cambia | assert/delta replay | sí | fail-closed |
| 24 | snapshot otro caso | antes de ejecutar | según previos | fail-closed |
| 25 | `.a11-runtime` inicial | guardia bootstrap | no efectos | fail-closed |
| 26 | `.a11-runtime` creada | guardia inmediata/final | sí | fail-closed |
| 27 | PHP residual | guardia inicial/final | terminar/validar árbol | fail-closed |
| 28 | segundo delta tras sellado | recepción | sí si proceso produjo efectos | fail-closed |
| 29 | repetición con PK distintas | aceptada | normal | relaciones/resultados idénticos |
| 30 | suite 31 ownership aislado | aceptada si guardias pasan | por caso | certificable |

Para WR-06, el plan podrá declarar aliases de `payment_session_id`, `payment_reconciliation_id`, `schedule_id`, `scheduled_action_id` y otras PK requeridas sin valores. First delivery crea/captura generation 1 y schedule; S2 conserva los IDs; replay resuelve los mismos; single application compara esa autoridad; cambio del action ID o segunda programación falla; cleanup elimina recursos capturados. Esta demostración no asigna valores ni reanuda WR-06.

## 26. Aplicación global a 31 casos

Todos los casos usan:

- envelope/version `veciahorra-a11-capture/v1`;
- catálogo de tipos y gramática de aliases únicos;
- JSON/hash canónico;
- ciclo S0-S4 y canal stdin/stdout;
- ownership `(execution_id,case_id)`;
- reglas de errores, cleanup y guardias.

Un caso sin IDs declara `capture_plan=[]` y `fixture_id_plan` con sus quince listas/cardinalidades aplicables, incluso vacías. Aun así recibe S0-S4 con captures vacías y hashes encadenados; esto evita un segundo transporte. No se autorizan excepciones WR-06 ni protocolos incompatibles.

## 27. Allowlist futura exacta

Implementación futura mínima: **exactamente cuatro archivos**.

| Estado | Archivo | Naturaleza/FQCN y responsabilidad |
|---|---|---|
| modificado | `tests/manual/support/durable-retry-a11-coordinator.php` | extiende `VeciAhorra\Tests\Manual\A11\DurableRetryA11Coordinator`: store en memoria, pipes stdin/stdout, lifecycle, PID/timeout/cleanup |
| nuevo | `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` | clases finales `DurableRetryA11CapturePlan`, `DurableRetryA11RuntimeCaptureStore`, `DurableRetryA11CanonicalJson`, `DurableRetryA11TransportEnvelopeValidator`; sin WordPress ni filesystem |
| nuevo | `tests/manual/durable-retry-a11-runtime-capture-test.php` | harness funcional procedural: operaciones, fases, WR-06 conceptual y proyección |
| nuevo | `tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php` | harness procedural: pipes, procesos, timeout, contaminación, adversariales y residuos |

Dependencias permitidas: PHP estándar, `proc_open`, clases del support file, coordinator y bootstrap de pruebas existente. Prohibidos: código productivo, vendor, docs, fixtures/JSON, manifest/schema, DB schema/migrations, configuración, artifacts y `.a11-runtime`. El schema estático v2 requerirá autorización separada y no integra esta allowlist.

Guardia exacta: diff limitado a esos cuatro paths, tres nuevos/uno modificado; staging vacío durante implementación. Suite mínima: `php -l` 4/4, harness funcional, infraestructura, 30 adversariales, ejecución vacía y con capturas, cross-process, timeout/árbol residual, `git diff --check`, allowlist y guardias `.a11-runtime`/artifacts/procesos. No se modifica PHP productivo.

## 28. Condiciones, cierre y veredicto

El coordinator puede conservar estado entre fases en un único proceso padre. `proc_open` ofrece el canal stdin/stdout efímero seleccionado. No se requiere reanudación tras muerte, filesystem runtime, PK en manifest estático ni cambio productivo. Ownership se determina por case+execution+alias; un solo hijo modifica indirectamente el store mediante delta validado; replay recibe S2 y cleanup el último snapshot. No quedan dos protocolos normativos.

Quedan cerrados por esta corrección: autoridad única, ciclo, plan, aliases, tipos, operaciones, deltas, snapshots, hash, canal, ownership, cardinalidades, proyección runtime, IDs externos, business IDs, replay, cleanup conceptual, relación del manifest estático, prohibición de persistencia runtime, errores, concurrencia, aplicación global y allowlist futura.

La implementación continúa prohibida hasta una ejecución expresamente autorizada. WR-06 permanece sin valores y sin materialización.

```text
A11 RUNTIME CAPTURE TRANSPORT IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
```
