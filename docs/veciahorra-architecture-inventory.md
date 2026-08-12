# Inventario arquitectónico de VeciAhorra

## 1. Propósito y alcance del inventario

Este documento es el entregable del Microhito 31.1 y constituye un mapa de
conocimiento, no la Arquitectura v1.0 consolidada. Su propósito es indicar qué
fuentes existen, qué autoridad temática pueden aportar, cuáles son históricas o
futuras, cómo se relacionan y qué deberá resolver el Microhito 31.2.

Se realizaron dos pasadas sobre:

- los 38 archivos de `docs/`;
- 6 Markdown fuera de `docs/` (`README.md`, `CHANGELOG.md` y cuatro README de
  módulos/infraestructura);
- los 106 archivos PHP de `tests/manual/`, incluidos workers y fixtures;
- módulos, modelos, rutas, schemas, shortcodes, bootstrap y hooks únicamente
  para verificar nombres y vigencia;
- historial Git selectivo para identificar origen y sucesión documental.

Se excluyeron `vendor/`, assets puramente visuales, datos de `artifacts/`,
contenido de base de datos y una reconstrucción exhaustiva de implementación.
Las tablas y clases se trataron como evidencia técnica, nunca automáticamente
como conceptos de dominio. Las etiquetas usadas son:

- **implementada**: observable en código y contratos ejecutables;
- **documentada**: decisión normativa, aunque pueda requerir implementación;
- **histórica**: describe correctamente una etapa anterior;
- **futura**: reserva espacio, sin autoridad operativa actual.

## 2. Resumen ejecutivo

Se revisaron **150 fuentes**: 44 documentos Markdown y 106 archivos de pruebas
manuales. De ellas, **133 son arquitectónicamente relevantes** para v1.0: 35
documentos narrativos y 98 contratos ejecutables principales. Las 17 restantes
son gobierno/proceso, changelog, notas tempranas o harnesses auxiliares; siguen
inventariadas porque aportan contexto, sucesión o evidencia.

Cobertura fuerte:

- Cart, Reservations, Checkout, Payments y Webpay;
- Payment Reconciliation, Business Completion, Delivery Completion y
  Fulfillment Completion;
- idempotencia, leases, CAS, recovery y límites transaccionales;
- Customer Panel, frontend público y navegación pública;
- testing contractual, ownership y no enumeración.

Cobertura parcial:

- visión de sistema y principios globales, repartidos entre README y diseños;
- Administration, Identity, Inventory, Delivery operativa e integración
  WooCommerce;
- Catalog, donde conviven producto maestro, catálogo público e inventario sin
  una frontera consolidada en un solo documento;
- WordPress Integration, documentada por piezas (REST, shortcodes, páginas,
  Action Scheduler y tema).

Cobertura insuficiente o futura:

- Marketplace como agregado o bounded context explícito;
- Publication, Offer, Ranking, Availability y Promotion;
- identidad durable y ciclo de vida del minimarket;
- Notifications y preferencias;
- gobierno de SEO/indexación y redirecciones heredadas.

Contradicciones principales: `/mis-pedidos/` frente a `/mis-compras/`; README
frontend y roadmap antiguos frente a funcionalidad implementada; Checkout sin
estado `completed` frente a proyecciones de compra finalizada; Payment histórico
creado antes del gateway frente a Payment materializado por Business Completion;
y el diseño híbrido de páginas heredadas frente a la decisión vigente de no
implementarlo. Son resolubles porque existen sucesores y pruebas claras.

**Conclusión:** existe base suficiente para iniciar el Microhito 31.2. La
consolidación debe seleccionar autoridades por tema, no fusionar documentos en
orden cronológico, y debe conservar explícitos los vacíos futuros.

## 3. Catálogo maestro de documentos

### 3.1 Documentos en `docs/`

