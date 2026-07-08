# VeciAhorra

VeciAhorra es un plugin de WordPress orientado a un marketplace de proximidad para
múltiples minimarkets. Centraliza catálogo, inventario y venta, y mantiene un
flujo transaccional consistente desde el carrito hasta la confirmación del pago.

## Objetivo

El proyecto busca entregar una experiencia confiable para comprar en comercios
locales, con separación por tienda, control de stock, reservas temporales y
órdenes trazables. El MVP 1.0 debe cubrir el ciclo completo de compra, incluido
Delivery y un medio de pago productivo. La integración directa con WooCommerce
mediante adaptadores queda prevista para una etapa posterior al MVP.

## Arquitectura por módulos

El plugin usa PHP con autoload PSR-4 bajo el espacio de nombres `VeciAhorra\`.
`Core` inicia la aplicación; `Database` administra esquema, migraciones y acceso
a datos; `Admin` integra las pantallas de WordPress. Los dominios viven en
`app/Modules` y separan, según corresponda, Requests, Controllers, Services,
Repositories, Routes, Models y Views.

| Módulo | Responsabilidad principal |
|---|---|
| Stores | Minimarkets participantes |
| Products y ProductCatalogs | Productos, marcas, categorías y unidades |
| Inventory | Disponibilidad y bloqueo de stock |
| Cart | Selección y cantidades del comprador |
| Checkout | Validación y orquestación de la compra |
| Reservations | Reserva temporal y liberación de inventario |
| Orders | Órdenes e ítems inmutables por tienda |
| Payments | Sesiones y confirmación idempotente del pago |
| CustomerPanel | Consulta de información del comprador |

El flujo estabilizado es `Cart → Checkout → Reservations → Orders → Payments`.
Su contrato detallado está en [docs/transaction-flow.md](docs/transaction-flow.md).

## Requisitos

- WordPress 6.8 o superior.
- PHP 8.2 o superior.
- MySQL/MariaDB compatible con la instalación de WordPress.
- Composer 2 para generar el autoload.
- WooCommerce 10.0 o superior sólo para escenarios que prueben la integración
  prevista; el flujo de dominio actual no depende de objetos de WooCommerce.

## Instalación

1. Copiar o clonar el repositorio en
   `wp-content/plugins/veciahorra`.
2. Desde la carpeta del plugin, ejecutar `composer install --no-dev`.
3. Confirmar que WordPress cumple los requisitos y, si se evalúa la integración
   prevista, que WooCommerce también los cumple.
4. Activar **VeciAhorra** desde **Plugins** en WordPress.
5. Verificar que las tablas y migraciones se creen sin errores.

Para desarrollo se recomienda omitir `--no-dev` y trabajar en una instalación
local aislada, nunca contra datos de producción.

## Estructura del proyecto

```text
veciahorra/
├── app/                 Núcleo, acceso a datos, administración y módulos
├── assets/              Recursos JavaScript y CSS del panel administrativo
├── docs/                Diseño funcional, roadmap y políticas del proyecto
├── languages/           Recursos de internacionalización
├── tests/manual/        Pruebas manuales y de integración existentes
├── vendor/              Dependencias instaladas por Composer
├── composer.json        Metadatos y autoload PSR-4
└── veciahorra.php       Punto de entrada del plugin
```

## Flujo de desarrollo

1. Seleccionar una tarea priorizada en el
   [backlog](docs/project-backlog.md) y acordar criterios de aceptación.
2. Crear una rama corta desde la rama principal: `feature/<tema>`,
   `fix/<tema>` o `docs/<tema>`.
3. Implementar un cambio acotado y mantener las capas del módulo.
4. Ejecutar las validaciones aplicables y comprobar la
   [Definition of Done](docs/definition-of-done.md).
5. Abrir un pull request con contexto, evidencia y riesgos.
6. Integrar sólo después de revisión y validaciones satisfactorias.

## Convención de commits

Se usa Conventional Commits con el formato `tipo(alcance): descripción`.
Los tipos habituales son `feat`, `fix`, `docs`, `test`, `refactor`, `style`,
`chore`, `build`, `ci` y `perf`. El alcance identifica el módulo, por ejemplo:

```text
feat(inventory): add low-stock notification
fix(payments): preserve idempotency on retry
docs(release): clarify beta exit criteria
```

La descripción se escribe en imperativo, sin punto final y cada commit debe
representar una unidad lógica revisable. Los cambios incompatibles deben usar
`!` o un pie `BREAKING CHANGE:`.

## Estado actual

VeciAhorra se encuentra en desarrollo pre‑1.0. La versión declarada por el
plugin es `0.3.0`. El backend del flujo transaccional y sus pruebas manuales
principales están disponibles; Customer Panel posee una base de sólo lectura.
Quedan pendientes la capa Delivery, el endurecimiento integral, automatización
de calidad, validación operativa y las etapas alpha, beta y release candidate.

El avance funcional aproximado y sus supuestos se mantienen en el
[roadmap 1.0](docs/roadmap-v1.0.md).

## Roadmap resumido

- Consolidar Delivery y el seguimiento de pedidos.
- Completar la experiencia del cliente y operación administrativa.
- Automatizar calidad, seguridad, compatibilidad y empaquetado.
- Validar el producto mediante alpha, beta y release candidate.
- Publicar 1.0 estable con migración, documentación y soporte definidos.

Consulta el [backlog completo](docs/project-backlog.md), la
[estrategia de releases](docs/release-strategy.md) y el
[historial de cambios](CHANGELOG.md).

## Colaboración y seguridad

Antes de contribuir, revisa [CONTRIBUTING.md](.github/CONTRIBUTING.md) y el
[Código de Conducta](.github/CODE_OF_CONDUCT.md). Las vulnerabilidades no deben
publicarse como issues; sigue el proceso privado descrito en
[SECURITY.md](.github/SECURITY.md).
