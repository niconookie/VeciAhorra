# Arquitectura de VeciAhorra v1.0

**Versión:** v1.0 Draft

**Estado:** Borrador estructural; contenido arquitectónico pendiente

**Fecha:** 18 de julio de 2026

**Autores:** Equipo VeciAhorra; Codex (estructura documental)
**Propósito:** Estructura maestra definitiva para consolidar la arquitectura
v1.0 a partir de `docs/veciahorra-architecture-inventory.md`.

## Tabla de contenido

1. Introducción
2. Visión del Sistema
3. Principios Arquitectónicos
4. Vocabulario Oficial
5. Arquitectura General y Plataforma WordPress
6. Bounded Contexts
7. Identidad, Ownership y Seguridad
8. Modelo del Dominio
9. Product, Catalog, Inventory y límite de Marketplace
10. Cart
11. Checkout, Orders y Reservations
12. PaymentSession y creación remota Webpay
13. PaymentOriginContext, WebpayReturn y autoridad financiera
14. Payment Reconciliation
15. Business Completion
16. Delivery y Delivery Completion
17. Fulfillment Completion
18. Action Scheduler, Recovery e Idempotencia
19. Autoridades Durables
20. Máquinas de Estado
21. Relaciones del Dominio
22. Contratos Públicos
23. Arquitectura Frontend, Customer Panel y Navegación Pública
24. Integraciones
25. Testing, Verificación, Operación y Release
26. Decisiones Arquitectónicas (ADR)
27. Riesgos Arquitectónicos y Vacíos
28. Evolución Futura
29. Referencias

---

## 1. Introducción

### 1.1 Estado del documento

Esta especificación se encuentra en estado **v1.0 Draft**. Los capítulos
fundacionales 1 a 6 y las reglas arquitectónicas de evolución de 28.1 están
redactados normativamente. Los capítulos restantes conservan su estructura de
trabajo y no adquieren autoridad hasta que sean completados y revisados.

Las expresiones **debe**, **no debe** y **solo** establecen obligaciones. Las
expresiones **puede** y **recomendado** describen opciones compatibles. Una
sección marcada como prevista, pendiente, histórica o futura no prescribe el
comportamiento de v1.0.

### 1.2 Propósito

Esta especificación fija el lenguaje, los límites y los principios permanentes
de VeciAhorra v1.0. Su función es permitir que diseño, implementación, pruebas y
operación evalúen un cambio contra una referencia arquitectónica común, sin
convertir detalles accidentales del código en reglas de dominio.

### 1.3 Alcance

El alcance comprende la arquitectura del plugin VeciAhorra, sus contextos de
dominio, sus fronteras con WordPress y sus integraciones externas. Distingue:

- dominio propio y capacidades delegadas a la plataforma;
- escritura autoritativa y modelos de lectura;
- contratos públicos y detalles reemplazables;
- comportamiento vigente, antecedentes históricos y espacios futuros.

Esta versión no especifica infraestructura general ajena al plugin, políticas
comerciales de los minimarkets ni capacidades que el inventario clasifica como
futuras.

### 1.4 Audiencia

El documento está dirigido a responsables de arquitectura y producto,
desarrolladores, revisores, responsables de pruebas, operación y release, y a
quienes integren VeciAhorra con WordPress o proveedores externos. Cada audiencia
debe usar el vocabulario del capítulo 4 y respetar las autoridades del dominio,
aunque trabaje solo en una superficie del sistema.

### 1.5 Cómo debe leerse esta especificación

1. Los capítulos 1 a 6 definen el marco normativo común.
2. Los capítulos de dominio deberán especializar ese marco sin contradecirlo.
3. Las referencias documentales explican el respaldo de cada regla; no
   incorporan automáticamente todo el contenido histórico de la fuente.
4. Cuando dos fuentes difieran, se aplica la sucesión documental registrada en
   el inventario y la fuente primaria vigente del tema.
5. Las pruebas son evidencia ejecutable de implementación, pero no crean por sí
   solas intención de dominio.
6. Una ausencia documental no autoriza a inventar una entidad, autoridad,
   contrato o capacidad.

### 1.6 Documentos fuente y referencias cruzadas

- Fuente normativa del alcance y la vigencia:
  `docs/veciahorra-architecture-inventory.md`, secciones 1–3 y 12.
- Fuente de propósito y modularidad: `README.md`, únicamente en sus apartados
  vigentes según el inventario.
- Fuente de criterios de entrega: `docs/definition-of-done.md`.
- Referencias: vocabulario en capítulo 4; ADR en capítulo 26; riesgos en 27;
  evolución en 28; fuentes completas en 29.

Diagramas: ninguno. Tablas previstas: T-01 Alcance y madurez documental; T-02
Convenciones normativas. Entidades, autoridades y contratos se mencionan aquí
solo como categorías del alcance y se definirán en sus capítulos respectivos.

## 2. Visión del Sistema

### 2.1 Qué es VeciAhorra

VeciAhorra es una plataforma de proximidad implementada como plugin modular de
WordPress. Permite que compradores consulten la oferta disponible de comercios
locales y recorran un ciclo de compra trazable desde la selección hasta el pago
y el cumplimiento. El dominio transaccional pertenece a VeciAhorra: WordPress
lo hospeda y le aporta capacidades de plataforma, pero no sustituye sus reglas.

### 2.2 Por qué existe y qué problemas resuelve

VeciAhorra existe para ofrecer una experiencia confiable de compra local donde
catálogo, disponibilidad, identidad del comprador y avance de la operación se
mantengan coherentes. Sus capacidades v1.0 abarcan:

- exposición pública de productos disponibles y sus alternativas locales;
- selección identificada del comprador;
- validación autoritativa de precio, producto y stock;
- reserva temporal y separación de la compra por comercio;
- coordinación durable del pago y de la finalización posterior;
- consulta de la compra mediante una proyección de solo lectura.

La visión no promete que cada capacidad comparta una única entidad ni que el
frontend reproduzca el dominio. La continuidad se obtiene mediante autoridades
durables, contratos explícitos e integración entre contextos.

### 2.3 Problemas que deliberadamente no intenta resolver

La arquitectura v1.0 no pretende:

- reemplazar WordPress como host, identidad de usuario o gestión de páginas;
- reemplazar al proveedor financiero ni adjudicarse su resultado remoto;
- convertir WooCommerce en autoridad del flujo nativo VeciAhorra;
- resolver políticas comerciales, logística física o gobierno interno de cada
  minimarket fuera de los contratos documentados;
- declarar como presentes Publication, Offer durable, Ranking, Promotion,
  Notifications u otras capacidades reservadas por el inventario;
- garantizar exactly-once físico sobre redes o sistemas externos.

### 2.4 Objetivos

- Mantener un núcleo modular con responsabilidades observables.
- Proteger consistencia, ownership e invariantes bajo errores y reintentos.
- Conservar evidencia durable suficiente para recuperar trabajo interrumpido.
- Ofrecer contratos públicos estables sin exponer detalles internos.
- Integrarse con la plataforma y proveedores mediante adaptadores acotados.
- Permitir evolución compatible y verificable hacia versiones posteriores.

### 2.5 No objetivos

- Unificar todos los dominios en un servicio o modelo compartido.
- Delegar decisiones de negocio al navegador, tema o gateway.
- Reutilizar una integración externa como modelo canónico interno.
- Preferir una respuesta rápida si para obtenerla debe ocultarse ambigüedad,
  perderse trazabilidad o reconstruirse una decisión ya sellada.
- Diseñar en esta versión los vacíos que el inventario mantiene expresamente
  como futuros.

### 2.6 Filosofía general

VeciAhorra trata la compra como una coordinación de responsabilidades, no como
una cadena de pantallas. Cada contexto conserva aquello que puede afirmar, lee
otras autoridades mediante contratos definidos y registra estados intermedios
cuando una operación no puede concluir de forma atómica. La plataforma favorece
la corrección recuperable y observable por sobre atajos entre capas.

### 2.7 Responsabilidades delegadas y visión como plataforma

- **WordPress** aporta ciclo de vida del plugin, usuarios, permisos de
  plataforma, páginas, opciones, REST, shortcodes y extensibilidad.
- **WooCommerce** permanece como integración compatible y aislada para los
  escenarios documentados; no gobierna el flujo público nativo.
- **Proveedores externos**, incluido Webpay, ejecutan capacidades remotas y
  entregan evidencia; no escriben directamente el dominio interno.
- **VeciAhorra** conserva reglas de negocio, ownership, autoridades durables,
  transiciones y proyecciones públicas.

La visión de plataforma es incorporar capacidades mediante contextos y
contratos compatibles, sin diluir las fronteras anteriores.

### 2.8 Documentos fuente y referencias cruzadas

- Principal: `README.md`, propósito y modularidad.
- Apoyo vigente: `docs/transaction-flow.md` y
  `docs/public-checkout-durable-payment-pipeline-design.md`.
- Alcance y vacíos: `docs/veciahorra-architecture-inventory.md`, secciones 2,
  4 y 11.
- Referencias: arquitectura general en capítulo 5; contextos en 6; evolución en
  28.

