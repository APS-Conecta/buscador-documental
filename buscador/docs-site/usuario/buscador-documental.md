---
tipo: guia
audiencia: usuario
apps: [buscador-documental]
resumen: "Buscar documentos por palabras o por significado, incluidos escaneos y fotografías, solo entre los archivos que usted ya puede ver."
---
# Buscador Documental

```{note}
Borrador. Pendiente de revisión antes de publicarse.
```

El Buscador Documental le permite encontrar documentos por las palabras que contienen o por su significado, incluso si son escaneos o fotografías. Solo muestra documentos que usted ya puede ver en Archivos.

## Cómo buscar

1. Abra **Buscador Documental** en el menú lateral, o escriba en la barra de búsqueda de Nextcloud.
2. Escriba palabras o una pregunta, por ejemplo «vacunación invierno».
3. Elija un modo:
   - **Exacta**: busca las palabras tal como las escribió.
   - **Por significado**: encuentra documentos parecidos aunque usen otras palabras.
   - **Mixta** (recomendada): combina ambas.

   Por significado y Mixta se habilitan cuando la administración las activa. Mientras no estén disponibles, o si el servicio que calcula el significado no responde, el buscador busca por palabras y se lo avisa: nunca muestra una lista vacía por ese motivo.
4. Use los filtros de **tipo de archivo** (PDF, documentos, planillas, presentaciones, texto o imágenes), **modificado** (última semana, mes, año o un rango de fechas), **carpeta**, **propiedad** (todos, mis archivos o compartidos conmigo) y **Solo con lectura incierta** para acotar los resultados. El menú de la izquierda ofrece atajos: **Todos los documentos**, **Mis archivos**, **Compartidos conmigo** y **Con lectura incierta**. **Quitar filtros** los limpia todos.

5. Ordene los resultados por **Relevancia**, **Más recientes**, **Más antiguos** o por **Nombre** (A-Z o Z-A).
6. Elija cuántos resultados ver por página: **25**, **50** o **100**, y use **Primera**, **Anterior**, **Siguiente** y **Última** para recorrer las páginas; el resumen dice, por ejemplo, «Mostrando 26–50 de 137 documentos». Se muestran como máximo los 1 000 resultados más relevantes; si hay más, afine la búsqueda con los filtros. Al ordenar por fecha o por nombre se ordenan esos 1 000. Si la búsqueda caduca (pasados unos diez minutos), vuelva a buscar.

La dirección de la página guarda su búsqueda, el modo, el orden y los filtros: puede copiarla para compartirla o volver atrás con el navegador. Con el teclado: escriba, pulse Intro, recorra los resultados con Tab y abra el detalle con Intro.

Si escribe una sigla habitual de la atención primaria (por ejemplo REM, HTA o EMPAM), el buscador busca también su nombre completo, y al revés. Las siglas con más de un significado (por ejemplo SS) no se expanden.

Cada resultado muestra el nombre, la carpeta, la página, la fecha, la persona propietaria y un extracto con las palabras resaltadas. Al elegir un resultado se abre un panel con el detalle. **Abrir documento (página N)** abre el documento y le indica en qué página está la coincidencia: Nextcloud todavía no permite abrir un PDF directamente en una página.

## Qué formatos se leen

El buscador lee PDF, documentos de texto (Word y de formato abierto), presentaciones, hojas de cálculo, archivos de texto y fotografías. En una hoja de cálculo cada hoja se busca por separado. Un PDF mezcla páginas con texto y páginas escaneadas: cada página se trata según corresponda.

## Qué carpetas se leen y cómo excluir una

Su administrador decide qué carpetas se leen: una carpeta incluida se lee completa, con sus subcarpetas y con los archivos nuevos que reciba, sin que nadie tenga que volver a pedirlo. También puede incluir todas las carpetas de equipo.

Para que una carpeta o un archivo no se lea, aplíquele la etiqueta `rag-excluded`: lo ya leído se borra del buscador, no solo se oculta, y se vuelve a leer si usted retira la etiqueta. Cualquier persona con permiso de edición sobre el archivo puede retirar la etiqueta; si necesita que la exclusión no pueda deshacerse, pida a su administrador que excluya la carpeta por regla. Si envía un archivo a la papelera, su contenido deja de buscarse de inmediato y se borra del índice; si lo restaura, se lee de nuevo desde cero.

## Documentos escaneados

El buscador lee documentos escaneados y fotografías. Si la lectura no es clara, el resultado muestra **Lectura incierta**. Una foto más nítida y recta mejora el resultado. En la pestaña **Texto reconocido** del panel lateral de Archivos puede ver el texto leído, su confianza y copiarlo; la persona propietaria o un administrador puede pedir que se vuelva a leer el documento (**Volver a leer este documento**). Si el recurso no permite descargar, el texto no se muestra.

## Mientras se prepara

Cuando se activa por primera vez, la preparación depende de la cantidad de documentos y la página del buscador muestra el avance de la búsqueda por significado («Preparando: 38 %»). Durante ese tiempo puede buscar por palabras exactas; la búsqueda por significado se habilita al terminar. El avance se muestra en la página del buscador.

## Qué se lee y qué no

- Solo se leen las carpetas que su administrador incluyó. Si no encuentra un documento, pregunte si su carpeta está incluida.
- La barra de búsqueda de Nextcloud muestra los cinco mejores documentos y busca solo por palabras exactas; para buscar por significado use la página del Buscador.
- Los documentos que requieren una contraseña para abrirse no se leen y aparecen en la lista de omitidos del administrador. Tampoco se leen los archivos dañados, demasiado grandes (más de 250 MB o de 300 páginas) ni los que parecen peligrosos.

## Privacidad

- Los documentos nunca salen del servidor de su establecimiento.
- Si se elimina una cuenta, todo lo leído de los archivos de esa persona se borra del buscador.
- Usted solo ve lo que ya puede ver en Archivos; se verifica en cada búsqueda. Si alguien deja de compartirle un documento, deja de aparecer de inmediato.
- Si un documento le fue compartido sin permiso de descarga, aparece en la lista solo con su nombre, sin extracto.
- Si la búsqueda abarca demasiados resultados, se le pedirá que use palabras más específicas.
- Para que una carpeta no se lea, pida a su administrador que la excluya, o aplique la etiqueta de exclusión (`rag-excluded`) a la carpeta.
