<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
trait PlatformCore
{
    public function init()
    {
        jm(g($this, "files"), "init");
        jm(g($this, "store"), "run", function ($tx = null) {
            foreach (
                js_iter(a_platform_workflows_DEFAULT_WORKFLOWS)
                as $item559
            ) {
                $definition = $item559;
                if (
                    js_truth(
                        !js_truth(
                            jm(
                                $tx,
                                "get",
                                "workflow_definitions",
                                g($definition, "id"),
                            ),
                        ),
                    )
                ) {
                    jm($tx, "insert", "workflow_definitions", [
                        ...js_array($definition),
                        "version" => 1,
                    ]);
                }
            }
            foreach (
                js_iter([
                    ["pre-primary", "Pre-Primary"],
                    ["primary", "Primary"],
                    ["administration", "Administration"],
                    ["hr", "Human Resources"],
                    ["finance", "Finance"],
                    ["ict", "ICT"],
                    ["support", "Supporting Services"],
                ])
                as $item741
            ) {
                $id = g($item741, 0);
                $name = g($item741, 1);
                if (js_truth(!js_truth(jm($tx, "get", "departments", $id)))) {
                    jm($tx, "insert", "departments", [
                        "id" => $id,
                        "name" => $name,
                    ]);
                }
            }
            foreach (
                js_iter([
                    "annual",
                    "sick",
                    "maternity",
                    "paternity",
                    "compassionate",
                    "study",
                    "emergency",
                    "other",
                ])
                as $item1055
            ) {
                $id = $item1055;
                if (js_truth(!js_truth(jm($tx, "get", "leave_types", $id)))) {
                    jm($tx, "insert", "leave_types", [
                        "id" => $id,
                        "name" => js_add(
                            jm(g($id, 0), "toUpperCase"),
                            jm($id, "slice", 1),
                        ),
                        "workflowId" => "leave-standard",
                        "deductsBalance" => $id === "annual",
                        "nonWorkingDays" => [0, 6],
                        "enabled" => true,
                    ]);
                }
            }
            if (
                js_truth(
                    !js_truth(jm($tx, "get", "system_settings", "initialised")),
                )
            ) {
                foreach (js_iter(g(SEED, "news")) as $item1620) {
                    $news = $item1620;
                    jm($tx, "insert", "cms_content", [
                        "id" => rid("CMS-"),
                        "kind" => "news",
                        "slug" => g($news, "slug"),
                        "title" => g($news, "title"),
                        "excerpt" => g($news, "excerpt"),
                        "body" => jm(
                            g($news, "content"),
                            "join",
                            '

',
                        ),
                        "category" => g($news, "category"),
                        "language" => "en",
                        "version" => 1,
                        "createdBy" => "legacy-public-site",
                        "status" => "PUBLISHED",
                        "legacyPublication" => true,
                        "published" => [
                            ...js_array($news),
                            "body" => jm(
                                g($news, "content"),
                                "join",
                                '

',
                            ),
                            "version" => 1,
                            "publishedAt" => g($news, "sortDate"),
                            "language" => "en",
                            "mediaId" => "",
                        ],
                        "history" => [],
                    ]);
                }
                jm($tx, "insert", "system_settings", [
                    "id" => "initialised",
                    "schemaVersion" => 1,
                    "at" => jm(new JsDate(), "toISOString"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    ["userId" => "system"],
                    "platform.initialise",
                    "system",
                    "adsp-v1",
                );
            }
        });
        if (js_truth(g(g($this, "config"), "adminPassword"))) {
            $exists = jm(g($this, "store"), "run", function ($tx = null) use (
                &$user,
            ) {
                return jm(jm($tx, "list", "users"), "some", function (
                    $user = null,
                ) {
                    return jm(g($user, "roles"), "includes", "system_admin");
                });
            });
            if (js_truth(!js_truth($exists))) {
                $user = create_user(
                    jm(g(g($this, "config"), "adminUsername"), "toLowerCase"),
                    g(g($this, "config"), "adminName"),
                    ["system_admin"],
                    g(g($this, "config"), "adminPassword"),
                );
                jm(g($this, "store"), "run", function ($tx = null) use (
                    &$user,
                ) {
                    if (
                        js_truth(
                            jm(jm($tx, "list", "users"), "some", function (
                                $item = null,
                            ) {
                                return jm(
                                    g($item, "roles"),
                                    "includes",
                                    "system_admin",
                                );
                            }),
                        )
                    ) {
                        return null;
                    }
                    jm($tx, "insert", "users", $user);
                    audit(
                        $tx,
                        g($this, "config"),
                        ["userId" => "bootstrap"],
                        "user.create",
                        "user",
                        g($user, "id"),
                        "success",
                        ["roles" => g($user, "roles")],
                    );
                });
            }
        }
        $headTeacherConfig = g($this, "config");
        $hasHeadTeacherBootstrap = js_or(
            js_or(
                js_or(
                    js_or(
                        g($headTeacherConfig, "headTeacherUsername"),
                        function () use (&$headTeacherConfig) {
                            return g($headTeacherConfig, "headTeacherName");
                        },
                    ),
                    function () use (&$headTeacherConfig) {
                        return g($headTeacherConfig, "headTeacherPassword");
                    },
                ),
                function () use (&$headTeacherConfig) {
                    return g($headTeacherConfig, "headTeacherApproved");
                },
            ),
            function () use (&$headTeacherConfig) {
                return g($headTeacherConfig, "headTeacherApprovalReference");
            },
        );
        if (js_truth($hasHeadTeacherBootstrap)) {
            $exists = jm(g($this, "store"), "run", function ($tx = null) use (
                &$user,
            ) {
                return jm(jm($tx, "list", "users"), "some", function (
                    $user = null,
                ) {
                    return jm(g($user, "roles"), "includes", "head_teacher");
                });
            });
            if (js_truth(!js_truth($exists))) {
                if (
                    js_truth(
                        js_or(
                            js_or(
                                js_or(
                                    js_or(
                                        !js_truth(
                                            g(
                                                $headTeacherConfig,
                                                "headTeacherUsername",
                                            ),
                                        ),
                                        function () use (&$headTeacherConfig) {
                                            return !js_truth(
                                                g(
                                                    $headTeacherConfig,
                                                    "headTeacherName",
                                                ),
                                            );
                                        },
                                    ),
                                    function () use (&$headTeacherConfig) {
                                        return !js_truth(
                                            g(
                                                $headTeacherConfig,
                                                "headTeacherPassword",
                                            ),
                                        );
                                    },
                                ),
                                function () use (&$headTeacherConfig) {
                                    return !js_truth(
                                        g(
                                            $headTeacherConfig,
                                            "headTeacherApproved",
                                        ),
                                    );
                                },
                            ),
                            function () use (&$headTeacherConfig) {
                                return !js_truth(
                                    g(
                                        $headTeacherConfig,
                                        "headTeacherApprovalReference",
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    throw new RuntimeException(
                        "Head-teacher environment provisioning requires complete account details and explicit owner approval.",
                    );
                }
                $user = create_user(
                    jm(
                        g($headTeacherConfig, "headTeacherUsername"),
                        "toLowerCase",
                    ),
                    g($headTeacherConfig, "headTeacherName"),
                    ["head_teacher"],
                    g($headTeacherConfig, "headTeacherPassword"),
                );
                jm(g($this, "store"), "run", function ($tx = null) use (
                    &$user,
                    &$headTeacherConfig,
                ) {
                    if (
                        js_truth(
                            jm(jm($tx, "list", "users"), "some", function (
                                $item = null,
                            ) {
                                return jm(
                                    g($item, "roles"),
                                    "includes",
                                    "head_teacher",
                                );
                            }),
                        )
                    ) {
                        return null;
                    }
                    jm($tx, "insert", "users", $user);
                    audit(
                        $tx,
                        g($this, "config"),
                        ["userId" => "owner-approved-bootstrap"],
                        "user.bootstrap",
                        "user",
                        g($user, "id"),
                        "success",
                        [
                            "role" => "head_teacher",
                            "approvalReference" => jm(
                                js_String(
                                    g(
                                        $headTeacherConfig,
                                        "headTeacherApprovalReference",
                                    ),
                                ),
                                "slice",
                                0,
                                120,
                            ),
                        ],
                    );
                });
            }
        }
    }
    public function run($actor = null, $fn = null)
    {
        return jm(g($this, "store"), "run", function ($tx = null) use (
            &$actor,
            &$fn,
        ) {
            $fresh = live_actor($tx, $actor, g($this, "config"));
            require_ready($fresh);
            return $fn($tx, $fresh);
        });
    }
    public function definitions($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "workflow.read",
            );
            return jm($tx, "list", "workflow_definitions");
        });
    }
    public function createWorkflow($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "workflow.configure",
            );
            $id = text(g($body, "id"), 80, true);
            if (
                js_truth(
                    js_or(
                        !js_truth(
                            jm(
                                new JsRegex('^[a-z][a-z0-9-]{2,79}$', ""),
                                "test",
                                $id,
                            ),
                        ),
                        function () use (&$tx, &$id) {
                            return jm($tx, "get", "workflow_definitions", $id);
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose a unique lowercase workflow ID.",
                );
            }
            if (
                js_truth(
                    !js_truth(
                        g(
                            a_platform_workflows_RESOURCE_TYPES,
                            g($body, "kind"),
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose a supported workflow process.",
                );
            }
            $definition = a_platform_workflows_validateDefinition($body, [
                "id" => $id,
                "kind" => g($body, "kind"),
                "version" => 0,
            ]);
            $saved = jm($tx, "insert", "workflow_definitions", $definition);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "workflow.create",
                "workflow_definition",
                $id,
            );
            return $saved;
        });
    }
    public function configureWorkflow($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "workflow.configure",
            );
            $previous = jm($tx, "get", "workflow_definitions", $id);
            if (js_truth(!js_truth($previous))) {
                throw new HttpError(404, "Workflow not found.");
            }
            a_platform_validation_revision($previous, $body);
            $updated = jm(
                $tx,
                "update",
                "workflow_definitions",
                a_platform_workflows_validateDefinition($body, $previous),
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "workflow.configure",
                "workflow_definition",
                $id,
                "success",
                ["version" => g($updated, "version")],
            );
            return $updated;
        });
    }
    public function approvalQueue($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            if (
                js_truth(
                    !js_truth(
                        jm(
                            [
                                "leave.review",
                                "leave.approve",
                                "contract.review",
                                "contract.approve",
                                "contract.sign",
                                "contract.view_self",
                                "document.review",
                                "document.approve",
                                "cms.review",
                                "cms.approve",
                                "media.review",
                            ],
                            "some",
                            function ($permission = null) use (&$actor) {
                                return a_platform_access_has(
                                    g($actor, "user"),
                                    $permission,
                                );
                            },
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "No approval responsibilities are assigned to this account.",
                );
            }
            $list = [];
            foreach (
                js_iter(jm($tx, "list", "workflow_instances"))
                as $item6842
            ) {
                $instance = $item6842;
                if (js_truth(g($instance, "status") !== "PENDING")) {
                    continue;
                }
                $resource = jm(
                    $tx,
                    "get",
                    g($instance, "collection"),
                    g($instance, "resourceId"),
                );
                if (
                    js_truth(
                        js_and($resource, function () use (
                            &$actor,
                            &$instance,
                            &$resource,
                        ) {
                            return a_platform_workflows_eligible(
                                g($actor, "user"),
                                $instance,
                                $resource,
                            );
                        }),
                    )
                ) {
                    js_push($list, [
                        ...js_array($instance),
                        "resource" => a_platform_validation_safeRecord(
                            $resource,
                        ),
                    ]);
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "approval.queue_read",
                "workflow_instance",
                "assigned",
            );
            return $list;
        });
    }
    public function decide($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            $instance = jm($tx, "get", "workflow_instances", $id);
            if (js_truth(!js_truth($instance))) {
                throw new HttpError(403, "Approval is not available.");
            }
            $resource = jm(
                $tx,
                "get",
                g($instance, "collection"),
                g($instance, "resourceId"),
            );
            if (js_truth(!js_truth($resource))) {
                throw new HttpError(409, "Request is no longer available.");
            }
            $updated = a_platform_workflows_decide(
                $tx,
                $actor,
                g($this, "config"),
                $instance,
                $resource,
                $body,
            );
            if (
                js_truth(
                    jm(
                        ["APPROVE", "REJECT", "RETURN"],
                        "includes",
                        g($body, "decision"),
                    ),
                )
            ) {
                if (
                    js_truth(
                        js_and(
                            g($updated, "kind") === "LEAVE_REQUEST",
                            function () use (&$updated) {
                                return jm(
                                    ["APPROVED", "REJECTED", "RETURNED"],
                                    "includes",
                                    g($updated, "status"),
                                );
                            },
                        ),
                    )
                ) {
                    if (js_truth(g($resource, "balanceId"))) {
                        $balance = jm(
                            $tx,
                            "get",
                            "leave_balances",
                            g($resource, "balanceId"),
                        );
                        $balance["reservedDays"] -= g($resource, "days");
                        if (js_truth(g($updated, "status") === "APPROVED")) {
                            $balance["usedDays"] += g($resource, "days");
                        }
                        jm($tx, "update", "leave_balances", $balance);
                    }
                }
                $resource["status"] = js_truth(
                    g($updated, "status") === "PENDING",
                )
                    ? "UNDER_REVIEW"
                    : g($updated, "status");
                if (js_truth(g($updated, "kind") === "EMPLOYMENT_CONTRACT")) {
                    if (js_truth(g($updated, "status") === "APPROVED")) {
                        $resource["status"] = "ACTIVE";
                        if (js_truth(g($resource, "replacesId"))) {
                            $previous = jm(
                                $tx,
                                "get",
                                "contracts",
                                g($resource, "replacesId"),
                            );
                            if (
                                js_truth(
                                    js_or(
                                        !js_truth($previous),
                                        function () use (&$previous) {
                                            return !js_truth(
                                                jm(
                                                    ["ACTIVE", "EXPIRED"],
                                                    "includes",
                                                    g($previous, "status"),
                                                ),
                                            );
                                        },
                                    ),
                                )
                            ) {
                                throw new HttpError(
                                    409,
                                    "The previous contract has already been superseded. HR must resolve the renewal conflict.",
                                );
                            }
                            jm($tx, "update", "contracts", [
                                ...js_array($previous),
                                "status" => "SUPERSEDED",
                                "supersededBy" => g($resource, "id"),
                            ]);
                        }
                    } else {
                        if (
                            js_truth(
                                js_and(
                                    g($updated, "status") === "PENDING",
                                    function () use (&$updated) {
                                        return g(
                                            g(
                                                g($updated, "steps"),
                                                g($updated, "stepIndex"),
                                            ),
                                            "selector",
                                        ) === "owner";
                                    },
                                ),
                            )
                        ) {
                            $resource["status"] = "AWAITING_EMPLOYEE";
                        }
                    }
                }
                if (js_truth(g($updated, "status") === "APPROVED")) {
                    $resource["approvedBy"] = g(g($actor, "user"), "id");
                    $resource["approvedAt"] = jm(new JsDate(), "toISOString");
                }
                jm($tx, "update", g($instance, "collection"), $resource);
            }
            return $updated;
        });
    }
}
