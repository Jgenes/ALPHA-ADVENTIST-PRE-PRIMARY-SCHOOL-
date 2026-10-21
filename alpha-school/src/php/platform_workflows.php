<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_platform_workflows_DEFAULT_WORKFLOWS", [
    [
        "id" => "leave-standard",
        "kind" => "LEAVE_REQUEST",
        "name" => "Leave · supervisor → HR → Head Teacher",
        "steps" => [
            [
                "id" => "supervisor",
                "label" => "Supervisor review",
                "selector" => "supervisor",
                "permission" => "leave.review",
            ],
            [
                "id" => "hr",
                "label" => "HR review",
                "selector" => "role",
                "roles" => ["hr_officer"],
                "permission" => "leave.review",
            ],
            [
                "id" => "final",
                "label" => "Final approval",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "leave.approve",
            ],
        ],
    ],
    [
        "id" => "contract-standard",
        "kind" => "EMPLOYMENT_CONTRACT",
        "name" =>
            "Contract · administration → finance (if needed) → approval → signature → employee",
        "steps" => [
            [
                "id" => "administration",
                "label" => "Administration review",
                "selector" => "role",
                "roles" => ["school_admin"],
                "permission" => "contract.review",
            ],
            [
                "id" => "finance",
                "label" => "Financial terms review",
                "selector" => "role",
                "roles" => ["finance_officer"],
                "permission" => "contract.review",
                "condition" => "financialReview",
            ],
            [
                "id" => "approve",
                "label" => "Management approval",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "contract.approve",
            ],
            [
                "id" => "sign",
                "label" => "Authorised signatory attestation",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "contract.sign",
            ],
            [
                "id" => "employee",
                "label" => "Employee acknowledgement",
                "selector" => "owner",
                "permission" => "contract.view_self",
            ],
        ],
    ],
    [
        "id" => "document-standard",
        "kind" => "CONTROLLED_DOCUMENT",
        "name" => "Document · administration → authorised approval",
        "steps" => [
            [
                "id" => "review",
                "label" => "Document review",
                "selector" => "role",
                "roles" => ["school_admin", "hr_officer", "dpo"],
                "permission" => "document.review",
            ],
            [
                "id" => "approve",
                "label" => "Institutional approval",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "document.approve",
            ],
        ],
    ],
    [
        "id" => "cms-standard",
        "kind" => "CMS_PUBLICATION",
        "name" => "Website · editor → approver → separate publication",
        "steps" => [
            [
                "id" => "editor",
                "label" => "Editorial review",
                "selector" => "role",
                "roles" => ["cms_editor"],
                "permission" => "cms.review",
            ],
            [
                "id" => "approve",
                "label" => "Publication approval",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "cms.approve",
            ],
        ],
    ],
    [
        "id" => "media-standard",
        "kind" => "MEDIA_PUBLICATION",
        "name" => "Child media · privacy review → management approval",
        "steps" => [
            [
                "id" => "privacy",
                "label" => "Consent and safeguarding review",
                "selector" => "role",
                "roles" => ["dpo"],
                "permission" => "media.review",
            ],
            [
                "id" => "approve",
                "label" => "Media publication approval",
                "selector" => "role",
                "roles" => ["head_teacher"],
                "permission" => "cms.approve",
            ],
        ],
    ],
]);
define("a_platform_workflows_RESOURCE_TYPES", [
    "LEAVE_REQUEST" => "leave_requests",
    "EMPLOYMENT_CONTRACT" => "contracts",
    "CONTROLLED_DOCUMENT" => "document_versions",
    "CMS_PUBLICATION" => "cms_content",
    "MEDIA_PUBLICATION" => "media",
]);
function a_platform_workflows_eligible(
    $user = null,
    $instance = null,
    $resource = null,
    $step = null,
) {
    $step ??= g(g($instance, "steps"), g($instance, "stepIndex"));
    if (
        js_truth(
            js_or(
                js_or(
                    js_or(!js_truth($user), function () use (&$user) {
                        return !js_truth(g($user, "active"));
                    }),
                    function () use (&$step) {
                        return !js_truth($step);
                    },
                ),
                function () use (&$user, &$step) {
                    return !js_truth(
                        a_platform_access_has($user, g($step, "permission")),
                    );
                },
            ),
        )
    ) {
        return false;
    }
    if (js_truth(g($step, "selector") === "owner")) {
        return g($user, "id") === g($resource, "ownerId");
    }
    if (
        js_truth(
            js_or(
                js_or(
                    g($user, "id") === g($resource, "createdBy"),
                    function () use (&$user, &$resource) {
                        return jm(
                            js_or(g($resource, "revisionAuthors"), function () {
                                return [];
                            }),
                            "includes",
                            g($user, "id"),
                        );
                    },
                ),
                function () use (&$user, &$instance, &$resource) {
                    return js_and(
                        g($user, "id") === g($resource, "ownerId"),
                        function () use (&$instance) {
                            return g($instance, "kind") === "LEAVE_REQUEST";
                        },
                    );
                },
            ),
        )
    ) {
        return false;
    }
    if (
        js_truth(
            js_and(
                g($instance, "kind") === "CONTROLLED_DOCUMENT",
                function () use (&$user, &$resource) {
                    return !js_truth(
                        a_platform_access_canReadDocument($user, $resource),
                    );
                },
            ),
        )
    ) {
        return false;
    }
    if (js_truth(g($step, "selector") === "supervisor")) {
        return g($resource, "supervisorId") === g($user, "id");
    }
    if (js_truth(g($step, "selector") === "department")) {
        return js_and(
            js_and(g($user, "departmentId"), function () use (
                &$user,
                &$resource,
            ) {
                return g($user, "departmentId") ===
                    g($resource, "departmentId");
            }),
            function () use (&$user, &$step) {
                return jm(
                    js_or(g($step, "roles"), function () {
                        return [];
                    }),
                    "some",
                    function ($role = null) use (&$user) {
                        return jm(g($user, "roles"), "includes", $role);
                    },
                );
            },
        );
    }
    return js_and(g($step, "selector") === "role", function () use (
        &$user,
        &$step,
    ) {
        return jm(
            js_or(g($step, "roles"), function () {
                return [];
            }),
            "some",
            function ($role = null) use (&$user) {
                return jm(g($user, "roles"), "includes", $role);
            },
        );
    });
}
function a_platform_workflows_notify(
    $tx = null,
    $userId = null,
    $title = null,
    $href = null,
    $resourceId = null,
    $key = "",
) {
    $id = js_or($key, function () {
        return rid("NTF-");
    });
    if (
        js_truth(
            js_and($key, function () use (&$tx, &$id) {
                return jm($tx, "get", "notifications", $id);
            }),
        )
    ) {
        return null;
    }
    jm($tx, "insert", "notifications", [
        "id" => $id,
        "userId" => $userId,
        "title" => $title,
        "href" => $href,
        "resourceId" => $resourceId,
        "readAt" => null,
        "createdAt" => jm(new JsDate(), "toISOString"),
    ]);
}
function a_platform_workflows_notifyStep(
    $tx = null,
    $instance = null,
    $resource = null,
) {
    foreach (js_iter(jm($tx, "list", "users")) as $item4090) {
        $user = $item4090;
        if (
            js_truth(a_platform_workflows_eligible($user, $instance, $resource))
        ) {
            a_platform_workflows_notify(
                $tx,
                g($user, "id"),
                "Approval required: " .
                    js_string(
                        js_or(
                            js_or(g($resource, "reference"), function () use (
                                &$resource,
                            ) {
                                return g($resource, "title");
                            }),
                            function () {
                                return "controlled request";
                            },
                        ),
                    ) .
                    "",
                "/portal/approvals",
                g($resource, "id"),
            );
        }
    }
}
function a_platform_workflows_validateDefinition($body = null, $previous = null)
{
    if (
        js_truth(
            js_or(
                js_or(
                    !js_truth(js_array_isArray(g($body, "steps"))),
                    function () use (&$body) {
                        return !js_truth(g(g($body, "steps"), "length"));
                    },
                ),
                function () use (&$body) {
                    return g(g($body, "steps"), "length") > 8;
                },
            ),
        )
    ) {
        throw new HttpError(400, "Use between one and eight workflow steps.");
    }
    $kind = g($previous, "kind");
    $allowed = g(
        [
            "LEAVE_REQUEST" => ["leave.review", "leave.approve"],
            "EMPLOYMENT_CONTRACT" => [
                "contract.review",
                "contract.approve",
                "contract.sign",
                "contract.view_self",
            ],
            "CONTROLLED_DOCUMENT" => ["document.review", "document.approve"],
            "CMS_PUBLICATION" => ["cms.review", "cms.approve"],
            "MEDIA_PUBLICATION" => ["media.review", "cms.approve"],
        ],
        $kind,
    );
    $ids = new JsSet();
    $steps = jm(g($body, "steps"), "map", function ($step = null) use (
        &$kind,
        &$allowed,
        &$ids,
    ) {
        if (
            js_truth(
                js_or(
                    js_or(
                        js_or(
                            !js_truth(
                                jm(
                                    new JsRegex('^[a-z0-9-]{1,40}$', ""),
                                    "test",
                                    js_or(g($step, "id"), function () {
                                        return "";
                                    }),
                                ),
                            ),
                            function () use (&$ids, &$step) {
                                return jm($ids, "has", g($step, "id"));
                            },
                        ),
                        function () use (&$allowed, &$step) {
                            return !js_truth(
                                jm(
                                    $allowed,
                                    "includes",
                                    g($step, "permission"),
                                ),
                            );
                        },
                    ),
                    function () use (&$step) {
                        return !js_truth(
                            jm(
                                ["role", "supervisor", "department", "owner"],
                                "includes",
                                g($step, "selector"),
                            ),
                        );
                    },
                ),
            )
        ) {
            throw new HttpError(400, "Invalid workflow step or permission.");
        }
        jm($ids, "add", g($step, "id"));
        if (
            js_truth(
                js_and(g($step, "selector") === "owner", function () use (
                    &$kind,
                    &$step,
                ) {
                    return !js_truth(
                        js_and(
                            $kind === "EMPLOYMENT_CONTRACT",
                            function () use (&$step) {
                                return g($step, "permission") ===
                                    "contract.view_self";
                            },
                        ),
                    );
                }),
            )
        ) {
            throw new HttpError(
                400,
                "Only contract acknowledgement can be assigned to the requester.",
            );
        }
        if (
            js_truth(
                js_and(g($step, "selector") === "supervisor", function () use (
                    &$kind,
                ) {
                    return $kind !== "LEAVE_REQUEST";
                }),
            )
        ) {
            throw new HttpError(
                400,
                "Supervisor routing is reserved for leave.",
            );
        }
        $roles = js_truth(
            jm(["role", "department"], "includes", g($step, "selector")),
        )
            ? a_platform_access_validRoles(g($step, "roles"))
            : [];
        if (
            js_truth(
                jm($roles, "some", function ($role = null) use (&$step) {
                    return !js_truth(
                        jm(
                            g(a_platform_access_ROLE_PERMISSIONS, $role),
                            "includes",
                            g($step, "permission"),
                        ),
                    );
                }),
            )
        ) {
            throw new HttpError(
                400,
                "Each selected role must have the step permission.",
            );
        }
        if (
            js_truth(
                js_and(g($step, "condition"), function () use (&$kind, &$step) {
                    return !js_truth(
                        js_and(
                            js_and(
                                $kind === "EMPLOYMENT_CONTRACT",
                                function () use (&$step) {
                                    return g($step, "condition") ===
                                        "financialReview";
                                },
                            ),
                            function () use (&$step) {
                                return g($step, "permission") ===
                                    "contract.review";
                            },
                        ),
                    );
                }),
            )
        ) {
            throw new HttpError(400, "Unsupported workflow condition.");
        }
        return [
            "id" => g($step, "id"),
            "label" => text(g($step, "label"), 100, true),
            "selector" => g($step, "selector"),
            "permission" => g($step, "permission"),
            "roles" => $roles,
            ...js_array(
                js_truth(g($step, "condition"))
                    ? ["condition" => g($step, "condition")]
                    : [],
            ),
        ];
    });
    $last = jm($steps, "at", -1);
    $requiredFinal = g(
        [
            "LEAVE_REQUEST" => "leave.approve",
            "CONTROLLED_DOCUMENT" => "document.approve",
            "CMS_PUBLICATION" => "cms.approve",
            "MEDIA_PUBLICATION" => "cms.approve",
            "EMPLOYMENT_CONTRACT" => "contract.view_self",
        ],
        $kind,
    );
    if (
        js_truth(
            js_or(g($last, "permission") !== $requiredFinal, function () use (
                &$last,
            ) {
                return g($last, "condition");
            }),
        )
    ) {
        throw new HttpError(
            400,
            "The final required authority cannot be removed or made optional.",
        );
    }
    if (
        js_truth(
            js_and($kind === "EMPLOYMENT_CONTRACT", function () use (
                &$steps,
                &$last,
            ) {
                return js_or(
                    js_or(g($last, "selector") !== "owner", function () use (
                        &$steps,
                    ) {
                        return !js_truth(
                            jm($steps, "some", function ($step = null) {
                                return g($step, "permission") ===
                                    "contract.approve";
                            }),
                        );
                    }),
                    function () use (&$steps) {
                        return !js_truth(
                            jm($steps, "some", function ($step = null) {
                                return g($step, "permission") ===
                                    "contract.sign";
                            }),
                        );
                    },
                );
            }),
        )
    ) {
        throw new HttpError(
            400,
            "Contracts require management approval, signatory attestation and employee acknowledgement.",
        );
    }
    if (
        js_truth(
            js_and($kind === "EMPLOYMENT_CONTRACT", function () use (&$steps) {
                return js_or(
                    jm($steps, "findIndex", function ($step = null) {
                        return g($step, "permission") === "contract.sign";
                    }) <
                        jm($steps, "findIndex", function ($step = null) {
                            return g($step, "permission") ===
                                "contract.approve";
                        }),
                    function () use (&$steps) {
                        return g(
                            jm($steps, "filter", function ($step = null) {
                                return g($step, "selector") === "owner";
                            }),
                            "length",
                        ) !== 1;
                    },
                );
            }),
        )
    ) {
        throw new HttpError(
            400,
            "Contract approval must precede signatory attestation and one final employee acknowledgement.",
        );
    }
    if (
        js_truth(
            js_and($kind === "MEDIA_PUBLICATION", function () use (&$steps) {
                return !js_truth(
                    jm($steps, "some", function ($step = null) {
                        return g($step, "permission") === "media.review";
                    }),
                );
            }),
        )
    ) {
        throw new HttpError(400, "Media must pass privacy review.");
    }
    if (
        js_truth(
            js_and($kind === "CMS_PUBLICATION", function () use (&$steps) {
                return !js_truth(
                    jm($steps, "some", function ($step = null) {
                        return g($step, "permission") === "cms.review";
                    }),
                );
            }),
        )
    ) {
        throw new HttpError(
            400,
            "CMS content requires an editorial review before approval.",
        );
    }
    return [
        ...js_array($previous),
        "name" => text(g($body, "name"), 150, true),
        "steps" => $steps,
        "version" => js_add(g($previous, "version"), 1),
    ];
}
function a_platform_workflows_startWorkflow(
    $tx = null,
    $actor = null,
    $config = null,
    $definitionId = null,
    $resource = null,
) {
    $definition = jm($tx, "get", "workflow_definitions", $definitionId);
    if (
        js_truth(
            js_or(!js_truth($definition), function () use (&$definition) {
                return !js_truth(
                    g(
                        a_platform_workflows_RESOURCE_TYPES,
                        g($definition, "kind"),
                    ),
                );
            }),
        )
    ) {
        throw new HttpError(400, "Workflow is not configured.");
    }
    $steps = jm(g($definition, "steps"), "filter", function ($step = null) use (
        &$resource,
    ) {
        return js_or(!js_truth(g($step, "condition")), function () use (
            &$resource,
            &$step,
        ) {
            return !js_truth(!js_truth(g($resource, g($step, "condition"))));
        });
    });
    $instance = [
        "id" => rid("WF-"),
        "definitionId" => $definitionId,
        "definitionVersion" => g($definition, "version"),
        "kind" => g($definition, "kind"),
        "collection" => g(
            a_platform_workflows_RESOURCE_TYPES,
            g($definition, "kind"),
        ),
        "resourceId" => g($resource, "id"),
        "requesterId" => g($resource, "createdBy"),
        "steps" => $steps,
        "stepIndex" => 0,
        "status" => "PENDING",
        "decisions" => [],
        "createdAt" => jm(new JsDate(), "toISOString"),
    ];
    $users = jm($tx, "list", "users");
    foreach (js_iter($steps) as $item8454) {
        $step = $item8454;
        if (
            js_truth(
                !js_truth(
                    jm($users, "some", function ($user = null) use (
                        &$resource,
                        &$instance,
                        &$step,
                    ) {
                        return a_platform_workflows_eligible(
                            $user,
                            $instance,
                            $resource,
                            $step,
                        );
                    }),
                ),
            )
        ) {
            throw new HttpError(
                409,
                "No independent authorised approver is configured for “" .
                    js_string(g($step, "label")) .
                    "”. Ask management to configure the route and staff profile.",
            );
        }
    }
    $saved = jm($tx, "insert", "workflow_instances", $instance);
    audit(
        $tx,
        $config,
        $actor,
        "workflow.submit",
        g($instance, "collection"),
        g($resource, "id"),
        "success",
        [
            "workflowId" => g($saved, "id"),
            "definitionVersion" => g($definition, "version"),
        ],
    );
    a_platform_workflows_notifyStep($tx, $saved, $resource);
    return $saved;
}
function a_platform_workflows_decide(
    $tx = null,
    $actor = null,
    $config = null,
    $instance = null,
    $resource = null,
    $body = null,
) {
    if (
        js_truth(
            js_or(g($instance, "status") !== "PENDING", function () use (
                &$instance,
                &$body,
            ) {
                return js_Number(g($body, "revision")) !==
                    g($instance, "revision");
            }),
        )
    ) {
        throw new HttpError(
            409,
            "The approval moved to another stage. Refresh before deciding.",
            "VERSION_CONFLICT",
        );
    }
    if (
        js_truth(
            !js_truth(
                a_platform_workflows_eligible(
                    g($actor, "user"),
                    $instance,
                    $resource,
                ),
            ),
        )
    ) {
        throw new HttpError(
            403,
            "You are not assigned to this approval stage.",
        );
    }
    if (
        js_truth(
            !js_truth(
                jm(
                    ["APPROVE", "REJECT", "RETURN", "COMMENT", "ESCALATE"],
                    "includes",
                    g($body, "decision"),
                ),
            ),
        )
    ) {
        throw new HttpError(400, "Select a valid decision.");
    }
    $step = g(g($instance, "steps"), g($instance, "stepIndex"));
    $comment = text(
        g($body, "comment"),
        2000,
        js_or(
            js_or(
                jm(
                    ["REJECT", "RETURN", "ESCALATE"],
                    "includes",
                    g($body, "decision"),
                ),
                function () use (&$step) {
                    return g($step, "permission") === "contract.sign";
                },
            ),
            function () use (&$step) {
                return g($step, "selector") === "owner";
            },
        ),
    );
    js_push($instance["decisions"], [
        "stepId" => g($step, "id"),
        "actorId" => g(g($actor, "user"), "id"),
        "decision" => g($body, "decision"),
        "comment" => $comment,
        "at" => jm(new JsDate(), "toISOString"),
        "session" => js_or(
            jm(g(g($actor, "session"), "id"), "slice", 0, 12),
            function () {
                return "";
            },
        ),
    ]);
    if (js_truth(g($body, "decision") === "APPROVE")) {
        $instance["stepIndex"]++;
        if (
            js_truth(
                g($instance, "stepIndex") >= g(g($instance, "steps"), "length"),
            )
        ) {
            $instance["status"] = "APPROVED";
        }
    } else {
        if (js_truth(g($body, "decision") === "REJECT")) {
            $instance["status"] = "REJECTED";
        } else {
            if (js_truth(g($body, "decision") === "RETURN")) {
                $instance["status"] = "RETURNED";
            }
        }
    }
    $updated = jm($tx, "update", "workflow_instances", $instance);
    audit(
        $tx,
        $config,
        $actor,
        js_add("workflow.", jm(g($body, "decision"), "toLowerCase")),
        g($instance, "collection"),
        g($resource, "id"),
        "success",
        ["workflowId" => g($instance, "id"), "stepId" => g($step, "id")],
    );
    if (
        js_truth(
            jm(
                ["REJECTED", "RETURNED", "APPROVED"],
                "includes",
                g($updated, "status"),
            ),
        )
    ) {
        a_platform_workflows_notify(
            $tx,
            g($resource, "createdBy"),
            "" .
                js_string(
                    js_or(g($resource, "reference"), function () use (
                        &$resource,
                    ) {
                        return g($resource, "title");
                    }),
                ) .
                ": " .
                js_string(jm(g($updated, "status"), "toLowerCase")) .
                "",
            "/portal/requests",
            g($resource, "id"),
        );
        if (
            js_truth(
                js_and(g($resource, "ownerId"), function () use (&$resource) {
                    return g($resource, "ownerId") !==
                        g($resource, "createdBy");
                }),
            )
        ) {
            a_platform_workflows_notify(
                $tx,
                g($resource, "ownerId"),
                "" .
                    js_string(
                        js_or(g($resource, "reference"), function () {
                            return "Your request";
                        }),
                    ) .
                    ": " .
                    js_string(jm(g($updated, "status"), "toLowerCase")) .
                    "",
                "/portal/requests",
                g($resource, "id"),
            );
        }
    } else {
        if (js_truth(g($body, "decision") === "APPROVE")) {
            a_platform_workflows_notifyStep($tx, $updated, $resource);
        }
    }
    if (js_truth(g($updated, "status") === "APPROVED")) {
        $publishPermission = g(
            [
                "CMS_PUBLICATION" => "cms.publish",
                "CONTROLLED_DOCUMENT" => "document.publish",
                "MEDIA_PUBLICATION" => "media.publish",
            ],
            g($updated, "kind"),
        );
        if (js_truth($publishPermission)) {
            foreach (js_iter(jm($tx, "list", "users")) as $item11196) {
                $user = $item11196;
                if (
                    js_truth(
                        js_and(
                            a_platform_access_has($user, $publishPermission),
                            function () use (&$resource, &$user) {
                                return g($user, "id") !==
                                    g($resource, "createdBy");
                            },
                        ),
                    )
                ) {
                    a_platform_workflows_notify(
                        $tx,
                        g($user, "id"),
                        "Approved content is ready for publication: " .
                            js_string(
                                js_or(
                                    g($resource, "reference"),
                                    function () use (&$resource) {
                                        return g($resource, "title");
                                    },
                                ),
                            ) .
                            "",
                        js_truth(g($updated, "kind") === "CMS_PUBLICATION")
                            ? "/portal/cms"
                            : (js_truth(
                                g($updated, "kind") === "MEDIA_PUBLICATION",
                            )
                                ? "/portal/media"
                                : "/portal/documents"),
                        g($resource, "id"),
                    );
                }
            }
        }
    }
    if (js_truth(g($body, "decision") === "ESCALATE")) {
        foreach (js_iter(jm($tx, "list", "users")) as $item11612) {
            $user = $item11612;
            if (js_truth(a_platform_access_has($user, "workflow.configure"))) {
                a_platform_workflows_notify(
                    $tx,
                    g($user, "id"),
                    "Escalation: " .
                        js_string(
                            js_or(g($resource, "reference"), function () use (
                                &$resource,
                            ) {
                                return g($resource, "title");
                            }),
                        ) .
                        "",
                    "/portal/approvals",
                    g($resource, "id"),
                );
            }
        }
    }
    return $updated;
}
