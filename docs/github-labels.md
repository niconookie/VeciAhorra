# Catálogo de labels para GitHub

## Propósito

Este catálogo define una taxonomía estable para clasificar issues y pull
requests de VeciAhorra. Los nombres se escriben en minúsculas, se reutilizan en
todo el repositorio y cada issue debe tener, como mínimo, una etiqueta de área y
una de prioridad. Los colores están expresados en hexadecimal sin `#`, como los
espera GitHub.

## Áreas técnicas

| Nombre | Descripción | Color sugerido |
|---|---|---|
| `backend` | Lógica PHP, dominio, servicios o administración del servidor | `0E8A16` |
| `frontend` | Interfaz y comportamiento en el navegador | `1D76DB` |
| `api` | Contratos, permisos o comportamiento de endpoints | `5319E7` |
| `database` | Esquema, migraciones, índices o persistencia | `006B75` |
| `documentation` | Documentación funcional, técnica u operativa | `0075CA` |
| `testing` | Pruebas, fixtures, regresión o infraestructura de validación | `BFDADC` |
| `refactor` | Mejora interna sin cambio funcional intencional | `FBCA04` |
| `security` | Autorización, privacidad, vulnerabilidades o hardening | `B60205` |
| `performance` | Rendimiento, consultas, capacidad o tiempos de respuesta | `D4C5F9` |
| `ui/ux` | Experiencia, accesibilidad o diseño de interfaz | `C5DEF5` |
| `release` | Preparación, empaquetado, despliegue o publicación | `0052CC` |

## Módulos

| Nombre | Descripción | Color sugerido |
|---|---|---|
| `module: core` | Bootstrap, contenedor, configuración y servicios transversales | `3E4B9E` |
| `module: products` | Productos, marcas, categorías y unidades | `2E7D32` |
| `module: inventory` | Stock, disponibilidad, bloqueos y alertas | `558B2F` |
| `module: orders` | Órdenes, ítems, estados y operación asociada | `EF6C00` |
| `module: reservations` | Reservas, expiración y liberación de stock | `F9A825` |
| `module: cart` | Carrito, cantidades e identidad del comprador | `00838F` |
| `module: checkout` | Validación y orquestación de compra | `6A1B9A` |
| `module: payments` | Pagos, sesiones, confirmación y conciliación | `AD1457` |
| `module: customer-panel` | Consulta y experiencia del cliente | `1565C0` |
| `module: delivery` | Entrega, asignación, estados y seguimiento | `6D4C41` |
| `module: notifications` | Mensajes transaccionales, canales y reintentos | `00897B` |
| `module: dashboard` | Operación administrativa y métricas | `455A64` |

## Prioridad

| Nombre | Descripción | Color sugerido |
|---|---|---|
| `priority: high` | Bloquea el MVP, una release o un flujo crítico | `B60205` |
| `priority: medium` | Necesario para una operación confiable, sin bloqueo inmediato | `FBCA04` |
| `priority: low` | Mejora aplazable sin impacto crítico | `0E8A16` |

La equivalencia con el backlog es: P0 = `priority: high`, P1 =
`priority: medium` y P2 = `priority: low`.

## Estado auxiliar

El campo **Status** del Project es la fuente principal del flujo. Estas labels
permiten ver el estado fuera del Project y deben sincronizarse al mover un item.

| Nombre | Descripción | Color sugerido |
|---|---|---|
| `status: ready` | Refinado, sin bloqueos y listo para comenzar | `0E8A16` |
| `status: blocked` | No puede avanzar por una dependencia o decisión explícita | `B60205` |
| `status: review` | Implementación terminada y pendiente de revisión | `5319E7` |
| `status: verified` | Criterios y pruebas verificados; listo para cerrar | `1D76DB` |

## Labels de tipo recomendadas

| Nombre | Descripción | Color sugerido |
|---|---|---|
| `type: feature` | Nueva capacidad visible o de dominio | `A2EEEF` |
| `type: bug` | Comportamiento incorrecto reproducible | `D73A4A` |
| `type: chore` | Mantenimiento sin funcionalidad de producto | `EDEDED` |
| `type: spike` | Investigación acotada con resultado documentado | `D876E3` |

## Reglas de uso

- No representar prioridad sólo en el título.
- Usar un único label `priority:*` por issue.
- Usar uno o más labels técnicos cuando el trabajo atraviese capas.
- Usar un único `module:*` principal; mencionar módulos secundarios en el cuerpo.
- `status: blocked` reemplaza temporalmente cualquier otro `status:*`.
- No crear sinónimos sin actualizar este catálogo y la configuración del Project.

La asignación inicial está definida en [github-issues-seed.md](github-issues-seed.md).
