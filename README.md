# GeneraIA Navegador

Asistente web en español con PHP, JavaScript, MySQL y modelos locales. Usa Ollama para conversar y entender imágenes; no necesita una clave de API de OpenAI.

## Funciones

- Chat en español con memoria de conversación guardada en MySQL.
- Comprensión de imágenes adjuntas con el modelo multimodal Qwen 3.5.
- Dictado por voz desde Chrome o Edge.
- Respuestas habladas con controles para pausar, reanudar y detener la voz del navegador.
- Búsqueda de imágenes mediante Unsplash; muestra el primer resultado con atribución al fotógrafo.
- Historial por sesión. El modelo usa solo mensajes recientes del chat actual y no recibe respuestas recuperadas de otras conversaciones.

## Requisitos

- XAMPP con Apache, MySQL y PHP 8.x.
- Extensiones PHP: curl, pdo_mysql, mbstring, fileinfo y gd.
- Ollama instalado y activo en Windows.
- Para buscar imágenes: una aplicación de desarrollador de Unsplash y su Access Key.
- Chrome o Edge actualizado para dictado y voz.

## Instalación

1. Copia la carpeta del proyecto a C:\xampp\htdocs\GeneraIA_Navegador.
2. Inicia Apache y MySQL desde el panel de XAMPP.
3. Importa schema.sql en phpMyAdmin.
4. Ollama debe estar activo en http://127.0.0.1:11434. El modelo qwen3.5:2b ya está instalado en la máquina usada para este proyecto. Si no aparece en Ollama, abre PowerShell y ejecuta:

    ollama pull qwen3.5:2b

5. Copia .env.example como .env en la carpeta principal del proyecto. Conserva los valores DB_* que correspondan a tu instalación de MySQL.
6. Abre http://localhost/GeneraIA_Navegador/.

El chat y la comprensión de imágenes adjuntas funcionan con Ollama. Las conversaciones siguen guardándose en MySQL.

## Búsqueda de imágenes

Al pedir una imagen, la aplicación detecta la intención, extrae la consulta, busca una foto relevante con la API de Unsplash y muestra la URL del primer resultado. Configura UNSPLASH_ACCESS_KEY en el archivo .env. Se conserva la URL CDN de Unsplash y se muestra la atribución al fotógrafo.

Unsplash limita inicialmente las aplicaciones en modo demo a 50 solicitudes por hora. Consulta la documentación oficial para conocer los requisitos y límites vigentes.
## Voz

El botón Hablar usa el reconocimiento de voz del navegador y envía el texto reconocido a Ollama. Mientras la respuesta se lee en voz alta, usa Pausar diálogo para pausar o reanudar, y Detener voz para terminar la lectura. El procesamiento del dictado depende del navegador y de su configuración; no es un modelo de transcripción de Ollama.

La casilla Respuesta de voz usa SpeechSynthesis del navegador/sistema operativo para leer en español la respuesta de Ollama. Las voces disponibles dependen de Windows y del navegador. No se genera un archivo MP3 en el servidor.

## Configuración

Las variables principales de .env son:

- OLLAMA_BASE_URL: dirección del servidor Ollama, inicialmente http://127.0.0.1:11434.
- OLLAMA_CHAT_MODEL: modelo para chat, inicialmente qwen3.5:2b. Un modelo local pequeño puede equivocarse; el prompt y la temperatura se ajustaron para favorecer respuestas directas y consistentes.
- OLLAMA_VISION_MODEL: modelo para analizar imágenes, inicialmente qwen3.5:2b.
- OLLAMA_KEEP_ALIVE: tiempo que Ollama conserva el modelo cargado; el valor inicial es 30m para evitar recargas frecuentes.
- OLLAMA_NUM_CTX y OLLAMA_NUM_PREDICT: límites de contexto y longitud de respuesta para reducir uso de memoria y demora.

La aplicación desactiva el razonamiento extendido y usa una temperatura conservadora. El modelo recibe solo la conversación actual; las respuestas anteriores no se tratan como fuentes y no se inyectan recuerdos de otros chats. Esto reduce respuestas arrastradas, aunque no garantiza que un modelo local pequeño siempre acierte.
- UNSPLASH_ACCESS_KEY: Access Key de tu aplicación registrada en Unsplash Developers.
- DB_HOST, DB_NAME, DB_USER y DB_PASS: conexión a MySQL.

No se requiere OPENAI_API_KEY. El chat funciona con Ollama local; la búsqueda de imágenes requiere una Access Key de Unsplash.

## Solución de problemas

- Chat sin respuesta: inicia Ollama y confirma que el modelo configurado aparece al ejecutar ollama list.
- Modelo no encontrado: ejecuta ollama pull qwen3.5:2b o corrige OLLAMA_CHAT_MODEL en .env.
- No analiza imágenes: usa un modelo con visión en OLLAMA_VISION_MODEL. Qwen 3.5 2B admite entrada de imágenes.
- No aparecen imágenes: configura UNSPLASH_ACCESS_KEY en .env y revisa los límites de tu aplicación de Unsplash.
- No funciona el dictado: concede permiso para el micrófono y usa Chrome o Edge.
- No se oye la respuesta: habilita una voz en español en la configuración de Windows y verifica que Respuesta de voz esté marcada.
- Error MySQL: inicia MySQL, importa schema.sql y confirma los valores DB_*.

## Privacidad

Los mensajes y las imágenes adjuntas se envían al servidor local de Ollama. Las búsquedas de imágenes envían la consulta a Unsplash y muestran directamente la imagen CDN de Unsplash con la atribución correspondiente. El dictado usa el reconocimiento de voz integrado en el navegador, cuyo tratamiento puede depender del proveedor del navegador. Las conversaciones se guardan en MySQL asociadas a la sesión del navegador.


## Despliegue en Alwaysdata

1. Crea un sitio de tipo PHP en el panel y apunta la raíz a la carpeta del proyecto. Añade el dominio en el sitio y configura su DNS.
2. Crea la base y el usuario MySQL en Alwaysdata, asigna permisos al usuario y selecciona esa base en phpMyAdmin antes de importar schema.sql. El archivo contiene las tablas y no crea una base aparte.
3. Sube .env con los valores reales de la base y la clave de Unsplash. No publiques ese archivo; .env.example contiene solo marcadores.
4. Configura OLLAMA_BASE_URL con un endpoint Ollama compatible accesible desde Alwaysdata. 127.0.0.1 apunta al servidor de Alwaysdata, no a tu computador. Si el endpoint requiere un token Bearer, define OLLAMA_API_KEY.
5. Asegura que PHP pueda escribir en media/. Para usar el chat, el endpoint remoto debe tener disponibles los modelos indicados en OLLAMA_CHAT_MODEL y OLLAMA_VISION_MODEL.
