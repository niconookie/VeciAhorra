# Roadmap hacia VeciAhorra 1.0

## Propósito

Este roadmap resume el camino desde la base transaccional actual hasta una
versión estable. El porcentaje es una estimación funcional, no una promesa de
fecha, y se recalcula cuando cambia el alcance.

## Objetivo del MVP

El MVP 1.0 permitirá que un cliente descubra productos de minimarkets, gestione
su carrito, confirme disponibilidad, genere órdenes separadas por tienda, pague
y consulte el estado de compra y entrega. El operador podrá administrar
catálogo, inventario, órdenes y despacho con trazabilidad suficiente para una
operación piloto.

## Avance aproximado

**65 % completado.** Se calcula sumando los puntos obtenidos en la siguiente
matriz. Cada área aporta su ponderación completa cuando cumple su criterio de
salida; un avance parcial se puntúa en proporción a los entregables verificables.

| Área | Ponderación | Obtenido | Criterio usado |
|---|---:|---:|---|
| Fundación técnica | 15 % | 15 % | Bootstrap, persistencia, migraciones y autoload disponibles |
| Catálogo e inventario | 20 % | 20 % | Stores, Products, catálogos, stock y bloqueos disponibles |
| Flujo transaccional | 25 % | 25 % | Cart, Checkout, Reservations, Orders y Payments integrados |
| Experiencia de cliente y operación | 10 % | 5 % | Customer Panel sólo tiene una base de lectura; falta cerrar la experiencia |
| Delivery | 10 % | 0 % | Módulo, operación y seguimiento aún no implementados |
| Calidad y hardening | 10 % | 0 % | Automatización, seguridad, rendimiento y observabilidad pendientes |
| Preparación de release | 10 % | 0 % | Alpha, beta, RC y publicación estable pendientes |
| **Total** | **100 %** | **65 %** | Suma reproducible de puntos obtenidos |

La cifra mide alcance funcional ponderado. No equivale a cobertura de pruebas
ni a preparación para producción y debe actualizarse junto con esta tabla.

## Fases completadas

| Fase | Resultado |
|---|---|
| Fundación | Bootstrap, contenedor, base de datos, migraciones y autoload |
| Catálogo comercial | Stores, Products y ProductCatalogs |
| Inventario | Administración, disponibilidad y bloqueo de stock |
| Compra | Cart, Checkout, Reservations y Orders integrados |
| Pago base | Payment, gateway simulado, sesión y confirmación idempotente |
| Consistencia | Flujo transaccional documentado y pruebas manuales principales |
| Cliente inicial | Customer Panel de sólo lectura |

## Fases pendientes

| Fase | Alcance | Criterio de salida |
|---|---|---|
| Delivery | Modelo, asignación, estados y seguimiento | Entrega vinculada a órdenes y auditable |
| Cierre del MVP | Panel de cliente y operación administrativa | Recorrido completo utilizable sin intervención técnica |
| Hardening | Pruebas automatizadas, seguridad, rendimiento y observabilidad | Riesgos críticos cerrados y regresiones controladas |
| Alpha | Validación interna del paquete completo | Flujos críticos aceptados por el equipo |
| Beta | Piloto con usuarios y comercios acotados | Incidencias bloqueantes resueltas |
| Release candidate | Congelamiento funcional y ensayo de actualización | Sin defectos críticos y rollback validado |
| Stable 1.0 | Publicación, soporte y monitoreo | Artefacto reproducible y operación estable |

## Módulos

| Módulo | Estado hacia 1.0 |
|---|---|
| Core, Database y Admin | Base completada; pendiente hardening |
| Stores | Funcional; pendiente validación integral |
| Products y ProductCatalogs | Funcional; pendiente validación integral |
| Inventory | Funcional con bloqueo; pendiente operación y alertas |
| Cart y Checkout | Backend funcional; pendiente experiencia final |
| Reservations y Orders | Integrados; pendiente operación y observabilidad |
| Payments | Flujo con gateway simulado; pendiente proveedor productivo |
| CustomerPanel | Fundación de sólo lectura; pendiente completar experiencia |
| Delivery | Pendiente para el MVP |

Delivery forma parte del alcance obligatorio del MVP 1.0 y todavía no está
implementado. WooCommerce no forma parte del flujo de dominio actual: su
integración mediante adaptadores está prevista para una etapa posterior al MVP.

## Seguimiento

Las unidades ejecutables, dependencias y criterios de aceptación se mantienen
en el [backlog del proyecto](project-backlog.md). Los hitos de estabilización se
definen en la [estrategia de releases](release-strategy.md).
