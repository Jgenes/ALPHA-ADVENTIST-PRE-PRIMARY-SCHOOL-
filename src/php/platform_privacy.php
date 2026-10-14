<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_platform_privacy_CHANNELS", [
    "website",
    "facebook",
    "instagram",
    "youtube",
    "print",
    "externalPromotion",
]);
function a_platform_privacy_currentConsent(
    $tx = null,
    $studentRef = null,
    $channel = "website",
) {
    $head = jm(
        $tx,
        "get",
        "system_settings",
        js_add("consent-current:", sha256($studentRef)),
    );
    $latest = js_truth($head)
        ? jm($tx, "get", "media_consents", g($head, "recordId"))
        : null;
    $today = jm(jm(new JsDate(), "toISOString"), "slice", 0, 10);
    return js_and(
        js_and(
            js_and(
                js_and(!js_truth(!js_truth($latest)), function () use (
                    &$latest,
                ) {
                    return g($latest, "status") === "ACTIVE";
                }),
                function () use (&$channel, &$latest) {
                    return g(g($latest, "channels"), $channel) === true;
                },
            ),
            function () use (&$latest, &$today) {
                return g($latest, "grantedAt") <= $today;
            },
        ),
        function () use (&$latest, &$today) {
            return g($latest, "expiresAt") >= $today;
        },
    );
}
function a_platform_privacy_validMediaConsent($tx = null, $record = null)
{
    if (
        js_truth(
            js_or(!js_truth(g($record, "subjectsVerified")), function () use (
                &$record,
            ) {
                return !js_truth(g($record, "noChildNames"));
            }),
        )
    ) {
        return false;
    }
    if (js_truth(!js_truth(g($record, "childrenPresent")))) {
        return g(g($record, "studentRefs"), "length") === 0;
    }
    if (js_truth(!js_truth(g(g($record, "studentRefs"), "length")))) {
        return false;
    }
    foreach (js_iter(g($record, "studentRefs")) as $item979) {
        $ref = $item979;
        if (js_truth(!js_truth(a_platform_privacy_currentConsent($tx, $ref)))) {
            return false;
        }
    }
    return true;
}
function a_platform_privacy_mediaIsPublic($tx = null, $id = null)
{
    $record = js_truth(js_typeof($id) === "string")
        ? jm($tx, "get", "media", $id)
        : $id;
    return js_and(
        js_and(!js_truth(!js_truth($record)), function () use (&$record) {
            return g($record, "status") === "PUBLISHED";
        }),
        function () use (&$tx, &$record) {
            return a_platform_privacy_validMediaConsent($tx, $record);
        },
    );
}
trait a_platform_privacy_Methods
{
    public function privacyRecords($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "privacy.register_read",
                "privacy",
                "authorised",
            );
            return [
                "consents" => jm($tx, "list", "media_consents"),
                "requests" => jm($tx, "list", "privacy_requests"),
                "incidents" => jm($tx, "list", "incidents"),
            ];
        });
    }
    public function saveConsent($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $studentRef = text(g($body, "studentRef"), 80, true);
            if (
                js_truth(
                    !js_truth(
                        jm(
                            new JsRegex('^[A-Z0-9-]{3,80}$', ""),
                            "test",
                            $studentRef,
                        ),
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Use a private pupil reference, not the child’s name.",
                );
            }
            $grantedAt = a_platform_validation_date(g($body, "grantedAt"));
            $expiresAt = a_platform_validation_date(g($body, "expiresAt"));
            if (
                js_truth(
                    js_or(
                        js_or(
                            $grantedAt >
                                jm(
                                    jm(new JsDate(), "toISOString"),
                                    "slice",
                                    0,
                                    10,
                                ),
                            function () use (&$grantedAt, &$expiresAt) {
                                return $expiresAt <= $grantedAt;
                            },
                        ),
                        function () use (&$grantedAt, &$expiresAt) {
                            return js_date_parse($expiresAt) -
                                js_date_parse($grantedAt) >
                                366 * 86400000;
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Consent must be verified, dated and reviewed within one year.",
                );
            }
            if (
                js_truth(
                    js_or(!js_truth(g($body, "channels")), function () use (
                        &$body,
                    ) {
                        return jm(
                            a_platform_privacy_CHANNELS,
                            "some",
                            function ($channel = null) use (&$body) {
                                return js_typeof(
                                    g(g($body, "channels"), $channel),
                                ) !== "boolean";
                            },
                        );
                    }),
                )
            ) {
                throw new HttpError(
                    400,
                    "Record a separate grant or refusal for every media channel.",
                );
            }
            $evidence = jm(
                $tx,
                "get",
                "document_versions",
                text(g($body, "evidenceVersionId"), 100, true),
            );
            if (
                js_truth(
                    js_or(
                        js_or(!js_truth($evidence), function () use (
                            &$evidence,
                        ) {
                            return !js_truth(
                                jm(
                                    ["CONFIDENTIAL", "HIGHLY_CONFIDENTIAL"],
                                    "includes",
                                    g($evidence, "classification"),
                                ),
                            );
                        }),
                        function () use (&$actor, &$evidence) {
                            return !js_truth(
                                a_platform_access_canReadDocument(
                                    g($actor, "user"),
                                    $evidence,
                                ),
                            );
                        },
                    ),
                )
            ) {
                throw new HttpError(
                    400,
                    "Attach a confidential signed-consent evidence document you are authorised to access.",
                );
            }
            if (js_truth(g($body, "authorityVerified") !== true)) {
                throw new HttpError(
                    400,
                    "Verify the guardian’s authority before recording consent.",
                );
            }
            $channels = js_object_fromEntries(
                jm(a_platform_privacy_CHANNELS, "map", function (
                    $channel = null,
                ) use (&$body) {
                    return [$channel, g(g($body, "channels"), $channel)];
                }),
            );
            $record = [
                "id" => rid("CON-"),
                "studentRef" => $studentRef,
                "guardianName" => text(g($body, "guardianName"), 160, true),
                "relationship" => a_platform_validation_choose(
                    g($body, "relationship"),
                    ["Parent", "Legal guardian"],
                ),
                "grantedAt" => $grantedAt,
                "expiresAt" => $expiresAt,
                "channels" => $channels,
                "evidenceVersionId" => g($evidence, "id"),
                "authorityVerifiedBy" => g(g($actor, "user"), "id"),
                "status" => js_truth(
                    jm(js_object_values($channels), "some", "js_truth"),
                )
                    ? "ACTIVE"
                    : "REFUSED",
                "recordedAt" => jm(new JsDate(), "toISOString"),
                "recordedBy" => g(g($actor, "user"), "id"),
                "withdrawal" => null,
            ];
            $saved = jm($tx, "insert", "media_consents", $record);
            $pointerId = js_add("consent-current:", sha256($studentRef));
            $pointer = jm($tx, "get", "system_settings", $pointerId);
            if (js_truth($pointer)) {
                jm($tx, "update", "system_settings", [
                    ...js_array($pointer),
                    "recordId" => g($saved, "id"),
                ]);
            } else {
                jm($tx, "insert", "system_settings", [
                    "id" => $pointerId,
                    "recordId" => g($saved, "id"),
                ]);
            }
            foreach (js_iter(jm($tx, "list", "media")) as $item4032) {
                $media = $item4032;
                if (
                    js_truth(
                        js_and(
                            js_and(
                                jm(
                                    g($media, "studentRefs"),
                                    "includes",
                                    $studentRef,
                                ),
                                function () use (&$media) {
                                    return g($media, "status") === "PUBLISHED";
                                },
                            ),
                            function () use (&$tx, &$media) {
                                return !js_truth(
                                    a_platform_privacy_validMediaConsent(
                                        $tx,
                                        $media,
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    jm($tx, "update", "media", [
                        ...js_array($media),
                        "status" => "WITHDRAWN",
                    ]);
                    jm(g($this, "files"), "unpublish", g($media, "file"));
                }
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "consent.record",
                "media_consent",
                g($saved, "id"),
                "success",
                ["status" => g($saved, "status")],
            );
            return $saved;
        });
    }
    public function withdrawConsent($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $record = jm($tx, "get", "media_consents", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Consent record not found.");
            }
            a_platform_validation_revision($record, $body);
            if (js_truth(g($record, "status") === "WITHDRAWN")) {
                throw new HttpError(409, "This consent is already withdrawn.");
            }
            $now = new JsDate();
            $deadline = jm(
                new JsDate(js_add(js_date_now(), 48 * 60 * 60 * 1000)),
                "toISOString",
            );
            $withdrawal = [
                "at" => jm($now, "toISOString"),
                "verifiedBy" => g(g($actor, "user"), "id"),
                "reason" => text(g($body, "reason"), 1000, true),
                "removalDeadline" => $deadline,
                "websiteRemovedAt" => jm($now, "toISOString"),
                "externalChannels" => jm(
                    a_platform_privacy_CHANNELS,
                    "filter",
                    function ($channel = null) use (&$record) {
                        return js_and($channel !== "website", function () use (
                            &$record,
                            &$channel,
                        ) {
                            return g(g($record, "channels"), $channel);
                        });
                    },
                ),
                "externalRemovalConfirmedAt" => null,
            ];
            $saved = jm($tx, "update", "media_consents", [
                ...js_array($record),
                "status" => "WITHDRAWN",
                "withdrawal" => $withdrawal,
            ]);
            foreach (js_iter(jm($tx, "list", "media")) as $item5453) {
                $media = $item5453;
                if (
                    js_truth(
                        js_and(
                            jm(
                                g($media, "studentRefs"),
                                "includes",
                                g($record, "studentRef"),
                            ),
                            function () use (&$media) {
                                return g($media, "status") === "PUBLISHED";
                            },
                        ),
                    )
                ) {
                    jm($tx, "update", "media", [
                        ...js_array($media),
                        "status" => "WITHDRAWN",
                        "withdrawnAt" => jm($now, "toISOString"),
                    ]);
                    jm(g($this, "files"), "unpublish", g($media, "file"));
                }
            }
            a_platform_workflows_notify(
                $tx,
                g(g($actor, "user"), "id"),
                "Consent withdrawn: confirm removal from external channels within 48 hours",
                "/portal/privacy",
                $id,
            );
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "consent.withdraw",
                "media_consent",
                $id,
                "success",
                ["removalDeadline" => $deadline],
            );
            return $saved;
        });
    }
    public function confirmConsentRemoval(
        $actor = null,
        $id = null,
        $body = null,
    ) {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $record = jm($tx, "get", "media_consents", $id);
            if (js_truth(!js_truth(g($record, "withdrawal")))) {
                throw new HttpError(409, "No withdrawal is pending.");
            }
            a_platform_validation_revision($record, $body);
            $saved = jm($tx, "update", "media_consents", [
                ...js_array($record),
                "withdrawal" => [
                    ...js_array(g($record, "withdrawal")),
                    "externalRemovalConfirmedAt" => jm(
                        new JsDate(),
                        "toISOString",
                    ),
                    "removalConfirmedBy" => g(g($actor, "user"), "id"),
                    "removalNote" => text(g($body, "comment"), 1000, true),
                ],
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "consent.removal_confirmed",
                "media_consent",
                $id,
            );
            return $saved;
        });
    }
    public function resolvePrivacyRequest(
        $actor = null,
        $id = null,
        $body = null,
    ) {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $record = jm($tx, "get", "privacy_requests", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Request not found.");
            }
            a_platform_validation_revision($record, $body);
            $status = a_platform_validation_choose(g($body, "status"), [
                "UNDER_REVIEW",
                "VERIFICATION_REQUIRED",
                "RESOLVED",
            ]);
            js_push($record["history"], [
                "status" => $status,
                "comment" => text(g($body, "comment"), 2000, true),
                "by" => g(g($actor, "user"), "id"),
                "at" => jm(new JsDate(), "toISOString"),
            ]);
            $record["status"] = $status;
            $saved = jm($tx, "update", "privacy_requests", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "privacy.request_update",
                "privacy_request",
                $id,
                "success",
                ["status" => $status],
            );
            return $saved;
        });
    }
    public function createIncident($actor = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $record = jm($tx, "insert", "incidents", [
                "id" => rid("INC-"),
                "title" => text(g($body, "title"), 160, true),
                "description" => text(g($body, "description"), 4000, true),
                "status" => "OPEN",
                "severity" => a_platform_validation_choose(
                    g($body, "severity"),
                    ["Low", "Medium", "High", "Critical"],
                ),
                "reportedBy" => g(g($actor, "user"), "id"),
                "createdAt" => jm(new JsDate(), "toISOString"),
                "actions" => [],
            ]);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "privacy.incident_create",
                "incident",
                g($record, "id"),
            );
            return $record;
        });
    }
    public function updateIncident($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            a_platform_access_requirePermission(
                g($actor, "user"),
                "privacy.manage",
            );
            $record = jm($tx, "get", "incidents", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Incident not found.");
            }
            a_platform_validation_revision($record, $body);
            js_push($record["actions"], [
                "comment" => text(g($body, "comment"), 2000, true),
                "by" => g(g($actor, "user"), "id"),
                "at" => jm(new JsDate(), "toISOString"),
            ]);
            $record["status"] = a_platform_validation_choose(
                g($body, "status"),
                ["OPEN", "INVESTIGATING", "CONTAINED", "CLOSED"],
            );
            $saved = jm($tx, "update", "incidents", $record);
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "privacy.incident_update",
                "incident",
                $id,
                "success",
                ["status" => g($record, "status")],
            );
            return $saved;
        });
    }
    public function media($actor = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) {
            if (
                js_truth(
                    !js_truth(
                        jm(
                            ["media.create", "media.review", "media.publish"],
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
                throw new HttpError(403, "Media management is restricted.");
            }
            $result = [];
            foreach (js_iter(jm($tx, "list", "media")) as $item9164) {
                $item = $item9164;
                js_push($result, [
                    ...js_array(a_platform_validation_safeRecord($item)),
                    "consentCurrent" => a_platform_privacy_validMediaConsent(
                        $tx,
                        $item,
                    ),
                ]);
            }
            audit(
                $tx,
                g($this, "config"),
                $actor,
                "media.library_read",
                "media",
                "authorised",
            );
            return $result;
        });
    }
    public function createMedia($actor = null, $body = null)
    {
        a_platform_access_requirePermission(g($actor, "user"), "media.create");
        $file = jm(g($this, "files"), "prepare", g($body, "file"), true);
        try {
            return $this->run($actor, function ($tx = null, $actor = null) use (
                &$body,
                &$file,
            ) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "media.create",
                );
                if (
                    js_truth(
                        js_or(
                            js_or(
                                js_typeof(g($body, "childrenPresent")) !==
                                    "boolean",
                                function () use (&$body) {
                                    return g($body, "subjectsVerified") !==
                                        true;
                                },
                            ),
                            function () use (&$body) {
                                return g($body, "noChildNames") !== true;
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Confirm the subjects have been checked and that captions contain no child names.",
                    );
                }
                $studentRefs = a_platform_validation_strings(
                    g($body, "studentRefs"),
                );
                if (
                    js_truth(
                        js_truth(g($body, "childrenPresent"))
                            ? !js_truth(g($studentRefs, "length"))
                            : g($studentRefs, "length") > 0,
                    )
                ) {
                    throw new HttpError(
                        400,
                        "Record every depicted child’s private consent reference, or confirm no children are depicted.",
                    );
                }
                $record = [
                    "id" => rid("MED-"),
                    "title" => text(g($body, "title"), 160, true),
                    "alt" => text(g($body, "alt"), 300, true),
                    "category" => text(g($body, "category"), 100, true),
                    "studentRefs" => $studentRefs,
                    "childrenPresent" => g($body, "childrenPresent"),
                    "subjectsVerified" => true,
                    "noChildNames" => true,
                    "file" => $file,
                    "status" => "DRAFT",
                    "createdBy" => g(g($actor, "user"), "id"),
                    "createdAt" => jm(new JsDate(), "toISOString"),
                ];
                if (
                    js_truth(
                        !js_truth(
                            a_platform_privacy_validMediaConsent($tx, $record),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Every child needs a current, independently recorded website consent before this media can be submitted.",
                    );
                }
                $saved = jm($tx, "insert", "media", $record);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "media.upload",
                    "media",
                    g($record, "id"),
                    "success",
                    ["checksum" => g($file, "checksum")],
                );
                return a_platform_validation_safeRecord($saved);
            });
        } catch (\Throwable $error) {
            jm(g($this, "files"), "discard", $file);
            throw $error;
        }
    }
    public function mediaAction($actor = null, $id = null, $body = null)
    {
        return $this->run($actor, function ($tx = null, $actor = null) use (
            &$id,
            &$body,
        ) {
            $record = jm($tx, "get", "media", $id);
            if (js_truth(!js_truth($record))) {
                throw new HttpError(404, "Media not found.");
            }
            a_platform_validation_revision($record, $body);
            if (js_truth(g($body, "action") === "SUBMIT")) {
                a_platform_access_requirePermission(
                    g($actor, "user"),
                    "media.create",
                );
                if (
                    js_truth(
                        js_or(
                            g($record, "createdBy") !==
                                g(g($actor, "user"), "id"),
                            function () use (&$record) {
                                return !js_truth(
                                    jm(
                                        ["DRAFT", "RETURNED"],
                                        "includes",
                                        g($record, "status"),
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        403,
                        "Only the author can submit a draft.",
                    );
                }
                if (
                    js_truth(
                        !js_truth(
                            a_platform_privacy_validMediaConsent($tx, $record),
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Current media consent is missing.",
                    );
                }
                $flow = a_platform_workflows_startWorkflow(
                    $tx,
                    $actor,
                    g($this, "config"),
                    "media-standard",
                    $record,
                );
                return a_platform_validation_safeRecord(
                    jm($tx, "update", "media", [
                        ...js_array($record),
                        "workflowId" => g($flow, "id"),
                        "status" => "UNDER_REVIEW",
                    ]),
                );
            }
            a_platform_access_requirePermission(
                g($actor, "user"),
                "media.publish",
            );
            if (js_truth(g($body, "action") === "PUBLISH")) {
                if (
                    js_truth(
                        js_or(
                            js_or(
                                g($record, "status") !== "APPROVED",
                                function () use (&$actor, &$record) {
                                    return g($record, "createdBy") ===
                                        g(g($actor, "user"), "id");
                                },
                            ),
                            function () use (&$tx, &$record) {
                                return !js_truth(
                                    a_platform_privacy_validMediaConsent(
                                        $tx,
                                        $record,
                                    ),
                                );
                            },
                        ),
                    )
                ) {
                    throw new HttpError(
                        409,
                        "Independent approval and current consent are required before publication.",
                    );
                }
                jm(g($this, "files"), "publish", g($record, "file"));
                $saved = jm($tx, "update", "media", [
                    ...js_array($record),
                    "status" => "PUBLISHED",
                    "publishedAt" => jm(new JsDate(), "toISOString"),
                    "publishedBy" => g(g($actor, "user"), "id"),
                ]);
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "media.publish",
                    "media",
                    $id,
                );
                return a_platform_validation_safeRecord($saved);
            }
            if (js_truth(g($body, "action") === "ARCHIVE")) {
                $saved = jm($tx, "update", "media", [
                    ...js_array($record),
                    "status" => "ARCHIVED",
                ]);
                jm(g($this, "files"), "unpublish", g($record, "file"));
                audit(
                    $tx,
                    g($this, "config"),
                    $actor,
                    "media.archive",
                    "media",
                    $id,
                );
                return a_platform_validation_safeRecord($saved);
            }
            throw new HttpError(400, "Choose submit, publish or archive.");
        });
    }
    public function publicMedia()
    {
        return jm(g($this, "store"), "run", function ($tx = null) {
            $out = [];
            foreach (js_iter(jm($tx, "list", "media")) as $item12929) {
                $media = $item12929;
                if (js_truth(a_platform_privacy_mediaIsPublic($tx, $media))) {
                    js_push($out, [
                        "id" => g($media, "id"),
                        "title" => g($media, "title"),
                        "alt" => g($media, "alt"),
                        "category" => g($media, "category"),
                        "url" => js_add("/media/", g($media, "id")),
                    ]);
                }
            }
            return $out;
        });
    }
    public function publicMediaFile($id = null)
    {
        return jm(g($this, "store"), "run", function ($tx = null) use (&$id) {
            $record = jm($tx, "get", "media", $id);
            if (
                js_truth(
                    !js_truth(a_platform_privacy_mediaIsPublic($tx, $record)),
                )
            ) {
                throw new HttpError(404, "Media is not available.");
            }
            return [
                "buffer" => jm(
                    g($this, "files"),
                    "read",
                    g($record, "file"),
                    "public",
                ),
                "file" => g($record, "file"),
            ];
        });
    }
}
