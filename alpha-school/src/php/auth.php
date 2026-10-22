<?php
function safe_user($u)
{
    if (!$u) {
        return null;
    }
    return [
        "id" => $u["id"],
        "username" => $u["username"],
        "name" => $u["name"],
        "roles" => $u["roles"],
        "departmentId" => $u["departmentId"] ?? "",
        "permissions" => a_platform_access_permissions($u),
        "active" => $u["active"],
        "auditScopes" => $u["auditScopes"] ?? [],
        "revision" => $u["revision"],
    ];
}
function create_user($username, $name, $roles, $password): array
{
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
        throw new HttpError(
            400,
            "Use a 3–64 character lowercase username (letters, numbers, dots, hyphens).",
        );
    }
    if (!strong_password($password)) {
        throw new HttpError(
            400,
            "Use 12–128 characters with uppercase, lowercase, number and symbol.",
        );
    }
    return array_merge(
        [
            "id" => rid("USR-"),
            "username" => $username,
            "name" => text($name, 120, true),
            "roles" => a_platform_access_validRoles($roles),
            "active" => true,
            "authVersion" => 1,
            "mustChangePassword" => true,
            "createdAt" => (new JsDate())->toISOString(),
        ],
        password_record($password),
    );
}
function rate_limit($tx, $key, $max, $windowMs, $now = null): bool
{
    $now ??= js_date_now();
    $id = sha256($key);
    $r = $tx->get("login_limits", $id);
    if (!$r || $r["expiresAt"] <= $now) {
        $next = ["id" => $id, "count" => 1, "expiresAt" => $now + $windowMs];
        $r
            ? $tx->update("login_limits", array_replace($r, $next))
            : $tx->insert("login_limits", $next);
        return true;
    }
    if ($r["count"] >= $max) {
        return false;
    }
    $r["count"]++;
    $tx->update("login_limits", $r);
    return true;
}
function require_ready($actor): void
{
    if (!$actor["user"]) {
        throw new HttpError(403, "Please sign in.", "AUTH_REQUIRED");
    }
    if ($actor["user"]["mustChangePassword"]) {
        throw new HttpError(
            403,
            "Change your initial password before continuing.",
            "PASSWORD_REQUIRED",
        );
    }
}
function require_csrf($actor, $body = [], $third = null): void
{
    if ($third !== null) {
        $body = $third;
    }
    $v = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? ($body["_csrf"] ?? "");
    if (
        !is_string($v) ||
        !$actor["session"] ||
        !hash_equals($actor["session"]["csrf"], $v)
    ) {
        throw new HttpError(
            403,
            "Your security token expired. Refresh and try again.",
            "CSRF_FAILED",
        );
    }
}
function live_actor($tx, $actor, $c): array
{
    $s = isset($actor["session"]["id"])
        ? $tx->get("sessions", $actor["session"]["id"])
        : null;
    $u = isset($actor["user"]["id"])
        ? $tx->get("users", $actor["user"]["id"])
        : null;
    if (
        !$s ||
        !($u["active"] ?? false) ||
        $s["userId"] !== $u["id"] ||
        $s["authVersion"] !== $u["authVersion"] ||
        $s["expiresAt"] <= js_date_now() ||
        js_date_now() - $s["lastSeen"] > $c["sessionIdleMs"]
    ) {
        throw new HttpError(403, "Please sign in again.", "AUTH_REQUIRED");
    }
    return array_replace($actor, ["user" => $u, "session" => $s]);
}
class Auth
{
    public function __construct(private Store $store, private array $config) {}
    private function cookie($token, $maxAge = null): void
    {
        $c = $this->config;
        $host = strtolower(explode(":", $_SERVER["HTTP_HOST"] ?? "")[0]);
        $embedded =
            !$c["production"] &&
            $c["environment"] === "development" &&
            preg_match('/^\d{1,5}-[a-z0-9-]+\.e2b\.app$/', $host);
        $secure =
            $embedded ||
            $c["production"] ||
            ($_SERVER["HTTPS"] ?? "") === "on" ||
            ($c["proxyHops"] > 0 &&
                trim(
                    explode(",", $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "")[
                        count(
                            explode(
                                ",",
                                $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "",
                            ),
                        ) - 1
                    ],
                ) === "https");
        header(
            "Set-Cookie: alpha_sid=" .
                rawurlencode($token) .
                "; Path=/; HttpOnly; SameSite=" .
                ($embedded ? "None" : "Lax") .
                "; Max-Age=" .
                ($maxAge ?? 28800) .
                ($secure ? "; Secure" : "") .
                ($embedded ? "; Partitioned" : ""),
            false,
        );
    }
    private function rotate($tx, $previous, $user): array
    {
        if ($previous) {
            $tx->remove("sessions", $previous["id"]);
        }
        $token = bin2hex(random_bytes(32));
        $now = js_date_now();
        $s = $tx->insert("sessions", [
            "id" => sha256($token),
            "userId" => $user["id"] ?? "",
            "authVersion" => $user["authVersion"] ?? 0,
            "csrf" => bin2hex(random_bytes(32)),
            "createdAt" => $now,
            "lastSeen" => $now,
            "expiresAt" =>
                $now + ($user ? $this->config["sessionAbsoluteMs"] : 900000),
        ]);
        return ["token" => $token, "session" => $s, "user" => $user];
    }
    public function actor($create = false, $res = null, $third = null): array
    {
        if (is_array($create)) {
            $create = $third ?? false;
        }
        $raw = $_COOKIE["alpha_sid"] ?? "";
        $actor = [
            "ip" => request_ip($this->config),
            "userAgent" => $_SERVER["HTTP_USER_AGENT"] ?? "",
        ];
        $r = $this->store->run(function ($tx) use ($raw, $actor, $create) {
            $s = preg_match('/^[a-f0-9]{64}$/', $raw)
                ? $tx->get("sessions", sha256($raw))
                : null;
            $u = $s["userId"] ?? "" ? $tx->get("users", $s["userId"]) : null;
            if (
                $s &&
                ($s["expiresAt"] <= js_date_now() ||
                    js_date_now() - $s["lastSeen"] >
                        $this->config["sessionIdleMs"] ||
                    ($s["userId"] &&
                        (!$u ||
                            !$u["active"] ||
                            $s["authVersion"] !== $u["authVersion"])))
            ) {
                $tx->remove("sessions", $s["id"]);
                $s = null;
                $u = null;
            }
            if (!$s && $create) {
                if (
                    !rate_limit($tx, "new-session:" . $actor["ip"], 60, 900000)
                ) {
                    return [
                        "error" => new HttpError(
                            429,
                            "Too many new sign-in sessions. Please wait before trying again.",
                            "RATE_LIMIT",
                        ),
                    ];
                }
                return $this->rotate($tx, null, null);
            }
            if ($s && js_date_now() - $s["lastSeen"] > 30000) {
                $s = $tx->update(
                    "sessions",
                    array_replace($s, ["lastSeen" => js_date_now()]),
                );
            }
            return ["session" => $s, "user" => $u];
        });
        if (isset($r["error"])) {
            throw $r["error"];
        }
        if (isset($r["token"])) {
            $this->cookie($r["token"]);
        }
        return array_merge($actor, $r);
    }
    public function info($actor): array
    {
        return [
            "user" => safe_user($actor["user"]),
            "csrf" => $actor["session"]["csrf"] ?? "",
            "needsPasswordChange" =>
                $actor["user"]["mustChangePassword"] ?? false,
        ];
    }
    public function login($actor, $body): array
    {
        $username = strtolower(text($body["username"] ?? "", 64));
        $r = $this->store->run(function ($tx) use ($username, $body, $actor) {
            if (
                !rate_limit($tx, "login:ip:" . $actor["ip"], 30, 600000) ||
                !rate_limit($tx, "login:user:" . $username, 6, 600000)
            ) {
                audit(
                    $tx,
                    $this->config,
                    $actor,
                    "auth.locked",
                    "user",
                    substr(sha256($username), 0, 16),
                    "denied",
                );
                return [
                    "error" => new HttpError(
                        429,
                        "Too many sign-in attempts. Try again in 10 minutes.",
                        "RATE_LIMIT",
                    ),
                ];
            }
            $u = null;
            foreach ($tx->list("users") as $x) {
                if ($x["username"] === $username) {
                    $u = $x;
                    break;
                }
            }
            $password = $body["password"] ?? "";
            if (!is_string($password)) {
                $password = "";
            }
            $valid = $u
                ? verify_password($u, $password)
                : password_verify(
                    $password,
                    '$2y$12$QmlLsNNRaIqvmDJOhHmhueZGTxvn4R.QWJF.8DsUGSEZ.QUFwPXNq',
                );
            if (!$u || !$u["active"] || !$valid) {
                audit(
                    $tx,
                    $this->config,
                    $actor,
                    "auth.login",
                    "user",
                    $u["id"] ?? substr(sha256($username), 0, 16),
                    "failure",
                );
                return [
                    "error" => new HttpError(
                        401,
                        "Sign-in details are incorrect.",
                        "LOGIN_FAILED",
                    ),
                ];
            }
            $tx->remove("login_limits", sha256("login:user:" . $username));
            $r = $this->rotate($tx, $actor["session"], $u);
            audit(
                $tx,
                $this->config,
                array_replace($actor, $r),
                "auth.login",
                "user",
                $u["id"],
            );
            return $r;
        });
        if (isset($r["error"])) {
            throw $r["error"];
        }
        $this->cookie($r["token"]);
        return $this->info($r);
    }
    public function logout($actor): array
    {
        $this->store->run(function ($tx) use ($actor) {
            if ($actor["session"]) {
                $tx->remove("sessions", $actor["session"]["id"]);
            }
            audit(
                $tx,
                $this->config,
                $actor,
                "auth.logout",
                "user",
                $actor["user"]["id"] ?? "anonymous",
            );
        });
        $this->cookie("", 0);
        header('Clear-Site-Data: "cache"');
        return ["ok" => true];
    }
    public function password($actor, $body): array
    {
        if (!$actor["user"]) {
            throw new HttpError(403, "Please sign in.", "AUTH_REQUIRED");
        }
        if (
            !strong_password($body["password"] ?? null) ||
            ($body["password"] ?? "") !== ($body["confirmPassword"] ?? null)
        ) {
            throw new HttpError(400, "Use a strong matching new password.");
        }
        $r = $this->store->run(function ($tx) use ($actor, $body) {
            $actor = live_actor($tx, $actor, $this->config);
            if (
                !rate_limit($tx, "password:" . $actor["user"]["id"], 6, 600000)
            ) {
                return [
                    "error" => new HttpError(429, "Try again in 10 minutes."),
                ];
            }
            $u = $actor["user"];
            if (
                !verify_password($u, $body["currentPassword"] ?? "") ||
                verify_password($u, $body["password"])
            ) {
                return [
                    "error" => new HttpError(
                        400,
                        "Check your current password and choose a different new password.",
                    ),
                ];
            }
            $u = $tx->update(
                "users",
                array_replace($u, password_record($body["password"]), [
                    "mustChangePassword" => false,
                    "authVersion" => $u["authVersion"] + 1,
                ]),
            );
            foreach ($tx->list("sessions") as $s) {
                if ($s["userId"] === $u["id"]) {
                    $tx->remove("sessions", $s["id"]);
                }
            }
            $r = $this->rotate($tx, null, $u);
            audit(
                $tx,
                $this->config,
                $actor,
                "auth.password_change",
                "user",
                $u["id"],
            );
            return $r;
        });
        if (isset($r["error"])) {
            throw $r["error"];
        }
        $this->cookie($r["token"]);
        return $this->info($r);
    }
}
