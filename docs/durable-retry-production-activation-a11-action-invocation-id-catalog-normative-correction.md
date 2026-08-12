# Octava corrección normativa A11: catálogo determinista de `invocation_id`

## 1. Veredicto normativo

**A11 ACTION INVOCATION ID CATALOG IMPLEMENTABLE TRAS OCTAVA CORRECCIÓN NORMATIVA**
Esta autoridad agrega exclusivamente la fuente determinista y el catálogo exacto de los 62 `invocation_id` de `action_invocation_plan`. No implementa EA6 ni modifica autoridades anteriores.

## 2. Diagnóstico cerrado

Las autoridades anteriores fijan la gramática general de `invocation_id`, un valor ilustrativo, una entry por caso y fase, 31 casos, dos fases por caso, 62 entries y lookup exclusivo mediante `invocation_id`. No fijaban los 62 strings exactos, la producción de los doce dígitos, el orden de asignación, la fuente normativa del ordinal ni la relación reproducible entre `case_id`, `phase` e `invocation_id`.

La gramática por sí sola no autoriza elegir un valor. Esta corrección cierra únicamente esa indeterminación.

## 3. Fuente autoritativa de casos

Los 31 `case_id` se extraen literalmente de:

- Ruta: `docs/durable-retry-production-activation-a11-expected-actions-case-specific-normative-correction.md`
- SHA-256: `9BF06D72A02ED7DCF08CF26D187234018291457AC9909849C6CFF58BB74155FB`

Se prohíben invención, normalización, cambio de caja, eliminación de prefijos, aliases, IDs runtime y orden accidental de arrays.

## 4. Orden canónico bytewise y catálogo de casos

La comparación es binaria ascendente sobre los bytes ASCII/UTF-8 literales: sensible a caja, independiente de locale, sistema operativo, orden documental e inserción, sin transformación, natural sort ni comparación numérica de fragmentos. La tabla primaria de `case_rank` es:

1. `A11-CON-01`
2. `A11-CON-02`
3. `A11-CON-03`
4. `A11-CON-04`
5. `A11-CON-05`
6. `A11-CR-01`
7. `A11-CR-02`
8. `A11-CR-03`
9. `A11-CR-04`
10. `A11-CR-05`
11. `A11-EX-01`
12. `A11-EX-02`
13. `A11-EX-03`
14. `A11-EX-04`
15. `A11-EX-05`
16. `A11-EX-06`
17. `A11-EX-07`
18. `A11-EX-08`
19. `A11-EX-09`
20. `A11-EX-10`
21. `A11-OP-01`
22. `A11-OP-02`
23. `A11-OP-03`
24. `A11-OP-04`
25. `A11-OP-05`
26. `A11-WR-01`
27. `A11-WR-02`
28. `A11-WR-03`
29. `A11-WR-04`
30. `A11-WR-05`
31. `A11-WR-06`

## 5. Orden canónico de fases

Cada caso produce exactamente dos entries consecutivas:

1. `first_delivery`, `phase_rank = 1`, sufijo literal de ID `fd`.
2. `replay`, `phase_rank = 2`, sufijo literal de ID `replay`.

No se permite ordenar alfabéticamente, invertir, omitir ni agregar fases.

## 6. Ordinal normativo

```text
ordinal = ((case_rank - 1) * 2) + phase_rank
```

`case_rank` pertenece a `1..31`; el ordinal es entero decimal en `1..62`. Por tanto, las parejas extremas producen `1`, `2`, `61` y `62` en el orden fijado.

## 7. Segmento decimal de doce dígitos

El ordinal se representa en decimal ASCII con ceros a la izquierda hasta exactamente doce caracteres: `1 → 000000000001`, `2 → 000000000002`, `9 → 000000000009`, `10 → 000000000010`, `61 → 000000000061`, `62 → 000000000062`.

Los doce dígitos significan exclusivamente el ordinal canónico estático. Se prohíben hexadecimal, base36, hash, timestamp, random, UUID, `execution_id`, PID, CRC, posición de fixture, contador de ejecución y longitudes distintas de doce.

## 8. Gramática y construcción exactas

La cuarta autoridad mantiene literalmente esta gramática:

```regex
^a11_[0-9]{12}_(?:setup|fd|replay|assertions|cleanup|observe_[a-z_]+)$
```

Para estas 62 entries la envolvente exacta es `a11_` + `twelve_digit_ordinal` + `_fd` cuando `phase=first_delivery`, o `_replay` cuando `phase=replay`. El ejemplo ilustrativo previo deja de ser fuente suficiente para seleccionar IDs; la gramática estructural permanece vigente.

## 9. Catálogo normativo readonly de 62 entries

