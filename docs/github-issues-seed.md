# Issues semilla para VeciAhorra 1.0

## Uso

Este documento propone **20 issues** pendientes y accionables. Los identificadores
son editoriales y sirven para revisar dependencias antes de crearlos en GitHub;
GitHub asignará el número definitivo. Todos deben cumplir la
[Definition of Done](definition-of-done.md). Las labels existen en el
[catálogo](github-labels.md) y los hitos en
[github-milestones.md](github-milestones.md).

Este seed inicial cubre exclusivamente Delivery, Notifications, Dashboard,
Hardening y Release v1.0; no agota el backlog P0/P1. El trabajo adicional de
Customer Panel, gateway productivo, cancelaciones y devoluciones se convertirá
en issues mediante seeds posteriores antes de cerrar el alcance del MVP.

No crear estos issues automáticamente sin confirmar alcance, responsables y
permisos. El cuerpo de cada issue puede copiarse desde su descripción hasta sus
pruebas requeridas.

## Delivery — 6 issues

### DLV-01 — Definir dominio y estados de Delivery

**Descripción:** Documentar modalidades, cobertura, ventanas, tarifas,
responsables, eventos y transiciones permitidas de una entrega vinculada a una
orden pagada. Debe resolver fallos y cancelaciones sin alterar retrospectivamente
el estado financiero.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `documentation`, `module: delivery`,
`priority: high`, `type: feature`

**Checklist:**

- [ ] Definir entidades, identidad y relaciones con Orders.
- [ ] Definir estados, eventos y transiciones válidas.
- [ ] Definir modalidades, cobertura, ventanas y tarifas.
- [ ] Resolver cancelación, fallo, reintento y cierre.
- [ ] Actualizar el contrato funcional canónico.

**Criterios de aceptación:** Existe un contrato revisable, sin estados ambiguos;
cada transición indica actor, precondición, efecto e idempotencia; Delivery queda
separado de pagos e inventario.

**Pruebas requeridas:** Revisión de tabla de transiciones; walkthrough de entrega
exitosa, fallo, cancelación y reintento; validación contra Orders y Customer Panel.

### DLV-02 — Crear persistencia y migración de Delivery

**Descripción:** Diseñar y versionar tablas de entrega e historial de eventos,
con claves, índices y restricciones coherentes con las órdenes existentes.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `database`, `module: delivery`,
`priority: high`, `type: feature`

**Checklist:**

- [ ] Definir esquema, claves foráneas lógicas e índices.
- [ ] Persistir historial auditable de estados.
- [ ] Implementar migración reejecutable.
- [ ] Documentar actualización y recuperación ante fallo.

**Criterios de aceptación:** Una orden elegible se relaciona con una única
entrega activa según el contrato; el historial no se sobrescribe; instalación y
actualización conservan datos y pueden reintentarse.

**Pruebas requeridas:** Instalación limpia, actualización, doble ejecución,
rollback operativo documentado, restricciones e índices sobre datos representativos.

### DLV-03 — Implementar API modular de Delivery

**Descripción:** Implementar Requests, Repository, Service, Controller y Routes
para crear, consultar y actualizar entregas sin saltar capas del módulo.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `api`, `database`, `module: delivery`,
`priority: high`, `type: feature`

**Checklist:**

- [ ] Implementar validación y normalización de entrada.
- [ ] Implementar persistencia y reglas de servicio.
- [ ] Exponer endpoints y respuestas consistentes.
- [ ] Aplicar autenticación, capacidades y permisos por recurso.
- [ ] Documentar contratos y errores.

**Criterios de aceptación:** Las operaciones autorizadas respetan el contrato de
DLV-01; entradas inválidas no escriben; usuarios no autorizados no infieren datos;
los errores usan códigos estables.

**Pruebas requeridas:** Request, Repository, Service, Controller, permisos REST,
not found, validación y regresión de rutas existentes.

### DLV-04 — Orquestar creación idempotente desde Orders pagadas

**Descripción:** Crear la entrega únicamente cuando las órdenes asociadas estén
pagadas y evitar duplicados ante callbacks, reintentos o concurrencia.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `database`, `module: delivery`,
`priority: high`, `type: feature`

**Checklist:**

- [ ] Definir punto de integración con confirmación de pago.
- [ ] Documentar la relación con Orders como dependencia del módulo Delivery.
- [ ] Verificar elegibilidad y propiedad de Orders.
- [ ] Añadir clave o bloqueo de idempotencia.
- [ ] Manejar fallo parcial sin corromper Payment u Orders.

