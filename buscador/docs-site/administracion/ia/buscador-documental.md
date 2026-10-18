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
Nextcloud 34, PostgreSQL con la extensión pgvector (versión 0.8 o posterior) y un contenedor de lectura (worker) con Tesseract. La búsqueda por significado necesita además un servidor Ollama con un modelo de lectura por significado (vea más abajo); sin él, el buscador funciona por palabras.

## Capacidad y tiempos (estimaciones)
```{note}
Cifras de la etapa de diseño, obtenidas con documentos sintéticos en un servidor compartido de 8 núcleos y 31 GB de memoria, sin GPU. Se confirmarán con datos reales antes de la primera versión.
```
Para un millón de páginas:

- **Espacio en disco:** unos 15 GB en la base de datos (alrededor de 5 kB por fragmento de texto, con tres fragmentos por página). Durante la primera carga el registro de escritura puede ocupar otros 14 GB de forma transitoria. Al cambiar de perfil de lectura por significado, reserve además el doble del espacio de los vectores.
- **Memoria:** los índices y los vectores que se consultan a diario suman unos 7 GB; conviene que quepan en la memoria del servidor.
- **Primera lectura:** unas 71 horas con los 8 núcleos dedicados, es decir, unas 9 noches de 8 horas si se limita a una ventana nocturna. La búsqueda por palabras queda disponible antes, tras unas 45 horas, y la búsqueda por significado se completa después.
- **Reconocimiento de texto:** entre 2 y 3 segundos por página con el motor solo; en la medición de la etapa S2, con la comprobación de confianza y una página a la vez, unos 13 segundos por página leída desde imagen. Las páginas con una imagen del tamaño de un escaneo se leen siempre desde la imagen, aunque tengan texto; las fotografías y los escaneos torcidos tardan más.
- **Lectura por significado (vectores):** medida con Ollama en CPU (4 núcleos, sin GPU) y fragmentos de unas 170 palabras, el modelo bge-m3 procesa **alrededor de 1 fragmento por segundo** (el modelo qwen3-embedding:0.6b, la mitad). Eso son unas 83 horas para 100 000 páginas y unos 33 días para un millón de páginas; con 8 núcleos el tiempo debería reducirse a la mitad y una GPU lo reduce mucho más. Cada fragmento ocupa 2 kB de vectores, más el índice (unos 6 GB y 8 GB para 3 millones de fragmentos). La búsqueda por palabras no espera a los vectores. Consultar una frase por significado agrega unos 0,2 a 0,3 segundos para calcular su vector.

## Archivos que no se leen
Cada archivo omitido queda con un motivo visible: **protegido con contraseña** (se necesita una contraseña para abrirlo; los PDF que solo tienen contraseña de edición sí se leen), **dañado**, **demasiado grande** (más de 250 MB, 300 páginas o 1 000 000 de celdas, o más fragmentos de los que admite el índice), **peligroso** (por ejemplo, un archivo comprimido que se expande de forma desmedida o una imagen de tamaño desproporcionado) y **formato no admitido**. Los archivos peligrosos se leen en un contenedor aislado, sin red, sin acceso a la base de datos ni a las claves del servicio y con memoria y procesos limitados. La lectura de cada archivo tiene un tiempo máximo; si se agota, el trabajo se reintenta y, tras cinco intentos, se omite con el motivo «interno».

## Primera puesta en marcha
1. Lea el aviso inicial: por defecto solo se leen las carpetas que usted incluya.
2. Incluya carpetas y revise la vista previa de exclusiones.
3. Siga el avance en **Administración · Buscador Documental**. Puede **Pausar lectura** en cualquier momento.

## Alcance y exclusiones
Modo predeterminado: carpetas incluidas. La etiqueta `rag-excluded` en un archivo o carpeta siempre prevalece y elimina lo ya leído. La exclusión se aplica también cuando la etiqueta está en una carpeta superior, aunque la persona acceda al archivo por otro punto de montaje, y también si hay varias etiquetas con ese nombre. Si el servicio de etiquetas no responde, la búsqueda no devuelve resultados en lugar de arriesgar una exposición.

