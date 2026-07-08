# Estrategia de releases

## Principios

VeciAhorra usa versionado semántico a partir de 1.0. Antes de esa versión, los
cambios pueden ajustar contratos todavía no estables y deben registrarse en el
CHANGELOG. Cada promoción reutiliza el mismo código fuente validado; no incorpora
funcionalidades fuera del proceso normal de revisión.

## Canales

### Alpha

Validación interna del MVP integrado. Puede contener funcionalidades incompletas
y datos reiniciables, pero debe instalarse de forma reproducible.

**Entrada:** alcance alpha congelado, paquete construible y flujos P0 disponibles.

**Salida:** instalación y smoke tests aprobados, recorridos críticos validados,
defectos bloqueantes cerrados y riesgos conocidos clasificados.

Versión sugerida: `1.0.0-alpha.1`, incrementando el número por iteración.

### Beta

Validación controlada con usuarios y comercios piloto. La funcionalidad del MVP
está completa; se priorizan usabilidad, compatibilidad y comportamiento real.

**Entrada:** criterios de salida alpha cumplidos, staging representativo,
monitoreo y soporte del piloto activos.

**Salida:** sin defectos críticos o altos no aceptados, métricas operativas dentro
de umbrales acordados y respaldo, restauración y rollback ensayados.

Versión sugerida: `1.0.0-beta.1`.

### Release candidate

Candidato exacto a producción. Se aplica congelamiento funcional y sólo se
admiten correcciones aprobadas que protejan la salida estable.

**Entrada:** beta aprobada, regresión completa, documentación y migraciones
cerradas, y auditoría de seguridad sin hallazgos críticos.

**Salida:** matriz de compatibilidad aprobada, ensayo de actualización exitoso,
notas finales completas y decisión go/no-go favorable.

Versión sugerida: `1.0.0-rc.1`.

### Stable

Versión apta para operación soportada. El artefacto debe corresponder al release
candidate aprobado y ser reproducible desde su etiqueta.

**Entrada:** criterios del RC cumplidos, responsables de despliegue, soporte y
rollback confirmados.

**Salida:** smoke tests pospublicación aprobados, monitoreo saludable y cierre
formal del lanzamiento. La versión inicial es `1.0.0`.

## Flujo de promoción

1. Integrar cambios revisados en la rama principal.
2. Construir un artefacto sin dependencias de desarrollo.
3. Ejecutar validaciones del canal y registrar evidencia.
4. Actualizar [CHANGELOG.md](../CHANGELOG.md) y notas de release.
5. Obtener aprobación go/no-go del canal.
6. Crear una etiqueta firmada o protegida y publicar el mismo artefacto validado.
7. Ejecutar smoke tests y observar las métricas acordadas.

## Correcciones y rollback

Un defecto de una prerelease genera una nueva iteración del mismo canal. En
stable, una corrección compatible incrementa PATCH; una funcionalidad compatible,
MINOR; y un cambio incompatible posterior a 1.0, MAJOR. Ante pérdida de datos,
fallos generalizados de compra o una vulnerabilidad crítica, se detiene la
promoción, se aplica rollback y se activa el proceso de seguridad o incidente.

Los criterios de calidad comunes están en
[definition-of-done.md](definition-of-done.md).
