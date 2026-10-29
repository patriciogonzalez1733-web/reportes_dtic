<?php
require_once __DIR__ . '/../config/db.php';

date_default_timezone_set('America/Santiago');
$conn->query("SET time_zone = '" . date('P') . "'");

// Cabeceras de seguridad.
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// Opciones de seguridad de la cookie de sesión.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
// Inicia la sesión del usuario.
session_start();

// Indica si todavía no hay ningún usuario registrado.
function sin_usuarios(mysqli $conn): bool
{
    return (int) $conn->query('SELECT COUNT(*) FROM usuarios')->fetch_row()[0] === 0;
}

// Devuelve el usuario con la sesión iniciada (o nada).
function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

// Exige sesión iniciada y que la cuenta siga activa.
function require_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $pagina = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
            $query  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
            $_SESSION['volver_a'] = $pagina . ($query ? '?' . $query : '');
        }
        // Redirige a otra página.
        header('Location: login.php');
        exit;
    }

    global $conn;
    $stmt = $conn->prepare('SELECT activo FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fila || (int) $fila['activo'] !== 1) {
        cerrar_sesion();
        // Redirige a otra página.
        header('Location: login.php');
        exit;
    }

    return $u;
}

// Página a la que volver después de iniciar sesión.
function pagina_despues_del_login(): string
{
    $destino = $_SESSION['volver_a'] ?? '';
    unset($_SESSION['volver_a']);
    if (preg_match('/^[a-z0-9_]+\.php(\?[A-Za-z0-9=&_.%-]*)?$/', $destino) && !preg_match('/^(login|logout)\.php/', $destino)) {
        return $destino;
    }
    return 'index.php';
}

// Exige que el usuario tenga uno de los roles indicados.
function require_rol(array $rolesPermitidos): array
{
    // Hay que haber iniciado sesión.
    $u = require_login();
    if (!in_array($u['rol'], $rolesPermitidos, true)) {
        http_response_code(403);
        die('No tienes permiso para acceder a esta página.');
    }
    return $u;
}

// Revisa correo y contraseña e inicia la sesión.
function intentar_login(mysqli $conn, string $correo, string $clave): bool
{
    $stmt = $conn->prepare('SELECT id, nombre, correo, password_hash, rol, activo FROM usuarios WHERE correo = ?');
    $stmt->bind_param('s', $correo);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($u && password_verify($clave, $u['password_hash']) && (int) $u['activo'] === 1) {
        unset($u['password_hash'], $u['activo']);
        session_regenerate_id(true);
        $_SESSION['usuario'] = $u;
        return true;
    }
    return false;
}

// Cierra la sesión.
function cerrar_sesion(): void
{
    $_SESSION = [];
    session_destroy();
}

// Campo oculto con el token de seguridad del formulario.
function csrf_field(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// Verifica el token de seguridad al recibir un formulario.
function csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Token de seguridad inválido. Vuelve a cargar la página e inténtalo de nuevo.');
    }
}
