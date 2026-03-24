<?php
require_once __DIR__ . '/db.php';
session_start();

// ── Auth ──────────────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    if (password_verify($_POST['pass'] ?? '', cb_cfg('admin_pass'))) {
        $_SESSION['cb_auth'] = true;
        header('Location: ?'); exit;
    }
    $login_err = 'Contraseña incorrecta.';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

function auth_required(): void {
    if (empty($_SESSION['cb_auth'])) {
        header('Location: ?'); exit;
    }
}

// ── Actions ───────────────────────────────────────────────────────────────
if (!empty($_SESSION['cb_auth'])) {

    // Create bot
    if (($_POST['action'] ?? '') === 'create_bot') {
        $id = cb_id();
        cb_db()->prepare("INSERT INTO bots (id,name,description,instructions,welcome,placeholder,color,backend,model) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$id, trim($_POST['name']), trim($_POST['description'] ?? ''),
                trim($_POST['instructions'] ?? ''), trim($_POST['welcome'] ?? 'Hola, ¿en qué puedo ayudarte?'),
                trim($_POST['placeholder'] ?? 'Escribe tu pregunta...'),
                $_POST['color'] ?? '#2563eb', $_POST['backend'] ?? 'groq',
                $_POST['model'] ?? '']);
        header('Location: ?p=bot&id=' . $id); exit;
    }

    // Update bot
    if (($_POST['action'] ?? '') === 'update_bot') {
        $id = $_POST['bot_id'] ?? '';
        cb_db()->prepare("UPDATE bots SET name=?,description=?,instructions=?,welcome=?,placeholder=?,color=?,backend=?,model=? WHERE id=?")
            ->execute([trim($_POST['name']), trim($_POST['description'] ?? ''),
                trim($_POST['instructions'] ?? ''), trim($_POST['welcome'] ?? ''),
                trim($_POST['placeholder'] ?? ''), $_POST['color'] ?? '#2563eb',
                $_POST['backend'] ?? 'groq', $_POST['model'] ?? '', $id]);
        header('Location: ?p=bot&id=' . $id . '&saved=1'); exit;
    }

    // Delete bot
    if (($_POST['action'] ?? '') === 'delete_bot') {
        cb_db()->prepare("DELETE FROM bots WHERE id=?")->execute([$_POST['bot_id']]);
        header('Location: ?'); exit;
    }

    // Add source (text/faq inline — PDF/URL go through api.php via JS)
    if (($_POST['action'] ?? '') === 'add_source') {
        $bot_id  = $_POST['bot_id'] ?? '';
        $type    = $_POST['type'] ?? 'text';
        $name    = trim($_POST['name'] ?? 'Sin título');
        $content = trim($_POST['content'] ?? '');
        if ($bot_id && $content) {
            cb_db()->prepare("INSERT INTO sources (bot_id,type,name,content,chars) VALUES (?,?,?,?,?)")
                ->execute([$bot_id, $type, $name, $content, mb_strlen($content)]);
        }
        header('Location: ?p=bot&id=' . $bot_id . '&tab=sources'); exit;
    }

    // Delete source
    if (($_POST['action'] ?? '') === 'delete_source') {
        $sid    = (int)($_POST['source_id'] ?? 0);
        $bot_id = $_POST['bot_id'] ?? '';
        cb_db()->prepare("DELETE FROM sources WHERE id=? AND bot_id=?")->execute([$sid, $bot_id]);
        header('Location: ?p=bot&id=' . $bot_id . '&tab=sources'); exit;
    }

    // Save settings
    if (($_POST['action'] ?? '') === 'save_settings') {
        foreach (['groq_key', 'gemini_key', 'claude_key', 'ollama_url', 'ollama_model'] as $k) {
            if (isset($_POST[$k])) cb_set_cfg($k, trim($_POST[$k]));
        }
        if (!empty($_POST['new_pass'])) {
            cb_set_cfg('admin_pass', password_hash($_POST['new_pass'], PASSWORD_BCRYPT, ['cost' => 10]));
        }
        header('Location: ?p=settings&saved=1'); exit;
    }
}

// ── Page routing ──────────────────────────────────────────────────────────
$p = $_GET['p'] ?? '';

// Login page (no auth required)
if (!isset($_SESSION['cb_auth'])) { $p = 'login'; }

function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $dir;
}