Diagramas previstos: A-01 Contexto general del sistema; A-02 Recorrido
funcional v1.0. Tablas previstas: T-03 Actores y objetivos; T-04 Alcance
positivo y negativo v1.0. Las entidades y autoridades nombradas son referencias
de alcance; su definición queda reservada para capítulos posteriores.

## 3. Principios Arquitectónicos

### 3.1 Autoridad durable

**Definición.** Una decisión relevante para continuar o recuperar el negocio
debe quedar representada en una fuente durable identificable, con un escritor
autorizado y reglas de transición.

**Motivación.** Una petición, callback o job puede interrumpirse; la memoria del
proceso y la respuesta del navegador no bastan para decidir qué ocurrió.

**Consecuencias.** Los consumidores referencian la autoridad previa y no
reconstruyen sus decisiones desde datos incidentales. La durabilidad no implica
que toda tabla sea autoridad.

**Ejemplos.** Checkout sella datos de compra; PaymentSession conserva la
creación remota; las etapas de completion persisten su progreso.

**Implicancias futuras.** Toda nueva autoridad debe declarar identidad,
escritor, invariantes, estados, idempotencia, recuperación y consumidores antes
de incorporarse.

**Respaldo.** `docs/public-checkout-durable-payment-pipeline-design.md`,
`docs/business-completion-design.md` y
`docs/durable-fulfillment-authority.md`.

### 3.2 Ownership

**Definición.** Todo recurso no público pertenece a una identidad o actor
determinable, y esa pertenencia se valida en el backend en cada acceso o
mutación.

**Motivación.** Un identificador difícil de adivinar reduce enumeración, pero
no reemplaza autorización ni demuestra pertenencia.

**Consecuencias.** Usuario WordPress e invitado con sesión opaca son identidades
distintas; las referencias públicas no conceden acceso por sí solas.

**Ejemplos.** El carrito exige identidad; Checkout y PaymentSession verifican
owner; Customer Panel filtra compras por comprador.

**Implicancias futuras.** Toda entidad o contrato nuevo debe declarar owner,
regla de acceso y comportamiento seguro ante recursos ajenos.

**Respaldo.** `docs/public-payment-session-backend-design.md`,
`docs/customer-panel-v1-design.md` y el inventario, secciones 4 y 8.3.

### 3.3 Responsabilidad única

**Definición.** Cada contexto, servicio y capa debe realizar únicamente las
decisiones que le pertenecen y delegar las restantes mediante contratos.

**Motivación.** Mezclar persistencia, transporte, integración y reglas de
negocio crea escritores alternativos e impide verificar invariantes.

**Consecuencias.** Routes y Controllers adaptan; Services deciden; Repositories
persisten condiciones solicitadas; gateways no modifican el dominio.

**Ejemplos.** Cart no reserva stock; Orders no interpreta gateways; el frontend
no confirma pagos.

**Implicancias futuras.** La conveniencia de llamar una dependencia no justifica
traspasar su responsabilidad ni escribir sus datos.

**Respaldo.** `docs/transaction-flow.md`, secciones 4 y 10, y `README.md`.

### 3.4 Bounded Context

**Definición.** Un bounded context es un límite semántico dentro del cual el
vocabulario, las reglas y la autoridad de escritura son coherentes.

**Motivación.** Términos iguales, como `completed`, pueden tener significados
distintos; compartirlos sin contexto produce acoplamiento conceptual.

**Consecuencias.** Los contextos se comunican por identificadores, resultados,
snapshots o contratos explícitos, no mediante acceso informal a internals.

**Ejemplos.** La finalización técnica de reconciliación no equivale al cierre de
negocio; Customer Purchase es una proyección y no una autoridad transaccional.

**Implicancias futuras.** Un contexto nuevo requiere propósito, límites,
lenguaje, propiedad de datos y dependencias permitidas.

**Respaldo.** Inventario, secciones 4, 9 y 10; `README.md`.

### 3.5 Separación lectura/escritura

**Definición.** Los modelos destinados a consultar o presentar información no
modifican las autoridades que proyectan.

**Motivación.** Una vista suele combinar varias fuentes y no posee información
suficiente para gobernar sus transiciones.

**Consecuencias.** Un read model puede derivar estados visibles y DTO, pero no
se convierte por ello en entidad durable ni escritor.

**Ejemplos.** Customer Panel proyecta la compra y el catálogo público proyecta
datos disponibles sin gobernar las fuentes que consulta.

**Implicancias futuras.** Toda proyección nueva debe declarar fuentes,
precedencia, frescura y ausencia de efectos laterales.

**Respaldo.** `docs/customer-panel-v1-design.md`,
`app/Modules/Catalog/README.md` e inventario, sección 6.3.

### 3.6 Contratos explícitos

**Definición.** La comunicación entre capas, contextos y sistemas se expresa
mediante entradas, salidas, errores, precondiciones y compatibilidad definidos.

**Motivación.** El conocimiento implícito de una implementación no protege a
consumidores ni permite detectar deriva.

**Consecuencias.** IDs públicos, DTO, interfaces, códigos de error y payloads
son contratos cuando se declaran como tales; clases internas no lo son
automáticamente.

**Ejemplos.** Interfaces de gateway, respuestas del Customer Panel y familias
REST inventariadas.

**Implicancias futuras.** Un cambio de contrato exige evaluar consumidores,
pruebas, versionado y migración antes de publicarse.

**Respaldo.** Inventario, secciones 8 y 12;
`docs/public-payment-session-backend-design.md`.

### 3.7 Idempotencia

**Definición.** Repetir una operación con la misma identidad y contrato produce
el mismo resultado observable o un rechazo estable, sin duplicar efectos.

**Motivación.** HTTP, jobs, callbacks y redes pueden reintentar aun cuando el
primer intento haya tenido efectos.

**Consecuencias.** Se requieren identidades estables, fingerprints, unicidad,
transiciones condicionales y replay; un payload incompatible no se reinterpreta.

**Ejemplos.** Creación de Payment por conjunto de Orders, confirmación terminal
repetida y procesadores protegidos con lease y CAS.

**Implicancias futuras.** Toda operación reintentable debe documentar clave,
alcance, resultado de replay y tratamiento de colisiones.

**Respaldo.** `docs/transaction-flow.md`, sección 8;
`docs/payment-reconciliation-lease-implementation.md`.

### 3.8 Consistencia antes que conveniencia

**Definición.** Cuando rapidez de implementación y preservación de invariantes
entran en conflicto, prevalece la consistencia verificable del dominio.

**Motivación.** Un éxito parcial o una inferencia optimista puede dejar stock,
órdenes, pagos y proyecciones en desacuerdo.

**Consecuencias.** Se valida antes de escribir, se comprueba el número de filas,
se usan transacciones o compensaciones y la ambigüedad se conserva.

**Ejemplos.** Checkout compensa escrituras parciales; confirmación de pago hace
rollback; un resultado remoto ambiguo no autoriza repetición ciega.

**Implicancias futuras.** No se aceptarán atajos que oculten fallos parciales,
degraden ownership o fabriquen éxito desde evidencia incompleta.

**Respaldo.** `docs/transaction-flow.md`, secciones 6, 7 y 9;
`docs/public-checkout-durable-payment-pipeline-design.md`.

### 3.9 Recuperación automática

**Definición.** Una operación durable interrumpida debe poder reanudarse desde
su autoridad persistida mediante trabajo seguro, acotado e idempotente.

**Motivación.** Los fallos transitorios y caídas de proceso son esperables; la
recuperación manual no puede ser el camino normal.

**Consecuencias.** Se distinguen estados reintentables, ambiguos y terminales;
los schedulers transportan IDs de autoridad y no decisiones reconstruidas.

**Ejemplos.** Sweeps de recuperación, leases vencidos y pipeline de completion
reanudable.

**Implicancias futuras.** Una nueva etapa durable debe definir elegibilidad,
backoff, deduplicación, límite de intentos y escalamiento manual.

**Respaldo.** `docs/public-checkout-durable-payment-pipeline-design.md`,
`docs/fulfillment-completion-processor-design.md` e inventario, sección 8.4.

### 3.10 Canonicidad de rutas

**Definición.** Cada destino público oficial posee una única autoridad de
resolución; consumidores y enlaces no construyen rutas alternativas.

**Motivación.** Slugs hardcodeados y páginas paralelas conducen a flujos
incompatibles y rompen cambios de configuración.

**Consecuencias.** Inicio, Catálogo, Carrito, Checkout y Mis compras se obtienen
desde `PublicRouteResolver`; la navegación conserva un único recorrido visible.

**Ejemplos.** Enlaces de portada y menú hacia las páginas VeciAhorra; aislamiento
de rutas comerciales heredadas.

**Implicancias futuras.** Toda ruta pública nueva debe tener autoridad única y
una estrategia explícita de compatibilidad antes de reemplazar otra.

**Respaldo.** Inventario, secciones 8.2 y 10;
`docs/public-navigation-final-certification.md`.

### 3.11 Aislamiento de WooCommerce

**Definición.** WooCommerce es una integración deliberada, no una fuente
implícita de navegación, búsqueda ni decisiones del dominio nativo.

**Motivación.** La coexistencia de superficies comerciales puede desviar al
usuario o mezclar autoridades incompatibles.

