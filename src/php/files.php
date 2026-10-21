<?php
class Files
{
    private string $root;
    public function __construct(private array $config)
    {
        storage_configuration();
        $this->root = $config["dataDir"] . "/storage";
    }
    public function init(): void
    {
        foreach (
            [$this->root, $this->root . "/private", $this->root . "/public"]
            as $directory
        ) {
            if (is_link($directory)) {
                throw new RuntimeException(
                    "Storage directories must not be symbolic links.",
                );
            }
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                throw new RuntimeException(
                    "Cannot create persistent storage.",
                );
            }
            if (!is_writable($directory) || !chmod($directory, 0700)) {
                throw new RuntimeException(
                    "Persistent storage must be private and writable by the application account.",
                );
            }
        }
    }
    public function provider(): string
    {
        return "local";
    }
    private function keyPath($area, $key): string
    {
        if (
            !in_array($area, ["private", "public"], true) ||
            !preg_match('/^[a-f0-9]{48}$/', $key)
        ) {
            throw new RuntimeException("Invalid storage key.");
        }
        return $this->root . "/" . $area . "/" . $key;
    }
    public function readStored($area, $key): string
    {
        $p = $this->keyPath($area, $key);
        if (!is_file($p) || is_link($p)) {
            throw new RuntimeException(
                "Protected storage must be a regular file.",
            );
        }
        return file_get_contents($p);
    }
    public function writeStored($area, $key, $bytes): void
    {
        $p = $this->keyPath($area, $key);
        $fh = @fopen($p, "x");
        if (!$fh) {
            throw new RuntimeException("Cannot write private file.");
        }
        try {
            fwrite($fh, $bytes);
        } finally {
            fclose($fh);
        }
        chmod($p, 0600);
    }
    public function removeStored($area, $key): void
    {
        $p = $this->keyPath($area, $key);
        if (is_file($p)) {
            unlink($p);
        }
    }
    public function prepare($input, $imageOnly = false): array
    {
        if (
            !is_array($input) ||
            !is_string($input["content"] ?? null) ||
            !is_string($input["name"] ?? null)
        ) {
            throw new HttpError(400, "Choose a file.");
        }
        $v = $input["content"];
        if (
            strlen($v) > 4 * ceil($this->config["maxUploadBytes"] / 3) ||
            strlen($v) % 4 !== 0 ||
            !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $v)
        ) {
            throw new HttpError(
                413,
                "Invalid file encoding or file over 5 MB.",
            );
        }
        $bytes = base64_decode($v, true);
        if ($bytes === false || base64_encode($bytes) !== $v) {
            throw new HttpError(400, "Invalid base64 encoding.");
        }
        if (!$bytes || strlen($bytes) > $this->config["maxUploadBytes"]) {
            throw new HttpError(413, "Choose a nonempty file up to 5 MB.");
        }
        $name = substr(
            preg_replace("/[^a-zA-Z0-9._ -]/", "_", basename($input["name"])),
            0,
            100,
        );
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($imageOnly && in_array($ext, ["jpg", "jpeg", "png"], true)) {
            $png = str_starts_with($bytes, hex2bin("89504e470d0a1a0a"));
            $jpeg = str_starts_with($bytes, hex2bin("ffd8ff"));
            if (!(($png && $ext === "png") || ($jpeg && $ext !== "png"))) {
                throw new HttpError(
                    400,
                    "Image type does not match its filename.",
                );
            }
            $info = @getimagesizefromstring($bytes);
            if (!$info || $info[0] * $info[1] > 25000000) {
                throw new HttpError(400, "This image is invalid or too large.");
            }
            $im = @imagecreatefromstring($bytes);
            if (!$im) {
                throw new HttpError(400, "This image is invalid or too large.");
            }
            if ($jpeg && function_exists("exif_read_data")) {
                $tmp = tempnam($this->config["dataDir"], "exif-");
                file_put_contents($tmp, $bytes);
                try {
                    $exif = @exif_read_data($tmp);
                    $orientation = $exif["Orientation"] ?? 1;
                    if (in_array($orientation, [2, 4, 5, 7], true)) {
                        imageflip($im, IMG_FLIP_HORIZONTAL);
                    }
                    $angle = match ($orientation) {
                        3, 4 => 180,
                        5, 6 => -90,
                        7, 8 => 90,
                        default => 0,
                    };
                    if ($angle) {
                        $im = imagerotate($im, $angle, 0);
                    }
                } finally {
                    unlink($tmp);
                }
            }
            $w = imagesx($im);
            $h = imagesy($im);
            $ratio = min(1, 1600 / $w, 1600 / $h);
            $out = imagecreatetruecolor(
                max(1, (int) round($w * $ratio)),
                max(1, (int) round($h * $ratio)),
            );
            $white = imagecolorallocate($out, 255, 255, 255);
            imagefill($out, 0, 0, $white);
            imagecopyresampled(
                $out,
                $im,
                0,
                0,
                0,
                0,
                imagesx($out),
                imagesy($out),
                $w,
                $h,
            );
            ob_start();
            imagejpeg($out, null, 84);
            $bytes = ob_get_clean();
            unset($out, $im);
            $mime = "image/jpeg";
            $name = "school-activity.jpg";
            $scan = "UNSCANNED_REENCODED";
        } elseif (!$imageOnly && $ext === "txt") {
            if (
                !mb_check_encoding($bytes, "UTF-8") ||
                preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $bytes)
            ) {
                throw new HttpError(
                    400,
                    "Text documents must use UTF-8 and contain no binary data.",
                );
            }
            $mime = "text/plain; charset=utf-8";
            $scan = "PLAIN_TEXT";
        } elseif (!$imageOnly && $ext === "pdf") {
            if (
                !str_starts_with($bytes, "%PDF-") ||
                !str_contains(substr($bytes, -1024), "%%EOF")
            ) {
                throw new HttpError(400, "Invalid PDF signature.");
            }
            if (
                preg_match(
                    "/\/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA|RichMedia)\b/i",
                    $bytes,
                )
            ) {
                throw new HttpError(
                    400,
                    "Active or embedded PDF content is not supported.",
                );
            }
            $mime = "application/pdf";
            $scan = "UNSCANNED";
        } else {
            throw new HttpError(
                415,
                $imageOnly
                    ? "Use a JPEG or PNG image."
                    : "Use a plain-text (.txt) or scanned PDF document.",
            );
        }
        $key = bin2hex(random_bytes(24));
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt(
            $bytes,
            "aes-256-gcm",
            $this->config["storageKey"],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($encrypted === false) {
            throw new RuntimeException("Encryption failed.");
        }
        $this->writeStored("private", $key, $iv . $encrypted . $tag);
        return [
            "storageKey" => $key,
            "name" => $name,
            "mime" => $mime,
            "size" => strlen($bytes),
            "checksum" => hash("sha256", $bytes),
            "scanStatus" => $scan,
        ];
    }
    public function read($file, $area = "private"): string
    {
        $bytes = $this->readStored($area, $file["storageKey"]);
        if ($area === "private") {
            $bytes = openssl_decrypt(
                substr($bytes, 12, -16),
                "aes-256-gcm",
                $this->config["storageKey"],
                OPENSSL_RAW_DATA,
                substr($bytes, 0, 12),
                substr($bytes, -16),
            );
            if ($bytes === false) {
                throw new RuntimeException("File authentication failed.");
            }
        }
        if (!hash_equals($file["checksum"], hash("sha256", $bytes))) {
            throw new RuntimeException("File integrity check failed.");
        }
        return $bytes;
    }
    public function publish($file): void
    {
        $bytes = $this->read($file);
        if (
            !$this->s3 &&
            is_file($this->keyPath("public", $file["storageKey"]))
        ) {
            return;
        }
        $this->writeStored("public", $file["storageKey"], $bytes);
    }
    public function unpublish($file): void
    {
        if ($file) {
            $this->removeStored("public", $file["storageKey"]);
        }
    }
    public function discard($file): void
    {
        if ($file) {
            $this->removeStored("private", $file["storageKey"]);
        }
    }
}
function public_file_metadata($f)
{
    return $f
        ? array_intersect_key(
            $f,
            array_flip(["name", "mime", "size", "checksum", "scanStatus"]),
        )
        : null;
}
