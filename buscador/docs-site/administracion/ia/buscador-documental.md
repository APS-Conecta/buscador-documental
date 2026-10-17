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
- **Reconocimiento de texto:** entre 2 y 3 segundos por página con el motor solo; en la medición de la etapa S2, con la comprobación de confianza y una página a la vez, unos 13 segundos por página leída desde imagen. Las páginas con una imagen del tamaño de un escaneo se leen siempre desde la imagen, aunque tengan texto; las fotografías y los escaneos torcidos tardan más.
- **Perfil de lectura por significado:** en un servidor sin GPU se recomienda el perfil pequeño; el perfil grande no es apto para ese equipo.

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
Cada noche, dentro de la ventana configurada (por omisión de 01:00 a 05:00 hora del servidor), el buscador compara Nextcloud con el índice y repara lo que los eventos no registraron: archivos nuevos o editados sin aviso, movidos fuera de las reglas, eliminados por otra vía o con la etiqueta de exclusión retirada. Primero quita lo que ya no corresponde y después agrega, con un tope diario de altas. Con 300 000 archivos tarda unos 10 segundos en el servidor de pruebas; el tiempo con un millón es una estimación (de uno a dos minutos). Los cambios de permisos en carpetas de equipo, que Nextcloud no avisa, se detectan cada 15 minutos.

## Eliminar los datos de una persona
Al eliminar una cuenta, todo lo leído de sus archivos se borra del buscador y la auditoría queda sin vínculo con la persona (se conserva un código aleatorio cuya correspondencia se elimina junto con la cuenta). Para borrar lo leído de una persona que sigue existiendo, use `occ buscador:purge-user`; sus archivos y su cuenta no se tocan. La auditoría se conserva 90 días (valor por confirmar con el asesor jurídico). Los registros del buscador (aplicación, proceso de lectura, contenedor aislado y base de datos) no contienen texto de documentos ni consultas; el registro de accesos del servidor web, que es de Nextcloud, anota las direcciones solicitadas, y por eso la búsqueda de la aplicación, que se incorpora en etapas posteriores, deberá usar peticiones POST.

## Permisos en cada búsqueda
Los permisos guardados en el índice solo preseleccionan resultados; cada resultado se vuelve a comprobar contra Nextcloud en el momento de la consulta. Por eso, retirar un recurso compartido o a una persona de un grupo surte efecto de inmediato, sin esperar a una nueva lectura. Un recurso compartido vencido no da acceso. Si el recurso compartido tiene la descarga desactivada, la persona ve el documento en la lista solo con su título, sin extracto.

La comprobación de 50 resultados tarda unos 380 ms en el percentil 95 en la medición de la etapa S1 (el objetivo era 300 ms); la ADR-0010 acepta ese resultado.

## Perfil de lectura por significado
Se recomienda al instalar y no cambia solo. Para cambiarlo use la pantalla de administración o `occ buscador:profile`; el cambio prepara un índice nuevo y la búsqueda por palabras sigue funcionando.

## Respaldo, actualización y recuperación
El respaldo debe incluir el esquema de búsqueda (contiene el texto reconocido). Procedimientos de instalación, actualización, reversión, reconstrucción y restauración: pendientes.

## Referencia
Comandos `occ`, ajustes y rutas: se generan automáticamente en la página de desarrollo del repositorio.
