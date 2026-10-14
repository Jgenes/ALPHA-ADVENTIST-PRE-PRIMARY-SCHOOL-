<?php
interface SmtpConnection
{
    public function readLine(): ?string;
    public function write(string $bytes): void;
    public function enableTls(): void;
    public function close(): void;
}
final class SmtpSocket implements SmtpConnection
{
    private $socket;
    private float $deadline;
    private const TLS_METHOD =
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT |
        STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    public function __construct(array $smtp)
    {
        $this->deadline = microtime(true) + 45;
        $context = stream_context_create([
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true,
                "peer_name" => $smtp["host"],
                "allow_self_signed" => false,
                "disable_compression" => true,
                "crypto_method" => self::TLS_METHOD,
            ],
        ]);
        $scheme = $smtp["security"] === "tls" ? "tls" : "tcp";
        $this->socket = @stream_socket_client(
            $scheme . "://" . $smtp["host"] . ":" . $smtp["port"],
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!$this->socket) {
            throw new RuntimeException(
                "SMTP connection or verified TLS handshake failed.",
            );
        }
        stream_set_timeout($this->socket, 10);
    }
    private function boundTimeout(): void
    {
        $remaining = min(10.0, $this->deadline - microtime(true));
        if ($remaining <= 0) {
            throw new RuntimeException("SMTP overall deadline exceeded.");
        }
        $seconds = (int) $remaining;
        stream_set_timeout(
            $this->socket,
            $seconds,
            max(1, (int) (($remaining - $seconds) * 1000000)),
        );
    }
    public function readLine(): ?string
    {
        $this->boundTimeout();
        $line = fgets($this->socket, 4096);
        if ($line === false) {
            throw new RuntimeException(
                "SMTP response interrupted or timed out.",
            );
        }
        return $line;
    }
    public function write(string $bytes): void
    {
        while ($bytes !== "") {
            $this->boundTimeout();
            $written = fwrite($this->socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException("SMTP write failed.");
            }
            $bytes = substr($bytes, $written);
        }
    }
    public function enableTls(): void
    {
        $this->boundTimeout();
        if (
            stream_socket_enable_crypto(
                $this->socket,
                true,
                self::TLS_METHOD,
            ) !== true
        ) {
            throw new RuntimeException(
                "SMTP STARTTLS certificate validation or handshake failed.",
            );
        }
    }
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
final class SmtpClient
{
    public function __construct(private SmtpConnection $connection) {}
    private function response(array $expected): string
    {
        $messages = [];
        $code = null;
        for ($i = 0; $i < 100; $i++) {
            $line = $this->connection->readLine();
            if (
                $line === null ||
                !preg_match('/\A([0-9]{3})([ -])([^\r\n]*)\r?\n\z/D', $line, $m)
            ) {
                throw new RuntimeException("Invalid SMTP response.");
            }
            $code ??= (int) $m[1];
            if ((int) $m[1] !== $code) {
                throw new RuntimeException("Inconsistent SMTP response.");
            }
            $messages[] = $m[3];
            if ($m[2] === " ") {
                if (!in_array($code, $expected, true)) {
                    throw new RuntimeException(
                        "SMTP command was not accepted (" . $code . ").",
                    );
                }
                return implode("\n", $messages);
            }
        }
        throw new RuntimeException("Too many SMTP response lines.");
    }
    private function command(string $line, array $expected): string
    {
        if (strpbrk($line, "\r\n") !== false) {
            throw new RuntimeException("Invalid SMTP command.");
        }
        $this->connection->write($line . "\r\n");
        return $this->response($expected);
    }
    public function send(array $smtp, string $recipient, string $message): bool
    {
        try {
            $this->response([220]);
            $capabilities = $this->command("EHLO alpha-school", [250]);
            if ($smtp["security"] === "starttls") {
                if (!preg_match('/(?:^|\n)STARTTLS(?:\s|$)/i', $capabilities)) {
                    throw new RuntimeException(
                        "SMTP provider did not advertise required STARTTLS.",
                    );
                }
                $this->command("STARTTLS", [220]);
                $this->connection->enableTls();
                $capabilities = $this->command("EHLO alpha-school", [250]);
            } elseif ($smtp["security"] !== "tls") {
                throw new RuntimeException("Encrypted SMTP is required.");
            }
            if (
                !preg_match(
                    '/(?:^|\n)AUTH(?:=|\s+)([^\n]+)/i',
                    $capabilities,
                    $match,
                )
            ) {
                throw new RuntimeException(
                    "SMTP authentication is not available over TLS.",
                );
            }
            $mechanisms = preg_split("/\s+/", strtoupper(trim($match[1])));
            if (in_array("LOGIN", $mechanisms, true)) {
                $this->command("AUTH LOGIN", [334]);
                $this->command(base64_encode($smtp["user"]), [334]);
                $this->command(base64_encode($smtp["password"]), [235]);
            } elseif (in_array("PLAIN", $mechanisms, true)) {
                $this->command(
                    "AUTH PLAIN " .
                        base64_encode(
                            "\0" . $smtp["user"] . "\0" . $smtp["password"],
                        ),
                    [235],
                );
            } else {
                throw new RuntimeException(
                    "SMTP provider needs LOGIN or PLAIN authentication over verified TLS.",
                );
            }
            foreach ([$smtp["from"], $recipient] as $address) {
                if (
                    !filter_var($address, FILTER_VALIDATE_EMAIL) ||
                    strpbrk($address, "\r\n") !== false
                ) {
                    throw new RuntimeException("Invalid email envelope.");
                }
            }
            $this->command("MAIL FROM:<" . $smtp["from"] . ">", [250]);
            $this->command("RCPT TO:<" . $recipient . ">", [250, 251]);
            $this->command("DATA", [354]);
            $this->connection->write(
                preg_replace('/(^|\r\n)\./', '$1..', rtrim($message, "\r\n")) .
                    "\r\n.\r\n",
            );
            $this->response([250]);
            // Acceptance is final even if the provider closes before replying to QUIT.
            try {
                $this->command("QUIT", [221]);
            } catch (Throwable) {
            }
            return true;
        } finally {
            $this->connection->close();
        }
    }
}
function smtp_encoded_header(string $value): string
{
    $value = trim(preg_replace('/[\x00-\x1f\x7f]/', " ", $value));
    $words = [];
    while ($value !== "") {
        $part = mb_strcut($value, 0, 42, "UTF-8");
        $words[] = "=?UTF-8?B?" . base64_encode($part) . "?=";
        $value = substr($value, strlen($part));
    }
    return implode("\r\n ", $words);
}
function smtp_alert_message(array $smtp, array $alert, string $baseUrl): array
{
    $recipient = in_array(
        $alert["type"],
        ["Safeguarding concern", "Privacy request"],
        true,
    )
        ? $smtp["privacyEmail"]
        : $smtp["officeEmail"];
    $body = implode("\r\n", [
        "An authorised school team member should review this new request.",
        "Type: " . $alert["type"],
        "Reference: " . $alert["reference"],
        "Received: " . $alert["createdAt"],
        "Open the secure record: " . $baseUrl . $alert["href"],
        "The request details are not included in this email.",
    ]);
    $domain = substr(strrchr($smtp["from"], "@"), 1);
    $headers = [
        "From: " .
        smtp_encoded_header($smtp["fromName"] ?? "Alpha School") .
        " <" .
        $smtp["from"] .
        ">",
        "To: " . $recipient,
        "Subject: " .
        smtp_encoded_header(
            "[Alpha school alert] " .
                $alert["type"] .
                " " .
                $alert["reference"],
        ),
        "Date: " . gmdate("r"),
        "Message-ID: <" . sha256($alert["id"]) . "@" . $domain . ">",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
    ];
    return [
        "recipient" => $recipient,
        "message" =>
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            chunk_split(base64_encode($body), 76, "\r\n"),
    ];
}
function send_smtp_alert(array $smtp, array $alert, string $baseUrl): bool
{
    $mail = smtp_alert_message($smtp, $alert, $baseUrl);
    return (new SmtpClient(new SmtpSocket($smtp)))->send(
        $smtp,
        $mail["recipient"],
        $mail["message"],
    );
}
