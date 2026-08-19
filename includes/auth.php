<?php
require_once __DIR__ . '/../config/base_url.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Core Helpers ────────────────────────────────────────────────────────────

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $redirect = BASE_URL . 'auth/login.php';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
            if (str_contains($uri, '/admin/')) {
                $redirect .= '?role=admin';
            } elseif (str_contains($uri, '/teacher/')) {
                $redirect .= '?role=teacher';
            } elseif (str_contains($uri, '/student/')) {
                $redirect .= '?role=student';
            }
        }
        header("Location: $redirect");
        exit;
    }
}

function requireRole(string $role): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if (!isLoggedIn()) {
        $redirect = match ($role) {
            'admin'   => BASE_URL . 'admin/',
            'teacher' => BASE_URL . 'index.php',
            'student' => BASE_URL . 'index.php',
            default   => BASE_URL . 'index.php',
        };
        header("Location: $redirect");
        exit;
    }
    if ($_SESSION['role'] !== $role) {
        setFlash('error', $LANG['error_not_authorized'] ?? 'You are not authorized to access that page.');
        redirectToDashboard();
    }
}

function redirectToDashboard(): void {
    $role = $_SESSION['role'] ?? '';
    match ($role) {
        'admin'   => header('Location: ' . BASE_URL . 'admin/dashboard.php'),
        'teacher' => header('Location: ' . BASE_URL . 'teacher/dashboard.php'),
        'student' => header('Location: ' . BASE_URL . 'student/dashboard.php'),
        default   => header('Location: ' . BASE_URL . 'auth/login.php'),
    };
    exit;
}

function getCurrentUser(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $userId = $_SESSION['user_id'] ?? 0;

    // Fetch fresh data from the database on every page load
    if ($userId) {
        global $conn;
        if ($conn) {
            $stmt = $conn->prepare("SELECT id, name, email, role, profile_image FROM users WHERE id=?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    // Sync session so code reading $_SESSION directly stays consistent
                    $_SESSION['name']          = $row['name'];
                    $_SESSION['email']         = $row['email'];
                    $_SESSION['role']          = $row['role'];
                    $_SESSION['profile_image'] = $row['profile_image'];
                    $cached = [
                        'id'            => (int)$row['id'],
                        'name'          => $row['name'],
                        'email'         => $row['email'],
                        'role'          => $row['role'],
                        'profile_image' => $row['profile_image'],
                    ];
                    return $cached;
                }
            }
        }
    }

    // Fallback to session data if DB is unavailable or user not found
    $cached = [
        'id'            => $userId,
        'name'          => $_SESSION['name']          ?? '',
        'email'         => $_SESSION['email']         ?? '',
        'role'          => $_SESSION['role']          ?? '',
        'profile_image' => $_SESSION['profile_image'] ?? null,
    ];
    return $cached;
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ─── Flash Messages ───────────────────────────────────────────────────────────

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function renderFlash(): void {
    $f = getFlash();
    if (!$f) return;

    $colors = [
        'success' => 'bg-green-50 border-green-400 text-green-800',
        'error'   => 'bg-red-50 border-red-400 text-red-800',
        'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
        'info'    => 'bg-cyan-50 border-cyan-400 text-cyan-800',
    ];
    $icons = [
        'success' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'error'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>',
        'warning' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>',
        'info'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>',
    ];
    $cls  = $colors[$f['type']] ?? $colors['info'];
    $icon = $icons[$f['type']] ?? $icons['info'];
    echo <<<HTML
    <div id="flash-alert" class="flex items-center gap-3 border-l-4 {$cls} px-4 py-3 rounded-lg mb-6 shadow-sm animate-fade-in">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0">
            {$icon}
        </svg>
        <span class="text-sm font-medium flex-1">{$f['message']}</span>
        <button onclick="document.getElementById('flash-alert').remove()" class="ml-auto opacity-60 hover:opacity-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    HTML;
}