**Consecuencias.** La experiencia pública VeciAhorra excluye elementos Woo no
autorizados, mientras los contextos Woo requeridos permanecen funcionalmente
aislados.

**Ejemplos.** Exclusión de `product` en búsqueda general y retiro operativo de
páginas heredadas sin desactivar WooCommerce.

**Implicancias futuras.** Toda ampliación WooCommerce debe declarar dirección
de adaptación, consumidor, autoridad y pruebas negativas sobre el flujo nativo.

**Respaldo.** `docs/public-search-isolation-design.md`,
`docs/legacy-woocommerce-pages-usage-audit.md` y
`docs/public-navigation-final-certification.md`.

### 3.12 Diseño API-first

**Definición.** Las capacidades se expresan primero como contratos de backend
consumibles y verificables; la interfaz es un consumidor de esos contratos.

**Motivación.** Las reglas no deben depender del DOM, tema, shortcode o estado
local del navegador.

**Consecuencias.** El servidor valida identidad, precio, stock, monto,
fulfillment y elegibilidad; el frontend adapta respuestas y presenta estados.

**Ejemplos.** Catálogo público, carrito, checkout y Customer Panel consumen
contratos REST en vez de decidir el negocio localmente.

**Implicancias futuras.** Una nueva interfaz debe reutilizar el contrato
autoritativo o justificar formalmente uno nuevo, nunca duplicar reglas.

**Respaldo.** `docs/public-checkout-functional-design.md`,
`docs/customer-panel-frontend-design.md` e inventario, sección 8.

### 3.13 Estados explícitos

**Definición.** El progreso durable se representa con estados nombrados,
transiciones permitidas, precondiciones y terminalidad dentro de su contexto.

**Motivación.** Booleanos o inferencias dispersas no distinguen trabajo
pendiente, reintentable, ambiguo, terminal o sujeto a revisión.

**Consecuencias.** Cada transición tiene escritor autorizado; un mismo nombre
no se extrapola entre contextos; los read models pueden traducir sin gobernar.

**Ejemplos.** Estados de Reservation, PaymentSession, Reconciliation y las
etapas de completion inventariadas.

**Implicancias futuras.** Añadir o cambiar un estado exige definir semántica,
entradas, salidas, replay, observabilidad y consumidores compatibles.

**Respaldo.** Inventario, sección 7; `docs/transaction-flow.md`, sección 5.

### 3.14 Evolución compatible

**Definición.** La arquitectura cambia preservando contratos y datos vigentes,
o mediante una transición explícita, versionada y verificable.

**Motivación.** Consumidores, instalaciones y trabajos durables pueden coexistir
con versiones diferentes durante una actualización.

**Consecuencias.** Migraciones son versionadas y reejecutables; cambios públicos
evalúan compatibilidad; una ruptura debe declararse y ofrecer migración.

**Ejemplos.** Rutas canónicas centralizadas sin eliminar páginas de
compatibilidad y promoción del mismo código validado entre canales de release.

**Implicancias futuras.** Se aplican las reglas de 28.1 y versionado semántico a
partir de 1.0.

**Respaldo.** `docs/definition-of-done.md`, `docs/release-strategy.md` y
`README.md`, convención de cambios incompatibles.

### 3.15 Invariantes globales

1. **Escritor único.** Una autoridad durable tiene un único escritor lógico
   autorizado; otros contextos solicitan o consumen su resultado. Respaldo:
   `docs/transaction-flow.md` y diseños de completion.
2. **Lectura sin efectos.** Un read model o módulo de consulta no modifica el
   estado de negocio que proyecta. Respaldo: `docs/customer-panel-v1-design.md`.
3. **Dominio soberano.** Una integración externa aporta evidencia o capacidad,
   pero no gobierna el dominio interno. Respaldo:
   `docs/payment-confirmation-functional-design.md`.
4. **Frontend no autoritativo.** El frontend no decide precio, stock, ownership,
   monto, elegibilidad, pago ni transiciones. Respaldo: diseños públicos de
   Checkout, PaymentSession y Customer Panel.
5. **No reconstrucción.** Una etapa no reconstruye una decisión durable ya
   sellada por otra autoridad. Respaldo:
   `docs/durable-fulfillment-authority.md` y diseños de completion.
6. **Consistencia observable.** Ninguna operación informa éxito si sus efectos
   obligatorios quedaron parciales o no verificables. Respaldo:
   `docs/transaction-flow.md`.
7. **Ownership obligatorio.** Un ID público nunca reemplaza la validación de
   pertenencia. Respaldo: `docs/public-payment-session-backend-design.md` y
   `docs/customer-panel-v1-design.md`.
8. **Ambigüedad conservada.** Un resultado remoto incierto no se transforma en
   éxito, fallo ni reintento ciego. Respaldo:
   `docs/public-checkout-durable-payment-pipeline-design.md`.
9. **Rutas canónicas.** Las cinco rutas públicas oficiales se resuelven por una
   única autoridad. Respaldo: inventario 8.2 y certificación final de navegación.
10. **Contratos compatibles.** Un contrato público no cambia de significado de
    forma silenciosa. Respaldo: `docs/definition-of-done.md` y
    `docs/release-strategy.md`.
11. **Contextos aislados.** WooCommerce y otras integraciones no contaminan el
    flujo nativo salvo por adaptadores expresamente autorizados. Respaldo:
    diseños de aislamiento público inventariados.
12. **Recuperación desde autoridad.** Los jobs transportan referencias durables
    y reanudan estados elegibles idempotentemente. Respaldo: inventario 8.4 y
    pipeline durable.

### 3.16 Documentos fuente y referencias cruzadas

- Fuente transversal principal: `docs/transaction-flow.md`.
- Durabilidad y recuperación:
  `docs/public-checkout-durable-payment-pipeline-design.md`.
- Frontera financiera: `docs/payment-confirmation-functional-design.md`.
- Ownership y lectura: `docs/public-payment-session-backend-design.md` y
  `docs/customer-panel-v1-design.md`.
- Navegación y aislamiento: `docs/public-navigation-final-certification.md` y
  `docs/public-search-isolation-design.md`.
- Evolución: `docs/definition-of-done.md` y `docs/release-strategy.md`.
- Referencias: vocabulario en 4; contextos en 6; autoridades en 19; estados en
  20; reglas de evolución en 28.1.

Diagramas: ninguno. Tablas previstas: T-05 Principios e invariantes; T-06
Prohibiciones por capa. Las entidades, autoridades y contratos citados son
ejemplos documentados; sus especificaciones permanecen pendientes.

## 4. Vocabulario Oficial

### 4.1 Términos normativos

- **Entidad:** objeto del dominio con identidad estable y ciclo de vida. Una
  clase o fila no es automáticamente una entidad.
- **Autoridad:** fuente reconocida para afirmar un hecho o decisión dentro de un
  límite definido.
- **Autoridad durable:** autoridad persistida que permite decidir y recuperar
  trabajo después de finalizar la request o proceso que la creó.
- **Bounded context:** límite semántico donde vocabulario, reglas y autoridad de
  escritura mantienen un significado coherente.
- **Aggregate:** conjunto de objetos del dominio gobernado como unidad de
  consistencia a través de una raíz. Solo debe usarse cuando esa frontera esté
  documentada; no es sinónimo de módulo ni tabla.
- **Ownership:** relación verificable que determina qué identidad o actor puede
  acceder o solicitar cambios sobre un recurso.
- **Contrato:** acuerdo explícito de entradas, salidas, errores, precondiciones,
  semántica y compatibilidad entre consumidores y proveedores.
- **Read model:** proyección de solo lectura construida desde una o más
  autoridades para una consulta o experiencia específica; no gobierna las
  fuentes que representa.
- **Pipeline:** secuencia coordinada de etapas con entradas, resultados y puntos
  de recuperación explícitos. No implica una única transacción global.
- **Reconciliación:** validación y clasificación durable de evidencia externa
  frente a la identidad y expectativas internas; no equivale por sí misma a
  completar el negocio.
- **Completion:** etapa durable posterior a una precondición terminal que
  materializa efectos internos acotados. Su significado siempre se califica por
  contexto; `completed` no es un estado global.
- **Integración:** frontera que adapta capacidades o evidencia de una plataforma
  o proveedor sin transferirle autoridad implícita sobre el dominio.
- **Snapshot:** copia inmutable de los datos necesarios para preservar una
  decisión o ejecutar una etapa posterior sin reconstruirla desde fuentes
  mutables.
- **Recuperación:** reanudación segura de trabajo durable interrumpido a partir
  de una autoridad y sus estados elegibles.
- **Escritor autorizado:** único componente lógico facultado para crear o
  transicionar una autoridad conforme a sus invariantes. Puede operar mediante
  varios transportes sin dejar de ser un único escritor lógico.
- **Estado terminal:** estado que no admite transición automática posterior
  dentro de su máquina y contexto. Terminal no significa necesariamente éxito.
- **Idempotencia:** propiedad por la cual repetir la misma operación identificada
  no duplica efectos y devuelve un resultado estable o rechazo compatible.
- **Consistencia:** cumplimiento simultáneo de invariantes y relaciones que una
  operación declara garantizar, incluyendo ausencia de éxito parcial silencioso.