| Documento | Dominio | Tipo | Estado | Vigencia | Autoridad temática | Se reutiliza | Se resume | Se reemplaza | Observaciones |
|---|---|---|---|---|---|---|---|---|---|
| `beta-readiness.md` | Vision, Testing | auditoría | parcialmente vigente | parcial | cierre de estabilización 27.0 | sí | sí | — | evidencia de una etapa, no estado final v1 |
| `business-completion-design.md` | Business Completion, Payments | especificación implementada | vigente | completa | materialización interna atómica post-conciliación | sí | no | — | primaria |
| `cart-functional-design.md` | Cart, Identity | diseño | parcialmente vigente | parcial | reglas y REST base de Cart | sí | sí | implementación/pruebas Cart | contiene propuestas futuras ya realizadas |
| `checkout-functional-design.md` | Checkout, Reservations, Orders | diseño | parcialmente vigente | parcial | principios originales de checkout | sí | sí | diseños públicos 28.7.x | preliminar, anterior al checkout durable |
| `customer-frontend-functional-design.md` | Frontend, Customer Panel | diseño | parcialmente vigente | parcial | mapa conceptual de experiencia cliente | sí | sí | diseños frontend/panel posteriores | usa “Mis pedidos” conceptualmente |
| `customer-panel-frontend-design.md` | Customer Panel, Frontend | diseño | vigente | completa | DOM, navegación, concurrencia y UX del panel | sí | no | — | complementa el diseño de dominio |
| `customer-panel-v1-design.md` | Customer Panel, Security | diseño | vigente | completa | read model, ownership, precedencia y DTO | sí | no | — | primaria |
| `definition-of-done.md` | Testing, Principles | política | vigente | completa | criterio general de entrega | sí | sí | — | autoridad de proceso, no dominio |
| `delivery-functional-design.md` | Delivery | diseño/estado | parcialmente vigente | parcial | estados, REST y tracking de Delivery | sí | sí | completions posteriores | operativo, no cubre pipeline durable completo |
| `durable-fulfillment-authority.md` | Checkout, Fulfillment | especificación implementada | vigente | completa | fulfillment inmutable `pickup/delivery` sellado en Checkout | sí | no | — | primaria |
| `fulfillment-completion-processor-design.md` | Fulfillment, Recovery | diseño normativo | vigente | completa | límites, lease, CAS e invariantes del cierre | sí | no | implementación/pruebas lo confirman | primaria |
| `github-issues-seed.md` | Future Capabilities | backlog | histórico | parcial | intención de roadmap | no | sí | implementación posterior | Delivery/Customer Panel ya avanzaron |
| `github-labels.md` | Testing/Governance | política | vigente | completa | taxonomía de trabajo | no | sí | — | no arquitectura de dominio |
| `github-milestones.md` | Future Capabilities | planificación | parcialmente vigente | parcial | dependencias de releases | no | sí | estado real posterior | fechas/avance pueden quedar obsoletos |
| `github-project-setup.md` | Future Capabilities | operación | parcialmente vigente | parcial | configuración del proyecto GitHub | no | sí | — | no fuente de arquitectura runtime |
| `legacy-woocommerce-content-pages-isolation-design.md` | Navigation, WooCommerce | diseño | vigente como contingencia | parcial | diseño híbrido conservador | sí | sí | decisión operacional posterior | explícitamente no implementar hoy |
| `legacy-woocommerce-pages-retirement.md` | Navigation, WordPress | retiro | vigente | completa | evidencia de 177/356 en borrador | sí | no | — | estado operacional |
| `legacy-woocommerce-pages-usage-audit.md` | Navigation, WooCommerce | auditoría | vigente | completa | seguridad del retiro y decisión de no implementar híbrido | sí | no | — | primaria para decisión operacional |
| `mock-payment-gateway-functional-design.md` | Payments, Testing | diseño | parcialmente vigente | parcial | interfaz neutral y mock | sí | sí | Webpay/durable pipeline | útil para abstracción, no autoridad final de estados |
| `payment-confirmation-functional-design.md` | Payments, Orders | diseño normativo | vigente | completa | frontera financiera/negocio y confirmación transaccional | sí | no | completado/refinado por pipeline durable | primaria para invariantes |
| `payment-functional-design.md` | Payments | diseño histórico | histórico | parcial | modelo inicial de Payment | sí | sí | confirmación y pipeline 28.7.4+ | endpoints/orden temporal no son todos actuales |
| `payment-reconciliation-attempt-materialization.md` | Reconciliation, WooCommerce | nota implementada | vigente | completa | identidad durable del intento WooCommerce | sí | no | — | primaria para rama WC |
| `payment-reconciliation-lease-implementation.md` | Reconciliation, Recovery | nota implementada | vigente | completa | lease, CAS y semántica técnica de completed | sí | no | — | primaria |
| `payment-reconciliation-order-completion-design.md` | Reconciliation, Payments | diseño maestro | parcialmente vigente | parcial alta | autoridad financiera, origen, handlers y recuperación | sí | sí | notas/implementación 28.7.4.6.x refinan partes | extensa; distinguir propuesta de evidencia real |
| `project-backlog.md` | Vision, Future | backlog | parcialmente vigente | parcial | orden histórico hacia 1.0 | sí | sí | avance real | no usar como estado de módulos |
| `public-checkout-durable-payment-pipeline-design.md` | Checkout, Webpay, Recovery | auditoría/diseño | vigente | completa | secuencia durable end-to-end y brechas explícitas | sí | no | — | fuente primaria transversal |
| `public-checkout-functional-design.md` | Checkout, Frontend | diseño | parcialmente vigente | parcial | UX, RB-CHK-001, identidad y entrega | sí | sí | implementación 28.7.1–28.7.5 | algunas “futuras” ya existen |
| `public-navigation-certification.md` | Navigation | certificación | reemplazado | nula actual | evidencia del primer fallo | sí | sí | certificación final | histórico necesario |
| `public-navigation-final-certification.md` | Navigation, Frontend | certificación | vigente | completa | cierre funcional Serie 30 | sí | no | — | veredicto final con observaciones |
| `public-navigation-recertification.md` | Navigation | certificación | reemplazado | nula actual | evidencia de fuga 177/356 | sí | sí | retiro + certificación final | histórico necesario |
| `public-payment-session-backend-design.md` | PaymentSession, Checkout | diseño contractual | vigente | completa | IDs públicos, ownership, idempotencia y REST | sí | no | pipeline durable lo integra | primaria |
| `public-payment-session-design.md` | Payments, Checkout | auditoría/diseño | parcialmente vigente | parcial | brecha original y modelo real previo | sí | sí | backend design/pipeline | distingue bien real vs futuro de su fecha |
| `public-search-isolation-design.md` | Navigation, WordPress | diseño | vigente | completa | aislamiento tradicional/live y contextos negativos | sí | no | implementación/tests | primaria de búsqueda |
| `release-strategy.md` | Vision, Testing | política | vigente | completa | promoción alpha/beta/RC/stable | sí | sí | — | arquitectura de release |
| `roadmap-v1.0.md` | Vision, Future | roadmap | parcialmente vigente | parcial | objetivo MVP y módulos | sí | sí | implementación posterior | avance porcentual/pendientes no son autoridad actual |
| `transaction-flow.md` | Checkout, Payments, Orders | especificación | vigente con refinamientos | parcial alta | flujo transaccional base e invariantes | sí | sí | completion pipeline amplía el postpago | primaria para núcleo pre-reconciliación |
| `webpay-integration-functional-design.md` | Webpay, Payments | diseño | parcialmente vigente | parcial | adapter, seguridad y mapeo Webpay | sí | sí | return/pipeline/implementación | propuesta de su etapa |
| `webpay-return-and-commit-foundation.md` | WebpayReturn | nota implementada | vigente | completa | endpoint, validación y replay del retorno | sí | no | pipeline amplía recovery | primaria puntual |