| ordinal | twelve_digit_ordinal | invocation_id | case_id | phase |
| ------: | -------------------- | ------------- | ------- | ----- |
| 1 | `000000000001` | `a11_000000000001_fd` | `A11-CON-01` | `first_delivery` |
| 2 | `000000000002` | `a11_000000000002_replay` | `A11-CON-01` | `replay` |
| 3 | `000000000003` | `a11_000000000003_fd` | `A11-CON-02` | `first_delivery` |
| 4 | `000000000004` | `a11_000000000004_replay` | `A11-CON-02` | `replay` |
| 5 | `000000000005` | `a11_000000000005_fd` | `A11-CON-03` | `first_delivery` |
| 6 | `000000000006` | `a11_000000000006_replay` | `A11-CON-03` | `replay` |
| 7 | `000000000007` | `a11_000000000007_fd` | `A11-CON-04` | `first_delivery` |
| 8 | `000000000008` | `a11_000000000008_replay` | `A11-CON-04` | `replay` |
| 9 | `000000000009` | `a11_000000000009_fd` | `A11-CON-05` | `first_delivery` |
| 10 | `000000000010` | `a11_000000000010_replay` | `A11-CON-05` | `replay` |
| 11 | `000000000011` | `a11_000000000011_fd` | `A11-CR-01` | `first_delivery` |
| 12 | `000000000012` | `a11_000000000012_replay` | `A11-CR-01` | `replay` |
| 13 | `000000000013` | `a11_000000000013_fd` | `A11-CR-02` | `first_delivery` |
| 14 | `000000000014` | `a11_000000000014_replay` | `A11-CR-02` | `replay` |
| 15 | `000000000015` | `a11_000000000015_fd` | `A11-CR-03` | `first_delivery` |
| 16 | `000000000016` | `a11_000000000016_replay` | `A11-CR-03` | `replay` |
| 17 | `000000000017` | `a11_000000000017_fd` | `A11-CR-04` | `first_delivery` |
| 18 | `000000000018` | `a11_000000000018_replay` | `A11-CR-04` | `replay` |
| 19 | `000000000019` | `a11_000000000019_fd` | `A11-CR-05` | `first_delivery` |
| 20 | `000000000020` | `a11_000000000020_replay` | `A11-CR-05` | `replay` |
| 21 | `000000000021` | `a11_000000000021_fd` | `A11-EX-01` | `first_delivery` |
| 22 | `000000000022` | `a11_000000000022_replay` | `A11-EX-01` | `replay` |
| 23 | `000000000023` | `a11_000000000023_fd` | `A11-EX-02` | `first_delivery` |
| 24 | `000000000024` | `a11_000000000024_replay` | `A11-EX-02` | `replay` |
| 25 | `000000000025` | `a11_000000000025_fd` | `A11-EX-03` | `first_delivery` |
| 26 | `000000000026` | `a11_000000000026_replay` | `A11-EX-03` | `replay` |
| 27 | `000000000027` | `a11_000000000027_fd` | `A11-EX-04` | `first_delivery` |
| 28 | `000000000028` | `a11_000000000028_replay` | `A11-EX-04` | `replay` |
| 29 | `000000000029` | `a11_000000000029_fd` | `A11-EX-05` | `first_delivery` |
| 30 | `000000000030` | `a11_000000000030_replay` | `A11-EX-05` | `replay` |
| 31 | `000000000031` | `a11_000000000031_fd` | `A11-EX-06` | `first_delivery` |
| 32 | `000000000032` | `a11_000000000032_replay` | `A11-EX-06` | `replay` |
| 33 | `000000000033` | `a11_000000000033_fd` | `A11-EX-07` | `first_delivery` |
| 34 | `000000000034` | `a11_000000000034_replay` | `A11-EX-07` | `replay` |
| 35 | `000000000035` | `a11_000000000035_fd` | `A11-EX-08` | `first_delivery` |
| 36 | `000000000036` | `a11_000000000036_replay` | `A11-EX-08` | `replay` |
| 37 | `000000000037` | `a11_000000000037_fd` | `A11-EX-09` | `first_delivery` |
| 38 | `000000000038` | `a11_000000000038_replay` | `A11-EX-09` | `replay` |
| 39 | `000000000039` | `a11_000000000039_fd` | `A11-EX-10` | `first_delivery` |
| 40 | `000000000040` | `a11_000000000040_replay` | `A11-EX-10` | `replay` |
| 41 | `000000000041` | `a11_000000000041_fd` | `A11-OP-01` | `first_delivery` |
| 42 | `000000000042` | `a11_000000000042_replay` | `A11-OP-01` | `replay` |
| 43 | `000000000043` | `a11_000000000043_fd` | `A11-OP-02` | `first_delivery` |
| 44 | `000000000044` | `a11_000000000044_replay` | `A11-OP-02` | `replay` |
| 45 | `000000000045` | `a11_000000000045_fd` | `A11-OP-03` | `first_delivery` |
| 46 | `000000000046` | `a11_000000000046_replay` | `A11-OP-03` | `replay` |
| 47 | `000000000047` | `a11_000000000047_fd` | `A11-OP-04` | `first_delivery` |
| 48 | `000000000048` | `a11_000000000048_replay` | `A11-OP-04` | `replay` |
| 49 | `000000000049` | `a11_000000000049_fd` | `A11-OP-05` | `first_delivery` |
| 50 | `000000000050` | `a11_000000000050_replay` | `A11-OP-05` | `replay` |
| 51 | `000000000051` | `a11_000000000051_fd` | `A11-WR-01` | `first_delivery` |
| 52 | `000000000052` | `a11_000000000052_replay` | `A11-WR-01` | `replay` |
| 53 | `000000000053` | `a11_000000000053_fd` | `A11-WR-02` | `first_delivery` |
| 54 | `000000000054` | `a11_000000000054_replay` | `A11-WR-02` | `replay` |
| 55 | `000000000055` | `a11_000000000055_fd` | `A11-WR-03` | `first_delivery` |
| 56 | `000000000056` | `a11_000000000056_replay` | `A11-WR-03` | `replay` |
| 57 | `000000000057` | `a11_000000000057_fd` | `A11-WR-04` | `first_delivery` |
| 58 | `000000000058` | `a11_000000000058_replay` | `A11-WR-04` | `replay` |
| 59 | `000000000059` | `a11_000000000059_fd` | `A11-WR-05` | `first_delivery` |
| 60 | `000000000060` | `a11_000000000060_replay` | `A11-WR-05` | `replay` |
| 61 | `000000000061` | `a11_000000000061_fd` | `A11-WR-06` | `first_delivery` |
| 62 | `000000000062` | `a11_000000000062_replay` | `A11-WR-06` | `replay` |