### 4.2 Términos complementarios

- **DTO:** representación de datos de un contrato; no es entidad ni autoridad.
- **Servicio:** componente que ejecuta o coordina comportamiento; no adquiere
  ownership de datos solo por acceder a ellos.
- **Repositorio:** frontera de persistencia que aplica operaciones solicitadas;
  no decide por sí sola reglas de negocio.
- **Referencia pública:** identificador apto para exposición externa; no concede
  ownership ni autorización.
- **CAS:** escritura condicional que solo progresa si versión, owner o estado de
  origen continúan siendo los esperados.
- **Lease:** derecho temporal y durable a procesar una autoridad, recuperable al
  vencer y protegido contra escritores concurrentes.
- **Canónico:** único origen o representación oficial que deben consumir los
  demás componentes para un propósito definido.
- **Proyección:** transformación de autoridades para lectura; en esta
  especificación se usa como sinónimo operativo de read model cuando no posee
  escritura de negocio.

### 4.3 Reglas de uso del vocabulario

- `completed` debe acompañarse por la autoridad o contexto al que pertenece.
- Customer Purchase se denomina read model, no entidad durable.
- Offer y Availability no se usan como entidades v1.0 mientras el inventario
  solo respalde su condición proyectada o futura.
- Módulo, servicio, repositorio, DTO y tabla no se denominan bounded context,
  entidad o autoridad sin una definición expresa.
- El vocabulario de una integración no reemplaza el lenguaje del dominio.

### 4.4 Documentos fuente y referencias cruzadas

- Principal: inventario, secciones 4, 6, 7 y 10.
- Apoyo: `docs/transaction-flow.md`,
  `docs/payment-reconciliation-order-completion-design.md`,
  `docs/customer-panel-v1-design.md` y diseños de completion citados por el
  inventario.
- Referencias: principios en capítulo 3; modelo en 8; autoridades en 19; estados
  en 20; relaciones en 21.

Diagramas: ninguno. Tablas previstas: T-07 Glosario oficial; T-08 Términos
históricos y reemplazos. Las listas anteriores fijan terminología, pero no
completan las especificaciones de entidades, estados ni autoridades.

## 5. Arquitectura General y Plataforma WordPress

### 5.1 Visión de alto nivel

VeciAhorra se despliega como un plugin modular dentro de WordPress. El punto de
entrada inicia la aplicación; los módulos agrupan capacidades; las capas de
aplicación coordinan reglas; la persistencia conserva autoridades; REST y
shortcodes exponen contratos; el frontend presenta read models y solicita
operaciones al backend.

Esta organización establece una dirección general:
presentación y consumidores dependen de contratos explícitos; aplicación y
dominio coordinan decisiones; persistencia y adaptadores ejecutan operaciones
autorizadas sobre las capacidades de plataforma y proveedores. Esta dirección
conceptual no define un pipeline transaccional.

### 5.2 Fronteras de responsabilidad

- **Presentación:** renderiza, captura intención y adapta respuestas; no decide
  reglas de negocio.
- **Transporte:** autentica o valida la solicitud según el contrato, transforma
  entrada/salida y no se convierte en autoridad.
- **Aplicación y dominio:** valida invariantes, coordina contextos y solicita
  transiciones a los escritores autorizados.
- **Persistencia:** aplica lecturas y escrituras condicionadas sin inventar
  reglas fuera de su contrato.
- **Integración:** traduce entre VeciAhorra y una capacidad externa, preservando
  la soberanía del modelo interno.
- **Plataforma WordPress:** hospeda ciclo de vida, identidad, permisos y
  superficies extensibles; no absorbe automáticamente el dominio.

### 5.3 Dependencias de plataforma

WordPress es una dependencia estructural de v1.0. Sus usuarios, páginas,
opciones, hooks, REST y shortcodes se consumen mediante fronteras identificadas.
La base de datos conserva el estado durable del plugin mediante schemas y
migraciones versionadas. Action Scheduler puede transportar trabajo diferido,
pero la decisión de qué procesar permanece en la autoridad durable.

Blocksy y Elementor pertenecen a presentación configurable. WooCommerce y
Webpay pertenecen a integración. Ninguno debe introducir reglas transversales
en el núcleo por el solo hecho de estar activo.

### 5.4 Restricciones de alto nivel

- No se accede informalmente a datos de otro contexto para evitar su contrato.
- No se mantiene una transacción local abierta durante una llamada de red.
- No se considera que un hook, request, sesión de navegador o job sea autoridad
  durable por sí mismo.
- No se duplican reglas entre REST, shortcodes, frontend y administración.
- No se confunde la estructura modular PHP con el mapa definitivo de bounded
  contexts.

### 5.5 Documentos fuente y referencias cruzadas

- Principal: `README.md`, arquitectura por módulos.
- Apoyo: inventario, secciones 4, 6.4 y 6.5;
  `docs/public-checkout-durable-payment-pipeline-design.md` para límites de red
  y trabajo durable.
- Referencias: contextos en 6; seguridad en 7; contratos en 22; frontend en 23;
  integraciones en 24.

Diagramas previstos: A-03 Mapa general de módulos y capas; A-04 Contexto
WordPress. Tablas previstas: T-09 Capas y responsabilidades; T-10 Dependencias
de plataforma. No se crean diagramas ni se detallan integraciones en este
microhito.

## 6. Bounded Contexts

### 6.1 Propósito

Los bounded contexts preservan coherencia semántica y ownership de escritura en
un sistema donde selección, disponibilidad, compra, pago, cumplimiento y
consulta evolucionan con ritmos diferentes. El límite permite que cada contexto
afirme únicamente aquello que conoce y que sus consumidores dependan de un
contrato, no de su representación interna.

### 6.2 Límites

Un límite de contexto se determina por combinación de:

- vocabulario con significado propio;
- invariantes y decisiones que deben cambiar juntas;
- autoridad o escritor lógico de esas decisiones;
- contratos ofrecidos y dependencias consumidas;
- tratamiento propio de errores, idempotencia y recuperación.

Los directorios y clases aportan evidencia, pero no fijan por sí solos el
límite. Tampoco toda etapa del pipeline necesita ser un contexto separado. La
asignación definitiva de entidades y autoridades se documentará en capítulos
posteriores.

### 6.3 Independencia

La independencia no significa aislamiento operativo absoluto. Significa que un
contexto:

- protege sus invariantes mediante su escritor autorizado;
- no requiere conocer tablas, clases internas o estados incidentales del otro;
- puede modificar su implementación sin romper contratos compatibles;
- conserva significado propio aun cuando coordine una operación mayor;
- no adopta automáticamente el modelo de WordPress, WooCommerce o un gateway.

### 6.4 Comunicación entre contextos

La comunicación permitida adopta una de estas formas generales:

- solicitud síncrona a un contrato de aplicación;
- referencia durable a una autoridad previa;
- snapshot sellado cuando el dato mutable no debe reinterpretarse;
- resultado o DTO explícito;
- trabajo asíncrono identificado por el ID de una autoridad;
- lectura de una proyección sin efectos laterales.

Una llamada directa no concede derecho de escritura. Una etapa consumidora no
debe reconstruir decisiones de una etapa productora ni derivar éxito global de
un estado terminal local.

### 6.5 Familias de contexto observadas

Sin cerrar todavía el mapa definitivo ni documentar módulos individuales, el
inventario justifica las siguientes familias semánticas:

- oferta pública y disponibilidad;
- selección e identidad de compra;
- congelamiento comercial y reserva;
- órdenes y evidencia interna de compra;
- coordinación financiera e integración de pago;
- reconciliación y finalización durable;
- cumplimiento y entrega;
- consulta del cliente y presentación pública;
- administración y capacidades de plataforma.

Estas familias son una descripción general. No crean agregados, entidades,
autoridades ni dependencias que no estén documentados en capítulos posteriores.

### 6.6 Reglas para incorporar o modificar contextos

- Debe existir una diferencia semántica o de invariantes respaldada, no solo una
  preferencia organizativa.
- Deben declararse propósito, vocabulario, datos gobernados, escritor y
  contratos.
- Deben identificarse dependencias permitidas y ciclos potenciales.
- Una extracción no debe romper contratos públicos ni reinterpretar datos
  durables existentes.
- Un vacío futuro del inventario no se convierte en contexto hasta contar con
  decisión y evidencia documental propias.

### 6.7 Documentos fuente y referencias cruzadas

- Principal: inventario, secciones 4, 5 y 6.
- Apoyo: `README.md` y `docs/transaction-flow.md` como evidencia de separación
  de responsabilidades.
- Referencias: vocabulario en 4; arquitectura general en 5; modelo en 8;
  contextos especializados en 9–18; relaciones en 21; integraciones en 24;
  evolución en 28.1.

Diagramas previstos: A-05 Mapa de bounded contexts; A-06 Dependencias
permitidas. Tablas previstas: T-11 Matriz de bounded contexts; T-12 Ownership
por contexto. No se dibujan ni completan en este microhito.

## 7. Identidad, Ownership y Seguridad

### 7.1 Objetivo

Responder cómo se representa a usuarios e invitados, cómo se comprueba
ownership y qué información nunca puede transformarse en autoridad pública.

