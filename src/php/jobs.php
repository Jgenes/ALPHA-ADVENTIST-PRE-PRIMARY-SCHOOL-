<?php
require_once __DIR__ . "/smtp.php";
/** Run from cron once a minute: php scripts/maintenance.php. */
function deliver_alerts(Platform $p, ?callable $smtpSender = null): void
{
    $c = $p->config;
    if (!$c["alertUrl"] && !$c["smtp"]) {
        return;
    }
    for ($i = 0; $i < 10; $i++) {
        $alert = $p->store->run(function ($tx) {
            foreach ($tx->list("outbox") as $r) {
                if (
                    (in_array($r["status"], ["PENDING", "RETRY"], true) &&
                        $r["nextAttemptAt"] <= js_date_now()) ||
                    ($r["status"] === "SENDING" &&
                        ($r["lockedUntil"] ?? 0) <= js_date_now())
                ) {
                    return $tx->update(
                        "outbox",
                        array_replace($r, [
                            "status" => "SENDING",
                            "attempts" => $r["attempts"] + 1,
                            "lockedUntil" => js_date_now() + 60000,
                        ]),
                    );
                }
            }
            return null;
        });
        if (!$alert) {
            break;
        }
        $delivered = false;
        $status = 0;
        try {
            if ($c["alertUrl"]) {
                $timestamp = (string) js_date_now();
                $payload = js_json_stringify([
                    "id" => $alert["id"],
                    "school" => "Alpha Adventist Pre & Primary School",
                    "reference" => $alert["reference"],
                    "type" => $alert["type"],
                    "receivedAt" => $alert["createdAt"],
                    "officeUrl" => $c["baseUrl"] . $alert["href"],
                ]);
                $signature = hash_hmac(
                    "sha256",
                    $timestamp . "." . $payload,
                    $c["alertSecret"],
                );
                $ch = curl_init($c["alertUrl"]);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/json",
                        "Idempotency-Key: " . $alert["id"],
                        "X-Alpha-Timestamp: " . $timestamp,
                        "X-Alpha-Signature: sha256=" . $signature,
                    ],
                ]);
                $r = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $delivered = $r !== false && $status >= 200 && $status < 300;
            } else {
                $delivered = ($smtpSender ?? "send_smtp_alert")(
                    $c["smtp"],
                    $alert,
                    $c["baseUrl"],
                );
                $status = $delivered ? 250 : 550;
            }
        } catch (Throwable) {
            /* Do not log personal data or credentials. */
        }
        $p->store->run(function ($tx) use ($p, $alert, $delivered, $status) {
            $r = $tx->get("outbox", $alert["id"]);
            if (
                !$r ||
                $r["status"] !== "SENDING" ||
                $r["attempts"] !== $alert["attempts"]
            ) {
                return;
            }
            $tx->update(
                "outbox",
                array_replace($r, [
                    "status" => $delivered
                        ? "DELIVERED"
                        : ($r["attempts"] >= 8
                            ? "FAILED"
                            : "RETRY"),
                    "lastStatus" => $status,
                    "nextAttemptAt" =>
                        js_date_now() + min(1440, 2 ** $r["attempts"]) * 60000,
                    "deliveredAt" => $delivered
                        ? (new JsDate())->toISOString()
                        : null,
                    "lockedUntil" => 0,
                ]),
            );
            audit(
                $tx,
                $p->config,
                ["userId" => "system"],
                "notification.delivery",
                "outbox",
                $r["id"],
                $delivered ? "success" : "failure",
                ["httpStatus" => $status, "attempt" => $r["attempts"]],
            );
        });
    }
}
