<?php
// Crea un link de un solo uso para un firmante externo.
function crear_token(mysqli $conn, int $solicitudId, int $fase, string $correo, string $nombre, int $horasValidez = 72): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expira = date('Y-m-d H:i:s', time() + $horasValidez * 3600);

    $stmt = $conn->prepare(
        "INSERT INTO tokens_confirmacion (solicitud_id, fase, token_hash, destinatario_correo, destinatario_nombre, expira_en)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissss', $solicitudId, $fase, $hash, $correo, $nombre, $expira);
    $stmt->execute();
    $stmt->close();

    return $token;
}

// Busca un link válido (no usado ni vencido).
function validar_token(mysqli $conn, string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT * FROM tokens_confirmacion WHERE token_hash = ? AND usado = 0 AND expira_en > NOW()"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ?: null;
}

// Marca el link como usado.
function marcar_token_usado(mysqli $conn, int $tokenId, string $ip): void
{
    $stmt = $conn->prepare("UPDATE tokens_confirmacion SET usado = 1, usado_en = NOW(), ip_uso = ? WHERE id = ?");
    $stmt->bind_param('si', $ip, $tokenId);
    $stmt->execute();
    $stmt->close();
}

// Dirección base del sistema para armar links.
function url_base(): string
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $carpeta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return "$protocolo://$host$carpeta";
}
