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
4. Use los filtros de tipo, carpeta, fecha y propiedad para acotar los resultados.

5. Ordene los resultados por **Relevancia**, **Más recientes**, **Más antiguos** o por **Nombre** (A-Z o Z-A).
6. Elija cuántos resultados ver por página: **25**, **50** o **100**, y use **Anterior** y **Siguiente** para recorrer las páginas. Se muestran como máximo los primeros 1 000 resultados; si hay más, afine la búsqueda con los filtros.

Los filtros disponibles son: tipo de archivo, carpeta, fecha de modificación, propiedad (todos, mis archivos o compartidos conmigo) y «Solo con lectura incierta». El menú de la izquierda ofrece atajos: **Todos los documentos**, **Mis archivos**, **Compartidos conmigo** y **Con lectura incierta**.

Cada resultado muestra el nombre, la carpeta, la página y un extracto con las palabras resaltadas. Use **Abrir en la página N** para ir directamente al lugar.

## Qué formatos se leen

El buscador lee PDF, documentos de texto (Word y de formato abierto), presentaciones, hojas de cálculo, archivos de texto y fotografías. En una hoja de cálculo cada hoja se busca por separado. Un PDF mezcla páginas con texto y páginas escaneadas: cada página se trata según corresponda.

## Qué carpetas se leen y cómo excluir una

Su administrador decide qué carpetas se leen: una carpeta incluida se lee completa, con sus subcarpetas y con los archivos nuevos que reciba, sin que nadie tenga que volver a pedirlo. También puede incluir todas las carpetas de equipo.

Para que una carpeta o un archivo no se lea, aplíquele la etiqueta `rag-excluded`: lo ya leído se borra del buscador, no solo se oculta, y se vuelve a leer si usted retira la etiqueta. Cualquier persona con permiso de edición sobre el archivo puede retirar la etiqueta; si necesita que la exclusión no pueda deshacerse, pida a su administrador que excluya la carpeta por regla. Si envía un archivo a la papelera, su contenido deja de buscarse de inmediato y se borra del índice; si lo restaura, se lee de nuevo desde cero.

## Documentos escaneados

El buscador lee documentos escaneados y fotografías. Si la lectura no es clara, el resultado muestra **Lectura incierta**. Una foto más nítida y recta mejora el resultado. En la pestaña **Texto reconocido** del panel lateral de Archivos puede ver el texto leído y pedir que se vuelva a leer el documento.

## Mientras se prepara

Cuando se activa por primera vez, la preparación depende de la cantidad de documentos y su avance se muestra en la página del buscador. Durante ese tiempo puede buscar por palabras exactas; la búsqueda por significado se habilita al terminar. El avance se muestra en la página del buscador.

## Qué se lee y qué no

- Solo se leen las carpetas que su administrador incluyó. Si no encuentra un documento, pregunte si su carpeta está incluida.
- La barra de búsqueda de Nextcloud busca solo por palabras exactas; para buscar por significado use la página del Buscador.
- Los documentos que requieren una contraseña para abrirse no se leen y aparecen en la lista de omitidos del administrador. Tampoco se leen los archivos dañados, demasiado grandes (más de 250 MB o de 300 páginas) ni los que parecen peligrosos.

## Privacidad

- Los documentos nunca salen del servidor de su establecimiento.
- Si se elimina una cuenta, todo lo leído de los archivos de esa persona se borra del buscador.
- Usted solo ve lo que ya puede ver en Archivos; se verifica en cada búsqueda. Si alguien deja de compartirle un documento, deja de aparecer de inmediato.
- Si un documento le fue compartido sin permiso de descarga, aparece en la lista solo con su nombre, sin extracto.
- Si la búsqueda abarca demasiados resultados, se le pedirá que use palabras más específicas.
- Para que una carpeta no se lea, pida a su administrador que la excluya, o aplique la etiqueta de exclusión (`rag-excluded`) a la carpeta.
