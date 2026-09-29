<?php
// ================================================================================================
// mail.php — envío de correo por SMTP sin dependencias (texto plano UTF-8).
// ------------------------------------------------------------------------------------------------
// En desarrollo el servidor es Mailpit (docker-compose: SMTP mailpit:1025, bandeja en :8025), que no
// pide autenticación ni TLS; por eso este cliente no implementa AUTH ni STARTTLS. Para producción
// habrá que ampliarlo o usar un relé local.
// Configuración: MAIL_HOST, MAIL_PORT, MAIL_FROM.
// ================================================================================================

require_once __DIR__ . "/../Config/env.php";

/** Envía un correo. Devuelve false (y lo registra en el log) si algo falla; nunca lanza. */
function sendMail(string $to, string $subject, string $body): bool {
    // Un salto de línea en destinatario o asunto permitiría inyectar cabeceras.
    if (preg_match("/[\r\n]/", $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("BioDex mail: destinatario o asunto no válidos");
        return false;
    }

    $host = biodex_env(["MAIL_HOST"]) ?? "mailpit";
    $port = (int) (biodex_env(["MAIL_PORT"]) ?? 1025);
    $from = biodex_env(["MAIL_FROM"]) ?? "no-reply@biodex.local";

    $sock = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$sock) {
        error_log("BioDex mail: no se pudo conectar a {$host}:{$port}: {$errstr}");
        return false;
    }
    stream_set_timeout($sock, 5);

    $headers = implode("\r\n", [
        "Date: " . date(DATE_RFC2822),
        "From: BioDex <{$from}>",
        "To: <{$to}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . substr(strrchr($from, "@"), 1) . ">",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
    ]);
    // Normaliza a CRLF y duplica el punto inicial de línea (dot-stuffing, RFC 5321 §4.5.2).
    $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $body = preg_replace("/^\./m", "..", $body);

    try {
        smtpExpect($sock, 220);
        smtpCommand($sock, "EHLO biodex", 250);
        smtpCommand($sock, "MAIL FROM:<{$from}>", 250);
        smtpCommand($sock, "RCPT TO:<{$to}>", 250);
        smtpCommand($sock, "DATA", 354);
        smtpCommand($sock, $headers . "\r\n\r\n" . $body . "\r\n.", 250);
        smtpCommand($sock, "QUIT", 221);
        return true;
    } catch (RuntimeException $e) {
        error_log("BioDex mail: " . $e->getMessage());
        return false;
    } finally {
        fclose($sock);
    }
}

/** Envía una línea SMTP y comprueba el código de respuesta. */
function smtpCommand($sock, string $line, int $expected): void {
    fwrite($sock, $line . "\r\n");
    smtpExpect($sock, $expected);
}

/** Lee una respuesta (posiblemente multilínea: "250-...") y lanza si el código no es el esperado. */
function smtpExpect($sock, int $expected): void {
    $reply = "";
    while (($line = fgets($sock, 1024)) !== false) {
        $reply .= $line;
        if (strlen($line) < 4 || $line[3] !== "-") break;
    }
    if ((int) substr($reply, 0, 3) !== $expected) {
        throw new RuntimeException("SMTP esperaba {$expected}, recibió: " . trim($reply));
    }
}
