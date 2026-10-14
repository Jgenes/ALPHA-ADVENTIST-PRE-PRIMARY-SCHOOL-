<?php
function validate_details($input): array
{
    $b = is_string($input) ? json_decode($input, true) : $input;
    if (!is_array($b) || ($b["detailsVerified"] ?? false) !== true) {
        throw new HttpError(
            400,
            "Confirm the school has verified these public contact details.",
        );
    }
    $phone = function ($v, $optional = false) {
        $s = text($v, 24, !$optional);
        if ($optional && !$s) {
            return "";
        }
        if (!preg_match('/^\+[1-9][0-9 ()-]{7,22}$/', $s)) {
            throw new HttpError(
                400,
                "Use an international school telephone number.",
            );
        }
        return $s;
    };
    $link = function ($v, $hosts) {
        $s = text($v, 500);
        if (!$s) {
            return "";
        }
        $p = parse_url($s);
        $ok = false;
        foreach ($hosts as $host) {
            if (
                ($p["host"] ?? "") === $host ||
                str_ends_with($p["host"] ?? "", "." . $host)
            ) {
                $ok = true;
            }
        }
        if (
            !$p ||
            ($p["scheme"] ?? "") !== "https" ||
            isset($p["user"]) ||
            isset($p["pass"]) ||
            !$ok
        ) {
            throw new HttpError(
                400,
                "Use a verified official channel or Google Maps link.",
            );
        }
        return $s;
    };
    $email = text($b["email"] ?? null, 160, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpError(400, "Use the verified school email address.");
    }
    $code = text($b["centreCode"] ?? null, 24, true);
    if (!preg_match('/^[A-Z0-9-]+$/', $code)) {
        throw new HttpError(400, "Use the official examination centre code.");
    }
    $r = [
        "officePhone" => $phone($b["officePhone"] ?? null),
        "headPhone" => $phone($b["headPhone"] ?? null),
        "whatsappPhone" => $phone($b["whatsappPhone"] ?? null),
        "safeguardingName" => text($b["safeguardingName"] ?? null, 120),
        "safeguardingPhone" => $phone($b["safeguardingPhone"] ?? null, true),
        "email" => $email,
        "centreCode" => $code,
        "detailsVerified" => true,
    ];
    foreach (
        ["box" => 80, "locationText" => 180, "officeHours" => 500]
        as $k => $max
    ) {
        $r[$k] = text($b[$k] ?? null, $max, true);
    }
    foreach (
        [
            "mapUrl" => ["google.com", "maps.app.goo.gl"],
            "facebookUrl" => ["facebook.com"],
            "instagramUrl" => ["instagram.com"],
            "youtubeUrl" => ["youtube.com", "youtu.be"],
        ]
        as $k => $hosts
    ) {
        $r[$k] = $link($b[$k] ?? null, $hosts);
    }
    return $r;
}
function apply_details($settings, $content): array
{
    foreach ($content as $item) {
        if (
            ($item["kind"] ?? "") === "settings" &&
            ($item["slug"] ?? "") === "school-contact"
        ) {
            $d = validate_details($item["body"]);
            $settings = array_replace($settings, [
                "email" => $d["email"],
                "emailPublished" => true,
                "safeguardingName" => $d["safeguardingName"],
                "safeguardingPhone" => $d["safeguardingPhone"],
                "phones" => [
                    [
                        "label" => "School Office",
                        "number" => $d["officePhone"],
                        "href" => preg_replace(
                            "/[^+0-9]/",
                            "",
                            $d["officePhone"],
                        ),
                    ],
                    [
                        "label" => "Head of School's Office",
                        "number" => $d["headPhone"],
                        "href" => preg_replace(
                            "/[^+0-9]/",
                            "",
                            $d["headPhone"],
                        ),
                    ],
                ],
                "whatsapp" => [
                    "number" => $d["whatsappPhone"],
                    "href" => preg_replace("/[^0-9]/", "", $d["whatsappPhone"]),
                ],
                "address" => array_replace($settings["address"], [
                    "box" => $d["box"],
                ]),
                "locationText" => $d["locationText"],
                "officeHours" => $d["officeHours"],
                "centreCode" => $d["centreCode"],
                "mapUrl" => $d["mapUrl"],
                "mapNote" => $d["mapUrl"]
                    ? "School-verified campus map link."
                    : $settings["mapNote"],
                "social" => [],
            ]);
            foreach (
                [
                    "Facebook" => "facebookUrl",
                    "Instagram" => "instagramUrl",
                    "YouTube" => "youtubeUrl",
                ]
                as $name => $key
            ) {
                if ($d[$key]) {
                    $settings["social"][] = [
                        "name" => $name,
                        "url" => $d[$key],
                    ];
                }
            }
            break;
        }
    }
    return $settings;
}
