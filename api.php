<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function cancel_request_path(string $requestId): ?string {
    if (!preg_match('/^[a-f0-9]{32}$/i', $requestId)) return null;
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generia_cancel_' . strtolower($requestId) . '.flag';
}
function cancellation_curl_options(?string $requestId): array {
    $path = cancel_request_path((string)$requestId);
    if ($path === null) return [];
    return [CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($path): int { return is_file($path) ? 1 : 0; }];
}
function consume_cancel_flag(?string $requestId): bool {
    $path = cancel_request_path((string)$requestId);
    if ($path === null || !is_file($path)) return false;
    @unlink($path); return true;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $requestId = trim((string)($_POST['request_id'] ?? ''));
    $path = cancel_request_path($requestId);
    if ($path === null) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Solicitud no válida.'], JSON_UNESCAPED_UNICODE); exit; }
    if (file_put_contents($path, 'stop', LOCK_EX) === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo detener la solicitud.'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok'=>true]); exit;
}
session_start();
@set_time_limit(660);

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function load_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $values = [];
    $path = __DIR__ . '/.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), " \t\n\r\0\x0B\"'");
        }
    }
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $localValues = require $local;
        if (is_array($localValues)) $values = array_merge($values, $localValues);
    }
    $config = [
        'ollama_url' => rtrim(getenv('OLLAMA_BASE_URL') ?: ($values['OLLAMA_BASE_URL'] ?? 'http://127.0.0.1:11434'), '/'),
        'ollama_api_key' => getenv('OLLAMA_API_KEY') ?: ($values['OLLAMA_API_KEY'] ?? ''),
        'chat_model' => getenv('OLLAMA_CHAT_MODEL') ?: ($values['OLLAMA_CHAT_MODEL'] ?? 'qwen3.5:2b'),
        'vision_model' => getenv('OLLAMA_VISION_MODEL') ?: ($values['OLLAMA_VISION_MODEL'] ?? 'qwen3.5:2b'),
        'quality_model' => getenv('OLLAMA_QUALITY_MODEL') ?: ($values['OLLAMA_QUALITY_MODEL'] ?? 'qwen3.5:4b'),
        'ollama_keep_alive' => getenv('OLLAMA_KEEP_ALIVE') ?: ($values['OLLAMA_KEEP_ALIVE'] ?? '30m'),
        'ollama_num_ctx' => max(2048, min(8192, (int)(getenv('OLLAMA_NUM_CTX') ?: ($values['OLLAMA_NUM_CTX'] ?? 4096)))),
        'ollama_num_predict' => max(128, min(1024, (int)(getenv('OLLAMA_NUM_PREDICT') ?: ($values['OLLAMA_NUM_PREDICT'] ?? 512)))),
        'unsplash_access_key' => getenv('UNSPLASH_ACCESS_KEY') ?: ($values['UNSPLASH_ACCESS_KEY'] ?? ''),
        'db_host' => getenv('DB_HOST') ?: ($values['DB_HOST'] ?? '127.0.0.1'),
        'db_name' => getenv('DB_NAME') ?: ($values['DB_NAME'] ?? 'generaia'),
        'db_user' => getenv('DB_USER') ?: ($values['DB_USER'] ?? 'root'),
        'db_pass' => getenv('DB_PASS') ?: ($values['DB_PASS'] ?? ''),
    ];
    return $config;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = load_config();
    $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
