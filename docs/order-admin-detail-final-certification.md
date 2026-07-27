# Certificación final integrada — detalle administrativo operacional de pedidos

Fecha de certificación: 2026-07-27  
Base funcional auditada: `2d02aacf5f0c62139c1591bd19ae7a61f027fec0`

## Veredicto global

El flujo integrado queda **funcionalmente certificado en el worktree auditado**, después de corregir un defecto mínimo de accesibilidad detectado durante la fase inicial de solo lectura.

La auditoría detectó que la región `role="alert"` era enfocables en el shell mediante `tabindex="-1"`, pero la vista no trasladaba el foco al publicar un error. La corrección aplicada:

- enfoca la región de error para todos los errores normalizados;
- enfoca la región ante una falla segura de configuración;
- exige `tabIndex === -1` al validar el shell;
- prueba el foco en los harness de vista y aplicación.

La matriz completa posterior a la corrección pasa. La corrección y este documento permanecen deliberadamente sin staging ni commit.

## Alcance certificado

Se auditó el flujo:

```text
Listado de pedidos
→ enlace canónico “Ver”
→ ruta administrativa de detalle
→ shell PHP y configuración privada
→ detail-app.js
→ transporte
→ estado
→ vista
→ endpoint REST
→ OrderAdminReadService
→ repositorio
→ assembler
→ resolver operacional
→ enlace PHP de regreso al listado
```

No se incorporaron acciones mutables, endpoints adicionales, consultas SQL, almacenamiento web, navegación programática ni cambios en Orders, Checkout, Payments, Reservations, Delivery o Fulfillment.

## Arquitectura final

```text
OrdersPage
├─ listado válido → app.js
└─ detalle válido → detail-app.js
                     ├─ detail-api.js
                     ├─ detail-state.js
                     └─ detail-view.js
                          ↓
                  GET /veciahorra/v1/orders/{id}/admin
                          ↓
                  OrderAdminReadService
                     ├─ OrderAdminReadRepository
                     ├─ OrderOperationalFactsAssembler
                     └─ OrderOperationalStateResolver
```

`detail-app.js` es el único entrypoint del detalle. PHP no encola por separado transporte, estado o vista; las dependencias se resuelven mediante imports ESM.

## Ruta y navegación

La única ruta válida es:

```text
admin.php?page=veciahorra-orders&action=view&order_id={id}
```

`order_id` acepta exclusivamente enteros positivos canónicos. Rutas inválidas, acciones desconocidas, IDs duplicados o defectuosos no cargan la aplicación ni generan REST.

El listado genera un enlace `<a>` real mediante `buildOrderDetailUrl()`. No se interceptan clics y se conserva navegación documental, teclado, clic modificado, apertura en pestaña y copia de enlace.

El contexto se transporta individualmente mediante:

| Listado | Detalle |
|---|---|
| `search` | `return_search` |
| `store_id` | `return_store_id` |
| `order_status` | `return_order_status` |
| `fulfillment_mode` | `return_fulfillment_mode` |
| `date_from` | `return_date_from` |
| `date_to` | `return_date_to` |
| `sort` | `return_sort` |
| `paged` | `return_paged` |
| `per_page` | `return_per_page` |

El enlace “Volver a pedidos” continúa siendo autoridad PHP. El request valida cada dimensión y elimina nonces, tokens, parámetros desconocidos y valores inválidos.

## Contrato frontend

La configuración privada contiene solamente:

```text
enabled
orderId
restUrl
nonce
```

La aplicación valida configuración, origen REST, ruta, ausencia de query/fragmento y unicidad del shell antes de crear capas.

Orden certificado:

1. validación de configuración;
2. validación del shell;
3. creación del transporte;
4. creación del estado;
5. creación de la vista;
6. render explícito del snapshot `idle`;
7. una suscripción;
8. una llamada a `state.load()`.

Secuencias:

```text
idle → loading → ready
idle → loading → unauthorized
idle → loading → forbidden
idle → loading → not_found
idle → loading → invalid_request
idle → loading → server_error
idle → loading → network_error
idle → loading → invalid_response
```

