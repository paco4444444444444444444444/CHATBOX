/*!
 * FEVAL Chatbot Widget v2.0.0
 * Asistente virtual IA para formacionfeval.com
 *
 * INSTRUCCIONES DE INSTALACIÓN:
 * ==============================
 * 1. Sube feval-chatbot.js y ollama-proxy.php a tu servidor Joomla
 * 2. Configura ollama-proxy.php con tu backend (Ollama, Groq o Claude)
 * 3. Añade en el <head> o antes de </body> de cada página:
 *
 *    OPCIÓN A — Ollama local (GRATIS, ILIMITADO):
 *    <script>window.FEVAL_CHATBOT_BACKEND = 'proxy';</script>
 *    <script>window.FEVAL_CHATBOT_PROXY_URL = '/ollama-proxy.php';</script>
 *    <script src="/ruta/a/feval-chatbot.js"></script>
 *
 *    OPCIÓN B — Groq nube (GRATIS hasta 14.400 req/día, muy rápido):
 *    <script>window.FEVAL_CHATBOT_BACKEND = 'proxy';</script>
 *    <script>window.FEVAL_CHATBOT_PROXY_URL = '/ollama-proxy.php';</script>
 *    <script src="/ruta/a/feval-chatbot.js"></script>
 *    (en ollama-proxy.php pon $BACKEND = 'groq')
 *
 *    OPCIÓN C — Claude directo desde browser (requiere API key de pago):
 *    <script>window.FEVAL_CHATBOT_BACKEND = 'claude';</script>
 *    <script>window.FEVAL_CHATBOT_API_KEY = "sk-ant-TU_KEY";</script>
 *    <script src="/ruta/a/feval-chatbot.js"></script>
 *
 * VARIABLES DE CONFIGURACIÓN:
 *    window.FEVAL_CHATBOT_BACKEND    — 'proxy' (recomendado) o 'claude'
 *    window.FEVAL_CHATBOT_PROXY_URL  — URL del proxy PHP (cuando backend='proxy')
 *    window.FEVAL_CHATBOT_API_KEY    — API key de Anthropic (solo backend='claude')
 *
 * SOPORTE: formacion@feval.com
 */

