# Configuración de GitHub para VeciAhorra Roadmap v1.0

## Alcance y fuentes

Este procedimiento prepara labels, milestones, issues y un Project sin cambiar
el producto. Las fuentes canónicas son:

- [Roadmap 1.0](roadmap-v1.0.md) para alcance y avance.
- [Backlog](project-backlog.md) para prioridades y trabajo pendiente.
- [Milestones](github-milestones.md) para agrupación y criterios de cierre.
- [Issues semilla](github-issues-seed.md) para unidades ejecutables.
- [Definition of Done](definition-of-done.md) para criterios comunes.
- [Estrategia de releases](release-strategy.md) para promociones.

No ejecutar comandos de creación hasta tener confirmación explícita, acceso al
repositorio y responsables definidos. La instalación local actual no incluye
GitHub CLI (`gh`).

## Orden de implementación

1. Confirmar propietario, repositorio, responsables y permisos.
2. Consultar y comparar obligatoriamente las labels existentes con
   [github-labels.md](github-labels.md); crear sólo las ausentes y someter toda
   actualización de metadata a revisión explícita.
3. Consultar obligatoriamente todos los milestones existentes antes de crear los
   ausentes, porque su creación no es idempotente.
4. Crear el Project **VeciAhorra Roadmap v1.0**.
5. Configurar campos, opciones, vistas y automatizaciones.
6. Crear los issues semilla y asignar milestone y labels.
7. Incorporar los issues al Project y completar sus campos.
8. Auditar conteos, vínculos y estados antes de anunciar el tablero.

## Project

### Identidad

- **Nombre:** VeciAhorra Roadmap v1.0
- **Descripción:** Plan operativo para completar Delivery, experiencia,
  hardening y publicación estable de VeciAhorra 1.0.
- **Visibilidad inicial:** privada durante la carga; cambiarla sólo después de
  revisar que no haya información sensible.
- **README del Project:** enlazar este documento, el roadmap y la Definition of
  Done.

### Campos

| Campo | Tipo | Opciones o formato | Uso |
|---|---|---|---|
| `Status` | Selección única | Backlog, Ready, In Progress, Review, Testing, Done | Flujo principal y columnas Kanban |
| `Priority` | Selección única | High, Medium, Low | Equivale a P0, P1 y P2 |
| `Module` | Selección única | Delivery, Notifications, Dashboard, Customer Panel, Payments, Hardening, Release | Área principal de ejecución |
| `Version` | Texto | `1.0.0`, `1.0.0-alpha.1`, `1.0.0-beta.1`, `1.0.0-rc.1` | Objetivo de publicación |
| `Estimate` | Número | Puntos enteros 1, 2, 3, 5 u 8 | Tamaño relativo, no horas |
| `Start date` | Fecha | `YYYY-MM-DD` | Inicio planificado para ubicar el item en Roadmap |
| `Target date` | Fecha | `YYYY-MM-DD` | Fin planificado para ubicar el item en Roadmap |

GitHub Projects incluye un campo `Status`; editar sus opciones en lugar de crear
otro campo con el mismo nombre. El milestone sigue viviendo en el issue y no se
duplica como campo personalizado. `Start date` y `Target date` son campos de
soporte necesarios para que la vista Roadmap represente los items en el tiempo;
las fechas de milestones funcionan como límites de planificación.

### Vistas

| Vista | Layout | Configuración |
|---|---|---|
| `Kanban` | Board | Agrupar por Status; ordenar por Priority y luego manualmente; ocultar Done por defecto si crece demasiado |
| `Table` | Table | Mostrar Status, Priority, Module, Milestone, Version, Estimate y assignees; agrupar por Module |
| `Roadmap` | Roadmap | Usar Start date y Target date; agrupar por Module; mostrar sólo milestones abiertos hacia 1.0 |
| `Completed` | Table | Filtro `status:Done`; ordenar por actualización descendente y mostrar milestone y Version |

### Flujo de estados

```text
Backlog → Ready → In Progress → Review → Testing → Done
             ↑          │          │          │
             └──────────┴──────────┴──────────┘
                    vuelve si requiere cambios
```

- **Backlog:** registrado, todavía sin refinamiento completo.
- **Ready:** alcance, aceptación y dependencias claros; `status: ready`.
- **In Progress:** tiene responsable y trabajo activo.
- **Review:** pull request o entregable listo; `status: review`.
- **Testing:** revisión aprobada y validación en curso.
- **Done:** aceptación y DoD verificadas; `status: verified` antes del cierre.
- Un bloqueo se registra con `status: blocked`, comentario explicativo y vínculo
  a la dependencia; no requiere una columna adicional.

## Implementación manual

### Labels y milestones

1. Abrir **Settings → Labels**, comparar nombres, descripciones y colores con
   [github-labels.md](github-labels.md), y crear únicamente las labels ausentes.
   Cualquier actualización requiere revisión previa; no sobrescribir metadata.
2. En **Issues → Milestones**, listar hitos abiertos y cerrados, compararlos con
   [github-milestones.md](github-milestones.md) y crear únicamente los ausentes.
   Esta consulta es obligatoria porque crear milestones no es idempotente.
