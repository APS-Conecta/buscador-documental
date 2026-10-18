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

## Operación diaria
- **Estado y estimación.** `occ buscador:status` muestra por carril (lectura de texto y vectores) cuánto falta y en cuánto tiempo terminará al ritmo de la última hora, con un margen de ±30 % cuando el ritmo es estable. Si hay menos de 10 archivos terminados en la última hora, la pausa está activa o la ventana nocturna está cerrada, lo dice en lugar de inventar una cifra.
- **Revisión de salud.** `occ buscador:doctor` comprueba el esquema, los roles y las extensiones de la base de datos, el secreto del proceso de lectura, si hay un proceso de lectura activo cuando hay trabajo pendiente, los eventos detenidos, la antigüedad de los cambios de permisos, la última conciliación, el modo de tareas de fondo (debe ser Cron), el servidor Ollama y el índice de vectores. También avisa si la ventana nocturna o su zona horaria no son entendidas por la base de datos (en ese caso se ignoran y el trabajo pesado corre a cualquier hora). Termina con código 1 solo si algo falla (las advertencias no lo hacen), de modo que sirve como sonda de monitoreo; `--json` entrega el resultado para máquinas. Nunca imprime secretos, direcciones, nombres de modelos ni texto.
- **Ventana nocturna.** `occ buscador:window set 01:00-05:00` limita el cálculo de vectores a esa franja (hora del servidor de Nextcloud; la zona debe ser un nombre IANA como `America/Santiago` que también conozca la base de datos: no se aceptan desfases como `+03:00` ni abreviaturas, porque PHP y PostgreSQL los leen de forma distinta); la lectura de archivos nuevos o editados sigue de día. Con `--lanes=all` la ventana también detiene la lectura de texto. `occ buscador:window off` la quita; `occ buscador:window show` dice si está abierta.
- **Concurrencia.** `occ buscador:throttle set --text=2 --vec=1` fija el tope de trabajos que puede tener asignados a la vez cada proceso de lectura (de 1 a 16; por omisión 4 y 2) y, entre todos los procesos, cuatro veces ese valor para la lectura de texto y dos veces para los vectores. El proceso de lectura estándar trabaja con un archivo a la vez, de modo que estos topes importan cuando se ejecutan varios procesos de lectura (por ejemplo, para que no más de un cierto número de documentos se procesen en paralelo); con un solo proceso no cambian nada. `occ buscador:throttle reset` vuelve a los valores iniciales. Los cambios rigen desde la siguiente solicitud de trabajo, sin reiniciar nada.
- **Pausa.** `occ buscador:pause` y `occ buscador:resume` sobreviven al reinicio de la base de datos, de Nextcloud y del proceso de lectura: la cola vive en PostgreSQL.
- **Alertas.** `occ buscador:status --prometheus` imprime solo números y etiquetas fijas; el archivo `ops/alerts.yml` de la aplicación trae reglas de ejemplo (proceso de lectura callado, eventos detenidos, cambios de permisos atrasados, conciliación vencida, vectores fallidos, cobertura estancada, cola demasiado lenta). Ejecute el comando desde Cron hacia el recolector de archivos de texto de su agente de monitoreo.

## Rotar el secreto del proceso de lectura
1. `occ buscador:rotate-secret --to-file=/ruta/segura/nuevo.secret --grace=24` crea un secreto nuevo de 48 caracteres, lo guarda en un archivo de uso privado (0600) y mantiene válido el anterior durante 24 horas. Sin `--to-file` lo imprime una sola vez.
2. Copie el valor a `BD_WORKER_SECRET` del proceso de lectura y reinícielo dentro del plazo. Si rota dos veces seguidas, el secreto anterior a la primera rotación deja de valer en la segunda (solo se conserva el inmediatamente anterior); el comando lo avisa. Nextcloud conserva su configuración en memoria hasta 3 segundos, de modo que el cambio se nota en pocos segundos.
3. Pasado el plazo el secreto anterior deja de valer. Con `--grace=0` deja de valer de inmediato: use esa opción si sospecha que se filtró; el trabajo en curso se detendrá hasta que el proceso de lectura tenga el valor nuevo y sus asignaciones venzan (hasta 15 minutos).
El secreto nunca aparece en los registros ni en la auditoría (esta solo guarda el plazo de gracia).

## Sin salida a internet
El proceso de lectura (el que recibe el texto de los documentos y tiene las claves) corre en una red interna sin ruta a internet: llega a la base de datos y a Nextcloud por su nombre y, en la configuración de ejemplo, al servidor Ollama de prueba; el contenedor de lectura aislado no tiene red. Nextcloud y la base de datos sí tienen red (Nextcloud la necesita, por ejemplo, para la tienda de aplicaciones). Para usar un servidor Ollama en otra máquina hay que darle al proceso de lectura una red que lo alcance: es una decisión de su red, la única excepción, y se hace en `compose.yaml` o en su orquestador. La prueba `make -C app test-e2e-egress` lo demuestra intentando salir y comprobando que un archivo se lee y se encuentra igualmente. Un servidor Ollama remoto es la única excepción y es una decisión de su red. El código PHP de la aplicación no llama a ningún otro servidor que no sea el Ollama configurado (una prueba estática lo verifica).

