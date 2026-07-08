# Backlog del proyecto: Delivery a Release 1.0

## Uso del backlog

Este documento reúne el trabajo conocido desde Delivery hasta 1.0. La prioridad
se expresa como P0 (bloquea el MVP), P1 (necesario para una operación confiable)
o P2 (mejora aplazable). Cada ítem debe convertirse en issue con criterios de
aceptación verificables y cumplir la [Definition of Done](definition-of-done.md).

## Epic 1 — Delivery

- [ ] **P0 — Definir dominio de entrega:** modalidades, ventanas, cobertura,
  tarifas, responsables y transiciones válidas.
- [ ] **P0 — Persistir entregas:** esquema versionado, relación con órdenes e
  historial de estados sin pérdida de auditoría.
- [ ] **P0 — Implementar capa de Delivery:** Requests, Repository, Service,
  Controller y Routes con autorización y validación.
- [ ] **P0 — Orquestar creación:** generar la entrega sólo para órdenes pagadas
  y evitar duplicados ante reintentos.
- [ ] **P0 — Gestionar estados:** `pending`, `assigned`, `in_transit`,
  `delivered` y `failed`, con transiciones explícitas.
- [ ] **P0 — Exponer seguimiento al cliente:** estado, ventana y eventos útiles
  sin filtrar información interna.
- [ ] **P1 — Crear operación administrativa:** búsqueda, filtros, asignación y
  actualización segura del despacho.
- [ ] **P1 — Incorporar notificaciones de eventos críticos:** contrato,
  reintentos y registro de fallos.
- [ ] **P1 — Cubrir concurrencia e idempotencia:** asignación, cambios de estado
  y confirmación de entrega.
- [ ] **P2 — Preparar adaptador de proveedor logístico:** interfaz desacoplada y
  proveedor simulado para pruebas.

## Epic 2 — Experiencia y operación del MVP

- [ ] **P0 — Completar Customer Panel:** detalle de órdenes, pagos, reservas y
  entrega con estados coherentes.
- [ ] **P0 — Completar recorrido de compra:** estados vacíos, errores
  recuperables, expiración y reintentos comprensibles.
- [ ] **P0 — Incorporar gateway productivo:** credenciales protegidas,
  callbacks autenticados, conciliación e idempotencia.
- [ ] **P0 — Definir cancelaciones y devoluciones:** reglas, permisos, efectos
  sobre pago, orden, entrega e inventario.
- [ ] **P1 — Consolidar operación de órdenes:** filtros, detalle, acciones
  permitidas e historial.
- [ ] **P1 — Añadir alertas de inventario:** umbrales configurables y contexto
  de tienda y producto.
- [ ] **P1 — Revisar accesibilidad e internacionalización:** teclado, foco,
  etiquetas, textos traducibles y formatos locales.
- [ ] **P2 — Mejorar comunicaciones transaccionales:** plantillas consistentes
  y preferencias del usuario.

## Epic 3 — Calidad y hardening

- [ ] **P0 — Automatizar pruebas críticas:** unidad, integración y recorrido
  Cart → Payment → Delivery.
- [ ] **P0 — Crear CI:** validación PHP, pruebas, estándares, Markdown y
  construcción del paquete en cada pull request.
- [ ] **P0 — Auditar autorización y entrada:** capacidades, nonces, permisos
  REST, sanitización, escape y exposición de datos.
- [ ] **P0 — Probar migraciones:** instalación limpia, actualización desde una
  versión soportada, reejecución y recuperación ante fallo.
- [ ] **P1 — Medir rendimiento:** consultas, índices, paginación, tiempos del
  checkout y límites operativos documentados.
- [ ] **P1 — Incorporar observabilidad:** logs estructurados sin secretos,
  correlación y señales para pagos y entregas.
- [ ] **P1 — Definir matriz de compatibilidad:** versiones de WordPress, PHP,
  WooCommerce y motores de base de datos.
- [ ] **P1 — Ejecutar revisión de privacidad:** retención, exportación,
  eliminación y minimización de datos personales.
- [ ] **P2 — Validar resiliencia:** fallos de gateway, tareas demoradas,
  timeouts y reintentos.

## Epic 4 — Alpha

- [ ] **P0 — Congelar alcance alpha** y etiquetar riesgos conocidos.
- [ ] **P0 — Construir paquete reproducible** sin dependencias de desarrollo.
- [ ] **P0 — Ejecutar smoke tests** de instalación, activación y flujos P0.
- [ ] **P0 — Realizar validación interna** con datos representativos.
- [ ] **P1 — Triage de hallazgos** con severidad, responsable y fecha objetivo.
- [ ] **P1 — Actualizar documentación operativa** y guía de diagnóstico.

## Epic 5 — Beta

- [ ] **P0 — Seleccionar piloto acotado** y definir soporte y consentimiento.
- [ ] **P0 — Preparar staging equivalente** con anonimización y monitoreo.
- [ ] **P0 — Validar comercios y compradores** en recorridos reales.
- [ ] **P0 — Corregir defectos críticos y altos**; documentar riesgos aceptados.
- [ ] **P1 — Evaluar métricas del piloto:** éxito de pago, entrega, errores y
  tiempos de respuesta.
- [ ] **P1 — Ensayar respaldo, restauración y rollback** con evidencia.

## Epic 6 — Release candidate

- [ ] **P0 — Congelar funcionalidades**; aceptar sólo correcciones aprobadas.
- [ ] **P0 — Completar regresión** sobre la matriz de compatibilidad.
- [ ] **P0 — Auditar seguridad y dependencias** sin vulnerabilidades críticas
  conocidas.
- [ ] **P0 — Ensayar actualización a 1.0** desde la versión soportada.
- [ ] **P0 — Cerrar notas de release y CHANGELOG** con cambios incompatibles.
- [ ] **P0 — Aprobar go/no-go** con producto, ingeniería y operación.

## Epic 7 — Release 1.0 estable

- [ ] **P0 — Etiquetar y publicar un artefacto verificable** desde el commit
  aprobado del release candidate.
- [ ] **P0 — Ejecutar despliegue gradual** con puntos de control y rollback.
- [ ] **P0 — Verificar smoke tests pospublicación** y salud transaccional.
- [ ] **P0 — Activar soporte y respuesta a incidentes** con responsables claros.
- [ ] **P1 — Publicar documentación de instalación, actualización y operación.**
- [ ] **P1 — Realizar retrospectiva 1.0** y priorizar el backlog posterior.

## Criterio de cierre del backlog 1.0

Todos los ítems P0 deben estar terminados; los P1 abiertos requieren aceptación
explícita del riesgo y un seguimiento asignado. La salida de cada etapa se rige
por [release-strategy.md](release-strategy.md).