La protección privada de instancia evita construcciones, suscripciones, cargas o solicitudes duplicadas.

## Shell y accesibilidad

El shell contiene exactamente una instancia de:

- `#veciahorra-order-detail`;
- `#veciahorra-order-detail-loading[role="status"]`;
- `#veciahorra-order-detail-error[role="alert"][tabindex="-1"]`;
- `main#veciahorra-order-detail-content`.

Se certificaron:

- `aria-busy=true` exclusivamente durante carga;
- regiones de carga, error y contenido mutuamente coherentes;
- mensajes locales cerrados;
- foco programático en errores;
- conservación del único `<h1>`;
- conservación del enlace de regreso;
- encabezados `h2`/`h3`, listas y listas de definición semánticas;
- ausencia de controles mutables o falsos controles.

## Render permitido

La vista renderiza únicamente:

1. resumen e identidad;
2. estado operacional;
3. minimarket;
4. relación con comprador;
5. líneas;
6. procesamiento;
7. fulfillment;
8. timeline;
9. pago;
10. inspector operacional.

`customer.relationship_status` se presenta como:

- `linked`: “Relación con comprador confirmada”;
- `unknown`: “Relación con comprador no confirmada o no disponible”.

Líneas y timeline conservan el orden recibido. No se recalculan totales, estados ni hechos. Elementos incompatibles usan fallbacks neutrales sin romper el resto.

## Contrato REST

Solicitud única:

```http
GET /wp-json/veciahorra/v1/orders/{id}/admin
Accept: application/json
X-WP-Nonce: …
credentials: same-origin
```

Sin body, query funcional, reintentos, polling ni endpoints secundarios.

El endpoint:

- exige `manage_options`;
- exige nonce REST válido;
- responde con errores cerrados 401, 403, 404, 422 y 500;
- mantiene `Cache-Control: private, no-store`;
- delega exclusivamente en `OrderAdminReadService`;
- no ejecuta escrituras ni acciones de lifecycle.

## Presupuesto exacto

| Frontera | Presupuesto certificado |
|---|---:|
| Solicitud frontend inicial del detalle | 1 REST |
| `OrderAdminReadService::getOrderDetail()` exitoso | 3 operaciones |
| Shell PHP | 0 SQL, 0 REST |
| Render aislado | 0 solicitudes |
| Estado al construirse | 0 solicitudes |
| Transporte al importarse | 0 solicitudes |
| Navegación al importarse/construir URL | 0 solicitudes |
| Ruta inválida | 0 solicitudes del detalle |
| Listado | 1 solicitud inicial |

El endpoint exitoso reportó `detail_operations=3`; el harness de presupuesto y seguridad confirmó que timeline e inspector no añaden consultas.

## Seguridad y privacidad

Se certificó ausencia visible o persistida de:

- nonce y configuración interna;
- mensajes arbitrarios del backend;
- SQL, stacks y rutas locales;
- `customer_id` y PII del comprador;
- `payment.session.public_id`;
- tokens, payloads o metadata privada;
- DTO o snapshots serializados;
- logs sensibles;
- campos no allowlisted.

Todo dato remoto visible se inserta mediante `textContent`. No se usan `innerHTML`, `outerHTML`, `insertAdjacentHTML`, History API, almacenamiento web ni URLs derivadas de datos privados.

Se mantienen:

```text
allowed_actions = ["view"]
mutable_actions = []
```

No existen botones, formularios, POST, PATCH, DELETE ni transiciones operacionales.

## Concurrencia y abandono

El estado combina `AbortController` y secuencia lógica. Operaciones obsoletas no publican ni liberan el controlador vigente.

En `pagehide`, la aplicación:

1. elimina el listener global;
2. desuscribe la vista;
3. ejecuta `state.destroy()`;
4. aborta indirectamente la solicitud activa;
5. descarta respuestas tardías.

La limpieza es idempotente y no bloquea el enlace de regreso.