## Respaldo y restauración
- **Respaldo.** `BD_BACKUP_KEY_FILE=/ruta/clave.key PGHOST=... PGUSER=... PGDATABASE=nextcloud tools/backup.sh /ruta/buscador.bak` guarda el esquema `aps_search` (incluye el texto de las páginas y los vectores) cifrado con AES-256, más un archivo `.sha256` que detecta daños y un archivo `.meta` solo con contadores. La frase de cifrado se deriva de **todo** el archivo de clave, que debe tener al menos 32 bytes, ser legible solo por su dueño y guardarse **aparte** del respaldo: quien tenga ambos lee todo lo indexado. El archivo no se sobrescribe y un respaldo fallido no deja restos. El cifrado no autentica el archivo y el `.sha256` detecta corrupción, no manipulación deliberada: guarde los respaldos donde nadie más pueda escribir.
- **Restauración.** En la base de destino deben existir los roles `bd_owner` y `bd_worker`, el usuario de base de datos de Nextcloud y las extensiones `unaccent` (y `vector` si el respaldo tiene vectores). `BD_BACKUP_KEY_FILE=... PGUSER=<superusuario> tools/restore.sh /ruta/buscador.bak base_destino` comprueba todo antes de cambiar nada, rechaza una clave equivocada o un respaldo dañado, no pisa un esquema existente y restaura en una sola transacción; al final imprime los contadores para compararlos con el `.meta`. **Restaure solo respaldos que usted hizo y guardó en un lugar donde nadie más escribe**: para limitar el daño de un archivo manipulado, solo la creación del esquema vacío usa privilegios de superusuario y todo lo demás se ejecuta como el rol `bd_owner`, que no es superusuario, no puede ejecutar programas en el servidor y no es dueño de nada fuera de `aps_search`. El archivo `.sha256` es obligatorio. El texto se descifra en un directorio privado (en memoria si existe `/dev/shm`) que se borra al terminar.
- **Después de restaurar.** Los permisos, las exclusiones y los borrados de personas posteriores al respaldo no están en él: ejecute `occ buscador:reconcile` (pide una conciliación completa en la siguiente pasada del trabajo de fondo) y revise `occ buscador:status` y `occ buscador:doctor`. Mientras tanto la comprobación en vivo contra Nextcloud sigue ocultando lo que ya no se puede ver, pero el texto de archivos eliminados o excluidos después del respaldo puede reaparecer en la base hasta que termine la conciliación.
- **Ensayo.** `make -C app test-e2e-restore` repite el procedimiento en la pila de pruebas: con un corpus de tres archivos el respaldo tardó unos 0,6 s y la restauración unos 1,1 s. Son tiempos del procedimiento, no del volumen; el ensayo con un millón de páginas se mide en la etapa S7.
- **Incluya el respaldo en su política de cifrado en reposo** (las páginas y los fragmentos son texto de sus documentos).

## Instalación, actualización, reversión y reconstrucción
- **Instalación.** Cree los roles y extensiones que pide la migración (el error nombra el paso que falta), active la aplicación, configure el secreto del proceso de lectura, incluya carpetas (`occ buscador:scope:add`) y, si quiere búsqueda por significado, registre el perfil. Verifique con `occ buscador:doctor`.
- **Actualización.** Respalde, actualice los archivos de la aplicación y vuelva a activarla: las migraciones de esquema son idempotentes y se aplican solas. El proceso de lectura se niega a trabajar con un esquema que no conoce; actualícelo con la misma versión. Verifique con `occ buscador:doctor`.
- **Reversión.** Un esquema nuevo no se deshace: restaure el respaldo previo a la actualización en una base limpia (vea Restauración) y reinstale la versión anterior de la aplicación y del proceso de lectura.
- **Reconstrucción.** Si el índice se pierde o se sospecha dañado, restáurelo desde un respaldo; si no hay respaldo, `occ buscador:reindex` vuelve a poner en cola todos los archivos incluidos. El tiempo es el de la primera lectura (vea Capacidad y tiempos): la lectura de texto no espera a los vectores.
- **Cambiar de perfil o de modelo.** Vea Búsqueda por significado: se crea una generación nueva y la anterior sigue sirviendo hasta que usted active la nueva.

## Referencia
Comandos `occ`, ajustes y rutas: se generan automáticamente en la página de desarrollo del repositorio.
