<?php
/**
 * Database-Based Session Handler — Linux Production
 * Stores sessions in MySQL instead of files.
 * Compatible with multi-server and cPanel environments.
 */
require_once 'db.php';

// Ensure sessions table exists with correct charset
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        session_id  VARCHAR(128)  NOT NULL,
        session_data LONGTEXT     DEFAULT NULL,
        created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    // Table may already exist — continue
    error_log('[OSUScan Session] Table check: ' . $e->getMessage());
}

// ── Custom Database Session Handler ─────────────────────────
class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT session_data FROM sessions WHERE session_id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (string)$result['session_data'] : '';
        } catch (Exception $e) {
            error_log('[OSUScan Session Read] ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO sessions (session_id, session_data)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE session_data = VALUES(session_data),
                                         updated_at   = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$id, $data]);
            return true;
        } catch (Exception $e) {
            error_log('[OSUScan Session Write] ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (Exception $e) {
            error_log('[OSUScan Session Destroy] ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM sessions WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$max_lifetime]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('[OSUScan Session GC] ' . $e->getMessage());
            return false;
        }
    }
}

// Register custom session handler
$handler = new DatabaseSessionHandler($pdo);
session_set_save_handler($handler, true);

// ── Session Cookie Configuration ─────────────────────────────
session_set_cookie_params([
    'lifetime' => 3600,       // 1 hour session lifetime
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,      // Set to TRUE when HTTPS is enabled
    'httponly' => true,       // Prevent JavaScript access to cookie
    'samesite' => 'Lax',
]);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
