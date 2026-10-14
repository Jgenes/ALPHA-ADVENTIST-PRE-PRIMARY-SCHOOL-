<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_jobs_maintenance($platform = null)
{
    $store = g($platform, "store");
    $config = g($platform, "config");
    $files = g($platform, "files");
    $now = js_date_now();
    jm($store, "run", function ($tx = null) use (&$config, &$files, &$now) {
        foreach (
            js_iter(["sessions", "login_limits", "download_grants"])
            as $item378
        ) {
            $name = $item378;
            foreach (js_iter(jm($tx, "list", $name)) as $item454) {
                $record = $item454;
                if (js_truth(g($record, "expiresAt") <= $now)) {
                    jm($tx, "remove", $name, g($record, "id"));
                }
            }
        }
        foreach (js_iter(jm($tx, "list", "system_settings")) as $item569) {
            $record = $item569;
            if (
                js_truth(
                    js_and(
                        jm(g($record, "id"), "startsWith", "submission-key:"),
                        function () use (&$now, &$record) {
                            return g($record, "expiresAt") <= $now;
                        },
                    ),
                )
            ) {
                jm($tx, "remove", "system_settings", g($record, "id"));
            }
        }
        foreach (
            js_iter(["submissions", "admissions", "privacy_requests"])
            as $item747
        ) {
            $name = $item747;
            $purged = 0;
            foreach (js_iter(jm($tx, "list", $name)) as $item847) {
                $record = $item847;
                if (js_truth(g($record, "expiresAt") > $now)) {
                    continue;
                }
                jm($tx, "remove", $name, g($record, "id"));
                $purged++;
                foreach (js_iter(jm($tx, "list", "outbox")) as $item997) {
                    $alert = $item997;
                    if (js_truth(g($alert, "reference") === g($record, "id"))) {
                        jm($tx, "remove", "outbox", g($alert, "id"));
                    }
                }
            }
            if (js_truth($purged)) {
                audit(
                    $tx,
                    $config,
                    ["userId" => "system"],
                    "retention.purge",
                    $name,
                    "",
                    "success",
                    ["count" => $purged],
                );
            }
        }
        foreach (js_iter(jm($tx, "list", "cms_content")) as $item1256) {
            $content = $item1256;
            if (
                js_truth(
                    js_or(
                        g($content, "status") !== "SCHEDULED",
                        function () use (&$now, &$content) {
                            return js_date_parse(g($content, "publishAt")) >
                                $now;
                        },
                    ),
                )
            ) {
                continue;
            }
            $publisher = jm($tx, "get", "users", g($content, "publishedBy"));
            $flow = jm(
                $tx,
                "get",
                "workflow_instances",
                g($content, "workflowId"),
            );
            if (
                js_truth(
                    js_or(
                        js_or(
                            !js_truth(
                                a_platform_access_has(
                                    $publisher,
                                    "cms.publish",
                                ),
                            ),
                            function () use (&$flow) {
                                return g($flow, "status") !== "APPROVED";
                            },
                        ),
                        function () use (&$tx, &$content) {
                            return js_and(
                                g($content, "mediaId"),
                                function () use (&$tx, &$content) {
                                    return !js_truth(
                                        a_platform_privacy_mediaIsPublic(
                                            $tx,
                                            g($content, "mediaId"),
                                        ),
                                    );
                                },
                            );
                        },
                    ),
                )
            ) {
                jm($tx, "update", "cms_content", [
                    ...js_array($content),
                    "status" => "APPROVED",
                    "publishAt" => null,
                ]);
                a_platform_workflows_notify(
                    $tx,
                    g($content, "createdBy"),
                    "Scheduled publication needs a new publisher or media consent check",
                    "/portal/cms",
                    g($content, "id"),
                );
                audit(
                    $tx,
                    $config,
                    ["userId" => "system"],
                    "cms.scheduled_publish",
                    "cms_content",
                    g($content, "id"),
                    "denied",
                );
                continue;
            }
            if (js_truth(g($content, "published"))) {
                js_push($content["history"], g($content, "published"));
            }
            $content["published"] = [
                "kind" => g($content, "kind"),
                "slug" => g($content, "slug"),
                "title" => g($content, "title"),
                "body" => g($content, "body"),
                "content" => jm(
                    g($content, "body"),
                    "split",
                    new JsRegex('\\n\\s*\\n', ""),
                ),
                "excerpt" => g($content, "excerpt"),
                "category" => g($content, "category"),
                "language" => g($content, "language"),
                "mediaId" => g($content, "mediaId"),
                "seoTitle" => g($content, "seoTitle"),
                "seoDescription" => g($content, "seoDescription"),
                "eventDate" => g($content, "eventDate"),
                "version" => g($content, "version"),
                "publishedAt" => g($content, "publishAt"),
            ];
            jm($tx, "update", "cms_content", [
                ...js_array($content),
                "status" => "PUBLISHED",
            ]);
            audit(
                $tx,
                $config,
                ["userId" => "system"],
                "cms.scheduled_publish",
                "cms_content",
                g($content, "id"),
            );
        }
        foreach (js_iter(jm($tx, "list", "media")) as $item2739) {
            $media = $item2739;
            if (
                js_truth(
                    js_and(
                        g($media, "status") === "PUBLISHED",
                        function () use (&$tx, &$media) {
                            return !js_truth(
                                a_platform_privacy_validMediaConsent(
                                    $tx,
                                    $media,
                                ),
                            );
                        },
                    ),
                )
            ) {
                jm($tx, "update", "media", [
                    ...js_array($media),
                    "status" => "WITHDRAWN",
                ]);
                jm($files, "unpublish", g($media, "file"));
                audit(
                    $tx,
                    $config,
                    ["userId" => "system"],
                    "media.consent_expired",
                    "media",
                    g($media, "id"),
                );
            }
        }
        $users = jm($tx, "list", "users");
        foreach (js_iter(jm($tx, "list", "contracts")) as $item3137) {
            $contract = $item3137;
            if (js_truth(g($contract, "status") !== "ACTIVE")) {
                continue;
            }
            $days = js_math_ceil(
                (js_date_parse(js_add(g($contract, "endDate"), "T23:59:59Z")) -
                    $now) /
                    86400000,
            );
            $applicable = jm([90, 60, 30, 14, 7], "filter", function (
                $threshold = null,
            ) use (&$days) {
                return $days <= $threshold;
            });
            if (js_truth(!js_truth(g($applicable, "length")))) {
                continue;
            }
            $threshold = js_math_min(...js_array($applicable));
            $recipients = new JsSet(
                jm(
                    [
                        g($contract, "ownerId"),
                        g($contract, "supervisorId"),
                        ...js_array(
                            jm(
                                jm($users, "filter", function ($user = null) {
                                    return js_and(
                                        g($user, "active"),
                                        function () use (&$user) {
                                            return jm(
                                                g($user, "roles"),
                                                "some",
                                                function ($role = null) {
                                                    return jm(
                                                        [
                                                            "hr_officer",
                                                            "school_admin",
                                                        ],
                                                        "includes",
                                                        $role,
                                                    );
                                                },
                                            );
                                        },
                                    );
                                }),
                                "map",
                                function ($user = null) {
                                    return g($user, "id");
                                },
                            ),
                        ),
                    ],
                    "filter",
                    "js_truth",
                ),
            );
            foreach (js_iter($recipients) as $item3740) {
                $userId = $item3740;
                a_platform_workflows_notify(
                    $tx,
                    $userId,
                    js_truth($days > 0)
                        ? "Contract " .
                            js_string(g($contract, "reference")) .
                            " expires in " .
                            js_string($days) .
                            " days"
                        : "Contract " .
                            js_string(g($contract, "reference")) .
                            " has expired",
                    "/portal/notifications",
                    g($contract, "id"),
                    "contract-reminder:" .
                        js_string(g($contract, "id")) .
                        ":" .
                        js_string($threshold) .
                        ":" .
                        js_string($userId) .
                        "",
                );
            }
            if (js_truth($days <= 0)) {
                jm($tx, "update", "contracts", [
                    ...js_array($contract),
                    "status" => "EXPIRED",
                ]);
                audit(
                    $tx,
                    $config,
                    ["userId" => "system"],
                    "contract.expire",
                    "contract",
                    g($contract, "id"),
                );
            }
        }
        $old = jm($tx, "get", "system_settings", "maintenance");
        $record = [
            "id" => "maintenance",
            "lastSuccessAt" => jm(new JsDate($now), "toISOString"),
        ];
        if (js_truth($old)) {
            jm($tx, "update", "system_settings", [
                ...js_array($old),
                ...js_array($record),
            ]);
        } else {
            jm($tx, "insert", "system_settings", $record);
        }
    });
}
