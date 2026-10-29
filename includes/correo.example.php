<?php
/*
 * Archivo: includes/correo.php
 * Descripción: Envío de correos electrónicos del sistema por SMTP (vía PHPMailer).
 * Plantilla: copiar este archivo como includes/correo.php y completar los datos del
 * SMTP de la institución. Punto único desde donde se manda cualquier correo del
 * sistema — cada pantalla del flujo (crear, aprobar, rechazar, corregir) llama a
 * enviar_correo().
 */


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/documento.php'; // documento_a_pdf(): los Word/Excel nunca salen por correo

// true = envía por el SMTP configurado abajo. false = no envía nada.
define('CORREO_SMTP_LOCAL', true);

// Datos del servidor de correo. Los entrega TI de la institución.
define('CORREO_SMTP_HOST', 'AQUI_VA_EL_SERVIDOR_SMTP');          // ej: smtp.unap.cl
define('CORREO_SMTP_PUERTO', 587);                               // número del puerto, ej: 587 o 465
define('CORREO_SMTP_SEGURIDAD', 'tls');                          // 'tls' (STARTTLS), 'ssl' o '' (sin cifrado)
define('CORREO_SMTP_USUARIO', 'AQUI_VA_LA_CUENTA_QUE_ENVIA');    // ej: dtic@unap.cl ('' si no pide usuario)
define('CORREO_SMTP_CLAVE', 'AQUI_VA_LA_CLAVE_DE_ESA_CUENTA');   // contraseña de la cuenta de arriba
define('CORREO_REMITENTE', 'AQUI_VA_EL_CORREO_REMITENTE');       // ej: no-responder@unap.cl (debe estar autorizado)

// Envía un correo HTML, opcionalmente con un archivo adjunto (ej. el TBI en Word), o con varios
// si se pasa un arreglo de rutas (ej. CIT + RBI); las que sean null o no existan se ignoran.
function enviar_correo(string $destinatario, string $nombreDestinatario, string $asunto, string $cuerpoHtml, string|array|null $rutaAdjunto = null): bool
{
    // Un solo archivo o varios: se normaliza a una lista de rutas que existen.
    $adjuntos = array_values(array_filter((array) $rutaAdjunto, fn($r) => is_string($r) && is_file($r)));

    // los Word/Excel nunca salen por correo: se convierten a PDF
    $adjuntos = array_map(function ($r) {
        if (!preg_match('/\.(docx|xlsx)$/i', $r)) {
            return $r;
        }
        try {
            return documento_a_pdf($r);
        } catch (\Throwable $e) {
            error_log('No se pudo convertir a PDF: ' . $e->getMessage());
            return null; // si falla, no se adjunta el editable
        }
    }, $adjuntos);
    $adjuntos = array_values(array_filter($adjuntos));

    // Envío real por SMTP, con PHPMailer. Reintenta hasta 3 veces si la
    // conexión SMTP falla puntualmente (puede pasar por una red momentáneamente
    // inestable), antes de darse por vencido.
    if (CORREO_SMTP_LOCAL) {
        $intentosMax = 3;
        for ($intento = 1; $intento <= $intentosMax; $intento++) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = CORREO_SMTP_HOST;
                $mail->Port = CORREO_SMTP_PUERTO;
                if (CORREO_SMTP_SEGURIDAD === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif (CORREO_SMTP_SEGURIDAD === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                }
                $mail->SMTPAuth = CORREO_SMTP_USUARIO !== '';
                $mail->Username = CORREO_SMTP_USUARIO;
                $mail->Password = CORREO_SMTP_CLAVE;
                $mail->Timeout = 10;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom(CORREO_REMITENTE, 'DTIC - Reportes');
                $mail->addAddress($destinatario, $nombreDestinatario);
                $mail->Subject = $asunto;
                $mail->isHTML(true);
                $mail->Body = $cuerpoHtml;
                $mail->AltBody = trim(strip_tags($cuerpoHtml));
                foreach ($adjuntos as $ruta) {
                    $mail->addAttachment($ruta, basename($ruta));
                }
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log("Error enviando correo (SMTP) a $destinatario [intento $intento/$intentosMax]: " . $e->getMessage());
                if ($intento < $intentosMax) {
                    sleep(2);
                }
            }
        }
        return false;
    }

    return true;
}