## Qué carpetas se incluyen
Mientras no se registren la confirmación jurídica y la evaluación de impacto, el modo es **carpetas incluidas**: no se lee nada fuera de las reglas que usted cree. Una regla incluye una carpeta con todo lo que contiene (archivos nuevos, subcarpetas y movimientos dentro de ella se leen solos), o bien todas las carpetas de equipo, incluidas las futuras. Las reglas se gestionan con `occ buscador:scope:list`, `add`, `remove` y `preview` (esta última solo cuenta archivos y tamaño, sin nombres). El modo **todo lo legible** se rechaza hasta que existan la confirmación y la evaluación. Cada cambio queda en la auditoría con seudónimos.

## Pausar, estado y reconstrucción
`occ buscador:pause` detiene la lectura de documentos nuevos y la entrega de archivos al proceso de lectura; las exclusiones, las eliminaciones y el borrado siguen funcionando. `occ buscador:resume` la reanuda. `occ buscador:status` muestra, solo con números y motivos, los trabajos en cola, los archivos por estado, los omitidos por motivo, los eventos pendientes o detenidos tras varios fallos, la antigüedad de los cambios de permisos pendientes y la última conciliación. `occ buscador:reindex` vuelve a poner en cola un archivo o todos.

## Conciliación nocturna
Cada noche, dentro de la ventana configurada (por omisión de 01:00 a 05:00 hora del servidor), el buscador compara Nextcloud con el índice y repara lo que los eventos no registraron: archivos nuevos o editados sin aviso, movidos fuera de las reglas, eliminados por otra vía o con la etiqueta de exclusión retirada. Primero quita lo que ya no corresponde y después agrega, con un tope diario de altas. Con 300 000 archivos tarda unos 10 segundos en el servidor de pruebas; el tiempo con un millón es una estimación (de uno a dos minutos). Los cambios de permisos en carpetas de equipo, que Nextcloud no avisa, se detectan cada 15 minutos. La ventana y el día de la conciliación se cuentan con la zona horaria del servidor. Al agregar o quitar una regla, o cambiar el modo, se solicita una evaluación que se ejecuta en la siguiente pasada, sin esperar a la noche. Las tareas de fondo del buscador tienen un presupuesto de unos 25 segundos (eventos) y 60 segundos (conciliación) por ejecución, por lo que se recomienda el modo Cron de Nextcloud, no AJAX ni Webcron.

## Eliminar los datos de una persona
Al eliminar una cuenta, todo lo leído de sus archivos se borra del buscador y la auditoría queda sin vínculo con la persona (se conserva un código aleatorio cuya correspondencia se elimina junto con la cuenta). Para borrar lo leído de una persona que sigue existiendo, use `occ buscador:purge-user`; sus archivos y su cuenta no se tocan. La auditoría se conserva 90 días (valor por confirmar con el asesor jurídico). Los registros del buscador (aplicación, proceso de lectura, contenedor aislado y base de datos) no contienen texto de documentos ni consultas; el registro de accesos del servidor web, que es de Nextcloud, anota las direcciones solicitadas, y por eso la búsqueda de la aplicación, que se incorpora en etapas posteriores, deberá usar peticiones POST.

## Permisos en cada búsqueda
Los permisos guardados en el índice solo preseleccionan resultados; cada resultado se vuelve a comprobar contra Nextcloud en el momento de la consulta. Por eso, retirar un recurso compartido o a una persona de un grupo surte efecto de inmediato, sin esperar a una nueva lectura. Un recurso compartido vencido no da acceso. Si el recurso compartido tiene la descarga desactivada, la persona ve el documento en la lista solo con su título, sin extracto.

Los totales y las páginas de la búsqueda se calculan con esos permisos guardados; los archivos con acceso que el índice no puede describir (círculos, Talk, Deck, correo, recursos federados y carpetas de equipo con reglas avanzadas) se comprueban contra Nextcloud antes de contarlos. Tras retirar un recurso compartido, el total puede seguir incluir el archivo durante unos minutos, hasta que se actualicen los permisos guardados; el archivo nunca se muestra. Mientras un archivo editado espera su nueva lectura, la lista muestra solo su título, no un extracto del texto anterior.