**Criterios de aceptación:** Una misma intención genera como máximo una entrega;
órdenes no pagadas se rechazan; repetir la operación devuelve el resultado
existente; un fallo deja estados recuperables.

**Pruebas requeridas:** Ejecución normal, orden no pagada, doble callback,
solicitudes concurrentes y fallo de persistencia simulado.

### DLV-05 — Implementar transiciones y asignación de entrega

**Descripción:** Aplicar `pending → assigned → in_transit → delivered` y la ruta
de `failed` con asignación segura, historial y reglas explícitas.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `api`, `module: delivery`,
`priority: high`, `type: feature`

**Checklist:**

- [ ] Implementar máquina de estados definida en DLV-01.
- [ ] Registrar actor, fecha y contexto de cada evento.
- [ ] Proteger asignación y actualización concurrentes.
- [ ] Rechazar transiciones terminales o inversas no permitidas.

**Criterios de aceptación:** Sólo se persisten transiciones válidas; los estados
terminales no se reabren; reintentos no duplican eventos; el historial permite
reconstruir el estado actual.

**Pruebas requeridas:** Matriz completa de transiciones, idempotencia,
concurrencia de asignación y permisos por rol.

### DLV-06 — Exponer operación y seguimiento de Delivery

**Descripción:** Proveer consulta segura para Customer Panel y operación
administrativa para buscar, filtrar, asignar y actualizar entregas.

**Milestone sugerido:** Delivery

**Labels sugeridos:** `backend`, `frontend`, `api`, `ui/ux`,
`module: delivery`, `priority: high`, `type: feature`

**Checklist:**

- [ ] Definir vista pública segura del seguimiento.
- [ ] Integrar estado y eventos útiles en Customer Panel.
- [ ] Crear listado administrativo con búsqueda y filtros.
- [ ] Conectar acciones permitidas a DLV-05.
- [ ] Cubrir estados vacíos, carga y error.

**Criterios de aceptación:** El cliente sólo consulta sus entregas y no recibe
datos internos; el operador completa el flujo sin acceso directo a base de datos;
la interfaz refleja estados reales y acciones permitidas.

**Pruebas requeridas:** Autorización horizontal, filtros, paginación, acciones
administrativas, accesibilidad básica y recorrido pagado → entregado.

## Notifications — 3 issues

### NTF-01 — Definir arquitectura y eventos de Notifications

**Descripción:** Definir contratos, canales y eventos de órdenes, pagos,
inventario y Delivery que generan comunicaciones, sin acoplar los módulos a un
proveedor concreto.

**Milestone sugerido:** Notifications

**Labels sugeridos:** `backend`, `documentation`, `module: notifications`,
`priority: medium`, `type: feature`

**Checklist:**

- [ ] Inventariar eventos y destinatarios.
- [ ] Definir interfaz de canal y payload versionado.
- [ ] Definir deduplicación, reintentos y estados.
- [ ] Definir tratamiento de datos personales.

**Criterios de aceptación:** Cada evento tiene origen, destinatario y plantilla;
los módulos publican contratos neutrales; no se incluyen secretos ni datos
innecesarios; la idempotencia está especificada.

**Pruebas requeridas:** Revisión de contratos; walkthrough de pago confirmado,
entrega en tránsito, entrega fallida y stock bajo.

### NTF-02 — Implementar cola, reintentos y trazabilidad

**Descripción:** Persistir notificaciones y procesarlas de forma reintentable,
con límites, backoff y diagnóstico de fallos permanentes.

**Milestone sugerido:** Notifications

**Labels sugeridos:** `backend`, `database`, `testing`,
`module: notifications`, `priority: medium`, `type: feature`

**Checklist:**

- [ ] Persistir intención, estado, intentos y resultado sanitizado.
- [ ] Implementar procesamiento y backoff acotado.
- [ ] Deduplicar por evento, canal y destinatario.
- [ ] Exponer fallos permanentes a operación.

**Criterios de aceptación:** Un evento no envía duplicados ante reintentos; los
fallos temporales se recuperan; los permanentes quedan visibles; logs y datos
persistidos no exponen credenciales.

**Pruebas requeridas:** Éxito, timeout, error temporal, error permanente,
concurrencia, deduplicación y sanitización de logs.

### NTF-03 — Crear plantillas y preferencias transaccionales

