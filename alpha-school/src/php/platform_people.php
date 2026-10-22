<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_platform_people_PENDING_LEAVE", ["SUBMITTED", "UNDER_REVIEW"]);
function a_platform_people_businessDays(
    $start = null,
    $end = null,
    $excluded = null,
) {
    $first = new JsDate(js_add($start, "T00:00:00Z"));
    $last = new JsDate(js_add($end, "T00:00:00Z"));
    $days = 0;
    for (
        $time = jm($first, "getTime");
        $time < jm($last, "getTime");
        $time += 86400000
    ) {
        if (
            js_truth(
                !js_truth(
                    jm(
                        $excluded,
                        "includes",
                        jm(new JsDate($time), "getUTCDay"),
                    ),
                ),
            )
        ) {
            $days++;
        }
    }
    return $days;
}
function a_platform_people_leaveFields($tx = null, $user = null, $body = null)
{
    $profile = jm($tx, "get", "staff_profiles", g($user, "id"));
    if (js_truth(!js_truth($profile))) {
        throw new HttpError(
            409,
            "HR must complete your staff profile before you apply for leave.",
        );
    }
    $type = jm($tx, "get", "leave_types", g($body, "typeId"));
    if (js_truth(!js_truth(g($type, "enabled")))) {
        throw new HttpError(400, "Select an enabled leave type.");
    }
    $startDate = a_platform_validation_date(g($body, "startDate"));
    $returnDate = a_platform_validation_date(g($body, "returnDate"));
    if (
        js_truth(
            js_or(
                js_or($returnDate <= $startDate, function () use (
                    &$startDate,
                    &$returnDate,
                ) {
                    return js_date_parse($returnDate) -
                        js_date_parse($startDate) >
                        366 * 86400000;
                }),
                function () use (&$startDate, &$returnDate) {
                    return jm($startDate, "slice", 0, 4) !==
                        jm(
                            jm(
                                new JsDate(
                                    js_date_parse($returnDate) - 86400000,
                                ),
                                "toISOString",
                            ),
                            "slice",
                            0,
                            4,
                        );
                },
            ),
        )
    ) {
        throw new HttpError(
            400,
            "The return date must follow the start date within the same leave year. Split requests across years.",
        );
    }
    $days = a_platform_people_businessDays(
        $startDate,
        $returnDate,
        g($type, "nonWorkingDays"),
    );
    if (js_truth(!js_truth($days))) {
        throw new HttpError(400, "The selected dates contain no working days.");
    }
    return [
        "ownerId" => g($user, "id"),
        "staffId" => g($profile, "staffId"),
        "departmentId" => g($profile, "departmentId"),
        "supervisorId" => g($profile, "supervisorId"),
        "typeId" => g($type, "id"),
        "startDate" => $startDate,
        "returnDate" => $returnDate,
        "days" => $days,
        "reason" => text(g($body, "reason"), 2000, true),
        "handover" => text(g($body, "handover"), 120, true),
        "emergencyContact" => text(g($body, "emergencyContact"), 100, true),
    ];
}
function a_platform_people_submitLeave(
    $tx = null,
    $actor = null,
    $config = null,
    $record = null,
) {
    if (
        js_truth(
            !js_truth(
                jm(["DRAFT", "RETURNED"], "includes", g($record, "status")),
            ),
        )
    ) {
        throw new HttpError(
            409,
            "Only a draft or returned request can be submitted.",
        );
    }
    if (
        js_truth(
            g($record, "startDate") <
                jm(jm(new JsDate(), "toISOString"), "slice", 0, 10),
        )
    ) {
        throw new HttpError(400, "Leave must start today or later.");
    }
    $type = jm($tx, "get", "leave_types", g($record, "typeId"));
    $profile = jm($tx, "get", "staff_profiles", g(g($actor, "user"), "id"));
    $record["supervisorId"] = g($profile, "supervisorId");
    $record["departmentId"] = g($profile, "departmentId");
    if (
        js_truth(
            jm(jm($tx, "list", "leave_requests"), "some", function (
                $item = null,
            ) use (&$record) {
                return js_and(
                    js_and(
                        js_and(
                            js_and(
                                g($item, "id") !== g($record, "id"),
                                function () use (&$record, &$item) {
                                    return g($item, "ownerId") ===
                                        g($record, "ownerId");
                                },
                            ),
                            function () use (&$item) {
                                return jm(
                                    [
                                        ...js_array(
                                            a_platform_people_PENDING_LEAVE,
                                        ),
                                        "APPROVED",
                                    ],
                                    "includes",
                                    g($item, "status"),
                                );
                            },
                        ),
                        function () use (&$record, &$item) {
                            return g($record, "startDate") <
                                g($item, "returnDate");
                        },
                    ),
                    function () use (&$record, &$item) {
                        return g($record, "returnDate") > g($item, "startDate");
                    },
                );
            }),
        )
    ) {
        throw new HttpError(
            409,
            "These dates overlap an existing leave request.",
        );
    }
    $record["balanceId"] = null;
    if (js_truth(g($type, "deductsBalance"))) {
        $id =
            "" .
            js_string(g($record, "ownerId")) .
            ":" .
            js_string(jm(g($record, "startDate"), "slice", 0, 4)) .
            ":" .
            js_string(g($record, "typeId")) .
            "";
        $balance = jm($tx, "get", "leave_balances", $id);
        if (
            js_truth(
                js_or(!js_truth($balance), function () use (
                    &$record,
                    &$balance,
                ) {
                    return g($balance, "entitledDays") -
                        g($balance, "usedDays") -
                        g($balance, "reservedDays") <
                        g($record, "days");
                }),
            )
        ) {
            throw new HttpError(
                409,
                "Insufficient available leave. Ask HR to confirm your entitlement. Pending requests reserve their days.",
            );
        }
        jm($tx, "update", "leave_balances", [
            ...js_array($balance),
            "reservedDays" => js_add(
                g($balance, "reservedDays"),
                g($record, "days"),
            ),
        ]);
        $record["balanceId"] = $id;
    }
    $flow = a_platform_workflows_startWorkflow(
        $tx,
        $actor,
        $config,
        g($type, "workflowId"),
        $record,
    );
    $record["workflowIds"] = [
        ...js_array(
            js_or(g($record, "workflowIds"), function () {
                return [];
            }),
        ),
        g($flow, "id"),
    ];
    $record["workflowId"] = g($flow, "id");
    $record["status"] = "SUBMITTED";
    $record["submittedAt"] = jm(new JsDate(), "toISOString");
    return jm($tx, "update", "leave_requests", $record);
}
trait a_platform_people_Methods
{
    public function users($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            if (
                js_truth(
                    js_and(
                        js_and(
                            !js_truth(
                                a_platform_access_has(
                                    g($actor, "user"),
                                    "user.create",
                                ),
                            ),
                            function () use (&$actor) {
                                return !js_truth(
                                    a_platform_access_has(
                                        g($actor, "user"),
                                        "role.grant",
                                    ),
                                );
                            },
                        ),
                        function () use (&$actor) {
                            return !js_truth(
                                a_platform_access_has(
                                    g($actor, "user"),
                                    "staff.manage",
                                ),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(403, "Account directory is restricted.");
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "user.list",
                "user",
                "directory",
            );
            return jm(jm($tx, "list", "users"), "map", "safe_user");
        });
    }
    public function createUser($actor = null, $body = null)
    {
        $user = create_user(
            jm(text(g($body, "username"), 64, true), "toLowerCase"),
            g($body, "name"),
            js_or(g($body, "roles"), function () {
                return ["supporting_staff"];
            }),
            g($body, "password"),
        );
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$user,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "user.create",
            );
            if (
                js_truth(
                    js_and(
                        !js_truth(
                            a_platform_access_has(
                                g($actor, "user"),
                                "role.grant",
                            ),
                        ),
                        function () use (&$user) {
                            return jm(g($user, "roles"), "some", function (
                                $role = null,
                            ) {
                                return $role !== "supporting_staff";
                            });
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "Management must grant business roles after account creation.",
                );
            }
            if (
                js_truth(
                    jm(g($user, "roles"), "some", function ($role = null) {
                        return jm(
                            ["system_admin", "ict_officer"],
                            "includes",
                            $role,
                        );
                    }),
                )
            ) {
                throw new HttpError(
                    403,
                    "Technical roles require the operator provisioning procedure.",
                );
            }
            if (
                js_truth(
                    jm(jm($tx, "list", "users"), "some", function (
                        $item = null,
                    ) use (&$user) {
                        return g($item, "username") === g($user, "username");
                    }),
                )
            ) {
                throw new HttpError(409, "Username is already in use.");
            }
            $saved = jm($tx, "insert", "users", $user);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "user.create",
                "user",
                g($saved, "id"),
                "success",
                ["roles" => g($saved, "roles")],
            );
            return safe_user($saved);
        });
    }
    public function changeUser($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                js_truth(g($body, "roles")) ? "role.grant" : "user.manage",
            );
            if (js_truth($id === g(g($actor, "user"), "id"))) {
                throw new HttpError(
                    403,
                    "You cannot change your own account authority.",
                );
            }
            $user = jm($tx, "get", "users", $id);
            if (js_truth(!js_truth($user))) {
                throw new HttpError(404, "Account not found.");
            }
            a_platform_validation_revision($user, $body);
            if (js_truth(g($user, "id") === g(g($actor, "user"), "id"))) {
                throw new HttpError(
                    403,
                    "You cannot change your own account authority.",
                );
            }
            if (js_truth(g($body, "roles"))) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "role.grant",
                );
                if (
                    js_truth(
                        jm(g($user, "roles"), "some", function ($role = null) {
                            return jm(
                                ["system_admin", "ict_officer"],
                                "includes",
                                $role,
                            );
                        }),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "Business roles cannot be added to technical accounts.",
                    );
                }
                $roles = a_platform_access_validRoles(g($body, "roles"));
                if (
                    js_truth(
                        jm($roles, "some", function ($role = null) {
                            return jm(
                                ["system_admin", "ict_officer"],
                                "includes",
                                $role,
                            );
                        }),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "Technical roles require operator provisioning.",
                    );
                }
                $user["roles"] = $roles;
                $user["auditScopes"] = js_truth(
                    jm($roles, "includes", "auditor"),
                )
                    ? a_platform_validation_strings(g($body, "auditScopes"), 30)
                    : [];
                $scopes = [
                    "user",
                    "staff_profile",
                    "leave_request",
                    "leave_balance",
                    "contract",
                    "document",
                    "document_version",
                    "workflow_definition",
                    "workflow_instance",
                    "notice",
                    "cms_content",
                    "admissions",
                    "submissions",
                    "calendar_event",
                    "media",
                    "media_consent",
                    "privacy_request",
                    "incident",
                    "api",
                    "outbox",
                    "system",
                    "audit_log",
                ];
                if (
                    js_truth(
                        jm(g($user, "auditScopes"), "some", function (
                            $scope = null,
                        ) use (&$scopes) {
                            return !js_truth(jm($scopes, "includes", $scope));
                        }),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Select recognised auditor resource scopes.",
                    );
                }
            } else {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "user.manage",
                );
                if (js_truth(js_typeof(g($body, "active")) !== "boolean")) {
                    throw new HttpError(400, "Specify the account status.");
                }
                $user["active"] = g($body, "active");
            }
            $others = jm(jm($tx, "list", "users"), "filter", function (
                $item = null,
            ) use (&$user) {
                return js_and(
                    g($item, "id") !== g($user, "id"),
                    function () use (&$item) {
                        return g($item, "active");
                    },
                );
            });
            foreach (js_iter(["head_teacher", "system_admin"]) as $item6959) {
                $essential = $item6959;
                $old = jm($tx, "get", "users", $id);
                if (
                    js_truth(
                        js_and(
                            js_and(
                                jm(g($old, "roles"), "includes", $essential),
                                function () use (&$user, &$essential) {
                                    return js_or(
                                        !js_truth(g($user, "active")),
                                        function () use (&$user, &$essential) {
                                            return !js_truth(
                                                jm(
                                                    g($user, "roles"),
                                                    "includes",
                                                    $essential,
                                                ),
                                            );
                                        },
                                    );
                                },
                            ),
                            function () use (&$others, &$essential) {
                                return !js_truth(
                                    jm($others, "some", function (
                                        $item = null,
                                    ) use (&$essential) {
                                        return jm(
                                            g($item, "roles"),
                                            "includes",
                                            $essential,
                                        );
                                    }),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "The last institutional or technical authority cannot be removed.",
                    );
                }
            }
            $user["authVersion"]++;
            $saved = jm($tx, "update", "users", $user);
            foreach (js_iter(jm($tx, "list", "sessions")) as $item7404) {
                $session = $item7404;
                if (js_truth(g($session, "userId") === g($user, "id"))) {
                    jm($tx, "remove", "sessions", g($session, "id"));
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth(g($body, "roles"))
                    ? "user.role_assignment"
                    : "user.status_change",
                "user",
                $id,
                "success",
                [
                    "roles" => g($saved, "roles"),
                    "active" => g($saved, "active"),
                ],
            );
            return safe_user($saved);
        });
    }
    public function staff($actor = null, $directory = false)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$directory,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "staff.view_self",
            );
            $profiles = jm($tx, "list", "staff_profiles");
            if (js_truth($directory)) {
                return jm($profiles, "map", function ($arg0) {
                    $id = g($arg0, "id");
                    $name = g($arg0, "name");
                    $departmentId = g($arg0, "departmentId");
                    $position = g($arg0, "position");
                    return [
                        "id" => $id,
                        "name" => $name,
                        "departmentId" => $departmentId,
                        "position" => $position,
                    ];
                });
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "staff.read",
                "staff_profile",
                "authorised",
            );
            return jm($profiles, "filter", function ($profile = null) use (
                &$actor,
            ) {
                return a_platform_access_canReadStaff(
                    g($actor, "user"),
                    $profile,
                );
            });
        });
    }
    public function saveStaff($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "staff.manage",
            );
            $user = jm($tx, "get", "users", $id);
            if (
                js_truth(
                    js_or(!js_truth($user), function () use (&$user) {
                        return !js_truth(a_platform_access_isStaff($user));
                    }),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose an active staff account, not a technical-only or family account.",
                );
            }
            if (
                js_truth(
                    !js_truth(
                        jm($tx, "get", "departments", g($body, "departmentId")),
                    ),
                )
            ) {
                throw new HttpError(400, "Select a department.");
            }
            $supervisorId = text(g($body, "supervisorId"), 100);
            if (js_truth($supervisorId)) {
                $supervisor = jm($tx, "get", "users", $supervisorId);
                if (
                    js_truth(
                        js_or(
                            js_or($supervisorId === $id, function () use (
                                &$supervisor,
                            ) {
                                return !js_truth(g($supervisor, "active"));
                            }),
                            function () use (&$supervisor) {
                                return !js_truth(
                                    a_platform_access_has(
                                        $supervisor,
                                        "leave.review",
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Choose a different authorised supervisor.",
                    );
                }
            }
            $previous = jm($tx, "get", "staff_profiles", $id);
            if (js_truth($previous)) {
                a_platform_validation_revision($previous, $body);
            }
            $staffId = text(g($body, "staffId"), 40, true);
            if (
                js_truth(
                    jm(jm($tx, "list", "staff_profiles"), "some", function (
                        $profile = null,
                    ) use (&$id, &$staffId) {
                        return js_and(
                            g($profile, "id") !== $id,
                            function () use (&$staffId, &$profile) {
                                return g($profile, "staffId") === $staffId;
                            },
                        );
                    }),
                )
            ) {
                throw new HttpError(409, "Staff ID is already in use.");
            }
            $profile = [
                ...js_array(
                    js_or($previous, function () {
                        return [];
                    }),
                ),
                "id" => $id,
                "staffId" => $staffId,
                "name" => g($user, "name"),
                "position" => text(g($body, "position"), 120, true),
                "departmentId" => g($body, "departmentId"),
                "employmentType" => a_platform_validation_choose(
                    g($body, "employmentType"),
                    ["Permanent", "Contract", "Temporary"],
                ),
                "employmentDate" => a_platform_validation_date(
                    g($body, "employmentDate"),
                ),
                "supervisorId" => $supervisorId,
                "email" => text(g($body, "email"), 160),
                "phone" => text(g($body, "phone"), 30),
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ];
            $result = js_truth($previous)
                ? jm($tx, "update", "staff_profiles", $profile)
                : jm($tx, "insert", "staff_profiles", $profile);
            jm($tx, "update", "users", [
                ...js_array($user),
                "departmentId" => g($profile, "departmentId"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "staff.update",
                "staff_profile",
                $id,
            );
            return $result;
        });
    }
    public function balances($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "leave.view_self",
            );
            return jm(jm($tx, "list", "leave_balances"), "filter", function (
                $balance = null,
            ) use (&$actor) {
                return js_or(
                    g($balance, "userId") === g(g($actor, "user"), "id"),
                    function () use (&$actor) {
                        return a_platform_access_has(
                            g($actor, "user"),
                            "staff.manage",
                        );
                    },
                );
            });
        });
    }
    public function setBalance($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "staff.manage",
            );
            if (
                js_truth(
                    js_or(
                        !js_truth(
                            jm(
                                $tx,
                                "get",
                                "staff_profiles",
                                g($body, "userId"),
                            ),
                        ),
                        function () use (&$body, &$tx) {
                            return !js_truth(
                                jm(
                                    $tx,
                                    "get",
                                    "leave_types",
                                    g($body, "typeId"),
                                ),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose a staff member and leave type.",
                );
            }
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(
                                js_or(
                                    js_or(
                                        !js_truth(
                                            js_number_isInteger(
                                                g($body, "year"),
                                            ),
                                        ),
                                        function () use (&$body) {
                                            return g($body, "year") < 2020;
                                        },
                                    ),
                                    function () use (&$body) {
                                        return g($body, "year") > 2100;
                                    },
                                ),
                                function () use (&$body) {
                                    return !js_truth(
                                        js_number_isFinite(
                                            g($body, "entitledDays"),
                                        ),
                                    );
                                },
                            ),
                            function () use (&$body) {
                                return g($body, "entitledDays") < 0;
                            },
                        ),
                        function () use (&$body) {
                            return g($body, "entitledDays") > 366;
                        },
                    ),
                )
            ) {
                throw new HttpError(400, "Enter a valid year and entitlement.");
            }
            $id =
                "" .
                js_string(g($body, "userId")) .
                ":" .
                js_string(g($body, "year")) .
                ":" .
                js_string(g($body, "typeId")) .
                "";
            $old = jm($tx, "get", "leave_balances", $id);
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
            }
            if (
                js_truth(
                    js_and($old, function () use (&$body, &$old) {
                        return g($body, "entitledDays") <
                            js_add(
                                g($old, "usedDays"),
                                g($old, "reservedDays"),
                            );
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "Entitlement cannot be less than used and reserved days.",
                );
            }
            $record = [
                ...js_array(
                    js_or($old, function () {
                        return ["usedDays" => 0, "reservedDays" => 0];
                    }),
                ),
                "id" => $id,
                "userId" => g($body, "userId"),
                "year" => g($body, "year"),
                "typeId" => g($body, "typeId"),
                "entitledDays" => g($body, "entitledDays"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "leave_balances", $record)
                : jm($tx, "insert", "leave_balances", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "leave.entitlement",
                "leave_balance",
                $id,
                "success",
                ["entitledDays" => g($body, "entitledDays")],
            );
            return $saved;
        });
    }
    public function configureLeaveType($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "workflow.configure",
            );
            $old = jm($tx, "get", "leave_types", $id);
            if (js_truth(!js_truth($old))) {
                throw new HttpError(404, "Leave type not found.");
            }
            a_platform_validation_revision($old, $body);
            $definition = jm(
                $tx,
                "get",
                "workflow_definitions",
                g($body, "workflowId"),
            );
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(
                                js_or(
                                    g($definition, "kind") !== "LEAVE_REQUEST",
                                    function () use (&$body) {
                                        return js_typeof(
                                            g($body, "deductsBalance"),
                                        ) !== "boolean";
                                    },
                                ),
                                function () use (&$body) {
                                    return !js_truth(
                                        js_array_isArray(
                                            g($body, "nonWorkingDays"),
                                        ),
                                    );
                                },
                            ),
                            function () use (&$body) {
                                return g(g($body, "nonWorkingDays"), "length") >
                                    6;
                            },
                        ),
                        function () use (&$body) {
                            return jm(
                                g($body, "nonWorkingDays"),
                                "some",
                                function ($day = null) {
                                    return js_or(
                                        js_or(
                                            !js_truth(
                                                js_number_isInteger($day),
                                            ),
                                            function () use (&$day) {
                                                return $day < 0;
                                            },
                                        ),
                                        function () use (&$day) {
                                            return $day > 6;
                                        },
                                    );
                                },
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose a leave route, balance rule and nonworking weekdays (0–6).",
                );
            }
            $saved = jm($tx, "update", "leave_types", [
                ...js_array($old),
                "workflowId" => g($definition, "id"),
                "deductsBalance" => g($body, "deductsBalance"),
                "nonWorkingDays" => [
                    ...js_array(new JsSet(g($body, "nonWorkingDays"))),
                ],
                "enabled" => g($body, "enabled") !== false,
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "leave.type_configure",
                "leave_type",
                $id,
            );
            return $saved;
        });
    }
    public function leaves($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "leave.view_self",
            );
            $records = jm(
                jm(jm($tx, "list", "leave_requests"), "filter", function (
                    $record = null,
                ) use (&$actor) {
                    return a_platform_access_canReadLeave(
                        g($actor, "user"),
                        $record,
                    );
                }),
                "map",
                "a_platform_validation_safeRecord",
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "leave.read",
                "leave_request",
                "authorised",
            );
            return $records;
        });
    }
    public function createLeave($actor = null, $body = null)
    {
        a_platform_access_requirePermission(g($actor, "user"), "leave.create");
        $file = js_truth(g($body, "file"))
            ? jm(g($this, "files"), "prepare", g($body, "file"))
            : null;
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$body,
                &$file,
            ) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "leave.create",
                );
                $fields = a_platform_people_leaveFields(
                    $tx,
                    g($actor, "user"),
                    $body,
                );
                $id = rid("LEV-");
                $saved = jm($tx, "insert", "leave_requests", [
                    "id" => $id,
                    "reference" =>
                        "LEV-" .
                        js_string(jm(new JsDate(), "getFullYear")) .
                        "-" .
                        js_string(jm(jm($id, "slice", -8), "toUpperCase")) .
                        "",
                    ...js_array($fields),
                    "file" => $file,
                    "createdBy" => g(g($actor, "user"), "id"),
                    "createdAt" => jm(new JsDate(), "toISOString"),
                    "status" => "DRAFT",
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "leave.create",
                    "leave_request",
                    $id,
                );
                if (js_truth(g($body, "submit"))) {
                    $saved = a_platform_people_submitLeave(
                        $tx,
                        $actor,
                        g($this, "config"),
                        $saved,
                    );
                }
                return a_platform_validation_safeRecord($saved);
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function editLeave($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "leave.create",
            );
            $record = jm($tx, "get", "leave_requests", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "ownerId") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(403, "This leave request is not yours.");
            }
            a_platform_validation_revision($record, $body);
            if (
                js_truth(
                    !js_truth(
                        jm(
                            ["DRAFT", "RETURNED"],
                            "includes",
                            g($record, "status"),
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "Only draft or returned leave can be edited.",
                );
            }
            $saved = jm($tx, "update", "leave_requests", [
                ...js_array($record),
                ...js_array(
                    a_platform_people_leaveFields(
                        $tx,
                        g($actor, "user"),
                        $body,
                    ),
                ),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "leave.edit",
                "leave_request",
                $id,
            );
            return a_platform_validation_safeRecord($saved);
        });
    }
    public function leaveAction($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "leave.create",
            );
            $record = jm($tx, "get", "leave_requests", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "ownerId") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(403, "This leave request is not yours.");
            }
            a_platform_validation_revision($record, $body);
            if (js_truth(g($body, "action") === "SUBMIT")) {
                return a_platform_validation_safeRecord(
                    a_platform_people_submitLeave(
                        $tx,
                        $actor,
                        g($this, "config"),
                        $record,
                    ),
                );
            }
            if (
                js_truth(
                    js_or(g($body, "action") !== "CANCEL", function () use (
                        &$record,
                    ) {
                        return !js_truth(
                            jm(
                                [
                                    "DRAFT",
                                    "RETURNED",
                                    ...js_array(
                                        a_platform_people_PENDING_LEAVE,
                                    ),
                                    "APPROVED",
                                ],
                                "includes",
                                g($record, "status"),
                            ),
                        );
                    }),
                )
            ) {
                throw new HttpError(409, "This request cannot be cancelled.");
            }
            if (
                js_truth(
                    js_and(
                        g($record, "status") === "APPROVED",
                        function () use (&$record) {
                            return g($record, "startDate") <=
                                jm(
                                    jm(new JsDate(), "toISOString"),
                                    "slice",
                                    0,
                                    10,
                                );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "Ask HR about leave that has already started.",
                );
            }
            if (
                js_truth(
                    js_and(g($record, "balanceId"), function () use (&$record) {
                        return jm(
                            [
                                ...js_array(a_platform_people_PENDING_LEAVE),
                                "APPROVED",
                            ],
                            "includes",
                            g($record, "status"),
                        );
                    }),
                )
            ) {
                $balance = jm(
                    $tx,
                    "get",
                    "leave_balances",
                    g($record, "balanceId"),
                );
                if (js_truth(g($record, "status") === "APPROVED")) {
                    $balance["usedDays"] -= g($record, "days");
                } else {
                    $balance["reservedDays"] -= g($record, "days");
                }
                jm($tx, "update", "leave_balances", $balance);
            }
            if (js_truth(g($record, "workflowId"))) {
                $workflow = jm(
                    $tx,
                    "get",
                    "workflow_instances",
                    g($record, "workflowId"),
                );
                if (js_truth(g($workflow, "status") === "PENDING")) {
                    jm($tx, "update", "workflow_instances", [
                        ...js_array($workflow),
                        "status" => "CANCELLED",
                    ]);
                }
            }
            $saved = jm($tx, "update", "leave_requests", [
                ...js_array($record),
                "status" => "CANCELLED",
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "leave.cancel",
                "leave_request",
                $id,
            );
            return a_platform_validation_safeRecord($saved);
        });
    }
    public function contracts($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "contract.view_self",
            );
            $records = [];
            foreach (js_iter(jm($tx, "list", "contracts")) as $item16476) {
                $record = $item16476;
                $instance = js_and(g($record, "workflowId"), function () use (
                    &$tx,
                    &$record,
                ) {
                    return jm(
                        $tx,
                        "get",
                        "workflow_instances",
                        g($record, "workflowId"),
                    );
                });
                if (
                    js_truth(
                        a_platform_access_canReadContract(
                            g($actor, "user"),
                            $record,
                            js_and(
                                js_and($instance, function () use (&$instance) {
                                    return g($instance, "status") === "PENDING";
                                }),
                                function () use (
                                    &$actor,
                                    &$record,
                                    &$instance,
                                ) {
                                    return a_platform_workflows_eligible(
                                        g($actor, "user"),
                                        $instance,
                                        $record,
                                    );
                                },
                            ),
                        ),
                    )
                ) {
                    js_push(
                        $records,
                        a_platform_validation_safeRecord($record),
                    );
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "contract.read",
                "contract",
                "authorised",
            );
            return $records;
        });
    }
    public function createContract($actor = null, $body = null)
    {
        a_platform_access_requirePermission(
            g($actor, "user"),
            "contract.create",
        );
        $file = jm(g($this, "files"), "prepare", g($body, "file"));
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$body,
                &$file,
            ) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "contract.create",
                );
                $employee = jm(
                    $tx,
                    "get",
                    "staff_profiles",
                    g($body, "ownerId"),
                );
                if (js_truth(!js_truth($employee))) {
                    throw new HttpError(400, "Choose a staff profile.");
                }
                $startDate = a_platform_validation_date(g($body, "startDate"));
                $endDate = a_platform_validation_date(g($body, "endDate"));
                if (js_truth($endDate <= $startDate)) {
                    throw new HttpError(
                        400,
                        "Contract expiry must follow its start date.",
                    );
                }
                $previous = js_truth(g($body, "replacesId"))
                    ? jm($tx, "get", "contracts", g($body, "replacesId"))
                    : null;
                if (
                    js_truth(
                        js_and(g($body, "replacesId"), function () use (
                            &$employee,
                            &$previous,
                        ) {
                            return js_or(
                                js_or(!js_truth($previous), function () use (
                                    &$employee,
                                    &$previous,
                                ) {
                                    return g($previous, "ownerId") !==
                                        g($employee, "id");
                                }),
                                function () use (&$previous) {
                                    return !js_truth(
                                        jm(
                                            ["ACTIVE", "EXPIRED"],
                                            "includes",
                                            g($previous, "status"),
                                        ),
                                    );
                                },
                            );
                        }),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Only the same employee’s active or expired contract can be renewed.",
                    );
                }
                $id = rid("CTR-");
                $saved = jm($tx, "insert", "contracts", [
                    "id" => $id,
                    "reference" =>
                        "CTR-" .
                        js_string(jm(new JsDate(), "getFullYear")) .
                        "-" .
                        js_string(jm(jm($id, "slice", -8), "toUpperCase")) .
                        "",
                    "title" => text(g($body, "title"), 160, true),
                    "ownerId" => g($employee, "id"),
                    "departmentId" => g($employee, "departmentId"),
                    "supervisorId" => g($employee, "supervisorId"),
                    "startDate" => $startDate,
                    "endDate" => $endDate,
                    "file" => $file,
                    "financialReview" => g($body, "financialReview") === true,
                    "status" => "DRAFT",
                    "createdBy" => g(g($actor, "user"), "id"),
                    "createdAt" => jm(new JsDate(), "toISOString"),
                    "replacesId" => js_or(g($previous, "id"), function () {
                        return "";
                    }),
                    "version" => js_add(
                        js_or(g($previous, "version"), function () {
                            return 0;
                        }),
                        1,
                    ),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "contract.create",
                    "contract",
                    $id,
                );
                return a_platform_validation_safeRecord($saved);
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function reviseContract($actor = null, $id = null, $body = null)
    {
        a_platform_access_requirePermission(
            g($actor, "user"),
            "contract.create",
        );
        $file = jm(g($this, "files"), "prepare", g($body, "file"));
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$id,
                &$body,
                &$file,
            ) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "contract.create",
                );
                $record = jm($tx, "get", "contracts", $id);
                if (
                    js_truth(
                        js_or(!js_truth($record), function () use (
                            &$actor,
                            &$record,
                        ) {
                            return g($record, "createdBy") !==
                                g(g($actor, "user"), "id");
                        }),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "Only the HR author can revise this contract.",
                    );
                }
                a_platform_validation_revision($record, $body);
                if (
                    js_truth(
                        !js_truth(
                            jm(
                                ["DRAFT", "RETURNED"],
                                "includes",
                                g($record, "status"),
                            ),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Issued or pending contracts cannot be overwritten. Draft a renewal instead.",
                    );
                }
                $startDate = a_platform_validation_date(g($body, "startDate"));
                $endDate = a_platform_validation_date(g($body, "endDate"));
                if (
                    js_truth(
                        js_or($endDate <= $startDate, function () use (
                            &$body,
                            &$record,
                        ) {
                            return g($body, "ownerId") !==
                                g($record, "ownerId");
                        }),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Keep the same employee and valid contract dates.",
                    );
                }
                $previousVersions = [
                    ...js_array(
                        js_or(g($record, "previousVersions"), function () {
                            return [];
                        }),
                    ),
                    [
                        "version" => g($record, "version"),
                        "file" => g($record, "file"),
                        "title" => g($record, "title"),
                        "startDate" => g($record, "startDate"),
                        "endDate" => g($record, "endDate"),
                        "workflowId" => js_or(
                            g($record, "workflowId"),
                            function () {
                                return "";
                            },
                        ),
                        "supersededAt" => jm(new JsDate(), "toISOString"),
                    ],
                ];
                $saved = jm($tx, "update", "contracts", [
                    ...js_array($record),
                    "title" => text(g($body, "title"), 160, true),
                    "startDate" => $startDate,
                    "endDate" => $endDate,
                    "financialReview" => g($body, "financialReview") === true,
                    "file" => $file,
                    "previousVersions" => $previousVersions,
                    "version" => js_add(g($record, "version"), 1),
                    "status" => "DRAFT",
                    "workflowId" => "",
                    "approvedBy" => "",
                    "approvedAt" => "",
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "contract.revise",
                    "contract",
                    $id,
                    "success",
                    ["version" => g($saved, "version")],
                );
                return a_platform_validation_safeRecord($saved);
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function contractSubmit($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "contract.create",
            );
            $record = jm($tx, "get", "contracts", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "createdBy") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(
                    403,
                    "Only the HR author can submit this draft.",
                );
            }
            a_platform_validation_revision($record, $body);
            if (
                js_truth(
                    !js_truth(
                        jm(
                            ["DRAFT", "RETURNED"],
                            "includes",
                            g($record, "status"),
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "This contract is already under review or issued.",
                );
            }
            $flow = a_platform_workflows_startWorkflow(
                $tx,
                $actor,
                g($this, "config"),
                "contract-standard",
                $record,
            );
            return a_platform_validation_safeRecord(
                jm($tx, "update", "contracts", [
                    ...js_array($record),
                    "status" => "UNDER_REVIEW",
                    "workflowId" => g($flow, "id"),
                    "workflowIds" => [
                        ...js_array(
                            js_or(g($record, "workflowIds"), function () {
                                return [];
                            }),
                        ),
                        g($flow, "id"),
                    ],
                ]),
            );
        });
    }
}