### 3.2 Markdown fuera de `docs/`

| Documento | Dominio | Tipo | Estado | Vigencia | Autoridad temática | Se reutiliza | Se resume | Se reemplaza | Observaciones |
|---|---|---|---|---|---|---|---|---|---|
| `README.md` | Vision, Principles | visión | parcialmente vigente | parcial | modularidad, propósito y flujo base | sí | sí | Arquitectura v1 futura | afirma Delivery pendiente pese a implementación posterior |
| `CHANGELOG.md` | Testing/Governance | historial | parcialmente vigente | parcial | cambios pre-1.0 declarados | no | sí | Git | incompleto respecto de Series 28–30 |
| `app/Database/README.md` | Administration, Marketplace | nota | histórico | nula | estado 0.1.0 | no | sí | schemas/migrations actuales | muy obsoleto |
| `app/Modules/Catalog/README.md` | Catalog, Marketplace | contrato | vigente | completa | API pública de catálogo/ofertas | sí | no | — | primaria para read model público |
| `app/Modules/Frontend/README.md` | Frontend | nota evolutiva | parcialmente vigente | parcial baja | fundamentos y contratos tempranos | sí | sí | implementación y diseños 28.7/29/30 | checkout “solo visual” y rutas reservadas están obsoletos |
| `app/Modules/Payments/WooCommerce/README.md` | WooCommerce, Webpay | nota | parcialmente vigente | parcial | continuación POST clásica y límites del adapter | sí | sí | materialización durable posterior | no describe toda la rama WC actual |

### 3.3 Pruebas como contratos ejecutables

Las 106 pruebas se revisaron por archivo; se catalogan por suite para evitar
atribuir a un helper autoridad independiente.

| Suite | Dominio | Tipo | Estado | Vigencia | Autoridad temática | Se reutiliza | Se resume | Se reemplaza | Observaciones |
|---|---|---|---|---|---|---|---|---|---|
| `inventory-*` | Inventory, Admin | prueba contractual | vigente | completa | validación, CRUD, locks, rutas y migración | sí | sí | — | primaria ejecutable |
| `product-*` y `catalog-*` | Product, Catalog | prueba contractual | vigente | completa | catálogo maestro, bulk, búsqueda y API pública | sí | sí | — | distingue admin y lectura pública |
| `cart-*`, `public-cart`, `public-add-to-cart` | Cart, Identity | prueba contractual | vigente | completa | sesión, mutación, totales, validación y REST | sí | sí | — | primaria ejecutable |
| `reservation-*` y `reservations-*` | Reservations | prueba contractual | vigente | completa | creación, expiración, concurrencia e integración | sí | sí | — | primaria ejecutable |
| `checkout-*` y `public-checkout-*` | Checkout | prueba contractual | vigente | completa | validate/create, ownership, reserva y UI | sí | sí | — | primaria ejecutable |
| `order-*`, `orders-*` | Orders | prueba contractual | vigente | completa | persistencia, servicio, rutas e integración | sí | sí | — | primaria ejecutable |
| `payment-*`, `mock-*` | Payments, PaymentSession | prueba contractual | vigente | completa | estados, gateway, confirmación, auditoría e idempotencia | sí | sí | — | incluye workers de concurrencia |
| `webpay-*` | Webpay | prueba contractual | vigente | completa | adapter, return, sandbox, rutas y gateway | sí | sí | — | red real limitada a smoke/sandbox |
| `payment-reconciliation-*` | Reconciliation, Recovery | prueba contractual | vigente | completa | schema, lease, fingerprint, processor, concurrencia | sí | sí | — | fixtures/workers no son autoridad solos |
| `business-*`, `delivery-completion-*`, `fulfillment-*`, `durable-*` | Completion, Delivery, Fulfillment | prueba contractual | vigente | completa | pipeline, exact-set, leases y recovery | sí | sí | — | primaria ejecutable |
| `transactional-*` | Payments, Completion | prueba contractual | vigente | completa | transacción, rollback, concurrencia y E2E | sí | sí | — | valida límites InnoDB |
| `customer-*` | Customer Panel, Security | prueba contractual | vigente | parcial alta | ownership, proyección, estados, REST y frontend | sí | sí | — | test headless depende de Chrome; detalle está combinado |
| `frontend-*`, `public-offer-*`, `public-route-*`, `public-search-*` | Frontend, Navigation | prueba contractual | vigente con deuda | parcial alta | montaje, rutas, búsqueda y fichas | sí | sí | — | `public-offer-selection-test` tiene deuda conocida |
| `woocommerce-*` | WooCommerce, Payments | prueba contractual | vigente | completa | resolver, gateway y completions WC | sí | sí | — | preserva rama deliberada |
| helpers/workers/fixtures (8, solapados arriba) | Testing | harness | vigente | completa | concurrencia y procesos separados | no | sí | — | soporte, no contrato aislado |

## 4. Inventario por dominio

