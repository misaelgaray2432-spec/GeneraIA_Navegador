# GeneraIA Navegador

Asistente web en español alojado en Alwaysdata. Está construido con PHP, JavaScript y MySQL, y se conecta a Ollama para conversar, analizar imágenes y consultar la web.

## Abrir la aplicación

[**Abrir GeneraIA en el navegador**](https://misaelgaray.alwaysdata.net/)

Este enlace usa el dominio predeterminado de la cuenta Alwaysdata. Si configuraste un dominio propio en **Web → Sites**, abre el sitio con ese dominio.

## Funciones

- Conversación en español con un modelo compatible con la API de chat de Ollama.
- Historial guardado en MySQL y asociado a la sesión del navegador.
- Análisis de imágenes adjuntas JPG, PNG, WEBP o GIF de hasta 5 MB, con un modelo que admita visión.
- Búsqueda web para preguntas recientes o cuando se solicita explícitamente “Busca en internet…”.
- Búsqueda de fotografías con Unsplash y Wikimedia Commons como alternativa.
- Créditos de imagen disponibles en un panel opcional.
- Dictado y lectura de respuestas mediante las funciones de voz del navegador.
- Opción para detener una búsqueda o respuesta en curso.

La búsqueda de imágenes encuentra fotografías existentes; no genera imágenes nuevas. La búsqueda web ayuda a responder preguntas actuales, pero no garantiza información completa ni infalible.

## Requisitos del sitio

- Un sitio PHP 8 o posterior en Alwaysdata con las extensiones **curl**, **mbstring** y **pdo_mysql**.
- Una base MySQL creada en Alwaysdata.
- Un endpoint Ollama accesible desde el servidor de Alwaysdata y los modelos configurados disponibles en ese endpoint.
- Para la búsqueda web, una clave Ollama válida como token Bearer.
- Para la búsqueda primaria de imágenes, una Access Key de Unsplash. Si no está configurada o Unsplash falla, la aplicación intenta usar Wikimedia Commons.
- Permiso de escritura de PHP en **media/**.
- Chrome o Edge actualizado para dictado por voz y permiso del micrófono. El sitio publicado debe usar HTTPS para las funciones de voz que lo requieran.

## Configurar Alwaysdata

1. En **Web → Sites**, crea o revisa el sitio PHP y confirma que su carpeta raíz contiene **index.php**, **api.php**, **assets/** y **.htaccess**.
2. En **Databases → MySQL**, crea la base de datos y el usuario, y concede al usuario acceso a esa base.
3. Abre phpMyAdmin desde Alwaysdata, selecciona la base correcta e importa **schema.sql**. Este archivo crea las tablas **conversations** y **messages**; no crea la base de datos.
4. Sube el archivo **.env** a la misma carpeta que **api.php**. Parte de **.env.example** y completa los valores reales. **.env** no se publica en GitHub.
5. Asegúrate de que PHP pueda escribir en **media/**. Sube y conserva **media/.htaccess** para bloquear la ejecución de scripts dentro de esa carpeta.
6. Abre la aplicación con el enlace de arriba. Si el sitio usa otro dominio, utiliza la dirección declarada para ese sitio en **Web → Sites**.

### Variables de configuración

El archivo **.env** debe quedar junto a **api.php**. Usa estos valores como guía y reemplaza los marcadores por los datos de tu cuenta:

    OLLAMA_BASE_URL=https://ollama.com
    OLLAMA_API_KEY=TU_TOKEN_OLLAMA
    OLLAMA_CHAT_MODEL=MODELO_DE_CHAT_DISPONIBLE
    OLLAMA_VISION_MODEL=MODELO_CON_VISION_DISPONIBLE
    OLLAMA_KEEP_ALIVE=30m
    OLLAMA_NUM_CTX=4096
    OLLAMA_NUM_PREDICT=512

    UNSPLASH_ACCESS_KEY=TU_ACCESS_KEY_DE_UNSPLASH

    DB_HOST=mysql-misaelgaray.alwaysdata.net
    DB_NAME=misaelgaray_generaia
    DB_USER=TU_USUARIO_MYSQL
    DB_PASS=TU_CONTRASENA_MYSQL

| Variable | Uso |
| --- | --- |
| **OLLAMA_BASE_URL** | Dirección base del servicio Ollama accesible desde Alwaysdata. Con Ollama Cloud normalmente es https://ollama.com; no uses 127.0.0.1 para un Ollama que corre en tu computador. |
| **OLLAMA_API_KEY** | Token Bearer del servicio Ollama. Se usa para autenticar el endpoint de modelos y es necesaria para la búsqueda web de Ollama. |
| **OLLAMA_CHAT_MODEL** | Nombre exacto de un modelo disponible para conversar. |
| **OLLAMA_VISION_MODEL** | Nombre exacto de un modelo disponible que acepte imágenes. |
| **OLLAMA_KEEP_ALIVE** | Tiempo que Ollama mantiene cargado el modelo; valor inicial 30m. |
| **OLLAMA_NUM_CTX / OLLAMA_NUM_PREDICT** | Límites de contexto y generación enviados al modelo. |
| **UNSPLASH_ACCESS_KEY** | Access Key de Unsplash Developers. Es opcional; Wikimedia Commons se usa como alternativa. |
| **DB_HOST, DB_NAME, DB_USER, DB_PASS** | Host, nombre de la base, usuario y contraseña MySQL indicados en Alwaysdata. El nombre de base de esta instalación es misaelgaray_generaia. |

Cambia los nombres de modelo de ejemplo por modelos que estén disponibles en tu servicio Ollama. Si un modelo no está habilitado allí, el sitio mostrará un error aunque el nombre esté escrito correctamente.

### Búsqueda web

La aplicación consulta https://ollama.com/api/web_search cuando detecta preguntas de actualidad, fechas, eventos y otros hechos que requieren comprobación; también puedes solicitarla directamente escribiendo “Busca en internet…”. Se consultan hasta cuatro resultados para preparar la respuesta.

El modelo recibe instrucciones de no incluir listas de fuentes, citas ni enlaces. Si la búsqueda no está disponible, la aplicación debe indicarlo; verifica siempre los datos importantes.

### Búsqueda de imágenes

Usa el botón **Buscar imagen** o pide una foto describiendo el tema. La aplicación intenta primero con Unsplash y, si no obtiene resultados, recurre a Wikimedia Commons. Los datos de autoría, licencia y proveedor se muestran en **Ver créditos (opcional)** cuando están disponibles. Aunque el panel esté cerrado, respeta las condiciones de uso y atribución aplicables a cada imagen.

## Despliegue automático desde GitHub

El workflow **.github/workflows/deployAlways.yml** publica el código en Alwaysdata al hacer un push a la rama **main**. Usa SFTP por SSH en el puerto 22 y no elimina archivos remotos.

El workflow despliega **api.php**, **index.php**, **schema.sql**, **.htaccess** y **assets/**. No sube **.env** ni **media/**; configura **.env** en el servidor y conserva allí los archivos de **media/**.

Para habilitarlo:

1. En Alwaysdata, habilita SSH/SFTP para el usuario de despliegue.
2. En el repositorio de GitHub, abre **Settings → Secrets and variables → Actions → New repository secret**.
3. Crea el secreto **ALWAYSDATA_SSH_PASSWORD** con la contraseña SSH/SFTP de Alwaysdata. No uses la contraseña MySQL.
4. Confirma en el workflow el servidor, usuario, puerto y carpeta remota.
5. Haz push a **main** y revisa el resultado en **Actions**.

No guardes credenciales en el workflow, el README ni el historial de Git. Si una contraseña o clave se publicó, cámbiala y actualiza el secreto de GitHub.

## Solución de problemas

| Error o síntoma | Qué revisar |
| --- | --- |
| MySQL Connection refused o SQLSTATE[HY000] [2002] | Revisa que la base esté activa y que DB_HOST coincida exactamente con el host MySQL de Alwaysdata. |
| MySQL Access denied | Comprueba DB_NAME, DB_USER, DB_PASS y que el usuario tenga permisos sobre esa base. |
| Falta una tabla | Selecciona misaelgaray_generaia en phpMyAdmin e importa schema.sql. |
| Ollama Connection refused | Confirma que OLLAMA_BASE_URL sea accesible desde Alwaysdata. Una dirección local como 127.0.0.1 apunta al propio servidor de Alwaysdata, no a tu computador. |
| Unauthorized | Revisa OLLAMA_API_KEY y confirma que el token corresponda al servicio configurado. |
| Modelo no encontrado o retirado | Cambia OLLAMA_CHAT_MODEL o OLLAMA_VISION_MODEL por nombres disponibles en ese servicio. |
| No funciona la búsqueda web | Configura una clave Ollama válida en OLLAMA_API_KEY, pide “Busca en internet…” y revisa los registros PHP. |
| No aparecen imágenes | Revisa UNSPLASH_ACCESS_KEY, intenta una descripción más específica y comprueba la conexión saliente; Wikimedia Commons es el respaldo automático. |
| GitHub Actions muestra read ECONNRESET (data socket) | Confirma que el workflow use SFTP por el puerto 22, SSH/SFTP esté habilitado y el secreto ALWAYSDATA_SSH_PASSWORD contenga la contraseña SSH/SFTP correcta. Revisa qué paso falló en Actions. |
| No se guardan imágenes adjuntas | Comprueba los permisos de escritura de PHP en media/ y los límites de carga configurados para el sitio. |
| No funciona dictado o lectura de voz | Usa Chrome o Edge actualizado, permite el micrófono y comprueba que el navegador/sistema tenga una voz en español. |

Los registros se consultan desde **Logs → PHP** y **Logs → Apache** en el panel de Alwaysdata. Al compartir errores, copia solo las líneas pertinentes y oculta contraseñas, claves y datos personales.

## Privacidad y seguridad

- **.env** y **config.local.php** están excluidos de Git. El **.htaccess** raíz bloquea el acceso web a archivos de configuración y al esquema SQL.
- Los mensajes e imágenes adjuntas se guardan en MySQL y **media/** asociados a la sesión del navegador. El modelo recibe mensajes recientes de la conversación actual.
- Las imágenes adjuntas se envían al endpoint Ollama configurado. Las consultas web se envían a Ollama y las consultas de imágenes a Unsplash y/o Wikimedia Commons. El tratamiento del dictado depende del navegador.
- Los proveedores externos aplican sus propias condiciones y políticas. No incluyas contraseñas, claves ni información sensible en los mensajes.
- No expongas un endpoint Ollama a internet sin proteger la autenticación y la conexión.

