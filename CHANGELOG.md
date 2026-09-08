# Changelog

Todos los cambios relevantes de VeciAhorra se documentarán en este archivo.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto seguirá [Versionado Semántico](https://semver.org/lang/es/)
desde la versión 1.0.0.

## [0.3.23] - 2026-09-08

- Carrito agregado con identidad, modalidad, zona, versión y bloqueo compartido con Checkout; adopción legacy transaccional y consumo atómico.
- Retiro cercano para carritos de despacho bajo el mínimo configurado: coincidencia completa en un minimarket, Haversine en servidor y aceptación expresa con revalidación e idempotencia.
- Configuración de clave de navegador de Google Maps y coordenadas de minimarkets; ubicación manual disponible sin clave y sin historial de puntos de clientes.
- Schema 0.40.0: carritos agregados, vínculos de origen, coordenadas y propuestas expirables.
- Cobertura aislada MariaDB/REST/Chrome y 16 mutaciones de carrito más 16 de retiro cercano.

## [Unreleased]

### Added

- Documentación de gobierno, contribución, seguridad y planificación hacia 1.0.
- Plantillas de issues y pull requests para estandarizar la colaboración.
- Fundación modular para Stores, Products, ProductCatalogs e Inventory.
- Flujo transaccional de Cart, Checkout, Reservations, Orders y Payments.
- Gateway de pago simulado y confirmación transaccional idempotente.
- Fundación de sólo lectura para Customer Panel.
- Pruebas manuales para los principales contratos funcionales y transaccionales.

### Changed

- README principal ampliado con arquitectura, instalación y flujo de desarrollo.
