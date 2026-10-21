<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_family_assignedClassIds($tx = null, $user = null)
{
    return new JsSet(
        jm(
            jm(jm($tx, "list", "class_teachers"), "filter", function (
                $item = null,
            ) use (&$user) {
                return js_and(
                    g($item, "teacherUserId") === g($user, "id"),
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
}
function a_platform_family_visibleStudents($tx = null, $user = null)
{
    $profiles = jm(jm($tx, "list", "student_profiles"), "filter", function (
        $item = null,
    ) {
        return g($item, "active") !== false;
    });
    if (js_truth(a_platform_access_has($user, "student.manage"))) {
        return $profiles;
    }
    if (js_truth(jm(g($user, "roles"), "includes", "student"))) {
        return jm($profiles, "filter", function ($item = null) use (&$user) {
            return g($item, "userId") === g($user, "id");
        });
    }
    if (js_truth(jm(g($user, "roles"), "includes", "parent"))) {
        $links = jm($tx, "list", "student_guardians");
        $ids = new JsSet(
            jm(
                jm($links, "filter", function ($item = null) use (&$user) {
                    return js_and(
                        js_and(
                            g($item, "guardianUserId") === g($user, "id"),
                            function () use (&$item) {
                                return g($item, "status") === "VERIFIED";
                            },
                        ),
                        function () use (&$item) {
                            return g($item, "verifiedAt");
                        },
                    );
                }),
                "map",
                function ($item = null) {
                    return g($item, "studentId");
                },
            ),
        );
        return jm($profiles, "filter", function ($item = null) use (&$ids) {
            return jm($ids, "has", g($item, "id"));
        });
    }
    if (js_truth(jm(g($user, "roles"), "includes", "teacher"))) {
        $classIds = a_platform_family_assignedClassIds($tx, $user);
        return jm($profiles, "filter", function ($item = null) use (
            &$classIds,
        ) {
            return jm($classIds, "has", g($item, "classId"));
        });
    }
    return [];
}
function a_platform_family_requireStudentAccess(
    $tx = null,
    $user = null,
    $studentId = null,
    $action = "student.read",
) {
    a_platform_access_requirePermission($user, $action);
    $student = jm($tx, "get", "student_profiles", $studentId);
    if (
        js_truth(
            js_or(!js_truth($student), function () use (&$student) {
                return g($student, "active") === false;
            }),
        )
    ) {
        throw new HttpError(404, "Learner record not found.");
    }
    if (js_truth(a_platform_access_has($user, "student.manage"))) {
        return $student;
    }
    $allowed = jm(
        a_platform_family_visibleStudents($tx, $user),
        "some",
        function ($item = null) use (&$student) {
            return g($item, "id") === g($student, "id");
        },
    );
    if (js_truth(!js_truth($allowed))) {
        throw new HttpError(
            403,
            "This learner record is outside your assigned scope.",
        );
    }
    return $student;
}
function a_platform_family_requireTeacherAssignment(
    $tx = null,
    $user = null,
    $classId = null,
) {
    if (js_truth(a_platform_access_has($user, "student.manage"))) {
        return null;
    }
    if (
        js_truth(
            js_or(
                !js_truth(jm(g($user, "roles"), "includes", "teacher")),
                function () use (&$tx, &$user, &$classId) {
                    return !js_truth(
                        jm(
                            a_platform_family_assignedClassIds($tx, $user),
                            "has",
                            $classId,
                        ),
                    );
                },
            ),
        )
    ) {
        throw new HttpError(403, "You are not assigned to this classroom.");
    }
}
function a_platform_family_userClassIds(
    $tx = null,
    $user = null,
    $students = null,
) {
    if (js_truth(a_platform_access_has($user, "class.manage"))) {
        return new JsSet(
            jm(jm($tx, "list", "classes"), "map", function ($item = null) {
                return g($item, "id");
            }),
        );
    }
    if (js_truth(jm(g($user, "roles"), "includes", "teacher"))) {
        return a_platform_family_assignedClassIds($tx, $user);
    }
    return new JsSet(
        jm($students, "map", function ($item = null) {
            return g($item, "classId");
        }),
    );
}
function a_platform_family_studentView($student = null, $classRecord = null)
{
    return [
        "id" => g($student, "id"),
        "studentRef" => g($student, "studentRef"),
        "userId" => g($student, "userId"),
        "name" => g($student, "name"),
        "classId" => g($student, "classId"),
        "className" => js_or(g($classRecord, "name"), function () {
            return "";
        }),
        "yearLevel" => js_or(
            js_or(g($student, "yearLevel"), function () use (&$classRecord) {
                return g($classRecord, "yearLevel");
            }),
            function () {
                return "";
            },
        ),
        "active" => g($student, "active") !== false,
        "revision" => g($student, "revision"),
    ];
}
trait a_platform_family_Methods
{
    public function students($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.read",
            );
            $profiles = g(
                js_promise_all([
                    a_platform_family_visibleStudents($tx, g($actor, "user")),
                    jm($tx, "list", "classes"),
                ]),
                0,
            );
            $classes = g(
                js_promise_all([
                    a_platform_family_visibleStudents($tx, g($actor, "user")),
                    jm($tx, "list", "classes"),
                ]),
                1,
            );
            $byId = new JsMap(
                jm($classes, "map", function ($item = null) {
                    return [g($item, "id"), $item];
                }),
            );
            return jm($profiles, "map", function ($item = null) use (&$byId) {
                return a_platform_family_studentView(
                    $item,
                    jm($byId, "get", g($item, "classId")),
                );
            });
        });
    }
    public function classes($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.read",
            );
            $classes = jm($tx, "list", "classes");
            if (
                js_truth(
                    a_platform_access_has(g($actor, "user"), "student.manage"),
                )
            ) {
                return $classes;
            }
            if (
                js_truth(
                    jm(g(g($actor, "user"), "roles"), "includes", "teacher"),
                )
            ) {
                $ids = a_platform_family_assignedClassIds(
                    $tx,
                    g($actor, "user"),
                );
                return jm($classes, "filter", function ($item = null) use (
                    &$ids,
                ) {
                    return jm($ids, "has", g($item, "id"));
                });
            }
            $students = a_platform_family_visibleStudents(
                $tx,
                g($actor, "user"),
            );
            $ids = new JsSet(
                jm($students, "map", function ($item = null) {
                    return g($item, "classId");
                }),
            );
            return jm($classes, "filter", function ($item = null) use (&$ids) {
                return jm($ids, "has", g($item, "id"));
            });
        });
    }
    public function saveClass($actor = null, $body = null, $id = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "class.manage",
            );
            $old = js_and($id, function () use (&$id, &$tx) {
                return jm($tx, "get", "classes", $id);
            });
            if (
                js_truth(
                    js_and($id, function () use (&$old) {
                        return !js_truth($old);
                    }),
                )
            ) {
                throw new HttpError(404, "Class not found.");
            }
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
            }
            $classId = js_or($id, function () use (&$body) {
                return text(g($body, "id"), 40, true);
            });
            if (
                js_truth(
                    !js_truth(
                        jm(
                            new JsRegex('^[a-z0-9][a-z0-9._-]{1,39}$', ""),
                            "test",
                            $classId,
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Use a 2–40 character lowercase class ID.",
                );
            }
            $record = [
                ...js_array(
                    js_or($old, function () {
                        return [];
                    }),
                ),
                "id" => $classId,
                "name" => text(g($body, "name"), 100, true),
                "yearLevel" => text(g($body, "yearLevel"), 80, true),
                "active" => g($body, "active") !== false,
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "classes", $record)
                : jm($tx, "insert", "classes", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($old) ? "class.update" : "class.create",
                "class",
                $classId,
            );
            return $saved;
        });
    }
    public function assignTeacher($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "class.manage",
            );
            $classRecord = jm($tx, "get", "classes", g($body, "classId"));
            $teacher = jm($tx, "get", "users", g($body, "teacherUserId"));
            if (
                js_truth(
                    js_or(
                        js_or(!js_truth($classRecord), function () use (
                            &$teacher,
                        ) {
                            return !js_truth(g($teacher, "active"));
                        }),
                        function () use (&$teacher) {
                            return !js_truth(
                                jm(g($teacher, "roles"), "includes", "teacher"),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose an existing class and active teacher account.",
                );
            }
            $old = jm(jm($tx, "list", "class_teachers"), "find", function (
                $item = null,
            ) use (&$classRecord, &$teacher) {
                return js_and(
                    g($item, "classId") === g($classRecord, "id"),
                    function () use (&$teacher, &$item) {
                        return g($item, "teacherUserId") === g($teacher, "id");
                    },
                );
            });
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
                $saved = jm($tx, "update", "class_teachers", [
                    ...js_array($old),
                    "active" => g($body, "active") !== false,
                    "updatedAt" => jm(new JsDate(), "toISOString"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "class.teacher_assign",
                    "class",
                    g($classRecord, "id"),
                );
                return $saved;
            }
            $saved = jm($tx, "insert", "class_teachers", [
                "id" => rid("CTA-"),
                "classId" => g($classRecord, "id"),
                "teacherUserId" => g($teacher, "id"),
                "active" => true,
                "assignedBy" => g(g($actor, "user"), "id"),
                "assignedAt" => jm(new JsDate(), "toISOString"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "class.teacher_assign",
                "class",
                g($classRecord, "id"),
            );
            return $saved;
        });
    }
    public function saveStudent($actor = null, $body = null, $id = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.manage",
            );
            $old = js_and($id, function () use (&$id, &$tx) {
                return jm($tx, "get", "student_profiles", $id);
            });
            if (
                js_truth(
                    js_and($id, function () use (&$old) {
                        return !js_truth($old);
                    }),
                )
            ) {
                throw new HttpError(404, "Learner record not found.");
            }
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
            }
            $user = jm($tx, "get", "users", g($body, "userId"));
            $classRecord = jm($tx, "get", "classes", g($body, "classId"));
            $studentRef = jm(
                text(g($body, "studentRef"), 80, true),
                "toUpperCase",
            );
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(
                                !js_truth(g($user, "active")),
                                function () use (&$user) {
                                    return !js_truth(
                                        jm(
                                            g($user, "roles"),
                                            "includes",
                                            "student",
                                        ),
                                    );
                                },
                            ),
                            function () use (&$classRecord) {
                                return !js_truth(g($classRecord, "active"));
                            },
                        ),
                        function () use (&$studentRef) {
                            return !js_truth(
                                jm(
                                    new JsRegex('^[A-Z0-9-]{3,80}$', ""),
                                    "test",
                                    $studentRef,
                                ),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose an active student account, an active class, and a valid private learner reference.",
                );
            }
            if (
                js_truth(
                    jm(jm($tx, "list", "student_profiles"), "some", function (
                        $item = null,
                    ) use (&$id, &$user, &$studentRef) {
                        return js_and(g($item, "id") !== $id, function () use (
                            &$user,
                            &$studentRef,
                            &$item,
                        ) {
                            return js_or(
                                g($item, "userId") === g($user, "id"),
                                function () use (&$studentRef, &$item) {
                                    return g($item, "studentRef") ===
                                        $studentRef;
                                },
                            );
                        });
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "This student account or private reference is already linked.",
                );
            }
            $profile = [
                ...js_array(
                    js_or($old, function () {
                        return [];
                    }),
                ),
                "id" => js_or($id, function () {
                    return rid("STU-");
                }),
                "studentRef" => $studentRef,
                "userId" => g($user, "id"),
                "name" => g($user, "name"),
                "classId" => g($classRecord, "id"),
                "yearLevel" => text(g($body, "yearLevel"), 80),
                "active" => g($body, "active") !== false,
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "student_profiles", $profile)
                : jm($tx, "insert", "student_profiles", $profile);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($old) ? "student.update" : "student.create",
                "student_profile",
                g($saved, "id"),
            );
            return a_platform_family_studentView($saved, $classRecord);
        });
    }
    public function verifyGuardian(
        $actor = null,
        $studentId = null,
        $body = null,
    ) {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$studentId,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.guardian.verify",
            );
            $student = jm($tx, "get", "student_profiles", $studentId);
            $guardian = jm($tx, "get", "users", g($body, "guardianUserId"));
            $relationship = a_platform_validation_choose(
                g($body, "relationship"),
                ["Parent", "Legal guardian"],
            );
            $verificationReference = text(
                g($body, "verificationReference"),
                120,
                true,
            );
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(
                                js_or(
                                    !js_truth(g($student, "active")),
                                    function () use (&$guardian) {
                                        return !js_truth(
                                            g($guardian, "active"),
                                        );
                                    },
                                ),
                                function () use (&$guardian) {
                                    return !js_truth(
                                        jm(
                                            g($guardian, "roles"),
                                            "includes",
                                            "parent",
                                        ),
                                    );
                                },
                            ),
                            function () use (&$body) {
                                return g($body, "authorityVerified") !== true;
                            },
                        ),
                        function () use (&$verificationReference) {
                            return g($verificationReference, "length") < 4;
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Verify the guardian’s identity and authority before linking the account.",
                );
            }
            $links = jm($tx, "list", "student_guardians");
            $old = jm($links, "find", function ($item = null) use (
                &$student,
                &$guardian,
            ) {
                return js_and(
                    g($item, "studentId") === g($student, "id"),
                    function () use (&$guardian, &$item) {
                        return g($item, "guardianUserId") ===
                            g($guardian, "id");
                    },
                );
            });
            if (js_truth(g($old, "status") === "VERIFIED")) {
                throw new HttpError(
                    409,
                    "This guardian is already linked to the learner.",
                );
            }
            $link = [
                ...js_array(
                    js_or($old, function () {
                        return [];
                    }),
                ),
                "id" => js_or(g($old, "id"), function () {
                    return rid("GDN-");
                }),
                "studentId" => g($student, "id"),
                "guardianUserId" => g($guardian, "id"),
                "guardianName" => g($guardian, "name"),
                "relationship" => $relationship,
                "status" => "VERIFIED",
                "verificationReference" => $verificationReference,
                "verifiedBy" => g(g($actor, "user"), "id"),
                "verifiedAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "student_guardians", $link)
                : jm($tx, "insert", "student_guardians", $link);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "student.guardian_verify",
                "student_guardian",
                g($saved, "id"),
            );
            return [
                "id" => g($saved, "id"),
                "studentId" => g($saved, "studentId"),
                "guardianUserId" => g($saved, "guardianUserId"),
                "relationship" => g($saved, "relationship"),
                "status" => g($saved, "status"),
                "verifiedAt" => g($saved, "verifiedAt"),
            ];
        });
    }
    public function attendance($actor = null, $studentId = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$studentId,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.attendance.read",
            );
            $students = js_truth($studentId)
                ? [
                    a_platform_family_requireStudentAccess(
                        $tx,
                        g($actor, "user"),
                        $studentId,
                        "student.attendance.read",
                    ),
                ]
                : a_platform_family_visibleStudents($tx, g($actor, "user"));
            $ids = new JsSet(
                jm($students, "map", function ($item = null) {
                    return g($item, "id");
                }),
            );
            $records = jm(
                jm(jm($tx, "list", "attendance_records"), "filter", function (
                    $item = null,
                ) use (&$ids) {
                    return jm($ids, "has", g($item, "studentId"));
                }),
                "sort",
                function ($a = null, $b = null) {
                    return jm(g($b, "date"), "localeCompare", g($a, "date"));
                },
            );
            if (
                js_truth(
                    jm(g(g($actor, "user"), "roles"), "some", function (
                        $role = null,
                    ) {
                        return jm(["parent", "student"], "includes", $role);
                    }),
                )
            ) {
                return jm($records, "map", function ($arg0) {
                    $id = g($arg0, "id");
                    $studentId = g($arg0, "studentId");
                    $date = g($arg0, "date");
                    $status = g($arg0, "status");
                    return [
                        "id" => $id,
                        "studentId" => $studentId,
                        "date" => $date,
                        "status" => $status,
                    ];
                });
            }
            return $records;
        });
    }
    public function recordAttendance($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.attendance.manage",
            );
            $student = jm(
                $tx,
                "get",
                "student_profiles",
                g($body, "studentId"),
            );
            if (js_truth(!js_truth(g($student, "active")))) {
                throw new HttpError(404, "Learner record not found.");
            }
            a_platform_family_requireTeacherAssignment(
                $tx,
                g($actor, "user"),
                g($student, "classId"),
            );
            $date = a_platform_validation_date(g($body, "date"));
            if (
                js_truth(
                    $date > jm(jm(new JsDate(), "toISOString"), "slice", 0, 10),
                )
            ) {
                throw new HttpError(
                    400,
                    "Attendance cannot be recorded for a future date.",
                );
            }
            $status = a_platform_validation_choose(g($body, "status"), [
                "PRESENT",
                "ABSENT",
                "LATE",
                "EXCUSED",
            ]);
            $id =
                "" . js_string(g($student, "id")) . ":" . js_string($date) . "";
            $old = jm($tx, "get", "attendance_records", $id);
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
            }
            $record = [
                ...js_array(
                    js_or($old, function () {
                        return [];
                    }),
                ),
                "id" => $id,
                "studentId" => g($student, "id"),
                "classId" => g($student, "classId"),
                "date" => $date,
                "status" => $status,
                "note" => text(g($body, "note"), 500),
                "recordedBy" => g(g($actor, "user"), "id"),
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "attendance_records", $record)
                : jm($tx, "insert", "attendance_records", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($old) ? "attendance.update" : "attendance.record",
                "attendance_record",
                $id,
            );
            return $saved;
        });
    }
    public function results($actor = null, $studentId = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$studentId,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.results.read",
            );
            $students = js_truth($studentId)
                ? [
                    a_platform_family_requireStudentAccess(
                        $tx,
                        g($actor, "user"),
                        $studentId,
                        "student.results.read",
                    ),
                ]
                : a_platform_family_visibleStudents($tx, g($actor, "user"));
            $ids = new JsSet(
                jm($students, "map", function ($item = null) {
                    return g($item, "id");
                }),
            );
            $records = jm(
                jm(jm($tx, "list", "student_results"), "filter", function (
                    $item = null,
                ) use (&$actor, &$ids) {
                    return js_and(
                        jm($ids, "has", g($item, "studentId")),
                        function () use (&$actor, &$item) {
                            return js_or(
                                a_platform_access_has(
                                    g($actor, "user"),
                                    "student.results.manage",
                                ),
                                function () use (&$item) {
                                    return g($item, "status") === "PUBLISHED";
                                },
                            );
                        },
                    );
                }),
                "sort",
                function ($a = null, $b = null) {
                    return jm(
                        g($b, "updatedAt"),
                        "localeCompare",
                        g($a, "updatedAt"),
                    );
                },
            );
            if (
                js_truth(
                    jm(g(g($actor, "user"), "roles"), "some", function (
                        $role = null,
                    ) {
                        return jm(["parent", "student"], "includes", $role);
                    }),
                )
            ) {
                return jm($records, "map", function ($arg0) {
                    $id = g($arg0, "id");
                    $studentId = g($arg0, "studentId");
                    $subject = g($arg0, "subject");
                    $term = g($arg0, "term");
                    $score = g($arg0, "score");
                    $publishedAt = g($arg0, "publishedAt");
                    return [
                        "id" => $id,
                        "studentId" => $studentId,
                        "subject" => $subject,
                        "term" => $term,
                        "score" => $score,
                        "publishedAt" => $publishedAt,
                    ];
                });
            }
            return $records;
        });
    }
    public function saveResult($actor = null, $body = null, $id = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.results.manage",
            );
            $student = jm(
                $tx,
                "get",
                "student_profiles",
                g($body, "studentId"),
            );
            if (js_truth(!js_truth(g($student, "active")))) {
                throw new HttpError(404, "Learner record not found.");
            }
            a_platform_family_requireTeacherAssignment(
                $tx,
                g($actor, "user"),
                g($student, "classId"),
            );
            $old = js_and($id, function () use (&$id, &$tx) {
                return jm($tx, "get", "student_results", $id);
            });
            if (
                js_truth(
                    js_and($id, function () use (&$old) {
                        return !js_truth($old);
                    }),
                )
            ) {
                throw new HttpError(404, "Result record not found.");
            }
            if (js_truth($old)) {
                a_platform_validation_revision($old, $body);
            }
            if (
                js_truth(
                    js_and($old, function () use (&$old) {
                        return g($old, "status") === "PUBLISHED";
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "Published results cannot be edited; create a corrected version for review.",
                );
            }
            $record = [
                ...js_array(
                    js_or($old, function () {
                        return [];
                    }),
                ),
                "id" => js_or($id, function () {
                    return rid("RES-");
                }),
                "studentId" => g($student, "id"),
                "classId" => g($student, "classId"),
                "subject" => text(g($body, "subject"), 100, true),
                "term" => text(g($body, "term"), 80, true),
                "score" => text(g($body, "score"), 40, true),
                "status" => "DRAFT",
                "recordedBy" => g(g($actor, "user"), "id"),
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = js_truth($old)
                ? jm($tx, "update", "student_results", $record)
                : jm($tx, "insert", "student_results", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($old)
                    ? "student.result_update"
                    : "student.result_create",
                "student_result",
                g($saved, "id"),
            );
            return $saved;
        });
    }
    public function publishResult($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.results.publish",
            );
            $record = jm($tx, "get", "student_results", $id);
            if (
                js_truth(
                    js_or(
                        js_or(!js_truth($record), function () use (&$record) {
                            return g($record, "status") !== "DRAFT";
                        }),
                        function () use (&$actor, &$record) {
                            return g($record, "recordedBy") ===
                                g(g($actor, "user"), "id");
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "Only another staff member’s draft result can be published.",
                );
            }
            a_platform_validation_revision($record, $body);
            $saved = jm($tx, "update", "student_results", [
                ...js_array($record),
                "status" => "PUBLISHED",
                "publishedBy" => g(g($actor, "user"), "id"),
                "publishedAt" => jm(new JsDate(), "toISOString"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "student.result_publish",
                "student_result",
                g($saved, "id"),
            );
            return $saved;
        });
    }
    public function materials($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.read",
            );
            $students = a_platform_family_visibleStudents(
                $tx,
                g($actor, "user"),
            );
            $studentIds = new JsSet(
                jm($students, "map", function ($item = null) {
                    return g($item, "id");
                }),
            );
            $classIds = a_platform_family_userClassIds(
                $tx,
                g($actor, "user"),
                $students,
            );
            return jm(
                jm($tx, "list", "learning_materials"),
                "filter",
                function ($item = null) use (
                    &$actor,
                    &$studentIds,
                    &$classIds,
                ) {
                    $owned = js_and(
                        g($item, "createdBy") === g(g($actor, "user"), "id"),
                        function () use (&$actor) {
                            return a_platform_access_has(
                                g($actor, "user"),
                                "student.material.manage",
                            );
                        },
                    );
                    $assignedDraft = js_and(
                        js_and(g($item, "status") === "DRAFT", function () use (
                            &$actor,
                        ) {
                            return a_platform_access_has(
                                g($actor, "user"),
                                "student.material.publish",
                            );
                        }),
                        function () use (&$studentIds, &$classIds, &$item) {
                            return js_or(
                                jm(
                                    js_or(g($item, "studentIds"), function () {
                                        return [];
                                    }),
                                    "some",
                                    function ($id = null) use (&$studentIds) {
                                        return jm($studentIds, "has", $id);
                                    },
                                ),
                                function () use (&$classIds, &$item) {
                                    return jm(
                                        js_or(
                                            g($item, "classIds"),
                                            function () {
                                                return [];
                                            },
                                        ),
                                        "some",
                                        function ($id = null) use (&$classIds) {
                                            return jm($classIds, "has", $id);
                                        },
                                    );
                                },
                            );
                        },
                    );
                    return js_or(
                        js_or($owned, function () use (&$assignedDraft) {
                            return $assignedDraft;
                        }),
                        function () use (&$studentIds, &$classIds, &$item) {
                            return js_and(
                                g($item, "status") === "PUBLISHED",
                                function () use (
                                    &$studentIds,
                                    &$classIds,
                                    &$item,
                                ) {
                                    return js_or(
                                        jm(
                                            js_or(
                                                g($item, "studentIds"),
                                                function () {
                                                    return [];
                                                },
                                            ),
                                            "some",
                                            function ($id = null) use (
                                                &$studentIds,
                                            ) {
                                                return jm(
                                                    $studentIds,
                                                    "has",
                                                    $id,
                                                );
                                            },
                                        ),
                                        function () use (&$classIds, &$item) {
                                            return jm(
                                                js_or(
                                                    g($item, "classIds"),
                                                    function () {
                                                        return [];
                                                    },
                                                ),
                                                "some",
                                                function ($id = null) use (
                                                    &$classIds,
                                                ) {
                                                    return jm(
                                                        $classIds,
                                                        "has",
                                                        $id,
                                                    );
                                                },
                                            );
                                        },
                                    );
                                },
                            );
                        },
                    );
                },
            );
        });
    }
    public function familyMessages($actor = null, $studentId = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$studentId,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.communication.read",
            );
            $students = js_truth($studentId)
                ? [
                    a_platform_family_requireStudentAccess(
                        $tx,
                        g($actor, "user"),
                        $studentId,
                        "student.communication.read",
                    ),
                ]
                : a_platform_family_visibleStudents($tx, g($actor, "user"));
            $ids = new JsSet(
                jm($students, "map", function ($item = null) {
                    return g($item, "id");
                }),
            );
            return jm(
                jm(jm($tx, "list", "family_messages"), "filter", function (
                    $item = null,
                ) use (&$actor, &$ids) {
                    return js_and(
                        jm($ids, "has", g($item, "studentId")),
                        function () use (&$actor, &$item) {
                            return js_or(
                                g($item, "senderId") ===
                                    g(g($actor, "user"), "id"),
                                function () use (&$actor, &$item) {
                                    return g($item, "recipientId") ===
                                        g(g($actor, "user"), "id");
                                },
                            );
                        },
                    );
                }),
                "sort",
                function ($a = null, $b = null) {
                    return jm(
                        g($a, "createdAt"),
                        "localeCompare",
                        g($b, "createdAt"),
                    );
                },
            );
        });
    }
    public function familyContacts($actor = null, $studentId = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$studentId,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.communication.read",
            );
            $students = js_truth($studentId)
                ? [
                    a_platform_family_requireStudentAccess(
                        $tx,
                        g($actor, "user"),
                        $studentId,
                        "student.communication.read",
                    ),
                ]
                : a_platform_family_visibleStudents($tx, g($actor, "user"));
            $output = [];
            foreach (js_iter($students) as $item15697) {
                $student = $item15697;
                $guardians = g(
                    js_promise_all([
                        jm($tx, "list", "student_guardians"),
                        jm($tx, "list", "class_teachers"),
                    ]),
                    0,
                );
                $assignments = g(
                    js_promise_all([
                        jm($tx, "list", "student_guardians"),
                        jm($tx, "list", "class_teachers"),
                    ]),
                    1,
                );
                $ids = js_truth(
                    jm(g(g($actor, "user"), "roles"), "includes", "parent"),
                )
                    ? jm(
                        jm($assignments, "filter", function ($item = null) use (
                            &$student,
                        ) {
                            return js_and(
                                g($item, "classId") === g($student, "classId"),
                                function () use (&$item) {
                                    return g($item, "active") !== false;
                                },
                            );
                        }),
                        "map",
                        function ($item = null) {
                            return g($item, "teacherUserId");
                        },
                    )
                    : (js_truth(
                        jm(
                            g(g($actor, "user"), "roles"),
                            "includes",
                            "teacher",
                        ),
                    )
                        ? jm(
                            jm($guardians, "filter", function (
                                $item = null,
                            ) use (&$student) {
                                return js_and(
                                    js_and(
                                        g($item, "studentId") ===
                                            g($student, "id"),
                                        function () use (&$item) {
                                            return g($item, "status") ===
                                                "VERIFIED";
                                        },
                                    ),
                                    function () use (&$item) {
                                        return g($item, "verifiedAt");
                                    },
                                );
                            }),
                            "map",
                            function ($item = null) {
                                return g($item, "guardianUserId");
                            },
                        )
                        : []);
                $contacts = [];
                foreach (js_iter(new JsSet($ids)) as $item16292) {
                    $id = $item16292;
                    $user = jm($tx, "get", "users", $id);
                    if (js_truth(g($user, "active"))) {
                        js_push($contacts, [
                            "id" => g($user, "id"),
                            "name" => g($user, "name"),
                            "role" => js_truth(
                                jm(
                                    g(g($actor, "user"), "roles"),
                                    "includes",
                                    "parent",
                                ),
                            )
                                ? "Teacher"
                                : "Parent / guardian",
                        ]);
                    }
                }
                js_push($output, [
                    "studentId" => g($student, "id"),
                    "studentName" => g($student, "name"),
                    "contacts" => $contacts,
                ]);
            }
            return $output;
        });
    }
    public function sendFamilyMessage($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.communication.send",
            );
            $student = a_platform_family_requireStudentAccess(
                $tx,
                g($actor, "user"),
                g($body, "studentId"),
                "student.communication.read",
            );
            if (
                js_truth(
                    !js_truth(
                        jm(g(g($actor, "user"), "roles"), "some", function (
                            $role = null,
                        ) {
                            return jm(["parent", "teacher"], "includes", $role);
                        }),
                    ),
                )
            ) {
                throw new HttpError(403, "Student messaging is not enabled.");
            }
            $recipient = jm($tx, "get", "users", g($body, "recipientId"));
            if (
                js_truth(
                    js_or(!js_truth(g($recipient, "active")), function () use (
                        &$actor,
                        &$recipient,
                    ) {
                        return g($recipient, "id") ===
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(
                    400,
                    "Choose an active member of this learner’s school team.",
                );
            }
            $guardians = g(
                js_promise_all([
                    jm($tx, "list", "student_guardians"),
                    jm($tx, "list", "class_teachers"),
                ]),
                0,
            );
            $assignments = g(
                js_promise_all([
                    jm($tx, "list", "student_guardians"),
                    jm($tx, "list", "class_teachers"),
                ]),
                1,
            );
            $guardianIds = jm(
                jm($guardians, "filter", function ($item = null) use (
                    &$student,
                ) {
                    return js_and(
                        js_and(
                            g($item, "studentId") === g($student, "id"),
                            function () use (&$item) {
                                return g($item, "status") === "VERIFIED";
                            },
                        ),
                        function () use (&$item) {
                            return g($item, "verifiedAt");
                        },
                    );
                }),
                "map",
                function ($item = null) {
                    return g($item, "guardianUserId");
                },
            );
            $teacherIds = jm(
                jm($assignments, "filter", function ($item = null) use (
                    &$student,
                ) {
                    return js_and(
                        g($item, "classId") === g($student, "classId"),
                        function () use (&$item) {
                            return g($item, "active") !== false;
                        },
                    );
                }),
                "map",
                function ($item = null) {
                    return g($item, "teacherUserId");
                },
            );
            $participants = new JsSet([
                ...js_array($guardianIds),
                ...js_array($teacherIds),
                g($student, "userId"),
            ]);
            if (
                js_truth(
                    js_or(
                        !js_truth(
                            jm(
                                $participants,
                                "has",
                                g(g($actor, "user"), "id"),
                            ),
                        ),
                        function () use (&$recipient, &$participants) {
                            return !js_truth(
                                jm($participants, "has", g($recipient, "id")),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "Messages are limited to verified guardians and the learner’s assigned classroom team.",
                );
            }
            $content = text(g($body, "message"), 2000, true);
            $record = jm($tx, "insert", "family_messages", [
                "id" => rid("MSG-"),
                "studentId" => g($student, "id"),
                "senderId" => g(g($actor, "user"), "id"),
                "recipientId" => g($recipient, "id"),
                "message" => $content,
                "createdAt" => jm(new JsDate(), "toISOString"),
            ]);
            jm($tx, "insert", "notifications", [
                "id" => rid("NTF-"),
                "userId" => g($recipient, "id"),
                "title" => "New family message",
                "href" => "/portal/family",
                "createdAt" => g($record, "createdAt"),
                "readAt" => "",
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "family.message_send",
                "family_message",
                g($record, "id"),
            );
            return $record;
        });
    }
    public function saveMaterial($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.material.manage",
            );
            $classIds = a_platform_validation_strings(g($body, "classIds"), 50);
            if (js_truth(!js_truth(g($classIds, "length")))) {
                throw new HttpError(400, "Choose one or more classrooms.");
            }
            foreach (js_iter($classIds) as $item18872) {
                $classId = $item18872;
                if (js_truth(!js_truth(jm($tx, "get", "classes", $classId)))) {
                    throw new HttpError(400, "Choose existing classrooms.");
                }
                a_platform_family_requireTeacherAssignment(
                    $tx,
                    g($actor, "user"),
                    $classId,
                );
            }
            $href = text(g($body, "href"), 500, true);
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(
                                !js_truth(jm($href, "startsWith", "/")),
                                function () use (&$href) {
                                    return jm($href, "startsWith", "//");
                                },
                            ),
                            function () use (&$href) {
                                return jm($href, "includes", "\\");
                            },
                        ),
                        function () use (&$href) {
                            return jm(
                                new JsRegex("[\\u0000-\\u001f]", ""),
                                "test",
                                $href,
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Use an internal approved school resource path.",
                );
            }
            $record = jm($tx, "insert", "learning_materials", [
                "id" => rid("MAT-"),
                "title" => text(g($body, "title"), 160, true),
                "description" => text(g($body, "description"), 1000),
                "href" => $href,
                "classIds" => $classIds,
                "studentIds" => [],
                "status" => "DRAFT",
                "createdBy" => g(g($actor, "user"), "id"),
                "createdAt" => jm(new JsDate(), "toISOString"),
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "student.material_create",
                "learning_material",
                g($record, "id"),
            );
            return $record;
        });
    }
    public function publishMaterial($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "student.material.publish",
            );
            $record = jm($tx, "get", "learning_materials", $id);
            if (
                js_truth(
                    js_or(
                        js_or(!js_truth($record), function () use (&$record) {
                            return g($record, "status") !== "DRAFT";
                        }),
                        function () use (&$actor, &$record) {
                            return g($record, "createdBy") ===
                                g(g($actor, "user"), "id");
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "Only another staff member’s draft material can be published.",
                );
            }
            a_platform_validation_revision($record, $body);
            $saved = jm($tx, "update", "learning_materials", [
                ...js_array($record),
                "status" => "PUBLISHED",
                "publishedBy" => g(g($actor, "user"), "id"),
                "publishedAt" => jm(new JsDate(), "toISOString"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "student.material_publish",
                "learning_material",
                g($saved, "id"),
            );
            return $saved;
        });
    }
}
