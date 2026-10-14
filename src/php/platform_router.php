<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_router_handleApi(
    $req = null,
    $res = null,
    $url = null,
    $platform = null,
    $auth = null,
) {
    $route = g($url, "pathname");
    $verb = g($req, "method");
    $mutating = jm(["POST", "PUT", "PATCH", "DELETE"], "includes", $verb);
    if (
        js_truth(
            !js_truth(
                jm(
                    ["GET", "POST", "PUT", "PATCH", "DELETE"],
                    "includes",
                    $verb,
                ),
            ),
        )
    ) {
        throw new HttpError(405, "Method not allowed.");
    }
    if (js_truth($mutating)) {
        a_platform_router_sameOrigin($req);
    }
    $publicKind = jm($route, "slice", 5);
    if (
        js_truth(
            js_and(
                js_object_hasOwn(
                    a_platform_communications_FORM_RULES,
                    $publicKind,
                ),
                function () use (&$verb) {
                    return $verb === "POST";
                },
            ),
        )
    ) {
        $body = parse_form($req);
        $requestIp = "request_ip";
        $result = jm(
            $platform,
            "submitPublic",
            [
                "ip" => $requestIp($req, g($platform, "config")),
                "userAgent" => js_or(
                    g(g($req, "headers"), "user-agent"),
                    function () {
                        return "";
                    },
                ),
            ],
            $publicKind,
            $body,
        );
        return a_platform_router_json($res, 200, $result);
    }
    $actor = jm(
        $auth,
        "actor",
        $req,
        $res,
        js_and($route === "/api/auth/session", function () use (&$verb) {
            return $verb === "GET";
        }),
    );
    $req["actor"] = $actor;
    if (
        js_truth(
            js_and($route === "/api/auth/session", function () use (&$verb) {
                return $verb === "GET";
            }),
        )
    ) {
        return a_platform_router_json($res, 200, jm($auth, "info", $actor));
    }
    if (js_truth(jm($route, "startsWith", "/api/auth/"))) {
        if (js_truth($verb !== "POST")) {
            throw new HttpError(
                405,
                "Use POST for this authentication action.",
            );
        }
        $body = parse_form($req);
        require_csrf($actor, $req, $body);
        $result = null;
        if (js_truth($route === "/api/auth/login")) {
            $result = jm($auth, "login", $actor, $body, $req, $res);
        } else {
            if (js_truth($route === "/api/auth/logout")) {
                $result = jm($auth, "logout", $actor, $req, $res);
            } else {
                if (js_truth($route === "/api/auth/password")) {
                    $result = jm($auth, "password", $actor, $body, $req, $res);
                } else {
                    throw new HttpError(
                        404,
                        "Authentication action not found.",
                    );
                }
            }
        }
        return a_platform_router_json($res, 200, [
            "ok" => true,
            ...js_array($result),
        ]);
    }
    require_ready($actor);
    $body = [];
    if (js_truth($mutating)) {
        $large = js_or(
            js_and($verb === "POST", function () use (&$route) {
                return js_or(
                    js_or(
                        jm(
                            new JsRegex(
                                '^\\/api\\/(documents|contracts|media|leave)$',
                                "",
                            ),
                            "test",
                            $route,
                        ),
                        function () use (&$route) {
                            return jm(
                                new JsRegex(
                                    '^\\/api\\/documents\\/[^/]+\\/versions$',
                                    "",
                                ),
                                "test",
                                $route,
                            );
                        },
                    ),
                    function () use (&$route) {
                        return jm(
                            new JsRegex(
                                '^\\/api\\/my\\/admissions\\/[^/]+\\/documents$',
                                "",
                            ),
                            "test",
                            $route,
                        );
                    },
                );
            }),
            function () use (&$route, &$verb) {
                return js_and($verb === "PUT", function () use (&$route) {
                    return jm(
                        new JsRegex('^\\/api\\/(contracts|leave)\\/[^/]+$', ""),
                        "test",
                        $route,
                    );
                });
            },
        );
        if (
            js_truth(
                js_and($large, function () use (&$platform, &$actor) {
                    return !js_truth(
                        jm(g($platform, "store"), "run", function (
                            $tx = null,
                        ) use (&$actor) {
                            return rate_limit(
                                $tx,
                                js_add("upload:", g(g($actor, "user"), "id")),
                                30,
                                10 * 60 * 1000,
                            );
                        }),
                    );
                }),
            )
        ) {
            throw new HttpError(
                429,
                "Too many upload attempts. Please wait before retrying.",
                "RATE_LIMIT",
            );
        }
        $body = parse_form($req, js_truth($large) ? 7 * 1024 * 1024 : 100000);
        require_csrf($actor, $req, $body);
    }
    $parts = jm(jm($route, "split", "/"), "filter", "js_truth");
    $id = js_or(g($parts, 2), function () {
        return "";
    });
    $action = js_or(g($parts, 3), function () {
        return "";
    });
    if (g($parts, 1) === "my") {
        $id = g($parts, 3) ?? "";
    }

    $result = null;
    if (
        js_truth(
            js_and($verb === "GET", function () use (&$route) {
                return jm(
                    new JsRegex('^\\/api\\/downloads\\/[a-f0-9]{64}$', ""),
                    "test",
                    $route,
                );
            }),
        )
    ) {
        return a_platform_router_sendFile(
            $res,
            jm($platform, "privateDownload", $actor, $id),
        );
    }
    if (
        js_truth(
            js_and($route === "/api/my/admissions", function () use (&$verb) {
                return $verb === "GET";
            }),
        )
    ) {
        $result = jm($platform, "myAdmissions", $actor);
    } else {
        if (
            js_truth(
                js_and($route === "/api/my/admissions", function () use (
                    &$verb,
                ) {
                    return $verb === "POST";
                }),
            )
        ) {
            $result = jm($platform, "createMyAdmission", $actor, $body);
        } else {
            if (
                js_truth(
                    js_and(
                        jm(
                            new JsRegex(
                                '^\\/api\\/my\\/admissions\\/[^/]+$',
                                "",
                            ),
                            "test",
                            $route,
                        ),
                        function () use (&$verb) {
                            return $verb === "PUT";
                        },
                    ),
                )
            ) {
                $result = jm(
                    $platform,
                    "updateMyAdmission",
                    $actor,
                    $id,
                    $body,
                );
            } else {
                if (
                    js_truth(
                        js_and(
                            jm(
                                new JsRegex(
                                    '^\\/api\\/my\\/admissions\\/[^/]+\\/submit$',
                                    "",
                                ),
                                "test",
                                $route,
                            ),
                            function () use (&$verb) {
                                return $verb === "POST";
                            },
                        ),
                    )
                ) {
                    $result = jm(
                        $platform,
                        "submitMyAdmission",
                        $actor,
                        $id,
                        $body,
                    );
                } else {
                    if (
                        js_truth(
                            js_and(
                                jm(
                                    new JsRegex(
                                        '^\\/api\\/my\\/admissions\\/[^/]+\\/documents$',
                                        "",
                                    ),
                                    "test",
                                    $route,
                                ),
                                function () use (&$verb) {
                                    return $verb === "POST";
                                },
                            ),
                        )
                    ) {
                        $result = jm(
                            $platform,
                            "uploadAdmissionDocument",
                            $actor,
                            $id,
                            $body,
                        );
                    } else {
                        if (
                            js_truth(
                                js_and(
                                    jm(
                                        new JsRegex(
                                            '^\\/api\\/admission-documents\\/[^/]+\\/download-link$',
                                            "",
                                        ),
                                        "test",
                                        $route,
                                    ),
                                    function () use (&$verb) {
                                        return $verb === "POST";
                                    },
                                ),
                            )
                        ) {
                            $result = jm(
                                $platform,
                                "admissionDocumentGrant",
                                $actor,
                                $id,
                            );
                        } else {
                            if (
                                js_truth(
                                    js_and(
                                        $route === "/api/dashboard",
                                        function () use (&$verb) {
                                            return $verb === "GET";
                                        },
                                    ),
                                )
                            ) {
                                $result = jm($platform, "dashboard", $actor);
                            } else {
                                if (
                                    js_truth(
                                        js_and(
                                            $route === "/api/family",
                                            function () use (&$verb) {
                                                return $verb === "GET";
                                            },
                                        ),
                                    )
                                ) {
                                    $result = jm($platform, "family", $actor);
                                } else {
                                    if (
                                        js_truth(
                                            js_and(
                                                $route === "/api/students",
                                                function () use (&$verb) {
                                                    return $verb === "GET";
                                                },
                                            ),
                                        )
                                    ) {
                                        $result = jm(
                                            $platform,
                                            "students",
                                            $actor,
                                        );
                                    } else {
                                        if (
                                            js_truth(
                                                js_and(
                                                    $route === "/api/students",
                                                    function () use (&$verb) {
                                                        return $verb === "POST";
                                                    },
                                                ),
                                            )
                                        ) {
                                            $result = jm(
                                                $platform,
                                                "saveStudent",
                                                $actor,
                                                $body,
                                            );
                                        } else {
                                            if (
                                                js_truth(
                                                    js_and(
                                                        jm(
                                                            new JsRegex(
                                                                '^\\/api\\/students\\/[^/]+$',
                                                                "",
                                                            ),
                                                            "test",
                                                            $route,
                                                        ),
                                                        function () use (
                                                            &$verb,
                                                        ) {
                                                            return $verb ===
                                                                "PUT";
                                                        },
                                                    ),
                                                )
                                            ) {
                                                $result = jm(
                                                    $platform,
                                                    "saveStudent",
                                                    $actor,
                                                    $body,
                                                    $id,
                                                );
                                            } else {
                                                if (
                                                    js_truth(
                                                        js_and(
                                                            jm(
                                                                new JsRegex(
                                                                    '^\\/api\\/students\\/[^/]+\\/guardians$',
                                                                    "",
                                                                ),
                                                                "test",
                                                                $route,
                                                            ),
                                                            function () use (
                                                                &$verb,
                                                            ) {
                                                                return $verb ===
                                                                    "POST";
                                                            },
                                                        ),
                                                    )
                                                ) {
                                                    $result = jm(
                                                        $platform,
                                                        "verifyGuardian",
                                                        $actor,
                                                        $id,
                                                        $body,
                                                    );
                                                } else {
                                                    if (
                                                        js_truth(
                                                            js_and(
                                                                $route ===
                                                                    "/api/classes",
                                                                function () use (
                                                                    &$verb,
                                                                ) {
                                                                    return $verb ===
                                                                        "GET";
                                                                },
                                                            ),
                                                        )
                                                    ) {
                                                        $result = jm(
                                                            $platform,
                                                            "classes",
                                                            $actor,
                                                        );
                                                    } else {
                                                        if (
                                                            js_truth(
                                                                js_and(
                                                                    $route ===
                                                                        "/api/classes",
                                                                    function () use (
                                                                        &$verb,
                                                                    ) {
                                                                        return $verb ===
                                                                            "POST";
                                                                    },
                                                                ),
                                                            )
                                                        ) {
                                                            $result = jm(
                                                                $platform,
                                                                "saveClass",
                                                                $actor,
                                                                $body,
                                                            );
                                                        } else {
                                                            if (
                                                                js_truth(
                                                                    js_and(
                                                                        jm(
                                                                            new JsRegex(
                                                                                '^\\/api\\/classes\\/[^/]+$',
                                                                                "",
                                                                            ),
                                                                            "test",
                                                                            $route,
                                                                        ),
                                                                        function () use (
                                                                            &$verb,
                                                                        ) {
                                                                            return $verb ===
                                                                                "PUT";
                                                                        },
                                                                    ),
                                                                )
                                                            ) {
                                                                $result = jm(
                                                                    $platform,
                                                                    "saveClass",
                                                                    $actor,
                                                                    $body,
                                                                    $id,
                                                                );
                                                            } else {
                                                                if (
                                                                    js_truth(
                                                                        js_and(
                                                                            $route ===
                                                                                "/api/class-teachers",
                                                                            function () use (
                                                                                &$verb,
                                                                            ) {
                                                                                return $verb ===
                                                                                    "POST";
                                                                            },
                                                                        ),
                                                                    )
                                                                ) {
                                                                    $result = jm(
                                                                        $platform,
                                                                        "assignTeacher",
                                                                        $actor,
                                                                        $body,
                                                                    );
                                                                } else {
                                                                    if (
                                                                        js_truth(
                                                                            js_and(
                                                                                $route ===
                                                                                    "/api/attendance",
                                                                                function () use (
                                                                                    &$verb,
                                                                                ) {
                                                                                    return $verb ===
                                                                                        "GET";
                                                                                },
                                                                            ),
                                                                        )
                                                                    ) {
                                                                        $result = jm(
                                                                            $platform,
                                                                            "attendance",
                                                                            $actor,
                                                                            js_or(
                                                                                jm(
                                                                                    g(
                                                                                        $url,
                                                                                        "searchParams",
                                                                                    ),
                                                                                    "get",
                                                                                    "studentId",
                                                                                ),
                                                                                function () {
                                                                                    return "";
                                                                                },
                                                                            ),
                                                                        );
                                                                    } else {
                                                                        if (
                                                                            js_truth(
                                                                                js_and(
                                                                                    $route ===
                                                                                        "/api/attendance",
                                                                                    function () use (
                                                                                        &$verb,
                                                                                    ) {
                                                                                        return $verb ===
                                                                                            "POST";
                                                                                    },
                                                                                ),
                                                                            )
                                                                        ) {
                                                                            $result = jm(
                                                                                $platform,
                                                                                "recordAttendance",
                                                                                $actor,
                                                                                $body,
                                                                            );
                                                                        } else {
                                                                            if (
                                                                                js_truth(
                                                                                    js_and(
                                                                                        $route ===
                                                                                            "/api/results",
                                                                                        function () use (
                                                                                            &$verb,
                                                                                        ) {
                                                                                            return $verb ===
                                                                                                "GET";
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            ) {
                                                                                $result = jm(
                                                                                    $platform,
                                                                                    "results",
                                                                                    $actor,
                                                                                    js_or(
                                                                                        jm(
                                                                                            g(
                                                                                                $url,
                                                                                                "searchParams",
                                                                                            ),
                                                                                            "get",
                                                                                            "studentId",
                                                                                        ),
                                                                                        function () {
                                                                                            return "";
                                                                                        },
                                                                                    ),
                                                                                );
                                                                            } else {
                                                                                if (
                                                                                    js_truth(
                                                                                        js_and(
                                                                                            $route ===
                                                                                                "/api/results",
                                                                                            function () use (
                                                                                                &$verb,
                                                                                            ) {
                                                                                                return $verb ===
                                                                                                    "POST";
                                                                                            },
                                                                                        ),
                                                                                    )
                                                                                ) {
                                                                                    $result = jm(
                                                                                        $platform,
                                                                                        "saveResult",
                                                                                        $actor,
                                                                                        $body,
                                                                                    );
                                                                                } else {
                                                                                    if (
                                                                                        js_truth(
                                                                                            js_and(
                                                                                                jm(
                                                                                                    new JsRegex(
                                                                                                        '^\\/api\\/results\\/[^/]+\\/publish$',
                                                                                                        "",
                                                                                                    ),
                                                                                                    "test",
                                                                                                    $route,
                                                                                                ),
                                                                                                function () use (
                                                                                                    &$verb,
                                                                                                ) {
                                                                                                    return $verb ===
                                                                                                        "POST";
                                                                                                },
                                                                                            ),
                                                                                        )
                                                                                    ) {
                                                                                        $result = jm(
                                                                                            $platform,
                                                                                            "publishResult",
                                                                                            $actor,
                                                                                            $id,
                                                                                            $body,
                                                                                        );
                                                                                    } else {
                                                                                        if (
                                                                                            js_truth(
                                                                                                js_and(
                                                                                                    $route ===
                                                                                                        "/api/learning-materials",
                                                                                                    function () use (
                                                                                                        &$verb,
                                                                                                    ) {
                                                                                                        return $verb ===
                                                                                                            "GET";
                                                                                                    },
                                                                                                ),
                                                                                            )
                                                                                        ) {
                                                                                            $result = jm(
                                                                                                $platform,
                                                                                                "materials",
                                                                                                $actor,
                                                                                            );
                                                                                        } else {
                                                                                            if (
                                                                                                js_truth(
                                                                                                    js_and(
                                                                                                        $route ===
                                                                                                            "/api/learning-materials",
                                                                                                        function () use (
                                                                                                            &$verb,
                                                                                                        ) {
                                                                                                            return $verb ===
                                                                                                                "POST";
                                                                                                        },
                                                                                                    ),
                                                                                                )
                                                                                            ) {
                                                                                                $result = jm(
                                                                                                    $platform,
                                                                                                    "saveMaterial",
                                                                                                    $actor,
                                                                                                    $body,
                                                                                                );
                                                                                            } else {
                                                                                                if (
                                                                                                    js_truth(
                                                                                                        js_and(
                                                                                                            jm(
                                                                                                                new JsRegex(
                                                                                                                    '^\\/api\\/learning-materials\\/[^/]+\\/publish$',
                                                                                                                    "",
                                                                                                                ),
                                                                                                                "test",
                                                                                                                $route,
                                                                                                            ),
                                                                                                            function () use (
                                                                                                                &$verb,
                                                                                                            ) {
                                                                                                                return $verb ===
                                                                                                                    "POST";
                                                                                                            },
                                                                                                        ),
                                                                                                    )
                                                                                                ) {
                                                                                                    $result = jm(
                                                                                                        $platform,
                                                                                                        "publishMaterial",
                                                                                                        $actor,
                                                                                                        $id,
                                                                                                        $body,
                                                                                                    );
                                                                                                } else {
                                                                                                    if (
                                                                                                        js_truth(
                                                                                                            js_and(
                                                                                                                $route ===
                                                                                                                    "/api/family/messages",
                                                                                                                function () use (
                                                                                                                    &$verb,
                                                                                                                ) {
                                                                                                                    return $verb ===
                                                                                                                        "GET";
                                                                                                                },
                                                                                                            ),
                                                                                                        )
                                                                                                    ) {
                                                                                                        $result = jm(
                                                                                                            $platform,
                                                                                                            "familyMessages",
                                                                                                            $actor,
                                                                                                            js_or(
                                                                                                                jm(
                                                                                                                    g(
                                                                                                                        $url,
                                                                                                                        "searchParams",
                                                                                                                    ),
                                                                                                                    "get",
                                                                                                                    "studentId",
                                                                                                                ),
                                                                                                                function () {
                                                                                                                    return "";
                                                                                                                },
                                                                                                            ),
                                                                                                        );
                                                                                                    } else {
                                                                                                        if (
                                                                                                            js_truth(
                                                                                                                js_and(
                                                                                                                    $route ===
                                                                                                                        "/api/family/messages",
                                                                                                                    function () use (
                                                                                                                        &$verb,
                                                                                                                    ) {
                                                                                                                        return $verb ===
                                                                                                                            "POST";
                                                                                                                    },
                                                                                                                ),
                                                                                                            )
                                                                                                        ) {
                                                                                                            $result = jm(
                                                                                                                $platform,
                                                                                                                "sendFamilyMessage",
                                                                                                                $actor,
                                                                                                                $body,
                                                                                                            );
                                                                                                        } else {
                                                                                                            if (
                                                                                                                js_truth(
                                                                                                                    js_and(
                                                                                                                        $route ===
                                                                                                                            "/api/family/contacts",
                                                                                                                        function () use (
                                                                                                                            &$verb,
                                                                                                                        ) {
                                                                                                                            return $verb ===
                                                                                                                                "GET";
                                                                                                                        },
                                                                                                                    ),
                                                                                                                )
                                                                                                            ) {
                                                                                                                $result = jm(
                                                                                                                    $platform,
                                                                                                                    "familyContacts",
                                                                                                                    $actor,
                                                                                                                    js_or(
                                                                                                                        jm(
                                                                                                                            g(
                                                                                                                                $url,
                                                                                                                                "searchParams",
                                                                                                                            ),
                                                                                                                            "get",
                                                                                                                            "studentId",
                                                                                                                        ),
                                                                                                                        function () {
                                                                                                                            return "";
                                                                                                                        },
                                                                                                                    ),
                                                                                                                );
                                                                                                            } else {
                                                                                                                if (
                                                                                                                    js_truth(
                                                                                                                        js_and(
                                                                                                                            $route ===
                                                                                                                                "/api/meta",
                                                                                                                            function () use (
                                                                                                                                &$verb,
                                                                                                                            ) {
                                                                                                                                return $verb ===
                                                                                                                                    "GET";
                                                                                                                            },
                                                                                                                        ),
                                                                                                                    )
                                                                                                                ) {
                                                                                                                    $result = jm(
                                                                                                                        $platform,
                                                                                                                        "metadata",
                                                                                                                        $actor,
                                                                                                                    );
                                                                                                                } else {
                                                                                                                    if (
                                                                                                                        js_truth(
                                                                                                                            js_and(
                                                                                                                                $route ===
                                                                                                                                    "/api/permissions",
                                                                                                                                function () use (
                                                                                                                                    &$verb,
                                                                                                                                ) {
                                                                                                                                    return $verb ===
                                                                                                                                        "GET";
                                                                                                                                },
                                                                                                                            ),
                                                                                                                        )
                                                                                                                    ) {
                                                                                                                        $result = a_platform_access_permissions(
                                                                                                                            g(
                                                                                                                                $actor,
                                                                                                                                "user",
                                                                                                                            ),
                                                                                                                        );
                                                                                                                    } else {
                                                                                                                        if (
                                                                                                                            js_truth(
                                                                                                                                js_and(
                                                                                                                                    $route ===
                                                                                                                                        "/api/roles",
                                                                                                                                    function () use (
                                                                                                                                        &$verb,
                                                                                                                                    ) {
                                                                                                                                        return $verb ===
                                                                                                                                            "GET";
                                                                                                                                    },
                                                                                                                                ),
                                                                                                                            )
                                                                                                                        ) {
                                                                                                                            a_platform_access_requirePermission(
                                                                                                                                g(
                                                                                                                                    $actor,
                                                                                                                                    "user",
                                                                                                                                ),
                                                                                                                                "role.grant",
                                                                                                                            );
                                                                                                                            $result = a_platform_access_ROLE_PERMISSIONS;
                                                                                                                        } else {
                                                                                                                            if (
                                                                                                                                js_truth(
                                                                                                                                    js_and(
                                                                                                                                        $route ===
                                                                                                                                            "/api/users",
                                                                                                                                        function () use (
                                                                                                                                            &$verb,
                                                                                                                                        ) {
                                                                                                                                            return $verb ===
                                                                                                                                                "GET";
                                                                                                                                        },
                                                                                                                                    ),
                                                                                                                                )
                                                                                                                            ) {
                                                                                                                                $result = jm(
                                                                                                                                    $platform,
                                                                                                                                    "users",
                                                                                                                                    $actor,
                                                                                                                                );
                                                                                                                            } else {
                                                                                                                                if (
                                                                                                                                    js_truth(
                                                                                                                                        js_and(
                                                                                                                                            $route ===
                                                                                                                                                "/api/users",
                                                                                                                                            function () use (
                                                                                                                                                &$verb,
                                                                                                                                            ) {
                                                                                                                                                return $verb ===
                                                                                                                                                    "POST";
                                                                                                                                            },
                                                                                                                                        ),
                                                                                                                                    )
                                                                                                                                ) {
                                                                                                                                    $result = jm(
                                                                                                                                        $platform,
                                                                                                                                        "createUser",
                                                                                                                                        $actor,
                                                                                                                                        $body,
                                                                                                                                    );
                                                                                                                                } else {
                                                                                                                                    if (
                                                                                                                                        js_truth(
                                                                                                                                            js_and(
                                                                                                                                                jm(
                                                                                                                                                    new JsRegex(
                                                                                                                                                        '^\\/api\\/users\\/[^/]+$',
                                                                                                                                                        "",
                                                                                                                                                    ),
                                                                                                                                                    "test",
                                                                                                                                                    $route,
                                                                                                                                                ),
                                                                                                                                                function () use (
                                                                                                                                                    &$verb,
                                                                                                                                                ) {
                                                                                                                                                    return $verb ===
                                                                                                                                                        "PATCH";
                                                                                                                                                },
                                                                                                                                            ),
                                                                                                                                        )
                                                                                                                                    ) {
                                                                                                                                        $result = jm(
                                                                                                                                            $platform,
                                                                                                                                            "changeUser",
                                                                                                                                            $actor,
                                                                                                                                            $id,
                                                                                                                                            $body,
                                                                                                                                        );
                                                                                                                                    } else {
                                                                                                                                        if (
                                                                                                                                            js_truth(
                                                                                                                                                js_and(
                                                                                                                                                    $route ===
                                                                                                                                                        "/api/staff",
                                                                                                                                                    function () use (
                                                                                                                                                        &$verb,
                                                                                                                                                    ) {
                                                                                                                                                        return $verb ===
                                                                                                                                                            "GET";
                                                                                                                                                    },
                                                                                                                                                ),
                                                                                                                                            )
                                                                                                                                        ) {
                                                                                                                                            $result = jm(
                                                                                                                                                $platform,
                                                                                                                                                "staff",
                                                                                                                                                $actor,
                                                                                                                                            );
                                                                                                                                        } else {
                                                                                                                                            if (
                                                                                                                                                js_truth(
                                                                                                                                                    js_and(
                                                                                                                                                        $route ===
                                                                                                                                                            "/api/staff/directory",
                                                                                                                                                        function () use (
                                                                                                                                                            &$verb,
                                                                                                                                                        ) {
                                                                                                                                                            return $verb ===
                                                                                                                                                                "GET";
                                                                                                                                                        },
                                                                                                                                                    ),
                                                                                                                                                )
                                                                                                                                            ) {
                                                                                                                                                $result = jm(
                                                                                                                                                    $platform,
                                                                                                                                                    "staff",
                                                                                                                                                    $actor,
                                                                                                                                                    true,
                                                                                                                                                );
                                                                                                                                            } else {
                                                                                                                                                if (
                                                                                                                                                    js_truth(
                                                                                                                                                        js_and(
                                                                                                                                                            jm(
                                                                                                                                                                new JsRegex(
                                                                                                                                                                    '^\\/api\\/staff\\/[^/]+$',
                                                                                                                                                                    "",
                                                                                                                                                                ),
                                                                                                                                                                "test",
                                                                                                                                                                $route,
                                                                                                                                                            ),
                                                                                                                                                            function () use (
                                                                                                                                                                &$verb,
                                                                                                                                                            ) {
                                                                                                                                                                return $verb ===
                                                                                                                                                                    "PUT";
                                                                                                                                                            },
                                                                                                                                                        ),
                                                                                                                                                    )
                                                                                                                                                ) {
                                                                                                                                                    $result = jm(
                                                                                                                                                        $platform,
                                                                                                                                                        "saveStaff",
                                                                                                                                                        $actor,
                                                                                                                                                        $id,
                                                                                                                                                        $body,
                                                                                                                                                    );
                                                                                                                                                } else {
                                                                                                                                                    if (
                                                                                                                                                        js_truth(
                                                                                                                                                            js_and(
                                                                                                                                                                $route ===
                                                                                                                                                                    "/api/departments",
                                                                                                                                                                function () use (
                                                                                                                                                                    &$verb,
                                                                                                                                                                ) {
                                                                                                                                                                    return $verb ===
                                                                                                                                                                        "GET";
                                                                                                                                                                },
                                                                                                                                                            ),
                                                                                                                                                        )
                                                                                                                                                    ) {
                                                                                                                                                        $result = g(
                                                                                                                                                            jm(
                                                                                                                                                                $platform,
                                                                                                                                                                "metadata",
                                                                                                                                                                $actor,
                                                                                                                                                            ),
                                                                                                                                                            "departments",
                                                                                                                                                        );
                                                                                                                                                    } else {
                                                                                                                                                        if (
                                                                                                                                                            js_truth(
                                                                                                                                                                js_and(
                                                                                                                                                                    $route ===
                                                                                                                                                                        "/api/leave/balances",
                                                                                                                                                                    function () use (
                                                                                                                                                                        &$verb,
                                                                                                                                                                    ) {
                                                                                                                                                                        return $verb ===
                                                                                                                                                                            "GET";
                                                                                                                                                                    },
                                                                                                                                                                ),
                                                                                                                                                            )
                                                                                                                                                        ) {
                                                                                                                                                            $result = jm(
                                                                                                                                                                $platform,
                                                                                                                                                                "balances",
                                                                                                                                                                $actor,
                                                                                                                                                            );
                                                                                                                                                        } else {
                                                                                                                                                            if (
                                                                                                                                                                js_truth(
                                                                                                                                                                    js_and(
                                                                                                                                                                        $route ===
                                                                                                                                                                            "/api/leave/balances",
                                                                                                                                                                        function () use (
                                                                                                                                                                            &$verb,
                                                                                                                                                                        ) {
                                                                                                                                                                            return $verb ===
                                                                                                                                                                                "POST";
                                                                                                                                                                        },
                                                                                                                                                                    ),
                                                                                                                                                                )
                                                                                                                                                            ) {
                                                                                                                                                                $result = jm(
                                                                                                                                                                    $platform,
                                                                                                                                                                    "setBalance",
                                                                                                                                                                    $actor,
                                                                                                                                                                    $body,
                                                                                                                                                                );
                                                                                                                                                            } else {
                                                                                                                                                                if (
                                                                                                                                                                    js_truth(
                                                                                                                                                                        js_and(
                                                                                                                                                                            jm(
                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                    '^\\/api\\/leave\\/types\\/[^/]+$',
                                                                                                                                                                                    "",
                                                                                                                                                                                ),
                                                                                                                                                                                "test",
                                                                                                                                                                                $route,
                                                                                                                                                                            ),
                                                                                                                                                                            function () use (
                                                                                                                                                                                &$verb,
                                                                                                                                                                            ) {
                                                                                                                                                                                return $verb ===
                                                                                                                                                                                    "PUT";
                                                                                                                                                                            },
                                                                                                                                                                        ),
                                                                                                                                                                    )
                                                                                                                                                                ) {
                                                                                                                                                                    $result = jm(
                                                                                                                                                                        $platform,
                                                                                                                                                                        "configureLeaveType",
                                                                                                                                                                        $actor,
                                                                                                                                                                        $action,
                                                                                                                                                                        $body,
                                                                                                                                                                    );
                                                                                                                                                                } else {
                                                                                                                                                                    if (
                                                                                                                                                                        js_truth(
                                                                                                                                                                            js_and(
                                                                                                                                                                                $route ===
                                                                                                                                                                                    "/api/leave",
                                                                                                                                                                                function () use (
                                                                                                                                                                                    &$verb,
                                                                                                                                                                                ) {
                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                        "GET";
                                                                                                                                                                                },
                                                                                                                                                                            ),
                                                                                                                                                                        )
                                                                                                                                                                    ) {
                                                                                                                                                                        $result = jm(
                                                                                                                                                                            $platform,
                                                                                                                                                                            "leaves",
                                                                                                                                                                            $actor,
                                                                                                                                                                        );
                                                                                                                                                                    } else {
                                                                                                                                                                        if (
                                                                                                                                                                            js_truth(
                                                                                                                                                                                js_and(
                                                                                                                                                                                    $route ===
                                                                                                                                                                                        "/api/leave",
                                                                                                                                                                                    function () use (
                                                                                                                                                                                        &$verb,
                                                                                                                                                                                    ) {
                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                            "POST";
                                                                                                                                                                                    },
                                                                                                                                                                                ),
                                                                                                                                                                            )
                                                                                                                                                                        ) {
                                                                                                                                                                            $result = jm(
                                                                                                                                                                                $platform,
                                                                                                                                                                                "createLeave",
                                                                                                                                                                                $actor,
                                                                                                                                                                                $body,
                                                                                                                                                                            );
                                                                                                                                                                        } else {
                                                                                                                                                                            if (
                                                                                                                                                                                js_truth(
                                                                                                                                                                                    js_and(
                                                                                                                                                                                        jm(
                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                '^\\/api\\/leave\\/[^/]+$',
                                                                                                                                                                                                "",
                                                                                                                                                                                            ),
                                                                                                                                                                                            "test",
                                                                                                                                                                                            $route,
                                                                                                                                                                                        ),
                                                                                                                                                                                        function () use (
                                                                                                                                                                                            &$verb,
                                                                                                                                                                                        ) {
                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                "PUT";
                                                                                                                                                                                        },
                                                                                                                                                                                    ),
                                                                                                                                                                                )
                                                                                                                                                                            ) {
                                                                                                                                                                                $result = jm(
                                                                                                                                                                                    $platform,
                                                                                                                                                                                    "editLeave",
                                                                                                                                                                                    $actor,
                                                                                                                                                                                    $id,
                                                                                                                                                                                    $body,
                                                                                                                                                                                );
                                                                                                                                                                            } else {
                                                                                                                                                                                if (
                                                                                                                                                                                    js_truth(
                                                                                                                                                                                        js_and(
                                                                                                                                                                                            jm(
                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                    '^\\/api\\/leave\\/[^/]+\\/action$',
                                                                                                                                                                                                    "",
                                                                                                                                                                                                ),
                                                                                                                                                                                                "test",
                                                                                                                                                                                                $route,
                                                                                                                                                                                            ),
                                                                                                                                                                                            function () use (
                                                                                                                                                                                                &$verb,
                                                                                                                                                                                            ) {
                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                    "POST";
                                                                                                                                                                                            },
                                                                                                                                                                                        ),
                                                                                                                                                                                    )
                                                                                                                                                                                ) {
                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                        $platform,
                                                                                                                                                                                        "leaveAction",
                                                                                                                                                                                        $actor,
                                                                                                                                                                                        $id,
                                                                                                                                                                                        $body,
                                                                                                                                                                                    );
                                                                                                                                                                                } else {
                                                                                                                                                                                    if (
                                                                                                                                                                                        js_truth(
                                                                                                                                                                                            js_and(
                                                                                                                                                                                                $route ===
                                                                                                                                                                                                    "/api/contracts",
                                                                                                                                                                                                function () use (
                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                ) {
                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                },
                                                                                                                                                                                            ),
                                                                                                                                                                                        )
                                                                                                                                                                                    ) {
                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                            $platform,
                                                                                                                                                                                            "contracts",
                                                                                                                                                                                            $actor,
                                                                                                                                                                                        );
                                                                                                                                                                                    } else {
                                                                                                                                                                                        if (
                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                js_and(
                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                        "/api/contracts",
                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                    ) {
                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                    },
                                                                                                                                                                                                ),
                                                                                                                                                                                            )
                                                                                                                                                                                        ) {
                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                $platform,
                                                                                                                                                                                                "createContract",
                                                                                                                                                                                                $actor,
                                                                                                                                                                                                $body,
                                                                                                                                                                                            );
                                                                                                                                                                                        } else {
                                                                                                                                                                                            if (
                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                        jm(
                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                '^\\/api\\/contracts\\/[^/]+$',
                                                                                                                                                                                                                "",
                                                                                                                                                                                                            ),
                                                                                                                                                                                                            "test",
                                                                                                                                                                                                            $route,
                                                                                                                                                                                                        ),
                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                        ) {
                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                "PUT";
                                                                                                                                                                                                        },
                                                                                                                                                                                                    ),
                                                                                                                                                                                                )
                                                                                                                                                                                            ) {
                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                    "reviseContract",
                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                    $id,
                                                                                                                                                                                                    $body,
                                                                                                                                                                                                );
                                                                                                                                                                                            } else {
                                                                                                                                                                                                if (
                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                            jm(
                                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                                    '^\\/api\\/contracts\\/[^/]+\\/submit$',
                                                                                                                                                                                                                    "",
                                                                                                                                                                                                                ),
                                                                                                                                                                                                                "test",
                                                                                                                                                                                                                $route,
                                                                                                                                                                                                            ),
                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                    "POST";
                                                                                                                                                                                                            },
                                                                                                                                                                                                        ),
                                                                                                                                                                                                    )
                                                                                                                                                                                                ) {
                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                        "contractSubmit",
                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                        $id,
                                                                                                                                                                                                        $body,
                                                                                                                                                                                                    );
                                                                                                                                                                                                } else {
                                                                                                                                                                                                    if (
                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                    "/api/documents",
                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                },
                                                                                                                                                                                                            ),
                                                                                                                                                                                                        )
                                                                                                                                                                                                    ) {
                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                            "documents",
                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                        );
                                                                                                                                                                                                    } else {
                                                                                                                                                                                                        if (
                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                        "/api/documents",
                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                                    },
                                                                                                                                                                                                                ),
                                                                                                                                                                                                            )
                                                                                                                                                                                                        ) {
                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                "createDocument",
                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                $body,
                                                                                                                                                                                                            );
                                                                                                                                                                                                        } else {
                                                                                                                                                                                                            if (
                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                '^\\/api\\/documents\\/[^/]+\\/versions$',
                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                        },
                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                )
                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                    "createDocument",
                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                    $id,
                                                                                                                                                                                                                );
                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                if (
                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                            jm(
                                                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                                                    '^\\/api\\/documents\\/[^/]+\\/action$',
                                                                                                                                                                                                                                    "",
                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                "test",
                                                                                                                                                                                                                                $route,
                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                    "POST";
                                                                                                                                                                                                                            },
                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                    )
                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                        "documentAction",
                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                        $id,
                                                                                                                                                                                                                        $body,
                                                                                                                                                                                                                    );
                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                jm(
                                                                                                                                                                                                                                    new JsRegex(
                                                                                                                                                                                                                                        '^\\/api\\/(documents|contracts|leave|media)\\/[^/]+\\/download-link$',
                                                                                                                                                                                                                                        "",
                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                    "test",
                                                                                                                                                                                                                                    $route,
                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                        "POST";
                                                                                                                                                                                                                                },
                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                        )
                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                            "downloadGrant",
                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                            g(
                                                                                                                                                                                                                                [
                                                                                                                                                                                                                                    "documents" =>
                                                                                                                                                                                                                                        "document",
                                                                                                                                                                                                                                    "contracts" =>
                                                                                                                                                                                                                                        "contract",
                                                                                                                                                                                                                                    "leave" =>
                                                                                                                                                                                                                                        "leave",
                                                                                                                                                                                                                                    "media" =>
                                                                                                                                                                                                                                        "media",
                                                                                                                                                                                                                                ],
                                                                                                                                                                                                                                g(
                                                                                                                                                                                                                                    $parts,
                                                                                                                                                                                                                                    1,
                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                            $id,
                                                                                                                                                                                                                        );
                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                        "/api/approvals",
                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                            "GET";
                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                            )
                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                "approvalQueue",
                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                            );
                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                '^\\/api\\/approvals\\/[^/]+\\/decision$',
                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                )
                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                    "decide",
                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                    $id,
                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                );
                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                "/api/requests",
                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                        "requests",
                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                    "/api/workflows",
                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                            "definitions",
                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                                        "/api/workflows",
                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                "createWorkflow",
                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                '^\\/api\\/workflows\\/[^/]+$',
                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                "PUT";
                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                    "configureWorkflow",
                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                    $id,
                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                                "/api/notices",
                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                        "notices",
                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                    "/api/notices",
                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                        "POST";
                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                            "saveNotice",
                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                            $body,
                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                    jm(
                                                                                                                                                                                                                                                                        new JsRegex(
                                                                                                                                                                                                                                                                            '^\\/api\\/notices\\/[^/]+$',
                                                                                                                                                                                                                                                                            "",
                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                        "test",
                                                                                                                                                                                                                                                                        $route,
                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                            "PUT";
                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                "saveNotice",
                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                $id,
                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                                '^\\/api\\/notices\\/[^/]+\\/publish$',
                                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                    "publishNotice",
                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                    $id,
                                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                            jm(
                                                                                                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                                                                                                    '^\\/api\\/notices\\/[^/]+\\/acknowledge$',
                                                                                                                                                                                                                                                                                    "",
                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                "test",
                                                                                                                                                                                                                                                                                $route,
                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                    "POST";
                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                        "acknowledgeNotice",
                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                        $id,
                                                                                                                                                                                                                                                                        $body,
                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                jm(
                                                                                                                                                                                                                                                                                    new JsRegex(
                                                                                                                                                                                                                                                                                        '^\\/api\\/notices\\/[^/]+\\/report$',
                                                                                                                                                                                                                                                                                        "",
                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                    "test",
                                                                                                                                                                                                                                                                                    $route,
                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                            "noticeReport",
                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                            $id,
                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                                                                        "/api/calendar",
                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                            "GET";
                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                "calendar",
                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                        $route ===
                                                                                                                                                                                                                                                                                            "/api/calendar",
                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                    "createEvent",
                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                                                                "/api/cms",
                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                        "cms",
                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                                                    "/api/cms",
                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                        "POST";
                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                            "saveContent",
                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                            $body,
                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                    jm(
                                                                                                                                                                                                                                                                                                        new JsRegex(
                                                                                                                                                                                                                                                                                                            '^\\/api\\/cms\\/[^/]+$',
                                                                                                                                                                                                                                                                                                            "",
                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                        "test",
                                                                                                                                                                                                                                                                                                        $route,
                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                            "PUT";
                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                "saveContent",
                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                                                $id,
                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                                                                '^\\/api\\/cms\\/[^/]+\\/action$',
                                                                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                    "contentAction",
                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                    $id,
                                                                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                                                                                "/api/admissions",
                                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                                        "enquiries",
                                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                                        true,
                                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                                                                    "/api/submissions",
                                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                                            "enquiries",
                                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                                            false,
                                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                                    jm(
                                                                                                                                                                                                                                                                                                                        new JsRegex(
                                                                                                                                                                                                                                                                                                                            '^\\/api\\/(admissions|submissions)\\/[^/]+$',
                                                                                                                                                                                                                                                                                                                            "",
                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                        "test",
                                                                                                                                                                                                                                                                                                                        $route,
                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                                            "PATCH";
                                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                                "changeEnquiry",
                                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                                $id,
                                                                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                                                                g(
                                                                                                                                                                                                                                                                                                                    $parts,
                                                                                                                                                                                                                                                                                                                    1,
                                                                                                                                                                                                                                                                                                                ) ===
                                                                                                                                                                                                                                                                                                                    "admissions",
                                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                                        $route ===
                                                                                                                                                                                                                                                                                                                            "/api/notifications",
                                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                                "GET";
                                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                                    "notifications",
                                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                                                            jm(
                                                                                                                                                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                                                                                                                                                    '^\\/api\\/notifications\\/[^/]+\\/read$',
                                                                                                                                                                                                                                                                                                                                    "",
                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                "test",
                                                                                                                                                                                                                                                                                                                                $route,
                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                                                    "POST";
                                                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                                                        "readNotification",
                                                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                                                        $id,
                                                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                                                                                    "/api/privacy",
                                                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                                                            "privacyRecords",
                                                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                                                                                                                        "/api/privacy/consents",
                                                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                                                "saveConsent",
                                                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                                                                                                '^\\/api\\/privacy\\/consents\\/[^/]+\\/withdraw$',
                                                                                                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                                                    "withdrawConsent",
                                                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                                                    g(
                                                                                                                                                                                                                                                                                                                                        $parts,
                                                                                                                                                                                                                                                                                                                                        3,
                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                                                                            jm(
                                                                                                                                                                                                                                                                                                                                                new JsRegex(
                                                                                                                                                                                                                                                                                                                                                    '^\\/api\\/privacy\\/consents\\/[^/]+\\/removal$',
                                                                                                                                                                                                                                                                                                                                                    "",
                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                                "test",
                                                                                                                                                                                                                                                                                                                                                $route,
                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                                                                    "POST";
                                                                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                                                                        "confirmConsentRemoval",
                                                                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                                                                        g(
                                                                                                                                                                                                                                                                                                                                            $parts,
                                                                                                                                                                                                                                                                                                                                            3,
                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                        $body,
                                                                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                                                                jm(
                                                                                                                                                                                                                                                                                                                                                    new JsRegex(
                                                                                                                                                                                                                                                                                                                                                        '^\\/api\\/privacy\\/requests\\/[^/]+$',
                                                                                                                                                                                                                                                                                                                                                        "",
                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                    "test",
                                                                                                                                                                                                                                                                                                                                                    $route,
                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                                                                        "PATCH";
                                                                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                                                                            "resolvePrivacyRequest",
                                                                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                                                                            g(
                                                                                                                                                                                                                                                                                                                                                $parts,
                                                                                                                                                                                                                                                                                                                                                3,
                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                            $body,
                                                                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                                                                                                                                        "/api/privacy/incidents",
                                                                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                                                                "createIncident",
                                                                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                                                                                                                '^\\/api\\/privacy\\/incidents\\/[^/]+$',
                                                                                                                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                                                                "PATCH";
                                                                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                                                                    "updateIncident",
                                                                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                                                                    g(
                                                                                                                                                                                                                                                                                                                                                        $parts,
                                                                                                                                                                                                                                                                                                                                                        3,
                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                    $body,
                                                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                                                                                                                                "/api/media",
                                                                                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                                                                                        "media",
                                                                                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                                                                                                                    "/api/media",
                                                                                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                                                                                        "POST";
                                                                                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                                                                                            "createMedia",
                                                                                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                                                                                            $body,
                                                                                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                                                                                    jm(
                                                                                                                                                                                                                                                                                                                                                                        new JsRegex(
                                                                                                                                                                                                                                                                                                                                                                            '^\\/api\\/media\\/[^/]+\\/action$',
                                                                                                                                                                                                                                                                                                                                                                            "",
                                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                                        "test",
                                                                                                                                                                                                                                                                                                                                                                        $route,
                                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                                                                                            "POST";
                                                                                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                                                                                "mediaAction",
                                                                                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                                                                                $id,
                                                                                                                                                                                                                                                                                                                                                                $body,
                                                                                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                                                                                        $route ===
                                                                                                                                                                                                                                                                                                                                                                            "/api/audit",
                                                                                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                                                                                "GET";
                                                                                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                                                                                    "auditLog",
                                                                                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                                                                                    js_object_fromEntries(
                                                                                                                                                                                                                                                                                                                                                                        g(
                                                                                                                                                                                                                                                                                                                                                                            $url,
                                                                                                                                                                                                                                                                                                                                                                            "searchParams",
                                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                                                                                if (
                                                                                                                                                                                                                                                                                                                                                                    js_truth(
                                                                                                                                                                                                                                                                                                                                                                        js_and(
                                                                                                                                                                                                                                                                                                                                                                            $route ===
                                                                                                                                                                                                                                                                                                                                                                                "/api/reports",
                                                                                                                                                                                                                                                                                                                                                                            function () use (
                                                                                                                                                                                                                                                                                                                                                                                &$verb,
                                                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                                                return $verb ===
                                                                                                                                                                                                                                                                                                                                                                                    "GET";
                                                                                                                                                                                                                                                                                                                                                                            },
                                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                                    )
                                                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                                                    $result = jm(
                                                                                                                                                                                                                                                                                                                                                                        $platform,
                                                                                                                                                                                                                                                                                                                                                                        "reports",
                                                                                                                                                                                                                                                                                                                                                                        $actor,
                                                                                                                                                                                                                                                                                                                                                                    );
                                                                                                                                                                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                                                                                                                                                                    if (
                                                                                                                                                                                                                                                                                                                                                                        js_truth(
                                                                                                                                                                                                                                                                                                                                                                            js_and(
                                                                                                                                                                                                                                                                                                                                                                                $route ===
                                                                                                                                                                                                                                                                                                                                                                                    "/api/search",
                                                                                                                                                                                                                                                                                                                                                                                function () use (
                                                                                                                                                                                                                                                                                                                                                                                    &$verb,
                                                                                                                                                                                                                                                                                                                                                                                ) {
                                                                                                                                                                                                                                                                                                                                                                                    return $verb ===
                                                                                                                                                                                                                                                                                                                                                                                        "GET";
                                                                                                                                                                                                                                                                                                                                                                                },
                                                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                                                        )
                                                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                                                        $result = jm(
                                                                                                                                                                                                                                                                                                                                                                            $platform,
                                                                                                                                                                                                                                                                                                                                                                            "search",
                                                                                                                                                                                                                                                                                                                                                                            $actor,
                                                                                                                                                                                                                                                                                                                                                                            js_object_fromEntries(
                                                                                                                                                                                                                                                                                                                                                                                g(
                                                                                                                                                                                                                                                                                                                                                                                    $url,
                                                                                                                                                                                                                                                                                                                                                                                    "searchParams",
                                                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                                                        );
                                                                                                                                                                                                                                                                                                                                                                    } else {
                                                                                                                                                                                                                                                                                                                                                                        if (
                                                                                                                                                                                                                                                                                                                                                                            js_truth(
                                                                                                                                                                                                                                                                                                                                                                                js_and(
                                                                                                                                                                                                                                                                                                                                                                                    $route ===
                                                                                                                                                                                                                                                                                                                                                                                        "/api/system",
                                                                                                                                                                                                                                                                                                                                                                                    function () use (
                                                                                                                                                                                                                                                                                                                                                                                        &$verb,
                                                                                                                                                                                                                                                                                                                                                                                    ) {
                                                                                                                                                                                                                                                                                                                                                                                        return $verb ===
                                                                                                                                                                                                                                                                                                                                                                                            "GET";
                                                                                                                                                                                                                                                                                                                                                                                    },
                                                                                                                                                                                                                                                                                                                                                                                ),
                                                                                                                                                                                                                                                                                                                                                                            )
                                                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                                                            $result = jm(
                                                                                                                                                                                                                                                                                                                                                                                $platform,
                                                                                                                                                                                                                                                                                                                                                                                "systemStatus",
                                                                                                                                                                                                                                                                                                                                                                                $actor,
                                                                                                                                                                                                                                                                                                                                                                            );
                                                                                                                                                                                                                                                                                                                                                                        } else {
                                                                                                                                                                                                                                                                                                                                                                            if (
                                                                                                                                                                                                                                                                                                                                                                                js_truth(
                                                                                                                                                                                                                                                                                                                                                                                    js_and(
                                                                                                                                                                                                                                                                                                                                                                                        jm(
                                                                                                                                                                                                                                                                                                                                                                                            new JsRegex(
                                                                                                                                                                                                                                                                                                                                                                                                '^\\/api\\/system\\/alerts\\/[^/]+\\/retry$',
                                                                                                                                                                                                                                                                                                                                                                                                "",
                                                                                                                                                                                                                                                                                                                                                                                            ),
                                                                                                                                                                                                                                                                                                                                                                                            "test",
                                                                                                                                                                                                                                                                                                                                                                                            $route,
                                                                                                                                                                                                                                                                                                                                                                                        ),
                                                                                                                                                                                                                                                                                                                                                                                        function () use (
                                                                                                                                                                                                                                                                                                                                                                                            &$verb,
                                                                                                                                                                                                                                                                                                                                                                                        ) {
                                                                                                                                                                                                                                                                                                                                                                                            return $verb ===
                                                                                                                                                                                                                                                                                                                                                                                                "POST";
                                                                                                                                                                                                                                                                                                                                                                                        },
                                                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                                                )
                                                                                                                                                                                                                                                                                                                                                                            ) {
                                                                                                                                                                                                                                                                                                                                                                                $result = jm(
                                                                                                                                                                                                                                                                                                                                                                                    $platform,
                                                                                                                                                                                                                                                                                                                                                                                    "retryAlert",
                                                                                                                                                                                                                                                                                                                                                                                    $actor,
                                                                                                                                                                                                                                                                                                                                                                                    g(
                                                                                                                                                                                                                                                                                                                                                                                        $parts,
                                                                                                                                                                                                                                                                                                                                                                                        3,
                                                                                                                                                                                                                                                                                                                                                                                    ),
                                                                                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                                                                                            } else {
                                                                                                                                                                                                                                                                                                                                                                                throw new HttpError(
                                                                                                                                                                                                                                                                                                                                                                                    404,
                                                                                                                                                                                                                                                                                                                                                                                    "API route not found.",
                                                                                                                                                                                                                                                                                                                                                                                );
                                                                                                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                    }
                                                                                                                                                                                                                                }
                                                                                                                                                                                                                            }
                                                                                                                                                                                                                        }
                                                                                                                                                                                                                    }
                                                                                                                                                                                                                }
                                                                                                                                                                                                            }
                                                                                                                                                                                                        }
                                                                                                                                                                                                    }
                                                                                                                                                                                                }
                                                                                                                                                                                            }
                                                                                                                                                                                        }
                                                                                                                                                                                    }
                                                                                                                                                                                }
                                                                                                                                                                            }
                                                                                                                                                                        }
                                                                                                                                                                    }
                                                                                                                                                                }
                                                                                                                                                            }
                                                                                                                                                        }
                                                                                                                                                    }
                                                                                                                                                }
                                                                                                                                            }
                                                                                                                                        }
                                                                                                                                    }
                                                                                                                                }
                                                                                                                            }
                                                                                                                        }
                                                                                                                    }
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                        }
                                                                                    }
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return a_platform_router_json($res, 200, ["ok" => true, "data" => $result]);
}