| Dominio | Responsabilidad, fuentes y decisiones consolidadas | Entidades/estados/autoridades/contratos | Vacíos, contradicciones y preguntas para 31.2 |
|---|---|---|---|
| System Vision | Marketplace local multi-minimarket; flujo propio desacoplado de WC. Fuentes: README, roadmap, transaction flow, beta readiness. | Módulos separados; flujo Cart→Checkout→Reservations→Orders→Payments ampliado por completions. | Definir visión v1 vigente y qué significa “Marketplace” más allá de módulos. |
| Architecture Principles | Modularidad PSR-4, autoridad durable, backend decide, snapshots, idempotencia, CAS, no reconstrucción, lectura segura. | Contratos en transaction flow y pipeline durable. | Unificar principios dispersos y jerarquía cuando colisionan documentos. |
| Identity | Usuario WP o invitado con sesión opaca; ownership por user/guest y IDs públicos. | CartSession, checkout owner, `chk_*`, `ps_*`; REST same-origin/nonce. | Ciclo de identidad invitada, expiración y vinculación posterior no está consolidado. |
| Administration | CRUD modular de Products, Stores, Catalog taxonomies, Inventory, Orders y Delivery. | Requests/Controllers/Services/Repositories y rutas admin/REST. | No existe arquitectura administrativa integral, roles/capabilities por módulo ni dashboard congelado. |
| Catalog | Read model público de productos activos con oferta; maestro Product separado. | Product, Category, Brand, Unit; `GET /catalog/products[/id]`. | Frontera exacta Product/ProductCatalog/Catalog/Inventory y cache futuro. |
| Marketplace | Visión multi-minimarket; agrupación de checkout por comercio. | Store/Minimarket e inventarios por comercio; oferta proyectada. | Bounded context, publicación, onboarding y gobernanza del minimarket insuficientes. |
| Inventory | Disponibilidad, precio, stock y locks; backend autoritativo. | Inventory durable; estados `active/inactive`; CRUD, lock service, lectura Catalog/Cart/Checkout. | Definir “availability” derivada versus stock/estado y reserva; política de sobreventa completa. |
| Cart | Selección temporal por identidad, sin reserva; totales del servidor. | Cart/CartItem; REST `/cart`, `/cart/items`, `/cart/items/{id}`. | Caducidad/limpieza durable y transición explícita al checkout congelado. |
| Reservations | Reserva temporal de 15 minutos, liberación/consumo y stock coordinado. | Reservation; `active`, `released`, `expired`, `consumed`; expiration service. | Autoridad temporal global y política exacta ante extensión/cancelación requieren síntesis. |
| Checkout | Congela identidad, Orders, total, fulfillment y owner; coordina creación. | Checkout/CheckoutOrder; `pending`, `payment_started`, `expired`, `cancelled`; validate/create/status REST. | No existe terminal `completed`; definir su cierre semántico o declarar que otras autoridades expresan finalización. |
| Payments | Payment se materializa en Business Completion; sesión coordina intento de gateway. | Payment `pending→paid/failed`; PaymentSession con estados create/ready/confirmed/expired/cancelled. | Separar claramente Payment financiero interno, Session e intento remoto en narrativa v1. |
| Payment Reconciliation | Valida evidencia financiera y origen bajo lease; no ejecuta negocio directo. | OriginContext, WebpayReturn, Reconciliation; seis estados; fingerprint, unique, CAS. | Aclarar semántica histórica de `completed`: técnica, no negocio; recovery de pago sin retorno sigue como brecha. |
| Business Completion | Materializa Payment, vínculos y Orders atómicamente desde reconciliación interna. | BusinessCompletion y snapshot/order set; seis estados; una TX InnoDB. | Política operacional para terminales no exitosos y tooling manual no consolidada. |
| Delivery | Operación logística posterior: materialización, asignación, tracking y entrega. | Delivery `pending/assigned/picked_up/delivered/cancelled`; routes `/deliveries`. | Separar Delivery entity, DeliveryCompletion de materialización y fulfillment final en terminología. |
| Fulfillment | Decisión immutable pickup/delivery y cierre durable exacto. | fulfillment_method; DeliveryCompletion; FulfillmentCompletion; leases/CAS. | Efecto concreto de FulfillmentCompletion para pickup/delivery está deliberadamente acotado y debe describirse sin inventar. |
| Customer Panel | Proyección read-only owned sobre autoridades previas; no modifica dominio. | Customer Purchase como read model; estados visibles; REST list/detail; `/mis-compras/`. | Paginación, sesión visual automatizada y política de retención; no elevar la proyección a nueva autoridad durable. |
| Frontend | Shortcodes montan contenido en páginas WP; REST es autoridad; accesibilidad/responsive. | cinco shortcodes, JS por contrato, PublicRouteResolver. | README temprano contradictorio; falta documento consolidado de componentes y lifecycle frontend. |
| Public Navigation | Menú WP/Blocksy y cinco rutas canónicas; búsqueda excluye WC general. | home/catalog/cart/checkout/customerPurchases; final certification. | 301 y SEO quedan separados; prevenir nuevas páginas heredadas sin implementar resolver híbrido ahora. |
| WordPress Integration | Plugin modular, REST namespace, shortcodes, WP identity/options, Action Scheduler y páginas. | `veciahorra/v1`, WP APIs, theme coexistence. | Definir frontera WordPress core vs dominio y política de activación/migración/capabilities en un capítulo único. |
| WooCommerce Integration | Rama deliberada y adapter Webpay; páginas WC fuera de navegación general. | WC order/payment completion, resolver oficial, intento durable. | README adapter quedó parcial; aclarar qué rama es compatibilidad y qué no comparte autoridades nativas. |
| Webpay Integration | Gateway neutral, create/return/commit, evidencia durable y recuperación. | Gateway interfaces, PaymentOriginContext, WebpayReturn, token hash. | Create/commit remoto no es exactly-once; pago aprobado sin retorno no tiene garantía completa documentada como implementada. |
| Action Scheduler / Recovery | Transporta IDs de autoridad; nunca es fuente de verdad. | hooks de Webpay create/return y pipeline completion; retry exponencial y sweep 300s. | Operación/observabilidad de colas, alertas y intervención manual. |
| Testing and Verification | 106 archivos cubren capas, integración, concurrencia, sandbox y navegación. | contratos ejecutables y fixtures multi-proceso. | CI automatizada ausente; deuda `public-offer-selection`; headless sensible al entorno. |
| Security and Ownership | Backend authority, nonces, IDs opacos, no enumeración, token/PII redactados. | ownership profundo de checkout/session/purchases; allowlists y códigos estables. | Threat model integral, roles administrativos, retención y privacidad formal. |
| Future Capabilities | Notifications, dashboard, promociones, ranking, publication y SEO. | solo conceptos/backlog. | No diseñar en 31.1; reservar fronteras explícitas en v1. |

