<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_validation_choose(
    $value = null,
    $values = null,
    $label = "option",
) {
    if (js_truth(!js_truth(jm($values, "includes", $value)))) {
        throw new HttpError(400, "Select a valid " . js_string($label) . ".");
    }
    return $value;
}
function a_platform_validation_date($value = null, $optional = false)
{
    if (
        js_truth(
            js_and(!js_truth($value), function () use (&$optional) {
                return $optional;
            }),
        )
    ) {
        return "";
    }
    if (
        js_truth(
            js_or(
                js_or(
                    js_or(js_typeof($value) !== "string", function () use (
                        &$value,
                    ) {
                        return !js_truth(
                            jm(
                                new JsRegex('^\\d{4}-\\d{2}-\\d{2}$', ""),
                                "test",
                                $value,
                            ),
                        );
                    }),
                    function () use (&$value) {
                        return !js_truth(
                            js_number_isFinite(js_date_parse($value)),
                        );
                    },
                ),
                function () use (&$value) {
                    return jm(
                        jm(new JsDate($value), "toISOString"),
                        "slice",
                        0,
                        10,
                    ) !== $value;
                },
            ),
        )
    ) {
        throw new HttpError(400, "Use a valid calendar date (YYYY-MM-DD).");
    }
    return $value;
}
function a_platform_validation_revision($record = null, $body = null)
{
    if (js_truth(js_Number(g($body, "revision")) !== g($record, "revision"))) {
        throw new HttpError(
            409,
            "This record changed. Refresh and try again.",
            "VERSION_CONFLICT",
        );
    }
}
function a_platform_validation_strings($values = null, $max = 100)
{
    if (js_truth($values == null)) {
        return [];
    }
    if (
        js_truth(
            js_or(
                js_or(!js_truth(js_array_isArray($values)), function () use (
                    &$values,
                    &$max,
                ) {
                    return g($values, "length") > $max;
                }),
                function () use (&$values) {
                    return jm($values, "some", function ($value = null) {
                        return js_or(
                            js_typeof($value) !== "string",
                            function () use (&$value) {
                                return g($value, "length") > 100;
                            },
                        );
                    });
                },
            ),
        )
    ) {
        throw new HttpError(400, "Invalid selection list.");
    }
    return [...js_array(new JsSet($values))];
}
function a_platform_validation_audience(
    $tx = null,
    $input = null,
    $publicAllowed = false,
) {
    $value = js_or($input, function () {
        return ["type" => "ALL_STAFF"];
    });
    a_platform_validation_choose(
        g($value, "type"),
        js_truth($publicAllowed)
            ? ["PUBLIC", "ALL_STAFF", "SELECTED"]
            : ["ALL_STAFF", "SELECTED"],
        "audience",
    );
    $userIds = a_platform_validation_strings(g($value, "userIds"));
    $roles = a_platform_validation_strings(g($value, "roles"), 20);
    $departmentIds = a_platform_validation_strings(
        g($value, "departmentIds"),
        30,
    );
    if (
        js_truth(
            jm($roles, "some", function ($role = null) {
                return !js_truth(g(a_platform_access_ROLE_PERMISSIONS, $role));
            }),
        )
    ) {
        throw new HttpError(400, "Unknown audience role.");
    }
    foreach (js_iter($userIds) as $item1488) {
        $id = $item1488;
        if (js_truth(!js_truth(g(jm($tx, "get", "users", $id), "active")))) {
            throw new HttpError(400, "Unknown audience user.");
        }
    }
    foreach (js_iter($departmentIds) as $item1610) {
        $id = $item1610;
        if (js_truth(!js_truth(jm($tx, "get", "departments", $id)))) {
            throw new HttpError(400, "Unknown audience department.");
        }
    }
    if (
        js_truth(
            js_and(
                js_and(
                    js_and(g($value, "type") === "SELECTED", function () use (
                        &$userIds,
                    ) {
                        return !js_truth(g($userIds, "length"));
                    }),
                    function () use (&$roles) {
                        return !js_truth(g($roles, "length"));
                    },
                ),
                function () use (&$departmentIds) {
                    return !js_truth(g($departmentIds, "length"));
                },
            ),
        )
    ) {
        throw new HttpError(400, "Choose at least one audience.");
    }
    return [
        "type" => g($value, "type"),
        "userIds" => $userIds,
        "roles" => $roles,
        "departmentIds" => $departmentIds,
    ];
}
function a_platform_validation_activeDate($record = null)
{
    return js_and(
        js_or(!js_truth(g($record, "publishAt")), function () use (&$record) {
            return js_date_parse(g($record, "publishAt")) <= js_date_now();
        }),
        function () use (&$record) {
            return js_or(!js_truth(g($record, "expiresAt")), function () use (
                &$record,
            ) {
                return js_date_parse(
                    js_add(g($record, "expiresAt"), "T23:59:59Z"),
                ) >= js_date_now();
            });
        },
    );
}
function a_platform_validation_safeRecord($record = null)
{
    if (
        js_truth(
            js_or(
                js_or($record === null, function () use (&$record) {
                    return $record === null;
                }),
                function () use (&$record) {
                    return $record === false;
                },
            ),
        )
    ) {
        return null;
    }
    if (js_truth(js_array_isArray($record))) {
        return jm($record, "map", "a_platform_validation_safeRecord");
    }
    if (js_truth(js_typeof($record) !== "object")) {
        return $record;
    }
    return js_object_fromEntries(
        jm(
            jm(js_object_entries($record), "filter", function ($arg0) {
                $key = g($arg0, 0);
                return !js_truth(
                    jm(
                        [
                            "storageKey",
                            "mfaSecretEnc",
                            "salt",
                            "hash",
                            "algorithm",
                        ],
                        "includes",
                        $key,
                    ),
                );
            }),
            "map",
            function ($arg0) {
                $key = g($arg0, 0);
                $value = g($arg0, 1);
                return [
                    $key,
                    js_truth($key === "file")
                        ? public_file_metadata($value)
                        : (js_truth(
                            js_and($value, function () use (&$value) {
                                return js_typeof($value) === "object";
                            }),
                        )
                            ? a_platform_validation_safeRecord($value)
                            : $value),
                ];
            },
        ),
    );
}