## Responsive

Se ejecutaron aplicación integrada y vista aislada en:

| Anchura | Aplicación | Vista | Solicitud exitosa | Overflow |
|---:|---:|---:|---:|---:|
| 1440 px | 143 aserciones | 113 aserciones | 1 | 0 |
| 1024 px | 143 aserciones | 113 aserciones | 1 | 0 |
| 768 px | 143 aserciones | 113 aserciones | 1 | 0 |
| 375 px | 143 aserciones | 113 aserciones | 1 | 0 |

El listado también pasó en 1440, 1024, 768 y 375 px con una solicitud y cero overflow.

Chrome reprodujo la caída ambiental conocida de su proceso GPU. La certificación responsive se ejecutó con Edge y los flags certificados. El runner temporal fue restaurado.

## Matriz de pruebas

| Prueba | Resultado |
|---|---:|
| Aplicación — infraestructura | PASS, 52 |
| Aplicación — navegador, 4 anchuras | PASS, 572 |
| Vista — infraestructura | PASS, 71 |
| Vista — navegador, 4 anchuras | PASS, 452 |
| Navegación — infraestructura | PASS, 41 |
| Navegación — navegador | PASS, 88 |
| Navegación — ida y regreso | PASS, 36 |
| Estado — infraestructura | PASS, 41 |
| Estado — navegador | PASS, 108 |
| Transporte — infraestructura | PASS, 42 |
| Transporte — navegador | PASS, 48 |
| Shell/ruta/retorno | PASS, 119; 0 SQL, 0 REST |
| Endpoint REST detalle | PASS, 97; 3 operaciones |
| REST listado | PASS, 20 |
| Infraestructura listado | PASS, 23 |
| JavaScript listado | PASS, 12 |
| Responsive listado | PASS; 4 anchuras, 1 request, 0 overflow |
| Read model | PASS, 139 |
| Assembler | PASS, 40 |
| Resolver | PASS, 305 |
| Presupuesto y seguridad | PASS, 24 |

Total cuantificado: **2.330 aserciones**, además del harness responsive del listado.

PHP lint pasó en todos los PHP afectados. La importación ESM real pasó para entrypoint y dependencias. `git diff --check` no reportó errores.

## Commits funcionales de la serie

| Hash | Mensaje |
|---|---|
| `1340884` | `docs(orders): design operational admin detail` |
| `8b5bf56` | `feat(orders): extend private admin detail read model` |
| `398d252` | `feat(orders): expose operational admin detail endpoint` |
| `e7f2ba5` | `feat(orders): add operational admin detail shell` |
| `1a5470a` | `feat(orders): add admin detail transport` |
| `1c8cf46` | `feat(orders): add admin detail state` |
| `d3881c4` | `feat(orders): add admin detail navigation` |
| `0731985` | `feat(orders): add admin detail rendering` |
| `2d02aac` | `feat(orders): initialize admin detail application` |

El listado operativo previo integrado por `dccf35d` es la frontera de entrada del flujo certificado.

## Estado Git y riesgos residuales

Base HEAD auditada:

```text
2d02aacf5f0c62139c1591bd19ae7a61f027fec0
```

La corrección de foco y sus pruebas permanecen modificadas sin staging. Este documento es nuevo y también permanece sin staging. `artifacts/` y los once documentos untracked preexistentes permanecen intactos.

No se identifican riesgos funcionales residuales dentro del alcance read-only después de la corrección. La caída GPU de Chrome corresponde al entorno del runner y queda cubierta por Edge.

## Recomendación de cierre y push

La implementación corregida está **apta para cierre funcional**, pero **no debe hacerse push todavía** porque la corrección de foco y este documento no están comprometidos.

Recomendación:

1. revisar el diff mínimo de accesibilidad y este certificado;
2. crear un commit estrictamente selectivo que excluya `artifacts/` y los once documentos ajenos;
3. repetir `git diff --check` y confirmar staging vacío después del commit;
4. autorizar el push únicamente después de esa revisión.
