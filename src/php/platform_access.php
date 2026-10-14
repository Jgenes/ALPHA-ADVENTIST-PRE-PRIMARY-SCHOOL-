<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_platform_access_STAFF", [
    "staff.view_self",
    "leave.create",
    "leave.view_self",
    "contract.view_self",
    "document.read",
    "notice.read",
    "calendar.read",
    "notification.read",
    "search",
    "request.read",
]);
define(
    "a_platform_access_ROLE_PERMISSIONS",
    js_object_freeze([
        "system_admin" => [
            "user.create",
            "user.manage",
            "system.read",
            "notification.read",
            "workflow.read",
        ],
        "ict_officer" => [
            ...js_array(a_platform_access_STAFF),
            "system.read",
            "cms.configure",
            "document.create",
            "document.review",
        ],
        "head_teacher" => [
            ...js_array(a_platform_access_STAFF),
            "staff.view_all",
            "staff.manage",
            "student.manage",
            "student.read",
            "student.guardian.verify",
            "student.attendance.read",
            "student.results.read",
            "student.results.manage",
            "student.material.manage",
            "class.manage",
            "student.attendance.manage",
            "student.results.publish",
            "student.material.publish",
            "leave.review",
            "leave.approve",
            "contract.view_all",
            "contract.review",
            "contract.approve",
            "contract.sign",
            "document.create",
            "document.review",
            "document.approve",
            "document.publish",
            "document.archive",
            "document.confidential",
            "document.high_confidential",
            "notice.create",
            "notice.publish",
            "cms.create",
            "cms.edit",
            "cms.edit_any",
            "cms.review",
            "cms.approve",
            "cms.publish",
            "cms.archive",
            "admission.read",
            "admission.manage",
            "submission.read",
            "submission.manage",
            "workflow.configure",
            "workflow.read",
            "role.grant",
            "user.create",
            "user.manage",
            "audit.read",
            "report.read",
            "calendar.manage",
            "privacy.manage",
            "media.review",
            "media.publish",
        ],
        "school_admin" => [
            ...js_array(a_platform_access_STAFF),
            "staff.view_all",
            "student.manage",
            "student.read",
            "student.attendance.read",
            "student.results.read",
            "student.results.manage",
            "student.material.manage",
            "class.manage",
            "student.attendance.manage",
            "student.results.publish",
            "student.material.publish",
            "leave.review",
            "contract.review",
            "document.create",
            "document.review",
            "document.publish",
            "document.archive",
            "notice.create",
            "notice.publish",
            "cms.create",
            "cms.edit",
            "cms.edit_any",
            "cms.review",
            "admission.read",
            "admission.manage",
            "submission.read",
            "submission.manage",
            "calendar.manage",
            "report.read",
            "workflow.read",
        ],
        "hr_officer" => [
            ...js_array(a_platform_access_STAFF),
            "staff.view_all",
            "staff.manage",
            "leave.review",
            "contract.create",
            "contract.review",
            "contract.view_all",
            "document.create",
            "document.review",
            "document.confidential",
            "document.high_confidential",
            "notice.create",
            "report.hr",
            "workflow.read",
        ],
        "finance_officer" => [
            ...js_array(a_platform_access_STAFF),
            "contract.review",
            "document.create",
            "document.review",
        ],
        "academic_coordinator" => [
            ...js_array(a_platform_access_STAFF),
            "staff.view_department",
            "student.manage",
            "student.read",
            "student.attendance.read",
            "student.results.read",
            "student.results.manage",
            "student.material.manage",
            "student.guardian.verify",
            "class.manage",
            "student.attendance.manage",
            "student.results.publish",
            "student.material.publish",
            "leave.review",
            "document.create",
            "document.review",
            "notice.create",
            "calendar.manage",
        ],
        "head_of_department" => [
            ...js_array(a_platform_access_STAFF),
            "staff.view_department",
            "leave.review",
            "document.create",
            "document.review",
            "notice.create",
        ],
        "teacher" => [
            ...js_array(a_platform_access_STAFF),
            "cms.create",
            "document.create",
            "notice.read",
            "calendar.read",
            "student.read",
            "student.attendance.read",
            "student.attendance.manage",
            "student.results.read",
            "student.results.manage",
            "student.material.manage",
            "student.communication.read",
            "student.communication.send",
        ],
        "supporting_staff" => a_platform_access_STAFF,
        "dpo" => [
            ...js_array(a_platform_access_STAFF),
            "privacy.manage",
            "audit.read",
            "document.create",
            "document.review",
            "document.confidential",
            "document.high_confidential",
            "media.review",
        ],
        "cms_author" => [
            ...js_array(a_platform_access_STAFF),
            "cms.create",
            "cms.edit",
        ],
        "cms_editor" => [
            ...js_array(a_platform_access_STAFF),
            "cms.create",
            "cms.edit",
            "cms.edit_any",
            "cms.review",
        ],
        "cms_publisher" => [
            ...js_array(a_platform_access_STAFF),
            "cms.publish",
            "cms.archive",
            "media.publish",
        ],
        "media_manager" => [
            ...js_array(a_platform_access_STAFF),
            "media.create",
            "cms.create",
        ],
        "auditor" => ["audit.read", "notification.read"],
        "parent" => [
            "notification.read",
            "notice.read",
            "calendar.read",
            "document.read",
            "search",
            "student.read",
            "student.profile.read",
            "student.results.read",
            "student.attendance.read",
            "student.communication.read",
            "student.communication.send",
            "admission.submit",
            "admission.read_self",
        ],
        "student" => [
            "notification.read",
            "notice.read",
            "calendar.read",
            "document.read",
            "search",
            "student.read",
            "student.profile.read",
            "student.results.read",
            "student.attendance.read",
            "student.communication.read",
        ],
    ]),
);
define("a_platform_access_ROLE_LABELS", [
    "system_admin" => "System Administrator",
    "ict_officer" => "ICT Officer",
    "head_teacher" => "Head Teacher",
    "school_admin" => "School Administrator",
    "hr_officer" => "HR Officer",
    "finance_officer" => "Finance Officer",
    "academic_coordinator" => "Academic Coordinator",
    "head_of_department" => "Head of Department",
    "teacher" => "Teacher",
    "supporting_staff" => "Supporting Staff",
    "dpo" => "Privacy Officer",
    "cms_author" => "Content Author",
    "cms_editor" => "Content Editor",
    "cms_publisher" => "CMS Publisher",
    "media_manager" => "Media Manager",
    "auditor" => "Auditor",
    "parent" => "Parent / Guardian",
    "student" => "Student",
]);
function a_platform_access_permissions($user = null)
{
    if (
        js_truth(
            js_or(!js_truth($user), function () use (&$user) {
                return g($user, "active") === false;
            }),
        )
    ) {
        return [];
    }
    return [
        ...js_array(
            new JsSet(
                jm(
                    js_or(g($user, "roles"), function () {
                        return [];
                    }),
                    "flatMap",
                    function ($role = null) {
                        return js_or(
                            g(a_platform_access_ROLE_PERMISSIONS, $role),
                            function () {
                                return [];
                            },
                        );
                    },
                ),
            ),
        ),
    ];
}
function a_platform_access_has($user = null, $action = null)
{
    return jm(a_platform_access_permissions($user), "includes", $action);
}
function a_platform_access_requirePermission($user = null, $action = null)
{
    if (js_truth(!js_truth(a_platform_access_has($user, $action)))) {
        throw new HttpError(
            403,
            "You are not authorised for this action.",
            "FORBIDDEN",
        );
    }
}
function a_platform_access_isStaff($user = null)
{
    return a_platform_access_has($user, "staff.view_self");
}
function a_platform_access_audienceAllows($user = null, $audience = [])
{
    if (js_truth(!js_truth($user))) {
        return false;
    }
    if (js_truth(g($audience, "type") === "ALL_STAFF")) {
        return a_platform_access_isStaff($user);
    }
    if (js_truth(g($audience, "type") === "PUBLIC")) {
        return true;
    }
    return js_or(
        js_or(
            jm(
                js_or(g($audience, "userIds"), function () {
                    return [];
                }),
                "includes",
                g($user, "id"),
            ),
            function () use (&$user, &$audience) {
                return jm(
                    js_or(g($audience, "roles"), function () {
                        return [];
                    }),
                    "some",
                    function ($role = null) use (&$user) {
                        return jm(g($user, "roles"), "includes", $role);
                    },
                );
            },
        ),
        function () use (&$user, &$audience) {
            return js_and(g($user, "departmentId"), function () use (
                &$user,
                &$audience,
            ) {
                return jm(
                    js_or(g($audience, "departmentIds"), function () {
                        return [];
                    }),
                    "includes",
                    g($user, "departmentId"),
                );
            });
        },
    );
}
function a_platform_access_canReadStaff($user = null, $profile = null)
{
    return js_truth(g($profile, "id") === g($user, "id"))
        ? a_platform_access_has($user, "staff.view_self")
        : js_or(
            a_platform_access_has($user, "staff.view_all"),
            function () use (&$user, &$profile) {
                return js_and(
                    js_and(
                        a_platform_access_has($user, "staff.view_department"),
                        function () use (&$user) {
                            return !js_truth(
                                !js_truth(g($user, "departmentId")),
                            );
                        },
                    ),
                    function () use (&$user, &$profile) {
                        return g($user, "departmentId") ===
                            g($profile, "departmentId");
                    },
                );
            },
        );
}
function a_platform_access_canReadLeave($user = null, $record = null)
{
    return js_or(
        js_or(
            js_and(g($record, "ownerId") === g($user, "id"), function () use (
                &$user,
            ) {
                return a_platform_access_has($user, "leave.view_self");
            }),
            function () use (&$user) {
                return js_and(
                    a_platform_access_has($user, "staff.view_all"),
                    function () use (&$user) {
                        return a_platform_access_has($user, "leave.review");
                    },
                );
            },
        ),
        function () use (&$user, &$record) {
            return js_and(
                a_platform_access_has($user, "leave.review"),
                function () use (&$user, &$record) {
                    return js_or(
                        g($record, "supervisorId") === g($user, "id"),
                        function () use (&$user, &$record) {
                            return js_and(
                                js_and(
                                    a_platform_access_has(
                                        $user,
                                        "staff.view_department",
                                    ),
                                    function () use (&$user) {
                                        return !js_truth(
                                            !js_truth(g($user, "departmentId")),
                                        );
                                    },
                                ),
                                function () use (&$user, &$record) {
                                    return g($user, "departmentId") ===
                                        g($record, "departmentId");
                                },
                            );
                        },
                    );
                },
            );
        },
    );
}
function a_platform_access_canReadContract(
    $user = null,
    $record = null,
    $assigned = false,
) {
    return js_or(
        js_or(
            js_and(
                js_and(
                    g($record, "ownerId") === g($user, "id"),
                    function () use (&$user) {
                        return a_platform_access_has(
                            $user,
                            "contract.view_self",
                        );
                    },
                ),
                function () use (&$record) {
                    return jm(
                        [
                            "AWAITING_EMPLOYEE",
                            "ACTIVE",
                            "EXPIRED",
                            "ARCHIVED",
                            "SUPERSEDED",
                        ],
                        "includes",
                        g($record, "status"),
                    );
                },
            ),
            function () use (&$user) {
                return a_platform_access_has($user, "contract.view_all");
            },
        ),
        function () use (&$user, &$assigned) {
            return js_and($assigned, function () use (&$user) {
                return a_platform_access_has($user, "contract.review");
            });
        },
    );
}
define("a_platform_access_CLASSIFICATIONS", [
    "PUBLIC",
    "INTERNAL",
    "RESTRICTED",
    "CONFIDENTIAL",
    "HIGHLY_CONFIDENTIAL",
]);
function a_platform_access_canReadDocument($user = null, $record = null)
{
    if (js_truth(!js_truth(a_platform_access_has($user, "document.read")))) {
        return false;
    }
    if (js_truth(g($record, "contractId"))) {
        return false;
    }
    if (js_truth(g($record, "classification") === "PUBLIC")) {
        return true;
    }
    if (js_truth(g($record, "classification") === "INTERNAL")) {
        return a_platform_access_isStaff($user);
    }
    $acl = js_or(g($record, "access"), function () {
        return [];
    });
    $named = js_or(
        js_or(g($record, "ownerId") === g($user, "id"), function () use (
            &$user,
            &$acl,
        ) {
            return jm(
                js_or(g($acl, "userIds"), function () {
                    return [];
                }),
                "includes",
                g($user, "id"),
            );
        }),
        function () use (&$user, &$acl) {
            return jm(
                js_or(g($acl, "roles"), function () {
                    return [];
                }),
                "some",
                function ($role = null) use (&$user) {
                    return jm(g($user, "roles"), "includes", $role);
                },
            );
        },
    );
    if (js_truth(g($record, "classification") === "RESTRICTED")) {
        return js_or($named, function () use (&$user, &$acl) {
            return js_and(
                !js_truth(!js_truth(g($user, "departmentId"))),
                function () use (&$user, &$acl) {
                    return jm(
                        js_or(g($acl, "departmentIds"), function () {
                            return [];
                        }),
                        "includes",
                        g($user, "departmentId"),
                    );
                },
            );
        });
    }
    if (js_truth(g($record, "classification") === "CONFIDENTIAL")) {
        return js_and($named, function () use (&$user) {
            return a_platform_access_has($user, "document.confidential");
        });
    }
    if (js_truth(g($record, "classification") === "HIGHLY_CONFIDENTIAL")) {
        return js_and($named, function () use (&$user) {
            return a_platform_access_has($user, "document.high_confidential");
        });
    }
    return false;
}
function a_platform_access_validRoles($roles = null)
{
    if (
        js_truth(
            js_or(
                js_or(
                    js_or(!js_truth(js_array_isArray($roles)), function () use (
                        &$roles,
                    ) {
                        return !js_truth(g($roles, "length"));
                    }),
                    function () use (&$roles) {
                        return g($roles, "length") > 5;
                    },
                ),
                function () use (&$roles) {
                    return jm($roles, "some", function ($role = null) {
                        return js_or(
                            js_typeof($role) !== "string",
                            function () use (&$role) {
                                return !js_truth(
                                    js_object_hasOwn(
                                        a_platform_access_ROLE_PERMISSIONS,
                                        $role,
                                    ),
                                );
                            },
                        );
                    });
                },
            ),
        )
    ) {
        throw new HttpError(400, "Select valid roles.");
    }
    if (
        js_truth(
            js_and(
                jm($roles, "some", function ($role = null) {
                    return jm(
                        ["system_admin", "ict_officer"],
                        "includes",
                        $role,
                    );
                }),
                function () use (&$roles) {
                    return jm($roles, "some", function ($role = null) {
                        return !js_truth(
                            jm(
                                ["system_admin", "ict_officer"],
                                "includes",
                                $role,
                            ),
                        );
                    });
                },
            ),
        )
    ) {
        throw new HttpError(
            400,
            "Technical administration and business roles must use separate accounts.",
        );
    }
    return [...js_array(new JsSet($roles))];
}