(function () {
  'use strict';

  // ─── Configuración ────────────────────────────────────────────────────────
  // Backend: 'proxy' usa ollama-proxy.php (Ollama/Groq, GRATIS)
  //          'claude' llama a Anthropic directamente desde el browser (de pago)
  var BACKEND     = (typeof window !== 'undefined' && window.FEVAL_CHATBOT_BACKEND)   || 'proxy';
  var PROXY_URL   = (typeof window !== 'undefined' && window.FEVAL_CHATBOT_PROXY_URL) || '/ollama-proxy.php';
  var API_KEY     = (typeof window !== 'undefined' && window.FEVAL_CHATBOT_API_KEY)   || '';
  var CLAUDE_URL  = 'https://api.anthropic.com/v1/messages';
  var CLAUDE_MODEL = 'claude-sonnet-4-20250514';
  var MAX_HISTORY = 10; // pares de mensajes a mantener en contexto
  var REQUEST_TIMEOUT_MS = 45000; // 45s (Ollama local puede tardar más)

  var SYSTEM_PROMPT = [
    'Eres el asistente virtual oficial de FEVAL Formación, la plataforma de formación TIC gratuita de la Institución Ferial de Extremadura. Tu nombre es "Asistente FEVAL".',
    'Tu misión es ayudar a los usuarios a informarse sobre cursos, inscripción, baremación, diplomas y cualquier duda sobre la formación TIC gratuita del Plan Formativo 2026.',
    '',
    '=== INFORMACIÓN GENERAL ===',
    '- 70 cursos TIC gratuitos en 2026, financiados por la Junta de Extremadura (Consejería de Economía, Empleo y Transformación Digital) y el SEXPE (Servicio Extremeño Público de Empleo)',
    '- Todos los cursos son GRATUITOS, incluyendo el examen oficial de certificación (en los cursos que lo incluyan)',
    '- Modalidad: Online con clases en directo (asistencia obligatoria en tiempo real en un horario específico)',
    '- Las clases NO se graban. Excepción: la parte presencial de algunos cursos de drones',
    '- Dirigido a: empleados y desempleados de Extremadura',
    '- Requisito para empleados: que su puesto de trabajo esté en Extremadura',
    '- Requisito para desempleados: ser demandante de empleo en cualquier centro de empleo de Extremadura',
    '- Personas de fuera de Extremadura: SOLO pueden participar si teletrabajan para una empresa con domicilio social en Extremadura',
    '- Requisitos técnicos: ordenador con conexión a internet y nociones básicas de informática',
    '',
    '=== 9 ÁREAS TEMÁTICAS ===',
    '1. Agricultura 4.0 y Drónica (9 cursos): agricultura de precisión, SIG, fotogrametría, sensores agrícolas, habilitación de piloto de drones (A1/A3/A2/STS) 64h, cursos de actualización de piloto',
    '2. Desarrollo de Videojuegos (5 cursos): Game Designer, Concept Art 2D/3D, Arte y Animación Unity, Programación Unity certificada, Desarrollo VR con Unity',
    '3. Desarrollo de Software (5+ cursos): JavaScript, Python básico y avanzado, bases de datos, análisis de datos con Python',
    '4. Sistemas y Soporte (3 cursos): Soporte IT básico, Administración Windows Server, Linux LPIC-1',
    '5. Ciberseguridad y Redes (7 cursos): fundamentos de redes 48h, ciberseguridad básica 48h, hacking ético EC-Council 48h, informática forense EC-Council 36h, CCNA Intro a Redes 24h, CCNA Switching/Routing/Wireless 48h, CCNA Enterprise Networking & Security 48h',
    '6. Gestión de Proyectos (3 cursos): introducción gestión proyectos PMI, Scrum Master, herramientas IA para gestión de proyectos',
    '7. Big Data, IA y Cloud (9+ cursos): Fundamentos IA 36h, IA avanzada 24h, IA Generativa 36h, Azure AI 900, AWS AI Practitioner, Cloud Computing e IA, Azure Fundamentals AZ-900, AWS Cloud Practitioner, Google Associate Cloud Engineer',
    '8. Analítica de Datos y BI (4 cursos): Excel para análisis de datos, Excel avanzado, Power BI introducción, Power BI avanzado',
    '9. Diseño Gráfico y Marketing Digital (7 cursos): Photoshop, Illustrator, edición de vídeo con Premiere, IA aplicada al diseño, Community Management, marketing digital y redes sociales, estrategias Meta (Instagram/Facebook) con certificación oficial',
    '',
    '=== PROCESO DE INSCRIPCIÓN ===',
    '- Pasos: (1) Crear cuenta personal en la web, (2) Activar cuenta confirmando el email de registro, (3) Iniciar sesión, (4) Hacer clic en "Inscripción" en el curso deseado',
    '- El formulario completo solo se rellena la 1ª vez; las siguientes inscripciones se autocompletan con opción de modificar datos',
    '- Pre-inscripciones abiertas hasta la fecha de inicio del curso o hasta alcanzar 75 pre-inscritos',
    '- Al llegar a 75 pre-inscritos, el sistema cierra automáticamente las pre-inscripciones de ese curso',
    '- Se seleccionan hasta 16 alumnos por baremo',
    '- Solo se contacta a los candidatos seleccionados, vía teléfono/email, días antes del inicio',
    '- Si te has inscrito y no recibes notificación antes del inicio, no has sido seleccionado en esa convocatoria',
    '- Cuota del 30%: si un curso es preferentemente para desempleados y quedan plazas libres, hasta un 30% puede asignarse a empleados, y viceversa',
    '- Los alumnos que completan un curso de un itinerario tienen preferencia para el siguiente nivel del mismo itinerario',
    '- En cursos con baja demanda (menos de 75 inscritos), se llama por orden de inscripción hasta completar las 16 plazas — inscríbete cuanto antes',
    '- Link para inscribirse: https://formacionfeval.com/index.php/cursos-feval',
    '- Baremaciones publicadas en: https://formacionfeval.com/index.php/baremaciones',
    '',
    '=== SELECCIÓN Y BAREMACIÓN ===',
    '- Al alcanzar 75 pre-inscripciones se cierra el plazo y se aplica el baremo',
    '- Cursos para desempleados: se usa la baremación oficial del SEXPE (consultable en la web)',
    '- Cursos para empleados: se usan criterios específicos (consultables en la web)',
    '- Se publica en la web la resolución con los DNI y puntuaciones ordenadas; se ofrecen plazas por ese orden hasta completar 16 alumnos',
    '- Solo los seleccionados son contactados por teléfono o email',
    '- Si no recibes noticias antes del inicio: no fuiste seleccionado en esta convocatoria',
    '- Si fuiste seleccionado pero no puedes asistir: comunícalo URGENTEMENTE a formacion@feval.com o 924 829 100 para reasignar tu plaza',
    '',
    '=== ASISTENCIA Y SEGUIMIENTO ===',
    '- Las clases suelen durar 180 minutos (3 horas); se requieren al menos 150 minutos de conexión para que cuente como asistencia válida',
    '- No se puede faltar más del 25% del total del curso (ej: en un curso de 12 clases, máximo 3 faltas)',
    '- Al menos una de las faltas debe justificarse con justificante oficial (urgencia médica, deber público, etc.)',
    '- Abandono voluntario o baja por inasistencia: NO genera sanciones ni penalizaciones para futuras formaciones con FEVAL o SEXPE',
    '- El abandono solo implica no obtener el diploma del curso actual',
    '',
    '=== DIPLOMAS Y CERTIFICACIONES ===',
    '- Cada curso incluye diploma de aprovechamiento expedido por SEXPE',
    '- Los cursos con certificación oficial incluyen además el certificado del fabricante (Cisco, AWS, Microsoft, EC-Council, Meta...) si se supera el examen',
    '- Tasas del examen de certificación: incluidas, sin coste para el alumno',
    '- La firma oficial del diploma SEXPE puede tardar varios meses',
    '- FEVAL puede emitir un certificado provisional de aprovechamiento firmado por FEVAL para quienes lo necesiten antes',
    '- Recogida de diplomas: Lunes a Viernes de 08:00 a 15:00 en Centro Tecnológico FEVAL, Paseo de FEVAL s/n, Don Benito (Badajoz)',
    '- IMPORTANTE: NO son Certificados de Profesionalidad SEPE — es formación no reglada. El valor está en las certificaciones oficiales de fabricante',
    '',
    '=== DEMANDA DE EMPLEO Y PRESTACIONES ===',
    '- Participar en los cursos NO suspende la demanda de empleo ni elimina prestaciones',
    '- La demanda de empleo se mantiene con su intermediación (salvo que el alumno renuncie voluntariamente a ella)',
    '- Para dudas sobre prestaciones o subsidios, contactar con el Centro de Empleo más cercano — FEVAL no tiene acceso al sistema SEXPE',
    '',
    '=== PROBLEMAS DE ACCESO Y CUENTA ===',
    '- Error al crear cuenta: normalmente el nombre de usuario ya está en uso — prueba con otro nombre de usuario',
    '- Cuenta bloqueada o sin activar: revisa el email (incluida la carpeta de spam) para confirmar el registro; si no llega el email, contacta con formacion@feval.com o 924 829 100',
    '- Contraseña olvidada: ve a la página de login y selecciona "¿Olvidaste tu contraseña?"',
    '- Sin enlace de aula virtual tras confirmación de matrícula: contacta urgentemente a formacion@feval.com o 924 829 100',
    '',
    '=== CONTACTO ===',
    '- Email: formacion@feval.com',
    '- Teléfonos: 924 829 100 | 618 457 790',
    '- Horario: Lunes a Viernes 08:00 a 15:00',
    '- Dirección: Centro Tecnológico FEVAL, Paseo de FEVAL s/n, Don Benito, Badajoz (06400)',
    '- Web: https://formacionfeval.com',
    '- Cursos: https://formacionfeval.com/index.php/cursos-feval',
    '- Baremaciones: https://formacionfeval.com/index.php/baremaciones',
    '- Ayuda: https://formacionfeval.com/index.php/ayuda',
    '- Contacto web: https://formacionfeval.com/index.php/contacto',
    '',
    '=== REGLAS ESTRICTAS ANTI-INVENCIÓN — LEER CON ATENCIÓN ===',
    '⚠️ REGLA ABSOLUTA: SOLO puedes responder usando la información que aparece EXACTAMENTE en este system prompt.',
    '⚠️ Si el usuario pregunta algo que NO está cubierto en este system prompt, responde LITERALMENTE:',
    '   "No tengo esa información concreta. Para obtenerla, visita https://formacionfeval.com o contacta directamente: formacion@feval.com / 924 829 100"',
    '⚠️ PROHIBIDO TOTALMENTE: inventar fechas, horarios, plazas, nombres de profesores, precios, requisitos, número de horas, calendarios ni NINGÚN dato que no esté escrito arriba.',
    '⚠️ Si no tienes el dato exacto, NO lo adivines — di que no lo tienes y da el contacto.',
    '',
    '=== REGLAS DE COMPORTAMIENTO ===',
    '- Responde SIEMPRE en español',
    '- Sé amable, profesional y conciso — respuestas directas y útiles',
    '- Usa ÚNICAMENTE la información de este prompt; no uses conocimiento externo sobre FEVAL',
    '- Si el usuario pregunta por un curso específico que no aparece aquí, dile que visite la web o contacte directamente',
    '- Para inscripciones siempre dirige a https://formacionfeval.com/index.php/cursos-feval',
    '- Para dudas muy específicas o urgentes, recomienda llamar al 924 829 100 o escribir a formacion@feval.com',
    '- Cuando menciones URLs usa siempre las URLs reales de formacionfeval.com que aparecen en este prompt',
    '- Si el usuario parece frustrado o tiene un problema urgente (plaza, acceso, diploma), prioriza darle el contacto directo'
  ].join('\n');

  var WELCOME_MESSAGE = '¡Hola! 👋 ¿En qué puedo ayudarte? Pregúntame sobre cursos, cómo inscribirte, diplomas o cualquier duda sobre la formación.';

  var QUICK_REPLIES = [
    { label: '📚 Cursos disponibles',   text: '¿Qué cursos hay disponibles?' },
    { label: '📝 Cómo inscribirse',     text: '¿Cómo me inscribo en un curso?' },
    { label: '🏆 Selección y baremo',   text: '¿Cómo funciona la selección de alumnos y el baremo?' },
    { label: '🎓 Diplomas y títulos',   text: '¿Qué diplomas o certificaciones se obtienen al terminar un curso?' }
  ];

  // ─── Estado ───────────────────────────────────────────────────────────────
  var isOpen           = false;
  var isLoading        = false;
  var conversationHistory = [];
  var welcomeShown     = false;
  var notificationDot  = null;

  // ─── CSS ──────────────────────────────────────────────────────────────────
  var CSS = [
    /* Reset & Base */
    '#feval-chatbot-root *{box-sizing:border-box;margin:0;padding:0;}',

    /* Floating button */
    '#feval-chat-btn{',
      'position:fixed;bottom:24px;right:24px;z-index:9999;',
      'width:60px;height:60px;border-radius:50%;',
      'background:linear-gradient(135deg,#1a56a0 0%,#0d3a72 100%);',
      'border:none;cursor:pointer;',
      'box-shadow:0 4px 20px rgba(26,86,160,0.45);',
      'display:flex;align-items:center;justify-content:center;',
      'transition:transform 0.2s ease,box-shadow 0.2s ease;',
      'outline:none;',
    '}',
    '#feval-chat-btn:hover{transform:scale(1.08);box-shadow:0 6px 26px rgba(26,86,160,0.55);}',
    '#feval-chat-btn:focus-visible{outline:3px solid #60a5fa;outline-offset:3px;}',
    '#feval-chat-btn svg{width:28px;height:28px;fill:#fff;transition:opacity 0.2s;}',
    '#feval-chat-btn.open svg.icon-chat{display:none;}',
    '#feval-chat-btn.open svg.icon-close{display:block;}',
    '#feval-chat-btn svg.icon-close{display:none;}',

    /* Notification badge */
    '#feval-notif-badge{',
      'position:absolute;top:2px;right:2px;',
      'width:14px;height:14px;border-radius:50%;',
      'background:#f97316;border:2px solid #fff;',
      'animation:feval-pulse 1.8s infinite;',
    '}',
    '@keyframes feval-pulse{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.3);opacity:0.7;}}',

    /* Tooltip */
    '#feval-chat-btn::after{',
      'content:"¿Necesitas ayuda?";',
      'position:absolute;right:70px;top:50%;transform:translateY(-50%);',
      'background:#1a56a0;color:#fff;',
      'padding:6px 12px;border-radius:8px;',
      'font-size:13px;font-family:system-ui,-apple-system,sans-serif;',
      'white-space:nowrap;pointer-events:none;',
      'opacity:0;transition:opacity 0.2s;',
      'box-shadow:0 2px 8px rgba(0,0,0,0.2);',
    '}',
    '#feval-chat-btn::before{',
      'content:"";',
      'position:absolute;right:66px;top:50%;transform:translateY(-50%);',
      'border:6px solid transparent;border-left-color:#1a56a0;',
      'opacity:0;transition:opacity 0.2s;pointer-events:none;',
    '}',
    '#feval-chat-btn:not(.open):hover::after,#feval-chat-btn:not(.open):hover::before{opacity:1;}',

    /* Chat window */
    '#feval-chat-window{',
      'position:fixed;bottom:96px;right:24px;z-index:9998;',
      'width:400px;height:560px;',
      'background:#fff;border-radius:16px;',
      'box-shadow:0 8px 40px rgba(0,0,0,0.18);',
      'display:flex;flex-direction:column;overflow:hidden;',
      'opacity:0;transform:translateY(20px) scale(0.96);pointer-events:none;',
      'transition:opacity 0.25s ease,transform 0.25s ease;',
      'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;',
    '}',
    '#feval-chat-window.open{opacity:1;transform:translateY(0) scale(1);pointer-events:all;}',

    /* Header */
    '#feval-chat-header{',
      'background:linear-gradient(135deg,#1a56a0 0%,#0d3a72 100%);',
      'padding:14px 16px;',
      'display:flex;align-items:center;gap:12px;',
      'flex-shrink:0;',
    '}',
    '#feval-chat-avatar{',
      'width:40px;height:40px;border-radius:50%;',
      'background:rgba(255,255,255,0.15);',
      'display:flex;align-items:center;justify-content:center;',
      'flex-shrink:0;',
    '}',
    '#feval-chat-avatar svg{width:22px;height:22px;fill:#fff;}',
    '#feval-chat-header-info{flex:1;min-width:0;}',
    '#feval-chat-title{color:#fff;font-weight:700;font-size:15px;line-height:1.2;}',
    '#feval-chat-subtitle{color:rgba(255,255,255,0.8);font-size:11.5px;margin-top:2px;}',
    '#feval-chat-status{display:flex;align-items:center;gap:5px;margin-top:3px;}',
    '#feval-status-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;animation:feval-blink 2s infinite;}',
    '@keyframes feval-blink{0%,100%{opacity:1;}50%{opacity:0.4;}}',
    '#feval-status-text{color:rgba(255,255,255,0.75);font-size:11px;}',
    '#feval-chat-actions{display:flex;gap:6px;flex-shrink:0;}',
    '.feval-header-btn{',
      'background:rgba(255,255,255,0.15);border:none;',
      'width:30px;height:30px;border-radius:8px;',
      'cursor:pointer;display:flex;align-items:center;justify-content:center;',
      'transition:background 0.2s;color:#fff;',
    '}',
    '.feval-header-btn:hover{background:rgba(255,255,255,0.25);}',
    '.feval-header-btn:focus-visible{outline:2px solid rgba(255,255,255,0.6);outline-offset:2px;}',
    '.feval-header-btn svg{width:15px;height:15px;fill:currentColor;}',

    /* Messages area */
    '#feval-messages{',
      'flex:1;overflow-y:auto;padding:18px 16px;',
      'display:flex;flex-direction:column;gap:16px;',
      'scroll-behavior:smooth;',
    '}',
    '#feval-messages::-webkit-scrollbar{width:5px;}',
    '#feval-messages::-webkit-scrollbar-track{background:transparent;}',
    '#feval-messages::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:4px;}',

    /* Message bubbles */
    '.feval-msg{display:flex;gap:10px;align-items:flex-end;animation:feval-msgIn 0.2s ease;}',
    '@keyframes feval-msgIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}',
    '.feval-msg.user{flex-direction:row-reverse;}',
    '.feval-msg-avatar{',
      'width:32px;height:32px;border-radius:50%;flex-shrink:0;',
      'background:linear-gradient(135deg,#1a56a0,#0d3a72);',
      'display:flex;align-items:center;justify-content:center;',
    '}',
    '.feval-msg-avatar svg{width:17px;height:17px;fill:#fff;}',
    '.feval-msg-body{max-width:82%;display:flex;flex-direction:column;gap:4px;}',
    '.feval-msg.user .feval-msg-body{align-items:flex-end;}',
    '.feval-msg-bubble{',
      'padding:12px 16px;border-radius:18px;',
      'font-size:14px;line-height:1.6;word-break:break-word;',
    '}',
    '.feval-msg-bubble p{margin:0 0 6px;}',
    '.feval-msg-bubble p:last-child{margin-bottom:0;}',
    '.feval-msg-bubble ul,.feval-msg-bubble ol{margin:4px 0 6px;padding-left:20px;}',
    '.feval-msg-bubble li{margin-bottom:4px;line-height:1.5;}',
    '.feval-msg-bubble li:last-child{margin-bottom:0;}',
    '.feval-msg.user .feval-msg-bubble ul,.feval-msg.user .feval-msg-bubble ol{color:#fff;}',
    '.feval-msg.assistant .feval-msg-bubble{',
      'background:#f1f5f9;color:#1e293b;',
      'border-bottom-left-radius:5px;',
    '}',
    '.feval-msg.user .feval-msg-bubble{',
      'background:linear-gradient(135deg,#1a56a0,#1e64b8);',
      'color:#fff;border-bottom-right-radius:5px;',
    '}',
    '.feval-msg-time{font-size:11px;color:#94a3b8;padding:0 4px;}',
    '.feval-msg-bubble a{color:#1a56a0;text-decoration:underline;}',
    '.feval-msg.user .feval-msg-bubble a{color:#bfdbfe;}',

    /* Quick replies */
    '#feval-quick-replies{',
      'display:flex;flex-wrap:wrap;gap:7px;padding:0 14px 4px;',
    '}',
    '.feval-qr-btn{',
      'background:#f0f6ff;border:1.5px solid #bcd4f5;',
      'color:#1a56a0;padding:7px 12px;border-radius:20px;',
      'font-size:12.5px;cursor:pointer;',
      'transition:background 0.15s,border-color 0.15s,transform 0.1s;',
      'font-family:inherit;',
    '}',
    '.feval-qr-btn:hover{background:#dbeafe;border-color:#93c5fd;transform:translateY(-1px);}',
    '.feval-qr-btn:active{transform:translateY(0);}',
    '.feval-qr-btn:focus-visible{outline:2px solid #1a56a0;outline-offset:2px;}',

    /* Typing indicator */
    '#feval-typing{',
      'display:none;align-items:flex-end;gap:8px;',
      'padding:0 14px 4px;',
    '}',
    '#feval-typing.show{display:flex;}',
    '.feval-typing-bubble{',
      'background:#f1f5f9;padding:10px 14px;border-radius:16px;border-bottom-left-radius:4px;',
      'display:flex;gap:5px;align-items:center;',
    '}',
    '.feval-typing-dot{',
      'width:7px;height:7px;border-radius:50%;background:#94a3b8;',
      'animation:feval-bounce 1.2s infinite;',
    '}',
    '.feval-typing-dot:nth-child(2){animation-delay:0.2s;}',
    '.feval-typing-dot:nth-child(3){animation-delay:0.4s;}',
    '@keyframes feval-bounce{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-5px);}}',

    /* Input area */
    '#feval-input-area{',
      'padding:12px 14px;border-top:1px solid #e2e8f0;',
      'display:flex;gap:9px;align-items:flex-end;flex-shrink:0;',
      'background:#fff;',
    '}',
    '#feval-textarea{',
      'flex:1;resize:none;border:1.5px solid #e2e8f0;',
      'border-radius:14px;padding:11px 15px;',
      'font-family:inherit;font-size:14px;line-height:1.5;',
      'color:#1e293b;outline:none;',
      'transition:border-color 0.2s;',
      'max-height:120px;overflow-y:auto;',
    '}',
    '#feval-textarea:focus{border-color:#1a56a0;}',
    '#feval-textarea:disabled{background:#f8fafc;cursor:not-allowed;}',
    '#feval-textarea::placeholder{color:#94a3b8;}',
    '#feval-send-btn{',
      'width:40px;height:40px;border-radius:50%;border:none;',
      'background:linear-gradient(135deg,#1a56a0,#0d3a72);',
      'color:#fff;cursor:pointer;',
      'display:flex;align-items:center;justify-content:center;flex-shrink:0;',
      'transition:opacity 0.2s,transform 0.15s,background 0.2s;',
      'opacity:0.45;',
    '}',
    '#feval-send-btn.active{opacity:1;}',
    '#feval-send-btn.active:hover{transform:scale(1.08);}',
    '#feval-send-btn:disabled{cursor:not-allowed;}',
    '#feval-send-btn svg{width:18px;height:18px;fill:#fff;}',

    /* Error message */
    '.feval-error-msg{',
      'background:#fef2f2;border:1px solid #fecaca;',
      'color:#991b1b;padding:10px 13px;border-radius:12px;',
      'font-size:12.5px;display:flex;align-items:flex-start;gap:8px;',
    '}',
    '.feval-error-msg svg{width:16px;height:16px;fill:#ef4444;flex-shrink:0;margin-top:1px;}',
    '.feval-retry-link{color:#1a56a0;cursor:pointer;text-decoration:underline;display:inline-block;margin-top:4px;}',

    /* Responsive – tablet/móvil (≤ 640px): bottom sheet */
    '@media(max-width:640px){',
      '#feval-chat-window{',
        'width:100%;height:82dvh;height:82vh;',
        'bottom:0;right:0;left:0;',
        'border-radius:20px 20px 0 0;',
        'padding-bottom:env(safe-area-inset-bottom,0px);',
      '}',
      /* Ocultar botón flotante cuando el chat está abierto en móvil */
      /* (el header ya tiene botón de cerrar) */
      '#feval-chat-btn.open{display:none;}',
      '#feval-chat-btn{bottom:16px;right:16px;}',
      '#feval-input-area{',
        'padding-bottom:calc(12px + env(safe-area-inset-bottom,0px));',
      '}',
      '.feval-qr-btn{font-size:13px;padding:8px 13px;}',
      '#feval-textarea{font-size:16px;}',
    '}',
    /* Muy pequeño (≤ 400px): pantalla completa */
    '@media(max-width:400px){',
      '#feval-chat-window{',
        'height:100dvh;height:100vh;',
        'border-radius:0;',
        'padding-top:env(safe-area-inset-top,0px);',
      '}',
    '}',
  ].join('');

  // ─── SVGs ─────────────────────────────────────────────────────────────────
  var SVG_CHAT = '<svg class="icon-chat" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 12H6l-2 2V4h16v10z"/></svg>';
  var SVG_CLOSE_BTN = '<svg class="icon-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
  var SVG_CLOSE_SM = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
  var SVG_TRASH = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>';
  var SVG_SEND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
  var SVG_BOT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 9V7c0-1.1-.9-2-2-2h-3V3.5a1.5 1.5 0 10-3 0V5H9C7.9 5 7 5.9 7 7v2c-1.66 0-3 1.34-3 3s1.34 3 3 3v3c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2v-3c1.66 0 3-1.34 3-3s-1.34-3-3-3zm-2 10H8v-8h10v8zm-8-5c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>';
  var SVG_ERROR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';

  // ─── Helpers ──────────────────────────────────────────────────────────────
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function applyInline(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
  }

  function formatText(text) {
    var lines = escapeHtml(text).split('\n');
    var html = '';
    var inUl = false;
    var inOl = false;

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var isBullet   = /^[-•*] /.test(line);
      var isNumbered = /^\d+[.)]\s/.test(line);
      var isEmpty    = line.trim() === '';

      if (isBullet) {
        if (inOl) { html += '</ol>'; inOl = false; }
        if (!inUl) { html += '<ul>'; inUl = true; }
        html += '<li>' + applyInline(line.replace(/^[-•*] /, '').trim()) + '</li>';
      } else if (isNumbered) {
        if (inUl) { html += '</ul>'; inUl = false; }
        if (!inOl) { html += '<ol>'; inOl = true; }
        html += '<li>' + applyInline(line.replace(/^\d+[.)]\s/, '').trim()) + '</li>';
      } else if (!isEmpty && (inUl || inOl)) {
        // Línea de continuación de un ítem de lista: se añade al <li> anterior
        html = html.replace(/<\/li>$/, ' ' + applyInline(line.trim()) + '</li>');
      } else {
        if (inUl) { html += '</ul>'; inUl = false; }
        if (inOl) { html += '</ol>'; inOl = false; }
        if (isEmpty) {
          // Solo añadir espacio si hay contenido previo
          if (html && !html.endsWith('<br>')) html += '<br>';
        } else {
          html += '<p>' + applyInline(line) + '</p>';
        }
      }
    }
    if (inUl) html += '</ul>';
    if (inOl) html += '</ol>';
    return html;
  }

  function formatTime(date) {
    return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  function scrollBottom() {
    var el = document.getElementById('feval-messages');
    if (el) el.scrollTop = el.scrollHeight;
  }

  // ─── Render helpers ───────────────────────────────────────────────────────
  function renderMessage(role, content, isError) {
    var container = document.getElementById('feval-messages');
    if (!container) return;

    // Hide quick replies after first user message
    if (role === 'user') {
      var qr = document.getElementById('feval-quick-replies');
      if (qr) qr.style.display = 'none';
    }

    var div = document.createElement('div');
    div.className = 'feval-msg ' + (role === 'user' ? 'user' : 'assistant');

    var avatarHtml = role === 'assistant'
      ? '<div class="feval-msg-avatar" aria-hidden="true">' + SVG_BOT + '</div>'
      : '';

    var bubbleHtml = isError
      ? '<div class="feval-error-msg">' + SVG_ERROR + '<div>' + formatText(content) + '</div></div>'
      : '<div class="feval-msg-bubble">' + formatText(content) + '</div>';

    div.innerHTML = avatarHtml +
      '<div class="feval-msg-body">' +
        bubbleHtml +
        '<div class="feval-msg-time" aria-label="Enviado a las ' + formatTime(new Date()) + '">' +
          formatTime(new Date()) +
        '</div>' +
      '</div>';

    container.appendChild(div);
    scrollBottom();
    return div;
  }

  function showTyping() {
    var el = document.getElementById('feval-typing');
    if (el) el.classList.add('show');
    scrollBottom();
  }

  function hideTyping() {
    var el = document.getElementById('feval-typing');
    if (el) el.classList.remove('show');
  }

  function setInputDisabled(val) {
    var ta = document.getElementById('feval-textarea');
    var btn = document.getElementById('feval-send-btn');
    if (ta) ta.disabled = val;
    if (btn) btn.disabled = val;
    if (!val && ta) updateSendBtn(ta.value);
  }

  function updateSendBtn(value) {
    var btn = document.getElementById('feval-send-btn');
    if (!btn) return;
    if (value && value.trim()) {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  }

  // ─── API call ─────────────────────────────────────────────────────────────
  function callAPI(userText, retryFn) {

    // Validar configuración según backend
    if (BACKEND === 'claude' && !API_KEY) {
      renderMessage('assistant',
        'El chat no está disponible en este momento. Contacta con nosotros: formacion@feval.com · 924 829 100.',
        true
      );
      return;
    }
    if (BACKEND === 'proxy' && !PROXY_URL) {
      renderMessage('assistant',
        'El chat no está configurado correctamente. Contacta con formacion@feval.com.',
        true
      );
      return;
    }

    isLoading = true;
    setInputDisabled(true);
    showTyping();

    // Add to history
    conversationHistory.push({ role: 'user', content: userText });

    // Trim history: keep last MAX_HISTORY messages (user+assistant pairs)
    while (conversationHistory.length > MAX_HISTORY * 2) {
      conversationHistory.splice(0, 2);
    }

    var controller = null;
    var timeoutId = null;

    // Construir la petición según el backend
    var fetchUrl, fetchHeaders, fetchBody;

    if (BACKEND === 'proxy') {
      // ── Proxy PHP (Ollama / Groq) ─────────────────────────────────────────
      fetchUrl = PROXY_URL;
      fetchHeaders = { 'Content-Type': 'application/json' };
      fetchBody = JSON.stringify({
        system: SYSTEM_PROMPT,
        messages: conversationHistory,
        max_tokens: 800
      });
    } else {
      // ── Claude directo desde browser ──────────────────────────────────────
      fetchUrl = CLAUDE_URL;
      fetchHeaders = {
        'Content-Type': 'application/json',
        'x-api-key': API_KEY,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true'
      };
      fetchBody = JSON.stringify({
        model: CLAUDE_MODEL,
        max_tokens: 1000,
        system: SYSTEM_PROMPT,
        messages: conversationHistory
      });
    }

    try {
      controller = new AbortController();
      timeoutId = setTimeout(function () {
        controller.abort();
      }, REQUEST_TIMEOUT_MS);

      fetch(fetchUrl, {
        method: 'POST',
        headers: fetchHeaders,
        body: fetchBody,
        signal: controller.signal
      })
      .then(function (response) {
        clearTimeout(timeoutId);
        if (!response.ok) {
          return response.json().catch(function () { return {}; }).then(function (errBody) {
            var msg = errBody && errBody.error
              ? (typeof errBody.error === 'string' ? errBody.error : errBody.error.message)
              : 'Error desconocido';
            throw new Error('API_ERROR:' + response.status + ':' + msg);
          });
        }
        return response.json();
      })
      .then(function (data) {
        hideTyping();
        isLoading = false;
        setInputDisabled(false);

        // Formato unificado: ambos backends devuelven {content:[{type:'text',text:'...'}]}
        var assistantText = '';
        if (data.content && data.content.length > 0) {
          for (var i = 0; i < data.content.length; i++) {
            if (data.content[i].type === 'text') {
              assistantText = data.content[i].text;
              break;
            }
          }
        }

        if (!assistantText) {
          assistantText = 'No pude procesar la respuesta. Inténtalo de nuevo o contacta con nosotros: formacion@feval.com · 924 829 100.';
        }

        conversationHistory.push({ role: 'assistant', content: assistantText });
        renderMessage('assistant', assistantText);

        var ta = document.getElementById('feval-textarea');
        if (ta) ta.focus();
      })
      .catch(function (err) {
        clearTimeout(timeoutId);
        hideTyping();
        isLoading = false;
        setInputDisabled(false);

        // Remove the user message we added if the call failed
        if (conversationHistory.length > 0 &&
            conversationHistory[conversationHistory.length - 1].role === 'user') {
          conversationHistory.pop();
        }

        var msg;
        if (err.name === 'AbortError') {
          msg = 'La respuesta tardó demasiado. Inténtalo de nuevo en unos segundos. ';
        } else if (err.message && err.message.indexOf('API_ERROR:') === 0) {
          var parts = err.message.split(':');
          var status = parseInt(parts[1]);
          if (status === 429) {
            msg = 'Demasiadas solicitudes seguidas. Espera un momento y vuelve a intentarlo. ';
          } else if (status === 502 || status === 503) {
            msg = 'El servicio no está disponible en este momento. ';
          } else {
            msg = 'Error de conexión (' + status + '). ';
          }
        } else {
          msg = 'No se pudo establecer conexión con el asistente. ';
        }

        var errorDiv = renderMessage('assistant', msg, true);
        if (errorDiv && retryFn) {
          var retryLink = document.createElement('span');
          retryLink.className = 'feval-retry-link';
          retryLink.textContent = 'Reintentar';
          retryLink.setAttribute('role', 'button');
          retryLink.setAttribute('tabindex', '0');
          retryLink.addEventListener('click', function () {
            errorDiv.remove();
            retryFn();
          });
          retryLink.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); retryLink.click(); }
          });
          var bubble = errorDiv.querySelector('.feval-error-msg div');
          if (bubble) bubble.appendChild(retryLink);
        }
      });
    } catch (e) {
      clearTimeout(timeoutId);
      hideTyping();
      isLoading = false;
      setInputDisabled(false);
      renderMessage('assistant', 'Error inesperado. Contacta con nosotros: formacion@feval.com · 924 829 100.', true);
    }
  }

  // ─── Send message ─────────────────────────────────────────────────────────
  function sendMessage(text) {
    if (!text || !text.trim() || isLoading) return;
    var trimmed = text.trim();
    renderMessage('user', trimmed);

    var ta = document.getElementById('feval-textarea');
    if (ta) {
      ta.value = '';
      ta.style.height = 'auto';
      updateSendBtn('');
    }

    callAPI(trimmed, function () { sendMessage(trimmed); });
  }

  // ─── Clear conversation ───────────────────────────────────────────────────
  function clearConversation() {
    conversationHistory = [];
    var container = document.getElementById('feval-messages');
    if (container) container.innerHTML = '';
    var qr = document.getElementById('feval-quick-replies');
    if (qr) qr.style.display = '';
    // Re-show welcome
    renderMessage('assistant', WELCOME_MESSAGE);
  }

  // ─── Toggle widget ────────────────────────────────────────────────────────
  function openChat() {
    isOpen = true;
    var btn = document.getElementById('feval-chat-btn');
    var win = document.getElementById('feval-chat-window');
    var badge = document.getElementById('feval-notif-badge');

    if (btn) btn.classList.add('open');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    if (win) win.classList.add('open');
    if (badge) badge.style.display = 'none';

    if (!welcomeShown) {
      welcomeShown = true;
      setTimeout(function () {
        renderMessage('assistant', WELCOME_MESSAGE);
        var ta = document.getElementById('feval-textarea');
        if (ta) ta.focus();
      }, 80);
    } else {
      setTimeout(function () {
        var ta = document.getElementById('feval-textarea');
        if (ta) ta.focus();
        scrollBottom();
      }, 80);
    }
  }

  function closeChat() {
    isOpen = false;
    var btn = document.getElementById('feval-chat-btn');
    var win = document.getElementById('feval-chat-window');
    if (btn) btn.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (win) win.classList.remove('open');
    if (btn) btn.focus();
  }

  function toggleChat() {
    if (isOpen) closeChat();
    else openChat();
  }

  // ─── Build DOM ────────────────────────────────────────────────────────────
  function injectStyles() {
    var style = document.createElement('style');
    style.id = 'feval-chatbot-styles';
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  function buildWidget() {
    // Root wrapper (keeps everything scoped)
    var root = document.createElement('div');
    root.id = 'feval-chatbot-root';
    root.setAttribute('aria-live', 'polite');

    // ── Floating button ──
    var btn = document.createElement('button');
    btn.id = 'feval-chat-btn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Abrir chat de asistencia FEVAL');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.innerHTML = SVG_CHAT + SVG_CLOSE_BTN + '<div id="feval-notif-badge" aria-hidden="true"></div>';
    btn.addEventListener('click', toggleChat);
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) closeChat();
    });

    // ── Chat window ──
    var win = document.createElement('div');
    win.id = 'feval-chat-window';
    win.setAttribute('role', 'dialog');
    win.setAttribute('aria-label', 'Chat de asistencia FEVAL');
    win.setAttribute('aria-modal', 'true');

    // Header
    win.innerHTML = [
      '<div id="feval-chat-header">',
        '<div id="feval-chat-avatar" aria-hidden="true">' + SVG_BOT + '</div>',
        '<div id="feval-chat-header-info">',
          '<div id="feval-chat-title">Asistente FEVAL</div>',
          '<div id="feval-chat-subtitle">Formación TIC gratuita · Online</div>',
          '<div id="feval-chat-status">',
            '<div id="feval-status-dot" aria-hidden="true"></div>',
            '<span id="feval-status-text">En línea</span>',
          '</div>',
        '</div>',
        '<div id="feval-chat-actions">',
          '<button class="feval-header-btn" id="feval-clear-btn" type="button" ',
            'aria-label="Limpiar conversación" title="Limpiar conversación">',
            SVG_TRASH,
          '</button>',
          '<button class="feval-header-btn" id="feval-close-btn" type="button" ',
            'aria-label="Cerrar chat" title="Cerrar chat">',
            SVG_CLOSE_SM,
          '</button>',
        '</div>',
      '</div>',

      // Messages
      '<div id="feval-messages" role="log" aria-label="Conversación con el asistente FEVAL" aria-live="polite"></div>',

      // Quick replies
      '<div id="feval-quick-replies" aria-label="Preguntas rápidas"></div>',

      // Typing indicator
      '<div id="feval-typing" role="status" aria-label="El asistente está escribiendo">',
        '<div class="feval-msg-avatar" aria-hidden="true">' + SVG_BOT + '</div>',
        '<div class="feval-typing-bubble" aria-hidden="true">',
          '<div class="feval-typing-dot"></div>',
          '<div class="feval-typing-dot"></div>',
          '<div class="feval-typing-dot"></div>',
        '</div>',
      '</div>',

      // Input
      '<div id="feval-input-area">',
        '<textarea id="feval-textarea" rows="1" ',
          'placeholder="Escribe tu pregunta..." ',
          'aria-label="Escribe tu mensaje" ',
          'maxlength="2000"></textarea>',
        '<button id="feval-send-btn" type="button" aria-label="Enviar mensaje" disabled>',
          SVG_SEND,
        '</button>',
      '</div>',
    ].join('');

    root.appendChild(btn);
    root.appendChild(win);
    document.body.appendChild(root);

    // ── Quick reply chips ──
    var qrContainer = document.getElementById('feval-quick-replies');
    QUICK_REPLIES.forEach(function (qr) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'feval-qr-btn';
      chip.textContent = qr.label;
      chip.setAttribute('aria-label', qr.label);
      chip.addEventListener('click', function () {
        sendMessage(qr.text);
      });
      qrContainer.appendChild(chip);
    });

    // ── Input events ──
    var textarea = document.getElementById('feval-textarea');
    textarea.addEventListener('input', function () {
      // Auto-grow
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 100) + 'px';
      updateSendBtn(this.value);
    });

    textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage(this.value);
      }
    });

    var sendBtn = document.getElementById('feval-send-btn');
    sendBtn.addEventListener('click', function () {
      sendMessage(textarea.value);
    });

    // ── Close & clear buttons ──
    document.getElementById('feval-close-btn').addEventListener('click', closeChat);
    document.getElementById('feval-clear-btn').addEventListener('click', function () {
      if (isLoading) return;
      clearConversation();
    });

    // ── Close on Escape ──
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) closeChat();
    });

    // ── Show notification badge after 3s if not opened ──
    setTimeout(function () {
      if (!isOpen) {
        notificationDot = document.getElementById('feval-notif-badge');
        if (notificationDot) notificationDot.style.display = '';
      }
    }, 3000);
  }

  // ─── Init ─────────────────────────────────────────────────────────────────
  function init() {
    if (document.getElementById('feval-chatbot-root')) return; // already loaded
    injectStyles();
    buildWidget();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