### 7.2 Documentos fuente

- Principal: `docs/public-payment-session-backend-design.md`.
- Apoyo: `docs/customer-panel-v1-design.md`,
  `docs/public-checkout-durable-payment-pipeline-design.md`.

### 7.3 Entidades, autoridades y contratos relacionados

- Entidades: WordPress User, guest identity, Cart, Checkout, PaymentSession.
- Autoridades: Cart, Checkout, PaymentSession y Customer Purchase read model.
- Contratos: nonce, cookie, sesión opaca, IDs públicos y no enumeración.

### 7.4 Diagramas y tablas previstos

- Diagramas: A-07 Flujo de identidad y ownership.
- Tablas: T-13 Matriz de ownership; T-14 Datos públicos, privados y secretos.

### 7.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 3–6.
- Referencias: capítulos 10–14, 22, 23 y 24.

## 8. Modelo del Dominio

### 8.1 Objetivo

Responder qué elementos son entidades, autoridades, DTO, servicios,
infraestructura, integraciones externas o conceptos futuros.

### 8.2 Documentos fuente

- Principal: sección 6 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: diseños y pruebas primarias de cada dominio.

### 8.3 Entidades, autoridades y contratos relacionados

- Entidades: conjunto normalizado completo.
- Autoridades: conjunto durable completo.
- Contratos: identidad, relaciones e invariantes de cada concepto.

### 8.4 Diagramas y tablas previstos

- Diagramas: A-08 Mapa conceptual del dominio.
- Tablas: T-15 Catálogo de entidades; T-16 Clasificación de conceptos.

### 8.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 4 y 6.
- Referencias: capítulos 9–21.

## 9. Product, Catalog, Inventory y límite de Marketplace

### 9.1 Objetivo

Responder qué responsabilidades actuales pertenecen a Product, Catalog,
Inventory y Store, dejando reservados Marketplace, Offer y Availability.

### 9.2 Documentos fuente

- Principal: `app/Modules/Catalog/README.md`.
- Apoyo: suites `product-*`, `catalog-*`, `inventory-*`; sección 11 del
  inventario para vacíos futuros.

### 9.3 Entidades, autoridades y contratos relacionados

- Entidades: Store/Minimarket, Product, Category, Brand, Unit, Inventory.
- Autoridades: Product e Inventory; Offer solo como proyección actual.
- Contratos: catálogo público list/detail y contratos administrativos.

### 9.4 Diagramas y tablas previstos

- Diagramas: A-09 Lectura pública Product–Inventory–Store.
- Tablas: T-17 Responsabilidades Product/Catalog/Inventory; T-18 Fronteras
  reservadas Marketplace/Publication/Offer/Availability.

### 9.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 6–8.
- Referencias: capítulos 10, 11, 22, 27 y 28.

## 10. Cart

### 10.1 Objetivo

Responder cómo se identifica, modifica y lee el carrito antes de congelar una
compra, y cuáles son sus límites respecto de stock y reserva.

### 10.2 Documentos fuente

- Principal: `docs/cart-functional-design.md`.
- Apoyo: suites `cart-*`, `public-cart-test.php`,
  `public-add-to-cart-test.php`.

### 10.3 Entidades, autoridades y contratos relacionados

- Entidades: Cart, CartItem, Inventory y buyer identity.
- Autoridades: Cart; Inventory solo para validación autoritativa.
- Contratos: `/cart`, `/cart/items`, sesión y payload de agregado.

### 10.4 Diagramas y tablas previstos

- Diagramas: A-10 Ciclo de Cart y transición a Checkout.
- Tablas: T-19 Operaciones e invariantes de Cart.

### 10.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7 y 9.
- Referencias: capítulos 11, 22 y 23.

## 11. Checkout, Orders y Reservations

### 11.1 Objetivo

Responder cómo se valida y congela una compra, se agrupa por minimarket y se
materializan Orders, Reservations y fulfillment autorizado.

### 11.2 Documentos fuente

- Principal: `docs/public-checkout-functional-design.md`.
- Apoyo: `docs/checkout-functional-design.md`, `docs/transaction-flow.md`,
  `docs/durable-fulfillment-authority.md`.

### 11.3 Entidades, autoridades y contratos relacionados

- Entidades: Checkout, CheckoutOrder, Order, OrderItem, Reservation.
- Autoridades: Checkout, Order, Reservation.
- Contratos: `/checkout/validate`, `/checkout`, checkout owned y RB-CHK-001.

### 11.4 Diagramas y tablas previstos

- Diagramas: A-11 Pipeline Checkout–Orders–Reservations.
- Tablas: T-20 Snapshot de Checkout; T-21 Reglas de reserva y compensación.

### 11.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7, 9 y 10.
- Referencias: capítulos 12, 19, 20 y 21.

## 12. PaymentSession y creación remota Webpay

### 12.1 Objetivo

Responder cómo se crea o reutiliza un intento de pago, cómo se separan
transacciones locales y red, y cómo se conserva una creación ambigua.

### 12.2 Documentos fuente

- Principal: `docs/public-payment-session-backend-design.md`.
- Apoyo: `docs/public-checkout-durable-payment-pipeline-design.md`,
  `docs/webpay-integration-functional-design.md`.

### 12.3 Entidades, autoridades y contratos relacionados

- Entidades: Checkout, PaymentSession, gateway session result.
- Autoridades: Checkout y PaymentSession.
- Contratos: `/payments/session`, idempotency key, fingerprint y gateway create.

### 12.4 Diagramas y tablas previstos

- Diagramas: A-12 Inicio durable de PaymentSession; A-13 Frontera local–Webpay.
- Tablas: T-22 Idempotencia de PaymentSession; T-23 Resultados de create remoto.

### 12.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7 y 11.
- Referencias: capítulos 13, 18, 20, 22 y 24.

## 13. PaymentOriginContext, WebpayReturn y autoridad financiera

### 13.1 Objetivo

Responder cómo se enlazan intento, token y evidencia financiera sin confiar en
datos reconstruidos desde el navegador.

### 13.2 Documentos fuente

- Principal: `docs/payment-reconciliation-attempt-materialization.md`.
- Apoyo: `docs/webpay-return-and-commit-foundation.md`,
  `docs/payment-reconciliation-order-completion-design.md`.

### 13.3 Entidades, autoridades y contratos relacionados

- Entidades: PaymentOriginContext, WebpayReturn y fingerprint financiero.
- Autoridades: PaymentOriginContext y WebpayReturn.
- Contratos: token hash, origin key, return, commit/status y replay.

### 13.4 Diagramas y tablas previstos

- Diagramas: A-14 Retorno Webpay y persistencia financiera.
- Tablas: T-24 Identidades financieras; T-25 Validación y resultados de retorno.

### 13.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7 y 12.
- Referencias: capítulos 14, 18, 19, 20 y 24.

## 14. Payment Reconciliation

### 14.1 Objetivo

Responder cómo se concilia técnicamente evidencia financiera bajo lease y qué
significa cada resultado sin ejecutar efectos de negocio implícitos.

### 14.2 Documentos fuente

- Principal: `docs/payment-reconciliation-lease-implementation.md`.
- Apoyo: `docs/payment-reconciliation-order-completion-design.md`, suites
  `payment-reconciliation-*`.

### 14.3 Entidades, autoridades y contratos relacionados

- Entidades: PaymentReconciliation, reconciliation lease y processing result.
- Autoridades: WebpayReturn y PaymentReconciliation.
- Contratos: claim, renew, CAS final, fingerprint y handler registry.

### 14.4 Diagramas y tablas previstos

- Diagramas: A-15 Pipeline de Payment Reconciliation.
- Tablas: T-26 Estados y resultados de reconciliación; T-27 Lease y CAS.

### 14.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 13 y 18.
- Referencias: capítulos 15, 19, 20, 21 y 24.

## 15. Business Completion

### 15.1 Objetivo

Responder cómo una reconciliación interna completada materializa de forma
atómica Payment, vínculos, Orders y snapshot de negocio.

### 15.2 Documentos fuente

- Principal: `docs/business-completion-design.md`.
- Apoyo: `docs/payment-confirmation-functional-design.md`, pruebas
  `business-*` y `transactional-*`.

### 15.3 Entidades, autoridades y contratos relacionados

- Entidades: BusinessCompletion, BusinessCompletionOrder, Payment, PaymentOrder,
  Order y Reservation.
- Autoridades: Payment, Order y BusinessCompletion.
- Contratos: transaction boundary, lock order, idempotency y exact snapshot.

### 15.4 Diagramas y tablas previstos

- Diagramas: A-16 Transacción de Business Completion.
- Tablas: T-28 Escrituras atómicas y locks; T-29 Invariantes del snapshot.

### 15.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 11 y 14.
- Referencias: capítulos 16–21.

## 16. Delivery y Delivery Completion

### 16.1 Objetivo

Responder cómo se materializa Delivery desde negocio sellado y cómo se separa
esa etapa de asignación, tracking y operación logística.

### 16.2 Documentos fuente

- Principal: `docs/delivery-functional-design.md` para Delivery operativa.
- Apoyo: `docs/fulfillment-completion-processor-design.md` sección de
  precondición 28.7.4.6.6 y pruebas `delivery-completion-*`.