## 5. Mapa de autoridades documentadas

“Cierra” significa que puede llevar su propia autoridad a estado terminal; no
implica que cierre todo el proceso comercial.

| Autoridad | Fuente principal | Crea | Modifica | Cierra | Solo lee | Certeza |
|---|---|---|---|---|---|---|
| Product | Product/Catalog tests; Catalog README | ProductService/admin | ProductService/admin | no hay cierre; activa/inactiva | Catalog, Inventory, frontend | alta |
| Inventory | inventory tests | InventoryService/admin | InventoryService, locks/reservas según operación | no definido como cierre | Catalog, Cart, Checkout | alta, ownership de stock requiere síntesis |
| Cart | cart design/tests | CartService por usuario/invitado | CartService | vaciado; no terminal durable documentado | Checkout/frontend | alta |
| Reservation | transaction flow/tests | Checkout mediante ReservationService | Reservation/expiration services | consumo, liberación o expiración | Checkout, Business, Customer projection | alta |
| Checkout | backend/pipeline designs | CheckoutService | PaymentSession/CheckoutService solo en transiciones permitidas | expira/cancela; no `completed` | PaymentSession, Customer Panel, completions | alta con vacío terminal |
| Order | transaction flow/Business design | Checkout/OrderService | Business `reserved→paid`; Delivery `paid→delivered` | Delivery al entregar | Payment, Customer Panel | alta |
| PaymentSession | backend design/pipeline | PaymentSessionService | coordinador create/Webpay y confirmación | confirmed/expired/cancelled/create_failed | return, status projection, Customer Panel | alta |
| PaymentOriginContext | reconciliation design/materialization | coordinador del intento | bind de token/evidencia por CAS | no se describe cierre autónomo | Return/Reconciliation/Recovery | alta parcial |
| WebpayReturn | return foundation/pipeline | WebpayReturnService/repository | commit/validación durable y resume | resultado financiero terminal | Reconciliation/status | alta |
| PaymentReconciliation | reconciliation design/lease | materializer desde retorno validado | claim/processor bajo lease | completed/permanent_failure/manual_review | Business, Customer Panel, recovery | alta |
| Payment | Business Completion design | BusinessCompletionProcessor | mismo processor/confirmation transaccional | paid o failed según flujo autorizado | Customer Panel, Orders logic | alta; narrativa histórica conflictiva |
| BusinessCompletion | business completion design | materializer origin-aware | BusinessCompletionProcessor bajo lease | completed/permanent_failure/manual_review | Delivery/Fulfillment/Customer Panel | alta |
| Delivery | delivery + durable completion | DeliveryCompletionProcessor para método delivery | DeliveryService/courier/tracking | DeliveryService al `delivered`/`cancelled` | Customer Panel/Fulfillment | alta |
| DeliveryCompletion | fulfillment design + tests | orchestration desde Business completed | DeliveryCompletionProcessor | completed/not_required/permanent_failure/manual_review | FulfillmentCompletion | alta |
| FulfillmentCompletion | processor design/tests | orchestration desde etapa previa | FulfillmentCompletionProcessor | completed/permanent_failure/manual_review | Customer Panel/recovery | alta |
| Customer Purchase | panel designs/tests | nadie: proyección al leer | nadie | nadie | CustomerPanelService/API/frontend | alta: **read model, no autoridad durable propia** |

## 6. Inventario de entidades

### 6.1 Entidades de dominio y agregados observados

- Store/Minimarket, Product, Category, Brand y Unit.
- Inventory.
- Cart y CartItem.
- Checkout y CheckoutOrder.
- Reservation.
- Order y OrderItem.
- Payment y PaymentOrder.
- PaymentSession.
- PaymentOriginContext y WebpayReturn.
- PaymentReconciliation.
- BusinessCompletion y BusinessCompletionOrder snapshot.
- Delivery y DeliveryTracking.
- DeliveryCompletion.
- FulfillmentCompletion.

### 6.2 Autoridades durables

El subconjunto durable con autoridad explícita incluye Checkout, Reservation,
Order, PaymentSession, PaymentOriginContext, WebpayReturn,
PaymentReconciliation, Payment, BusinessCompletion, Delivery,
DeliveryCompletion y FulfillmentCompletion. Product, Inventory y Cart también
son persistentes, pero su semántica de “autoridad” global debe explicarse por
dominio en 31.2, no inferirse solo de sus tablas.

### 6.3 DTO y read models

- resultados de gateway, confirmación, reconciliación y completions;
- `FinancialFingerprintComponents`, leases y resultados CAS;
- DTO de catálogo/oferta pública;
- `CustomerPurchaseListItem`, `CustomerPurchaseDetail`, visible status y
  timeline;
- Customer Purchase es una proyección, no una fila/entidad autónoma.

### 6.4 Servicios

Services de Product, Inventory, Cart, Checkout, Reservation, Order, Payment,
PaymentSession, WebpayReturn, Reconciliation, Business/Delivery/Fulfillment
Completion, Delivery Tracking, Customer Panel y PublicRouteResolver. Un servicio
coordina comportamiento; no es automáticamente entidad ni autoridad.

### 6.5 Infraestructura e integraciones

- WordPress REST, shortcodes, páginas, usuarios, opciones y filtros;
- repositorios/schemas InnoDB y migraciones;
- Action Scheduler como transporte;
- Blocksy/Elementor como presentación;
- WooCommerce y Transbank Webpay como integraciones externas;
- mock gateway como test double contractual.

