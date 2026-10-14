<?php
// Read-only legacy archives are accepted; no MongoDB runtime or driver is required.
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
require dirname(__DIR__) . "/src/php/legacy-migration.php";
function legacy_ejson($v)
{
    if (!is_array($v)) {
        return $v;
    }
    if (count($v) === 1) {
        $k = array_key_first($v);
        $x = $v[$k];
        if ($k === '$date') {
            $date =
                is_array($x) && isset($x['$numberLong'])
                    ? (float) $x['$numberLong']
                    : $x;
            return (new JsDate($date))->toISOString();
        }
        if (
            in_array(
                $k,
                [
                    '$numberLong',
                    '$numberInt',
                    '$numberDecimal',
                    '$numberDouble',
                ],
                true,
            )
        ) {
            if (!is_numeric($x) || !is_finite((float) $x)) {
                throw new RuntimeException("Invalid legacy number.");
            }
            return $x + 0;
        }
        if ($k === '$oid') {
            return (string) $x;
        }
        if ($k === '$binary') {
            return is_array($x) ? $x["base64"] ?? "" : $x;
        }
        if (in_array($k, ['$undefined', '$minKey', '$maxKey'], true)) {
            return null;
        }
        if (str_starts_with((string) $k, '$')) {
            throw new RuntimeException("Unsupported legacy EJSON marker.");
        }
    }
    return array_map("legacy_ejson", $v);
}
cli_main(function () use ($argv) {
    load_environment();
    if (
        env_value("MYSQL_IMPORT_APPROVED") !== "true" ||
        env_value("BACKUP_WRITES_PAUSED") !== "true"
    ) {
        throw new RuntimeException(
            "Explicit import approval and paused writes are required.",
        );
    }
    if (
        in_array(
            env_value("APP_ENV", env_value("NODE_ENV")),
            ["production", "staging"],
            true,
        ) &&
        env_value("MYSQL_IMPORT_STAGE_VERIFIED") !== "true"
    ) {
        throw new RuntimeException(
            "Rehearse the cutover in isolated staging first.",
        );
    }
    $snapshot = legacy_ejson(
        open_backup(
            $argv[1] ?? env_value("MYSQL_IMPORT_BACKUP_FILE"),
            backup_key(),
            true,
        ),
    );
    $c = configuration();
    if (
        !$c["mysql"] ||
        $c["mysql"]["database"] === ($snapshot["database"] ?? "")
    ) {
        throw new RuntimeException("Use a separate dedicated MySQL target.");
    }
    $audited = true;
    foreach ($snapshot["collections"] as $entry) {
        if (!str_starts_with($entry["name"] ?? "", "adsp_")) {
            $audited = false;
        }
    }
    if (env_value("MYSQL_IMPORT_DRY_RUN") === "true") {
        echo "Dry run: " .
            count($snapshot["collections"]) .
            " collections; mode: " .
            ($audited
                ? "audited record transfer"
                : "legacy text and enquiries; accounts/media quarantined") .
            ". Nothing written.\n";
        return;
    }
    $p = cli_application(!$audited);
    if ($audited) {
        foreach ($snapshot["collections"] as &$entry) {
            foreach ($entry["documents"] as &$r) {
                $r["id"] = (string) ($r["id"] ?? ($r["_id"] ?? ""));
                unset($r["_id"]);
            };
        }
        unset($entry, $r);
        $counts = restore_snapshot($p->store, $p->files, $snapshot);
    } else {
        $counts = legacy_importLegacy($p->store, $c, $snapshot);
    }
    echo "Approved import complete: " .
        js_json_stringify($counts) .
        "\nNo old public media copies were recreated. Check audit/file integrity and current consent before release.\n";
});
