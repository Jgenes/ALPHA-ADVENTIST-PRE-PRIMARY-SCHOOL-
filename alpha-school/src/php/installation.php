<?php
/** One-time cPanel setup. Never included by the normal website entry point. */
function setup_storage_directory(): string
{
    $dir = env_value('DATA_DIR') ?: application_root() . '/private';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('Cannot create private setup storage. Check the hosting account permissions.');
    }
    $real = realpath($dir);
    $public = realpath(public_root());
    if (!$real || !$public || $real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR) || is_link($dir)) {
        throw new RuntimeException('Setup storage must be a real private directory outside public_html.');
    }
    if (!is_writable($real) || !chmod($real, 0700)) {
        throw new RuntimeException('Private storage must be writable by this hosting account.');
    }
    return $real;
}
function setup_token_matches(string $submitted): bool
{
    $expected = env_value('SETUP_TOKEN');
    return strlen($expected) >= 32 && strlen($submitted) <= 512 && hash_equals($expected, $submitted);
}
function setup_https(): bool
{
    if (in_array(strtolower($_SERVER['HTTPS'] ?? ''), ['on', '1'], true)) {
        return true;
    }
    // Only honour the final proxy header when the operator explicitly trusts it.
    if ((int) env_value('TRUST_PROXY_HOPS', 0) > 0) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        return trim(end($parts)) === 'https';
    }
    return false;
}
function setup_transport_allowed(): bool
{
    return setup_https() || (
        env_value('APP_ENV') === 'development' &&
        in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) &&
        in_array(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)
    );
}
function setup_csrf_matches(array $post, array $session): bool
{
    return is_string($post['csrf'] ?? null) && is_string($session['csrf'] ?? null) &&
        strlen($session['csrf']) === 64 && hash_equals($session['csrf'], $post['csrf']);
}
/** Global disk-backed limit: session resets cannot bypass it. Never records tokens. */
function setup_attempt_allowed(string $dir, ?int $now = null): bool
{
    $now ??= time();
    $path = $dir . '/setup-attempts.json';
    if (is_link($path)) {
        throw new RuntimeException('Invalid setup state file.');
    }
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) {
        throw new RuntimeException('Cannot lock private setup state.');
    }
    try {
        chmod($path, 0600);
        $state = json_decode(stream_get_contents($file), true) ?: [];
        if (($state['until'] ?? 0) <= $now) {
            $state = ['count' => 0, 'until' => $now + 900];
        }
        $allowed = $state['count'] < 10;
        if ($allowed) {
            $state['count']++;
        }
        rewind($file);
        ftruncate($file, 0);
        fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
        return $allowed;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
function setup_accounts(array $input): array
{
    if (($input['approved'] ?? '') !== 'yes') {
        throw new HttpError(400, 'Confirm that the school has approved both appointments.');
    }
    $reference = trim((string) ($input['approval_reference'] ?? ''));
    if (strlen($reference) < 3 || strlen($reference) > 120) {
        throw new HttpError(400, 'Enter an appointment approval reference of 3–120 characters.');
    }
    $users = [];
    foreach (['admin' => 'system_admin', 'head' => 'head_teacher'] as $prefix => $role) {
        $password = (string) ($input[$prefix . '_password'] ?? '');
        if ($password !== ($input[$prefix . '_confirm'] ?? null)) {
            throw new HttpError(400, 'The two password entries must match for each account.');
        }
        $users[] = create_user(
            strtolower(trim((string) ($input[$prefix . '_username'] ?? ''))),
            (string) ($input[$prefix . '_name'] ?? ''),
            [$role],
            $password,
        );
    }
    if ($users[0]['username'] === $users[1]['username']) {
        throw new HttpError(400, 'Use different usernames for the system administrator and head teacher.');
    }
    if (hash_equals((string) $input['admin_password'], (string) $input['head_password'])) {
        throw new HttpError(400, 'Use a different unique password for each account.');
    }
    return [$users, $reference];
}
function setup_requirements(): void
{
    if (PHP_VERSION_ID < 80200) {
        throw new HttpError(503, 'Select PHP 8.2 or newer in cPanel.');
    }
    foreach (['pdo_mysql', 'openssl', 'mbstring', 'gd', 'sodium', 'fileinfo', 'session'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new HttpError(503, 'Enable the ' . $extension . ' PHP extension in cPanel.');
        }
    }
}
function setup_install(array $input): void
{
    setup_requirements();
    $dir = setup_storage_directory();
    if (file_exists($dir . '/setup-complete.lock')) {
        throw new HttpError(409, 'Setup is already complete. Use the normal portal sign-in.');
    }
    [$users, $reference] = setup_accounts($input);
    $config = configuration();
    if ($config['adminPassword'] || $config['headTeacherPassword']) {
        throw new HttpError(409, 'Initial accounts are already configured by an operator. Use the existing CLI provisioning procedure.');
    }
    $store = new Store($config);
    if ($store->list('users')) {
        throw new HttpError(409, 'This database already has accounts. Setup will not reset or overwrite them.');
    }
    $platform = new Platform($store, $config, new Files($config));
    $platform->init();
    $store->run(function ($tx) use ($users, $reference, $config) {
        // Recheck inside the serialized transaction to prevent parallel installers.
        if ($tx->list('users')) {
            throw new HttpError(409, 'Accounts already exist. Setup cannot overwrite them.');
        }
        foreach ($users as $user) {
            $tx->insert('users', $user);
            audit($tx, $config, ['userId' => 'owner-approved-setup'], 'user.bootstrap', 'user', $user['id'], 'success', [
                'role' => $user['roles'][0],
                'approvalReference' => $reference,
            ]);
        }
    });
    // Even if a marker write fails, the existing-account guard blocks reprovisioning.
    $lock = fopen($dir . '/setup-complete.lock', 'x');
    if (!$lock) {
        throw new RuntimeException('Accounts were created. Remove public_html/setup.php and clear SETUP_TOKEN now.');
    }
    fwrite($lock, gmdate('c') . "\n");
    fclose($lock);
    chmod($dir . '/setup-complete.lock', 0600);
}