### 6.6 Conceptos futuros, no entidades actuales

Publication, Offer durable, Ranking, Availability agregada, Promotion,
Notification, Preference, Marketplace aggregate y SEO policy. “Oferta” hoy es
una proyección pública de Inventory+Store+Product, no una autoridad separada.

## 7. Inventario de estados

| Autoridad/proyección | Fuente | Inicial | Estados terminales documentados | Transiciones/escritores confirmados | Ambigüedades |
|---|---|---|---|---|---|
| Checkout | model/backend design | `pending` | `expired`, `cancelled`; `payment_started` no terminal | CheckoutService crea; inicio pago pasa a `payment_started` | no `completed/paid`; cierre comercial vive fuera |
| Reservation | ReservationService/flow | `active` | `released`, `expired`, `consumed` | ReservationService y expiration; Business consume | reglas exactas de cancelación/compensación dispersas |
| PaymentSession | model/pipeline | `pending` | `confirmed`, `expired`, `cancelled`, `create_failed`; ambiguous requiere recovery/manual | PaymentSessionService/coordinator con CAS | transición desde `create_ambiguous` depende de recovery futuro |
| Payment | PaymentService/Business | `pending` | `paid`, `failed` | Business lleva a paid; confirmation legacy puede failed | coexistencia del flujo legacy y durable debe explicitarse |
| PaymentReconciliation | model/lease docs | `pending` | `completed`, `permanent_failure`, `manual_review`; `retryable` reintentable | claim: pending/retryable/expired processing; processor cierra CAS | `completed` es técnico-financiero, no negocio |
| BusinessCompletion | design/repository | `pending` | `completed`, `permanent_failure`, `manual_review`; `retryable` reintentable | processor bajo lease/TX | tooling de reapertura fuera de alcance |
| Delivery | model/design | `pending` | `delivered`, `cancelled` | `pending→assigned→picked_up→delivered`; DeliveryService | cancelación desde qué estados no está consolidada aquí |
| DeliveryCompletion | repositories/tests | `pending` | `completed`, `not_required`, `permanent_failure`, `manual_review`; `retryable` | processor bajo lease; pickup→not_required, delivery→completed | DTO usa nombre `retryable_failure`, persistencia usa `retryable` |
| FulfillmentCompletion | design/repository | `pending` | `completed`, `permanent_failure`, `manual_review`; `retryable` | processor bajo lease/CAS | efecto durable final deliberadamente abstracto/acotado |
| Customer Purchase | status resolver/panel design | derivado | `delivered`, `cancelled`, `payment_rejected` visibles; otros pueden estabilizarse pero son proyección | resolver puro, ningún escritor | no es máquina durable; precedencia ante combinaciones nuevas |

Estados completos de PaymentSession: `pending`, `create_processing`,
`create_retryable`, `create_ambiguous`, `create_failed`, `ready`, `confirmed`,
`expired`, `cancelled`. Estados visibles Customer Purchase: `pending_payment`,
`processing_payment`, `payment_rejected`, `payment_received`,
`preparing_order`, `preparing_delivery`, `out_for_delivery`, `delivered`,
`cancelled`, `under_review`.

No se reconstruye aquí una máquina más detallada que la evidencia disponible.

## 8. Inventario de contratos

### 8.1 REST estable observable (`veciahorra/v1`)

- Catalog: `GET /catalog/products`, `GET /catalog/products/{id}`.
- Cart: `/cart`, `/cart/items`, `/cart/items/{id}` con GET/POST/PATCH/DELETE
  según recurso.
- Checkout: `POST /checkout/validate`, `POST /checkout`,
  `GET /checkout/{checkout_id}` y `GET /checkout/{checkout_id}/payment-status`.
- Payments: `/payments`, `/payments/{id}`, `/payments/{id}/session`,
  `/payments/session`, `/payments/session/{public_id}`, `/payments/confirm` y
  `/payments/webpay/return`.
- Customer Panel: `GET /customer-panel/purchases` y detalle por ID público.
- Administración/dominio: `/products`, `/inventory`, `/reservations`,
  `/orders`, `/deliveries`, `/categories`, `/brands`, `/units` y subrecursos
  documentados por sus Routes/tests.

La lista es inventario de familias estables, no promesa de hacer públicos los
endpoints administrativos. Permisos, DTO y códigos de error se toman de Routes,
Requests y pruebas; 31.2 debe separar API pública de operación administrativa.

### 8.2 Shortcodes y rutas públicas

- `[veciahorra_frontend]` para catálogo o ficha con `product_id`.
- `[veciahorra_cart]`.
- `[veciahorra_checkout]`.
- `[veciahorra_customer_panel]`.
- `[veciahorra_public_route_link route="..."]`.

`PublicRouteResolver` es autoridad única de Inicio, Catálogo, Carrito, Checkout
y Mis compras; resuelve páginas publicadas por shortcode/portada y memoiza por
request. Parámetro canónico del detalle de compra: `?compra=<checkout_public_id>`.

### 8.3 Ownership, respuestas y errores

- usuario autenticado: identidad WordPress y ownership por user ID;
- invitado: identificador opaco de sesión, nunca localStorage como autoridad;
- IDs públicos no enumerables y respuestas 404/403 conservadoras;
- nonce REST/cookie same-origin cuando aplica;
- backend recalcula precio, stock, monto, fulfillment y elegibilidad;
- errores públicos usan códigos estables y no exponen SQL, tokens, leases,
  stack traces o PII.

### 8.4 Idempotencia, locks y recuperación

