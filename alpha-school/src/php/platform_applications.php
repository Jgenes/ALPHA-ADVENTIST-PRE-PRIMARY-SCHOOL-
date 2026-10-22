<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_applications_applicationData($user = null, $body = null)
{
    $childName = text(g($body, "childName"), 160, true);
    $level = text(g($body, "level"), 40, true);
    $arrangement = a_platform_validation_choose(g($body, "arrangement"), [
        "Day",
        "Boarding",
    ]);
    $validLevels = [
        ...js_array(g(g(SEED, "academics"), "prePrimary")),
        ...js_array(g(g(SEED, "academics"), "primary")),
    ];
    if (js_truth(!js_truth(jm($validLevels, "includes", $level)))) {
        throw new HttpError(400, "Select a current school level.");
    }
    $guardianPhone = text(g($body, "guardianPhone"), 30, true);
    if (
        js_truth(
            !js_truth(
                jm(
                    new JsRegex('^\\+?[0-9 ()-]{7,24}$', ""),
                    "test",
                    $guardianPhone,
                ),
            ),
        )
    ) {
        throw new HttpError(400, "Enter a valid guardian telephone number.");
    }
    $guardianEmail = text(g($body, "guardianEmail"), 160);
    if (
        js_truth(
            js_and($guardianEmail, function () use (&$guardianEmail) {
                return !js_truth(
                    jm(
                        new JsRegex('^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$', ""),
                        "test",
                        $guardianEmail,
                    ),
                );
            }),
        )
    ) {
        throw new HttpError(400, "Enter a valid guardian email address.");
    }
    return [
        "child_name" => $childName,
        "guardian_name" => g($user, "name"),
        "phone" => $guardianPhone,
        "email" => $guardianEmail,
        "level" => $level,
        "arrangement" => $arrangement,
        "message" => text(g($body, "message"), 2000),
        "previous_school" => text(g($body, "previousSchool"), 160),
    ];
}
function a_platform_applications_parentApplication(
    $user = null,
    $record = null,
    $documents = [],
) {
    return [
        "id" => g($record, "id"),
        "reference" => g($record, "reference"),
        "status" => g($record, "status"),
        "data" => [
            "child_name" => g(g($record, "data"), "child_name"),
            "guardian_name" => g(g($record, "data"), "guardian_name"),
            "level" => g(g($record, "data"), "level"),
            "arrangement" => g(g($record, "data"), "arrangement"),
            "previous_school" => js_or(
                g(g($record, "data"), "previous_school"),
                function () {
                    return "";
                },
            ),
        ],
        "documents" => jm($documents, "map", function ($item = null) {
            return [
                "id" => g($item, "id"),
                "documentType" => g($item, "documentType"),
                "title" => g($item, "title"),
                "file" => public_file_metadata(g($item, "file")),
                "uploadedAt" => g($item, "createdAt"),
            ];
        }),
        "history" => jm(
            js_or(g($record, "history"), function () {
                return [];
            }),
            "map",
            function ($item = null) use (&$user) {
                return [
                    "from" => g($item, "from"),
                    "to" => g($item, "to"),
                    "note" => js_truth(g($item, "by") === g($user, "id"))
                        ? g($item, "note")
                        : "",
                    "at" => g($item, "at"),
                ];
            },
        ),
        "createdAt" => g($record, "createdAt"),
        "updatedAt" => js_or(g($record, "updatedAt"), function () use (
            &$record,
        ) {
            return g($record, "createdAt");
        }),
        "revision" => g($record, "revision"),
    ];
}
trait a_platform_applications_Methods
{
    public function myAdmissions($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "admission.read_self",
            );
            $records = jm(
                jm(jm($tx, "list", "admissions"), "filter", function (
                    $item = null,
                ) use (&$actor) {
                    return g($item, "applicantUserId") ===
                        g(g($actor, "user"), "id");
                }),
                "sort",
                function ($a = null, $b = null) {
                    return jm(
                        g($b, "createdAt"),
                        "localeCompare",
                        g($a, "createdAt"),
                    );
                },
            );
            $output = [];
            foreach (js_iter($records) as $item2455) {
                $record = $item2455;
                $documents = jm(
                    jm($tx, "list", "admission_documents"),
                    "filter",
                    function ($item = null) use (&$record) {
                        return g($item, "applicationId") === g($record, "id");
                    },
                );
                js_push(
                    $output,
                    a_platform_applications_parentApplication(
                        g($actor, "user"),
                        $record,
                        $documents,
                    ),
                );
            }
            return $output;
        });
    }
    public function createMyAdmission($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "admission.submit",
            );
            if (
                js_truth(
                    !js_truth(
                        jm(g(g($actor, "user"), "roles"), "includes", "parent"),
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "Only a parent or guardian account can submit an application.",
                );
            }
            $data = a_platform_applications_applicationData(
                g($actor, "user"),
                $body,
            );
            if (js_truth(g($body, "privacyConsent") !== true)) {
                throw new HttpError(
                    400,
                    "Confirm the privacy notice before saving this application.",
                );
            }
            $id =
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
            $now = jm(new JsDate(), "toISOString");
            $record = jm($tx, "insert", "admissions", [
                "id" => $id,
                "reference" => $id,
                "type" => "Admission application",
                "kind" => "apply",
                "source" => "authenticated_parent",
                "applicantUserId" => g(g($actor, "user"), "id"),
                "data" => $data,
                "status" => "DRAFT",
                "consent" => [
                    "purpose" => "admissions_review",
                    "policyVersion" => g(g($this, "config"), "policyVersion"),
                    "acceptedAt" => $now,
                ],
                "createdAt" => $now,
                "expiresAt" => js_add(
                    js_date_now(),
                    g(g($this, "config"), "admissionRetentionDays") * 86400000,
                ),
                "history" => [
                    [
                        "from" => "",
                        "to" => "DRAFT",
                        "note" => "",
                        "by" => g(g($actor, "user"), "id"),
                        "at" => $now,
                    ],
                ],
                "documentIds" => [],
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "admission.application_create",
                "admissions",
                $id,
            );
            return a_platform_applications_parentApplication(
                g($actor, "user"),
                $record,
            );
        });
    }
    public function updateMyAdmission($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "admission.submit",
            );
            $record = jm($tx, "get", "admissions", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "applicantUserId") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(404, "Application not found.");
            }
            a_platform_validation_revision($record, $body);
            if (
                js_truth(
                    !js_truth(
                        jm(
                            ["DRAFT", "DOCUMENTS_REQUIRED"],
                            "includes",
                            g($record, "status"),
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "Only a draft or returned application can be edited.",
                );
            }
            $updated = jm($tx, "update", "admissions", [
                ...js_array($record),
                "data" => a_platform_applications_applicationData(
                    g($actor, "user"),
                    $body,
                ),
                "updatedAt" => jm(new JsDate(), "toISOString"),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "admission.application_update",
                "admissions",
                $id,
            );
            return a_platform_applications_parentApplication(
                g($actor, "user"),
                $updated,
                jm(jm($tx, "list", "admission_documents"), "filter", function (
                    $item = null,
                ) use (&$id) {
                    return g($item, "applicationId") === $id;
                }),
            );
        });
    }
    public function uploadAdmissionDocument(
        $actor = null,
        $id = null,
        $body = null,
    ) {
        a_platform_access_requirePermission(
            g($actor, "user"),
            "admission.submit",
        );
        $file = jm(g($this, "files"), "prepare", g($body, "file"));
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$id,
                &$body,
                &$file,
            ) {
                $record = jm($tx, "get", "admissions", $id);
                if (
                    js_truth(
                        js_or(!js_truth($record), function () use (
                            &$actor,
                            &$record,
                        ) {
                            return g($record, "applicantUserId") !==
                                g(g($actor, "user"), "id");
                        }),
                    )
                ) {
                    throw new HttpError(404, "Application not found.");
                }
                if (
                    js_truth(
                        !js_truth(
                            jm(
                                ["DRAFT", "DOCUMENTS_REQUIRED"],
                                "includes",
                                g($record, "status"),
                            ),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Application documents can only be changed while a draft or correction is open.",
                    );
                }
                $document = jm($tx, "insert", "admission_documents", [
                    "id" => rid("ADOC-"),
                    "applicationId" => $id,
                    "ownerId" => g(g($actor, "user"), "id"),
                    "documentType" => text(g($body, "documentType"), 60, true),
                    "title" => text(g($body, "title"), 140, true),
                    "file" => $file,
                    "status" => "SUBMITTED",
                    "createdAt" => jm(new JsDate(), "toISOString"),
                ]);
                jm($tx, "update", "admissions", [
                    ...js_array($record),
                    "documentIds" => [
                        ...js_array(
                            new JsSet([
                                ...js_array(
                                    js_or(
                                        g($record, "documentIds"),
                                        function () {
                                            return [];
                                        },
                                    ),
                                ),
                                g($document, "id"),
                            ]),
                        ),
                    ],
                    "updatedAt" => jm(new JsDate(), "toISOString"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "admission.document_upload",
                    "admission_document",
                    g($document, "id"),
                    "success",
                    ["checksum" => g($file, "checksum")],
                );
                return [
                    "id" => g($document, "id"),
                    "documentType" => g($document, "documentType"),
                    "title" => g($document, "title"),
                    "file" => public_file_metadata($file),
                    "revision" => g($document, "revision"),
                ];
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function submitMyAdmission($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "admission.submit",
            );
            $record = jm($tx, "get", "admissions", $id);
            if (
                js_truth(
                    js_or(!js_truth($record), function () use (
                        &$actor,
                        &$record,
                    ) {
                        return g($record, "applicantUserId") !==
                            g(g($actor, "user"), "id");
                    }),
                )
            ) {
                throw new HttpError(404, "Application not found.");
            }
            a_platform_validation_revision($record, $body);
            if (
                js_truth(
                    !js_truth(
                        jm(
                            ["DRAFT", "DOCUMENTS_REQUIRED"],
                            "includes",
                            g($record, "status"),
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    409,
                    "This application is not ready for submission.",
                );
            }
            $now = jm(new JsDate(), "toISOString");
            $saved = jm($tx, "update", "admissions", [
                ...js_array($record),
                "status" => "SUBMITTED",
                "submittedAt" => $now,
                "updatedAt" => $now,
                "history" => [
                    ...js_array(
                        js_or(g($record, "history"), function () {
                            return [];
                        }),
                    ),
                    [
                        "from" => g($record, "status"),
                        "to" => "SUBMITTED",
                        "note" => "",
                        "by" => g(g($actor, "user"), "id"),
                        "at" => $now,
                    ],
                ],
            ]);
            foreach (js_iter(jm($tx, "list", "users")) as $item7100) {
                $user = $item7100;
                if (
                    js_truth(
                        js_and(g($user, "active"), function () use (&$user) {
                            return a_platform_access_has(
                                $user,
                                "admission.read",
                            );
                        }),
                    )
                ) {
                    a_platform_workflows_notify(
                        $tx,
                        g($user, "id"),
                        "New admission application: " .
                            js_string(g($saved, "reference")) .
                            "",
                        "/portal/admissions",
                        g($saved, "id"),
                    );
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "admission.application_submit",
                "admissions",
                $id,
            );
            return a_platform_applications_parentApplication(
                g($actor, "user"),
                $saved,
                jm(jm($tx, "list", "admission_documents"), "filter", function (
                    $item = null,
                ) use (&$id) {
                    return g($item, "applicationId") === $id;
                }),
            );
        });
    }
    public function admissionDocumentGrant($actor = null, $id = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
        ) {
            $document = jm($tx, "get", "admission_documents", $id);
            $application = js_and($document, function () use (
                &$tx,
                &$document,
            ) {
                return jm(
                    $tx,
                    "get",
                    "admissions",
                    g($document, "applicationId"),
                );
            });
            $allowed = js_and($application, function () use (
                &$actor,
                &$application,
            ) {
                return js_or(
                    js_and(
                        g($application, "applicantUserId") ===
                            g(g($actor, "user"), "id"),
                        function () use (&$actor) {
                            return a_platform_access_has(
                                g($actor, "user"),
                                "admission.read_self",
                            );
                        },
                    ),
                    function () use (&$actor) {
                        return a_platform_access_has(
                            g($actor, "user"),
                            "admission.read",
                        );
                    },
                );
            });
            if (js_truth(!js_truth($allowed))) {
                throw new HttpError(
                    403,
                    "You are not authorised to access this application document.",
                );
            }
            $token = jm(js_crypto_randomBytes(32), "toString", "hex");
            jm($tx, "insert", "download_grants", [
                "id" => sha256($token),
                "userId" => g(g($actor, "user"), "id"),
                "sessionId" => g(g($actor, "session"), "id"),
                "kind" => "admission_document",
                "resourceId" => $id,
                "checksum" => g(g($document, "file"), "checksum"),
                "expiresAt" => js_add(js_date_now(), 60000),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "admission.document_download_grant",
                "admission_document",
                $id,
            );
            return [
                "url" => js_add("/api/downloads/", $token),
                "expiresIn" => 60,
            ];
        });
    }
}
