---
tipo: referencia
esqueleto: borrador
audiencia: administracion
apps: [buscador-documental]
resumen: "Requisitos, capacidad estimada, puesta en marcha, alcance, perfil de lectura por significado y respaldo del Buscador Documental."
---
# App: Buscador Documental (`buscador`)

```{note}
Borrador. El identificador `buscador` es provisional.
```

## Qué hace
Lee los documentos de las carpetas incluidas, reconoce el texto de escaneos y fotografías y permite buscarlos por palabras o por significado, respetando los permisos de Nextcloud en cada consulta.

## Requisitos
Nextcloud 34, PostgreSQL con la extensión pgvector (versión 0.8 o posterior) y un contenedor de lectura (worker) con Tesseract. El perfil de significado se recomienda según el equipo (procesador, memoria, GPU opcional) y usted puede cambiarlo.

## Capacidad y tiempos (estimaciones)
```{note}
Cifras de la etapa de diseño, obtenidas con documentos sintéticos en un servidor compartido de 8 núcleos y 31 GB de memoria, sin GPU. Se confirmarán con datos reales antes de la primera versión.
```
Para un millón de páginas:

- **Espacio en disco:** unos 15 GB en la base de datos (alrededor de 5 kB por fragmento de texto, con tres fragmentos por página). Durante la primera carga el registro de escritura puede ocupar otros 14 GB de forma transitoria. Al cambiar de perfil de lectura por significado, reserve además el doble del espacio de los vectores.
- **Memoria:** los índices y los vectores que se consultan a diario suman unos 7 GB; conviene que quepan en la memoria del servidor.
- **Primera lectura:** unas 71 horas con los 8 núcleos dedicados, es decir, unas 9 noches de 8 horas si se limita a una ventana nocturna. La búsqueda por palabras queda disponible antes, tras unas 45 horas, y la búsqueda por significado se completa después.
- **Reconocimiento de texto:** entre 2 y 3 segundos por página en una página escaneada limpia; las fotografías y los escaneos torcidos tardan más.
- **Perfil de lectura por significado:** en un servidor sin GPU se recomienda el perfil pequeño; el perfil grande no es apto para ese equipo.

## Primera puesta en marcha
1. Lea el aviso inicial: por defecto solo se leen las carpetas que usted incluya.
2. Incluya carpetas y revise la vista previa de exclusiones.
3. Siga el avance en **Administración · Buscador Documental**. Puede **Pausar lectura** en cualquier momento.

## Alcance y exclusiones
Modo predeterminado: carpetas incluidas. La etiqueta `rag-excluded` en un archivo o carpeta siempre prevalece y elimina lo ya leído.

## Perfil de lectura por significado
Se recomienda al instalar y no cambia solo. Para cambiarlo use la pantalla de administración o `occ buscador:profile`; el cambio prepara un índice nuevo y la búsqueda por palabras sigue funcionando.

## Respaldo, actualización y recuperación
El respaldo debe incluir el esquema de búsqueda (contiene el texto reconocido). Procedimientos de instalación, actualización, reversión, reconstrucción y restauración: pendientes.

## Referencia
Comandos `occ`, ajustes y rutas: se generan automáticamente en la página de desarrollo del repositorio.
