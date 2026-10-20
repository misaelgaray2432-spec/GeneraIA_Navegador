(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const els = {
    prompt: $('promptInput'), send: $('sendButton'), imageMode: $('imageModeButton'), imageInput: $('imageInput'),
    attachmentPreview: $('attachmentPreview'), attachmentName: $('attachmentName'), removeAttachment: $('removeAttachment'),
    stream: $('chatStream'), welcome: $('welcomePanel'), working: $('workingIndicator'), workingText: $('workingText'),
    voiceToggle: $('voiceToggle'), speechControl: $('speechControl'), speechControlLabel: $('speechControlLabel'), stopSpeechButton: $('stopSpeechButton'), record: $('recordButton'), recordLabel: $('recordLabel'), toast: $('toast'), sidebar: $('sidebar'), cancelRequest: $('cancelRequestButton')
  };
  const state = { conversationId: window.GENERAIA_CONFIG?.conversationId || '', image: null, recording: false, recorder: null, chunks: [], busy: false, audioUrl: null, utterance: null, activeRequestId: null, activeAbortController: null };
  let toastTimer;

  function toast(message) {
    els.toast.textContent = message;
    els.toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove('show'), 3600);
  }
  async function api(action, formData) {
    const body = formData || new FormData();
    body.set('action', action);
    body.set('conversation_id', state.conversationId);
    if (state.activeRequestId) body.set('request_id', state.activeRequestId);
    const requestOptions = { method: 'POST', body, credentials: 'same-origin' };
    if (state.activeAbortController) requestOptions.signal = state.activeAbortController.signal;
    const response = await fetch('api.php', requestOptions);
    let data;
    try { data = await response.json(); } catch { throw new Error('El servidor devolvió una respuesta no válida. Revisa PHP y la configuración de XAMPP.'); }
    if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo completar la solicitud.');
    if (data.conversation_id) state.conversationId = data.conversation_id;
    return data;
  }
  function errorAdvice(message) {
    const detail = String(message || '').toLowerCase();
    if (/unsplash_access_key/.test(detail)) return 'Configura UNSPLASH_ACCESS_KEY en .env con la Access Key de tu aplicacion de Unsplash.';
    if (/ollama termino sin generar texto|ollama no devolvio texto/.test(detail)) return 'Ollama cerró la respuesta sin entregar texto. Inténtalo otra vez; si se repite, reinicia Ollama y revisa sus registros.';
    if (/127\.0\.0\.1:11434|ollama|connection refused|failed to connect/.test(detail)) return 'Inicia Ollama y comprueba que este escuchando en http://127.0.0.1:11434. Si falta el modelo, abre una terminal y ejecuta ollama pull qwen3.5:2b.';
    if (/model .* not found|model not found/.test(detail)) return 'Descarga el modelo configurado con ollama pull NOMBRE_DEL_MODELO o cambia OLLAMA_CHAT_MODEL en .env.';
    if (/mysql|database|base de datos|schema\.sql/.test(detail)) return 'Revisa que MySQL este activo, que se haya importado schema.sql y que los datos DB_* de .env sean correctos.';
    return 'Revisa el error anterior y el registro de PHP/Apache para mas detalles.';
  }
  function formatRequestError(title, error) {
    const message = error?.message || 'Error desconocido.';
    return `${title}\n\n${message}\n\n${errorAdvice(message)}`;
  }
  function setSpeechControls(active, paused = false) {
    els.speechControl.classList.toggle('hidden', !active);
    els.stopSpeechButton.classList.toggle('hidden', !active);
    els.speechControlLabel.textContent = paused ? 'Reanudar di\u00e1logo' : 'Pausar di\u00e1logo';
    els.speechControl.setAttribute('aria-pressed', paused ? 'true' : 'false');
  }
  function stopSpeech() {
    if (!('speechSynthesis' in window)) return;
    const synth = window.speechSynthesis;
    state.utterance = null;
    synth.cancel();
    setSpeechControls(false);
  }
  function speakText(text) {
    if (!('speechSynthesis' in window)) { toast('Este navegador no tiene voz sintetizada disponible.'); return; }
    const synth = window.speechSynthesis;
    const plain = String(text).replace(/\x60{3}[\s\S]*?\x60{3}/g, ' fragmento de c\u00f3digo ').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/[#>*_\x60]/g, '');
    stopSpeech();
    const utterance = new SpeechSynthesisUtterance(plain);
    utterance.lang = 'es-CO';
    const voices = synth.getVoices();
    utterance.voice = voices.find((voice) => voice.lang.toLowerCase() === 'es-co') || voices.find((voice) => voice.lang.toLowerCase().startsWith('es')) || null;
    state.utterance = utterance;
    const isCurrent = () => state.utterance === utterance;
    utterance.onstart = () => { if (isCurrent()) setSpeechControls(true, false); };
    utterance.onpause = () => { if (isCurrent()) setSpeechControls(true, true); };
    utterance.onresume = () => { if (isCurrent()) setSpeechControls(true, false); };
    utterance.onend = () => { if (isCurrent()) { state.utterance = null; setSpeechControls(false); } };
    utterance.onerror = () => { if (isCurrent()) { state.utterance = null; setSpeechControls(false); } };
    synth.speak(utterance);
  }
  function showChat() { els.welcome.classList.add('hidden'); els.stream.classList.remove('hidden'); }
  function makeRequestId() {
    const bytes = new Uint8Array(16);
    if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  }
  function showWorking(text) {
    state.activeRequestId = makeRequestId();
    state.activeAbortController = new AbortController();
    els.workingText.textContent = text || 'GeneraIA está pensando…';
    els.working.classList.remove('hidden');
    els.cancelRequest.disabled = false;
    els.cancelRequest.textContent = 'Detener';
    els.send.disabled = true;
    els.imageMode.disabled = true;
    state.busy = true;
  }
  function hideWorking() {
    els.working.classList.add('hidden');
    els.cancelRequest.disabled = false;
    els.cancelRequest.textContent = 'Detener';
    els.send.disabled = false;
    els.imageMode.disabled = false;
    state.busy = false;
    state.activeAbortController = null;
    state.activeRequestId = null;
  }
  function cancelCurrentRequest() {
    const requestId = state.activeRequestId;
    const controller = state.activeAbortController;
    if (!requestId || !controller || controller.signal.aborted) return;
    els.cancelRequest.disabled = true;
    els.cancelRequest.textContent = 'Deteniendo…';
    const body = new FormData();
    body.set('action', 'cancel');
    body.set('request_id', requestId);
    fetch('api.php', { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
    controller.abort();
  }
  function escapeHtml(value) { return String(value).replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  // Small Markdown renderer for model text; HTML is escaped before formatting.
  function markdown(text) {
    let safe = escapeHtml(text);
    const blocks = [];
    safe = safe.replace(/```([\w+-]*)\n([\s\S]*?)```/g, (_, lang, code) => { const key = `@@CODE${blocks.length}@@`; blocks.push(`<pre><code>${code.trim()}</code></pre>`); return key; });
    safe = safe.replace(/^### (.+)$/gm, '<h3>$1</h3>').replace(/^## (.+)$/gm, '<h2>$1</h2>').replace(/^# (.+)$/gm, '<h1>$1</h1>');
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>').replace(/`([^`]+)`/g, '<code>$1</code>');
    safe = safe.replace(/^\s*[-*] (.+)$/gm, '<li>$1</li>').replace(/(?:<li>.*<\/li>\n?)+/g, (m) => `<ul>${m}</ul>`);
    safe = safe.split(/\n{2,}/).map(part => /^@@CODE\d+@@$/.test(part.trim()) || /^<(h[1-3]|ul|pre|ol)/.test(part.trim()) ? part : `<p>${part.replace(/\n/g, '<br>')}</p>`).join('');
    safe = safe.replace(/@@CODE(\d+)@@/g, (_, i) => blocks[Number(i)] || '');
    safe = safe.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    return safe;
  }
  function appendMessage(role, text, options = {}) {
    showChat();
    const wrapper = document.createElement('article');
    wrapper.className = `message ${role}`;
    const avatar = document.createElement('div'); avatar.className = 'message-avatar'; avatar.textContent = role === 'assistant' ? '✳' : 'T';
    const content = document.createElement('div'); content.className = 'message-content';
    const label = document.createElement('div'); label.className = 'message-label'; label.textContent = role === 'assistant' ? 'GeneraIA' : 'Tú';
    const bubble = document.createElement('div'); bubble.className = 'message-bubble';
    if (role === 'assistant') bubble.innerHTML = markdown(text); else bubble.textContent = text;
    if (options.imageUrl) { const img = document.createElement('img'); img.src = options.imageUrl; img.alt = 'Imagen adjunta'; img.loading = 'lazy'; bubble.appendChild(img); }
    if (options.generatedImage) { const img = document.createElement('img'); img.src = options.generatedImage; img.alt = 'Resultado de Unsplash'; img.loading = 'lazy'; bubble.appendChild(img); const a = document.createElement('a'); a.className = 'mini-action'; a.href = options.generatedImage; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = 'Abrir imagen original'; a.style.display = 'inline-block'; a.style.marginTop = '10px'; bubble.appendChild(a); }
    if (options.audioUrl) { const audio = document.createElement('audio'); audio.controls = true; audio.preload = 'none'; audio.src = options.audioUrl; bubble.appendChild(audio); }
    if (options.speak && role === 'assistant') speakText(options.spokenText || text);
    if (options.showActions && text) {
      const actions = document.createElement('div'); actions.className = 'message-actions';
      const copy = document.createElement('button'); copy.className = 'mini-action'; copy.textContent = 'Copiar respuesta'; copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(text); toast('Respuesta copiada.'); } catch { toast('No se pudo copiar automáticamente.'); } });
      actions.appendChild(copy); bubble.appendChild(actions);
    }
    content.append(label, bubble); wrapper.append(avatar, content); els.stream.appendChild(wrapper); wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); return wrapper;
  }
  function resetAttachment() { state.image = null; els.imageInput.value = ''; els.attachmentPreview.classList.add('hidden'); els.attachmentName.textContent = ''; }
  function autosize() { els.prompt.style.height = 'auto'; els.prompt.style.height = `${Math.min(els.prompt.scrollHeight, 160)}px`; }

  function isImageGenerationRequest(text) {
    const normalized = String(text || '').toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/^[\u00bf\u00a1]+/, '').trim();
    if (/^(?:como|por que|explica|explicame|dime como|puedes explicar|tutorial|pasos para|que modelo|que herramienta)\b/.test(normalized)) return false;
    const imageSubject = /\b(?:imagen(?:es)?|fotos?|fotografias?|dibujos?|ilustraciones?|retratos?|posters?|logotipos?|logos?|avatares?|portadas?|wallpaper|fondo de pantalla)\b/.test(normalized);
    const asksToCreate = /\b(?:gener\w*|crea\w*|cree|creen|haz\w*|haga\w*|hic\w*|dibuja\w*|ilustra\w*|pinta\w*|disena\w*|elabor\w*|create|generate|draw|make|illustrate)\b/.test(normalized);
    const asksForVisual = /\b(?:quiero|quisiera|me\s+gustaria|necesito|dame|me\s+das|darias|muestr\w*|mostr\w*|ensen\w*|mandame|enviame|busc\w*|encontr\w*|me\s+puedes\s+dar|puedes\s+darme|puedes\s+mostrar|podrias\s+mostrar|show|find|search)\b/.test(normalized);
    const startsWithImage = /^(?:por favor\s+)?(?:(?:una|un|la|el|alguna|alguno|unas|unos|las|los|algunas|algunos)\s+)?(?:imagen(?:es)?|fotos?|fotografias?|dibujos?|ilustraciones?|retratos?|posters?|logotipos?|logos?|avatares?|portadas?|wallpapers?|fondos?\s+de\s+pantalla)\s+(?:de|del|con|sobre)\b/.test(normalized);
    const drawingVerb = /\b(?:dibuja(?:me)?|ilustra(?:me)?|pinta(?:me)?)\b/.test(normalized);
    return startsWithImage || (imageSubject && (asksToCreate || asksForVisual)) || drawingVerb;
  }

  async function sendChat() {
    if (state.busy) return;
    const question = els.prompt.value.trim();
    if (!question && !state.image) { els.prompt.focus(); return; }
    if (!state.image && isImageGenerationRequest(question)) {
      await generateImage(question);
      return;
    }
    const localImage = state.image;
    const form = new FormData(); form.set('message', question);
    if (localImage) form.set('image', localImage, localImage.name);
    appendMessage('user', question || '¿Qué puedes identificar en esta imagen?', localImage ? { imageUrl: URL.createObjectURL(localImage) } : {});
    els.prompt.value = ''; autosize(); resetAttachment(); showWorking('Analizando tu pregunta y buscando contexto relevante…');
    try {
      const data = await api('chat', form);
      appendMessage('assistant', data.answer || 'No recibi texto de respuesta.', { audioUrl: data.audio_url || null, showActions: true, speak: els.voiceToggle.checked });
      
      if (data.history_count !== undefined) $('memoryStatus').textContent = `${data.history_count} respuestas guardadas`;
    } catch (error) { if (error?.name === 'AbortError') appendMessage('assistant', 'Solicitud detenida.'); else appendMessage('assistant', formatRequestError('No pude completar la solicitud.', error)); }
    finally { hideWorking(); els.prompt.focus(); }
  }
  async function generateImage(promptOverride = null) {
    if (state.busy) return;
    const prompt = (promptOverride ?? els.prompt.value).trim();
    if (!prompt) { toast('Escribe una descripción de la imagen que quieres crear.'); els.prompt.focus(); return; }
    appendMessage('user', `Buscar imagen: ${prompt}`); showWorking('Buscando imágenes en Unsplash…');
    try {
      const form = new FormData(); form.set('prompt', prompt);
      const data = await api('image', form);
      els.prompt.value = ''; autosize();
      appendMessage('assistant', data.caption || 'Imagen lista.', { generatedImage: data.image_url, showActions: true, speak: els.voiceToggle.checked, spokenText: data.speech_caption || data.caption });
      if (data.history_count !== undefined) $('memoryStatus').textContent = `${data.history_count} respuestas guardadas`;
    } catch (error) { if (error?.name === 'AbortError') appendMessage('assistant', 'Búsqueda detenida.'); else appendMessage('assistant', formatRequestError('No pude buscar la imagen.', error)); }
    finally { hideWorking(); els.prompt.focus(); }
  }
  function startOrStopRecording() {
    if (state.busy) return;
    if (state.recording && state.recorder) { state.recorder.stop(); return; }
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) { toast('El dictado por voz requiere Chrome o Edge actualizado.'); return; }
    try {
      const recognition = new SpeechRecognition();
      recognition.lang = 'es-CO';
      recognition.interimResults = false;
      recognition.maxAlternatives = 1;
      recognition.onstart = () => { state.recording = true; els.record.classList.add('recording'); els.recordLabel.textContent = 'Detener'; toast('Escuchando...'); };
      recognition.onresult = (event) => {
        const text = event.results?.[0]?.[0]?.transcript?.trim() || '';
        if (!text) { toast('No se detecto voz. Intenta de nuevo.'); return; }
        els.prompt.value = text;
        autosize();
        sendChat();
      };
      recognition.onerror = (event) => {
        if (event.error === 'not-allowed' || event.error === 'service-not-allowed') toast('Permite el acceso al microfono para dictar.');
        else if (event.error !== 'no-speech' && event.error !== 'aborted') toast('No se pudo transcribir la voz: ' + event.error);
      };
      recognition.onend = () => { state.recording = false; state.recorder = null; els.record.classList.remove('recording'); els.recordLabel.textContent = 'Hablar'; };
      state.recorder = recognition;
      recognition.start();
    } catch (error) {
      state.recording = false;
      state.recorder = null;
      els.record.classList.remove('recording');
      els.recordLabel.textContent = 'Hablar';
      toast('No se pudo iniciar el reconocimiento de voz.');
    }
  }
  async function newChat() {
    if (state.busy) return;
    try { const data = await api('new_chat'); stopSpeech(); state.conversationId = data.conversation_id; els.stream.replaceChildren(); els.stream.classList.add('hidden'); els.welcome.classList.remove('hidden'); resetAttachment(); els.prompt.value = ''; autosize(); toast('Nueva conversación creada.'); els.sidebar.classList.remove('open'); }
    catch (error) { toast(error.message); }
  }
  els.send.addEventListener('click', sendChat);
  els.imageMode.addEventListener('click', generateImage);
  els.cancelRequest.addEventListener('click', cancelCurrentRequest);
  els.prompt.addEventListener('input', autosize);
  els.prompt.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); } });
  els.imageInput.addEventListener('change', () => { const file = els.imageInput.files?.[0]; if (!file) return; if (file.size > 5 * 1024 * 1024) { resetAttachment(); toast('La imagen debe pesar como máximo 5 MB.'); return; } if (!/^image\/(png|jpeg|webp|gif)$/.test(file.type)) { resetAttachment(); toast('Adjunta una imagen JPG, PNG, WEBP o GIF.'); return; } state.image = file; els.attachmentName.textContent = file.name; els.attachmentPreview.classList.remove('hidden'); });
  els.removeAttachment.addEventListener('click', resetAttachment);
  els.record.addEventListener('click', startOrStopRecording);
  els.speechControl.addEventListener('click', () => {
    if (!state.utterance) return;
    if (window.speechSynthesis.paused) window.speechSynthesis.resume();
    else if (window.speechSynthesis.speaking) window.speechSynthesis.pause();
  });
  els.stopSpeechButton.addEventListener('click', stopSpeech);
  $('newChatButton').addEventListener('click', newChat);
  document.querySelectorAll('[data-prompt]').forEach(button => button.addEventListener('click', () => { els.prompt.value = button.dataset.prompt; autosize(); els.prompt.focus(); }));
  $('memoryNav').addEventListener('click', () => $('infoModal').classList.remove('hidden'));
  $('modalClose').addEventListener('click', () => $('infoModal').classList.add('hidden'));
  $('modalGotIt').addEventListener('click', () => $('infoModal').classList.add('hidden'));
  $('historyNav').addEventListener('click', () => { els.stream.scrollTo({ top: els.stream.scrollHeight, behavior: 'smooth' }); if (els.stream.classList.contains('hidden')) toast('Esta conversación todavía no tiene mensajes.'); });
  $('menuButton').addEventListener('click', () => els.sidebar.classList.toggle('open'));
  document.addEventListener('click', e => { if (window.innerWidth <= 680 && els.sidebar.classList.contains('open') && !els.sidebar.contains(e.target) && !e.target.closest('#menuButton')) els.sidebar.classList.remove('open'); });
  async function loadHistory() {
    try {
      const data = await api('history');
      if (data.history_count !== undefined) $('memoryStatus').textContent = `${data.history_count} respuestas guardadas`;
      if (Array.isArray(data.messages) && data.messages.length) {
        showChat();
        for (const msg of data.messages) {
          const opts = {};
          if (msg.image_path) opts.imageUrl = msg.image_path;
          if (msg.audio_path) opts.audioUrl = msg.audio_path;
          if (msg.role === 'assistant') opts.showActions = true;
          appendMessage(msg.role, msg.content, opts);
        }
      }
    } catch (error) {
      // The page can still render its interface; API setup errors appear when the user sends a request.
      console.debug('No se pudo cargar el historial inicial:', error.message);
    }
  }
  // Chat history and memories are persisted in MySQL for the browser session.
  autosize();
  loadHistory();
})();
