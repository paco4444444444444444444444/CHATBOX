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
        cb_db()->prepare("INSERT INTO bots (id,name,description,instructions,welcome,placeholder,color,backend) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$id, trim($_POST['name']), trim($_POST['description'] ?? ''),
                trim($_POST['instructions'] ?? ''), trim($_POST['welcome'] ?? 'Hola, ¿en qué puedo ayudarte?'),
                trim($_POST['placeholder'] ?? 'Escribe tu pregunta...'),
                $_POST['color'] ?? '#2563eb', $_POST['backend'] ?? 'groq']);
        header('Location: ?p=bot&id=' . $id); exit;
    }

    // Update bot
    if (($_POST['action'] ?? '') === 'update_bot') {
        $id = $_POST['bot_id'] ?? '';
        cb_db()->prepare("UPDATE bots SET name=?,description=?,instructions=?,welcome=?,placeholder=?,color=?,backend=? WHERE id=?")
            ->execute([trim($_POST['name']), trim($_POST['description'] ?? ''),
                trim($_POST['instructions'] ?? ''), trim($_POST['welcome'] ?? ''),
                trim($_POST['placeholder'] ?? ''), $_POST['color'] ?? '#2563eb',
                $_POST['backend'] ?? 'groq', $id]);
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
        foreach (['groq_key', 'claude_key', 'ollama_url', 'ollama_model'] as $k) {
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
          <h3 style="margin-bottom:20px;font-size:16px">API Keys / LLM</h3>
          <div class="grid-2">
            <div class="field">
              <label>Groq API Key</label>
              <input type="password" name="groq_key" value="<?= h(cb_cfg('groq_key')) ?>" placeholder="gsk_...">
            </div>
            <div class="field">
              <label>Claude (Anthropic) API Key</label>
              <input type="password" name="claude_key" value="<?= h(cb_cfg('claude_key')) ?>" placeholder="sk-ant-...">
            </div>
            <div class="field">
              <label>Ollama URL</label>
              <input type="text" name="ollama_url" value="<?= h(cb_cfg('ollama_url')) ?>" placeholder="http://localhost:11434">
            </div>
            <div class="field">
              <label>Ollama Modelo</label>
              <input type="text" name="ollama_model" value="<?= h(cb_cfg('ollama_model')) ?>" placeholder="qwen2.5:7b">
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
          <div class="grid-2">
            <div class="field">
              <label>Nombre del bot</label>
              <input type="text" name="name" value="<?= h($bot['name']) ?>" required>
            </div>
            <div class="field">
              <label>Backend LLM</label>
              <select name="backend">
                <?php foreach (['groq','claude','ollama'] as $b): ?>
                  <option value="<?= $b ?>" <?= $bot['backend']===$b?'selected':'' ?>><?= ucfirst($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Descripción (visible en el header del widget)</label>
            <input type="text" name="description" value="<?= h($bot['description']) ?>" placeholder="Asistente virtual de...">
          </div>
          <div class="field">
            <label>Instrucciones del sistema (comportamiento del bot)</label>
            <textarea name="instructions" rows="5" placeholder="Eres un asistente de... Responde siempre en español..."><?= h($bot['instructions']) ?></textarea>
          </div>
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
          <div style="display:flex;gap:12px;align-items:center;margin-top:4px">
            <button type="submit" class="btn btn-primary">Guardar</button>
            <form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar bot y todos sus datos?')">
              <input type="hidden" name="action" value="delete_bot">
              <input type="hidden" name="bot_id" value="<?= h($bot_id) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Eliminar bot</button>
            </form>
          </div>
        </form>
      </div>

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
        <script src="<?= h($base) ?>/widget.js"></script>
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
  ['text','faq','url','pdf'].forEach(t => {
    document.getElementById('src-' + t).style.display = t === type ? '' : 'none';
  });
  document.querySelectorAll('#src-tabs .tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
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
            <label>Instrucciones del sistema</label>
            <textarea name="instructions" rows="4" placeholder="Eres un asistente amable de... Responde siempre en español. Si no sabes la respuesta, dilo claramente."></textarea>
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
