<?php
session_start();
$conversationId = $_SESSION['generia_conversation_id'] ?? '';
if (!$conversationId) {
    $conversationId = bin2hex(random_bytes(16));
    $_SESSION['generia_conversation_id'] = $conversationId;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101726">
  <title>MGM-IA — Asistente Generativo</title>
  <link rel="stylesheet" href="assets/styles.css?v=2.0.1">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="brand-lockup">
        <div class="brand-mark"><span class="brand-orbit"></span><span class="brand-core">✳</span></div>
        <div><div class="brand-name">Genera<span>IA</span></div><div class="brand-caption">ASISTENTE MULTIMODAL</div></div>
      </div>
      <button class="new-chat-button" id="newChatButton"><span class="plus">＋</span> Nueva conversación</button>
      <div class="side-section-title">TU ESPACIO</div>
      <div class="side-nav-item active"><span class="nav-icon">◉</span> Asistente <span class="nav-live"></span></div>
      <div class="side-nav-item" id="memoryNav"><span class="nav-icon">✧</span> Contexto de conversación</div>
      <div class="side-nav-item" id="historyNav"><span class="nav-icon">◷</span> Conversación actual</div>
      <div class="sidebar-spacer"></div>
      <div class="learning-card">
        <div class="learning-card-top"><span class="learning-pulse"></span><span>CONTEXTO DE CONVERSACIÓN</span></div>
        <div class="learning-title">Contexto reciente</div>
        <p>Usa solo mensajes recientes de esta conversación para reducir respuestas mezcladas de otros chats.</p>
        <div class="learning-footer"><span class="status-dot"></span><span id="memoryStatus">Usa solo esta conversación</span></div>
      </div>
      <div class="sidebar-footer"><span class="privacy-icon">▣</span><span>Tu clave de API se mantiene en el servidor.</span></div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="icon-button mobile-menu" id="menuButton" aria-label="Abrir menú">☰</button>
        <div class="topbar-context"><span class="context-dot"></span><span>Espacio personal</span><span class="topbar-slash">/</span><strong>Asistente IA</strong></div>
        <div class="topbar-actions"><span class="secure-chip"><span>♧</span> Sesión privada</span><button class="avatar-button" title="GeneraIA">G</button></div>
      </header>

      <section class="workspace">
        <div class="welcome" id="welcomePanel">
          <div class="welcome-badge"><span class="sparkle">✦</span> TU INTELIGENCIA ARTIFICIAL, GENERATIVA</div>
          <div class="welcome-emblem"><div class="emblem-ring ring-one"></div><div class="emblem-ring ring-two"></div><div class="emblem-spark">✳</div><div class="emblem-orbit orbit-a"></div><div class="emblem-orbit orbit-b"></div></div>
          <h1>Hola, ¿qué vamos a<br><span>crear hoy?</span></h1>
          <p class="welcome-copy">Pregunta lo que quieras. Puedo ayudarte con explicaciones, voz e imágenes; mantengo el contexto de este chat sin mezclar respuestas de otras conversaciones.</p>
          <div class="suggestion-grid">
            <button class="suggestion-card" data-prompt="Explícame qué es el machine learning con ejemplos sencillos."><span class="suggestion-icon icon-violet">✧</span><span><strong>Aprender algo nuevo</strong><small>Conceptos explicados paso a paso</small></span><span class="suggestion-arrow">↗</span></button>
            <button class="suggestion-card" data-prompt="Ayúdame a crear una aplicación web desde cero y organizar sus archivos."><span class="suggestion-icon icon-blue">⌘</span><span><strong>Crear un proyecto</strong><small>Ideas, código y soluciones</small></span><span class="suggestion-arrow">↗</span></button>
            <button class="suggestion-card" data-prompt="Dame cinco ideas creativas para una imagen futurista y descríbelas."><span class="suggestion-icon icon-pink">▧</span><span><strong>Desatar la creatividad</strong><small>Ideas y contenido visual</small></span><span class="suggestion-arrow">↗</span></button>
            <button class="suggestion-card" data-prompt="Diseña un plan de estudio de 30 días para aprender inteligencia artificial."><span class="suggestion-icon icon-gold">⌁</span><span><strong>Planear mi aprendizaje</strong><small>Objetivos y próximos pasos</small></span><span class="suggestion-arrow">↗</span></button>
          </div>
        </div>

        <div class="chat-stream hidden" id="chatStream" aria-live="polite"></div>
        <div class="working-indicator hidden" id="workingIndicator"><span class="typing-mark"><i></i><i></i><i></i></span><span id="workingText">GeneraIA está pensando…</span><button class="cancel-request-button" id="cancelRequestButton" type="button" aria-label="Detener búsqueda o respuesta">Detener</button></div>
      </section>

      <footer class="composer-area">
        <div class="attachment-preview hidden" id="attachmentPreview"><span class="attachment-label">Imagen adjunta</span><span id="attachmentName"></span><button id="removeAttachment" aria-label="Quitar imagen">×</button></div>
        <div class="composer-box">
          <textarea id="promptInput" rows="1" placeholder="Pregúntame cualquier cosa…" aria-label="Escribe tu pregunta"></textarea>
          <div class="composer-toolbar">
            <div class="composer-tools">
              <label class="tool-button" for="imageInput" title="Adjuntar una imagen"><span class="tool-symbol">＋</span><span>Adjuntar</span></label>
              <input class="visually-hidden" type="file" id="imageInput" accept="image/png,image/jpeg,image/webp,image/gif">
              <button class="tool-button" id="recordButton" title="Dictar una pregunta"><span class="tool-symbol">♩</span><span id="recordLabel">Hablar</span></button>
              <span class="toolbar-divider"></span>
              <label class="audio-toggle"><input type="checkbox" id="voiceToggle" checked><span class="toggle-ui"></span><span>Respuesta de voz</span></label><button class="speech-control hidden" id="speechControl" type="button" aria-label="Pausar diálogo" aria-pressed="false"><span id="speechControlLabel">Pausar diálogo</span></button><button class="speech-stop-button hidden" id="stopSpeechButton" type="button">Detener voz</button>
            </div>
            <div class="composer-actions"><button id="imageModeButton" class="image-mode-button" title="Buscar una imagen"><span>▧</span><span>Buscar imagen</span></button><button class="send-button" id="sendButton" aria-label="Enviar pregunta"><span>Enviar</span><span class="send-arrow">↑</span></button></div>
          </div>
        </div>
        <div class="composer-note"><span class="note-lock">♧</span> La IA puede equivocarse. Verifica los datos importantes. <span class="note-separator">·</span> Pide “busca imágenes de…” o “muéstrame una foto de…” o usa Buscar imagen.</div>
      </footer>
    </main>
  </div>
  <div class="toast" id="toast" role="status"></div>
  <div class="modal-backdrop hidden" id="infoModal"><div class="info-modal"><button class="modal-close" id="modalClose">×</button><div class="modal-kicker">GENERaIA · CONTEXTO</div><h2>¿Qué contexto usa GeneraIA?</h2><p>Para responder, GeneraIA usa la pregunta actual y los mensajes recientes de esta conversación. No añade respuestas de otros chats como si fueran fuentes, lo que ayuda a evitar que recuerdos antiguos desvíen la respuesta.</p><p>El modelo local puede equivocarse; las respuestas previas tampoco se consideran hechos garantizados. El historial de esta sesión se guarda en MySQL y no reentrena el modelo.</p><div class="modal-note"><span class="status-dot"></span> Contexto limitado a esta conversación</div><button class="primary-modal-button" id="modalGotIt">Entendido</button></div></div>
  <script>window.GENERAIA_CONFIG = { conversationId: <?= json_encode($conversationId) ?> };</script>
  <script src="assets/app.js?v=2.0.7" defer></script>
</body>
</html>