function ollama_request_headers(): array {
    $apiKey = trim((string)load_config()['ollama_api_key']);
    return $apiKey === '' ? [] : ['Authorization: Bearer ' . $apiKey];
}
function request_json(string $url, ?array $payload = null, int $timeout = 180, ?string $requestId = null, array $headers = []): array {
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $headers = array_merge(['Content-Type: application/json'], $headers);
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }
    if ($headers !== []) $options[CURLOPT_HTTPHEADER] = $headers;
    $options += cancellation_curl_options($requestId);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cancelled = consume_cancel_flag($requestId);
    curl_close($ch);
    if ($cancelled) throw new RuntimeException('Solicitud detenida por el usuario.');
    if ($raw === false) throw new RuntimeException('No se pudo conectar con el servicio local ' . $url . ': ' . $curlError);
    $data = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = $data['error'] ?? '';
        $message = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
        throw new RuntimeException($message !== '' ? $message : ('Error HTTP ' . $httpCode . ' al llamar al servicio local.'));
    }
    if (!is_array($data)) throw new RuntimeException('El servicio local devolvio una respuesta inesperada.');
    return $data;
}
function ollama_model_available(string $model): bool {
    static $models = null;
    if ($models === null) {
        try {
            $list = request_json(load_config()['ollama_url'] . '/api/tags', null, 10, null, ollama_request_headers());
            $models = array_map(static fn($entry) => (string)($entry['name'] ?? $entry['model'] ?? ''), $list['models'] ?? []);
        } catch (Throwable $e) {
            $models = [];
        }
    }
    return in_array($model, $models, true);
}
function preferred_model(string $fallback): string {
    $preferred = load_config()['quality_model'];
    return $preferred !== '' && ollama_model_available($preferred) ? $preferred : $fallback;
}
function extract_image_search_query(string $prompt): string {
    $query = mb_strtolower(trim($prompt), 'UTF-8');
    $query = strtr($query, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $query = preg_replace('/[¿?¡!.,;:]+/u', ' ', $query) ?? $query;
    $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
    $patterns = [
        '/^(?:por favor|porfa)\s+/u',
        '/^(?:(?:me\s+)?(?:puedes|podrias)|quiero|quisiera|necesito|dame|darias|muestrame|muestra|mostrarme|mostrar|ensename|busca|buscame|buscar|encuentra|encontrar|generame|genera|generar|creame|crea|crear|hazme|haz|dibuja|dibujame|ilustra|pinta|ver|show me|find|search for|create|generate|show|draw)\s+/u',
        '/^(?:(?:una|un|unas|unos|algunas|algunos|la|el|las|los)\s+)*(?:imagenes?|images?|fotos?|photos?|photographs?|pictures?|fotografias?|dibujos?|drawings?|ilustraciones?|illustrations?|retratos?|portraits?|posters?|wallpapers?|fondos?\s+de\s+pantalla)(?:\s+(?:de|del|sobre|con|of|about)\s+)?/u',
        '/^(?:de|del|sobre|con|of|about|the|a|an)\s+/u',
        '/^(?:una|un|unas|unos|la|el|las|los|the|a|an)\s+/u',
    ];
    for ($pass = 0; $pass < 4; $pass++) {
        foreach ($patterns as $pattern) $query = preg_replace($pattern, '', $query) ?? $query;
    }
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    if ($query === '') $query = trim(mb_strtolower($prompt, 'UTF-8'));
    if ($query === '') throw new RuntimeException('Escribe qué imagen quieres buscar.');
    return mb_substr($query, 0, 200, 'UTF-8');
}
function unsplash_referral_url(string $url): string {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'unsplash.com') $url = 'https://unsplash.com/';
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'utm_source=generai_navegador&utm_medium=referral';
}
function unsplash_image_search(string $prompt, ?string $requestId = null): array {
    $accessKey = trim((string)load_config()['unsplash_access_key']);
    if ($accessKey === '') throw new RuntimeException('Falta UNSPLASH_ACCESS_KEY en .env para buscar imágenes.');
    $query = extract_image_search_query($prompt);
    $sessionCache = $_SESSION['unsplash_image_cache'] ?? [];
    if (!is_array($sessionCache)) $sessionCache = [];
    $now = time();
    foreach ($sessionCache as $cacheItemKey => $cacheItem) {
        if (!is_array($cacheItem) || (int)($cacheItem['expires'] ?? 0) <= $now || !is_array($cacheItem['result'] ?? null)) unset($sessionCache[$cacheItemKey]);
    }
    $cacheKey = hash('sha256', $query);
    if (isset($sessionCache[$cacheKey])) return $sessionCache[$cacheKey]['result'];
    $url = 'https://api.unsplash.com/search/photos?' . http_build_query(['query'=>$query,'page'=>1,'per_page'=>1,'order_by'=>'relevant','content_filter'=>'high'], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('No se pudo iniciar la solicitud de búsqueda de Unsplash.');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($requestId) { $path = cancel_request_path((string)$requestId); return $path !== null && is_file($path) ? 1 : 0; },CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Version: v1','Authorization: Client-ID ' . $accessKey]]);
    $raw = curl_exec($ch);$curlError = curl_error($ch);$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);$cancelled = consume_cancel_flag($requestId);curl_close($ch);if ($cancelled) throw new RuntimeException('Solicitud detenida por el usuario.');
    if ($raw === false) throw new RuntimeException('No se pudo conectar con Unsplash: ' . $curlError);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Unsplash devolvió una respuesta no válida.');
    if ($status < 200 || $status >= 300) {
        error_log('GeneraIA Unsplash image search failed: HTTP ' . $status);
        if ($status === 429) throw new RuntimeException('Unsplash alcanzó el límite de solicitudes. Intenta de nuevo más tarde.');
        if (in_array($status, [401,403], true)) throw new RuntimeException('Unsplash rechazó la solicitud. Verifica UNSPLASH_ACCESS_KEY y el estado de tu aplicación.');
        throw new RuntimeException('Unsplash respondió con HTTP ' . $status . '.');
    }
    $photo = $data['results'][0] ?? null;
    if (!is_array($photo)) throw new RuntimeException('Unsplash no encontró imágenes para «' . $query . '».');
    $imageUrl = trim((string)($photo['urls']['regular'] ?? ''));$parts = parse_url($imageUrl);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'images.unsplash.com') throw new RuntimeException('El primer resultado de Unsplash no contiene una URL de imagen válida.');
    $photographer = trim((string)($photo['user']['name'] ?? ''));if ($photographer === '') $photographer = 'Fotógrafo de Unsplash';
    $profileUrl = (string)($photo['user']['links']['html'] ?? 'https://unsplash.com/');
    $photoUrl = (string)($photo['links']['html'] ?? 'https://unsplash.com/');
    $result = ['image_url'=>$imageUrl,'query'=>$query,'title'=>trim((string)($photo['alt_description'] ?? $photo['description'] ?? '')),'photographer'=>$photographer,'photographer_url'=>unsplash_referral_url($profileUrl),'unsplash_url'=>unsplash_referral_url('https://unsplash.com/'),'photo_url'=>unsplash_referral_url($photoUrl)];
    $sessionCache[$cacheKey] = ['expires'=>$now + 900,'result'=>$result];
    if (count($sessionCache) > 12) $sessionCache = array_slice($sessionCache, -12, null, true);
    $_SESSION['unsplash_image_cache'] = $sessionCache;
    return $result;
}
function current_conversation(PDO $pdo, string $requestedId): string {
    $sessionKey = session_id();
    $id = preg_match('/^[a-f0-9]{32}$/', $requestedId) ? $requestedId : '';
    if ($id !== '') {
        $stmt = $pdo->prepare('SELECT id FROM conversations WHERE id = ? AND session_key = ? LIMIT 1');
        $stmt->execute([$id, $sessionKey]);
        if ($stmt->fetch()) { $_SESSION['generia_conversation_id'] = $id; return $id; }
    }
    $sessionId = $_SESSION['generia_conversation_id'] ?? '';
    if ($sessionId) {
        $stmt = $pdo->prepare('SELECT id FROM conversations WHERE id = ? AND session_key = ? LIMIT 1');
        $stmt->execute([$sessionId, $sessionKey]);
        if ($stmt->fetch()) return (string) $sessionId;
    }
    $id = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO conversations (id, session_key, title) VALUES (?, ?, ?)');
    $stmt->execute([$id, $sessionKey, 'Nueva conversación']);
    $_SESSION['generia_conversation_id'] = $id;
    return $id;
}
function update_title(PDO $pdo, string $id, string $message): void {
    $stmt = $pdo->prepare("UPDATE conversations SET title = CASE WHEN title = 'Nueva conversación' THEN ? ELSE title END, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $title = mb_substr(preg_replace('/\s+/u', ' ', trim($message)), 0, 72, 'UTF-8');
    $stmt->execute([$title !== '' ? $title : 'Conversación', $id]);
}
function save_message(PDO $pdo, string $conversationId, string $role, string $content, ?string $imagePath = null, ?string $audioPath = null): void {
    $stmt = $pdo->prepare('INSERT INTO messages (conversation_id, role, content, image_path, audio_path) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$conversationId, $role, $content, $imagePath, $audioPath]);
    $pdo->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$conversationId]);
}
function get_recent_messages(PDO $pdo, string $conversationId, int $limit = 10): array {
    $stmt = $pdo->prepare('SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . max(1, min(20, $limit)));
    $stmt->execute([$conversationId]); $rows = array_reverse($stmt->fetchAll());
    $out = [];
    foreach ($rows as $r) $out[] = ['role' => $r['role'], 'content' => $r['content']];
    return $out;
}
function saved_media(string $prefix, string $extension, string $binary): string {
    $dir = __DIR__ . '/media';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta media.');
    $name = $prefix . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
    if (file_put_contents($dir . '/' . $name, $binary, LOCK_EX) === false) throw new RuntimeException('No se pudo guardar el archivo generado.');
    return 'media/' . $name;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
$action = (string)($_POST['action'] ?? '');
$requestId = trim((string)($_POST['request_id'] ?? ''));
if (cancel_request_path($requestId) === null) $requestId = null;
try {
    $pdo = db();
    $conversationId = current_conversation($pdo, (string)($_POST['conversation_id'] ?? ''));
    if ($action === 'new_chat') {
        $id = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT INTO conversations (id, session_key, title) VALUES (?, ?, ?)');
        $stmt->execute([$id, session_id(), 'Nueva conversación']); $_SESSION['generia_conversation_id'] = $id;
        respond(['ok' => true, 'conversation_id' => $id]);
    }
    if ($action === 'history') {
        $stmt = $pdo->prepare('SELECT role, content, image_path, audio_path, created_at FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 200');
        $stmt->execute([$conversationId]);
        respond(['ok'=>true, 'conversation_id'=>$conversationId, 'messages'=>$stmt->fetchAll(), 'history_count'=>count_saved_answers($pdo, session_id())]);
    }
    if ($action === 'chat') {
        $message = trim((string)($_POST['message'] ?? ''));
        $image = $_FILES['image'] ?? null;
        if ($message === '' && (!$image || ($image['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) respond(['ok'=>false,'error'=>'Escribe una pregunta o adjunta una imagen.'], 422);
        if (mb_strlen($message, 'UTF-8') > 12000) respond(['ok'=>false,'error'=>'La pregunta es demasiado larga.'], 422);
        $imageBase64 = null;
        $imagePath = null;
        if ($image && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($image['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo cargar la imagen.');
            if ($image['size'] > 5 * 1024 * 1024) throw new RuntimeException('La imagen debe pesar maximo 5 MB.');
            $info = @getimagesize($image['tmp_name']);
            $mime = $info['mime'] ?? '';
            if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) throw new RuntimeException('Formato de imagen no compatible.');
            $imageBytes = (string)file_get_contents($image['tmp_name']);
            $imageBase64 = base64_encode($imageBytes);
            $extension = match($mime) { 'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif', default=>'img' };
            $imagePath = saved_media('upload', $extension, $imageBytes);
        }
        $userContent = $message !== '' ? $message : 'Describe esta imagen y dime que elementos identificas.';
        save_message($pdo, $conversationId, 'user', $userContent, $imagePath);
        update_title($pdo, $conversationId, $userContent);
        $recent = get_recent_messages($pdo, $conversationId, 6);
        $instructions = "Eres GeneraIA, un asistente en español. Responde primero a lo que pregunta el usuario en el último mensaje. Usa el historial solo para resolver referencias o continuar una tarea; las respuestas anteriores del asistente nunca son evidencia y pueden estar equivocadas. Prioriza exactitud y pertinencia: no inventes hechos, cifras, fechas, citas, fuentes, resultados ni pasos que no conozcas. Si una pregunta es ambigua, pide una aclaración breve; si no tienes certeza, dilo claramente y separa lo que sabes de lo que infieres. No afirmes que una ciudad, país o entidad no existe solo porque no la recuerdes; reconoce la incertidumbre o pide más contexto. Como dato geográfico fijo: Santiago de Cali sí existe y es la capital del Valle del Cauca, Colombia. Si la respuesta depende de información actual o no disponible en esta conversación, no la afirmes como verificada ni digas que buscaste en internet. Corrige los errores señalados por el usuario y responde en el idioma y formato que pidió. Sé conciso por defecto: responde en 1 a 3 frases o hasta 4 viñetas; evita introducciones, repeticiones y cierres genéricos. Amplía solo cuando el usuario pida detalle.";
        $messages = [['role'=>'system','content'=>$instructions]];
        foreach ($recent as $index => $row) {
            $entry = ['role'=>$row['role'],'content'=>$row['content']];
            if ($imageBase64 !== null && $row['role'] === 'user' && $row['content'] === $userContent && $index === count($recent)-1) {
                $entry['images'] = [$imageBase64];
            }
            $messages[] = $entry;
        }
        $config = load_config();
        $model = $imageBase64 !== null ? $config['vision_model'] : $config['chat_model'];
        $wantsDetail = preg_match('/\b(detallad[oa]s?|en detalle|paso a paso|a fondo|ampl[ií]a|profundiza|desarrolla|extens[oa]s?)\b/iu', $message) === 1;
        $response = request_json($config['ollama_url'] . '/api/chat', ['model'=>$model,'messages'=>$messages,'stream'=>false,'think'=>false,'keep_alive'=>($model === $config['quality_model'] ? '5m' : $config['ollama_keep_alive']),'options'=>['temperature'=>0.2,'top_p'=>0.85,'repeat_penalty'=>1.1,'num_ctx'=>$config['ollama_num_ctx'],'num_predict'=>$wantsDetail ? min(768, $config['ollama_num_predict']) : min(192, $config['ollama_num_predict'])]], 300, $requestId, ollama_request_headers());
        $answer = trim((string)($response['message']['content'] ?? ''));
        if ($answer === '') {
            error_log('GeneraIA empty Ollama response: model=' . $model . ' done_reason=' . (string)($response['done_reason'] ?? 'unknown') . ' eval_count=' . (int)($response['eval_count'] ?? 0));
            throw new RuntimeException('Ollama termino sin generar texto. Recarga la pagina e intenta de nuevo; si persiste, revisa el registro de Apache.');
        }
        save_message($pdo, $conversationId, 'assistant', $answer);
        respond(['ok'=>true,'conversation_id'=>$conversationId,'answer'=>$answer,'audio_url'=>null,'history_count'=>count_saved_answers($pdo, session_id())]);
    }
    if ($action === 'image') {
        $prompt = trim((string)($_POST['prompt'] ?? ''));
        if ($prompt === '') respond(['ok'=>false,'error'=>'Escribe una descripción para la imagen.'], 422);
        if (mb_strlen($prompt, 'UTF-8') > 3000) respond(['ok'=>false,'error'=>'La descripción de la imagen es demasiado larga.'], 422);
        save_message($pdo, $conversationId, 'user', 'Buscar imagen: ' . $prompt);
        update_title($pdo, $conversationId, 'Imagen: ' . $prompt);
        $search = unsplash_image_search($prompt, $requestId);
        $imageUrl = $search['image_url'];
        $captionPlain = 'Primer resultado de Unsplash para «' . $search['query'] . '».';
        if ($search['title'] !== '') $captionPlain .= ' ' . mb_substr($search['title'], 0, 140, 'UTF-8');
        $photographerName = trim(preg_replace('/[\[\]\(\)]/u', '', $search['photographer']) ?? '');
        if ($photographerName === '') $photographerName = 'Fotógrafo de Unsplash';
        $caption = $captionPlain . "\n\nFoto de [" . $photographerName . "](" . $search['photographer_url'] . ") en [Unsplash](" . $search['unsplash_url'] . ") · [ver foto](" . $search['photo_url'] . ").";
        $speechCaption = $captionPlain . ' Foto de ' . $search['photographer'] . ' en Unsplash.';
        $historyImageUrl = mb_strlen($imageUrl, 'UTF-8') <= 500 ? $imageUrl : null;
        save_message($pdo, $conversationId, 'assistant', $caption . "\nURL: " . $imageUrl, $historyImageUrl);
        respond(['ok'=>true,'conversation_id'=>$conversationId,'caption'=>$caption,'speech_caption'=>$speechCaption,'image_url'=>$imageUrl,'search_query'=>$search['query'],'history_count'=>count_saved_answers($pdo, session_id())]);
    }
    respond(['ok'=>false,'error'=>'Acción desconocida.'], 400);
} catch (PDOException $e) {
    error_log('GeneraIA database error: ' . $e->getMessage());
    respond(['ok'=>false,'error'=>'No se pudo acceder a MySQL. Revisa la base de datos generaia, el usuario, la contraseña e importa schema.sql.'], 500);
} catch (Throwable $e) {
    if ($e->getMessage() === 'Solicitud detenida por el usuario.') respond(['ok'=>false,'cancelled'=>true,'error'=>'Solicitud detenida.'], 409);
    error_log('GeneraIA error: ' . $e->getMessage());
    respond(['ok'=>false,'error'=>$e->getMessage()], 500);
}

function count_saved_answers(PDO $pdo, string $sessionKey): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.session_key=? AND m.role='assistant'");
    $stmt->execute([$sessionKey]); return (int)$stmt->fetchColumn();
}
