<?php
function protected_file($file): bool
{
    return is_string($file) &&
        is_file($file) &&
        !is_link($file) &&
        (fileperms($file) & 0077) === 0;
}
function backup_key(): string
{
    $v = env_value("MYSQL_BACKUP_ENCRYPTION_KEY");
    if (!$v) {
        $file = env_value(
            "MYSQL_BACKUP_ENCRYPTION_KEY_FILE",
            env_value("MONGO_BACKUP_ENCRYPTION_KEY_FILE"),
        );
        if (!protected_file($file)) {
            throw new RuntimeException(
                "Provide a protected backup-key file (permissions 600 or stricter).",
            );
        }
        $v = trim(file_get_contents($file));
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $v)) {
        throw new RuntimeException(
            "Use a separate 64-hex-character backup key.",
        );
    }
    return hex2bin($v);
}
function seal_backup($snapshot, $key): string
{
    $body = js_json_stringify($snapshot);
    if (strlen($body) > 512 * 1024 * 1024) {
        throw new RuntimeException(
            "Use managed snapshots for archives over 512 MB.",
        );
    }
    $iv = random_bytes(12);
    $data = openssl_encrypt(
        $body,
        "aes-256-gcm",
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
    );
    if ($data === false) {
        throw new RuntimeException("Backup encryption failed.");
    }
    return "ALPHAMYS" . $iv . $tag . $data;
}
function open_backup($file, $key, $allowLegacy = false): array
{
    if (
        !is_file($file) ||
        filesize($file) < 36 ||
        filesize($file) > 512 * 1024 * 1024 + 36
    ) {
        throw new RuntimeException(
            "Unsupported, oversized or truncated backup.",
        );
    }
    $data = file_get_contents($file);
    $magic = substr($data, 0, 8);
    if (
        !in_array($magic, ["ALPHAMYS", "ALPHAMDB"], true) ||
        ($magic === "ALPHAMDB" && !$allowLegacy)
    ) {
        throw new RuntimeException(
            "Unsupported archive. Legacy MongoDB archives require the approved import command.",
        );
    }
    $plain = openssl_decrypt(
        substr($data, 36),
        "aes-256-gcm",
        $key,
        OPENSSL_RAW_DATA,
        substr($data, 8, 12),
        substr($data, 20, 16),
    );
    if ($plain === false) {
        throw new RuntimeException("Backup authentication failed.");
    }
    $r = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($r) || !is_array($r["collections"] ?? null)) {
        throw new RuntimeException("Invalid archive structure.");
    }
    return $r;
}
function file_references($collections): array
{
    $keys = [];
    $visit = function ($v) use (&$visit, &$keys) {
        if (!is_array($v)) {
            return;
        }
        if (
            is_string($v["storageKey"] ?? null) &&
            preg_match('/^[a-f0-9]{48}$/', $v["storageKey"])
        ) {
            $keys[$v["storageKey"]] = true;
        }
        foreach ($v as $child) {
            $visit($child);
        }
    };
    $visit($collections);
    return array_keys($keys);
}
function validate_backup_files($files): void
{
    $seen = [];
    foreach ($files as $f) {
        if (
            !preg_match('/^[a-f0-9]{48}$/', $f["key"] ?? "") ||
            isset($seen[$f["key"]])
        ) {
            throw new RuntimeException("Unsafe or duplicate file key.");
        }
        $seen[$f["key"]] = true;
        $bytes = base64_decode($f["content"] ?? "", true);
        if (
            $bytes === false ||
            strlen($bytes) !== ($f["size"] ?? -1) ||
            !hash_equals($f["sha256"] ?? "", sha256($bytes))
        ) {
            throw new RuntimeException("File checksum mismatch.");
        }
    }
}
function capture_snapshot(Store $store, Files $files, array $c): array
{
    return $store->run(function ($tx) use ($store, $files, $c) {
        $collections = [];
        foreach (COLLECTIONS as $name) {
            $collections[] = [
                "name" => "adsp_" . $name,
                "documents" => $tx->list($name),
            ];
        }
        $stored = [];
        $size = 0;
        foreach (file_references($collections) as $key) {
            $data = $files->readStored("private", $key);
            $size += strlen($data);
            if ($size > 256 * 1024 * 1024) {
                throw new RuntimeException(
                    "Use managed snapshots for large file libraries.",
                );
            }
            $stored[] = [
                "key" => $key,
                "size" => strlen($data),
                "sha256" => sha256($data),
                "content" => base64_encode($data),
            ];
        }
        return [
            "version" => 3,
            "dialect" => $store->kind,
            "database" => $c["mysql"]["database"] ?? "sqlite",
            "exportedAt" => (new JsDate())->toISOString(),
            "collections" => $collections,
            "files" => $stored,
            "storage" => [
                "provider" => env_value("SUPABASE_S3_ENDPOINT")
                    ? "supabase-s3"
                    : "local",
                "bucket" => env_value("SUPABASE_STORAGE_BUCKET"),
            ],
            "publicCopiesRequireReview" => true,
        ];
    });
}
function restore_snapshot(Store $store, Files $files, $snapshot): array
{
    validate_backup_files($snapshot["files"] ?? []);
    $planned = [];
    $seen = [];
    foreach ($snapshot["collections"] as $entry) {
        $table = $entry["name"] ?? "";
        $name = str_starts_with($table, "adsp_") ? substr($table, 5) : "";
        if (
            !in_array($name, COLLECTIONS, true) ||
            isset($seen[$name]) ||
            !is_array($entry["documents"] ?? null)
        ) {
            throw new RuntimeException("Unknown or duplicate backup table.");
        }
        $seen[$name] = true;
        $rows = in_array($name, ["sessions", "download_grants"], true)
            ? []
            : $entry["documents"];
        foreach ($rows as $r) {
            if (
                !is_array($r) ||
                !preg_match(
                    '/^[A-Za-z0-9_.:-]{1,191}$/',
                    (string) ($r["id"] ?? ""),
                ) ||
                !is_int($r["revision"] ?? null) ||
                $r["revision"] < 1
            ) {
                throw new RuntimeException("Invalid backup record.");
            }
        }
        $planned[$name] = $rows;
    }
    foreach (COLLECTIONS as $name) {
        if ($store->list($name)) {
            throw new RuntimeException(
                "Target contains records. Existing data is never overwritten.",
            );
        }
    }
    $counts = $store->run(function ($tx) use ($planned) {
        $counts = [];
        foreach ($planned as $name => $rows) {
            foreach ($rows as $r) {
                $q = $tx->db->prepare(
                    "INSERT INTO adsp_" .
                        $name .
                        " (id,revision,payload) VALUES (?,?,?)",
                );
                $q->execute([$r["id"], $r["revision"], js_json_stringify($r)]);
            }
            $counts[$name] = count($rows);
        }
        return $counts;
    });
    foreach ($snapshot["files"] ?? [] as $f) {
        $files->writeStored(
            "private",
            $f["key"],
            base64_decode($f["content"], true),
        );
    }
    return $counts;
}
function cli_application($initialise = true): Platform
{
    $c = configuration();
    $store = new Store($c);
    $files = new Files($c);
    $p = new Platform($store, $c, $files);
    if ($initialise) {
        $p->init();
    } else {
        $files->init();
    }
    return $p;
}
function cli_main(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "[alpha] Operation failed: " .
                get_class($e) .
                ". Check approval, isolated targets, private key files and database configuration." .
                PHP_EOL,
        );
        exit(1);
    }
}