Esta tabla es la autoridad primaria readonly para construir `action_invocation_plan`.

## 10. Unicidad y guardas aritméticas

La validación debe demostrar simultáneamente: 62 filas; 62 ordinales únicos; mínimo `1`; máximo `62`; conjunto exacto `1..62`; suma `1953`; 62 segmentos decimales únicos; 62 `invocation_id` únicos; 31 `case_id` únicos; exactamente dos fases por caso; una fila por `(case_id, phase)`; y cero duplicados, extras u omisiones.

La suma `1953` es sólo guardia aritmética y no forma parte del ID.

## 11. Fuente de implementación autorizada

La futura implementación puede incorporar literalmente las 62 filas o incorporar los 31 casos en el orden publicado y aplicar exactamente el algoritmo. Ambos métodos deben coincidir byte por byte con la sección 9.

No se autoriza leer Markdown en runtime, descubrir casos mediante filesystem, reflection, tests o datos productivos, ni regenerar desde otro orden.

## 12. Relación con las demás claves

`invocation_id` identifica la entry, pero no sustituye ni deriva `execution_id`, `case_id`, `phase`, `entrypoint_id`, loopback authority/bindings ni las restantes claves de la cuarta corrección. Esas claves continúan obteniéndose de sus autoridades previas.

No se permite derivar `case_id`, `phase` o `entrypoint_id` leyendo los doce dígitos en runtime. El binding normativo es la fila completa y puede validarse contra el catálogo.

## 13. Lookup y consumo

El lookup runtime sigue siendo exclusivamente por el string completo de `invocation_id`. Se prohíbe resolver por ordinal, `case_id`, fase o posición.

Cada ID declarado procede del catálogo, se consume como máximo una vez y se registra en memoria. En cierre limpio, orphan se calcula por diferencia entre IDs declarados y consumidos. No hay generación tardía, sustitución ni adición después del bootstrap.

## 14. Estabilidad y reproducibilidad

Los 62 valores son estáticos e idénticos byte por byte entre reconstrucciones y ejecuciones, incluidos `first_delivery` y `replay`. Son independientes de máquina, reloj, WordPress, MySQL, PID, rama Git, orden de ejecución y `execution_id`. Una nueva ejecución no genera IDs nuevos.

## 15. Validación estática y reason

Después de validar schema, tipos y gramática, la futura implementación compara cardinalidad, ordinales, padding, unicidad, casos, fases, bindings y cada fila con este catálogo. Toda diferencia produce exactamente:

```text
action_invocation_id_catalog_mismatch
```

Este reason se evalúa en la validación estática pre-bootstrap, antes del binding operativo del plan y antes de procesos, sockets, listeners, child, stub o acciones. Dentro de esa etapa sigue a los errores previos de schema/tipo/gramática; no altera la precedencia ya fijada fuera de esta discrepancia de catálogo. El rechazo deja cero efectos observables.