**Descripción:** Crear plantillas consistentes y reglas de preferencias para
comunicaciones obligatorias y opcionales del MVP.

**Milestone sugerido:** Notifications

**Labels sugeridos:** `frontend`, `ui/ux`, `documentation`,
`module: notifications`, `priority: low`, `type: feature`

**Checklist:**

- [ ] Definir catálogo y variables permitidas por plantilla.
- [ ] Separar mensajes operativos obligatorios de preferencias opcionales.
- [ ] Proveer fallback ante datos ausentes.
- [ ] Revisar idioma, accesibilidad y enlaces.

**Criterios de aceptación:** Las plantillas no ejecutan contenido no confiable;
variables y fallback están documentados; preferencias no silencian mensajes
obligatorios; el contenido coincide con los estados del dominio.

**Pruebas requeridas:** Render con datos completos e incompletos, escape de
contenido, preferencias y revisión manual en cada canal soportado.

## Dashboard — 3 issues

### DSH-01 — Definir métricas y contratos del Admin Dashboard

**Descripción:** Definir qué señales operativas necesita el administrador para
órdenes, pagos, inventario y entregas, con fuente y ventana temporal explícitas.

**Milestone sugerido:** Dashboard

**Labels sugeridos:** `backend`, `documentation`, `module: dashboard`,
`priority: medium`, `type: feature`

**Checklist:**

- [ ] Definir métricas, filtros y responsables.
- [ ] Identificar fuente y fórmula de cada indicador.
- [ ] Definir límites de frescura y volumen.
- [ ] Excluir métricas sin acción operativa asociada.

**Criterios de aceptación:** Cada métrica es reproducible, tiene propietario y
acción; no mezcla tiendas sin autorización; no declara analítica histórica fuera
del alcance del MVP.

**Pruebas requeridas:** Validación de fórmulas con dataset controlado, límites de
acceso y revisión con escenarios operativos.

### DSH-02 — Implementar consultas y API del Dashboard

**Descripción:** Implementar agregaciones paginadas o acotadas para servir las
métricas aprobadas sin degradar checkout ni administración.

**Milestone sugerido:** Dashboard

**Labels sugeridos:** `backend`, `api`, `database`, `performance`,
`module: dashboard`, `priority: medium`, `type: feature`

**Checklist:**

- [ ] Implementar consultas e índices necesarios.
- [ ] Aplicar filtros y autorización por tienda o rol.
- [ ] Definir límites, paginación y caché si corresponde.
- [ ] Instrumentar tiempos y errores.

**Criterios de aceptación:** La API coincide con DSH-01; las consultas permanecen
dentro del presupuesto acordado; los usuarios sólo agregan datos autorizados;
los rangos inválidos se rechazan.

**Pruebas requeridas:** Exactitud, autorización, dataset voluminoso, consultas
explicadas, límites y regresión de endpoints existentes.

### DSH-03 — Construir interfaz operativa del Dashboard

**Descripción:** Presentar indicadores, pendientes y accesos a órdenes,
inventario y Delivery con estados comprensibles y accesibles.

**Milestone sugerido:** Dashboard

**Labels sugeridos:** `frontend`, `ui/ux`, `performance`,
`module: dashboard`, `priority: medium`, `type: feature`

**Checklist:**

- [ ] Diseñar jerarquía y navegación hacia acciones.
- [ ] Implementar filtros y persistencia razonable de selección.
- [ ] Cubrir carga, vacío, datos parciales y error.
- [ ] Revisar teclado, foco, contraste y adaptación de pantalla.

**Criterios de aceptación:** Los valores coinciden con la API; toda alerta lleva
a una acción; la interfaz funciona sin depender sólo de color; errores parciales
no ocultan el resto del tablero.

**Pruebas requeridas:** Recorrido administrativo, accesibilidad, filtros, estados
de interfaz, datasets grandes y navegadores de la matriz soportada.

## Hardening — 5 issues

### HRD-01 — Crear CI para calidad y paquete reproducible

**Descripción:** Automatizar validación PHP, pruebas, estándares, Markdown y
construcción del artefacto sin dependencias de desarrollo.

**Milestone sugerido:** Hardening

**Labels sugeridos:** `testing`, `release`, `backend`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Definir eventos, permisos mínimos y cancelación de ejecuciones obsoletas.
- [ ] Ejecutar lint, estándares y pruebas disponibles.
- [ ] Validar Markdown y enlaces locales.
- [ ] Construir y conservar artefacto reproducible.
- [ ] Documentar diagnóstico y ejecución local equivalente.

