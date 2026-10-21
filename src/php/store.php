<?php
/** Local cPanel MySQL may use loopback; remote production MySQL always requires TLS. */
function mysql_ssl_mode(array $c): string
{
    $local = in_array(strtolower($c["mysql"]["host"]), ["localhost", "127.0.0.1"], true);
    $mode = env_value("MYSQL_SSL_MODE") !== ""
        ? env_value("MYSQL_SSL_MODE")
        : (($c["production"] && !$local) ? "verify-full" : "disabled");
    if (!in_array($mode, ["disabled", "verify-full"], true)) {
        throw new RuntimeException("MYSQL_SSL_MODE must be disabled or verify-full.");
    }
    if ($mode === "disabled" && $c["production"] && !$local) {
        throw new RuntimeException("Remote production MySQL requires verified TLS. Only loopback MySQL may disable TLS.");
    }
    return $mode;
}
function mysql_connection(array $c, bool $selectDatabase = true): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $m = $c["mysql"];
    $mode = mysql_ssl_mode($c);
    if ($mode === "verify-full") {
        $ca = env_value("MYSQL_TLS_CA_FILE", env_value("MYSQL_SSL_CA_FILE"));
        if (!$ca && env_value("MYSQL_SSL_CA")) {
            $ca = $c["dataDir"] . "/mysql-ca.pem";
            file_put_contents($ca, env_value("MYSQL_SSL_CA"));
            chmod($ca, 0600);
        }
        $ca = $ca ?: "/etc/ssl/certs/ca-certificates.crt";
        if (!$ca || !is_readable($ca)) {
            throw new RuntimeException(
                "Verified MySQL TLS requires MYSQL_SSL_CA_FILE or MYSQL_SSL_CA.",
            );
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $options[PDO::ATTR_EMULATE_PREPARES] = false;
    $database = $selectDatabase ? ";dbname=" . $m["database"] : "";
    $pdo = new PDO(
        "mysql:host={$m["host"]};port={$m["port"]}{$database};charset=utf8mb4",
        $m["user"],
        $m["password"],
        $options,
    );
    if ($mode === "verify-full") {
        $tls = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        if (!$tls || empty($tls["Value"])) {
            throw new RuntimeException(
                "MySQL connection did not negotiate required TLS.",
            );
        }
    }
    return $pdo;
}
class Store
{
    public PDO $db;
    public string $kind;
    private bool $running = false;
    public function __construct(array $c)
    {
        if (!$c["mysql"]) {
            throw new RuntimeException("MySQL configuration is required.");
        }
        $this->kind = "mysql";
        $this->db = mysql_connection($c);
        // Inspect existing schema metadata before issuing setup DDL.
        $tables = $this->db
            ->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()",
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        $triggers = $this->db
            ->query(
                "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        $auditReady =
            in_array("adsp_audit_no_update", $triggers, true) &&
            in_array("adsp_audit_no_delete", $triggers, true);
        if (
            $auditReady &&
            !array_diff(
                array_map(fn($name) => "adsp_" . $name, COLLECTIONS),
                $tables,
            )
        ) {
            return;
        }
        foreach (COLLECTIONS as $name) {
            $table = "adsp_" . $name;
            $fields = [];
            $indexes = [];
            foreach (
                array_merge(
                    array_map(
                        fn($x) => [$x, true],
                        UNIQUE_KEYS[$name] ?? [],
                    ),
                    array_map(
                        fn($x) => [$x, false],
                        SECONDARY_KEYS[$name] ?? [],
                    ),
                )
                as [$group, $unique]
            ) {
                foreach ($group as $f) {
                    $fields[$f] = "idx_" . strtolower($f);
                }
                $idx = substr(
                    $table .
                        "_" .
                        strtolower(implode("_", $group)) .
                        ($unique ? "_uq" : ""),
                    0,
                    64,
                );
                $indexes[] =
                    ($unique ? "UNIQUE " : "") .
                    "KEY `$idx` (" .
                    implode(
                        ",",
                        array_map(
                            fn($f) => "`" . $fields[$f] . "`",
                            $group,
                        ),
                    ) .
                    ")";
            }
            $columns = [];
            foreach ($fields as $f => $col) {
                $columns[] = "`$col` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS ((CASE WHEN JSON_EXTRACT(payload, '$.$f') IS NULL OR JSON_TYPE(JSON_EXTRACT(payload, '$.$f')) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.$f')) END)) VIRTUAL";
            }
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS `$table` (seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, revision INT UNSIGNED NOT NULL, payload JSON NOT NULL, PRIMARY KEY(id), UNIQUE KEY seq_unique(seq)" .
                    ($columns ? ", " . implode(", ", $columns) : "") .
                    ($indexes ? ", " . implode(", ", $indexes) : "") .
                    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            );
        }
        foreach (["UPDATE", "DELETE"] as $verb) {
            $name = "adsp_audit_no_" . strtolower($verb);
            $q = $this->db->prepare(
                "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?",
            );
            $q->execute([$name]);
            if (!$q->fetch()) {
                $this->db->exec(
                    "CREATE TRIGGER `$name` BEFORE $verb ON adsp_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit records are append-only'",
                );
            }
        }
    }
    public function run(callable $fn)
    {
        if ($this->running) {
            return $fn($this);
        }
        $lock = false;
        try {
            $q = $this->db->query(
                "SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), ':adsp-transaction'), 256), 10)",
            );
            if ((int) $q->fetchColumn() !== 1) {
                throw new HttpError(
                    409,
                    "Database is busy. Refresh and try again.",
                    "VERSION_CONFLICT",
                );
            }
            $lock = true;
            $this->db->beginTransaction();
            $this->running = true;
            $r = $fn($this);
            $this->db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->running) {
                $this->db->rollBack();
            }
            if (
                $e instanceof PDOException &&
                in_array(
                    (string) $e->getCode(),
                    ["23000", "40001", "HY000"],
                    true,
                )
            ) {
                throw new HttpError(
                    409,
                    "This record changed. Refresh and try again.",
                    "VERSION_CONFLICT",
                );
            }
            throw $e;
        } finally {
            $this->running = false;
            if ($lock) {
                $this->db->query(
                    "SELECT RELEASE_LOCK(SHA2(CONCAT(DATABASE(), ':adsp-transaction'), 256))",
                );
            }
        }
    }
    private function table($name): string
    {
        if (!in_array($name, COLLECTIONS, true)) {
            throw new RuntimeException("Unknown collection.");
        }
        return "adsp_" . $name;
    }
    public function get($name, $id)
    {
        $q = $this->db->prepare(
            "SELECT payload FROM " . $this->table($name) . " WHERE id=?",
        );
        $q->execute([(string) $id]);
        $r = $q->fetchColumn();
        return $r === false
            ? null
            : json_decode($r, true, 512, JSON_THROW_ON_ERROR);
    }
    public function getForUpdate($name, $id)
    {
        $q = $this->db->prepare(
            "SELECT payload FROM " .
                $this->table($name) .
                " WHERE id=? FOR UPDATE",
        );
        $q->execute([(string) $id]);
        $r = $q->fetchColumn();
        return $r === false
            ? null
            : json_decode($r, true, 512, JSON_THROW_ON_ERROR);
    }
    public function list($name): array
    {
        return array_map(
            fn($r) => json_decode(
                $r["payload"],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $this->db
                ->query(
                    "SELECT payload FROM " .
                        $this->table($name) .
                        " ORDER BY seq",
                )
                ->fetchAll(),
        );
    }
    public function insert($name, $r): array
    {
        $r["revision"] = 1;
        $q = $this->db->prepare(
            "INSERT INTO " .
                $this->table($name) .
                " (id,revision,payload) VALUES(?,?,?)",
        );
        try {
            $q->execute([$r["id"], 1, js_json_stringify($r)]);
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                throw new HttpError(
                    409,
                    "This record changed. Refresh and try again.",
                    "VERSION_CONFLICT",
                );
            }
            throw $e;
        }
        return $r;
    }
    public function update($name, $r, $expected = null): array
    {
        if ($name === "audit_logs") {
            throw new HttpError(409, "Audit records are append-only.");
        }
        $expected ??= $r["revision"];
        $r["revision"] = $expected + 1;
        $q = $this->db->prepare(
            "UPDATE " .
                $this->table($name) .
                " SET revision=?,payload=? WHERE id=? AND revision=?",
        );
        $q->execute([
            $r["revision"],
            js_json_stringify($r),
            $r["id"],
            $expected,
        ]);
        if (!$q->rowCount()) {
            throw new HttpError(
                409,
                "This record changed. Refresh and try again.",
                "VERSION_CONFLICT",
            );
        }
        return $r;
    }
    public function remove($name, $id): void
    {
        if ($name === "audit_logs") {
            throw new HttpError(409, "Audit records are append-only.");
        }
        $q = $this->db->prepare(
            "DELETE FROM " . $this->table($name) . " WHERE id=?",
        );
        $q->execute([$id]);
    }
    public function health(): bool
    {
        return (bool) $this->db->query("SELECT 1")->fetchColumn();
    }
}
