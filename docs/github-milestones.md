# Milestones de GitHub para VeciAhorra 1.0

## Criterio operativo

Los milestones agrupan resultados de producto, no sprints. El estado sugerido se
basa en el [roadmap 1.0](roadmap-v1.0.md) y debe confirmarse en GitHub antes de
cerrar cada hito. Un milestone cerrado puede reabrirse si una auditoría descubre
un criterio de cierre incumplido.

| Milestone | Objetivo | Estado sugerido | Issues sugeridos | Criterio de cierre |
|---|---|---|---|---|
| Foundation | Disponer de bootstrap, contenedor, configuración, persistencia, migraciones y autoload | Cerrado | Documentar decisiones base; verificar instalación y migraciones existentes | Fundación cargable, esquema versionado y documentación base disponible |
| Products | Administrar productos y catálogos asociados | Cerrado | Validación integral de Products; deuda o defectos detectados como issues separados | CRUD, búsquedas, catálogos y validación de referencias operativos |
| Inventory | Administrar stock y protegerlo frente a concurrencia | Cerrado | Regresión de inventario; alertas quedan en Notifications | Persistencia, administración, disponibilidad y bloqueo de stock verificados |
| Orders | Persistir órdenes e ítems congelados por minimarket | Cerrado | Regresión de estados y asociación con reservas/pagos | Creación, consulta y transiciones actuales verificadas |
| Reservations | Reservar, consumir, liberar y expirar stock de forma segura | Cerrado | Regresión de concurrencia, idempotencia y expiración | Estados terminales e inventario consistentes ante reintentos |
| Cart | Gestionar selección, cantidades e identidad del comprador | Cerrado | Regresión del contrato Cart; mejoras de UX en Customer Panel | Backend, validaciones y REST del carrito verificados |
| Checkout | Orquestar validación, reservas, órdenes y limpieza del carrito | Cerrado | Regresión transaccional y de compensaciones | Checkout crea órdenes por tienda sin duplicar ni perder stock |
| Payments | Crear y confirmar pagos mediante el gateway actual | Cerrado | Gateway productivo y conciliación antes de Release v1.0 | Gateway simulado, sesiones y confirmación idempotente verificados |
| Customer Panel | Completar la experiencia de consulta del comprador | Abierto | Completar detalle; estados vacíos y errores; integrar seguimiento de Delivery | Cliente consulta órdenes, pagos y entrega con estados coherentes |
| Delivery | Implementar entrega auditable desde orden pagada hasta cierre | Abierto | Issues DLV-01 a DLV-06 del seed | Dominio, persistencia, API, operación y seguimiento cumplen sus criterios |
| Notifications | Comunicar eventos críticos con reintentos y trazabilidad | Abierto | Issues NTF-01 a NTF-03 del seed | Canales, plantillas, preferencias y fallos son observables y seguros |
| Dashboard | Consolidar la operación administrativa del MVP | Abierto | Issues DSH-01 a DSH-03 del seed | Operador visualiza y gestiona órdenes, entregas, stock y señales críticas |
| Hardening | Reducir riesgos de calidad, seguridad, compatibilidad y rendimiento | Abierto | Issues HRD-01 a HRD-05 del seed | Automatización y auditorías P0 pasan; riesgos P1 abiertos están aceptados |
| Release v1.0 | Validar y publicar alpha, beta, RC y estable | Abierto | Issues REL-01 a REL-03 del seed | Criterios stable cumplidos y 1.0 publicada con soporte y rollback |

## Dependencias recomendadas

```text
Delivery ─┬─> Customer Panel
          ├─> Notifications
          └─> Dashboard

Customer Panel + Delivery + Notifications + Dashboard
                         │
                         v
                     Hardening
                         │
                         v
                  Release v1.0
```

Payments puede cerrarse para reflejar la base existente, pero el gateway
productivo se gestiona como dependencia P0 de **Release v1.0**. Las alertas de
inventario se ejecutan en **Notifications**, no reabren el milestone Inventory.

## Fechas

No se proponen fechas hasta confirmar capacidad y responsables. Al crear los
milestones, asignar due dates sólo a los hitos abiertos y mantener Release v1.0
como fecha límite superior. Ninguna fecha debe sustituir sus criterios de cierre.

Consulta los issues completos en [github-issues-seed.md](github-issues-seed.md)
y el proceso de alta en [github-project-setup.md](github-project-setup.md).
