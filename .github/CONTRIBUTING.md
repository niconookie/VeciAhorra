# Contribuir a VeciAhorra

Gracias por ayudar a mejorar VeciAhorra. Buscamos cambios pequeños, verificables
y respetuosos de los contratos transaccionales del proyecto.

## Antes de comenzar

1. Lee el [Código de Conducta](CODE_OF_CONDUCT.md).
2. Busca issues y pull requests existentes.
3. Para errores o propuestas acotadas, crea el issue con la plantilla adecuada.
4. Discute primero los cambios de arquitectura, seguridad, datos o alcance amplio.
5. Nunca publiques vulnerabilidades; usa el proceso de [seguridad](SECURITY.md).

## Entorno local

Necesitas WordPress 6.8+, PHP 8.2+, Composer 2 y una base de datos compatible con
WordPress. WooCommerce 10.0+ sólo es necesario para escenarios que evalúen la
integración prevista; el dominio actual todavía no depende de sus objetos.
Instala el repositorio en `wp-content/plugins/veciahorra`, ejecuta
`composer install` y activa el plugin en una instalación local aislada.

No uses datos reales. La activación instala o actualiza el esquema, por lo que se
recomienda respaldar el entorno y probar migraciones con datos desechables.

## Ramas y commits

Crea una rama corta desde la principal:

- `feature/<tema>` para funcionalidad.
- `fix/<tema>` para correcciones.
- `docs/<tema>` para documentación.
- `chore/<tema>` para mantenimiento.

Los commits siguen `tipo(alcance): descripción`, conforme a Conventional Commits.
Usa un alcance modular como `inventory`, `payments` o `delivery`; escribe en
imperativo y separa unidades lógicas. Declara cambios incompatibles con `!` o
`BREAKING CHANGE:`.

## Desarrollo y pruebas

- Conserva la separación entre Requests, Controllers, Services, Repositories,
  Routes, Models y Views.
- No omitas validación, autorización, sanitización ni escape de salida.
- Protege idempotencia y concurrencia en inventario, reservas, órdenes y pagos.
- Agrega pruebas proporcionales al cambio y ejecuta todas las relacionadas.
- Si una validación es manual, documenta pasos, entorno y resultado reproducible.
- Actualiza la documentación canónica y `CHANGELOG.md` cuando corresponda.

La tarea debe cumplir [Definition of Done](../docs/definition-of-done.md).

## Pull requests

Completa la plantilla: contexto, cambios, evidencia, riesgos y rollback. Vincula
el issue, mantén el PR enfocado y responde las observaciones de revisión. No
solicites revisión mientras fallen pruebas conocidas o falte información
necesaria para evaluar el cambio.

Al contribuir aceptas que tu trabajo se distribuye bajo la licencia del proyecto.