**Criterios de aceptación:** Pull requests reciben resultado determinista; el
workflow usa permisos mínimos; fallos bloquean integración según política; el
artefacto no incluye secretos ni dependencias de desarrollo.

**Pruebas requeridas:** Ejecución exitosa, fallo intencional por etapa,
comparación de artefactos y revisión de permisos.

### HRD-02 — Automatizar regresión transaccional end-to-end

**Descripción:** Cubrir mediante pruebas automatizadas el recorrido Cart →
Checkout → Reservations → Orders → Payments → Delivery y sus compensaciones.

**Milestone sugerido:** Hardening

**Labels sugeridos:** `testing`, `backend`, `database`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Preparar fixtures deterministas por minimarket.
- [ ] Cubrir éxito multi-tienda y estados finales.
- [ ] Cubrir expiración, pago fallido y compensaciones.
- [ ] Cubrir reintentos y concurrencia crítica.

**Criterios de aceptación:** La suite detecta duplicación, sobreventa y estados
inconsistentes; puede repetirse sin residuos; produce evidencia útil en CI.

**Pruebas requeridas:** Todos los escenarios descritos, repetición consecutiva,
aislamiento de datos y ejecución en la matriz mínima.

### HRD-03 — Auditar seguridad, autorización y privacidad

**Descripción:** Revisar capacidades, nonces, permisos REST, validación, escape,
exposición y ciclo de vida de datos personales en todos los módulos del MVP.

**Milestone sugerido:** Hardening

**Labels sugeridos:** `security`, `api`, `backend`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Crear matriz de endpoints, roles y capacidades.
- [ ] Revisar entrada, salida y errores por capa.
- [ ] Probar acceso horizontal y vertical.
- [ ] Revisar retención, exportación y eliminación de datos.
- [ ] Registrar y priorizar hallazgos sin secretos.

**Criterios de aceptación:** No quedan hallazgos críticos o altos abiertos; cada
endpoint tiene política explícita; datos sensibles no aparecen en respuestas o
logs; riesgos aceptados tienen responsable.

**Pruebas requeridas:** Casos autenticados y anónimos, roles insuficientes,
payloads hostiles, IDOR, escape contextual y revisión de logs.

### HRD-04 — Validar migraciones y matriz de compatibilidad

**Descripción:** Definir y probar versiones soportadas de WordPress 6.8+, PHP
8.2+, WooCommerce cuando aplique y motores de base de datos.

**Milestone sugerido:** Hardening

**Labels sugeridos:** `database`, `testing`, `release`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Publicar matriz mínima y objetivo.
- [ ] Probar instalación limpia y actualización soportada.
- [ ] Probar reejecución e interrupción de migraciones.
- [ ] Documentar respaldo, restauración y rollback.

**Criterios de aceptación:** Cada combinación declarada tiene evidencia; las
migraciones preservan datos; incompatibilidades fallan con mensaje accionable;
la documentación coincide con configuración y metadatos.

**Pruebas requeridas:** Matriz acordada, datasets previos, interrupción simulada,
restauración y smoke tests posteriores.

### HRD-05 — Medir rendimiento, resiliencia y observabilidad

**Descripción:** Establecer presupuestos y señales para consultas, checkout,
callbacks de pago, notificaciones y Delivery bajo carga representativa.

**Milestone sugerido:** Hardening

**Labels sugeridos:** `performance`, `testing`, `backend`,
`priority: medium`, `type: chore`

**Checklist:**

- [ ] Definir presupuestos y dataset representativo.
- [ ] Medir consultas, índices y paginación.
- [ ] Simular timeout, proveedor caído y tareas demoradas.
- [ ] Añadir correlación y logs estructurados sanitizados.
- [ ] Documentar umbrales y respuesta operativa.

**Criterios de aceptación:** Los flujos P0 cumplen presupuestos acordados; fallos
externos son recuperables; señales permiten rastrear una transacción sin datos
sensibles; degradaciones tienen mitigación.

**Pruebas requeridas:** Perfil de consultas, carga base, timeout, reintentos,
recuperación y revisión manual de observabilidad.

## Release v1.0 — 3 issues

### REL-01 — Cerrar alcance y preparar alpha 1.0

**Descripción:** Verificar que los P0 del MVP estén implementados, construir un
paquete reproducible y ejecutar la validación interna del canal alpha.

**Milestone sugerido:** Release v1.0

