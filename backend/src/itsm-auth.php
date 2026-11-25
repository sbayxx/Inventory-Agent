<?php
declare(strict_types=1);
function inventoryActor(bool $redirectLogin = false): array {
    // Read session directly from file (bypasses shared session issues)
    $cookie = $_SERVER['HTTP_COOKIE'] ?? '';
    $sid = '';
    if (preg_match('/PHPSESSID=([a-zA-Z0-9]+)/', $cookie, $m)) {
        $sid = $m[1];
    }
    $sessionData = [];
    if ($sid !== '') {
        $sessionFile = '/tmp/sess_' . $sid;
        if (file_exists($sessionFile)) {
            $raw = file_get_contents($sessionFile);
            // Parse PHP session format: key|serialized_value;
            $sessionData = [];
            $offset = 0;
            while ($offset < strlen($raw)) {
                $pos = strpos($raw, '|', $offset);
                if ($pos === false) break;
                $key = substr($raw, $offset, $pos - $offset);
                $offset = $pos + 1;
                $val = @unserialize(substr($raw, $offset));
                if ($val === false && substr($raw, $offset, 2) !== 'b:0') {
                    // Try to find the end of this value
                    // PHP session format is tricky; try session_decode approach
                    break;
                }
                $sessionData[$key] = $val;
                $offset += strlen(serialize($val));
            }
        }
    }

    // Fallback: use session_decode if available
    if (empty($sessionData) && $sid !== '') {
        $sessionFile = '/tmp/sess_' . $sid;
        if (file_exists($sessionFile)) {
            $raw = file_get_contents($sessionFile);
            $oldSession = $_SESSION ?? [];
            session_start();
            session_decode($raw);
            $sessionData = $_SESSION ?? [];
            $_SESSION = $oldSession;
        }
    }

    $userId = $sessionData['user_id'] ?? null;
    if (!$userId) {
        if ($redirectLogin) { header('Location: /login?redirect=%2Finventory%2F', true, 303); exit; }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login_required']);
        exit;
    }

    $root = rtrim(envRequired('INV_ITSM_ROOT'), '/');
    if (!is_file($root . '/core/Database.php')) throw new RuntimeException('ITSM backend not found');
    require_once $root . '/core/Database.php';
    if (is_file($root . '/core/Env.php')) require_once $root . '/core/Env.php';

    $dbUser = Database::fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
    if (!$dbUser) {
        if ($redirectLogin) { header('Location: /login?redirect=%2Finventory%2F', true, 303); exit; }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login_required']);
        exit;
    }
    unset($dbUser['password']);
    if (isset($dbUser['is_active']) && !(int)$dbUser['is_active']) {
        if ($redirectLogin) { header('Location: /login?redirect=%2Finventory%2F', true, 303); exit; }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login_required']);
        exit;
    }
    if (!in_array($dbUser['role'] ?? '', ['admin', 'customer_admin', 'agent', 'manager'], true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'inventory_access_denied']);
        exit;
    }

    if (!isset($_SESSION['_inventory_csrf'])) $_SESSION['_inventory_csrf'] = bin2hex(random_bytes(32));
    $actor = ['id' => (int)$dbUser['id'], 'role' => $dbUser['role'], 'admin' => ($dbUser['role'] === 'admin'), 'customers' => [], 'csrf_token' => $_SESSION['_inventory_csrf']];

    if ($dbUser['role'] === 'admin') {
        $actor['customers'] = [];
    } else {
        $ids = [];
        if (!empty($dbUser['customer_id'])) $ids[] = (int)$dbUser['customer_id'];
        $rows = Database::fetchAll("SELECT customer_id FROM customer_user WHERE user_id = ?", [(int)$dbUser['id']]);
        foreach ($rows as $row) {
            $cid = (int)$row['customer_id'];
            if (!in_array($cid, $ids)) $ids[] = $cid;
        }
        $actor['customers'] = $ids;
    }
    return $actor;
}

function inventoryScope(array $actor): array {
    if ($actor['admin']) return ['1=1', []];
    $ids = array_values(array_filter(array_map('intval', $actor['customers']), fn($n) => $n > 0));
    if (!$ids) return ['1=0', []];
    return ['a.customer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function inventoryCustomers(array $actor): array {
    if ($actor['admin']) return Database::fetchAll('SELECT id,name FROM customers WHERE is_active=1 ORDER BY name');
    $ids = array_values(array_filter(array_map('intval', $actor['customers']), fn($n) => $n > 0));
    if (!$ids) return [];
    return Database::fetchAll('SELECT id,name FROM customers WHERE is_active=1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', $ids);
}