// ── HTML helpers ──────────────────────────────────────────────────────────
function page_start(string $title, string $extra_css = ''): void { ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Chatbase</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-size:14px;font-weight:500;transition:opacity .15s}
.btn-primary{background:#2563eb;color:#fff}.btn-primary:hover{background:#1d4ed8}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-ghost{background:#f1f5f9;color:#374151;border:1px solid #e2e8f0}.btn-ghost:hover{background:#e2e8f0}
.btn-sm{padding:5px 11px;font-size:13px}
input,textarea,select{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:14px;color:#1e293b;outline:none;transition:border-color .2s}
input:focus,textarea:focus,select:focus{border-color:#2563eb}
label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
.field{margin-bottom:16px}
.card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:24px}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase}
.badge-groq{background:#dbeafe;color:#1d4ed8}
.badge-gemini{background:#fef9c3;color:#854d0e}
.badge-claude{background:#fce7f3;color:#9d174d}
.badge-ollama{background:#dcfce7;color:#15803d}
.nav{background:#fff;border-bottom:1px solid #e2e8f0;padding:0 24px;display:flex;align-items:center;gap:24px;height:56px}
.nav-brand{font-weight:700;font-size:17px;color:#1e293b;display:flex;align-items:center;gap:8px}
.nav-brand span{color:#2563eb}
.nav-links{display:flex;gap:4px;margin-left:auto}
.nav-link{padding:6px 12px;border-radius:7px;font-size:14px;color:#64748b;transition:background .15s}
.nav-link:hover,.nav-link.active{background:#f1f5f9;color:#1e293b;text-decoration:none}
.container{max-width:960px;margin:0 auto;padding:32px 20px}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
.page-title{font-size:22px;font-weight:700}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:640px){.grid-2{grid-template-columns:1fr}}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.alert-success{background:#dcfce7;color:#15803d;border:1px solid #86efac}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5}
.table{width:100%;border-collapse:collapse}
.table th{text-align:left;font-size:12px;color:#64748b;padding:8px 12px;border-bottom:2px solid #e2e8f0}
.table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:14px;vertical-align:middle}
.table tr:last-child td{border-bottom:none}
.tabs{display:flex;gap:2px;border-bottom:1px solid #e2e8f0;margin-bottom:24px}
.tab{padding:10px 16px;font-size:14px;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s}
.tab.active,.tab:hover{color:#2563eb}.tab.active{border-color:#2563eb}
<?= $extra_css ?>
</style>
</head>
<body>
<?php } ?>

<?php function nav_bar(): void { ?>
<nav class="nav">
  <div class="nav-brand">🤖 Chat<span>base</span></div>
  <div class="nav-links">
    <a href="?" class="nav-link <?= ($_GET['p'] ?? '') === '' ? 'active' : '' ?>">Bots</a>
    <a href="?p=settings" class="nav-link <?= ($_GET['p'] ?? '') === 'settings' ? 'active' : '' ?>">Ajustes</a>
    <a href="?logout" class="nav-link">Salir</a>
  </div>
</nav>
<?php } ?>

<?php
// ============================================================
//  LOGIN
// ============================================================
if ($p === 'login') {
    page_start('Acceder', '
    .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .login-box{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:40px 36px;width:100%;max-width:380px}
    .login-logo{font-size:28px;font-weight:800;text-align:center;margin-bottom:8px}
    .login-logo span{color:#2563eb}
    .login-sub{text-align:center;color:#64748b;font-size:14px;margin-bottom:28px}
    ');
    ?>
    <div class="login-wrap">
      <div class="login-box">
        <div class="login-logo">🤖 Chat<span>base</span></div>
        <div class="login-sub">Panel de administración</div>
        <?php if (!empty($login_err)): ?>
          <div class="alert alert-error"><?= h($login_err) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="field">
            <label>Contraseña</label>
            <input type="password" name="pass" autofocus placeholder="••••••••" required>
          </div>
          <button type="submit" name="login" class="btn btn-primary" style="width:100%;justify-content:center">Entrar</button>
        </form>
        <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:16px">Contraseña por defecto: <code>admin</code></p>
      </div>
    </div>
    </body></html>
    <?php exit;
}

// ============================================================
//  SETTINGS
// ============================================================
if ($p === 'settings') {
    auth_required();
    page_start('Ajustes');
    nav_bar(); ?>
    <div class="container">
      <div class="page-header">
        <h1 class="page-title">Ajustes</h1>
      </div>
      <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">✓ Guardado correctamente.</div>
      <?php endif; ?>
      <div class="card">
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <h3 style="margin-bottom:4px;font-size:16px">API Keys / LLM</h3>

          <!-- Gemini multi-key -->
          <div class="field" style="margin-top:16px">
            <label>Gemini API Keys <span style="font-weight:400;color:#64748b">(una por línea — se rotan automáticamente · fallback automático a Groq)</span></label>
            <textarea name="gemini_key" rows="3" style="font-family:monospace;font-size:13px" placeholder="AIza_key1...&#10;AIza_key2...&#10;AIza_key3..."><?= h(cb_cfg('gemini_key')) ?></textarea>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px">
              ⭐ Contexto 1M tokens · 1.500 req/día por cuenta · 5 cuentas = 7.500/día · Gratis en <a href="https://aistudio.google.com" target="_blank">aistudio.google.com</a>
            </div>
          </div>

          <!-- Groq multi-key -->
          <div class="field" style="margin-top:16px">
            <label>Groq API Keys <span style="font-weight:400;color:#64748b">(una por línea — se rotan automáticamente si una alcanza el límite)</span></label>
            <textarea name="groq_key" rows="3" style="font-family:monospace;font-size:13px" placeholder="gsk_key1...&#10;gsk_key2...&#10;gsk_key3..."><?= h(cb_cfg('groq_key')) ?></textarea>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px">
              💡 Cada cuenta gratuita da 14.400 req/día · 2 cuentas = 28.800/día · 3 cuentas = 43.200/día
            </div>
          </div>

          <div class="grid-2">
            <div class="field">
              <label>Claude (Anthropic) API Key</label>
              <textarea name="claude_key" rows="3" style="font-family:monospace;font-size:13px" placeholder="sk-ant-key1...&#10;sk-ant-key2..."><?= h(cb_cfg('claude_key')) ?></textarea>
            </div>
            <div class="field" style="display:flex;gap:12px">
              <div style="flex:1">
                <label>Ollama URL</label>
                <input type="text" name="ollama_url" value="<?= h(cb_cfg('ollama_url')) ?>" placeholder="http://localhost:11434">
              </div>
              <div style="flex:1">
                <label>Ollama Modelo</label>
                <input type="text" name="ollama_model" value="<?= h(cb_cfg('ollama_model')) ?>" placeholder="qwen2.5:7b">
              </div>
            </div>
          </div>
          <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">
          <h3 style="margin-bottom:20px;font-size:16px">Seguridad</h3>
          <div class="field" style="max-width:300px">
            <label>Nueva contraseña (dejar vacío para no cambiar)</label>
            <input type="password" name="new_pass" placeholder="••••••••">
          </div>
          <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </form>
      </div>
    </div>
    </body></html>
    <?php exit;
}

// ============================================================
//  BOT DETAIL (sources, embed, logs)
// ============================================================
if ($p === 'bot') {
    auth_required();
    $bot_id = $_GET['id'] ?? '';
    $s = cb_db()->prepare("SELECT * FROM bots WHERE id=?"); $s->execute([$bot_id]);
    $bot = $s->fetch();
    if (!$bot) { header('Location: ?'); exit; }

    $tab = $_GET['tab'] ?? 'general';
    page_start(h($bot['name']));
    nav_bar(); ?>
    <div class="container">
      <div class="page-header">
        <div>
          <a href="?" style="font-size:13px;color:#64748b">← Todos los bots</a>
          <h1 class="page-title" style="margin-top:4px"><?= h($bot['name']) ?></h1>
        </div>
        <span class="badge badge-<?= h($bot['backend']) ?>"><?= h($bot['backend']) ?></span>
      </div>
      <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">✓ Bot actualizado.</div>
      <?php endif; ?>

      <div class="tabs">
        <?php foreach (['general'=>'General','sources'=>'Fuentes','embed'=>'Embed','logs'=>'Conversaciones'] as $t=>$tl): ?>
          <a href="?p=bot&id=<?= h($bot_id) ?>&tab=<?= $t ?>" class="tab <?= $tab===$t?'active':'' ?>"><?= $tl ?></a>
        <?php endforeach; ?>
      </div>

      <?php if ($tab === 'general'): ?>
      <!-- GENERAL -->
      <div class="card">
        <form method="post">
          <input type="hidden" name="action" value="update_bot">
          <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">

          <!-- Identidad -->
          <h3 style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px">Identidad</h3>
          <div class="grid-2">
            <div class="field">
              <label>Nombre del bot</label>
              <input type="text" name="name" value="<?= h($bot['name']) ?>" required>
            </div>
            <div class="field">
              <label>Descripción (header del widget)</label>
              <input type="text" name="description" value="<?= h($bot['description']) ?>" placeholder="Asistente virtual de...">
            </div>
          </div>

          <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">

          <!-- Modelo -->
          <h3 style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px">Modelo</h3>
          <div class="grid-2">
            <div class="field">
              <label>Backend</label>
              <select name="backend" id="backend-sel" onchange="updateModels(this.value)">
                <?php foreach (['gemini','groq','claude','ollama'] as $b): ?>
                  <option value="<?= $b ?>" <?= $bot['backend']===$b?'selected':'' ?>><?= ucfirst($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Modelo específico</label>
              <select name="model" id="model-sel">
                <?php
                $models = [
                  'gemini' => ['gemini-2.0-flash'=>'Gemini 2.0 Flash (recomendado)','gemini-2.0-flash-lite'=>'Gemini 2.0 Flash Lite (más rápido)','gemini-1.5-flash'=>'Gemini 1.5 Flash','gemini-1.5-pro'=>'Gemini 1.5 Pro (más potente)'],
                  'groq'   => ['llama-3.3-70b-versatile'=>'Llama 3.3 70B (128k ctx)','llama-3.1-8b-instant'=>'Llama 3.1 8B (rápido)','mixtral-8x7b-32768'=>'Mixtral 8x7B','gemma2-9b-it'=>'Gemma2 9B'],
                  'claude' => ['claude-haiku-4-5-20251001'=>'Claude Haiku (rápido)','claude-sonnet-4-6'=>'Claude Sonnet (potente, 64k respuesta)'],
                  'ollama' => [''=>'(usa el modelo de Ajustes)','llama3.2'=>'Llama 3.2','qwen2.5:7b'=>'Qwen2.5 7B','mistral'=>'Mistral'],
                ];
                $cur_back  = $bot['backend'];
                $cur_model = $bot['model'] ?? '';
                foreach ($models[$cur_back] as $mv => $ml):
                ?>
                  <option value="<?= h($mv) ?>" <?= $cur_model===$mv?'selected':'' ?>><?= h($ml) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">

          <!-- Personalidad -->
          <h3 style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px">Personalidad del agente</h3>

          <div class="field">
            <label>Template de instrucciones</label>
            <select id="tpl-sel" onchange="applyTemplate(this.value)" style="width:auto;min-width:220px">
              <option value="">— Seleccionar template —</option>
              <option value="general">Agente general</option>
              <option value="support">Soporte al cliente</option>
              <option value="faq">Bot de FAQs</option>
              <option value="sales">Asistente de ventas</option>
              <option value="feval">FEVAL Formación</option>
            </select>
          </div>

          <div class="field">
            <label>Instrucciones del sistema</label>
            <textarea name="instructions" id="instructions" rows="10" placeholder="Describe el rol, comportamiento y restricciones del bot..."><?= h($bot['instructions']) ?></textarea>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px">Puedes usar las secciones: ### Business Context / ### Role / ### Constraints</div>
          </div>

          <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">

          <!-- Widget -->
          <h3 style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px">Widget</h3>
          <div class="grid-2">
            <div class="field">
              <label>Mensaje de bienvenida</label>
              <input type="text" name="welcome" value="<?= h($bot['welcome']) ?>">
            </div>
            <div class="field">
              <label>Placeholder del textarea</label>
              <input type="text" name="placeholder" value="<?= h($bot['placeholder']) ?>">
            </div>
            <div class="field">
              <label>Color principal</label>
              <input type="color" name="color" value="<?= h($bot['color']) ?>" style="height:42px;cursor:pointer">
            </div>
          </div>

          <div style="display:flex;gap:12px;align-items:center;margin-top:8px">
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
          </div>
        </form>
        <form method="post" style="margin:8px 0 0 0" onsubmit="return confirm('¿Eliminar bot y todos sus datos?')">
          <input type="hidden" name="action" value="delete_bot">
          <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">
          <button type="submit" class="btn btn-danger btn-sm">Eliminar bot</button>
        </form>
      </div>

<script>
var MODELS = <?= json_encode($models) ?>;
function updateModels(backend) {
  var sel = document.getElementById('model-sel');
  sel.innerHTML = '';
  var opts = MODELS[backend] || {};
  Object.entries(opts).forEach(function([v,l]){ var o=document.createElement('option'); o.value=v; o.textContent=l; sel.appendChild(o); });
}
var TEMPLATES = {
  general: "### Business Context\n[Describe tu empresa o servicio aquí]\n\n### Role\n- Primary Function: Eres un asistente de IA que ayuda a los usuarios con sus consultas. Ofrece respuestas claras, amables y eficientes.\n- Si una pregunta no está clara, pide aclaraciones.\n- Finaliza siempre con una nota positiva.\n\n### Constraints\n1. No Divulgar Datos: Nunca menciones explícitamente que tienes acceso a datos de entrenamiento.\n2. Mantener el Foco: Si el usuario intenta desviar la conversación, redirige educadamente.\n3. Uso Exclusivo de los Datos: Responde solo basándote en la información proporcionada.",
  support: "### Business Context\n[Nombre empresa] es [descripción del negocio].\n\n### Role\n- Eres el agente de soporte al cliente de [empresa].\n- Tu objetivo es resolver dudas, problemas técnicos e incidencias de forma rápida y empática.\n- Si no puedes resolver el problema, escala al equipo humano indicando: soporte@empresa.com\n\n### Constraints\n1. No inventes información sobre productos o políticas.\n2. Si no sabes la respuesta, dilo claramente y proporciona el contacto de soporte.\n3. Mantén siempre un tono profesional y empático.",
  faq: "### Role\n- Eres un bot de preguntas frecuentes.\n- Responde únicamente con la información de la base de conocimiento.\n- Si la pregunta no está en la base de conocimiento, responde: 'No tengo información sobre eso. Puedes contactar con nosotros en [email].'\n\n### Constraints\n1. No inventes respuestas.\n2. Sé conciso y directo.\n3. Si una FAQ tiene múltiples partes, responde por pasos.",
  sales: "### Business Context\n[Empresa] ofrece [productos/servicios].\n\n### Role\n- Eres un asistente de ventas amable y profesional.\n- Tu objetivo es informar sobre productos, precios y disponibilidad.\n- Guía al usuario hacia la compra de forma natural, sin ser agresivo.\n- Para cerrar ventas, dirige al usuario a: [URL de compra o contacto]\n\n### Constraints\n1. No prometas descuentos o condiciones que no estén confirmados.\n2. Si el precio no está en la base de conocimiento, indica que se contacte con ventas.\n3. Mantén siempre un tono positivo y orientado al cliente.",
  feval: "### Business Context\nFEVAL es una iniciativa de formación desarrollada en colaboración con SEXPE para ofrecer educación digital de alta calidad a través del 'Plan Formativo 2026'. La plataforma ofrece 70 cursos online en 9 áreas temáticas, incluyendo IA, Big Data, Ciberseguridad y Desarrollo de Software, diseñados para empleados y desempleados de Extremadura. Todos los cursos son GRATUITOS.\n\n### Role\n- Primary Function: Eres un asistente de IA que ayuda a los usuarios con sus consultas sobre cursos, preinscripción, diplomas y cualquier duda sobre la formación.\n- Escucha atentamente al usuario, comprende sus necesidades y ayúdale o dirígele a los recursos apropiados.\n- Si una pregunta no está clara, solicita aclaraciones.\n- Finaliza siempre con una nota positiva.\n\n### Constraints\n1. No Divulgar Datos: Nunca menciones explícitamente que tienes acceso a datos de entrenamiento.\n2. Mantener el Foco: Si el usuario intenta desviar la conversación a temas no relacionados, redirige educadamente.\n3. Uso Exclusivo de los Datos: Responde solo basándote en la información de formación proporcionada.\n4. Si no encuentras la respuesta, indica: 'Para más información contacta con formacion@feval.com o llama al 924 829 100.'"
};
function applyTemplate(key) {
  if (!key) return;
  if (document.getElementById('instructions').value.trim() && !confirm('¿Reemplazar las instrucciones actuales con el template?')) {
    document.getElementById('tpl-sel').value = ''; return;
  }
  document.getElementById('instructions').value = TEMPLATES[key] || '';
  document.getElementById('tpl-sel').value = '';
}
</script>

      <?php elseif ($tab === 'sources'): ?>
      <!-- SOURCES -->
      <?php
        $srcs = cb_db()->prepare("SELECT * FROM sources WHERE bot_id=? ORDER BY created_at DESC");
        $srcs->execute([$bot_id]);
        $sources = $srcs->fetchAll();
        $total_chars = array_sum(array_column($sources, 'chars'));
      ?>
      <div class="card" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
          <h3 style="font-size:15px"><?= count($sources) ?> fuentes · <?= number_format($total_chars) ?> caracteres</h3>
        </div>
        <?php if ($sources): ?>
        <table class="table">
          <thead><tr><th>Tipo</th><th>Nombre</th><th>Chars</th><th>Fecha</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($sources as $src): ?>
            <tr>
              <td><span class="badge" style="background:#f1f5f9;color:#374151"><?= h($src['type']) ?></span></td>
              <td><?= h($src['name']) ?></td>
              <td><?= number_format($src['chars']) ?></td>
              <td style="color:#94a3b8;font-size:12px"><?= h(substr($src['created_at'],0,10)) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('¿Eliminar esta fuente?')">
                  <input type="hidden" name="action" value="delete_source">
                  <input type="hidden" name="source_id" value="<?= (int)$src['id'] ?>">
                  <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">🗑</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p style="color:#94a3b8;font-size:14px">Sin fuentes aún. Añade contenido abajo.</p>
        <?php endif; ?>
      </div>

      <!-- Add source form -->
      <div class="card">
        <h3 style="font-size:15px;margin-bottom:16px">Añadir fuente</h3>
        <div class="tabs" id="src-tabs" style="margin-bottom:20px">
          <span class="tab active" onclick="showSrcTab('text',this)">Texto</span>
          <span class="tab" onclick="showSrcTab('faq',this)">FAQ</span>
          <span class="tab" onclick="showSrcTab('url',this)">URL</span>
          <span class="tab" onclick="showSrcTab('pdf',this)">PDF</span>
          <span class="tab" onclick="showSrcTab('site',this)">🌐 Sitio web</span>
        </div>

        <!-- TEXT -->
        <div id="src-text">
          <form method="post">
            <input type="hidden" name="action" value="add_source">
            <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">
            <input type="hidden" name="type" value="text">
            <div class="field"><label>Nombre / Descripción</label><input type="text" name="name" placeholder="Información general" required></div>
            <div class="field"><label>Contenido</label><textarea name="content" rows="7" placeholder="Pega aquí el texto que el bot debe conocer..." required></textarea></div>
            <button class="btn btn-primary" type="submit">Añadir texto</button>
          </form>
        </div>

        <!-- FAQ -->
        <div id="src-faq" style="display:none">
          <form method="post">
            <input type="hidden" name="action" value="add_source">
            <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">
            <input type="hidden" name="type" value="faq">
            <div class="field"><label>Nombre</label><input type="text" name="name" value="FAQs" required></div>
            <div class="field"><label>Pares Pregunta / Respuesta (formato libre)</label>
              <textarea name="content" rows="8" placeholder="P: ¿Cuál es el precio?&#10;R: El servicio es gratuito.&#10;&#10;P: ¿Hay soporte 24h?&#10;R: Sí, por email." required></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Añadir FAQs</button>
          </form>
        </div>

        <!-- URL -->
        <div id="src-url" style="display:none">
          <div class="field"><label>URL a crawlear</label><input type="url" id="crawl-url" placeholder="https://ejemplo.com/sobre-nosotros"></div>
          <div class="field"><label>Nombre</label><input type="text" id="crawl-name" placeholder="Página de ayuda"></div>
          <button class="btn btn-primary" onclick="crawlURL('<?= h($bot_id) ?>')">Importar URL</button>
          <span id="crawl-status" style="font-size:13px;color:#64748b;margin-left:12px"></span>
        </div>

        <!-- PDF -->
        <div id="src-pdf" style="display:none">
          <div class="field"><label>Archivo PDF</label><input type="file" id="pdf-file" accept=".pdf"></div>
          <div class="field"><label>Nombre</label><input type="text" id="pdf-name" placeholder="Manual de usuario"></div>
          <button class="btn btn-primary" onclick="uploadPDF('<?= h($bot_id) ?>')">Subir PDF</button>
          <span id="pdf-status" style="font-size:13px;color:#64748b;margin-left:12px"></span>
        </div>

        <!-- SITIO WEB -->
        <div id="src-site" style="display:none">
          <p style="font-size:13px;color:#64748b;margin-bottom:14px">Introduce la URL raíz de tu sitio y descubriremos automáticamente todas las páginas enlazadas.</p>
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:260px;margin-bottom:0">
              <label>URL del sitio web</label>
              <input type="url" id="site-url" placeholder="https://ejemplo.com">
            </div>
            <button class="btn btn-primary" onclick="discoverSite('<?= h($bot_id) ?>')">🔍 Descubrir páginas</button>
            <span id="site-status" style="font-size:13px;color:#64748b;align-self:center"></span>
          </div>
          <div id="site-pages" style="display:none;margin-top:18px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <label style="font-weight:600;font-size:14px" id="site-count"></label>
              <div style="display:flex;gap:8px">
                <button class="btn btn-ghost btn-sm" onclick="toggleAllPages(true)">Seleccionar todas</button>
                <button class="btn btn-ghost btn-sm" onclick="toggleAllPages(false)">Ninguna</button>
              </div>
            </div>
            <div id="site-list" style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:6px"></div>
            <div style="display:flex;align-items:center;gap:12px;margin-top:12px">
              <button class="btn btn-primary" onclick="crawlSelected('<?= h($bot_id) ?>')">⬇ Importar seleccionadas</button>
              <span id="crawl-progress" style="font-size:13px;color:#64748b"></span>
            </div>
          </div>
        </div>
      </div>

      <?php elseif ($tab === 'embed'): ?>
      <!-- EMBED -->
      <?php $base = base_url(); ?>
      <div class="card">
        <h3 style="font-size:15px;margin-bottom:8px">Código de embed</h3>
        <p style="font-size:13px;color:#64748b;margin-bottom:16px">Pega este código antes de <code>&lt;/body&gt;</code> en cualquier página.</p>
        <textarea readonly id="embed-code" style="font-family:monospace;font-size:12px;background:#f8fafc;resize:none;height:120px"><?= h('<script>
window.CHATBASE_URL   = \'' . $base . '\';
window.CHATBASE_BOT_ID = \'' . $bot_id . '\';
</script>
<script src="' . $base . '/widget.js"></script>') ?></textarea>
        <button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="copyEmbed()">📋 Copiar</button>
        <hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0">
        <h3 style="font-size:15px;margin-bottom:8px">Probar ahora</h3>
        <p style="font-size:13px;color:#64748b;margin-bottom:16px">Preview del widget directamente aquí.</p>
        <script>
        window.CHATBASE_URL    = '<?= h($base) ?>';
        window.CHATBASE_BOT_ID = '<?= h($bot_id) ?>';
        </script>
        <script src="<?= h($base) ?>/widget.js?v=<?= filemtime(__DIR__.'/widget.js') ?>"></script>
      </div>

      <?php elseif ($tab === 'logs'): ?>
      <!-- LOGS -->
      <?php
        $msgs = cb_db()->prepare("SELECT session_id, role, content, created_at FROM messages WHERE bot_id=? ORDER BY created_at DESC LIMIT 200");
        $msgs->execute([$bot_id]);
        $all_msgs = $msgs->fetchAll();
        $sessions = [];
        foreach ($all_msgs as $m) {
            $sessions[$m['session_id']][] = $m;
        }
      ?>
      <div style="color:#64748b;font-size:13px;margin-bottom:16px"><?= count($sessions) ?> conversaciones · <?= count($all_msgs) ?> mensajes (últimos 200)</div>
      <?php if (!$sessions): ?>
        <div class="card"><p style="color:#94a3b8;font-size:14px">Sin conversaciones aún.</p></div>
      <?php else: ?>
        <?php foreach ($sessions as $sid => $msgs): ?>
          <div class="card" style="margin-bottom:12px">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:10px">Sesión: <?= h($sid) ?> · <?= h(substr($msgs[0]['created_at'],0,16)) ?></div>
            <?php foreach (array_reverse($msgs) as $m): ?>
              <div style="display:flex;gap:8px;margin-bottom:8px;<?= $m['role']==='user'?'flex-direction:row-reverse':'' ?>">
                <div style="font-size:12px;font-weight:600;color:<?= $m['role']==='user'?'#2563eb':'#64748b' ?>;flex-shrink:0;padding-top:2px">
                  <?= $m['role']==='user'?'Tú':'Bot' ?>
                </div>
                <div style="background:<?= $m['role']==='user'?'#dbeafe':'#f1f5f9' ?>;padding:8px 12px;border-radius:10px;font-size:13px;max-width:85%;white-space:pre-wrap"><?= h($m['content']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php endif; ?>

    </div>

<script>
function showSrcTab(type, el) {
  ['text','faq','url','pdf','site'].forEach(t => {
    document.getElementById('src-' + t).style.display = t === type ? '' : 'none';
  });
  document.querySelectorAll('#src-tabs .tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
}
function discoverSite(botId) {
  var url = document.getElementById('site-url').value.trim();
  if (!url) { alert('Introduce una URL'); return; }
  var st = document.getElementById('site-status');
  st.textContent = 'Descubriendo...';
  document.getElementById('site-pages').style.display = 'none';
  fetch('api.php?action=discover_site', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({url: url})
  }).then(r => r.json()).then(d => {
    if (!d.ok) { st.textContent = '✗ ' + (d.error || 'Error'); return; }
    st.textContent = '';
    document.getElementById('site-count').textContent = d.pages.length + ' páginas encontradas';
    var list = document.getElementById('site-list');
    list.innerHTML = '';
    d.pages.forEach(function(p) {
      var row = document.createElement('label');
      row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 8px;cursor:pointer;border-radius:4px;font-size:13px;transition:background .1s';
      row.onmouseover = function(){ this.style.background='#f8fafc'; };
      row.onmouseout  = function(){ this.style.background=''; };
      var cb = document.createElement('input');
      cb.type = 'checkbox'; cb.checked = true;
      cb.dataset.url  = p.url;
      cb.dataset.name = p.text || p.url;
      var txt = document.createElement('span');
      txt.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
      txt.title = p.url;
      txt.textContent = p.text || p.url;
      var path = document.createElement('span');
      path.style.cssText = 'color:#94a3b8;font-size:11px;white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis';
      path.textContent = p.url.replace(/^https?:\/\/[^\/]+/, '') || '/';
      row.appendChild(cb); row.appendChild(txt); row.appendChild(path);
      list.appendChild(row);
    });
    document.getElementById('site-pages').style.display = '';
  }).catch(function() { st.textContent = '✗ Error de red'; });
}
function toggleAllPages(checked) {
  document.querySelectorAll('#site-list input[type=checkbox]').forEach(function(cb){ cb.checked = checked; });
}
function crawlSelected(botId) {
  var checked = Array.from(document.querySelectorAll('#site-list input[type=checkbox]:checked'));
  if (!checked.length) { alert('Selecciona al menos una página'); return; }
  var urls = checked.map(function(cb){ return {url: cb.dataset.url, name: cb.dataset.name}; });
  var prog = document.getElementById('crawl-progress');
  prog.textContent = 'Importando ' + urls.length + ' página' + (urls.length>1?'s':'') + '...';
  fetch('api.php?action=crawl_pages', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({bot_id: botId, urls: urls})
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      prog.textContent = '✓ ' + d.added + ' importadas' + (d.failed ? ', ' + d.failed + ' fallidas' : '');
      setTimeout(function(){ location.reload(); }, 1500);
    } else {
      prog.textContent = '✗ ' + (d.error || 'Error');
    }
  }).catch(function(){ prog.textContent = '✗ Error de red'; });
}
function copyEmbed() {
  var t = document.getElementById('embed-code');
  t.select(); document.execCommand('copy');
  alert('¡Copiado!');
}
function crawlURL(botId) {
  var url  = document.getElementById('crawl-url').value.trim();
  var name = document.getElementById('crawl-name').value.trim() || url;
  var st   = document.getElementById('crawl-status');
  if (!url) { alert('Introduce una URL'); return; }
  st.textContent = 'Importando...';
  var fd = new FormData();
  fd.append('bot_id', botId); fd.append('type','url');
  fd.append('name', name);    fd.append('url', url);
  fetch('api.php?action=add_source', {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{
      if (d.ok) { st.textContent='✓ Importado (' + d.chars + ' chars)'; setTimeout(()=>location.reload(),1000); }
      else st.textContent = '✗ ' + (d.error||'Error');
    }).catch(()=>{ st.textContent='✗ Error de red'; });
}
function uploadPDF(botId) {
  var file = document.getElementById('pdf-file').files[0];
  var name = document.getElementById('pdf-name').value.trim();
  var st   = document.getElementById('pdf-status');
  if (!file) { alert('Selecciona un PDF'); return; }
  st.textContent = 'Subiendo...';
  var fd = new FormData();
  fd.append('bot_id', botId); fd.append('type','pdf');
  fd.append('name', name || file.name); fd.append('file', file);
  fetch('api.php?action=add_source', {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{
      if (d.ok) { st.textContent='✓ Subido (' + d.chars + ' chars)'; setTimeout(()=>location.reload(),1000); }
      else st.textContent = '✗ ' + (d.error||'Error');
    }).catch(()=>{ st.textContent='✗ Error de red'; });
}
</script>
</body></html>
<?php exit; }

// ============================================================
//  NEW BOT
// ============================================================
if ($p === 'new') {
    auth_required();
    page_start('Nuevo bot');
    nav_bar(); ?>
    <div class="container">
      <div class="page-header">
        <h1 class="page-title">Crear nuevo bot</h1>
        <a href="?" class="btn btn-ghost">Cancelar</a>
      </div>
      <div class="card">
        <form method="post">
          <input type="hidden" name="action" value="create_bot">
          <div class="grid-2">
            <div class="field">
              <label>Nombre del bot *</label>
              <input type="text" name="name" required autofocus placeholder="Mi asistente">
            </div>
            <div class="field">
              <label>Backend LLM</label>
              <select name="backend">
                <option value="gemini">Gemini Flash (1M tokens, gratis)</option>
                <option value="groq">Groq (llama-3.3-70b, gratis)</option>
                <option value="claude">Claude (Anthropic, de pago)</option>
                <option value="ollama">Ollama (local, gratis)</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Descripción</label>
            <input type="text" name="description" placeholder="Asistente virtual de atención al cliente">
          </div>
          <div class="field">
            <label>Template de instrucciones</label>
            <select id="new-tpl-sel" onchange="applyNewTemplate(this.value)" style="width:auto;min-width:220px">
              <option value="">— Seleccionar template —</option>
              <option value="general">Agente general</option>
              <option value="support">Soporte al cliente</option>
              <option value="faq">Bot de FAQs</option>
              <option value="sales">Asistente de ventas</option>
              <option value="feval">FEVAL Formación</option>
            </select>
          </div>
          <div class="field">
            <label>Instrucciones del sistema</label>
            <textarea name="instructions" id="new-instructions" rows="6" placeholder="Eres un asistente amable de... Responde siempre en español. Si no sabes la respuesta, dilo claramente."></textarea>
          </div>
          <div class="grid-2">
            <div class="field">
              <label>Mensaje de bienvenida</label>
              <input type="text" name="welcome" value="Hola, ¿en qué puedo ayudarte?">
            </div>
            <div class="field">
              <label>Placeholder</label>
              <input type="text" name="placeholder" value="Escribe tu pregunta...">
            </div>
            <div class="field">
              <label>Color principal</label>
              <input type="color" name="color" value="#2563eb" style="height:42px;cursor:pointer">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Crear bot →</button>
        </form>
      </div>
    </div>
<script>
var NEW_TEMPLATES = {
  general: "### Business Context\n[Describe tu empresa o servicio aquí]\n\n### Role\n- Primary Function: Eres un asistente de IA que ayuda a los usuarios con sus consultas. Ofrece respuestas claras, amables y eficientes.\n- Si una pregunta no está clara, pide aclaraciones.\n- Finaliza siempre con una nota positiva.\n\n### Constraints\n1. No Divulgar Datos: Nunca menciones explícitamente que tienes acceso a datos de entrenamiento.\n2. Mantener el Foco: Si el usuario intenta desviar la conversación, redirige educadamente.\n3. Uso Exclusivo de los Datos: Responde solo basándote en la información proporcionada.",
  support: "### Business Context\n[Nombre empresa] es [descripción del negocio].\n\n### Role\n- Eres el agente de soporte al cliente de [empresa].\n- Tu objetivo es resolver dudas, problemas técnicos e incidencias de forma rápida y empática.\n- Si no puedes resolver el problema, escala al equipo humano indicando: soporte@empresa.com\n\n### Constraints\n1. No inventes información sobre productos o políticas.\n2. Si no sabes la respuesta, dilo claramente y proporciona el contacto de soporte.\n3. Mantén siempre un tono profesional y empático.",
  faq: "### Role\n- Eres un bot de preguntas frecuentes.\n- Responde únicamente con la información de la base de conocimiento.\n- Si la pregunta no está en la base de conocimiento, responde: 'No tengo información sobre eso. Puedes contactar con nosotros en [email].'\n\n### Constraints\n1. No inventes respuestas.\n2. Sé conciso y directo.\n3. Si una FAQ tiene múltiples partes, responde por pasos.",
  sales: "### Business Context\n[Empresa] ofrece [productos/servicios].\n\n### Role\n- Eres un asistente de ventas amable y profesional.\n- Tu objetivo es informar sobre productos, precios y disponibilidad.\n- Guía al usuario hacia la compra de forma natural, sin ser agresivo.\n- Para cerrar ventas, dirige al usuario a: [URL de compra o contacto]\n\n### Constraints\n1. No prometas descuentos o condiciones que no estén confirmados.\n2. Si el precio no está en la base de conocimiento, indica que se contacte con ventas.\n3. Mantén siempre un tono positivo y orientado al cliente.",
  feval: "### Business Context\nFEVAL es una iniciativa de formación desarrollada en colaboración con SEXPE para ofrecer educación digital de alta calidad a través del 'Plan Formativo 2026'. La plataforma ofrece 70 cursos online en 9 áreas temáticas, incluyendo IA, Big Data, Ciberseguridad y Desarrollo de Software, diseñados para empleados y desempleados de Extremadura. Todos los cursos son GRATUITOS.\n\n### Role\n- Primary Function: Eres un asistente de IA que ayuda a los usuarios con sus consultas sobre cursos, preinscripción, diplomas y cualquier duda sobre la formación.\n- Escucha atentamente al usuario, comprende sus necesidades y ayúdale o dirígele a los recursos apropiados.\n- Si una pregunta no está clara, solicita aclaraciones.\n- Finaliza siempre con una nota positiva.\n\n### Constraints\n1. No Divulgar Datos: Nunca menciones explícitamente que tienes acceso a datos de entrenamiento.\n2. Mantener el Foco: Si el usuario intenta desviar la conversación a temas no relacionados, redirige educadamente.\n3. Uso Exclusivo de los Datos: Responde solo basándote en la información de formación proporcionada.\n4. Si no encuentras la respuesta, indica: 'Para más información contacta con formacion@feval.com o llama al 924 829 100.'"
};
function applyNewTemplate(key) {
  if (!key) return;
  document.getElementById('new-instructions').value = NEW_TEMPLATES[key] || '';
  document.getElementById('new-tpl-sel').value = '';
}
</script>
    </body></html>
    <?php exit;
}

// ============================================================
//  DASHBOARD (default)
// ============================================================
auth_required();
$bots_q = cb_db()->query("
  SELECT b.*,
    (SELECT COUNT(*) FROM sources WHERE bot_id=b.id) AS src_count,
    (SELECT COUNT(*) FROM messages WHERE bot_id=b.id) AS msg_count
  FROM bots b ORDER BY b.created_at DESC
");
$bots = $bots_q->fetchAll();
page_start('Dashboard');
nav_bar(); ?>
<div class="container">
  <div class="page-header">
    <h1 class="page-title">Mis Bots</h1>
    <a href="?p=new" class="btn btn-primary">+ Nuevo bot</a>
  </div>
  <?php if (!$bots): ?>
    <div class="card" style="text-align:center;padding:48px 24px">
      <div style="font-size:40px;margin-bottom:12px">🤖</div>
      <h2 style="font-size:18px;margin-bottom:8px">Todavía no tienes ningún bot</h2>
      <p style="color:#64748b;margin-bottom:20px">Crea tu primer bot y entrénalo con tus documentos.</p>
      <a href="?p=new" class="btn btn-primary">+ Crear primer bot</a>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
      <?php foreach ($bots as $b): ?>
        <div class="card" style="display:flex;flex-direction:column;gap:12px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
            <div style="width:42px;height:42px;border-radius:10px;background:<?= h($b['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px">🤖</div>
            <span class="badge badge-<?= h($b['backend']) ?>"><?= h($b['backend']) ?></span>
          </div>
          <div>
            <div style="font-weight:700;font-size:16px"><?= h($b['name']) ?></div>
            <?php if ($b['description']): ?>
              <div style="font-size:13px;color:#64748b;margin-top:3px"><?= h(mb_substr($b['description'],0,80)) ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:16px;font-size:12px;color:#94a3b8">
            <span>📄 <?= $b['src_count'] ?> fuentes</span>
            <span>💬 <?= $b['msg_count'] ?> mensajes</span>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px">
            <a href="?p=bot&id=<?= h($b['id']) ?>" class="btn btn-ghost btn-sm">Gestionar</a>
            <a href="?p=bot&id=<?= h($b['id']) ?>&tab=embed" class="btn btn-ghost btn-sm">Embed</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body></html>
