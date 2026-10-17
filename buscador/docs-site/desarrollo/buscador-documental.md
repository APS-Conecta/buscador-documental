---
tipo: referencia
esqueleto: borrador
audiencia: desarrollo
apps: [buscador-documental]
resumen: "Buscador Documental: estado del diseño, decisiones de las etapas S0 y S1, comprobación de permisos, pruebas y cómo repetir las mediciones con el arnés."
---
# buscador-documental

```{note}
Borrador. Falta el mapa de módulos completo, que se cierra con las etapas siguientes del plan. La referencia de comandos `occ`, ajustes y rutas se genera automáticamente.
```

## Propósito y diseño

Buscador Documental es la aplicación que busca por palabras y por significado, incluidos escaneos y fotografías, respetando los permisos de Nextcloud. El uso diario está en {doc}`/usuario/buscador-documental` y la configuración, en {doc}`/administracion/ia/buscador-documental`. El plan por etapas (S0 a S7), los criterios de aceptación y las decisiones (ADR) viven en el repositorio de la aplicación, en la carpeta `buscador/` de planificación.

## Decisiones de la etapa S0 (mediciones)

La etapa S0 mide, con un arnés desechable, los números que condicionan el diseño y aporta las mediciones con que se confirma o se descarta la ADR-0001 (Postgres primero). Las cifras son indicativas: se obtuvieron con texto sintético en un servidor compartido de 8 núcleos, y las etapas S4 y S7 las repiten con el fragmentador y el modelo reales.

- **ADR-0001, confirmada de forma condicional.** PostgreSQL se mantiene, pero la búsqueda exacta necesita un límite previo de candidatos para los términos muy frecuentes: sin él, el percentil 95 supera varios segundos. La etapa S4 debe comprobar con texto real que el límite no empeora la relevancia; si la empeora y no hay alternativa, la ADR se reabre y se evalúa OpenSearch.
- **Búsqueda por significado.** El recorrido iterativo del índice vectorial (`hnsw.iterative_scan = relaxed_order`, con reordenación posterior) es obligatorio cuando la persona solo puede leer una parte pequeña de los archivos; sin él, la mayoría de las consultas devuelven menos resultados de los pedidos. Para quien puede leer muy pocos archivos, la búsqueda exacta es más rápida.
- **Comprobación de permisos de 50 resultados.** En S0 no cumplía el límite de 300 ms (330 a 405 ms con una caché por solicitud). El resultado de S1 está en la sección siguiente.
- **Límites propuestos** de tamaño, páginas, píxeles y tiempo por archivo: figuran en `02-performance.md` y se comprueban en la etapa S2.
- **Reconocimiento de texto.** Una capa de texto falsa o corrupta se conserva si el procesamiento omite las páginas con texto; hay que rehacer el reconocimiento en esas páginas. Las fotografías necesitan enderezado y giro automáticos.

Las cifras medidas (almacenamiento, tiempos de índice, latencias, reconocimiento, horas de carga inicial) están en la hoja de capacidad `capacity-sheet.md` del mismo repositorio de planificación.

## Decisiones de la etapa S1 (esqueleto con canario de permisos)

La etapa S1 entrega el recorrido completo mínimo: una aplicación de Nextcloud (`OCA\BuscadorDocumental`, identificador `buscador_documental`) con su esquema `aps_search` en PostgreSQL, un canal de trabajo con autenticación HMAC, un proceso de lectura en Python (OCR) y la consulta con verificación de permisos en vivo.

- **El permiso lo decide la verificación en vivo.** Los permisos guardados en el índice (`u:` y `g:`) solo sirven de prefiltro. El conjunto de la persona que consulta incluye siempre `x:recheck`, y cada resultado pasa por `Recheck` antes de mostrar el extracto. Un resultado que falla se descarta; si falla la comprobación de exclusión o el servicio de etiquetas, falla toda la consulta sin devolver resultados.
- **Reglas de `Recheck`.** Cuenta como legible cualquier nodo legible por cualquier montaje; un recurso compartido vencido no cuenta; si el recurso compartido tiene la descarga desactivada, el resultado se devuelve solo con el título, sin encabezado, página ni extracto (ADR-0009).
- **Etiqueta `rag-excluded`.** Se revisa en el archivo y en cada carpeta superior hasta la raíz del almacenamiento. Cuenta cualquier etiqueta con ese nombre, aunque esté duplicada. El borrado de lo ya leído deja cero filas en `page` y `chunk`.
- **Consulta.** `websearch_to_tsquery` con la configuración `aps_search.spanish_unaccent`, un límite previo de 10 000 candidatos, el mejor fragmento por archivo, tiempo máximo de 5 s (si se agota, la respuesta pide afinar la búsqueda) y extractos con marcas aleatorias por solicitud y escape de HTML.
- **Canal de trabajo.** El proceso de lectura no escribe directamente en las tablas: usa los puntos de acceso de la aplicación con firma HMAC, ventana de ±120 s y caché de nonces de 5 min (ADR-0007). `write_result` fuerza el archivo reclamado y un trabajador con el arrendamiento vencido no puede sobrescribir (prueba del trabajador zombi).
- **A-PERF-3 no se cumple.** Con una sonda corregida (50 resultados, 3 personas, 30 repeticiones, 5 variantes intercaladas), la variante entregada tiene un percentil 95 de 377 ms en la persona más desfavorable y la mejor variante, 308,5 ms, frente a 300 ms, sin contar la preparación del sistema de archivos (105 a 176 ms). Las cinco variantes decidieron igual los 50 resultados en las 450 ejecuciones. La ADR-0010 propone aceptar el resultado con presupuesto de 150 ms para unos 20 documentos; está en estado propuesto y requiere decisión humana.
- **Búsqueda exacta en PostgreSQL 18.** Con el mismo flujo de texto de S0 (3 000 000 de fragmentos, solo texto) los resultados coinciden con los de PostgreSQL 17.11 dentro del ruido (p95 con límite previo: 205 frente a 179 ms en estrés), de modo que el límite previo sigue siendo obligatorio.
- **Sin verificar.** La semántica de descarga con varios nodos (M7) no se puede comprobar en un solo nodo; queda para S3 y S5.

## Pruebas

Todo corre en Docker, en el proyecto de compose `bd-s1`, solo en `127.0.0.1`. Desde el directorio `app/`:

| Objetivo | Qué comprueba |
|---|---|
| `make test-unit` | Escritura de permisos, autenticación HMAC, controladores, `Retrieve` y `Recheck` con dobles. |
| `make test-contract` | El controlador PHP y el cliente Python frente a los ejemplos de `worker-v1.yaml`. |
| `make test-db` | Privilegios del rol de trabajo y trabajador zombi. |
| `make test-search` | Consulta contra el esquema real: prefiltro obsoleto, contador de descartes, resultado sin extracto con descarga desactivada. |
| `make test-e2e-one` | Un PDF escaneado de punta a punta por la aplicación, el trabajador y el OCR. |
| `make test-canary` | Veinte comprobaciones con Nextcloud 34 y el trabajador reales: línea base, recurso revocado, descarga desactivada, grupo retirado, etiqueta en carpeta superior, etiqueta duplicada, borrado y rechazo al encolar. |

`make clean` elimina únicamente los recursos de `bd-s1`. Las pruebas de trabajo en Python se ejecutan con `make -C worker test`. La integración continua (`.github/workflows/ci.yml`: `php`, `worker` y `smoke`) está escrita pero aún no se ha ejecutado en GitHub.

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