- claves y fingerprints versionados, índices UNIQUE y replay estable;
- leases con reloj DB, owner aleatorio, versión monotónica y CAS;
- orden de locks documentado por etapa y colecciones por ID ascendente;
- no mantener transacción durante red Webpay;
- Action Scheduler transporta `authority_id`, deduplica acciones y recupera
  estados elegibles; cinco intentos y backoff están presentes en el pipeline de
  completion;
- create/commit remotos no ofrecen exactly-once físico: ambigüedad se conserva y
  no autoriza repetición ciega.

### 8.5 Acciones programadas implementadas

- `veciahorra_webpay_create_recover` y
  `veciahorra_webpay_create_recovery_sweep`;
- `veciahorra_webpay_return_recovery`;
- `veciahorra_process_payment_reconciliation`;
- `veciahorra_process_business_completion`;
- `veciahorra_process_delivery_completion`;
- `veciahorra_process_fulfillment_completion`;
- `veciahorra_recover_completion_pipeline`.

### 8.6 Separación por madurez

- **Estable:** contratos observables anteriores y cubiertos por pruebas.
- **Implementación actual:** clases, tablas y wiring pueden cambiar sin alterar
  el contrato si no son públicos.
- **Histórico:** endpoints propuestos en `payment-functional-design.md`, rutas
  reservadas del README frontend y `/mis-pedidos/`.
- **Futuro:** resolver híbrido de contenido WC, publication/offer/ranking,
  notifications, SEO y recovery financiero sin retorno completamente cerrado.

## 9. Mapa de dependencias documentales

```text
README / roadmap / diseños 22–27
  └─ transaction-flow.md
      ├─ customer-frontend-functional-design.md
      │   ├─ public-checkout-functional-design.md
      │   │   ├─ public-payment-session-design.md
      │   │   ├─ public-payment-session-backend-design.md
      │   │   └─ public-checkout-durable-payment-pipeline-design.md
      │   └─ customer-panel-v1-design.md
      │       └─ customer-panel-frontend-design.md
      └─ payment-confirmation-functional-design.md
          └─ payment-reconciliation-order-completion-design.md
              ├─ payment-reconciliation-lease-implementation.md
              ├─ payment-reconciliation-attempt-materialization.md
              ├─ business-completion-design.md
              ├─ durable-fulfillment-authority.md
              └─ fulfillment-completion-processor-design.md

public-search-isolation-design.md
  └─ public-navigation-certification.md
      └─ public-navigation-recertification.md
          ├─ legacy-woocommerce-content-pages-isolation-design.md
          ├─ legacy-woocommerce-pages-usage-audit.md
          │   └─ legacy-woocommerce-pages-retirement.md
          └─ public-navigation-final-certification.md
```

Dependencia semántica del pipeline:

1. Payment Reconciliation produce evidencia terminal técnica.
2. Business Completion consume una reconciliación interna completada y
   materializa Payment/Orders/snapshot.
3. Delivery Completion consume Business Completion y materializa Delivery o
   `not_required`.
4. Fulfillment Completion consume la autoridad sellada y la precondición de
   Delivery Completion; no reconstruye etapas.
5. Customer Panel proyecta todas las autoridades anteriores, pero no las
   modifica.

## 10. Duplicidades y contradicciones

| Tema | Documento A | Documento B | Diferencia | Parece vigente y evidencia | Resolución propuesta 31 | Riesgo |
|---|---|---|---|---|---|---|
| Ruta del panel | Frontend README / customer frontend: `/mis-pedidos/` | panel frontend/final navigation: `/mis-compras/` | slug conceptual vs canónico | `/mis-compras/`: resolver, menú, página y pruebas | declarar alias histórico y única ruta v1 | medio |
| Checkout visual | Frontend README: no invoca transacción | public checkout/pipeline + código | fase 28.7.1 vs implementación posterior | pipeline durable y tests | fechar README como evolutivo; consolidar comportamiento actual | alto si se lee aislado |
| Estado Delivery | README/roadmap: pendiente | Delivery design, módulo y tests | planificación obsoleta | implementación observable | separar roadmap histórico de arquitectura runtime | medio |
| Nacimiento de Payment | payment functional/design inicial | business completion | Payment antes/inicio vs materializada post-reconciliation | Business processor y tests | fijar una secuencia v1 y relegar flujo legacy | alto |
| `reconciliation.completed` | diseño extenso puede sugerir pedido completado | lease note/processor | término sobrecargado | significa conciliación técnica; negocio separado | glosario obligatorio por autoridad | alto |
| Checkout terminal | UX habla compra completada | model solo pending/payment_started/expired/cancelled | estado visible vs durable | completions/panel proyectan cierre | declarar que Checkout no representa finalización o diseñar decisión futura | medio |
| Delivery retry | DTO `retryable_failure` | persistencia `retryable` | nombre de resultado vs estado durable | repositorio/status resolver usan `retryable` | documentar traducción, no renombrar en 31.1 | bajo |
| Ofertas | Catalog README ofrece `offers` | no existe autoridad Offer | DTO/read projection vs entidad | se deriva de Inventory/Store/Product | reservar Offer sin inventar entidad v1 | medio |
| WooCommerce futuro | README principal dice integración posterior | adapter y completion WC implementados | visión antigua | código/tests y docs 28.7.4.6 | describir compatibilidad actual y límites | medio |
| Resolver híbrido | isolation design lo recomienda | usage audit/final cert decide no implementar | diseño contingente vs necesidad actual | retiro 177/356 cerró consumidor | conservar como futuro condicional | bajo |
| Navegación | certificaciones inicial/recertificación: no certificado | certificación final | estados históricos distintos | final commit `8f021ce` y páginas retiradas | conservar historia, autoridad final única | bajo |
| Customer Purchase | lenguaje “entidad compra” posible | panel design/query | durable vs proyección | no tiene tabla/escritor propio | llamarlo read model v1 | alto conceptual |

