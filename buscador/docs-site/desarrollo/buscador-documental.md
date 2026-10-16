---
tipo: referencia
esqueleto: borrador
audiencia: desarrollo
apps: [buscador-documental]
resumen: "Buscador Documental: estado del diseño, decisiones de la etapa de mediciones (S0) y cómo repetir las mediciones con el arnés."
---
# buscador-documental

```{note}
Borrador. Faltan el mapa de módulos, el contrato del proceso de trabajo (worker) y las pruebas, que se completan en las etapas S1 y S2 del plan. La referencia de comandos `occ`, ajustes y rutas se genera automáticamente.
```

## Propósito y diseño

Buscador Documental es la aplicación que busca por palabras y por significado, incluidos escaneos y fotografías, respetando los permisos de Nextcloud. El uso diario está en {doc}`/usuario/buscador-documental` y la configuración, en {doc}`/administracion/ia/buscador-documental`. El plan por etapas (S0 a S7), los criterios de aceptación y las decisiones (ADR) viven en el repositorio de la aplicación, en la carpeta `buscador/` de planificación.

## Decisiones de la etapa S0 (mediciones)

La etapa S0 mide, con un arnés desechable, los números que condicionan el diseño y aporta las mediciones con que se confirma o se descarta la ADR-0001 (Postgres primero). Las cifras son indicativas: se obtuvieron con texto sintético en un servidor compartido de 8 núcleos, y las etapas S4 y S7 las repiten con el fragmentador y el modelo reales.

- **ADR-0001, confirmada de forma condicional.** PostgreSQL se mantiene, pero la búsqueda exacta necesita un límite previo de candidatos para los términos muy frecuentes: sin él, el percentil 95 supera varios segundos. La etapa S4 debe comprobar con texto real que el límite no empeora la relevancia; si la empeora y no hay alternativa, la ADR se reabre y se evalúa OpenSearch.
- **Búsqueda por significado.** El recorrido iterativo del índice vectorial (`hnsw.iterative_scan = relaxed_order`, con reordenación posterior) es obligatorio cuando la persona solo puede leer una parte pequeña de los archivos; sin él, la mayoría de las consultas devuelven menos resultados de los pedidos. Para quien puede leer muy pocos archivos, la búsqueda exacta es más rápida.
- **Comprobación de permisos de 50 resultados.** Por ahora no cumple el límite de 300 ms (330 a 405 ms con una caché por solicitud). La etapa S1 debe diseñar una verificación que lo cumpla antes de terminar.
- **Límites propuestos** de tamaño, páginas, píxeles y tiempo por archivo: figuran en `02-performance.md` y se comprueban en la etapa S2.
- **Reconocimiento de texto.** Una capa de texto falsa o corrupta se conserva si el procesamiento omite las páginas con texto; hay que rehacer el reconocimiento en esas páginas. Las fotografías necesitan enderezado y giro automáticos.

Las cifras medidas (almacenamiento, tiempos de índice, latencias, reconocimiento, horas de carga inicial) están en la hoja de capacidad `capacity-sheet.md` del mismo repositorio de planificación.

## Repetir las mediciones

El arnés está en el directorio `spikes/s0-harness/` del repositorio de la aplicación (mientras no se publique, en el directorio local indicado por quien lo mantiene). Requiere Docker con Compose v2, bash y Python 3 en el equipo anfitrión, 8 núcleos, 31 GB de RAM y 60 GB de disco libre, sin GPU. Todo corre en el proyecto de compose `bd-spike`, solo en `127.0.0.1` y con contraseñas de prueba; en un servidor compartido no se detiene ni se mata nada que no haya iniciado el propio arnés.

1. `make up`: levanta PostgreSQL 17 con pgvector 0.8.7, Nextcloud 34 con la aplicación de carpetas de equipo y un contenedor de trabajo con Tesseract, OCRmyPDF y ONNX Runtime.
2. `make seed`: crea 200 grupos, 100 carpetas de equipo, una persona con un solo grupo, otra en los 200 grupos, un árbol con reglas de acceso, una etiqueta que excluye archivos del índice (`rag-excluded`), un recurso compartido con descarga desactivada y un conjunto fijo de 50 resultados para medir los permisos.
3. `make corpus`: genera un corpus sintético en español de 6 003 páginas (PDF nativos, escaneados, fotografías, mixtos y con capa de texto falsa), 200 consultas de referencia y las autocomprobaciones; con la misma semilla el resultado es idéntico.
4. `make load`: carga 3 000 000 de fragmentos sintéticos con vectores aleatorios (equivalente a un millón de páginas), construye los índices y registra tamaños y volumen del registro de escritura (WAL).
5. `make measure`: ejecuta las mediciones de búsqueda, reconocimiento de texto y permisos (varias horas); `make measure-cold` repite la búsqueda con la memoria compartida de Postgres vacía. `make report` resume los resultados en `results/summary.md`.
6. `make clean`: elimina únicamente el proyecto `bd-spike`.

Cada medición guarda un JSON con la carga del servidor: la máquina es compartida, por lo que se repite y se informa la mediana y la dispersión.

## Limitaciones del arnés

Texto sintético (los términos más frecuentes son más extremos que en español natural), vectores aleatorios para la latencia a escala, relevancia no medida (solo la exhaustividad del índice vectorial), generación de carga en el mismo servidor de la base de datos, memoria «fría» solo en Postgres y PostgreSQL 17 en lugar de 18. Las consultas de frase y de encabezado no encuentran nada en el texto sintético a escala.
