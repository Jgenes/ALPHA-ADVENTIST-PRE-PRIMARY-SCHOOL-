<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function legacy_legacyCollections($snapshot = null)
{
    $byName = new JsMap(
        jm(g($snapshot, "collections"), "map", function ($item = null) {
            return [g($item, "name"), g($item, "documents")];
        }),
    );
    $oldState = js_or(
        g(
            jm(jm($byName, "get", "platform_state"), "find", function (
                $item = null,
            ) {
                return g($item, "_id") === "primary";
            }),
            "data",
        ),
        function () {
            return [];
        },
    );
    return function ($name = null) use (&$byName, &$oldState) {
        return js_or(
            js_or(jm($byName, "get", $name), function () use (
                &$oldState,
                &$name,
            ) {
                return g($oldState, $name);
            }),
            function () {
                return [];
            },
        );
    };
}
function legacy_legacyRecords($snapshot = null, $config = null)
{
    $get = legacy_legacyCollections($snapshot);
    $content = [];
    $records = [];
    $text = function ($value = null, $max = 2000) {
        return jm(jm(js_String($value ?? ""), "trim"), "slice", 0, $max);
    };
    $iso = function ($value = null) use (&$snapshot) {
        $date = new JsDate(
            js_or($value, function () use (&$snapshot) {
                return g($snapshot, "exportedAt");
            }),
        );
        if (js_truth(!js_truth(js_number_isFinite(jm($date, "getTime"))))) {
            throw new RuntimeException("Legacy dates need review.");
        }
        return jm($date, "toISOString");
    };
    foreach (
        js_iter([
            ["news", "news"],
            ["event", "events"],
            ["banner", "announcements"],
        ])
        as $item1021
    ) {
        $kind = g($item1021, 0);
        $name = g($item1021, 1);
        foreach (js_iter($get($name)) as $item1124) {
            $old = $item1124;
            $title = $text(g($old, "title"), 180);
            $body = $text(
                js_truth(js_array_isArray(g($old, "content")))
                    ? jm(
                        g($old, "content"),
                        "join",
                        '

',
                    )
                    : js_or(
                        js_or(g($old, "body"), function () use (&$old) {
                            return g($old, "description");
                        }),
                        function () use (&$old) {
                            return g($old, "text");
                        },
                    ),
                20000,
            );
            if (
                js_truth(
                    js_or(!js_truth($title), function () use (&$body) {
                        return !js_truth($body);
                    }),
                )
            ) {
                throw new RuntimeException(
                    "Legacy public content needs field mapping before migration.",
                );
            }
            $slug = a_lib_slugify(
                js_or(g($old, "slug"), function () use (&$title) {
                    return $title;
                }),
            );
            $publishedAt = $iso(
                js_or(
                    js_or(g($old, "sortDate"), function () use (&$old) {
                        return g($old, "at");
                    }),
                    function () use (&$old) {
                        return g($old, "createdAt");
                    },
                ),
            );
            $fields = [
                "kind" => $kind,
                "slug" => $slug,
                "title" => $title,
                "body" => $body,
                "content" => jm($body, "split", new JsRegex('\\n\\s*\\n', "")),
                "excerpt" => $text(
                    js_or(g($old, "excerpt"), function () use (&$body) {
                        return $body;
                    }),
                    400,
                ),
                "category" => $text(
                    js_or(g($old, "category"), function () {
                        return "School News";
                    }),
                    100,
                ),
                "language" => "en",
                "mediaId" => "",
                "seoTitle" => "",
                "seoDescription" => "",
                "eventDate" => js_truth($kind === "event")
                    ? jm(
                        $iso(
                            js_or(
                                js_or(g($old, "date"), function () use (&$old) {
                                    return g($old, "sortDate");
                                }),
                                function () use (&$old) {
                                    return g($old, "createdAt");
                                },
                            ),
                        ),
                        "slice",
                        0,
                        10,
                    )
                    : "",
                "version" => 1,
                "publishedAt" => $publishedAt,
            ];
            js_push($content, [
                "id" => js_add(
                    "CMS-LEGACY-",
                    jm(
                        sha256(js_add(js_add($kind, ":"), $slug)),
                        "slice",
                        0,
                        24,
                    ),
                ),
                ...js_array($fields),
                "status" => "DRAFT",
                "createdBy" => "legacy-import",
                "createdAt" => jm(new JsDate(), "toISOString"),
                "revisionAuthors" => [],
                "published" => null,
                "history" => [],
                "legacyPublication" => false,
                "migrationNote" =>
                    "Imported text only. Verify institutional facts and use independent review before publishing.",
            ]);
        }
    }
    $applications = $get("admissionApplications");
    $appReferences = new JsSet(
        jm($applications, "map", function ($item = null) {
            return g($item, "applicationReference");
        }),
    );
    foreach (
        js_iter([
            ["submissions", $get("submissions")],
            ["admissionApplications", $applications],
        ])
        as $item2419
    ) {
        $source = g($item2419, 0);
        $list = g($item2419, 1);
        foreach (js_iter($list) as $item2534) {
            $old = $item2534;
            if (
                js_truth(
                    js_and($source === "submissions", function () use (
                        &$old,
                        &$appReferences,
                    ) {
                        return jm($appReferences, "has", g($old, "reference"));
                    }),
                )
            ) {
                continue;
            }
            $application = $source === "admissionApplications";
            $kind = js_truth($application)
                ? "apply"
                : js_or(
                    jm(
                        js_object_keys(a_platform_communications_FORM_RULES),
                        "find",
                        function ($key = null) use (&$old) {
                            return js_and(
                                !js_truth(
                                    g(
                                        g(
                                            a_platform_communications_FORM_RULES,
                                            $key,
                                        ),
                                        "private",
                                    ),
                                ),
                                function () use (&$old, &$key) {
                                    return js_or(
                                        js_or(
                                            $key === g($old, "kind"),
                                            function () use (&$old, &$key) {
                                                return $key === g($old, "type");
                                            },
                                        ),
                                        function () use (&$old, &$key) {
                                            return g(
                                                g(
                                                    a_platform_communications_FORM_RULES,
                                                    $key,
                                                ),
                                                "type",
                                            ) === g($old, "type");
                                        },
                                    );
                                },
                            );
                        },
                    ),
                    function () {
                        return "contact";
                    },
                );
            $input = js_truth($application)
                ? [
                    "child_name" => g(g($old, "applicant"), "fullName"),
                    "guardian_name" => g(
                        g(g($old, "guardians"), 0),
                        "fullName",
                    ),
                    "phone" => g(g(g($old, "guardians"), 0), "phone"),
                    "email" => g(g(g($old, "guardians"), 0), "email"),
                    "level" => js_or(
                        g(g($old, "programme"), "level"),
                        function () use (&$old) {
                            return g($old, "level");
                        },
                    ),
                    "arrangement" => js_or(
                        g(g($old, "programme"), "arrangement"),
                        function () use (&$old) {
                            return g($old, "arrangement");
                        },
                    ),
                    "message" => js_or(g($old, "message"), function () {
                        return "";
                    }),
                ]
                : js_or(g($old, "data"), function () {
                    return [];
                });
            $data = js_object_fromEntries(
                jm(
                    g(g(a_platform_communications_FORM_RULES, $kind), "fields"),
                    "map",
                    function ($field = null) use (&$text, &$input) {
                        return [
                            $field,
                            $text(
                                g($input, $field),
                                js_truth($field === "message") ? 2000 : 160,
                            ),
                        ];
                    },
                ),
            );
            if (
                js_truth(
                    js_and($application, function () use (&$data) {
                        return js_or(
                            js_or(
                                !js_truth(g($data, "child_name")),
                                function () use (&$data) {
                                    return !js_truth(g($data, "guardian_name"));
                                },
                            ),
                            function () use (&$data) {
                                return !js_truth(g($data, "phone"));
                            },
                        );
                    }),
                )
            ) {
                throw new RuntimeException(
                    "Legacy application mapping needs review; no partial import is allowed.",
                );
            }
            $createdAt = $iso(
                js_or(g($old, "at"), function () use (&$old) {
                    return g($old, "createdAt");
                }),
            );
            $reference = $text(
                js_or(g($old, "applicationReference"), function () use (&$old) {
                    return g($old, "reference");
                }),
                80,
            );
            $id = js_truth(
                js_and($reference, function () use (&$reference) {
                    return jm(
                        new JsRegex('^[A-Za-z0-9-]+$', ""),
                        "test",
                        $reference,
                    );
                }),
            )
                ? $reference
                : js_add(
                    "LEGACY-",
                    jm(
                        sha256(
                            js_add(
                                js_add($source, ":"),
                                js_String(
                                    js_or(
                                        js_or(g($old, "id"), function () use (
                                            &$old,
                                        ) {
                                            return g($old, "_id");
                                        }),
                                        function () use (&$old) {
                                            return js_json_stringify($old);
                                        },
                                    ),
                                ),
                            ),
                        ),
                        "slice",
                        0,
                        24,
                    ),
                );
            js_push($records, [
                "collection" => js_truth($kind === "apply")
                    ? "admissions"
                    : "submissions",
                "record" => [
                    "id" => $id,
                    "reference" => $id,
                    "kind" => $kind,
                    "type" => g(
                        g(a_platform_communications_FORM_RULES, $kind),
                        "type",
                    ),
                    "data" => $data,
                    "status" => js_truth($kind === "apply")
                        ? "SUBMITTED"
                        : "NEW",
                    "createdAt" => $createdAt,
                    "expiresAt" => js_add(
                        js_date_parse($createdAt),
                        (js_truth($kind === "apply")
                            ? g($config, "admissionRetentionDays")
                            : g($config, "formRetentionDays")) * 86400000,
                    ),
                    "history" => [
                        [
                            "from" => "LEGACY",
                            "to" => js_truth($kind === "apply")
                                ? "SUBMITTED"
                                : "NEW",
                            "at" => jm(new JsDate(), "toISOString"),
                            "note" =>
                                "Migrated for authorised review. Verify old status and the lawful retention basis; no old consent is assumed.",
                        ],
                    ],
                    "consent" => [
                        "acceptedAt" => null,
                        "policyVersion" => "legacy-not-recorded",
                        "purpose" => "verify_legacy_record",
                        "legacy" => true,
                    ],
                ],
            ]);
        }
    }
    return [
        "content" => $content,
        "records" => $records,
        "legacyUsersNotImported" => g($get("users"), "length"),
        "legacyMediaNotImported" => g($get("gallery"), "length"),
    ];
}
function legacy_importLegacy($store = null, $config = null, $snapshot = null)
{
    $transformed = legacy_legacyRecords($snapshot, $config);
    return jm($store, "run", function ($tx = null) use (
        &$config,
        &$snapshot,
        &$transformed,
    ) {
        $id = "migration:legacy-to-adsp-v1";
        $previous = jm($tx, "get", "system_settings", $id);
        if (js_truth($previous)) {
            return g($previous, "counts");
        }
        if (
            js_truth(
                js_or(
                    js_or(
                        g(jm($tx, "list", "admissions"), "length"),
                        function () use (&$tx) {
                            return g(jm($tx, "list", "submissions"), "length");
                        },
                    ),
                    function () use (&$tx, &$item) {
                        return jm(
                            jm($tx, "list", "cms_content"),
                            "some",
                            function ($item = null) {
                                return !js_truth(g($item, "legacyPublication"));
                            },
                        );
                    },
                ),
            )
        ) {
            throw new RuntimeException(
                "Target has live platform records. Review a separate merge plan; nothing was changed.",
            );
        }
        foreach (js_iter(g($transformed, "content")) as $item5389) {
            $item = $item5389;
            $old = jm(jm($tx, "list", "cms_content"), "find", function (
                $value = null,
            ) use (&$item) {
                return js_and(
                    g($value, "kind") === g($item, "kind"),
                    function () use (&$item, &$value) {
                        return g($value, "slug") === g($item, "slug");
                    },
                );
            });
            if (js_truth($old)) {
                jm($tx, "update", "cms_content", [
                    ...js_array($old),
                    ...js_array($item),
                    "id" => g($old, "id"),
                    "revision" => g($old, "revision"),
                    "published" => g($old, "published"),
                    "history" => g($old, "history"),
                    "version" => js_add(g($old, "version"), 1),
                ]);
            } else {
                jm($tx, "insert", "cms_content", $item);
            }
        }
        foreach (js_iter(g($transformed, "records")) as $item5786) {
            $item = $item5786;
            jm($tx, "insert", g($item, "collection"), g($item, "record"));
        }
        $counts = [
            "drafts" => g(g($transformed, "content"), "length"),
            "privateRecords" => g(g($transformed, "records"), "length"),
            "legacyUsersNotImported" => g(
                $transformed,
                "legacyUsersNotImported",
            ),
            "legacyMediaNotImported" => g(
                $transformed,
                "legacyMediaNotImported",
            ),
        ];
        jm($tx, "insert", "system_settings", [
            "id" => $id,
            "at" => jm(new JsDate(), "toISOString"),
            "sourceDatabase" => g($snapshot, "database"),
            "sourceExportedAt" => legacy_isoDate(g($snapshot, "exportedAt")),
            "counts" => $counts,
        ]);
        audit(
            $tx,
            $config,
            ["userId" => "approved-migration"],
            "migration.legacy_import",
            "system",
            $id,
            "success",
            $counts,
        );
        return $counts;
    });
}
function legacy_isoDate($value = null)
{
    return jm(new JsDate($value), "toISOString");
}