No se corrigió silenciosamente ninguna diferencia. El riesgo indica daño de una
consolidación incorrecta, no necesariamente un defecto runtime actual.

## 11. Vacíos arquitectónicos

| Espacio | Evidencia actual | Vacío reservado, sin diseño nuevo |
|---|---|---|
| Marketplace | módulos Store/Product/Inventory y agrupación por minimarket | límites, agregado, ownership, publicación y gobernanza |
| Publication | estado Product no equivale a publicación por marketplace | autoridad, versiones, moderación y relación con catálogo |
| Offer | DTO público derivado de inventory | identidad durable, vigencia, seller, términos y lifecycle si llega a existir |
| Ranking | orden actual precio/stock/ID | política, explicabilidad, personalización y abuso |
| Availability | active+stock+store activo | definición única frente a reserva, locks, horarios y fulfillment |
| Promotion | no hay autoridad | precios promocionales, prioridad, vigencia, auditoría e impuestos |
| Notifications | backlog futuro | eventos, outbox, preferencias, plantillas, retries y privacidad |
| Identidad minimarket | Store existe | lifecycle, owner WP, roles, suspensión, multiusuario y aislamiento |
| Catalog/Marketplace/Inventory | read model funcional | fronteras de escritura/lectura y vocabulario Oferta/Disponibilidad |
| Administración | pantallas modulares | matriz de capabilities, auditoría y responsabilidades operativas |
| SEO/publicación web | navegación certificada | sitemap/noindex/canonical/robots y 301 de legados |
| Recovery operacional | workers implementados | observabilidad, alertas, dashboard y runbooks/manual review |
| Seguridad | reglas por flujo | threat model único, retención, borrado y clasificación PII |
| Release v1 | estrategia existe | versión real, compatibilidad, migraciones y criterio de freeze |

## 12. Documentos recomendados como fuentes primarias

### Visión, principios y contexto WordPress

- `README.md` (solo propósito/modularidad, no estado de avance).
- `docs/transaction-flow.md`.
- `docs/definition-of-done.md` y `docs/release-strategy.md` para calidad.

### Catálogo, Inventory, Cart y Checkout

- `app/Modules/Catalog/README.md`.
- `docs/cart-functional-design.md`, contrastado con pruebas Cart.
- `docs/checkout-functional-design.md` como antecedente.
- `docs/public-checkout-functional-design.md`.
- `docs/public-payment-session-backend-design.md`.
- `docs/durable-fulfillment-authority.md`.

### Payments, Webpay y pipeline durable

- `docs/payment-confirmation-functional-design.md`.
- `docs/payment-reconciliation-order-completion-design.md`, usando sus notas
  implementadas como refinamiento.
- `docs/payment-reconciliation-lease-implementation.md`.
- `docs/payment-reconciliation-attempt-materialization.md`.
- `docs/business-completion-design.md`.
- `docs/public-checkout-durable-payment-pipeline-design.md`.
- `docs/webpay-return-and-commit-foundation.md`.

### Delivery y Fulfillment

- `docs/delivery-functional-design.md`.
- `docs/durable-fulfillment-authority.md`.
- `docs/fulfillment-completion-processor-design.md`.
- pruebas DeliveryCompletion/FulfillmentCompletion como autoridad ejecutable.

### Customer Panel y frontend

- `docs/customer-panel-v1-design.md`.
- `docs/customer-panel-frontend-design.md`.
- `docs/customer-frontend-functional-design.md` solo como visión histórica.
- `docs/public-navigation-final-certification.md`.
- `docs/public-search-isolation-design.md`.
- `docs/legacy-woocommerce-pages-usage-audit.md` para la decisión híbrida.

### Contratos ejecutables

Para cada capítulo deben citarse las suites del catálogo 3.3 correspondientes.
Las pruebas verifican implementación, pero no deben usarse solas para inventar
intención de dominio.

## 13. Propuesta de estructura para el Microhito 31.2

Índice inicial derivado de este inventario para
`docs/veciahorra-architecture-v1.md`:

1. Propósito, alcance v1.0 y glosario normativo.
2. Visión del sistema y límites del marketplace de proximidad.
3. Principios arquitectónicos e invariantes globales.
4. Contexto WordPress: bootstrap, módulos, REST, identidad y persistencia.
5. Mapa de bounded contexts implementados y dependencias permitidas.
6. Identidad, ownership, seguridad y referencias públicas.
7. Product, Catalog, Inventory y el límite actual de Offer/Availability.
8. Cart y transición a compra congelada.
9. Checkout, Orders, Reservations y fulfillment autorizado.
10. PaymentSession, gateway y creación remota Webpay.
11. PaymentOriginContext, WebpayReturn y autoridad financiera.
12. Payment Reconciliation y semántica técnica de estados.
13. Business Completion y frontera transaccional interna.
14. Delivery, Delivery Completion y operación logística.
15. Fulfillment Completion y cierre durable.
16. Action Scheduler, recovery, leases, CAS e idempotencia end-to-end.
17. Customer Purchase como read model y Customer Panel.
18. Frontend público, rutas, shortcodes y navegación certificada.
19. Integración WooCommerce: compatibilidad deliberada y aislamiento.
20. Contratos REST/DTO/eventos y política de errores.
21. Testing, verificación, observabilidad y release.
22. Decisiones históricas reemplazadas y compatibilidad.
23. Riesgos y vacíos reservados: Marketplace, Publication, Offer, Ranking,
    Availability, Promotion, Notifications y SEO.
24. Matriz final de autoridades y escritores autorizados.

Antes de redactarlo, 31.2 debe resolver explícitamente las contradicciones de la
sección 10, en especial Payment, Checkout terminal, Customer Purchase y la
frontera Catalog/Marketplace/Inventory. No debe implementar los espacios de la
sección 11 ni presentar brechas futuras como garantías v1.