### 16.3 Entidades, autoridades y contratos relacionados

- Entidades: Delivery, DeliveryTracking y DeliveryCompletion.
- Autoridades: Delivery y DeliveryCompletion.
- Contratos: `/deliveries`, exact-set, `not_required` y tracking.

### 16.4 Diagramas y tablas previstos

- Diagramas: A-17 Materialización y operación de Delivery.
- Tablas: T-30 Responsabilidades Delivery/DeliveryCompletion; T-31 Operaciones
  logísticas y límites.

### 16.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 11 y 15.
- Referencias: capítulos 17–21, 23 y 24.

## 17. Fulfillment Completion

### 17.1 Objetivo

Responder cómo se consume la autoridad sellada y la precondición de Delivery
Completion para cerrar durablemente fulfillment sin reconstruir etapas.

### 17.2 Documentos fuente

- Principal: `docs/fulfillment-completion-processor-design.md`.
- Apoyo: `docs/durable-fulfillment-authority.md`, pruebas `fulfillment-*`.

### 17.3 Entidades, autoridades y contratos relacionados

- Entidades: FulfillmentCompletion y snapshots relacionados.
- Autoridades: BusinessCompletion, DeliveryCompletion y FulfillmentCompletion.
- Contratos: lease, exact verification, CAS y terminal replay.

### 17.4 Diagramas y tablas previstos

- Diagramas: A-18 Pipeline de Fulfillment Completion.
- Tablas: T-32 Precondiciones de fulfillment; T-33 Fallos y cierres permitidos.

### 17.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 15, 16 y 18.
- Referencias: capítulos 19–21 y 23.

## 18. Action Scheduler, Recovery e Idempotencia

### 18.1 Objetivo

Responder cómo se transporta trabajo, se recuperan etapas, se controlan leases
y se evita repetir efectos a través del pipeline.

### 18.2 Documentos fuente

- Principal: `docs/public-checkout-durable-payment-pipeline-design.md`.
- Apoyo: `docs/payment-reconciliation-lease-implementation.md`,
  `docs/fulfillment-completion-processor-design.md`.

### 18.3 Entidades, autoridades y contratos relacionados

- Entidades: unidades de trabajo, leases y acciones programadas.
- Autoridades: las autoridades durables; Action Scheduler no es autoridad.
- Contratos: hooks, deduplicación, backoff, sweeps, heartbeat y CAS.

### 18.4 Diagramas y tablas previstos

- Diagramas: A-19 Orquestación y recovery end-to-end.
- Tablas: T-34 Catálogo de acciones programadas; T-35 Garantías de idempotencia.

### 18.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 12–17.
- Referencias: capítulos 3, 19, 20, 25 y 27.

## 19. Autoridades Durables

### 19.1 Objetivo

Responder quién crea, modifica, cierra y lee cada autoridad, y distinguir
autoridad durable de read model o integración externa.

### 19.2 Documentos fuente

- Principal: sección 5 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: documentos primarios de capítulos 9–18.

### 19.3 Entidades, autoridades y contratos relacionados

- Entidades: entidades asociadas a cada autoridad.
- Autoridades: Product, Inventory, Cart, Reservation, Checkout, Order,
  PaymentSession, PaymentOriginContext, WebpayReturn, PaymentReconciliation,
  Payment, BusinessCompletion, Delivery, DeliveryCompletion,
  FulfillmentCompletion y Customer Purchase como read model.
- Contratos: ownership de escritura y lectura.

### 19.4 Diagramas y tablas previstos

- Diagramas: ninguno.
- Tablas: T-36 Matriz definitiva de autoridades; T-37 Autoridad versus
  proyección/servicio/infraestructura.

### 19.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 8–18.
- Referencias: capítulos 20, 21, 22, 26 y 27.

## 20. Máquinas de Estado

### 20.1 Objetivo

Responder cuáles son los estados y transiciones explícitamente documentados,
sus escritores y las ambigüedades que no deben inferirse.

### 20.2 Documentos fuente

- Principal: sección 7 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: models, repositories, diseños y pruebas citados allí.

### 20.3 Entidades, autoridades y contratos relacionados

- Entidades: Checkout, Reservation, PaymentSession, Payment,
  PaymentReconciliation, BusinessCompletion, Delivery, DeliveryCompletion,
  FulfillmentCompletion y Customer Purchase visible.
- Autoridades: las autoridades correspondientes; Customer Purchase es derivada.
- Contratos: transición, writer, terminalidad, CAS y precedencia visible.

### 20.4 Diagramas y tablas previstos

- Diagramas: S-01 a S-10, catálogo de máquinas de estado previsto.
- Tablas: T-38 Matriz global de estados; T-39 Escritores y terminalidad.

### 20.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 4 y 9–19.
- Referencias: capítulos 21, 23, 26 y 27.

## 21. Relaciones del Dominio

### 21.1 Objetivo

Responder cómo se enlazan las entidades y autoridades sin crear relaciones
alternativas ni reconstruir snapshots.

### 21.2 Documentos fuente

- Principal: `docs/public-checkout-durable-payment-pipeline-design.md`.
- Apoyo: `docs/transaction-flow.md`, `docs/customer-panel-v1-design.md`.

### 21.3 Entidades, autoridades y contratos relacionados

- Entidades: cadena completa Product/Inventory hasta Customer Purchase.
- Autoridades: todas las autoridades del pipeline transaccional.
- Contratos: relaciones 1:1, 1:N, unique keys, snapshots y referencias públicas.

### 21.4 Diagramas y tablas previstos

- Diagramas: A-20 Relaciones durables del dominio.
- Tablas: T-40 Cardinalidades y claves; T-41 Relaciones autoritativas y
  relaciones de lectura.

### 21.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 8–20.
- Referencias: capítulos 22, 23 y 27.

## 22. Contratos Públicos

### 22.1 Objetivo

Responder qué REST, DTO, shortcodes, rutas, parámetros, errores y garantías son
públicos o estables, separándolos de detalles internos.

### 22.2 Documentos fuente

- Principal: sección 8 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: Routes, Requests y suites contractuales correspondientes.

### 22.3 Entidades, autoridades y contratos relacionados

- Entidades: DTO y recursos expuestos por contexto.
- Autoridades: fuentes backend de cada respuesta.
- Contratos: REST `veciahorra/v1`, shortcodes, PublicRouteResolver,
  `?compra=`, ownership, errores, idempotencia y timeout.

### 22.4 Diagramas y tablas previstos

- Diagramas: ninguno.
- Tablas: T-42 Catálogo REST; T-43 DTO públicos; T-44 Shortcodes y rutas;
  T-45 Errores y políticas de respuesta.

### 22.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7, 9–21.
- Referencias: capítulos 23–25 y 29.

## 23. Arquitectura Frontend, Customer Panel y Navegación Pública

### 23.1 Objetivo

Responder cómo se montan superficies públicas, se consumen contratos, se
proyectan compras y se preserva navegación canónica aislada de WooCommerce.

### 23.2 Documentos fuente

- Principal Customer Panel: `docs/customer-panel-v1-design.md`.
- Principal frontend panel: `docs/customer-panel-frontend-design.md`.
- Principal navegación: `docs/public-navigation-final-certification.md`.
- Apoyo: `docs/public-search-isolation-design.md`.

### 23.3 Entidades, autoridades y contratos relacionados

- Entidades: Customer Purchase DTO/read model y componentes frontend.
- Autoridades: PublicRouteResolver y autoridades leídas por Customer Panel.
- Contratos: shortcodes, DOM, REST, navegación lista/detalle, búsqueda y menús.

### 23.4 Diagramas y tablas previstos

- Diagramas: F-01 Montaje frontend y consumo REST; F-02 Proyección Customer
  Purchase; F-03 Navegación pública canónica.
- Tablas: T-46 Superficies frontend; T-47 Estados visibles del cliente;
  T-48 Rutas públicas y comportamiento de autenticación.

### 23.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 7, 19–22.
- Referencias: capítulos 5, 24, 25, 27 y 29.

## 24. Integraciones

### 24.1 Objetivo

Responder qué responsabilidades pertenecen a WordPress, WooCommerce, Webpay,
Blocksy, Elementor y Action Scheduler, y qué límites no deben cruzar.

### 24.2 Documentos fuente

- Principal WordPress/frontend: `docs/public-navigation-final-certification.md`.
- Principal WooCommerce: `docs/payment-reconciliation-attempt-materialization.md`.
- Principal Webpay: `docs/public-checkout-durable-payment-pipeline-design.md`.
- Apoyo: `app/Modules/Payments/WooCommerce/README.md`.

### 24.3 Entidades, autoridades y contratos relacionados

- Entidades: adapters y contextos de integración.
- Autoridades: autoridades internas; sistemas externos nunca sustituyen
  snapshots internos sin contrato explícito.
- Contratos: WordPress APIs, Woo order adapter, gateway, theme/page integration.

### 24.4 Diagramas y tablas previstos

- Diagramas: I-01 Fronteras de integraciones externas.
- Tablas: T-49 Matriz de integraciones; T-50 Compatibilidad y aislamiento.

