<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_overview_familyRecords($tx = null, $user = null)
{
    return jm(
        js_promise_all([
            jm($tx, "list", "student_profiles"),
            jm($tx, "list", "student_guardians"),
            jm($tx, "list", "classes"),
            jm($tx, "list", "class_teachers"),
            jm($tx, "list", "attendance_records"),
            jm($tx, "list", "student_results"),
            jm($tx, "list", "learning_materials"),
        ]),
        "then",
        function ($arg0) use (&$user) {
            $profiles = g($arg0, 0);
            $guardians = g($arg0, 1);
            $classes = g($arg0, 2);
            $assignments = g($arg0, 3);
            $attendance = g($arg0, 4);
            $results = g($arg0, 5);
            $materials = g($arg0, 6);
            $classById = new JsMap(
                jm($classes, "map", function ($item = null) {
                    return [g($item, "id"), $item];
                }),
            );
            $visible = null;
            if (js_truth(jm(g($user, "roles"), "includes", "parent"))) {
                $linkedIds = new JsSet(
                    jm(
                        jm($guardians, "filter", function ($link = null) use (
                            &$user,
                        ) {
                            return js_and(
                                js_and(
                                    g($link, "guardianUserId") ===
                                        g($user, "id"),
                                    function () use (&$link) {
                                        return g($link, "status") ===
                                            "VERIFIED";
                                    },
                                ),
                                function () use (&$link) {
                                    return g($link, "verifiedAt");
                                },
                            );
                        }),
                        "map",
                        function ($link = null) {
                            return g($link, "studentId");
                        },
                    ),
                );
                $visible = jm($profiles, "filter", function ($item = null) use (
                    &$linkedIds,
                ) {
                    return js_and(
                        jm($linkedIds, "has", g($item, "id")),
                        function () use (&$item) {
                            return g($item, "active") !== false;
                        },
                    );
                });
            } else {
                if (js_truth(jm(g($user, "roles"), "includes", "student"))) {
                    $visible = jm($profiles, "filter", function (
                        $item = null,
                    ) use (&$user) {
                        return js_and(
                            g($item, "userId") === g($user, "id"),
                            function () use (&$item) {
                                return g($item, "active") !== false;
                            },
                        );
                    });
                } else {
                    if (
                        js_truth(jm(g($user, "roles"), "includes", "teacher"))
                    ) {
                        $classIds = new JsSet(
                            jm(
                                jm($assignments, "filter", function (
                                    $item = null,
                                ) use (&$user) {
                                    return js_and(
                                        g($item, "teacherUserId") ===
                                            g($user, "id"),
                                        function () use (&$item) {
                                            return g($item, "active") !== false;
                                        },
                                    );
                                }),
                                "map",
                                function ($item = null) {
                                    return g($item, "classId");
                                },
                            ),
                        );
                        $visible = jm($profiles, "filter", function (
                            $item = null,
                        ) use (&$classIds) {
                            return js_and(
                                jm($classIds, "has", g($item, "classId")),
                                function () use (&$item) {
                                    return g($item, "active") !== false;
                                },
                            );
                        });
                    } else {
                        $visible = [];
                    }
                }
            }
            return jm($visible, "map", function ($student = null) use (
                &$attendance,
                &$results,
                &$materials,
                &$classById,
            ) {
                $classRecord = jm($classById, "get", g($student, "classId"));
                $studentAttendance = jm($attendance, "filter", function (
                    $item = null,
                ) use (&$student) {
                    return g($item, "studentId") === g($student, "id");
                });
                $present = g(
                    jm($studentAttendance, "filter", function ($item = null) {
                        return g($item, "status") === "PRESENT";
                    }),
                    "length",
                );
                $publishedResults = jm(
                    jm($results, "filter", function ($item = null) use (
                        &$student,
                    ) {
                        return js_and(
                            g($item, "studentId") === g($student, "id"),
                            function () use (&$item) {
                                return js_or(
                                    g($item, "status") === "PUBLISHED",
                                    function () use (&$item) {
                                        return g($item, "published") === true;
                                    },
                                );
                            },
                        );
                    }),
                    "map",
                    function ($item = null) {
                        return [
                            "subject" => g($item, "subject"),
                            "score" => g($item, "score"),
                            "term" => g($item, "term"),
                        ];
                    },
                );
                $availableMaterials = jm($materials, "filter", function (
                    $item = null,
                ) use (&$student) {
                    return js_and(
                        js_or(
                            g($item, "status") === "PUBLISHED",
                            function () use (&$item) {
                                return g($item, "published") === true;
                            },
                        ),
                        function () use (&$student, &$item) {
                            return js_or(
                                jm(
                                    js_or(g($item, "studentIds"), function () {
                                        return [];
                                    }),
                                    "includes",
                                    g($student, "id"),
                                ),
                                function () use (&$student, &$item) {
                                    return jm(
                                        js_or(
                                            g($item, "classIds"),
                                            function () {
                                                return [];
                                            },
                                        ),
                                        "includes",
                                        g($student, "classId"),
                                    );
                                },
                            );
                        },
                    );
                });
                return [
                    "id" => g($student, "id"),
                    "name" => g($student, "name"),
                    "studentRef" => g($student, "studentRef"),
                    "classId" => g($student, "classId"),
                    "className" => js_or(g($classRecord, "name"), function () {
                        return "";
                    }),
                    "yearLevel" => js_or(
                        js_or(g($student, "yearLevel"), function () use (
                            &$classRecord,
                        ) {
                            return g($classRecord, "yearLevel");
                        }),
                        function () {
                            return "";
                        },
                    ),
                    "attendance" => js_truth(g($studentAttendance, "length"))
                        ? [
                            "present" => $present,
                            "total" => g($studentAttendance, "length"),
                            "percentage" => js_math_round(
                                ($present / g($studentAttendance, "length")) *
                                    100,
                            ),
                        ]
                        : null,
                    "results" => $publishedResults,
                    "materials" => jm($availableMaterials, "map", function (
                        $item = null,
                    ) {
                        return [
                            "id" => g($item, "id"),
                            "title" => g($item, "title"),
                            "description" => js_or(
                                g($item, "description"),
                                function () {
                                    return "";
                                },
                            ),
                            "href" => js_or(g($item, "href"), function () {
                                return "";
                            }),
                        ];
                    }),
                ];
            });
        },
    );
}
trait a_platform_overview_Methods
{
    public function metadata($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            return [
                "publicSettings" => apply_details(
                    g(SEED, "settings"),
                    jm(
                        jm(jm($tx, "list", "cms_content"), "filter", function (
                            $item = null,
                        ) {
                            return js_and(
                                g($item, "published"),
                                function () use (&$item) {
                                    return g($item, "status") !== "ARCHIVED";
                                },
                            );
                        }),
                        "map",
                        function ($item = null) {
                            return [
                                "kind" => g($item, "kind"),
                                ...js_array(g($item, "published")),
                            ];
                        },
                    ),
                ),
                "departments" => jm($tx, "list", "departments"),
                "leaveTypes" => js_truth(
                    a_platform_access_has(g($actor, "user"), "leave.create"),
                )
                    ? jm($tx, "list", "leave_types")
                    : [],
                "roles" => js_truth(
                    js_or(
                        a_platform_access_has(g($actor, "user"), "role.grant"),
                        function () use (&$actor) {
                            return a_platform_access_has(
                                g($actor, "user"),
                                "user.create",
                            );
                        },
                    ),
                )
                    ? a_platform_access_ROLE_LABELS
                    : [],
                "classifications" => a_platform_access_CLASSIFICATIONS,
                "environment" => g(g($this, "config"), "environment"),
            ];
        });
    }
    public function family($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            $user = g($actor, "user");
            if (
                js_truth(
                    !js_truth(
                        jm(["parent", "student", "teacher"], "some", function (
                            $role = null,
                        ) use (&$user) {
                            return jm(g($user, "roles"), "includes", $role);
                        }),
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "This account has no family or classroom portal access.",
                    "FORBIDDEN",
                );
            }
            $students = a_platform_overview_familyRecords($tx, $user);
            $notices = jm(
                jm(
                    jm(jm($tx, "list", "notices"), "filter", function (
                        $item = null,
                    ) use (&$user) {
                        return js_and(
                            js_and(
                                g($item, "status") === "PUBLISHED",
                                function () use (&$item) {
                                    return a_platform_validation_activeDate(
                                        $item,
                                    );
                                },
                            ),
                            function () use (&$user, &$item) {
                                return a_platform_access_audienceAllows(
                                    $user,
                                    g($item, "audience"),
                                );
                            },
                        );
                    }),
                    "slice",
                    0,
                    5,
                ),
                "map",
                function ($item = null) {
                    return [
                        "id" => g($item, "id"),
                        "title" => g($item, "title"),
                        "message" => g($item, "message"),
                        "category" => g($item, "category"),
                        "publishedAt" => js_or(
                            g($item, "publishedAt"),
                            function () use (&$item) {
                                return g($item, "createdAt");
                            },
                        ),
                    ];
                },
            );
            $calendar = jm(
                jm(
                    jm(
                        jm(jm($tx, "list", "calendar"), "filter", function (
                            $item = null,
                        ) use (&$user) {
                            return js_and(
                                g($item, "date") >=
                                    jm(
                                        jm(new JsDate(), "toISOString"),
                                        "slice",
                                        0,
                                        10,
                                    ),
                                function () use (&$user, &$item) {
                                    return a_platform_access_audienceAllows(
                                        $user,
                                        g($item, "audience"),
                                    );
                                },
                            );
                        }),
                        "sort",
                        function ($a = null, $b = null) {
                            return jm(
                                g($a, "date"),
                                "localeCompare",
                                g($b, "date"),
                            );
                        },
                    ),
                    "slice",
                    0,
                    5,
                ),
                "map",
                function ($item = null) {
                    return [
                        "id" => g($item, "id"),
                        "title" => g($item, "title"),
                        "description" => js_or(
                            g($item, "description"),
                            function () {
                                return "";
                            },
                        ),
                        "date" => g($item, "date"),
                        "category" => g($item, "category"),
                    ];
                },
            );
            if (js_truth(jm(g($user, "roles"), "includes", "parent"))) {
                return [
                    "role" => "parent",
                    "children" => $students,
                    "notices" => $notices,
                    "calendar" => $calendar,
                ];
            }
            if (js_truth(jm(g($user, "roles"), "includes", "student"))) {
                return [
                    "role" => "student",
                    "student" => js_or(g($students, 0), function () {
                        return null;
                    }),
                    "notices" => $notices,
                    "calendar" => $calendar,
                ];
            }
            if (js_truth(jm(g($user, "roles"), "includes", "teacher"))) {
                $classes = jm($tx, "list", "classes");
                $assignments = jm(
                    jm($tx, "list", "class_teachers"),
                    "filter",
                    function ($item = null) use (&$user) {
                        return js_and(
                            g($item, "teacherUserId") === g($user, "id"),
                            function () use (&$item) {
                                return g($item, "active") !== false;
                            },
                        );
                    },
                );
                return [
                    "role" => "teacher",
                    "classrooms" => jm($assignments, "map", function (
                        $assignment = null,
                    ) use (&$students, &$classes) {
                        return [
                            ...js_array(
                                jm($classes, "find", function (
                                    $item = null,
                                ) use (&$assignment) {
                                    return g($item, "id") ===
                                        g($assignment, "classId");
                                }),
                            ),
                            "students" => jm($students, "filter", function (
                                $student = null,
                            ) use (&$assignment) {
                                return g($student, "classId") ===
                                    g($assignment, "classId");
                            }),
                        ];
                    }),
                    "notices" => $notices,
                    "calendar" => $calendar,
                ];
            }
            return [
                "role" => "staff",
                "children" => [],
                "notices" => $notices,
                "calendar" => $calendar,
            ];
        });
    }
    public function dashboard($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            $user = g($actor, "user");
            $profile = js_truth(a_platform_access_has($user, "staff.view_self"))
                ? jm($tx, "get", "staff_profiles", g($user, "id"))
                : null;
            $year = jm(new JsDate(), "getFullYear");
            $balances = jm(
                jm($tx, "list", "leave_balances"),
                "filter",
                function ($item = null) use (&$user, &$year) {
                    return js_and(
                        g($item, "userId") === g($user, "id"),
                        function () use (&$year, &$item) {
                            return g($item, "year") === $year;
                        },
                    );
                },
            );
            $contracts = jm(jm($tx, "list", "contracts"), "filter", function (
                $record = null,
            ) use (&$user) {
                return js_and(
                    g($record, "ownerId") === g($user, "id"),
                    function () use (&$user, &$record) {
                        return a_platform_access_canReadContract(
                            $user,
                            $record,
                        );
                    },
                );
            });
            $current = g(
                jm($contracts, "sort", function ($a = null, $b = null) {
                    return jm(
                        g($b, "createdAt"),
                        "localeCompare",
                        g($a, "createdAt"),
                    );
                }),
                0,
            );
            $receipts = jm($tx, "list", "notice_acknowledgements");
            $notices = jm(jm($tx, "list", "notices"), "filter", function (
                $record = null,
            ) use (&$user) {
                return js_and(
                    js_and(
                        g($record, "status") === "PUBLISHED",
                        function () use (&$record) {
                            return a_platform_validation_activeDate($record);
                        },
                    ),
                    function () use (&$user, &$record) {
                        return a_platform_access_audienceAllows(
                            $user,
                            g($record, "audience"),
                        );
                    },
                );
            });
            $notifications = jm(
                jm($tx, "list", "notifications"),
                "filter",
                function ($item = null) use (&$user) {
                    return js_and(
                        g($item, "userId") === g($user, "id"),
                        function () use (&$item) {
                            return !js_truth(g($item, "readAt"));
                        },
                    );
                },
            );
            $pendingApprovals = 0;
            foreach (
                js_iter(jm($tx, "list", "workflow_instances"))
                as $item6227
            ) {
                $instance = $item6227;
                if (js_truth(g($instance, "status") === "PENDING")) {
                    $resource = jm(
                        $tx,
                        "get",
                        g($instance, "collection"),
                        g($instance, "resourceId"),
                    );
                    if (
                        js_truth(
                            js_and($resource, function () use (
                                &$user,
                                &$instance,
                                &$resource,
                            ) {
                                return a_platform_workflows_eligible(
                                    $user,
                                    $instance,
                                    $resource,
                                );
                            }),
                        )
                    ) {
                        $pendingApprovals++;
                    }
                }
            }
            $documents = jm(
                jm($tx, "list", "document_versions"),
                "filter",
                function ($record = null) use (&$user) {
                    return js_and(
                        g($record, "status") === "ISSUED",
                        function () use (&$user, &$record) {
                            return a_platform_access_canReadDocument(
                                $user,
                                $record,
                            );
                        },
                    );
                },
            );
            $calendar = jm(
                jm(
                    jm(jm($tx, "list", "calendar"), "filter", function (
                        $record = null,
                    ) use (&$user) {
                        return js_and(
                            g($record, "date") >=
                                jm(
                                    jm(new JsDate(), "toISOString"),
                                    "slice",
                                    0,
                                    10,
                                ),
                            function () use (&$user, &$record) {
                                return a_platform_access_audienceAllows(
                                    $user,
                                    g($record, "audience"),
                                );
                            },
                        );
                    }),
                    "sort",
                    function ($a = null, $b = null) {
                        return jm(
                            g($a, "date"),
                            "localeCompare",
                            g($b, "date"),
                        );
                    },
                ),
                "slice",
                0,
                5,
            );
            $annual = jm($balances, "find", function ($item = null) {
                return g($item, "typeId") === "annual";
            });
            $output = [
                "name" => g($user, "name"),
                "profile" => $profile,
                "annualLeave" => js_truth($annual)
                    ? g($annual, "entitledDays") -
                        g($annual, "usedDays") -
                        g($annual, "reservedDays")
                    : null,
                "balances" => $balances,
                "contractStatus" => js_or(g($current, "status"), function () {
                    return "No issued contract";
                }),
                "contractEndDate" => js_or(g($current, "endDate"), function () {
                    return null;
                }),
                "notices" => g($notices, "length"),
                "unreadNotices" => g(
                    jm($notices, "filter", function ($notice = null) use (
                        &$user,
                        &$receipts,
                    ) {
                        return !js_truth(
                            jm($receipts, "some", function (
                                $receipt = null,
                            ) use (&$user, &$notice) {
                                return js_and(
                                    js_and(
                                        g($receipt, "noticeId") ===
                                            g($notice, "id"),
                                        function () use (&$notice, &$receipt) {
                                            return g($receipt, "version") ===
                                                g($notice, "version");
                                        },
                                    ),
                                    function () use (&$user, &$receipt) {
                                        return g($receipt, "userId") ===
                                            g($user, "id");
                                    },
                                );
                            }),
                        );
                    }),
                    "length",
                ),
                "acknowledgementsDue" => g(
                    jm($notices, "filter", function ($notice = null) use (
                        &$user,
                        &$receipts,
                    ) {
                        return js_and(
                            g($notice, "acknowledgementRequired"),
                            function () use (&$user, &$receipts, &$notice) {
                                return !js_truth(
                                    jm($receipts, "some", function (
                                        $receipt = null,
                                    ) use (&$user, &$notice) {
                                        return js_and(
                                            js_and(
                                                js_and(
                                                    g($receipt, "noticeId") ===
                                                        g($notice, "id"),
                                                    function () use (
                                                        &$notice,
                                                        &$receipt,
                                                    ) {
                                                        return g(
                                                            $receipt,
                                                            "version",
                                                        ) ===
                                                            g(
                                                                $notice,
                                                                "version",
                                                            );
                                                    },
                                                ),
                                                function () use (
                                                    &$user,
                                                    &$receipt,
                                                ) {
                                                    return g(
                                                        $receipt,
                                                        "userId",
                                                    ) === g($user, "id");
                                                },
                                            ),
                                            function () use (&$receipt) {
                                                return g(
                                                    $receipt,
                                                    "acknowledgedAt",
                                                );
                                            },
                                        );
                                    }),
                                );
                            },
                        );
                    }),
                    "length",
                ),
                "documents" => g($documents, "length"),
                "pendingApprovals" => $pendingApprovals,
                "notifications" => jm(
                    jm($notifications, "slice", -5),
                    "reverse",
                ),
                "upcoming" => $calendar,
            ];
            if (js_truth(jm(g($user, "roles"), "includes", "parent"))) {
                $family = a_platform_overview_familyRecords($tx, $user);
                $output["family"] = ["children" => $family];
            }
            if (js_truth(jm(g($user, "roles"), "includes", "student"))) {
                $student = g(a_platform_overview_familyRecords($tx, $user), 0);
                $output["student"] = js_truth($student)
                    ? [
                        "studentRef" => g($student, "studentRef"),
                        "className" => g($student, "className"),
                        "attendance" => g($student, "attendance"),
                        "results" => g($student, "results"),
                    ]
                    : null;
            }
            if (js_truth(jm(g($user, "roles"), "includes", "teacher"))) {
                $assignments = jm(
                    jm($tx, "list", "class_teachers"),
                    "filter",
                    function ($item = null) use (&$user) {
                        return js_and(
                            g($item, "teacherUserId") === g($user, "id"),
                            function () use (&$item) {
                                return g($item, "active") !== false;
                            },
                        );
                    },
                );
                $classes = jm($tx, "list", "classes");
                $classroomStudents = a_platform_overview_familyRecords(
                    $tx,
                    $user,
                );
                $output["teacher"] = [
                    "classrooms" => jm($assignments, "map", function (
                        $item = null,
                    ) use (&$student, &$classes, &$classroomStudents) {
                        return [
                            ...js_array(
                                jm($classes, "find", function (
                                    $record = null,
                                ) use (&$item) {
                                    return g($record, "id") ===
                                        g($item, "classId");
                                }),
                            ),
                            "studentCount" => g(
                                jm($classroomStudents, "filter", function (
                                    $student = null,
                                ) use (&$item) {
                                    return g($student, "classId") ===
                                        g($item, "classId");
                                }),
                                "length",
                            ),
                        ];
                    }),
                ];
            }
            if (js_truth(a_platform_access_has($user, "staff.view_all"))) {
                $output["staffTotal"] = g(
                    jm($tx, "list", "staff_profiles"),
                    "length",
                );
            }
            if (js_truth(a_platform_access_has($user, "system.read"))) {
                $output["accountsTotal"] = g(
                    jm(jm($tx, "list", "users"), "filter", function (
                        $item = null,
                    ) {
                        return g($item, "active");
                    }),
                    "length",
                );
                $output["alertsPending"] = g(
                    jm(jm($tx, "list", "outbox"), "filter", function (
                        $item = null,
                    ) {
                        return g($item, "status") !== "DELIVERED";
                    }),
                    "length",
                );
                $output["database"] = g(g($this, "store"), "kind");
                $output["officeAlertsConfigured"] =
                    (bool) ($this->config["alertUrl"] || $this->config["smtp"]);
            }
            return $output;
        });
    }
    public function requests($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "request.read",
            );
            $result = [];
            foreach (
                js_iter(jm($tx, "list", "workflow_instances"))
                as $item9382
            ) {
                $flow = $item9382;
                $record = jm(
                    $tx,
                    "get",
                    g($flow, "collection"),
                    g($flow, "resourceId"),
                );
                if (js_truth(!js_truth($record))) {
                    continue;
                }
                $allowed =
                    g($flow, "requesterId") === g(g($actor, "user"), "id");
                if (js_truth(g($flow, "kind") === "LEAVE_REQUEST")) {
                    $allowed = a_platform_access_canReadLeave(
                        g($actor, "user"),
                        $record,
                    );
                }
                if (js_truth(g($flow, "kind") === "EMPLOYMENT_CONTRACT")) {
                    $allowed = a_platform_access_canReadContract(
                        g($actor, "user"),
                        $record,
                        js_and(
                            g($flow, "status") === "PENDING",
                            function () use (&$actor, &$flow, &$record) {
                                return a_platform_workflows_eligible(
                                    g($actor, "user"),
                                    $flow,
                                    $record,
                                );
                            },
                        ),
                    );
                }
                if (js_truth(g($flow, "kind") === "CONTROLLED_DOCUMENT")) {
                    $allowed = js_and(
                        a_platform_access_canReadDocument(
                            g($actor, "user"),
                            $record,
                        ),
                        function () use (&$actor, &$flow, &$record) {
                            return js_or(
                                g($record, "createdBy") ===
                                    g(g($actor, "user"), "id"),
                                function () use (&$actor, &$flow, &$record) {
                                    return a_platform_workflows_eligible(
                                        g($actor, "user"),
                                        $flow,
                                        $record,
                                    );
                                },
                            );
                        },
                    );
                }
                if (js_truth($allowed)) {
                    js_push($result, [
                        ...js_array($flow),
                        "resource" => a_platform_validation_safeRecord($record),
                    ]);
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "request.read",
                "workflow_instance",
                "authorised",
            );
            return jm($result, "reverse");
        });
    }
    public function auditLog($actor = null, $params = [])
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$params,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "audit.read",
            );
            $all = jm($tx, "list", "audit_logs");
            $isScoped = js_and(
                jm(g(g($actor, "user"), "roles"), "includes", "auditor"),
                function () use (&$actor) {
                    return !js_truth(
                        jm(g(g($actor, "user"), "roles"), "some", function (
                            $role = null,
                        ) {
                            return jm(
                                ["head_teacher", "dpo"],
                                "includes",
                                $role,
                            );
                        }),
                    );
                },
            );
            $records = jm($all, "filter", function ($record = null) use (
                &$actor,
                &$isScoped,
            ) {
                return js_or(!js_truth($isScoped), function () use (
                    &$actor,
                    &$record,
                ) {
                    return jm(
                        js_or(g(g($actor, "user"), "auditScopes"), function () {
                            return [];
                        }),
                        "includes",
                        g($record, "resourceType"),
                    );
                });
            });
            if (js_truth(g($params, "action"))) {
                $records = jm($records, "filter", function (
                    $record = null,
                ) use (&$params) {
                    return jm(
                        g($record, "action"),
                        "includes",
                        text(g($params, "action"), 100),
                    );
                });
            }
            if (js_truth(g($params, "result"))) {
                $records = jm($records, "filter", function (
                    $record = null,
                ) use (&$params) {
                    return g($record, "result") === g($params, "result");
                });
            }
            $integrityValid = verify_audit(
                $all,
                g(g($this, "config"), "auditKey"),
                jm($tx, "get", "system_settings", "audit-head"),
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "audit.read",
                "audit_log",
                "authorised",
            );
            return [
                "integrityValid" => $integrityValid,
                "total" => g($records, "length"),
                "records" => jm(jm($records, "slice", -200), "reverse"),
                "limitedToAssignedScopes" => $isScoped,
            ];
        });
    }
    public function reports($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            $user = g($actor, "user");
            if (
                js_truth(
                    !js_truth(
                        jm(
                            [
                                "report.read",
                                "report.hr",
                                "system.read",
                                "cms.publish",
                            ],
                            "some",
                            function ($permission = null) use (&$user) {
                                return a_platform_access_has(
                                    $user,
                                    $permission,
                                );
                            },
                        ),
                    ),
                )
            ) {
                throw new HttpError(403, "Reports are restricted.");
            }
            $output = [];
            if (js_truth(a_platform_access_has($user, "staff.view_all"))) {
                $profiles = jm($tx, "list", "staff_profiles");
                $leave = jm($tx, "list", "leave_requests");
                $today = jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
                $output["hr"] = [
                    "staff" => g($profiles, "length"),
                    "permanent" => g(
                        jm($profiles, "filter", function ($item = null) {
                            return g($item, "employmentType") === "Permanent";
                        }),
                        "length",
                    ),
                    "contractStaff" => g(
                        jm($profiles, "filter", function ($item = null) {
                            return g($item, "employmentType") === "Contract";
                        }),
                        "length",
                    ),
                    "onLeave" => g(
                        jm($leave, "filter", function ($item = null) use (
                            &$today,
                        ) {
                            return js_and(
                                js_and(
                                    g($item, "status") === "APPROVED",
                                    function () use (&$today, &$item) {
                                        return g($item, "startDate") <= $today;
                                    },
                                ),
                                function () use (&$today, &$item) {
                                    return g($item, "returnDate") > $today;
                                },
                            );
                        }),
                        "length",
                    ),
                    "pendingLeave" => g(
                        jm($leave, "filter", function ($item = null) {
                            return jm(
                                ["SUBMITTED", "UNDER_REVIEW"],
                                "includes",
                                g($item, "status"),
                            );
                        }),
                        "length",
                    ),
                    "usedLeaveDays" => jm(
                        jm($tx, "list", "leave_balances"),
                        "reduce",
                        function ($sum = null, $item = null) {
                            return js_add($sum, g($item, "usedDays"));
                        },
                        0,
                    ),
                ];
                if (
                    js_truth(a_platform_access_has($user, "contract.view_all"))
                ) {
                    $output["hr"]["expiringContracts"] = jm(
                        jm(jm($tx, "list", "contracts"), "filter", function (
                            $item = null,
                        ) {
                            return js_and(
                                g($item, "status") === "ACTIVE",
                                function () use (&$item) {
                                    return js_date_parse(g($item, "endDate")) -
                                        js_date_now() <=
                                        90 * 86400000;
                                },
                            );
                        }),
                        "map",
                        "a_platform_validation_safeRecord",
                    );
                }
            }
            if (js_truth(a_platform_access_has($user, "admission.read"))) {
                $admissions = jm($tx, "list", "admissions");
                $enquiries = jm($tx, "list", "submissions");
                $output["admissions"] = [
                    "applications" => g($admissions, "length"),
                    "visitsRequested" => g(
                        jm($enquiries, "filter", function ($item = null) {
                            return g($item, "kind") === "visit";
                        }),
                        "length",
                    ),
                    "enquiries" => g($enquiries, "length"),
                    "enrolled" => g(
                        jm($admissions, "filter", function ($item = null) {
                            return g($item, "status") === "ENROLLED";
                        }),
                        "length",
                    ),
                    "statuses" => js_object_fromEntries(
                        jm(
                            [
                                ...js_array(
                                    new JsSet(
                                        jm($admissions, "map", function (
                                            $item = null,
                                        ) {
                                            return g($item, "status");
                                        }),
                                    ),
                                ),
                            ],
                            "map",
                            function ($status = null) use (&$admissions) {
                                return [
                                    $status,
                                    g(
                                        jm($admissions, "filter", function (
                                            $item = null,
                                        ) use (&$status) {
                                            return g($item, "status") ===
                                                $status;
                                        }),
                                        "length",
                                    ),
                                ];
                            },
                        ),
                    ),
                ];
            }
            if (js_truth(a_platform_access_has($user, "document.read"))) {
                $versions = jm(
                    jm($tx, "list", "document_versions"),
                    "filter",
                    function ($item = null) use (&$user) {
                        return a_platform_access_canReadDocument($user, $item);
                    },
                );
                $today = jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
                $output["documents"] = [
                    "issued" => g(
                        jm($versions, "filter", function ($item = null) {
                            return g($item, "status") === "ISSUED";
                        }),
                        "length",
                    ),
                    "awaitingApproval" => g(
                        jm($versions, "filter", function ($item = null) {
                            return g($item, "status") === "UNDER_REVIEW";
                        }),
                        "length",
                    ),
                    "reviewDue" => g(
                        jm($versions, "filter", function ($item = null) use (
                            &$today,
                        ) {
                            return js_and(
                                js_and(
                                    g($item, "status") === "ISSUED",
                                    function () use (&$item) {
                                        return g($item, "reviewDate");
                                    },
                                ),
                                function () use (&$today, &$item) {
                                    return g($item, "reviewDate") <= $today;
                                },
                            );
                        }),
                        "length",
                    ),
                    "expired" => g(
                        jm($versions, "filter", function ($item = null) use (
                            &$today,
                        ) {
                            return js_and(
                                js_and(
                                    g($item, "status") === "ISSUED",
                                    function () use (&$item) {
                                        return g($item, "expiryDate");
                                    },
                                ),
                                function () use (&$today, &$item) {
                                    return g($item, "expiryDate") < $today;
                                },
                            );
                        }),
                        "length",
                    ),
                    "superseded" => g(
                        jm($versions, "filter", function ($item = null) {
                            return g($item, "status") === "SUPERSEDED";
                        }),
                        "length",
                    ),
                ];
            }
            if (
                js_truth(
                    jm(["cms.review", "cms.publish"], "some", function (
                        $permission = null,
                    ) use (&$user) {
                        return a_platform_access_has($user, $permission);
                    }),
                )
            ) {
                $content = jm($tx, "list", "cms_content");
                $output["cms"] = [
                    "live" => g(
                        jm($content, "filter", function ($item = null) {
                            return js_and(
                                g($item, "published"),
                                function () use (&$item) {
                                    return g($item, "status") !== "ARCHIVED";
                                },
                            );
                        }),
                        "length",
                    ),
                    "drafts" => g(
                        jm($content, "filter", function ($item = null) {
                            return g($item, "status") === "DRAFT";
                        }),
                        "length",
                    ),
                    "awaitingApproval" => g(
                        jm($content, "filter", function ($item = null) {
                            return g($item, "status") === "UNDER_REVIEW";
                        }),
                        "length",
                    ),
                    "scheduled" => g(
                        jm($content, "filter", function ($item = null) {
                            return g($item, "status") === "SCHEDULED";
                        }),
                        "length",
                    ),
                    "archived" => g(
                        jm($content, "filter", function ($item = null) {
                            return g($item, "status") === "ARCHIVED";
                        }),
                        "length",
                    ),
                    "analytics" => "No visitor tracking is enabled.",
                ];
            }
            if (js_truth(a_platform_access_has($user, "system.read"))) {
                $logs = jm($tx, "list", "audit_logs");
                $safeguardingRecord = jm(
                    jm($tx, "list", "cms_content"),
                    "find",
                    function ($item = null) {
                        return js_and(
                            js_and(
                                js_and(
                                    g($item, "kind") === "settings",
                                    function () use (&$item) {
                                        return g($item, "slug") ===
                                            "school-contact";
                                    },
                                ),
                                function () use (&$item) {
                                    return g($item, "published");
                                },
                            ),
                            function () use (&$item) {
                                return g($item, "status") !== "ARCHIVED";
                            },
                        );
                    },
                );
                $safeguardingContactConfigured = false;
                if (js_truth($safeguardingRecord)) {
                    try {
                        $safeguardingContactConfigured = ("js_truth")(
                            g(
                                validate_details(
                                    g(
                                        g($safeguardingRecord, "published"),
                                        "body",
                                    ),
                                ),
                                "safeguardingName",
                            ),
                        );
                    } catch (\Throwable $caught) {
                    }
                }
                $output["security"] = [
                    "failedSignIns" => g(
                        jm($logs, "filter", function ($item = null) {
                            return js_and(
                                g($item, "action") === "auth.login",
                                function () use (&$item) {
                                    return g($item, "result") === "failure";
                                },
                            );
                        }),
                        "length",
                    ),
                    "lockouts" => g(
                        jm($logs, "filter", function ($item = null) {
                            return g($item, "action") === "auth.locked";
                        }),
                        "length",
                    ),
                    "database" => g(g($this, "store"), "kind"),
                    "externalAlertsConfigured" =>
                        (bool) ($this->config["alertUrl"] ||
                            $this->config["smtp"]),
                    "safeguardingContactConfigured" => $safeguardingContactConfigured,
                ];
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "report.read",
                "report",
                "authorised",
            );
            return $output;
        });
    }
    public function systemStatus($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "system.read",
            );
            return [
                "database" => g(g($this, "store"), "kind"),
                "environment" => g(g($this, "config"), "environment"),
                "databaseName" => $this->config["mysql"]["database"] ?? "",
                "storage" => $this->files->provider(),
                "emailTransport" => $this->config["alertUrl"]
                    ? "webhook"
                    : ($this->config["smtp"]
                        ? "smtp"
                        : "unconfigured"),
                "officeAlertsConfigured" =>
                    (bool) ($this->config["alertUrl"] || $this->config["smtp"]),
                "outbox" => jm(jm($tx, "list", "outbox"), "map", function (
                    $arg0,
                ) {
                    $id = g($arg0, "id");
                    $status = g($arg0, "status");
                    $attempts = g($arg0, "attempts");
                    $nextAttemptAt = g($arg0, "nextAttemptAt");
                    $createdAt = g($arg0, "createdAt");
                    $deliveredAt = g($arg0, "deliveredAt");
                    $lastStatus = g($arg0, "lastStatus");
                    return [
                        "id" => $id,
                        "status" => $status,
                        "attempts" => $attempts,
                        "nextAttemptAt" => $nextAttemptAt,
                        "createdAt" => $createdAt,
                        "deliveredAt" => $deliveredAt,
                        "lastStatus" => $lastStatus,
                    ];
                }),
                "maintenance" => jm(
                    $tx,
                    "get",
                    "system_settings",
                    "maintenance",
                ),
            ];
        });
    }
    public function retryAlert($actor = null, $id = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "system.read",
            );
            $record = jm($tx, "get", "outbox", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (&$record) {
                        return !js_truth(
                            jm(
                                ["FAILED", "PENDING", "RETRY"],
                                "includes",
                                g($record, "status"),
                            ),
                        );
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "Only a queued or failed alert can be retried.",
                );
            }
            jm($tx, "update", "outbox", [
                ...js_array($record),
                "status" => "PENDING",
                "attempts" => 0,
                "nextAttemptAt" => js_date_now(),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "notification.retry",
                "outbox",
                $id,
            );
            return ["ok" => true];
        });
    }
    public function search($actor = null, $params = null)
    {
        $query = jm(text(g($params, "q"), 150), "toLowerCase");
        if (js_truth(g($query, "length") < 2)) {
            return [];
        }
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$params,
            &$query,
        ) {
            a_platform_access_requirePermission(g($actor, "user"), "search");
            $results = [];
            $add = function (
                $type = null,
                $record = null,
                $title = null,
                $summary = null,
                $url = null,
            ) use (&$results) {
                return js_push($results, [
                    "type" => $type,
                    "id" => g($record, "id"),
                    "title" => $title,
                    "summary" => $summary,
                    "url" => $url,
                    "classification" => js_or(
                        g($record, "classification"),
                        function () {
                            return "INTERNAL";
                        },
                    ),
                    "category" => js_or(g($record, "category"), function () {
                        return "";
                    }),
                    "departmentId" => js_or(
                        g($record, "departmentId"),
                        function () {
                            return "";
                        },
                    ),
                    "ownerId" => js_or(
                        js_or(g($record, "ownerId"), function () use (
                            &$record,
                        ) {
                            return g($record, "createdBy");
                        }),
                        function () {
                            return "";
                        },
                    ),
                    "version" => js_or(g($record, "version"), function () {
                        return 1;
                    }),
                    "status" => js_or(g($record, "status"), function () {
                        return "";
                    }),
                    "year" => jm(
                        js_or(g($record, "createdAt"), function () {
                            return "";
                        }),
                        "slice",
                        0,
                        4,
                    ),
                ]);
            };
            foreach (
                js_iter(jm($tx, "list", "document_versions"))
                as $item17159
            ) {
                $record = $item17159;
                if (
                    js_truth(
                        js_and(
                            g($record, "status") === "ISSUED",
                            function () use (&$actor, &$record) {
                                return a_platform_access_canReadDocument(
                                    g($actor, "user"),
                                    $record,
                                );
                            },
                        ),
                    )
                ) {
                    $add(
                        "Document",
                        $record,
                        g($record, "title"),
                        "" .
                            js_string(g($record, "documentNumber")) .
                            " · version " .
                            js_string(g($record, "version")) .
                            "",
                        "/portal/documents",
                    );
                }
            }
            foreach (js_iter(jm($tx, "list", "notices")) as $item17411) {
                $record = $item17411;
                if (
                    js_truth(
                        js_and(
                            js_and(
                                g($record, "status") === "PUBLISHED",
                                function () use (&$record) {
                                    return a_platform_validation_activeDate(
                                        $record,
                                    );
                                },
                            ),
                            function () use (&$actor, &$record) {
                                return a_platform_access_audienceAllows(
                                    g($actor, "user"),
                                    g($record, "audience"),
                                );
                            },
                        ),
                    )
                ) {
                    $add(
                        "Notice",
                        $record,
                        g($record, "title"),
                        jm(g($record, "message"), "slice", 0, 180),
                        "/portal/notices",
                    );
                }
            }
            foreach (js_iter(jm($tx, "list", "calendar")) as $item17658) {
                $record = $item17658;
                if (
                    js_truth(
                        a_platform_access_audienceAllows(
                            g($actor, "user"),
                            g($record, "audience"),
                        ),
                    )
                ) {
                    $add(
                        "Event",
                        $record,
                        g($record, "title"),
                        g($record, "date"),
                        "/portal/calendar",
                    );
                }
            }
            foreach (js_iter(jm($tx, "list", "cms_content")) as $item17832) {
                $record = $item17832;
                if (
                    js_truth(
                        js_and(
                            js_and(g($record, "published"), function () use (
                                &$record,
                            ) {
                                return g($record, "status") !== "ARCHIVED";
                            }),
                            function () use (&$record) {
                                return g($record, "kind") !== "settings";
                            },
                        ),
                    )
                ) {
                    $add(
                        "News / page",
                        [
                            "id" => g($record, "id"),
                            ...js_array(g($record, "published")),
                            "status" => "PUBLISHED",
                            "classification" => "PUBLIC",
                        ],
                        g(g($record, "published"), "title"),
                        g(g($record, "published"), "excerpt"),
                        js_truth(g($record, "kind") === "news")
                            ? js_add("/news/", g($record, "slug"))
                            : js_add("/pages/", g($record, "slug")),
                    );
                }
            }
            foreach (js_iter(jm($tx, "list", "staff_profiles")) as $item18205) {
                $profile = $item18205;
                if (
                    js_truth(
                        a_platform_access_canReadStaff(
                            g($actor, "user"),
                            $profile,
                        ),
                    )
                ) {
                    $add(
                        "Staff",
                        $profile,
                        g($profile, "name"),
                        g($profile, "position"),
                        js_truth(
                            g($profile, "id") === g(g($actor, "user"), "id"),
                        )
                            ? "/portal/profile"
                            : "/portal/staff",
                    );
                }
            }
            foreach (js_iter(jm($tx, "list", "contracts")) as $item18430) {
                $contract = $item18430;
                $instance = js_and(g($contract, "workflowId"), function () use (
                    &$tx,
                    &$contract,
                ) {
                    return jm(
                        $tx,
                        "get",
                        "workflow_instances",
                        g($contract, "workflowId"),
                    );
                });
                if (
                    js_truth(
                        a_platform_access_canReadContract(
                            g($actor, "user"),
                            $contract,
                            js_and(
                                g($instance, "status") === "PENDING",
                                function () use (
                                    &$actor,
                                    &$contract,
                                    &$instance,
                                ) {
                                    return a_platform_workflows_eligible(
                                        g($actor, "user"),
                                        $instance,
                                        $contract,
                                    );
                                },
                            ),
                        ),
                    )
                ) {
                    $add(
                        "Contract",
                        $contract,
                        g($contract, "title"),
                        g($contract, "reference"),
                        "/portal/contracts",
                    );
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "search.read",
                "search",
                "authorised",
            );
            return jm(
                jm($results, "filter", function ($item = null) use (
                    &$params,
                    &$query,
                ) {
                    return js_and(
                        jm(
                            jm(
                                "" .
                                    js_string(g($item, "title")) .
                                    " " .
                                    js_string(g($item, "summary")) .
                                    "",
                                "toLowerCase",
                            ),
                            "includes",
                            $query,
                        ),
                        function () use (&$params, &$item) {
                            return jm(
                                [
                                    "category",
                                    "classification",
                                    "departmentId",
                                    "ownerId",
                                    "version",
                                    "status",
                                    "year",
                                    "type",
                                ],
                                "every",
                                function ($key = null) use (&$params, &$item) {
                                    return js_or(
                                        !js_truth(g($params, $key)),
                                        function () use (
                                            &$params,
                                            &$item,
                                            &$key,
                                        ) {
                                            return js_String(g($item, $key)) ===
                                                g($params, $key);
                                        },
                                    );
                                },
                            );
                        },
                    );
                }),
                "slice",
                0,
                50,
            );
        });
    }
}
