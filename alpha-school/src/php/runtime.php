<?php
/** Small PHP collection/formatting helpers shared by the migrated templates and services. */
function g($value, $key)
{
    if ($key === "length" && (is_array($value) || is_string($value))) {
        return is_array($value) ? count($value) : mb_strlen($value);
    }
    if ($value instanceof JsSet && $key === "size") {
        return count($value->values);
    }
    if (is_string($value) && is_numeric($key)) {
        return mb_substr($value, (int) $key, 1);
    }
    if (is_array($value)) {
        return $value[$key] ?? null;
    }
    if (is_object($value)) {
        return $value->$key ?? null;
    }
    return null;
}
function js_array($v): array
{
    if ($v instanceof JsSet) {
        return $v->values;
    }
    return is_array($v) ? $v : (is_object($v) ? (array) $v : []);
}
function js_iter($v): array
{
    return js_array($v);
}
function js_truth($v): bool
{
    return !(
        $v === null ||
        $v === false ||
        $v === "" ||
        $v === 0 ||
        $v === 0.0 ||
        (is_float($v) && is_nan($v))
    );
}
function js_or($a, callable $b)
{
    return js_truth($a) ? $a : $b();
}
function js_and($a, callable $b)
{
    return js_truth($a) ? $b() : $a;
}
function js_string($v): string
{
    if ($v === null) {
        return "";
    }
    if ($v === true) {
        return "true";
    }
    if ($v === false) {
        return "false";
    }
    if (is_array($v)) {
        return implode(",", array_map("js_string", $v));
    }
    return (string) $v;
}
function js_Number($v = 0)
{
    if (is_numeric($v)) {
        $n = 0 + $v;
        return is_float($n) &&
            is_finite($n) &&
            floor($n) === $n &&
            abs($n) <= 9007199254740991
            ? (int) $n
            : $n;
    }
    return $v === null || $v === "" ? 0 : NAN;
}
function js_parseInt($v, $base = 10): int
{
    return intval($v, $base);
}
function js_encodeURIComponent($v): string
{
    return str_replace(
        ["%21", "%27", "%28", "%29", "%2A"],
        ["!", "'", "(", ")", "*"],
        rawurlencode(js_string($v)),
    );
}
function js_decodeURIComponent($v): string
{
    return rawurldecode($v);
}
function js_add($a, $b)
{
    return is_string($a) || is_string($b)
        ? js_string($a) . js_string($b)
        : $a + $b;
}
function js_typeof($v): string
{
    return match (true) {
        is_string($v) => "string",
        is_bool($v) => "boolean",
        is_numeric($v) => "number",
        is_callable($v) && !is_string($v) => "function",
        default => "object",
    };
}
function js_omit($v, $keys): array
{
    return array_diff_key(js_array($v), array_flip($keys));
}
function js_push(&$a, ...$values): int
{
    $a ??= [];
    array_push($a, ...$values);
    return count($a);
}
function js_pop(&$a)
{
    return array_pop($a);
}
function js_shift(&$a)
{
    return array_shift($a);
}
function js_unshift(&$a, ...$v): int
{
    return array_unshift($a, ...$v);
}
function jm($o, $method, ...$args)
{
    if (is_object($o) && method_exists($o, $method)) {
        return $o->$method(...$args);
    }
    if ($method === "then") {
        return $args[0]($o);
    }
    if ($method === "map") {
        $r = [];
        foreach (js_array($o) as $i => $v) {
            $r[] = $args[0]($v, $i);
        }
        return $r;
    }
    if ($method === "filter") {
        $r = [];
        foreach (js_array($o) as $i => $v) {
            if (js_truth($args[0]($v, $i))) {
                $r[] = $v;
            }
        }
        return $r;
    }
    if ($method === "some" || $method === "every") {
        foreach (js_array($o) as $i => $v) {
            $b = js_truth($args[0]($v, $i));
            if ($method === "some" && $b) {
                return true;
            }
            if ($method === "every" && !$b) {
                return false;
            }
        }
        return $method === "every";
    }
    if ($method === "find" || $method === "findIndex") {
        foreach (js_array($o) as $i => $v) {
            if (js_truth($args[0]($v, $i))) {
                return $method === "find" ? $v : $i;
            }
        }
        return $method === "find" ? null : -1;
    }
    if ($method === "flatMap") {
        $r = [];
        foreach (js_array($o) as $i => $v) {
            $r = array_merge($r, js_array($args[0]($v, $i)));
        }
        return $r;
    }
    if ($method === "reduce") {
        $r = $args[1] ?? null;
        foreach (js_array($o) as $i => $v) {
            $r = $args[0]($r, $v, $i);
        }
        return $r;
    }
    if ($method === "includes") {
        return is_array($o)
            ? in_array($args[0], $o, true)
            : str_contains(js_string($o), js_string($args[0]));
    }
    if ($method === "indexOf") {
        $i = is_array($o)
            ? array_search($args[0], $o, true)
            : strpos(js_string($o), js_string($args[0]));
        return $i === false ? -1 : $i;
    }
    if ($method === "join") {
        return implode($args[0] ?? ",", array_map("js_string", js_array($o)));
    }
    if ($method === "concat") {
        $r = js_array($o);
        foreach ($args as $v) {
            $r = array_merge($r, is_array($v) ? $v : [$v]);
        }
        return $r;
    }
    if ($method === "slice" || $method === "substring") {
        $start = $args[0] ?? 0;
        $end = $args[1] ?? null;
        $len = is_array($o) ? count($o) : mb_strlen(js_string($o));
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        if ($end !== null && $end < 0) {
            $end = $len + $end;
        }
        return is_array($o)
            ? array_slice(
                $o,
                $start,
                $end === null ? null : max(0, $end - $start),
            )
            : mb_substr(
                js_string($o),
                $start,
                $end === null ? null : max(0, $end - $start),
            );
    }
    if ($method === "at") {
        $a = is_array($o) ? $o : mb_str_split(js_string($o));
        $i = $args[0];
        return $a[$i < 0 ? count($a) + $i : $i] ?? null;
    }
    if ($method === "sort") {
        $a = js_array($o);
        if (isset($args[0])) {
            usort($a, $args[0]);
        } else {
            sort($a);
        }
        return $a;
    }
    if ($method === "reverse") {
        return array_reverse(js_array($o));
    }
    if ($method === "toLowerCase") {
        return mb_strtolower(js_string($o));
    }
    if ($method === "toUpperCase") {
        return mb_strtoupper(js_string($o));
    }
    if ($method === "trim") {
        return trim(js_string($o));
    }
    if ($method === "startsWith") {
        return str_starts_with(js_string($o), js_string($args[0]));
    }
    if ($method === "endsWith") {
        return str_ends_with(js_string($o), js_string($args[0]));
    }
    if ($method === "localeCompare") {
        return strcmp(js_string($o), js_string($args[0]));
    }
    if ($method === "padStart") {
        return str_pad(js_string($o), $args[0], $args[1] ?? " ", STR_PAD_LEFT);
    }
    if ($method === "toFixed") {
        return number_format((float) $o, $args[0] ?? 0, ".", "");
    }
    if ($method === "replace" || $method === "replaceAll") {
        if ($args[0] instanceof JsRegex) {
            return preg_replace(
                $args[0]->pattern,
                str_replace('$&', '$0', $args[1]),
                js_string($o),
                str_contains($args[0]->flags, "g") ? -1 : 1,
            );
        }
        return $method === "replaceAll"
            ? str_replace($args[0], $args[1], js_string($o))
            : preg_replace(
                "~" . preg_quote($args[0], "~") . "~",
                str_replace('$&', '$0', $args[1]),
                js_string($o),
                1,
            );
    }
    if ($method === "split") {
        return $args[0] instanceof JsRegex
            ? preg_split($args[0]->pattern, js_string($o))
            : explode($args[0], js_string($o));
    }
    if ($method === "toString") {
        return js_string($o);
    }
    throw new RuntimeException("Unknown PHP helper method: " . $method);
}
class JsRegex
{
    public string $pattern;
    public function __construct(
        public string $source,
        public string $flags = "",
    ) {
        $source = preg_replace("/\\\\u([a-fA-F0-9]{4})/", '\\x{$1}', $source);
        $this->pattern =
            "~" .
            str_replace("~", "\\~", $source) .
            "~" .
            (str_contains($flags, "i") ? "i" : "") .
            (str_contains($flags, "m") ? "m" : "") .
            (str_contains($flags, "s") ? "s" : "");
    }
    public function test($v): bool
    {
        return preg_match($this->pattern, js_string($v)) === 1;
    }
}
class JsSet
{
    public array $values = [];
    public function __construct($v = [])
    {
        foreach (js_iter($v) as $x) {
            $this->add($x);
        }
    }
    public function add($v): self
    {
        if (!$this->has($v)) {
            $this->values[] = $v;
        }
        return $this;
    }
    public function has($v): bool
    {
        return in_array($v, $this->values, true);
    }
}
class JsMap
{
    private array $values = [];
    public function __construct($v = [])
    {
        foreach (js_iter($v) as [$k, $x]) {
            $this->values[$k] = $x;
        }
    }
    public function get($key)
    {
        return $this->values[$key] ?? null;
    }
    public function set($key, $v): self
    {
        $this->values[$key] = $v;
        return $this;
    }
}
class JsDate
{
    public ?DateTimeImmutable $date;
    public function __construct($v = null)
    {
        try {
            $this->date =
                $v === null
                    ? new DateTimeImmutable("now", new DateTimeZone("UTC"))
                    : (is_numeric($v)
                        ? new DateTimeImmutable("@" . $v / 1000)
                        : new DateTimeImmutable($v, new DateTimeZone("UTC")));
        } catch (Throwable) {
            $this->date = null;
        }
    }
    public function getTime()
    {
        return $this->date ? (float) $this->date->format("U.u") * 1000 : NAN;
    }
    public function toISOString(): string
    {
        if (!$this->date) {
            throw new RuntimeException("Invalid date");
        }
        return $this->date
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s.v\Z");
    }
    public function getFullYear(): int
    {
        return (int) $this->date->format("Y");
    }
    public function getUTCDay(): int
    {
        return (int) $this->date->format("w");
    }
    public function toLocaleDateString($lang = "en-GB", $opts = []): string
    {
        $d = $this->date;
        if (isset($opts["timeZone"])) {
            $d = $d->setTimezone(new DateTimeZone($opts["timeZone"]));
        }
        return $d->format(isset($opts["month"]) ? "j F Y" : "d/m/Y");
    }
}
function js_date_now(): int
{
    return (int) round(microtime(true) * 1000);
}
function js_date_parse($v)
{
    return (new JsDate($v))->getTime();
}
function js_date_UTC($year, $month, $day): int
{
    return (int) (new JsDate(
        sprintf("%04d-%02d-%02d", $year, $month + 1, $day),
    ))->getTime();
}
function js_json_stringify($v): string
{
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}
function js_json_parse($v)
{
    return json_decode($v, true, 512, JSON_THROW_ON_ERROR);
}
function js_object_freeze($v)
{
    return $v;
}
function js_object_keys($v): array
{
    return array_keys(js_array($v));
}
function js_object_values($v): array
{
    return array_values(js_array($v));
}
function js_object_entries($v): array
{
    $r = [];
    foreach (js_array($v) as $k => $x) {
        $r[] = [$k, $x];
    }
    return $r;
}
function js_object_fromEntries($v): array
{
    $r = [];
    foreach (js_iter($v) as [$k, $x]) {
        $r[$k] = $x;
    }
    return $r;
}
function js_object_hasOwn($v, $key): bool
{
    return array_key_exists($key, js_array($v));
}
function js_object_assign($v, ...$rest): array
{
    return array_replace(js_array($v), ...array_map("js_array", $rest));
}
function js_array_isArray($v): bool
{
    return is_array($v) && array_is_list($v);
}
function js_array_from($v): array
{
    return js_iter($v);
}
function js_number_isFinite($v): bool
{
    return is_numeric($v) && is_finite((float) $v);
}
function js_number_isInteger($v): bool
{
    return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
}
function js_number_isSafeInteger($v): bool
{
    return js_number_isInteger($v) && abs($v) <= 9007199254740991;
}
function js_math_round($v)
{
    return (int) floor($v + 0.5);
}
function js_math_max(...$v)
{
    return max($v);
}
function js_math_min(...$v)
{
    return min($v);
}
function js_math_ceil($v)
{
    return ceil($v);
}
function js_math_floor($v)
{
    return floor($v);
}
function js_promise_all($v)
{
    return $v;
}
function js_promise_resolve($v = null)
{
    return $v;
}
class RandomBytes
{
    public function __construct(public string $bytes) {}
    public function toString($format = "hex"): string
    {
        return $format === "hex"
            ? bin2hex($this->bytes)
            : base64_encode($this->bytes);
    }
}
function js_crypto_randomBytes($length): RandomBytes
{
    return new RandomBytes(random_bytes($length));
}
function js_crypto_randomUUID(): string
{
    return substr(rid(), 0);
}