### 24.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 5, 7, 12–14, 18, 22 y 23.
- Referencias: capítulos 26–29.

## 25. Testing, Verificación, Operación y Release

### 25.1 Objetivo

Responder cómo se verifica la arquitectura, qué contratos ejecutables existen
y qué brechas operativas o de automatización permanecen.

### 25.2 Documentos fuente

- Principal: sección 3.3 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: `docs/definition-of-done.md`, `docs/release-strategy.md`,
  `docs/beta-readiness.md`.

### 25.3 Entidades, autoridades y contratos relacionados

- Entidades: fixtures, workers y ambientes, sin autoridad de dominio.
- Autoridades: todas las ejercitadas por suites contractuales.
- Contratos: unit/integration/concurrency/E2E/sandbox/manual, CI y release gates.

### 25.4 Diagramas y tablas previstos

- Diagramas: V-01 Pirámide y flujo de verificación.
- Tablas: T-51 Matriz dominio–pruebas; T-52 Criterios de release y evidencia;
  T-53 Deudas de verificación.

### 25.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 3, 6, 18 y 22–24.
- Referencias: capítulos 26–29.

## 26. Decisiones Arquitectónicas (ADR)

### 26.1 Objetivo

Responder qué decisiones consolidadas requerirán ADR, cuál es su fuente y qué
alternativas históricas deben registrarse sin reabrirlas aquí.

### 26.2 Documentos fuente

- Principal: secciones 9 y 10 de
  `docs/veciahorra-architecture-inventory.md`.
- Apoyo: documentos sucesores indicados por cada contradicción.

### 26.3 Entidades, autoridades y contratos relacionados

- Entidades: las afectadas por cada decisión.
- Autoridades: las afectadas por ownership, estado o integración.
- Contratos: formato ADR, estado, contexto, decisión, consecuencias y sucesión.

### 26.4 Diagramas y tablas previstos

- Diagramas: ninguno.
- Tablas: T-54 Registro maestro de ADR; T-55 Decisiones históricas y sucesores.

### 26.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 3–25.
- Referencias: capítulos 27–29.

## 27. Riesgos Arquitectónicos y Vacíos

### 27.1 Objetivo

Responder qué contradicciones, riesgos y conocimientos incompletos permanecen,
sin diseñar soluciones o módulos futuros.

### 27.2 Documentos fuente

- Principal: secciones 10 y 11 de
  `docs/veciahorra-architecture-inventory.md`.
- Apoyo: matrices de riesgos de los diseños primarios.

### 27.3 Entidades, autoridades y contratos relacionados

- Entidades: conceptos afectados y conceptos futuros aún no modelados.
- Autoridades: autoridades con certeza parcial o semántica pendiente.
- Contratos: clasificación, impacto, evidencia, owner futuro y condición de
  cierre; sin solución técnica en este capítulo.

### 27.4 Diagramas y tablas previstos

- Diagramas: ninguno.
- Tablas: T-56 Registro de riesgos; T-57 Vacíos por dominio; T-58
  Contradicciones pendientes de resolución.

### 27.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 4, 19, 20, 25 y 26.
- Referencias: capítulos 28 y 29.

## 28. Evolución Futura

### 28.1 Objetivo

Establecer cómo puede evolucionar la Arquitectura v1.0 sin degradar sus
principios, contratos ni datos durables. Este apartado regula el cambio
arquitectónico; no diseña capacidades futuras.

#### 28.1.1 Compatibilidad

Todo cambio debe clasificar su efecto sobre contratos públicos, datos
persistidos, estados en curso, integraciones y consumidores. Se considera
compatible cuando conserva la semántica observable vigente o incorpora una
transición que permite a productores y consumidores coexistir durante la
actualización.

Las migraciones deben ser versionadas, reejecutables y verificables. Una mejora
interna no debe exigir cambios coordinados a consumidores si el contrato puede
preservarse.

#### 28.1.2 Incorporación de nuevos bounded contexts

Un contexto nuevo requiere evidencia de un límite semántico real y debe
declarar propósito, vocabulario, invariantes, datos gobernados, escritor,
contratos y dependencias. No se crea un contexto solo para reflejar carpetas,
equipos o capacidades mencionadas como futuras.

Antes de incorporarlo debe demostrarse que no duplica una autoridad existente,
no introduce ciclos indebidos y dispone de una estrategia compatible para datos
y consumidores previos.

#### 28.1.3 Modificación de contratos

Un contrato puede ampliarse de manera compatible cuando preserva campos,
significados, errores y comportamientos consumidos. Cambiar semántica, retirar
una forma vigente o endurecer una precondición observable exige versionado o una
transición documentada.

La implementación interna puede sustituirse sin versionar el contrato siempre
que pruebas contractuales demuestren equivalencia observable.

#### 28.1.4 Creación de nuevas autoridades

Una nueva autoridad solo se justifica cuando existe una decisión durable que no
puede gobernarse correctamente desde una autoridad vigente. Su propuesta debe
especificar identidad, alcance, escritor único, invariantes, estados,
terminalidad, ownership, idempotencia, recuperación, observabilidad,
migraciones y consumidores.

No se crea una autoridad para duplicar un read model, cachear por conveniencia o
resolver una ambigüedad sin definir primero su semántica.

#### 28.1.5 Cambios incompatibles

Un cambio incompatible debe ser explícito, excepcional y trazable. Requiere:

1. motivación que descarte una alternativa compatible razonable;
2. identificación de contratos, autoridades, datos y consumidores afectados;
3. estrategia de migración, coexistencia o retiro;
4. pruebas de actualización, recuperación y rollback;
5. comunicación mediante versionado y registro de cambio;
6. actualización coordinada de esta especificación y sus decisiones aplicables.

No se permite una ruptura silenciosa ni usar un cambio de implementación para
reinterpretar estados o datos ya persistidos.

#### 28.1.6 Versionado arquitectónico

La versión de esta especificación identifica el contrato arquitectónico del
sistema y no reemplaza la versión del plugin. Correcciones editoriales sin
cambio normativo incrementan la revisión documental. Una ampliación compatible
de principios o contextos incrementa la versión menor de la arquitectura. Una
ruptura deliberada de fundamentos, autoridades o contratos requiere una versión
mayor y el proceso de cambio incompatible.

A partir de la versión estable 1.0, el software aplica versionado semántico
según `docs/release-strategy.md`. Mientras este documento permanezca Draft, toda
modificación normativa debe quedar revisable y no puede presentarse como
garantía estable sin aprobación.

#### 28.1.7 Criterios de aceptación de una evolución

Una evolución arquitectónica está lista únicamente cuando:

- respeta los principios e invariantes del capítulo 3 o declara formalmente la
  versión mayor que los reemplaza;
- emplea el vocabulario oficial del capítulo 4;
- conserva límites y ownership o documenta su migración;
- actualiza contratos y referencias cruzadas afectadas;
- aporta pruebas proporcionales al riesgo;
- define despliegue, recuperación y rollback;
- distingue implementación vigente, antecedente histórico y capacidad futura.

**Fuentes:** `docs/definition-of-done.md`, `docs/release-strategy.md`,
`README.md` y `docs/veciahorra-architecture-inventory.md`, secciones 1, 10 y
11. Referencias cruzadas: capítulos 1, 3, 4, 6, 19, 22, 25 y 26.

### 28.2 Documentos fuente

- Principal: sección 11 de `docs/veciahorra-architecture-inventory.md`.
- Apoyo: `docs/project-backlog.md`, `docs/roadmap-v1.0.md`, diseño híbrido de
  aislamiento solo como contingencia.

### 28.3 Entidades, autoridades y contratos relacionados

- Entidades: ninguna nueva; solo espacios Publication, Offer, Ranking,
  Availability, Promotion, Notifications y Marketplace.
- Autoridades: pendientes/no definidas.
- Contratos: criterios de reevaluación y compatibilidad futura, por completar.

### 28.4 Diagramas y tablas previstos

- Diagramas: E-01 Horizonte de evolución y espacios reservados.
- Tablas: T-59 Capacidades futuras y disparadores; T-60 Exclusiones v1.0.

### 28.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 2, 9, 24, 26 y 27.
- Referencias: capítulo 29.

## 29. Referencias

### 29.1 Objetivo

Responder qué fuente primaria respalda cada capítulo, qué fuentes son
históricas y qué contratos ejecutables verifican la implementación.

### 29.2 Documentos fuente

- Principal: secciones 3 y 12 de
  `docs/veciahorra-architecture-inventory.md`.
- Apoyo: historial Git selectivo y suites contractuales.

### 29.3 Entidades, autoridades y contratos relacionados

- Entidades: ninguna adicional.
- Autoridades: índice de fuentes por autoridad.
- Contratos: referencias documentales, ejecutables y commits de sucesión.

### 29.4 Diagramas y tablas previstos

- Diagramas: ninguno.
- Tablas: T-61 Bibliografía normativa; T-62 Fuentes históricas/reemplazadas;
  T-63 Índice de contratos ejecutables.

### 29.5 Dependencias y referencias cruzadas

- Dependencias: capítulos 1–28.
- Referencias: todos los capítulos.

---

## Catálogo definitivo de diagramas previstos

