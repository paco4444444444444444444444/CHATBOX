<?php
// chatbase/db.php — SQLite helpers + schema

define('CB_DB_FILE', __DIR__ . '/chatbase.sqlite');

function cb_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . CB_DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
        cb_schema($pdo);
    }
    return $pdo;
}

function cb_schema(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS bots (
        id           TEXT PRIMARY KEY,
        name         TEXT NOT NULL,
        description  TEXT NOT NULL DEFAULT '',
        instructions TEXT NOT NULL DEFAULT '',
        welcome      TEXT NOT NULL DEFAULT 'Hola, ¿en qué puedo ayudarte?',
        placeholder  TEXT NOT NULL DEFAULT 'Escribe tu pregunta...',
        color        TEXT NOT NULL DEFAULT '#2563eb',
        backend      TEXT NOT NULL DEFAULT 'groq',
        created_at   TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS sources (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        bot_id     TEXT NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
        type       TEXT NOT NULL,
        name       TEXT NOT NULL,
        content    TEXT NOT NULL DEFAULT '',
        chars      INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        bot_id     TEXT NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
        session_id TEXT NOT NULL,
        role       TEXT NOT NULL,
        content    TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS cfg (
        k TEXT PRIMARY KEY,
        v TEXT NOT NULL DEFAULT ''
    );
    ");
    foreach ([
        'admin_pass'   => password_hash('admin', PASSWORD_BCRYPT, ['cost' => 10]),
        'groq_key'     => '',
        'claude_key'   => '',
        'ollama_url'   => 'http://localhost:11434',
        'ollama_model' => 'qwen2.5:7b',
    ] as $k => $v) {
        $db->prepare("INSERT OR IGNORE INTO cfg (k,v) VALUES (?,?)")->execute([$k, $v]);
    }
}

function cb_cfg(string $k): string {
    $s = cb_db()->prepare("SELECT v FROM cfg WHERE k=?");
    $s->execute([$k]);
    $r = $s->fetch();
    return $r ? $r['v'] : '';
}

function cb_set_cfg(string $k, string $v): void {
    cb_db()->prepare("INSERT OR REPLACE INTO cfg (k,v) VALUES (?,?)")->execute([$k, $v]);
}

function cb_id(): string {
    return bin2hex(random_bytes(8));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
