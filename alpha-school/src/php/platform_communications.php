<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_platform_communications_FORM_RULES", [
    "contact" => [
        "type" => "Contact enquiry",
        "fields" => ["name", "phone", "email", "topic", "message"],
        "required" => ["name", "phone", "message"],
    ],
    "visit" => [
        "type" => "School visit request",
        "fields" => [
            "name",
            "phone",
            "email",
            "preferred_day",
            "level",
            "message",
        ],
        "required" => ["name", "phone"],
    ],
    "apply" => [
        "type" => "Admission application",
        "fields" => [
            "child_name",
            "guardian_name",
            "phone",
            "email",
            "level",
            "arrangement",
            "message",
        ],
        "required" => [
            "child_name",
            "guardian_name",
            "phone",
            "level",
            "arrangement",
        ],
    ],
    "computer-class" => [
        "type" => "Computer class enquiry",
        "fields" => ["name", "phone", "email", "level", "class", "message"],
        "required" => ["name", "phone"],
    ],
    "computer-interest" => [
        "type" => "Community training interest",
        "fields" => [
            "name",
            "phone",
            "email",
            "course",
            "interest",
            "category",
            "message",
        ],
        "required" => ["name", "phone"],
    ],
    "safeguarding" => [
        "type" => "Safeguarding concern",
        "fields" => ["name", "phone", "message"],
        "required" => ["message"],
        "private" => true,
    ],
    "privacy-request" => [
        "type" => "Privacy request",
        "fields" => ["name", "phone", "email", "request_type", "message"],
        "required" => ["name", "message"],
        "private" => true,
    ],
]);
define("a_platform_communications_ADMISSION_TRANSITIONS", [
    "DRAFT" => ["SUBMITTED"],
    "SUBMITTED" => ["DOCUMENTS_REQUIRED", "UNDER_REVIEW"],
    "DOCUMENTS_REQUIRED" => ["UNDER_REVIEW", "DECLINED"],
    "UNDER_REVIEW" => [
        "DOCUMENTS_REQUIRED",
        "ASSESSMENT",
        "ACCEPTED",
        "WAITLISTED",
        "DECLINED",
    ],
    "ASSESSMENT" => [
        "DOCUMENTS_REQUIRED",
        "ACCEPTED",
        "WAITLISTED",
        "DECLINED",
    ],
    "ACCEPTED" => ["ENROLLED", "DECLINED"],
    "WAITLISTED" => ["UNDER_REVIEW", "ACCEPTED", "DECLINED"],
    "DECLINED" => [],
    "ENROLLED" => [],
]);
function a_platform_communications_queueAlert(
    $tx = null,
    $reference = null,
    $type = null,
    $privateRequest = false,
    $application = false,
) {
    $href = js_truth($privateRequest)
        ? "/portal/privacy"
        : (js_truth($application)
            ? "/portal/admissions"
            : "/portal/enquiries");
    foreach (js_iter(jm($tx, "list", "users")) as $item2110) {
        $user = $item2110;
        if (
            js_truth(
                a_platform_access_has(
                    $user,
                    js_truth($privateRequest)
                        ? "privacy.manage"
                        : (js_truth($application)
                            ? "admission.read"
                            : "submission.read"),
                ),
            )
        ) {
            a_platform_workflows_notify(
                $tx,
                g($user, "id"),
                "" . js_string($type) . ": " . js_string($reference) . "",
                $href,
                $reference,
            );
        }
    }
    jm($tx, "insert", "outbox", [
        "id" => rid("ALERT-"),
        "reference" => $reference,
        "type" => $type,
        "href" => $href,
        "status" => "PENDING",
        "attempts" => 0,
        "nextAttemptAt" => js_date_now(),
        "createdAt" => jm(new JsDate(), "toISOString"),
    ]);
}
function a_platform_communications_cmsFields($body = null)
{
    $kind = a_platform_validation_choose(g($body, "kind"), [
        "news",
        "page",
        "event",
        "vacancy",
        "faq",
        "banner",
        "settings",
    ]);
    $title = text(g($body, "title"), 180, true);
    $slug = a_lib_slugify(
        js_or(text(g($body, "slug"), 100), function () use (&$title) {
            return $title;
        }),
    );
    if (js_truth(!js_truth($slug))) {
        throw new HttpError(400, "Use a readable URL slug.");
    }
    $language = a_platform_validation_choose(
        js_or(g($body, "language"), function () {
            return "en";
        }),
        ["en", "sw"],
    );
    $settingsBody = js_truth($kind === "settings")
        ? js_json_stringify(validate_details(g($body, "body")))
        : null;
    if (
        js_truth(
            js_and($kind === "settings", function () use (&$slug, &$language) {
                return js_or($slug !== "school-contact", function () use (
                    &$language,
                ) {
                    return $language !== "en";
                });
            }),
        )
    ) {
        throw new HttpError(
            400,
            "School details use the school-contact record in English.",
        );
    }
    $eventDate = js_truth($kind === "event")
        ? a_platform_validation_date(g($body, "eventDate"))
        : "";
    return [
        "kind" => $kind,
        "title" => $title,
        "slug" => $slug,
        "language" => $language,
        "body" => js_or($settingsBody, function () use (&$body) {
            return text(g($body, "body"), 20000, true);
        }),
        "excerpt" => text(g($body, "excerpt"), 400, true),
        "category" => js_or(text(g($body, "category"), 100), function () {
            return "School News";
        }),
        "seoTitle" => text(g($body, "seoTitle"), 70),
        "seoDescription" => text(g($body, "seoDescription"), 170),
        "mediaId" => text(g($body, "mediaId"), 100),
        "eventDate" => $eventDate,
    ];
}
function a_platform_communications_noticeFields($body = null)
{
    return [
        "title" => text(g($body, "title"), 180, true),
        "message" => text(g($body, "message"), 8000, true),
        "category" => a_platform_validation_choose(g($body, "category"), [
            "General",
            "Academic",
            "HR",
            "Administration",
            "ICT",
            "Meetings",
            "Training",
            "Events",
            "Emergency",
            "Deadlines",
        ]),
        "priority" => a_platform_validation_choose(g($body, "priority"), [
            "Urgent",
            "Important",
            "Information",
            "General",
        ]),
        "expiresAt" => a_platform_validation_date(g($body, "expiresAt"), true),
        "acknowledgementRequired" =>
            g($body, "acknowledgementRequired") === true,
    ];
}
trait a_platform_communications_Methods
{
    public function submitPublic($actor = null, $kind = null, $body = null)
    {
        $rule = js_truth(
            js_object_hasOwn(a_platform_communications_FORM_RULES, $kind),
        )
            ? g(a_platform_communications_FORM_RULES, $kind)
            : null;
        if (js_truth(!js_truth($rule))) {
            throw new HttpError(404, "Form not found.");
        }
        if (js_truth(g($body, "website_url"))) {
            return [
                "ok" => true,
                "message" =>
                    "Thank you. Please call the school if you need help.",
            ];
        }
        if (
            js_truth(
                !js_truth(
                    jm(
                        ["on", "yes", "true", true],
                        "includes",
                        g($body, "privacy_consent"),
                    ),
                ),
            )
        ) {
            throw new HttpError(
                400,
                "Please read and accept the privacy notice before submitting.",
            );
        }
        $data = [];
        foreach (js_iter(g($rule, "fields")) as $item4519) {
            $field = $item4519;
            $data[$field] = text(
                g($body, $field),
                js_truth($field === "message") ? 2000 : 160,
                jm(g($rule, "required"), "includes", $field),
            );
        }
        if (
            js_truth(
                js_and(
                    js_and($kind === "privacy-request", function () use (
                        &$data,
                    ) {
                        return !js_truth(g($data, "phone"));
                    }),
                    function () use (&$data) {
                        return !js_truth(g($data, "email"));
                    },
                ),
            )
        ) {
            throw new HttpError(
                400,
                "Provide a safe phone number or email for private follow-up.",
            );
        }
        if (
            js_truth(
                js_and(g($data, "phone"), function () use (&$data) {
                    return !js_truth(
                        jm(
                            new JsRegex('^\\+?[0-9 ()-]{7,24}$', ""),
                            "test",
                            g($data, "phone"),
                        ),
                    );
                }),
            )
        ) {
            throw new HttpError(400, "Enter a valid telephone number.");
        }
        if (
            js_truth(
                js_and(g($data, "email"), function () use (&$data) {
                    return !js_truth(
                        jm(
                            new JsRegex('^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$', ""),
                            "test",
                            g($data, "email"),
                        ),
                    );
                }),
            )
        ) {
            throw new HttpError(400, "Enter a valid email address.");
        }
        if (
            js_truth(
                js_and($kind === "apply", function () use (&$data) {
                    return js_or(
                        !js_truth(
                            jm(
                                [
                                    ...js_array(
                                        g(g(SEED, "academics"), "prePrimary"),
                                    ),
                                    ...js_array(
                                        g(g(SEED, "academics"), "primary"),
                                    ),
                                ],
                                "includes",
                                g($data, "level"),
                            ),
                        ),
                        function () use (&$data) {
                            return !js_truth(
                                jm(
                                    ["Day", "Boarding"],
                                    "includes",
                                    g($data, "arrangement"),
                                ),
                            );
                        },
                    );
                }),
            )
        ) {
            throw new HttpError(
                400,
                "Select a valid class and day or boarding arrangement.",
            );
        }
        $inputKey = text(g($body, "submissionKey"), 80);
        $key = js_truth($inputKey)
            ? js_add(
                "submission-key:",
                sha256(
                    js_add(
                        js_add(
                            js_add(js_add(g($actor, "ip"), ":"), $kind),
                            ":",
                        ),
                        $inputKey,
                    ),
                ),
            )
            : null;
        $result = jm(g($this, "store"), "run", function ($tx = null) use (
            &$actor,
            &$kind,
            &$rule,
            &$data,
            &$key,
        ) {
            if (js_truth($key)) {
                $saved = jm($tx, "get", "system_settings", $key);
                if (
                    js_truth(
                        js_and($saved, function () use (&$saved) {
                            return g($saved, "expiresAt") > js_date_now();
                        }),
                    )
                ) {
                    return ["reference" => g($saved, "reference")];
                }
            }
            if (
                js_truth(
                    !js_truth(
                        rate_limit(
                            $tx,
                            js_add("form:", g($actor, "ip")),
                            8,
                            60 * 60 * 1000,
                        ),
                    ),
                )
            ) {
                return [
                    "error" => new HttpError(
                        429,
                        "Too many submissions. Please wait or call the school office.",
                    ),
                ];
            }
            $reference =
                "ALPHA-" .
                js_string(jm(new JsDate(), "getFullYear")) .
                "-" .
                js_string(
                    jm(
                        jm(js_crypto_randomBytes(6), "toString", "hex"),
                        "toUpperCase",
                    ),
                ) .
                "";
            $record = [
                "id" => $reference,
                "reference" => $reference,
                "type" => g($rule, "type"),
                "kind" => $kind,
                "data" => $data,
                "status" => js_truth($kind === "apply") ? "SUBMITTED" : "NEW",
                "consent" => [
                    "purpose" => js_truth($kind === "apply")
                        ? "admissions_review"
                        : (js_truth(g($rule, "private"))
                            ? "private_request_triage"
                            : "respond_to_enquiry"),
                    "policyVersion" => g(g($this, "config"), "policyVersion"),
                    "acceptedAt" => jm(new JsDate(), "toISOString"),
                ],
                "createdAt" => jm(new JsDate(), "toISOString"),
                "expiresAt" => js_add(
                    js_date_now(),
                    (js_truth($kind === "apply")
                        ? g(g($this, "config"), "admissionRetentionDays")
                        : g(g($this, "config"), "formRetentionDays")) *
                        86400000,
                ),
                "history" => [],
            ];
            $collection = js_truth($kind === "apply")
                ? "admissions"
                : (js_truth(g($rule, "private"))
                    ? "privacy_requests"
                    : "submissions");
            jm($tx, "insert", $collection, $record);
            a_platform_communications_queueAlert(
                $tx,
                $reference,
                g($rule, "type"),
                g($rule, "private"),
                $kind === "apply",
            );
            if (js_truth($key)) {
                $existing = jm($tx, "get", "system_settings", $key);
                $receipt = [
                    "id" => $key,
                    "reference" => $reference,
                    "expiresAt" => js_add(js_date_now(), 86400000),
                ];
                if (js_truth($existing)) {
                    jm($tx, "update", "system_settings", [
                        ...js_array($existing),
                        ...js_array($receipt),
                    ]);
                } else {
                    jm($tx, "insert", "system_settings", $receipt);
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "public_form.submit",
                $collection,
                $reference,
                "success",
                [
                    "kind" => $kind,
                    "policyVersion" => g(g($this, "config"), "policyVersion"),
                ],
            );
            return ["reference" => $reference];
        });
        if (js_truth(g($result, "error"))) {
            throw g($result, "error");
        }
        return [
            "ok" => true,
            "reference" => g($result, "reference"),
            "message" => js_truth(g($body, "language") === "sw")
                ? "Tumepokea ombi lako. Hifadhi namba hii ya kumbukumbu; ofisi itawasiliana nawe."
                : "Your request is saved in the school office queue. Keep your reference number; the authorised team will follow up.",
        ];
    }
    public function enquiries($actor = null, $admissions = false)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$admissions,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                js_truth($admissions) ? "admission.read" : "submission.read",
            );
            $collection = js_truth($admissions) ? "admissions" : "submissions";
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($admissions) ? "admission.read" : "submission.read",
                $collection,
                "authorised",
            );
            return jm(jm($tx, "list", $collection), "reverse");
        });
    }
    public function changeEnquiry(
        $actor = null,
        $id = null,
        $body = null,
        $admissions = false,
    ) {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
            &$admissions,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                js_truth($admissions)
                    ? "admission.manage"
                    : "submission.manage",
            );
            $collection = js_truth($admissions) ? "admissions" : "submissions";
            $record = jm($tx, "get", $collection, $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Reference not found.");
            }
            a_platform_validation_revision($record, $body);
            $next = text(g($body, "status"), 40, true);
            if (
                js_truth(
                    js_truth($admissions)
                        ? !js_truth(
                            jm(
                                g(
                                    a_platform_communications_ADMISSION_TRANSITIONS,
                                    g($record, "status"),
                                ),
                                "includes",
                                $next,
                            ),
                        )
                        : !js_truth(
                            jm(
                                g(
                                    [
                                        "NEW" => ["IN_PROGRESS", "CLOSED"],
                                        "IN_PROGRESS" => ["CLOSED"],
                                        "CLOSED" => ["IN_PROGRESS"],
                                    ],
                                    g($record, "status"),
                                ),
                                "includes",
                                $next,
                            ),
                        ),
                )
            ) {
                throw new HttpError(
                    409,
                    "This status transition is not allowed.",
                );
            }
            $from = g($record, "status");
            $note = text(
                g($body, "comment"),
                2000,
                js_and($admissions, function () use (&$next) {
                    return jm(
                        ["DECLINED", "DOCUMENTS_REQUIRED"],
                        "includes",
                        $next,
                    );
                }),
            );
            js_push($record["history"], [
                "from" => $from,
                "to" => $next,
                "note" => $note,
                "by" => g(g($actor, "user"), "id"),
                "at" => jm(new JsDate(), "toISOString"),
            ]);
            $record["status"] = $next;
            $record["updatedAt"] = jm(new JsDate(), "toISOString");
            $saved = jm($tx, "update", $collection, $record);
            if (
                js_truth(
                    js_and($admissions, function () use (&$record) {
                        return g($record, "applicantUserId");
                    }),
                )
            ) {
                a_platform_workflows_notify(
                    $tx,
                    g($record, "applicantUserId"),
                    "Admission application " .
                        js_string(g($record, "reference")) .
                        ": " .
                        js_string(
                            jm(
                                jm(
                                    $next,
                                    "replace",
                                    new JsRegex("_", "g"),
                                    " ",
                                ),
                                "toLowerCase",
                            ),
                        ) .
                        "",
                    "/portal/family",
                    g($record, "id"),
                );
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($admissions)
                    ? "admission.status_change"
                    : "submission.status_change",
                $collection,
                $id,
                "success",
                ["from" => $from, "to" => $next],
            );
            return $saved;
        });
    }
    public function cms($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            if (
                js_truth(
                    !js_truth(
                        jm(
                            [
                                "cms.create",
                                "cms.review",
                                "cms.approve",
                                "cms.publish",
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
                    "The content workspace is restricted.",
                );
            }
            return jm(jm($tx, "list", "cms_content"), "filter", function (
                $record = null,
            ) use (&$actor) {
                return js_or(
                    g($record, "createdBy") === g(g($actor, "user"), "id"),
                    function () use (&$actor) {
                        return jm(
                            [
                                "cms.edit_any",
                                "cms.review",
                                "cms.approve",
                                "cms.publish",
                            ],
                            "some",
                            function ($permission = null) use (&$actor) {
                                return a_platform_access_has(
                                    g($actor, "user"),
                                    $permission,
                                );
                            },
                        );
                    },
                );
            });
        });
    }
    public function saveContent($actor = null, $body = null, $id = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "cms.create",
            );
            $fields = a_platform_communications_cmsFields($body);
            if (
                js_truth(
                    jm(jm($tx, "list", "cms_content"), "some", function (
                        $item = null,
                    ) use (&$id, &$fields) {
                        return js_and(
                            js_and(g($item, "id") !== $id, function () use (
                                &$fields,
                                &$item,
                            ) {
                                return g($item, "kind") === g($fields, "kind");
                            }),
                            function () use (&$fields, &$item) {
                                return g($item, "slug") === g($fields, "slug");
                            },
                        );
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "This URL slug is already in use for that content type.",
                );
            }
            $record = null;
            if (js_truth($id)) {
                $record = jm($tx, "get", "cms_content", $id);
                if (
                    js_truth(
                        js_or(!js_truth($record), function () use (
                            &$actor,
                            &$record,
                        ) {
                            return js_and(
                                g($record, "createdBy") !==
                                    g(g($actor, "user"), "id"),
                                function () use (&$actor) {
                                    return !js_truth(
                                        a_platform_access_has(
                                            g($actor, "user"),
                                            "cms.edit_any",
                                        ),
                                    );
                                },
                            );
                        }),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "This draft is not available for editing.",
                    );
                }
                a_platform_validation_revision($record, $body);
                if (
                    js_truth(
                        !js_truth(
                            jm(
                                ["DRAFT", "RETURNED", "REJECTED", "PUBLISHED"],
                                "includes",
                                g($record, "status"),
                            ),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Reviewed, scheduled or archived content cannot be overwritten.",
                    );
                }
                if (
                    js_truth(
                        js_or(
                            g($record, "kind") !== g($fields, "kind"),
                            function () use (&$fields, &$record) {
                                return g($record, "slug") !==
                                    g($fields, "slug");
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Keep the content type and permanent URL when revising a published item.",
                    );
                }
                $authors = js_truth(g($record, "status") === "PUBLISHED")
                    ? [g(g($actor, "user"), "id")]
                    : [
                        ...js_array(
                            new JsSet([
                                ...js_array(
                                    js_or(
                                        g($record, "revisionAuthors"),
                                        function () use (&$record) {
                                            return [g($record, "createdBy")];
                                        },
                                    ),
                                ),
                                g(g($actor, "user"), "id"),
                            ]),
                        ),
                    ];
                $record = jm($tx, "update", "cms_content", [
                    ...js_array($record),
                    ...js_array($fields),
                    "version" => js_add(g($record, "version"), 1),
                    "status" => "DRAFT",
                    "revisionAuthors" => $authors,
                    "workflowId" => "",
                    "approvedBy" => "",
                    "approvedAt" => "",
                    "publishAt" => null,
                ]);
            } else {
                $record = jm($tx, "insert", "cms_content", [
                    "id" => rid("CMS-"),
                    ...js_array($fields),
                    "status" => "DRAFT",
                    "version" => 1,
                    "createdBy" => g(g($actor, "user"), "id"),
                    "revisionAuthors" => [g(g($actor, "user"), "id")],
                    "createdAt" => jm(new JsDate(), "toISOString"),
                    "published" => null,
                    "history" => [],
                ]);
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($id) ? "cms.edit" : "cms.create",
                "cms_content",
                g($record, "id"),
                "success",
                ["version" => g($record, "version")],
            );
            return $record;
        });
    }
    public function contentAction($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            $record = jm($tx, "get", "cms_content", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Content not found.");
            }
            a_platform_validation_revision($record, $body);
            if (js_truth(g($body, "action") === "SUBMIT")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "cms.create",
                );
                if (
                    js_truth(
                        js_and(
                            g($record, "createdBy") !==
                                g(g($actor, "user"), "id"),
                            function () use (&$actor) {
                                return !js_truth(
                                    a_platform_access_has(
                                        g($actor, "user"),
                                        "cms.edit_any",
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(403, "This draft is not yours.");
                }
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
                        "Only a draft or returned item can be submitted.",
                    );
                }
                $flow = a_platform_workflows_startWorkflow(
                    $tx,
                    $actor,
                    g($this, "config"),
                    "cms-standard",
                    $record,
                );
                return jm($tx, "update", "cms_content", [
                    ...js_array($record),
                    "workflowId" => g($flow, "id"),
                    "workflowIds" => [
                        ...js_array(
                            js_or(g($record, "workflowIds"), function () {
                                return [];
                            }),
                        ),
                        g($flow, "id"),
                    ],
                    "status" => "UNDER_REVIEW",
                ]);
            }
            if (js_truth(g($body, "action") === "PUBLISH")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "cms.publish",
                );
                $flow = js_and(g($record, "workflowId"), function () use (
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
                        js_or(
                            js_or(
                                js_or(
                                    g($record, "status") !== "APPROVED",
                                    function () use (&$flow) {
                                        return g($flow, "status") !==
                                            "APPROVED";
                                    },
                                ),
                                function () use (&$actor, &$record) {
                                    return g($record, "createdBy") ===
                                        g(g($actor, "user"), "id");
                                },
                            ),
                            function () use (&$actor, &$record) {
                                return jm(
                                    js_or(
                                        g($record, "revisionAuthors"),
                                        function () {
                                            return [];
                                        },
                                    ),
                                    "includes",
                                    g(g($actor, "user"), "id"),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Only independently approved content can be published by a separate publisher.",
                    );
                }
                if (
                    js_truth(
                        js_and(g($record, "mediaId"), function () use (
                            &$tx,
                            &$record,
                        ) {
                            return !js_truth(
                                a_platform_privacy_mediaIsPublic(
                                    $tx,
                                    g($record, "mediaId"),
                                ),
                            );
                        }),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "The selected media has not passed approval and current consent checks.",
                    );
                }
                $publishAt = js_truth(g($body, "publishAt"))
                    ? new JsDate(g($body, "publishAt"))
                    : new JsDate();
                if (
                    js_truth(
                        !js_truth(
                            js_number_isFinite(jm($publishAt, "getTime")),
                        ),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Use a valid publication date and time.",
                    );
                }
                $record["publishAt"] = jm($publishAt, "toISOString");
                $record["publishedBy"] = g(g($actor, "user"), "id");
                if (js_truth(jm($publishAt, "getTime") > js_date_now())) {
                    $record["status"] = "SCHEDULED";
                } else {
                    if (js_truth(g($record, "published"))) {
                        js_push($record["history"], g($record, "published"));
                    }
                    $record["published"] = [
                        ...js_array(
                            a_platform_communications_cmsFields($record),
                        ),
                        "content" => jm(
                            g($record, "body"),
                            "split",
                            new JsRegex('\\n\\s*\\n', ""),
                        ),
                        "publishedAt" => g($record, "publishAt"),
                        "version" => g($record, "version"),
                    ];
                    $record["status"] = "PUBLISHED";
                }
                $saved = jm($tx, "update", "cms_content", $record);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    js_truth(g($record, "status") === "SCHEDULED")
                        ? "cms.schedule"
                        : "cms.publish",
                    "cms_content",
                    $id,
                    "success",
                    ["version" => g($record, "version")],
                );
                return $saved;
            }
            if (js_truth(g($body, "action") === "ARCHIVE")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "cms.archive",
                );
                $saved = jm($tx, "update", "cms_content", [
                    ...js_array($record),
                    "status" => "ARCHIVED",
                    "archivedAt" => jm(new JsDate(), "toISOString"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "cms.archive",
                    "cms_content",
                    $id,
                );
                return $saved;
            }
            throw new HttpError(400, "Choose submit, publish or archive.");
        });
    }
    public function publicContent()
    {
        return jm(g($this, "store"), "run", function ($tx = null) {
            $out = [];
            foreach (js_iter(jm($tx, "list", "cms_content")) as $item14758) {
                $record = $item14758;
                if (
                    js_truth(
                        js_or(
                            !js_truth(g($record, "published")),
                            function () use (&$record) {
                                return g($record, "status") === "ARCHIVED";
                            },
                        ),
                    )
                ) {
                    continue;
                }
                $published = [
                    "id" => g($record, "id"),
                    "kind" => g($record, "kind"),
                    ...js_array(g($record, "published")),
                ];
                if (
                    js_truth(
                        js_and(g($published, "mediaId"), function () use (
                            &$tx,
                            &$published,
                        ) {
                            return !js_truth(
                                a_platform_privacy_mediaIsPublic(
                                    $tx,
                                    g($published, "mediaId"),
                                ),
                            );
                        }),
                    )
                ) {
                    $published["mediaId"] = "";
                }
                js_push($out, $published);
            }
            return $out;
        });
    }
    public function notices($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notice.read",
            );
            $acknowledgements = jm($tx, "list", "notice_acknowledgements");
            return jm(
                jm(jm($tx, "list", "notices"), "filter", function (
                    $record = null,
                ) use (&$actor) {
                    return js_or(
                        js_or(
                            g($record, "createdBy") ===
                                g(g($actor, "user"), "id"),
                            function () use (&$actor) {
                                return a_platform_access_has(
                                    g($actor, "user"),
                                    "notice.publish",
                                );
                            },
                        ),
                        function () use (&$actor, &$record) {
                            return js_and(
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
                            );
                        },
                    );
                }),
                "map",
                function ($record = null) use (&$actor, &$acknowledgements) {
                    return [
                        ...js_array($record),
                        "acknowledgement" => js_or(
                            jm($acknowledgements, "find", function (
                                $ack = null,
                            ) use (&$actor, &$record) {
                                return js_and(
                                    js_and(
                                        g($ack, "noticeId") ===
                                            g($record, "id"),
                                        function () use (&$record, &$ack) {
                                            return g($ack, "version") ===
                                                g($record, "version");
                                        },
                                    ),
                                    function () use (&$actor, &$ack) {
                                        return g($ack, "userId") ===
                                            g(g($actor, "user"), "id");
                                    },
                                );
                            }),
                            function () {
                                return null;
                            },
                        ),
                    ];
                },
            );
        });
    }
    public function saveNotice($actor = null, $body = null, $id = "")
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notice.create",
            );
            $fields = a_platform_communications_noticeFields($body);
            $fields["audience"] = a_platform_validation_audience(
                $tx,
                g($body, "audience"),
            );
            $record = null;
            if (js_truth($id)) {
                $old = jm($tx, "get", "notices", $id);
                if (
                    js_truth(
                        js_or(!js_truth($old), function () use (
                            &$actor,
                            &$old,
                        ) {
                            return g($old, "createdBy") !==
                                g(g($actor, "user"), "id");
                        }),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "Only the notice author can revise it.",
                    );
                }
                a_platform_validation_revision($old, $body);
                $record = jm($tx, "update", "notices", [
                    ...js_array($old),
                    ...js_array($fields),
                    "version" => js_add(g($old, "version"), 1),
                    "status" => "DRAFT",
                    "history" => [
                        ...js_array(
                            js_or(g($old, "history"), function () {
                                return [];
                            }),
                        ),
                        [
                            "title" => g($old, "title"),
                            "message" => g($old, "message"),
                            "audience" => g($old, "audience"),
                            "version" => g($old, "version"),
                            "publishedAt" => js_or(
                                g($old, "publishedAt"),
                                function () {
                                    return null;
                                },
                            ),
                        ],
                    ],
                ]);
            } else {
                $record = jm($tx, "insert", "notices", [
                    "id" => rid("NTC-"),
                    ...js_array($fields),
                    "version" => 1,
                    "status" => "DRAFT",
                    "createdBy" => g(g($actor, "user"), "id"),
                    "createdAt" => jm(new JsDate(), "toISOString"),
                ]);
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth($id) ? "notice.edit" : "notice.create",
                "notice",
                g($record, "id"),
            );
            return $record;
        });
    }
    public function publishNotice($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notice.publish",
            );
            $record = jm($tx, "get", "notices", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Notice not found.");
            }
            a_platform_validation_revision($record, $body);
            if (
                js_truth(
                    js_or(g($record, "status") !== "DRAFT", function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "createdBy") ===
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(
                    409,
                    "A separate publisher must review and issue this draft.",
                );
            }
            if (
                js_truth(
                    js_and(g($record, "expiresAt"), function () use (&$record) {
                        return g($record, "expiresAt") <
                            jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
                    }),
                )
            ) {
                throw new HttpError(400, "This notice has already expired.");
            }
            $saved = jm($tx, "update", "notices", [
                ...js_array($record),
                "status" => "PUBLISHED",
                "publishedBy" => g(g($actor, "user"), "id"),
                "publishedAt" => jm(new JsDate(), "toISOString"),
            ]);
            foreach (js_iter(jm($tx, "list", "users")) as $item17627) {
                $user = $item17627;
                if (
                    js_truth(
                        a_platform_access_audienceAllows(
                            $user,
                            g($record, "audience"),
                        ),
                    )
                ) {
                    a_platform_workflows_notify(
                        $tx,
                        g($user, "id"),
                        js_truth(g($record, "acknowledgementRequired"))
                            ? "A staff notice requires your acknowledgement"
                            : "A new staff notice is available",
                        "/portal/notices",
                        $id,
                    );
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "notice.publish",
                "notice",
                $id,
                "success",
                ["version" => g($record, "version")],
            );
            return $saved;
        });
    }
    public function acknowledgeNotice($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notice.read",
            );
            $notice = jm($tx, "get", "notices", $id);
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(!js_truth($notice), function () use (
                                &$notice,
                            ) {
                                return g($notice, "status") !== "PUBLISHED";
                            }),
                            function () use (&$notice) {
                                return !js_truth(
                                    a_platform_validation_activeDate($notice),
                                );
                            },
                        ),
                        function () use (&$actor, &$notice) {
                            return !js_truth(
                                a_platform_access_audienceAllows(
                                    g($actor, "user"),
                                    g($notice, "audience"),
                                ),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "This notice is not addressed to you or is no longer current.",
                );
            }
            if (
                js_truth(
                    js_Number(g($body, "version")) !== g($notice, "version"),
                )
            ) {
                throw new HttpError(
                    409,
                    "The notice has changed. Read the current version first.",
                );
            }
            $ackId =
                "" .
                js_string($id) .
                ":" .
                js_string(g($notice, "version")) .
                ":" .
                js_string(g(g($actor, "user"), "id")) .
                "";
            $existing = jm($tx, "get", "notice_acknowledgements", $ackId);
            $now = jm(new JsDate(), "toISOString");
            $staff = jm(
                $tx,
                "get",
                "staff_profiles",
                g(g($actor, "user"), "id"),
            );
            $value = [
                ...js_array(
                    js_or($existing, function () {
                        return [];
                    }),
                ),
                "id" => $ackId,
                "noticeId" => $id,
                "version" => g($notice, "version"),
                "userId" => g(g($actor, "user"), "id"),
                "staffId" => js_or(g($staff, "staffId"), function () {
                    return "";
                }),
                "readAt" => js_or(g($existing, "readAt"), function () use (
                    &$now,
                ) {
                    return $now;
                }),
                "acknowledgedAt" => js_truth(
                    js_and(g($body, "acknowledge") === true, function () use (
                        &$notice,
                    ) {
                        return g($notice, "acknowledgementRequired");
                    }),
                )
                    ? js_or(g($existing, "acknowledgedAt"), function () use (
                        &$now,
                    ) {
                        return $now;
                    })
                    : js_or(g($existing, "acknowledgedAt"), function () {
                        return null;
                    }),
                "session" => jm(
                    sha256(g(g($actor, "session"), "id")),
                    "slice",
                    0,
                    20,
                ),
            ];
            $saved = js_truth($existing)
                ? jm($tx, "update", "notice_acknowledgements", $value)
                : jm($tx, "insert", "notice_acknowledgements", $value);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                js_truth(g($body, "acknowledge"))
                    ? "notice.acknowledge"
                    : "notice.read",
                "notice",
                $id,
                "success",
                ["version" => g($notice, "version")],
            );
            return $saved;
        });
    }
    public function noticeReport($actor = null, $id = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notice.publish",
            );
            $notice = jm($tx, "get", "notices", $id);
            if (js_truth(!js_truth($notice))) {
                throw new HttpError(404, "Notice not found.");
            }
            $receipts = jm($tx, "list", "notice_acknowledgements");
            $people = jm(
                jm(jm($tx, "list", "users"), "filter", function (
                    $user = null,
                ) use (&$notice) {
                    return js_and(g($user, "active"), function () use (
                        &$notice,
                        &$user,
                    ) {
                        return a_platform_access_audienceAllows(
                            $user,
                            g($notice, "audience"),
                        );
                    });
                }),
                "map",
                function ($user = null) use (&$id, &$notice, &$receipts) {
                    return [
                        "userId" => g($user, "id"),
                        "name" => g($user, "name"),
                        "acknowledgement" => js_or(
                            jm($receipts, "find", function ($item = null) use (
                                &$id,
                                &$notice,
                                &$user,
                            ) {
                                return js_and(
                                    js_and(
                                        g($item, "userId") === g($user, "id"),
                                        function () use (&$id, &$item) {
                                            return g($item, "noticeId") === $id;
                                        },
                                    ),
                                    function () use (&$notice, &$item) {
                                        return g($item, "version") ===
                                            g($notice, "version");
                                    },
                                );
                            }),
                            function () {
                                return null;
                            },
                        ),
                    ];
                },
            );
            $acknowledged = g(
                jm($people, "filter", function ($person = null) {
                    return g(g($person, "acknowledgement"), "acknowledgedAt");
                }),
                "length",
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "notice.acknowledgement_report",
                "notice",
                $id,
            );
            return [
                "noticeId" => $id,
                "version" => g($notice, "version"),
                "total" => g($people, "length"),
                "acknowledged" => $acknowledged,
                "percentage" => js_truth(g($people, "length"))
                    ? js_math_round(
                        ($acknowledged / g($people, "length")) * 100,
                    )
                    : 0,
                "people" => $people,
            ];
        });
    }
    public function calendar($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "calendar.read",
            );
            return jm(jm($tx, "list", "calendar"), "filter", function (
                $record = null,
            ) use (&$actor) {
                return js_or(
                    a_platform_access_audienceAllows(
                        g($actor, "user"),
                        g($record, "audience"),
                    ),
                    function () use (&$actor, &$record) {
                        return g($record, "createdBy") ===
                            g(g($actor, "user"), "id");
                    },
                );
            });
        });
    }
    public function createEvent($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "calendar.manage",
            );
            $record = [
                "id" => rid("EVT-"),
                "title" => text(g($body, "title"), 180, true),
                "description" => text(g($body, "description"), 2000),
                "date" => a_platform_validation_date(g($body, "date")),
                "category" => a_platform_validation_choose(
                    g($body, "category"),
                    [
                        "School",
                        "Meeting",
                        "Training",
                        "Examination",
                        "Worship",
                        "Deadline",
                        "Holiday",
                        "Sports",
                    ],
                ),
                "audience" => a_platform_validation_audience(
                    $tx,
                    g($body, "audience"),
                ),
                "createdBy" => g(g($actor, "user"), "id"),
                "createdAt" => jm(new JsDate(), "toISOString"),
            ];
            $saved = jm($tx, "insert", "calendar", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "calendar.create",
                "calendar_event",
                g($record, "id"),
            );
            return $saved;
        });
    }
    public function notifications($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notification.read",
            );
            return jm(
                jm(
                    jm(jm($tx, "list", "notifications"), "filter", function (
                        $item = null,
                    ) use (&$actor) {
                        return g($item, "userId") ===
                            g(g($actor, "user"), "id");
                    }),
                    "reverse",
                ),
                "slice",
                0,
                100,
            );
        });
    }
    public function readNotification($actor = null, $id = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "notification.read",
            );
            $record = jm($tx, "get", "notifications", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "userId") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(403, "Notification is not available.");
            }
            return jm($tx, "update", "notifications", [
                ...js_array($record),
                "readAt" => jm(new JsDate(), "toISOString"),
            ]);
        });
    }
}