3. Crear los hitos sugeridos cerrados sólo si se desea representar el historial;
   cerrarlos después de comprobar el criterio indicado.

### Project e issues

1. Abrir **Projects → New project** y crear una tabla vacía.
2. Renombrar el Project y configurar los cinco campos de este documento.
3. Editar `Status` con las seis opciones y crear las cuatro vistas.
4. Crear cada issue desde [github-issues-seed.md](github-issues-seed.md), sin
   copiar el identificador editorial (`DLV-01`, por ejemplo) al título salvo que
   el equipo quiera mantenerlo.
5. Asignar milestone, labels y campos; dejar inicialmente `Status = Backlog`.
6. Mover a Ready sólo después del refinamiento y asignación de Estimate.

## Preparación con GitHub CLI

Los siguientes ejemplos son para PowerShell. Requieren instalar GitHub CLI,
autenticarse con `gh auth login` y conceder el scope `project` cuando corresponda.
Son comandos de referencia: **no ejecutarlos sin confirmación explícita**.

```powershell
$Owner = 'niconookie'
$Repo = 'VeciAhorra'

gh auth status
gh auth refresh -s project
```

### Labels

Primero, listar y comparar obligatoriamente las labels existentes:

```powershell
gh label list --repo "$Owner/$Repo" `
  --limit 200 `
  --json name,description,color
```

Sólo si `backend` no existe, crearla con:

```powershell
gh label create 'backend' `
  --repo "$Owner/$Repo" `
  --description 'Lógica PHP, dominio, servicios o administración del servidor' `
  --color '0E8A16'
```

El procedimiento no usa `--force`. Si una label existente difiere del catálogo,
documentar la diferencia y obtener revisión explícita antes de ejecutar cualquier
actualización; nunca sobrescribir nombre, descripción o color automáticamente.

### Milestones

GitHub CLI no ofrece un comando de alto nivel para crear milestones. Antes de
cualquier `POST`, es obligatorio consultar abiertos y cerrados, ya que la
creación no es idempotente:

```powershell
gh api "repos/$Owner/$Repo/milestones?state=all" `
  --paginate `
  --jq '.[] | [.number, .title, .state] | @tsv'
```

Sólo si `Delivery` no aparece en el resultado, crearlo:

```powershell
gh api --method POST "repos/$Owner/$Repo/milestones" `
  -f title='Delivery' `
  -f state='open' `
  -f description='Implementar entrega auditable desde orden pagada hasta cierre'
```

### Project y campos

```powershell
gh project create --owner $Owner --title 'VeciAhorra Roadmap v1.0'
gh project list --owner $Owner

# Reemplazar <NUMBER> por el número devuelto para el Project.
gh project field-create <NUMBER> --owner $Owner `
  --name 'Priority' --data-type 'SINGLE_SELECT' `
  --single-select-options 'High,Medium,Low'

gh project field-create <NUMBER> --owner $Owner `
  --name 'Module' --data-type 'SINGLE_SELECT' `
  --single-select-options 'Delivery,Notifications,Dashboard,Customer Panel,Payments,Hardening,Release'

gh project field-create <NUMBER> --owner $Owner `
  --name 'Version' --data-type 'TEXT'

gh project field-create <NUMBER> --owner $Owner `
  --name 'Estimate' --data-type 'NUMBER'

gh project field-create <NUMBER> --owner $Owner `
  --name 'Start date' --data-type 'DATE'

gh project field-create <NUMBER> --owner $Owner `
  --name 'Target date' --data-type 'DATE'
```

Editar las opciones del campo Status y crear las vistas desde la interfaz web;
esto reduce la dependencia de operaciones GraphQL sensibles a IDs internos.

### Issues

Guardar temporalmente el cuerpo de un issue en un archivo revisado y ejecutar:

```powershell
gh issue create `
  --repo "$Owner/$Repo" `
  --title 'Definir dominio y estados de Delivery' `
  --body-file '<archivo-temporal.md>' `
  --milestone 'Delivery' `
  --label 'backend' `
  --label 'documentation' `
  --label 'module: delivery' `
  --label 'priority: high'
```

Después se puede incorporar un issue existente al Project:

```powershell
gh project item-add <NUMBER> --owner $Owner --url '<URL-DEL-ISSUE>'
```

## Auditoría posterior a la configuración

- Existen todas las labels del catálogo, sin sinónimos accidentales.
- Cada milestone tiene descripción, estado y criterio de cierre coherentes.
- Existen exactamente los issues aprobados; ninguno contiene secretos.
- Cada issue tiene una prioridad, módulo principal, milestone y aceptación.
- Todos los issues están en el Project y comienzan en Backlog o Ready.
- Las cuatro vistas muestran el mismo universo con filtros correctos.
- El número de items por milestone coincide con el seed aprobado.
- No se cerraron milestones históricos sin evidencia suficiente.

Referencias oficiales: [GitHub CLI](https://cli.github.com/manual/),
[planificación con Projects](https://docs.github.com/issues/planning-and-tracking-with-projects),
[milestones](https://docs.github.com/issues/using-labels-and-milestones-to-track-work/about-milestones)
y [REST API de milestones](https://docs.github.com/rest/issues/milestones).