## 16. Inmutabilidad

El catálogo se congela tras validarse y antes del bootstrap. No puede mutarse, persistir el ordinal como campo nuevo, añadir campos al schema ni crear un catálogo paralelo. El ordinal es herramienta de construcción estática, nunca clave alternativa de lookup.

## 17. Matriz adversarial obligatoria

| Nº | Caso | Resultado normativo |
|---:|:---|:---|
| 1 | Catálogo exacto de 62 filas | `aceptar` |
| 2 | Ordinal faltante | `action_invocation_id_catalog_mismatch` |
| 3 | Ordinal duplicado | `action_invocation_id_catalog_mismatch` |
| 4 | Ordinal 0 | `action_invocation_id_catalog_mismatch` |
| 5 | Ordinal 63 | `action_invocation_id_catalog_mismatch` |
| 6 | Segmento con once dígitos | `action_invocation_id_catalog_mismatch` |
| 7 | Segmento con trece dígitos | `action_invocation_id_catalog_mismatch` |
| 8 | Segmento no decimal | `action_invocation_id_catalog_mismatch` |
| 9 | Padding incorrecto | `action_invocation_id_catalog_mismatch` |
| 10 | Prefijo incorrecto | `action_invocation_id_catalog_mismatch` |
| 11 | Sufijo incorrecto | `action_invocation_id_catalog_mismatch` |
| 12 | invocation_id duplicado | `action_invocation_id_catalog_mismatch` |
| 13 | Caso faltante | `action_invocation_id_catalog_mismatch` |
| 14 | Caso extra | `action_invocation_id_catalog_mismatch` |
| 15 | Fase faltante | `action_invocation_id_catalog_mismatch` |
| 16 | Fase duplicada | `action_invocation_id_catalog_mismatch` |
| 17 | Fases invertidas | `action_invocation_id_catalog_mismatch` |
| 18 | Orden de casos no canónico | `action_invocation_id_catalog_mismatch` |
| 19 | Natural sort | `action_invocation_id_catalog_mismatch` |
| 20 | Orden dependiente de locale | `action_invocation_id_catalog_mismatch` |
| 21 | ID basado en timestamp | `action_invocation_id_catalog_mismatch` |
| 22 | ID basado en hash | `action_invocation_id_catalog_mismatch` |
| 23 | ID basado en execution_id | `action_invocation_id_catalog_mismatch` |
| 24 | ID aleatorio | `action_invocation_id_catalog_mismatch` |
| 25 | Lookup por ordinal | `prohibido` |
| 26 | Lookup por case_id | `prohibido` |
| 27 | Reconstrucción determinista repetida | `mismos 62 bytes` |
| 28 | Concordancia algoritmo/tabla | `aceptar sólo si es total` |
| 29 | Suma de ordinales | `1953` |
| 30 | Cierre con IDs declarados | `exactamente 62` |

## 18. Reconciliación con autoridades previas

Permanecen vigentes: la gramática de la cuarta autoridad; la cardinalidad de 62; la asignación por caso y fase; la quinta corrección de orphan; la sexta corrección de bootstrap; y la séptima corrección de harnesses. El ejemplo único anterior queda desplazado sólo como fuente para seleccionar valores. Esta octava corrección no altera ningún otro contrato.

Toda prohibición previa de inventar IDs queda satisfecha por el catálogo explícito de la sección 9.

## 19. Prohibiciones expresas

Se prohíbe elegir libremente dentro de la gramática; usar sólo el ejemplo; timestamps, random, hashes, UUID, PID o `execution_id`; orden de ejecución, documental, filesystem, locale o natural sort; omitir la tabla; publicar sólo pseudocódigo; cambiar IDs entre ejecuciones; resolver por ordinal; persistir el ordinal; añadir schema; crear catálogo paralelo; o modificar las siete autoridades.

## 20. Obligaciones futuras de EA6

Antes del bootstrap, EA6 deberá construir las 62 filas, verificar algoritmo y tabla, comprobar todas las guardas, congelar el resultado y mantener lookup exclusivo por `invocation_id`. Esta corrección no autoriza esa implementación en el presente encargo.

## 21. Alcance documental

El único delta autorizado por esta corrección es este documento nuevo. No autoriza componentes de soporte, harnesses, PHP, WordPress, MySQL, child, stub, loopback, artefactos runtime, commit ni push.

## 22. Cierre

Quedan fijados los 31 casos literales, su orden bytewise, las dos fases, los ordinales `1..62`, los doce dígitos, los 62 strings finales y el rechazo pre-bootstrap.

**A11 ACTION INVOCATION ID CATALOG IMPLEMENTABLE TRAS OCTAVA CORRECCIÓN NORMATIVA**