La comprobación de 50 resultados tarda unos 380 ms en el percentil 95 en la medición de la etapa S1 (el objetivo era 300 ms); la ADR-0010 acepta ese resultado.

## Búsqueda por significado
La búsqueda por significado y la mixta necesitan un servidor Ollama que usted instala y mantiene; el buscador no incluye ningún modelo. Puede estar en el mismo servidor o en otro de la red.

1. En el servidor Ollama descargue el modelo: `ollama pull bge-m3:567m`. Los perfiles disponibles se ven con `occ buscador:profile list` (`bge-m3` y `qwen3-embedding-0.6b`).
2. Registre el perfil: `occ buscador:profile set bge-m3 --url=http://servidor:11434`. Si el servidor exige un token, guárdelo en un archivo y use `--token-file=ruta` (se guarda cifrado). El comando comprueba que el modelo existe, anota su identificador y su tamaño de vector, y crea una **generación** de vectores.
3. Los vectores se calculan en segundo plano, después de la lectura de texto. `occ buscador:status` y `occ buscador:profile show` muestran el avance (cobertura). `occ buscador:profile refresh` fuerza la medición (con `--retry-failed` vuelve a intentar los archivos que el proceso de lectura abandonó).
4. Cuando la cobertura llega al mínimo (95 % por omisión) la generación queda **lista** y se construye su índice. Nada cambia solo: para usarla ejecute `occ buscador:profile activate 1`.
5. Active los modos con `occ buscador:profile semantic on`. Hágalo después de revisar los resultados con documentos reales: en la evaluación con texto sintético la búsqueda mixta superó a la de palabras y a la de significado, pero esa prueba debe repetirse con sus documentos. Con `semantic off` los modos se desactivan y el buscador vuelve a las palabras.

Si cambia de modelo o el servidor Ollama actualiza el modelo con el mismo nombre, el identificador cambia: el proceso de lectura se detiene con el motivo «el modelo cambió» en lugar de mezclar vectores incompatibles, y usted debe registrar el perfil de nuevo (se crea una generación nueva; la anterior sigue sirviendo hasta que active la nueva y puede eliminarla después con `occ buscador:profile drop <generación>`).

Mientras no haya un perfil activo, la cobertura sea baja o el servidor Ollama no responda, las búsquedas por significado y mixta muestran los resultados por palabras con un aviso; nunca devuelven una lista vacía por ese motivo.

**Privacidad y seguridad.** El texto de los documentos y las consultas viajan al servidor Ollama que usted configuró. Si no está en el mismo servidor, use una dirección https detrás de un proxy que pida autenticación y que solo permita `/api/embed`, `/api/tags` y `/api/show` (Ollama no tiene autenticación propia). Eso debe constar en la evaluación de impacto. Nextcloud bloquea por omisión las conexiones a direcciones locales; el buscador las permite solo hacia la dirección que usted registró. Los registros no contienen la dirección, el token, las consultas ni el texto de los documentos. El proceso de lectura no sigue redirecciones del servidor Ollama. Si usa una dirección http fuera de este equipo, `occ buscador:profile set` lo advierte: el texto y el token viajarían sin cifrar. El contenedor de lectura debería poder conectarse solo a ese servidor; el archivo `compose.yaml` de ejemplo no lo restringe y debe ajustarse en su red.

**Siglas y términos.** El buscador incluye un diccionario de unas 400 siglas de la atención primaria chilena (REM, HTA, CESFAM, EMPAM…). Al buscar una sigla también se busca su nombre completo y al revés. Las siglas ambiguas (por ejemplo SS o PCR) no se expanden.

## Respaldo, actualización y recuperación
El respaldo debe incluir el esquema de búsqueda (contiene el texto reconocido). Procedimientos de instalación, actualización, reversión, reconstrucción y restauración: pendientes.

## Referencia
Comandos `occ`, ajustes y rutas: se generan automáticamente en la página de desarrollo del repositorio.
