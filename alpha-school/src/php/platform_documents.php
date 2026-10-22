<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_platform_documents_versionAllowed(
    $tx = null,
    $user = null,
    $version = null,
) {
    if (
        js_truth(
            js_or(!js_truth($version), function () use (&$user, &$version) {
                return !js_truth(
                    a_platform_access_canReadDocument($user, $version),
                );
            }),
        )
    ) {
        return false;
    }
    if (
        js_truth(
            js_and(
                jm(
                    ["ISSUED", "SUPERSEDED", "ARCHIVED"],
                    "includes",
                    g($version, "status"),
                ),
                function () use (&$version) {
                    return g($version, "publishedAt");
                },
            ),
        )
    ) {
        return true;
    }
    if (
        js_truth(
            js_and(g($version, "status") === "APPROVED", function () use (
                &$user,
            ) {
                return a_platform_access_has($user, "document.publish");
            }),
        )
    ) {
        return true;
    }
    if (js_truth(g($version, "createdBy") === g($user, "id"))) {
        return true;
    }
    $flow = js_and(g($version, "workflowId"), function () use (
        &$tx,
        &$version,
    ) {
        return jm($tx, "get", "workflow_instances", g($version, "workflowId"));
    });
    return js_and(
        js_and($flow, function () use (&$flow) {
            return g($flow, "status") === "PENDING";
        }),
        function () use (&$user, &$version, &$flow) {
            return a_platform_workflows_eligible($user, $flow, $version);
        },
    );
}
function a_platform_documents_downloadable(
    $tx = null,
    $user = null,
    $kind = null,
    $id = null,
) {
    if (js_truth($kind === "document")) {
        $version = jm($tx, "get", "document_versions", $id);
        return js_truth(
            a_platform_documents_versionAllowed($tx, $user, $version),
        )
            ? $version
            : null;
    }
    if (js_truth($kind === "contract")) {
        $contract = jm($tx, "get", "contracts", $id);
        if (js_truth(!js_truth($contract))) {
            return null;
        }
        $flow = js_and(g($contract, "workflowId"), function () use (
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
        return js_truth(
            a_platform_access_canReadContract(
                $user,
                $contract,
                js_and(g($flow, "status") === "PENDING", function () use (
                    &$user,
                    &$contract,
                    &$flow,
                ) {
                    return a_platform_workflows_eligible(
                        $user,
                        $flow,
                        $contract,
                    );
                }),
            ),
        )
            ? $contract
            : null;
    }
    if (js_truth($kind === "media")) {
        $media = jm($tx, "get", "media", $id);
        return js_truth(
            js_and($media, function () use (&$user) {
                return jm(
                    ["media.create", "media.review", "media.publish"],
                    "some",
                    function ($permission = null) use (&$user) {
                        return a_platform_access_has($user, $permission);
                    },
                );
            }),
        )
            ? $media
            : null;
    }
    if (js_truth($kind === "leave")) {
        $leave = jm($tx, "get", "leave_requests", $id);
        return js_truth(
            js_and($leave, function () use (&$user, &$leave) {
                return a_platform_access_canReadLeave($user, $leave);
            }),
        )
            ? $leave
            : null;
    }
    if (js_truth($kind === "admission_document")) {
        $document = jm($tx, "get", "admission_documents", $id);
        $application = js_and($document, function () use (&$tx, &$document) {
            return jm($tx, "get", "admissions", g($document, "applicationId"));
        });
        if (
            js_truth(
                js_and(
                    g($application, "applicantUserId") === g($user, "id"),
                    function () use (&$user) {
                        return a_platform_access_has(
                            $user,
                            "admission.read_self",
                        );
                    },
                ),
            )
        ) {
            return $document;
        }
        return js_truth(
            js_and($application, function () use (&$user) {
                return a_platform_access_has($user, "admission.read");
            }),
        )
            ? $document
            : null;
    }
    return null;
}
function a_platform_documents_documentView(
    $document = null,
    $current = null,
    $draft = null,
    $versions = null,
) {
    return [
        ...js_array($document),
        "current" => a_platform_validation_safeRecord($current),
        "draft" => a_platform_validation_safeRecord($draft),
        "versions" => jm($versions, "map", "a_platform_validation_safeRecord"),
    ];
}
trait a_platform_documents_Methods
{
    public function documents($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "document.read",
            );
            $out = [];
            foreach (js_iter(jm($tx, "list", "documents")) as $item2431) {
                $document = $item2431;
                $current = js_and(
                    g($document, "currentVersionId"),
                    function () use (&$tx, &$document) {
                        return jm(
                            $tx,
                            "get",
                            "document_versions",
                            g($document, "currentVersionId"),
                        );
                    },
                );
                $draft = js_and(
                    g($document, "draftVersionId"),
                    function () use (&$tx, &$document) {
                        return jm(
                            $tx,
                            "get",
                            "document_versions",
                            g($document, "draftVersionId"),
                        );
                    },
                );
                $visibleCurrent = js_truth(
                    js_and($current, function () use (
                        &$actor,
                        &$tx,
                        &$current,
                    ) {
                        return a_platform_documents_versionAllowed(
                            $tx,
                            g($actor, "user"),
                            $current,
                        );
                    }),
                )
                    ? $current
                    : null;
                $visibleDraft = js_truth(
                    js_and($draft, function () use (&$actor, &$tx, &$draft) {
                        return a_platform_documents_versionAllowed(
                            $tx,
                            g($actor, "user"),
                            $draft,
                        );
                    }),
                )
                    ? $draft
                    : null;
                if (
                    js_truth(
                        js_or($visibleCurrent, function () use (
                            &$visibleDraft,
                        ) {
                            return $visibleDraft;
                        }),
                    )
                ) {
                    $versions = [];
                    foreach (
                        js_iter(jm($tx, "list", "document_versions"))
                        as $item2999
                    ) {
                        $item = $item2999;
                        if (
                            js_truth(
                                js_and(
                                    js_and(
                                        g($item, "documentId") ===
                                            g($document, "id"),
                                        function () use (&$item) {
                                            return jm(
                                                [
                                                    "SUPERSEDED",
                                                    "ARCHIVED",
                                                    "RETIRED_DRAFT",
                                                ],
                                                "includes",
                                                g($item, "status"),
                                            );
                                        },
                                    ),
                                    function () use (&$actor, &$tx, &$item) {
                                        return a_platform_documents_versionAllowed(
                                            $tx,
                                            g($actor, "user"),
                                            $item,
                                        );
                                    },
                                ),
                            )
                        ) {
                            js_push($versions, $item);
                        }
                    }
                    js_push(
                        $out,
                        a_platform_documents_documentView(
                            $document,
                            $visibleCurrent,
                            $visibleDraft,
                            $versions,
                        ),
                    );
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "document.library_read",
                "document",
                "authorised",
            );
            return $out;
        });
    }
    public function createDocument(
        $actor = null,
        $body = null,
        $documentId = "",
    ) {
        a_platform_access_requirePermission(
            g($actor, "user"),
            "document.create",
        );
        $file = jm(g($this, "files"), "prepare", g($body, "file"));
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$body,
                &$documentId,
                &$file,
            ) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "document.create",
                );
                $document = null;
                if (js_truth($documentId)) {
                    $document = jm($tx, "get", "documents", $documentId);
                    if (
                        js_truth(
                            js_or(
                                js_or(!js_truth($document), function () use (
                                    &$actor,
                                    &$document,
                                ) {
                                    return !js_truth(
                                        a_platform_access_canReadDocument(
                                            g($actor, "user"),
                                            $document,
                                        ),
                                    );
                                }),
                                function () use (&$actor, &$document) {
                                    return js_and(
                                        g($document, "ownerId") !==
                                            g(g($actor, "user"), "id"),
                                        function () use (&$actor, &$document) {
                                            return g($document, "createdBy") !==
                                                g(g($actor, "user"), "id");
                                        },
                                    );
                                },
                            ),
                        )
                    ) {
                        throw new HttpError(
                            403,
                            "Only the document owner or author may create a revision.",
                        );
                    }
                    a_platform_validation_revision($document, $body);
                    $oldDraft = js_and(
                        g($document, "draftVersionId"),
                        function () use (&$tx, &$document) {
                            return jm(
                                $tx,
                                "get",
                                "document_versions",
                                g($document, "draftVersionId"),
                            );
                        },
                    );
                    if (
                        js_truth(
                            js_and($oldDraft, function () use (&$oldDraft) {
                                return jm(
                                    ["UNDER_REVIEW", "APPROVED"],
                                    "includes",
                                    g($oldDraft, "status"),
                                );
                            }),
                        )
                    ) {
                        throw new HttpError(
                            409,
                            "Complete the current review before creating another version.",
                        );
                    }
                    if (js_truth($oldDraft)) {
                        jm($tx, "update", "document_versions", [
                            ...js_array($oldDraft),
                            "status" => "RETIRED_DRAFT",
                        ]);
                    }
                } else {
                    $classification = a_platform_validation_choose(
                        g($body, "classification"),
                        a_platform_access_CLASSIFICATIONS,
                        "classification",
                    );
                    if (js_truth($classification === "CONFIDENTIAL")) {
                        a_platform_access_requirePermission(
                            g($actor, "user"),
                            "document.confidential",
                        );
                    }
                    if (js_truth($classification === "HIGHLY_CONFIDENTIAL")) {
                        a_platform_access_requirePermission(
                            g($actor, "user"),
                            "document.high_confidential",
                        );
                    }
                    $number = text(g($body, "documentNumber"), 64, true);
                    if (
                        js_truth(
                            !js_truth(
                                jm(
                                    new JsRegex('^[A-Z0-9-]{3,64}$', ""),
                                    "test",
                                    $number,
                                ),
                            ),
                        )
                    ) {
                        throw new HttpError(
                            400,
                            "Use an uppercase document number, e.g. ALPHA-HR-POL-001.",
                        );
                    }
                    if (
                        js_truth(
                            jm(jm($tx, "list", "documents"), "some", function (
                                $item = null,
                            ) use (&$number) {
                                return g($item, "documentNumber") === $number;
                            }),
                        )
                    ) {
                        throw new HttpError(
                            409,
                            "Document number exists. Add a version to the existing document instead.",
                        );
                    }
                    $access = a_platform_validation_audience($tx, [
                        "type" => "SELECTED",
                        "userIds" => [
                            g(g($actor, "user"), "id"),
                            ...js_array(
                                a_platform_validation_strings(
                                    g(g($body, "access"), "userIds"),
                                ),
                            ),
                        ],
                        "roles" => a_platform_validation_strings(
                            g(g($body, "access"), "roles"),
                        ),
                        "departmentIds" => a_platform_validation_strings(
                            g(g($body, "access"), "departmentIds"),
                        ),
                    ]);
                    $draft = [
                        "id" => rid("DOC-"),
                        "documentNumber" => $number,
                        "title" => text(g($body, "title"), 180, true),
                        "description" => text(g($body, "description"), 2000),
                        "category" => a_platform_validation_choose(
                            g($body, "category"),
                            [
                                "Admissions",
                                "Parents",
                                "Academic",
                                "Careers",
                                "Public policies",
                                "HR",
                                "Administration",
                                "ICT",
                                "Child protection",
                                "Safety",
                            ],
                        ),
                        "classification" => $classification,
                        "ownerId" => g(g($actor, "user"), "id"),
                        "departmentId" => js_or(
                            g(g($actor, "user"), "departmentId"),
                            function () {
                                return "";
                            },
                        ),
                        "access" => $access,
                        "createdBy" => g(g($actor, "user"), "id"),
                        "createdAt" => jm(new JsDate(), "toISOString"),
                        "currentVersionId" => "",
                        "draftVersionId" => "",
                        "lastVersion" => 0,
                        "status" => "DRAFT",
                    ];
                    if (
                        js_truth(
                            js_and(
                                $classification === "PUBLIC",
                                function () use (&$draft) {
                                    return jm(
                                        ["HR", "Child protection"],
                                        "includes",
                                        g($draft, "category"),
                                    );
                                },
                            ),
                        )
                    ) {
                        throw new HttpError(
                            400,
                            "Use Public policies for explicitly approved public guidance. HR and child-protection records are private.",
                        );
                    }
                    $document = jm($tx, "insert", "documents", $draft);
                }
                $version = jm($tx, "insert", "document_versions", [
                    "id" => rid("VER-"),
                    "documentId" => g($document, "id"),
                    "documentNumber" => g($document, "documentNumber"),
                    "title" => g($document, "title"),
                    "ownerId" => g($document, "ownerId"),
                    "departmentId" => g($document, "departmentId"),
                    "access" => g($document, "access"),
                    "classification" => g($document, "classification"),
                    "version" => js_add(g($document, "lastVersion"), 1),
                    "file" => $file,
                    "effectiveDate" => a_platform_validation_date(
                        g($body, "effectiveDate"),
                        true,
                    ),
                    "reviewDate" => a_platform_validation_date(
                        g($body, "reviewDate"),
                        true,
                    ),
                    "expiryDate" => a_platform_validation_date(
                        g($body, "expiryDate"),
                        true,
                    ),
                    "changeSummary" => text(
                        g($body, "changeSummary"),
                        1000,
                        true,
                    ),
                    "createdBy" => g(g($actor, "user"), "id"),
                    "createdAt" => jm(new JsDate(), "toISOString"),
                    "status" => "DRAFT",
                ]);
                $saved = jm($tx, "update", "documents", [
                    ...js_array($document),
                    "lastVersion" => g($version, "version"),
                    "draftVersionId" => g($version, "id"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "document.upload",
                    "document_version",
                    g($version, "id"),
                    "success",
                    [
                        "checksum" => g($file, "checksum"),
                        "classification" => g($document, "classification"),
                        "version" => g($version, "version"),
                    ],
                );
                return a_platform_documents_documentView(
                    $saved,
                    null,
                    $version,
                    [],
                );
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function documentAction($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            $version = jm($tx, "get", "document_versions", $id);
            if (
                js_truth(
                    js_or(!js_truth($version), function () use (
                        &$actor,
                        &$version,
                    ) {
                        return !js_truth(
                            a_platform_access_canReadDocument(
                                g($actor, "user"),
                                $version,
                            ),
                        );
                    }),
                )
            ) {
                throw new HttpError(
                    403,
                    "This document is not available to you.",
                );
            }
            a_platform_validation_revision($version, $body);
            $document = jm($tx, "get", "documents", g($version, "documentId"));
            if (js_truth(g($body, "action") === "SUBMIT")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "document.create",
                );
                if (
                    js_truth(
                        js_or(
                            js_or(
                                g($version, "createdBy") !==
                                    g(g($actor, "user"), "id"),
                                function () use (&$version) {
                                    return !js_truth(
                                        jm(
                                            ["DRAFT", "RETURNED"],
                                            "includes",
                                            g($version, "status"),
                                        ),
                                    );
                                },
                            ),
                            function () use (&$version, &$document) {
                                return g($document, "draftVersionId") !==
                                    g($version, "id");
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Only the current draft author can submit for review.",
                    );
                }
                $definitionId = js_or(
                    text(g($body, "workflowId"), 100),
                    function () {
                        return "document-standard";
                    },
                );
                if (
                    js_truth(
                        g(
                            jm(
                                $tx,
                                "get",
                                "workflow_definitions",
                                $definitionId,
                            ),
                            "kind",
                        ) !== "CONTROLLED_DOCUMENT",
                    )
                ) {
                    throw new HttpError(400, "Choose a document workflow.");
                }
                $flow = a_platform_workflows_startWorkflow(
                    $tx,
                    $actor,
                    g($this, "config"),
                    $definitionId,
                    $version,
                );
                return a_platform_validation_safeRecord(
                    jm($tx, "update", "document_versions", [
                        ...js_array($version),
                        "workflowId" => g($flow, "id"),
                        "status" => "UNDER_REVIEW",
                    ]),
                );
            }
            if (js_truth(g($body, "action") === "PUBLISH")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "document.publish",
                );
                if (
                    js_truth(
                        js_or(
                            js_or(
                                g($version, "createdBy") ===
                                    g(g($actor, "user"), "id"),
                                function () use (&$version) {
                                    return g($version, "status") !== "APPROVED";
                                },
                            ),
                            function () use (&$version, &$document) {
                                return g($document, "draftVersionId") !==
                                    g($version, "id");
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "An independent publisher can issue only the approved current version.",
                    );
                }
                $current = js_and(
                    g($document, "currentVersionId"),
                    function () use (&$tx, &$document) {
                        return jm(
                            $tx,
                            "get",
                            "document_versions",
                            g($document, "currentVersionId"),
                        );
                    },
                );
                if (js_truth($current)) {
                    jm($tx, "update", "document_versions", [
                        ...js_array($current),
                        "status" => "SUPERSEDED",
                    ]);
                }
                $published = jm($tx, "update", "document_versions", [
                    ...js_array($version),
                    "status" => "ISSUED",
                    "publishedAt" => jm(new JsDate(), "toISOString"),
                    "publishedBy" => g(g($actor, "user"), "id"),
                ]);
                jm($tx, "update", "documents", [
                    ...js_array($document),
                    "currentVersionId" => g($version, "id"),
                    "draftVersionId" => "",
                    "status" => "ISSUED",
                ]);
                if (js_truth(g($version, "classification") === "PUBLIC")) {
                    jm(g($this, "files"), "publish", g($version, "file"));
                    if (js_truth($current)) {
                        jm(g($this, "files"), "unpublish", g($current, "file"));
                    }
                }
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "document.publish",
                    "document_version",
                    $id,
                    "success",
                    [
                        "version" => g($version, "version"),
                        "classification" => g($version, "classification"),
                    ],
                );
                return a_platform_validation_safeRecord($published);
            }
            if (js_truth(g($body, "action") === "ARCHIVE")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "document.archive",
                );
                if (
                    js_truth(
                        !js_truth(
                            jm(
                                ["ISSUED", "SUPERSEDED"],
                                "includes",
                                g($version, "status"),
                            ),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Only an issued or superseded version can be archived.",
                    );
                }
                $saved = jm($tx, "update", "document_versions", [
                    ...js_array($version),
                    "status" => "ARCHIVED",
                    "archivedAt" => jm(new JsDate(), "toISOString"),
                ]);
                if (
                    js_truth(
                        g($document, "currentVersionId") === g($version, "id"),
                    )
                ) {
                    jm($tx, "update", "documents", [
                        ...js_array($document),
                        "status" => "ARCHIVED",
                    ]);
                }
                jm(g($this, "files"), "unpublish", g($version, "file"));
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "document.archive",
                    "document_version",
                    $id,
                );
                return a_platform_validation_safeRecord($saved);
            }
            throw new HttpError(400, "Choose submit, publish or archive.");
        });
    }
    public function publicDownloads()
    {
        return jm(g($this, "store"), "run", function ($tx = null) {
            $out = [];
            $today = jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
            foreach (js_iter(jm($tx, "list", "documents")) as $item10692) {
                $document = $item10692;
                if (
                    js_truth(
                        js_or(
                            g($document, "classification") !== "PUBLIC",
                            function () use (&$document) {
                                return g($document, "status") !== "ISSUED";
                            },
                        ),
                    )
                ) {
                    continue;
                }
                $version = jm(
                    $tx,
                    "get",
                    "document_versions",
                    g($document, "currentVersionId"),
                );
                if (
                    js_truth(
                        js_or(
                            js_or(
                                js_or(!js_truth($version), function () use (
                                    &$version,
                                ) {
                                    return g($version, "status") !== "ISSUED";
                                }),
                                function () use (&$today, &$version) {
                                    return js_and(
                                        g($version, "effectiveDate"),
                                        function () use (&$today, &$version) {
                                            return g(
                                                $version,
                                                "effectiveDate",
                                            ) > $today;
                                        },
                                    );
                                },
                            ),
                            function () use (&$today, &$version) {
                                return js_and(
                                    g($version, "expiryDate"),
                                    function () use (&$today, &$version) {
                                        return g($version, "expiryDate") <
                                            $today;
                                    },
                                );
                            },
                        ),
                    )
                ) {
                    continue;
                }
                js_push($out, [
                    "id" => g($document, "id"),
                    "documentNumber" => g($document, "documentNumber"),
                    "title" => g($document, "title"),
                    "category" => g($document, "category"),
                    "description" => g($document, "description"),
                    "version" => g($version, "version"),
                    "publishedAt" => g($version, "publishedAt"),
                    "size" => g(g($version, "file"), "size"),
                    "name" => g(g($version, "file"), "name"),
                ]);
            }
            return $out;
        });
    }
    public function publicDownload($id = null, $actor = null)
    {
        return jm(g($this, "store"), "run", function ($tx = null) use (
            &$id,
            &$actor,
        ) {
            $document = jm($tx, "get", "documents", $id);
            if (
                js_truth(
                    js_or(
                        js_or(!js_truth($document), function () use (
                            &$document,
                        ) {
                            return g($document, "classification") !== "PUBLIC";
                        }),
                        function () use (&$document) {
                            return g($document, "status") !== "ISSUED";
                        },
                    ),
                )
            ) {
                throw new HttpError(404, "Public download not found.");
            }
            $version = jm(
                $tx,
                "get",
                "document_versions",
                g($document, "currentVersionId"),
            );
            $today = jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(!js_truth($version), function () use (
                                &$version,
                            ) {
                                return g($version, "status") !== "ISSUED";
                            }),
                            function () use (&$version, &$today) {
                                return js_and(
                                    g($version, "effectiveDate"),
                                    function () use (&$version, &$today) {
                                        return g($version, "effectiveDate") >
                                            $today;
                                    },
                                );
                            },
                        ),
                        function () use (&$version, &$today) {
                            return js_and(
                                g($version, "expiryDate"),
                                function () use (&$version, &$today) {
                                    return g($version, "expiryDate") < $today;
                                },
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    404,
                    "This download is not currently published.",
                );
            }
            $buffer = jm(
                g($this, "files"),
                "read",
                g($version, "file"),
                "public",
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "document.public_download",
                "document_version",
                g($version, "id"),
            );
            return ["buffer" => $buffer, "file" => g($version, "file")];
        });
    }
    public function downloadGrant($actor = null, $kind = null, $id = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$kind,
            &$id,
        ) {
            $record = a_platform_documents_downloadable(
                $tx,
                g($actor, "user"),
                $kind,
                $id,
            );
            if (js_truth(!js_truth(g($record, "file")))) {
                throw new HttpError(
                    403,
                    "You are not authorised to download this file.",
                );
            }
            $token = jm(js_crypto_randomBytes(32), "toString", "hex");
            jm($tx, "insert", "download_grants", [
                "id" => sha256($token),
                "userId" => g(g($actor, "user"), "id"),
                "sessionId" => g(g($actor, "session"), "id"),
                "kind" => $kind,
                "resourceId" => $id,
                "checksum" => g(g($record, "file"), "checksum"),
                "expiresAt" => js_add(js_date_now(), 60000),
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "document.download_grant",
                $kind,
                $id,
            );
            return [
                "url" => js_add("/api/downloads/", $token),
                "expiresIn" => 60,
            ];
        });
    }
    public function privateDownload($actor = null, $token = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$token,
        ) {
            $grant = jm($tx, "get", "download_grants", sha256($token));
            if (
                js_truth(
                    js_or(
                        js_or(
                            js_or(!js_truth($grant), function () use (
                                &$actor,
                                &$grant,
                            ) {
                                return g($grant, "userId") !==
                                    g(g($actor, "user"), "id");
                            }),
                            function () use (&$actor, &$grant) {
                                return g($grant, "sessionId") !==
                                    g(g($actor, "session"), "id");
                            },
                        ),
                        function () use (&$grant) {
                            return g($grant, "expiresAt") <= js_date_now();
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    403,
                    "This download link expired or belongs to another session.",
                );
            }
            $record = a_platform_documents_downloadable(
                $tx,
                g($actor, "user"),
                g($grant, "kind"),
                g($grant, "resourceId"),
            );
            if (
                js_truth(
                    js_or(!js_truth(g($record, "file")), function () use (
                        &$grant,
                        &$record,
                    ) {
                        return g(g($record, "file"), "checksum") !==
                            g($grant, "checksum");
                    }),
                )
            ) {
                throw new HttpError(
                    403,
                    "Access to this version is no longer authorised.",
                );
            }
            $buffer = jm(g($this, "files"), "read", g($record, "file"));
            jm($tx, "remove", "download_grants", g($grant, "id"));
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "document.download",
                g($grant, "kind"),
                g($record, "id"),
            );
            return ["buffer" => $buffer, "file" => g($record, "file")];
        });
    }
}