**Labels sugeridos:** `release`, `testing`, `documentation`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Confirmar cierre o excepción aprobada de P0.
- [ ] Congelar alcance y registrar riesgos conocidos.
- [ ] Construir `1.0.0-alpha.1` reproducible.
- [ ] Ejecutar instalación y smoke tests P0.
- [ ] Actualizar CHANGELOG y documentación operativa.

**Criterios de aceptación:** Se cumplen los criterios alpha de la estrategia de
releases; no hay bloqueantes; paquete y evidencia se vinculan al issue; los
hallazgos tienen severidad y responsable.

**Pruebas requeridas:** Instalación limpia, activación, flujo completo del MVP,
smoke administrativo y comparación del artefacto.

### REL-02 — Ejecutar beta controlada

**Descripción:** Validar el MVP con un piloto acotado, medir su comportamiento y
resolver los defectos que impidan declarar cumplidos los criterios de salida beta.
La creación y validación del release candidate pertenecen a REL-03.

**Milestone sugerido:** Release v1.0

**Labels sugeridos:** `release`, `testing`, `security`,
`priority: high`, `type: chore`

**Checklist:**

- [ ] Preparar staging, soporte, consentimiento y monitoreo.
- [ ] Ejecutar piloto y evaluar métricas acordadas.
- [ ] Cerrar defectos críticos y altos no aceptados.
- [ ] Ensayar respaldo, restauración y rollback.
- [ ] Registrar la decisión de salida beta y los riesgos aceptados.

**Criterios de aceptación:** Beta cumple sus criterios de salida; las métricas
del piloto tienen evidencia; no quedan defectos críticos o altos no aceptados;
respaldo y rollback fueron ensayados; el alcance que pasará a RC está identificado.

**Pruebas requeridas:** Recorridos piloto, métricas operativas, regresión beta,
compatibilidad representativa, respaldo, restauración y rollback.

### REL-03 — Validar el release candidate y publicar VeciAhorra 1.0 estable

**Descripción:** Crear el release candidate desde la beta aprobada, aplicar el
congelamiento funcional, obtener una decisión go/no-go independiente y publicar
como estable exactamente el artefacto aprobado.

**Milestone sugerido:** Release v1.0

**Labels sugeridos:** `release`, `documentation`, `testing`,
`priority: high`, `type: chore`

**Checklist:**

Release candidate:

- [ ] Aplicar congelamiento funcional y construir el RC reproducible.
- [ ] Ejecutar regresión, seguridad y matriz de compatibilidad completas.
- [ ] Ensayar actualización, respaldo, restauración y rollback.
- [ ] Cerrar CHANGELOG, notas de release y riesgos conocidos.
- [ ] Registrar una decisión go/no-go antes de cualquier publicación estable.

Publicación estable:

- [ ] Confirmar aprobación final y responsables.
- [ ] Etiquetar el commit aprobado como `1.0.0`.
- [ ] Publicar artefacto, CHANGELOG y notas.
- [ ] Ejecutar despliegue gradual y smoke tests.
- [ ] Observar señales y activar rollback si corresponde.
- [ ] Cerrar retrospectiva y backlog posterior.

**Criterios de aceptación:** El RC cumple sus criterios de salida y cuenta con
go/no-go registrado antes de iniciar la publicación; el artefacto estable
corresponde exactamente al RC aprobado y puede reproducirse; smoke tests pasan;
soporte y rollback están disponibles; no hay incidentes críticos abiertos; el
milestone se cierra formalmente.

**Pruebas requeridas:** Regresión y seguridad del RC, matriz de compatibilidad,
hash del artefacto, instalación/actualización final, smoke tests pospublicación,
verificación de monitoreo y ensayo previo de rollback.

## Dependencias y orden sugerido

1. DLV-01 → DLV-02 y DLV-03 → DLV-04 y DLV-05 → DLV-06.
2. NTF-01 → NTF-02 → NTF-03; NTF-02 consume eventos estables de Delivery.
3. DSH-01 → DSH-02 → DSH-03; Dashboard consume datos de Delivery.
4. HRD-01 habilita evidencia continua; HRD-02 depende del flujo Delivery.
5. HRD-03, HRD-04 y HRD-05 deben cerrar antes de REL-02.
6. REL-01 → REL-02 → REL-03.

Al cargar los issues, usar inicialmente `Status = Backlog`. Sólo asignar
`status: ready` después de confirmar dependencias, responsable y Estimate.
