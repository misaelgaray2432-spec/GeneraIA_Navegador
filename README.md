# GeneraIA Navegador

Asistente web en español creado con PHP, JavaScript y MySQL. Usa un endpoint compatible con Ollama para conversar y analizar imágenes; además puede consultar la búsqueda web de Ollama y buscar fotografías en Unsplash o Wikimedia Commons.

> La búsqueda web amplía la información disponible, pero no convierte al asistente en una fuente infalible ni garantiza una respuesta sobre cualquier tema. Verifica los datos importantes.

## Contenido

- [Funciones](#funciones)
- [Requisitos](#requisitos)
- [Instalación local con XAMPP](#instalación-local-con-xampp)
- [Configuración](#configuración)
- [Búsqueda en internet](#búsqueda-en-internet)
- [Búsqueda de imágenes](#búsqueda-de-imágenes)
- [Voz e imágenes adjuntas](#voz-e-imágenes-adjuntas)
- [Despliegue en Alwaysdata](#despliegue-en-alwaysdata)
- [Solución de problemas](#solución-de-problemas)
- [Privacidad y seguridad](#privacidad-y-seguridad)

## Funciones

- Conversación en español mediante la API de chat de Ollama.
- Historial de conversaciones guardado en MySQL y asociado a la sesión del navegador.
- Análisis de imágenes adjuntas con un modelo compatible con visión. Se aceptan JPG, PNG, WEBP y GIF de hasta 5 MB.
- Búsqueda web automática para preguntas que parecen necesitar información reciente, fechas o comprobación de hechos. También se puede pedir explícitamente “Busca en internet…”.
- Búsqueda de fotografías: Unsplash como primera opción y Wikimedia Commons como alternativa.
- Créditos de las imágenes disponibles en un panel opcional. Las respuestas de texto no muestran listas de fuentes ni enlaces de búsqueda.
- Dictado mediante el reconocimiento de voz disponible en el navegador y lectura en voz alta mediante SpeechSynthesis.
- Botón para detener una búsqueda o respuesta en curso.

La búsqueda de imágenes encuentra fotografías existentes; no genera imágenes nuevas.

## Requisitos

- PHP 8 o posterior con las extensiones `curl`, `mbstring` y `pdo_mysql`.
- Apache y MySQL. XAMPP incluye estos componentes para desarrollo local.
- Un servidor Ollama accesible desde PHP y los modelos configurados instalados en ese servidor.
- Para búsqueda web: una clave de Ollama que pueda usarse como token Bearer.
- Para la búsqueda primaria de imágenes: una Access Key de Unsplash. Si no se configura o Unsplash no está disponible, la aplicación intenta usar Wikimedia Commons.
- Chrome o Edge actualizado para dictado por voz. Se necesita permiso para usar el micrófono.
- La carpeta `media/` debe permitir que PHP guarde los archivos adjuntos.

## Instalación local con XAMPP

1. Coloca la carpeta del proyecto dentro de `C:\xampp\htdocs\`. La carpeta debe contener `index.php`, `api.php`, `schema.sql` y `assets/`.
2. Abre el panel de XAMPP e inicia Apache y MySQL.
3. Entra a phpMyAdmin, crea una base de datos —por ejemplo, `generaia`— y selecciona esa base.
4. Importa `schema.sql` en la base seleccionada. El archivo crea las tablas `conversations` y `messages`; no crea una base de datos.
5. Copia `.env.example` con el nombre `.env` en la carpeta principal del proyecto. Edita los valores de conexión a MySQL y los servicios que vayas a usar, según la sección [Configuración](#configuración).
6. Instala/inicia Ollama en el equipo o servidor elegido y descarga los modelos cuyos nombres figuran en `OLLAMA_CHAT_MODEL` y `OLLAMA_VISION_MODEL`. Por ejemplo, si conservas el valor inicial, ejecuta `ollama pull qwen3.5:2b`.
7. Abre en el navegador `http://localhost/<carpeta-del-proyecto>/`. Por ejemplo, si la carpeta que contiene `index.php` es `C:\xampp\htdocs\GeneraIA_Navegador`, la dirección es `http://localhost/GeneraIA_Navegador/`.

Si no se cargan imágenes adjuntas, revisa `upload_max_filesize` y `post_max_size` en `php.ini`; deben permitir una carga de al menos 5 MB más el encabezado del formulario. Reinicia Apache después de cambiar `php.ini`.

## Configuración

La aplicación lee las variables de un archivo `.env` ubicado junto a `api.php`. Parte de `.env.example` y cambia los valores que correspondan:

    OLLAMA_BASE_URL=http://127.0.0.1:11434
    OLLAMA_API_KEY=
    OLLAMA_CHAT_MODEL=qwen3.5:2b
    OLLAMA_VISION_MODEL=qwen3.5:2b
    OLLAMA_KEEP_ALIVE=30m
    OLLAMA_NUM_CTX=4096
    OLLAMA_NUM_PREDICT=512

    UNSPLASH_ACCESS_KEY=

    DB_HOST=127.0.0.1
    DB_NAME=generaia
    DB_USER=root
    DB_PASS=

| Variable | Para qué sirve |
| --- | --- |
| `OLLAMA_BASE_URL` | URL base del servicio Ollama, sin añadir `/api/chat`. En XAMPP suele ser `http://127.0.0.1:11434`. |
| `OLLAMA_API_KEY` | Token Bearer opcional para el endpoint de modelos si ese servicio lo exige. También es necesario para que la búsqueda web de Ollama funcione. |
| `OLLAMA_CHAT_MODEL` | Nombre exacto del modelo de chat disponible en el endpoint configurado. |
| `OLLAMA_VISION_MODEL` | Nombre exacto del modelo que analizará imágenes; debe admitir entrada visual. |
| `OLLAMA_KEEP_ALIVE` | Tiempo durante el que Ollama mantiene cargado el modelo; por defecto `30m`. |
| `OLLAMA_NUM_CTX` | Límite de contexto enviado al modelo; la aplicación lo acota entre 2048 y 8192. |
| `OLLAMA_NUM_PREDICT` | Límite de salida; la aplicación lo acota entre 128 y 1024 tokens. |
| `UNSPLASH_ACCESS_KEY` | Clave de una aplicación de Unsplash Developers. Es opcional porque Wikimedia Commons funciona como alternativa. |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Servidor, base, usuario y contraseña de MySQL. |

Usa el nombre de modelo que realmente ofrece tu servidor. Un modelo que no aparece en `ollama list` (o que el proveedor remoto no ofrece) producirá un error de modelo no encontrado. La aplicación utiliza el modelo configurado para chat o visión; no requiere una clave de OpenAI.

### Valores de MySQL

- **XAMPP local:** normalmente `DB_HOST=127.0.0.1`, `DB_USER=root`, `DB_PASS=`. Usa el nombre de la base que creaste.
- **Alwaysdata:** copia el host, nombre de base, usuario y contraseña tal como aparecen en el panel de Alwaysdata. No sustituyas el host por `127.0.0.1`.

## Búsqueda en internet

La búsqueda usa `https://ollama.com/api/web_search` y consulta hasta cuatro resultados para preparar la respuesta. La aplicación intenta activarla automáticamente al detectar palabras o preguntas relacionadas con actualidad, noticias, precios, clima, versiones, fechas/años, eventos o hechos que requieren comprobación. También puedes pedirla directamente, por ejemplo: “Busca en internet quién ganó…”.

Para habilitarla, configura `OLLAMA_API_KEY` en `.env` con una clave de Ollama válida. Si la clave falta o la búsqueda no está disponible, la aplicación lo informa; no debe tratarse una respuesta del modelo como verificada en ese caso. Si una pregunta necesita datos actuales y no activa la búsqueda, formula la petición explícitamente con “busca en internet”.

Por decisión de interfaz, la aplicación pide al modelo que no incluya fuentes, citas ni enlaces, y elimina una sección de fuentes si aparece al final de la respuesta. Esto no significa que todos los detalles hayan sido verificados; revisa por tu cuenta cualquier dato importante.

## Búsqueda de imágenes

Usa el botón **Buscar imagen** o escribe una solicitud como “muéstrame una foto de…”. La aplicación busca primero en Unsplash y, si no obtiene una imagen, intenta Wikimedia Commons. Para la búsqueda de Unsplash configura `UNSPLASH_ACCESS_KEY`; Wikimedia Commons no requiere una clave de aplicación. La disponibilidad y los resultados dependen de los servicios externos y de la descripción usada.

La respuesta presenta la imagen sin un bloque de créditos visible. Cuando los datos están disponibles, la atribución, el proveedor, la licencia y el enlace de la imagen pueden consultarse en **Ver créditos (opcional)**. Los requisitos de atribución de cada imagen siguen aplicando aunque el panel esté cerrado.

## Voz e imágenes adjuntas

- **Adjuntar:** carga una imagen JPG, PNG, WEBP o GIF de hasta 5 MB y escribe qué quieres saber. La imagen se envía al modelo de visión configurado.
- **Hablar:** usa el reconocimiento de voz del navegador para dictar el texto. El comportamiento y el tratamiento de voz dependen del navegador.
- **Respuesta de voz:** usa la voz instalada en el navegador/sistema operativo; no crea un archivo de audio en el servidor. Usa **Pausar diálogo** o **Detener voz** para controlarla.
- El dictado puede requerir HTTPS en el sitio publicado y permiso de micrófono. En desarrollo, `localhost` normalmente se considera un contexto seguro por los navegadores.

## Despliegue en Alwaysdata

### Preparar Alwaysdata

1. Crea y configura el sitio PHP en el panel de Alwaysdata.
2. Crea la base MySQL y un usuario, asígnale permisos, selecciona la base en phpMyAdmin e importa `schema.sql`.
3. Sube los archivos del proyecto a la carpeta raíz del sitio mediante SFTP. Comprueba que estén `index.php`, `api.php`, `assets/` y `.htaccess`.
4. Crea o sube `.env` en esa misma carpeta con los valores reales. `.env` está excluido de Git y no se copia mediante la automatización de GitHub.
5. Configura `OLLAMA_BASE_URL` con un servicio Ollama accesible desde Alwaysdata y los modelos disponibles en ese servicio. Allí `127.0.0.1` se refiere al propio servidor de Alwaysdata, no al computador personal.
6. Confirma que PHP pueda escribir en `media/`. Conserva `media/.htaccess` para impedir la ejecución de scripts en esa carpeta.
7. Revisa **Logs → PHP** y **Logs → Apache** en Alwaysdata si el sitio muestra un error.

### Despliegue automático con GitHub Actions

El flujo `.github/workflows/deployAlways.yml` se ejecuta cuando hay un `push` a la rama `main`. Copia `api.php`, `index.php`, `schema.sql`, `.htaccess` y `assets/` al sitio mediante SFTP por SSH en el puerto 22. No elimina archivos remotos. La base de datos se configura/importa por separado; el flujo no sube `.env` ni la carpeta `media/`.

Antes del primer despliegue:

1. En Alwaysdata, habilita SSH/SFTP para el usuario que usará el flujo y confirma el servidor, puerto, usuario y carpeta remota configurados en el archivo del workflow.
2. En el repositorio de GitHub, abre **Settings → Secrets and variables → Actions → New repository secret**.
3. Crea el secreto `ALWAYSDATA_SSH_PASSWORD` con la contraseña del usuario SSH/SFTP de Alwaysdata. No uses la contraseña de MySQL.
4. Conserva `.env` en el servidor y verifica sus valores. Sube también `media/.htaccess` y confirma permisos de escritura de `media/`.
5. Haz `push` a `main` y revisa **Actions** en GitHub para conocer el resultado.

No guardes contraseñas ni claves API en el workflow, el README, capturas de pantalla o el historial de Git. Si una credencial se publicó, cámbiala en el servicio correspondiente y actualiza el secreto de GitHub.

## Solución de problemas

| Síntoma | Qué revisar |
| --- | --- |
| Error MySQL `Connection refused` o `SQLSTATE[HY000] [2002]` | Confirma que MySQL está activo. En Alwaysdata usa el host MySQL mostrado en el panel; `127.0.0.1` apunta al propio alojamiento. |
| MySQL `Access denied` | Revisa `DB_NAME`, `DB_USER`, `DB_PASS` y los permisos del usuario sobre esa base. |
| GitHub Actions falla con `read ECONNRESET (data socket)` | Confirma que el workflow usa SFTP por el puerto 22, que SSH/SFTP está habilitado en Alwaysdata y que el host, usuario y secreto `ALWAYSDATA_SSH_PASSWORD` son correctos. El secreto debe contener la contraseña SSH/SFTP, no la de MySQL. Revisa el paso de SFTP que falló en **Actions**. |
| No existe una tabla o la base está vacía | Selecciona la base correcta en phpMyAdmin e importa `schema.sql`. |
| Ollama devuelve `Connection refused` | Comprueba que el servicio está activo y que `OLLAMA_BASE_URL` es accesible desde el servidor PHP. En Alwaysdata no puedes usar la dirección local de tu PC. |
| Respuesta `Unauthorized` | Revisa el token Bearer configurado en `OLLAMA_API_KEY` y confirma que corresponda al endpoint de modelos. Para la búsqueda web también debe ser válido para Ollama. |
| Modelo no encontrado o retirado | Configura en `OLLAMA_CHAT_MODEL` y `OLLAMA_VISION_MODEL` nombres que existan en el servicio conectado y que admitan las tareas requeridas. |
| No busca en internet | Configura `OLLAMA_API_KEY`, pide explícitamente “busca en internet” y revisa los registros PHP para el error del proveedor. |
| No encuentra una imagen | Prueba una descripción más concreta; revisa la clave de Unsplash, la conexión saliente del servidor y los registros PHP. Wikimedia Commons es la alternativa automática. |
| No carga CSS o JavaScript | Confirma que se subió completa la carpeta `assets/` y recarga la página sin usar la caché del navegador. |
| No se guarda una imagen adjunta | Revisa permisos de escritura para PHP en `media/` y los límites `upload_max_filesize` / `post_max_size`. |
| Dictado o lectura en voz alta no funciona | Usa Chrome o Edge actualizado, concede permiso de micrófono y comprueba que el sistema tenga una voz en español. |

En XAMPP, el registro de Apache suele estar en `C:\xampp\apache\logs\error.log`; también puedes revisar los registros PHP configurados en `php.ini`. En Alwaysdata, consulta los registros PHP/Apache desde el panel y comparte solo las líneas pertinentes, ocultando claves, contraseñas y datos personales.

## Privacidad y seguridad

- `.env` y `config.local.php` están excluidos de Git; el `.htaccess` raíz también bloquea el acceso web a archivos de configuración y al esquema SQL. Mantén esas protecciones en el servidor.
- Los mensajes y las imágenes adjuntas se guardan en MySQL/`media/` asociados a la sesión del navegador. El modelo recibe solo mensajes recientes de la conversación actual, no respuestas recuperadas de otros chats como evidencia.
- Las imágenes adjuntas se envían al endpoint Ollama configurado. Las consultas de búsqueda web se envían a Ollama; las consultas de imágenes se envían a Unsplash y/o Wikimedia Commons. El navegador puede gestionar el dictado con su propio servicio de reconocimiento.
- Los proveedores externos aplican sus propias condiciones y políticas de tratamiento. No incluyas contraseñas, claves ni información sensible en las preguntas.
- No publiques el puerto de Ollama en internet sin proteger el acceso y el transporte.