| ID | Diagrama previsto | Fuente documental principal |
|---|---|---|
| A-01 | Contexto general del sistema | `README.md` |
| A-02 | Recorrido funcional v1.0 | `docs/transaction-flow.md` |
| A-03 | Mapa general de módulos y capas | `README.md` |
| A-04 | Contexto WordPress | inventario, dominio WordPress Integration |
| A-05 | Mapa de bounded contexts | inventario, sección 4 |
| A-06 | Dependencias permitidas entre contextos | `docs/transaction-flow.md` |
| A-07 | Flujo de identidad y ownership | `docs/public-payment-session-backend-design.md` |
| A-08 | Mapa conceptual del dominio | inventario, sección 6 |
| A-09 | Lectura pública Product–Inventory–Store | `app/Modules/Catalog/README.md` |
| A-10 | Ciclo de Cart y transición a Checkout | `docs/cart-functional-design.md` |
| A-11 | Pipeline Checkout–Orders–Reservations | `docs/public-checkout-functional-design.md` |
| A-12 | Inicio durable de PaymentSession | `docs/public-payment-session-backend-design.md` |
| A-13 | Frontera transacción local–Webpay | `docs/public-checkout-durable-payment-pipeline-design.md` |
| A-14 | Retorno Webpay y persistencia financiera | `docs/webpay-return-and-commit-foundation.md` |
| A-15 | Pipeline de Payment Reconciliation | `docs/payment-reconciliation-lease-implementation.md` |
| A-16 | Transacción de Business Completion | `docs/business-completion-design.md` |
| A-17 | Materialización y operación de Delivery | `docs/delivery-functional-design.md` |
| A-18 | Pipeline de Fulfillment Completion | `docs/fulfillment-completion-processor-design.md` |
| A-19 | Orquestación y recovery end-to-end | `docs/public-checkout-durable-payment-pipeline-design.md` |
| A-20 | Relaciones durables del dominio | `docs/public-checkout-durable-payment-pipeline-design.md` |
| F-01 | Montaje frontend y consumo REST | `docs/customer-frontend-functional-design.md` |
| F-02 | Proyección Customer Purchase | `docs/customer-panel-v1-design.md` |
| F-03 | Navegación pública canónica | `docs/public-navigation-final-certification.md` |
| I-01 | Fronteras de integraciones externas | inventario, dominios de integración |
| V-01 | Pirámide y flujo de verificación | inventario, sección 3.3 |
| E-01 | Horizonte de evolución y espacios reservados | inventario, sección 11 |

### Máquinas de estado previstas

| ID | Diagrama previsto | Fuente documental principal |
|---|---|---|
| S-01 | Estados de Checkout | `docs/public-payment-session-backend-design.md` |
| S-02 | Estados de Reservation | `docs/transaction-flow.md` |
| S-03 | Estados de PaymentSession | `docs/public-checkout-durable-payment-pipeline-design.md` |
| S-04 | Estados de Payment | `docs/payment-confirmation-functional-design.md` |
| S-05 | Estados de PaymentReconciliation | `docs/payment-reconciliation-lease-implementation.md` |
| S-06 | Estados de BusinessCompletion | `docs/business-completion-design.md` |
| S-07 | Estados de Delivery | `docs/delivery-functional-design.md` |
| S-08 | Estados de DeliveryCompletion | inventario, sección 7 |
| S-09 | Estados de FulfillmentCompletion | `docs/fulfillment-completion-processor-design.md` |
| S-10 | Estados visibles de Customer Purchase | `docs/customer-panel-v1-design.md` |

## Catálogo definitivo de tablas previstas

| ID | Tabla prevista | Columnas previstas |
|---|---|---|
| T-01 | Alcance y madurez documental | tema, implementado, documentado, histórico, futuro, fuente |
| T-02 | Convenciones normativas | término, uso, prohibición, referencia |
| T-03 | Actores y objetivos | actor, objetivo, superficie, límite |
| T-04 | Alcance v1.0 | capacidad, incluida, excluida, fuente |
| T-05 | Principios e invariantes | ID, principio, alcance, fuente |
| T-06 | Prohibiciones por capa | capa, no debe, autoridad correcta, fuente |
| T-07 | Glosario oficial | término, definición, tipo, fuente |
| T-08 | Términos históricos | término anterior, término vigente, sucesor, nota |
| T-09 | Capas y responsabilidades | capa, responsabilidad, dependencias, prohibiciones |
| T-10 | Dependencias de plataforma | componente, uso, contrato, optionalidad |
| T-11 | Bounded contexts | contexto, responsabilidad, entidades, autoridad, API |
| T-12 | Ownership por contexto | contexto, crea, modifica, lee, dependencia |
| T-13 | Ownership de recursos | recurso, autenticado, invitado, comprobación, error |
| T-14 | Clasificación de datos | dato, público, privado, secreto, retención |
| T-15 | Catálogo de entidades | nombre, tipo, contexto, identidad, fuente |
| T-16 | Clasificación de conceptos | concepto, entidad, DTO, servicio, infraestructura, futuro |
| T-17 | Product/Catalog/Inventory | responsabilidad, escritura, lectura, límite, fuente |
| T-18 | Fronteras futuras | espacio, estado actual, vacío, disparador |
| T-19 | Operaciones de Cart | operación, identidad, validación, efecto, contrato |
| T-20 | Snapshot de Checkout | campo conceptual, fuente, inmutabilidad, consumidor |
| T-21 | Reservas y compensación | evento, transición, stock, escritor, idempotencia |
| T-22 | Idempotencia PaymentSession | clave, fingerprint, replay, conflicto, fuente |
| T-23 | Create remoto | resultado, estado local, retry, recuperación, visible |
| T-24 | Identidades financieras | identidad, origen, unicidad, secreto, consumidor |
| T-25 | Retorno Webpay | evidencia, validación, resultado, writer, replay |
| T-26 | Reconciliación | estado, significado, terminal, writer, efecto permitido |
| T-27 | Lease y CAS | operación, precondición, reloj, resultado, fallo |
| T-28 | Business Completion | orden, fila, lock, escritura, invariante |
| T-29 | Snapshot de negocio | componente, origen, sellado, lector, prohibición |
| T-30 | Delivery/Completion | responsabilidad, autoridad, estado, writer, límite |
| T-31 | Operación logística | operación, transición, actor, endpoint, efecto |
| T-32 | Precondiciones fulfillment | método, business, delivery completion, resultado esperado |
| T-33 | Cierres fulfillment | caso, estado, retry, manual, fuente |
| T-34 | Acciones programadas | hook, grupo, payload, productor, consumidor |
| T-35 | Garantías idempotencia | etapa, delivery, barrera, replay, ambigüedad |
| T-36 | Matriz de autoridades | autoridad, crea, modifica, cierra, lee, fuente |
| T-37 | Clasificación de autoridad | concepto, durable, read model, servicio, integración |
| T-38 | Estados globales | autoridad, inicial, intermedio, terminal, fuente |
| T-39 | Escritores de estados | autoridad, transición, escritor, CAS, ambigüedad |
| T-40 | Cardinalidades | origen, relación, destino, cardinalidad, restricción |
| T-41 | Relaciones de lectura | lector, autoridad fuente, propósito, prohibición |
| T-42 | REST | método, ruta, audiencia, request, response, ownership |
| T-43 | DTO públicos | DTO, campos, fuente, consumidor, estabilidad |
| T-44 | Shortcodes y rutas | shortcode/ruta, página, parámetro, autoridad, fallback |
| T-45 | Errores públicos | código, HTTP, exposición, retry, fuente |
| T-46 | Superficies frontend | superficie, mount, REST, estados UI, ruta |
| T-47 | Estados visibles | código, etiqueta, evidencia, precedencia, terminalidad |
| T-48 | Navegación pública | elemento, ruta, visitante, autenticado, fuente |
| T-49 | Integraciones | sistema, adapter, entrada, salida, límite |
| T-50 | Compatibilidad/aislamiento | integración, permitido, prohibido, prueba, riesgo |
| T-51 | Dominio–pruebas | dominio, suite, nivel, concurrencia, estado |
| T-52 | Release y evidencia | etapa, entrada, evidencia, salida, rollback |
| T-53 | Deudas de verificación | deuda, impacto, evidencia, owner, condición de cierre |
| T-54 | Registro ADR | ID, título, estado, contexto, decisión, fuente |
| T-55 | Decisiones históricas | tema, anterior, vigente, sucesor, compatibilidad |
| T-56 | Riesgos | ID, riesgo, impacto, evidencia, mitigación pendiente |
| T-57 | Vacíos | dominio, vacío, estado, espacio reservado, prioridad |
| T-58 | Contradicciones | tema, fuente A, fuente B, vigente, resolución pendiente |
| T-59 | Capacidades futuras | capacidad, vacío, disparador, dependencia, exclusión v1 |
| T-60 | Exclusiones v1.0 | capacidad, motivo, autoridad pendiente, revisión |
| T-61 | Bibliografía normativa | capítulo, fuente principal, versión/commit, vigencia |
| T-62 | Fuentes históricas | documento, tema, sucesor, vigencia, uso permitido |
| T-63 | Contratos ejecutables | dominio, archivo/suite, contrato, nivel, observación |
