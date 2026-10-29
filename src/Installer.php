<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * Writes .env from command-line options (see bin/install.php).
 *
 * The written file must be read identically by Env (PHP) and by the bash parser in bin/env.sh,
 * hence the strict contract: unquoted values, one occurrence per key, key lines starting at the
 * beginning of the line. Output is intentionally English and not localized: it is an operator tool.
 */
final class Installer
{
    private const REQUIRED_KEYS = ['BOT_TOKEN', 'ALLOWED_USER_IDS', 'REPO_PATH'];

    private const OPTIONAL_KEYS = [
        'FORGE',
        'FORGE_HOST',
        'FORGE_OWNERS',
        'FORGE_REPO',
        'CLAUDE_BIN',
        'BOT_LOCALE',
        'TIMEZONE',
        'OPENRONIN_LOGINS',
        'CLAUDE_EXTRA_ARGS',
    ];

    /** Deprecated keys still accepted as options and as defaults; the new key replaces them in .env. */
    private const LEGACY_KEYS = [
        'GITHUB_OWNERS' => 'FORGE_OWNERS',
        'GITHUB_REPO' => 'FORGE_REPO',
    ];

    private const REQUIRED_EXTENSIONS = ['curl', 'pdo_sqlite', 'pcntl', 'posix'];

    private const TIMEOUT_PROBE = 10;
    private const TIMEOUT_HTTP = 15;
    private const TIMEOUT_NETWORK = 30;

    private const EXTRA_ARGS_WARNING = 'WARNING: with --dangerously-skip-permissions Claude runs WITHOUT permission prompts and executes '
        ."whatever a Telegram message makes it do, with the rights of the bot's system user and its forge CLI token. "
        .'Use a dedicated unprivileged user and a narrowly scoped forge token, or set a stricter value, e.g. --permission-mode,acceptEdits.';

    private const DANGEROUS_FLAG = '--dangerously-skip-permissions';

    private const SECRET_KEYS = ['BOT_TOKEN'];

    /**
     * @param array<string, string|false> $environment real environment (only BOT_TOKEN is read)
     * @param int|null                    $euid        effective user id; null when it cannot be determined
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly array $environment,
        private readonly ?int $euid,
        private readonly ?string $userName = null,
        private readonly ?InstallerServices $services = null,
    ) {
    }

    /**
     * @param list<string> $args command-line arguments without the script name
     * @param resource     $out
     * @param resource     $err
     */
    public function run(array $args, $out, $err): int
    {
        // No file (temporary, backup, final) is ever readable by other users.
        $previousUmask = umask(0077);

        try {
            return $this->execute($args, $out, $err);
        } catch (\Throwable $e) {
            fwrite($err, 'Error: '.$e->getMessage()."\n");

            return 1;
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * @param list<string> $args
     * @param resource     $out
     * @param resource     $err
     */
    private function execute(array $args, $out, $err): int
    {
        [$options, $tokenFile, $allowRoot, $errors, $flags] = $this->parseArgs($args);
        $services = $this->services ?? InstallerServices::system();
        $interactive = !$flags['non-interactive'] && ($services->isTty)();
        $unitFile = $flags['unit-file'];
        if ($interactive && !($services->extensionLoaded)('pcntl')) {
            // Without pcntl Ctrl+C cannot be intercepted, so the echo could stay off: do not ask anything.
            $errors[] = 'The PHP extension pcntl is not loaded: the interactive mode cannot restore the terminal echo after Ctrl+C. Install it or pass --non-interactive.';
            $interactive = false;
        }
        if ($unitFile !== null && !is_dir(dirname($unitFile))) {
            $errors[] = '--unit-file: the directory '.dirname($unitFile).' does not exist.';
        }

        $user = ($this->userName ?? 'unknown').($this->euid !== null ? " (euid={$this->euid})" : '');
        fwrite($out, "Installing as user: {$user}\n");
        if ($this->euid === 0 && !$allowRoot) {
            fwrite($err, "Error: refusing to run as root (effective user: {$user}). Run as the bot's user or pass --allow-root.\n");

            return 1;
        }

        $envPath = $this->rootDir.'/.env';
        $distPath = $this->rootDir.'/.env.dist';

        if (!is_file($distPath)) {
            $errors[] = "{$distPath} was not found.";
        }
        $placeholders = [];
        if (is_file($distPath)) {
            $dist = Env::load($distPath, true);
            foreach (self::REQUIRED_KEYS as $key) {
                $placeholders[$key] = $dist->get($key);
            }
        }

        $existed = is_file($envPath);
        $baseFile = $existed ? $envPath : $distPath;
        $base = is_file($baseFile) ? (string) file_get_contents($baseFile) : '';
        $defaults = is_file($baseFile) ? Env::load($baseFile, true) : null;

        $options['BOT_TOKEN'] = $this->resolveToken($options, $tokenFile, $errors);
        if ($options['BOT_TOKEN'] === null) {
            unset($options['BOT_TOKEN']);
        }

        // Effective values to write.
        $values = [];
        $previous = [];
        foreach ([...self::REQUIRED_KEYS, ...self::OPTIONAL_KEYS] as $key) {
            $old = $defaults?->get($key);
            if (($old === null || $old === '') && ($legacy = array_search($key, self::LEGACY_KEYS, true)) !== false) {
                $old = $defaults?->get($legacy) ?? $old;
            }
            $required = in_array($key, self::REQUIRED_KEYS, true);
            if ($required && $old !== null && $old === ($placeholders[$key] ?? null)) {
                $old = null;
            }
            $previous[$key] = $old;

            $value = $options[$key] ?? $old;
            if ($interactive) {
                $value = $this->ask($key, $value, $placeholders, $services, $out, $err);
            }
            $problem = $this->validate($key, $value, $placeholders);
            if ($problem !== null) {
                $errors[] = $problem;
                continue;
            }
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        $warnings = [];
        if ($errors === []) {
            $this->checkEnvironment($values, $flags['skip-network-checks'], $services, $errors, $warnings);
        }

        if ($errors !== []) {
            return $this->fail($errors, $err);
        }

        foreach (self::LEGACY_KEYS as $legacy => $current) {
            if (isset($values[$current])) {
                $base = (string) preg_replace('/^\s*'.$legacy.'\s*=.*\R?/m', '', $base);
            }
        }
        $merged = self::merge($base, $values);
        $tmpPath = $this->rootDir.'/.env.tmp-'.getmypid();
        $backupPath = $this->rootDir.'/.env.bak';

        try {
            if (file_put_contents($tmpPath, $merged) === false || !chmod($tmpPath, 0600)) {
                throw new \RuntimeException("Failed to write {$tmpPath}.");
            }

            // Barrier: the bot must be able to start on the new file. Creates STATE_DIR, inbox and
            // outbox on purpose (Config does it); with the umask above they are 0700, which is fine
            // because the bot runs as the same user as the installer.
            try {
                Config::fromEnv(Env::load($tmpPath, true), $this->rootDir);
            } catch (\Throwable $e) {
                return $this->fail(['The resulting configuration is rejected: '.$e->getMessage()], $err);
            }

            if ($existed) {
                if (!copy($envPath, $backupPath) || !chmod($backupPath, 0600)) {
                    throw new \RuntimeException("Failed to write the backup {$backupPath}.");
                }
            }
            if (!rename($tmpPath, $envPath)) {
                throw new \RuntimeException("Failed to replace {$envPath}.");
            }
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }

        $extraArgs = $values['CLAUDE_EXTRA_ARGS'] ?? self::DANGEROUS_FLAG;
        if (in_array(self::DANGEROUS_FLAG, array_map('trim', explode(',', $extraArgs)), true)) {
            $warnings[] = self::DANGEROUS_FLAG.' is in CLAUDE_EXTRA_ARGS: Claude runs WITHOUT permission prompts and executes '
                .'whatever a Telegram message makes it do, with the rights of the bot\'s system user and its forge CLI token. '
                .'Run the bot as a dedicated unprivileged user with a narrowly scoped forge token, or set a stricter '
                .'CLAUDE_EXTRA_ARGS (e.g. --permission-mode,acceptEdits).';
        }
        if ($this->euid === 0) {
            $warnings[] = "Running as root: {$envPath}, its backup, and the state directories were created owned by root. "
                .'If the bot runs as another user, `chown` them to that user, otherwise it cannot read .env or write its state.';
        }
        foreach ($warnings as $warning) {
            fwrite($err, "Warning: {$warning}\n");
        }

        fwrite($out, "Wrote {$envPath} (mode 0600)\n");
        if ($existed) {
            fwrite($out, "Previous file saved to {$backupPath} (mode 0600)\n");
        }
        fwrite($out, "Keys set:\n");
        foreach ($values as $key => $value) {
            $status = match (true) {
                $previous[$key] === null => 'new',
                $previous[$key] !== $value => 'changed',
                default => 'unchanged',
            };
            $shown = in_array($key, self::SECRET_KEYS, true) ? self::mask($value) : $value;
            fwrite($out, "  {$key}={$shown} ({$status})\n");
        }
        if ($warnings !== []) {
            fwrite($out, "Warnings:\n");
            foreach ($warnings as $warning) {
                fwrite($out, "  - {$warning}\n");
            }
        }
        if ($this->userName === null) {
            fwrite($err, "Warning: the current user name is unknown: the systemd unit has no User= line, add it before installing.\n");
        }
        $unit = $this->unit($services);
        $cron = $this->cronLine(Config::resolvePath($defaults?->get('STATE_DIR') ?? $this->rootDir.'/state', $this->rootDir));
        if ($unitFile !== null) {
            if (file_put_contents($unitFile, $unit) === false || !chmod($unitFile, 0644)) {
                fwrite($err, "Error: failed to write the unit file {$unitFile}.\n");

                return 1;
            }
            fwrite($out, "Wrote the systemd unit to {$unitFile}\n");
        }
        fwrite($out, "systemd unit (install it yourself, e.g. as /etc/systemd/system/workspace-assistant.service):\n{$unit}");
        fwrite($out, "cron line for bin/check_gh_auth.sh (add it with `crontab -e`):\n{$cron}\n");
        if ($existed) {
            fwrite($out, "Next: restart the bot (`bin/ctl.sh restart` or your service manager) to apply the changes\n");
        } else {
            fwrite($out, "Next: start the bot with `php {$this->rootDir}/bin/bot.php`\n");
        }

        return 0;
    }

    /**
     * Why the value of a key cannot be used; null when it can. Offline checks only.
     *
     * @param array<string, string|null> $placeholders
     */
    private function validate(string $key, ?string $value, array $placeholders): ?string
    {
        if (in_array($key, self::REQUIRED_KEYS, true)) {
            if ($value === null || $value === '') {
                return "{$key} is required (".$this->howToPass($key).').';
            }
            if ($value === ($placeholders[$key] ?? null)) {
                return "{$key} is the example value from .env.dist; pass a real value (".$this->howToPass($key).').';
            }
        }
        if ($value === null) {
            return null;
        }
        $problem = self::contractViolation($value);
        if ($problem !== null) {
            return "{$key}: {$problem}.";
        }

        return match (true) {
            $key === 'REPO_PATH' && !str_starts_with($value, '/') => 'REPO_PATH: must be an absolute path (the bot resolves it relative to its own working directory).',
            $key === 'REPO_PATH' && !is_dir($value.'/.git') => "REPO_PATH={$value} does not look like a git repository.",
            $key === 'ALLOWED_USER_IDS' && preg_match('/^\d+(,\d+)*$/', $value) !== 1 => 'ALLOWED_USER_IDS: must contain only digits separated by commas (e.g. 1001,1002).',
            $key === 'FORGE' && $value !== '' && !isset(Config::DEFAULT_HOSTS[$value]) => "FORGE={$value} is not supported (expected ".implode(' or ', array_keys(Config::DEFAULT_HOSTS)).').',
            $key === 'TIMEZONE' && $value !== '' && !Timezone::isValid($value) => "Invalid TIMEZONE={$value}.",
            $key === 'BOT_LOCALE' && $value !== '' && !in_array($value, (new Locale())->codes(), true) => "BOT_LOCALE={$value} is not in the locale catalog (available: ".implode(', ', (new Locale())->codes()).').',
            default => null,
        };
    }

    /**
     * Asks for the value of a key until the answer is valid. An empty answer keeps the default.
     *
     * @param array<string, string|null> $placeholders
     * @param resource                   $out
     * @param resource                   $err
     */
    private function ask(string $key, ?string $default, array $placeholders, InstallerServices $services, $out, $err): ?string
    {
        $secret = in_array($key, self::SECRET_KEYS, true);
        if ($key === 'CLAUDE_EXTRA_ARGS') {
            fwrite($out, self::EXTRA_ARGS_WARNING."\n");
        }
        $shown = $default !== null && $default !== '' && $secret ? self::mask($default) : $default;
        $prompt = $key.($shown !== null && $shown !== '' ? " [{$shown}]" : '').': ';

        while (true) {
            fwrite($out, $prompt);
            $answer = $secret ? $this->readSecret($services, $out) : $this->readLine($services);
            $answer = $answer === '' ? $default : $answer;
            $problem = $this->validate($key, $answer, $placeholders);
            if ($problem === null) {
                return $answer;
            }
            fwrite($err, "  {$problem}\n");
        }
    }

    private function readLine(InstallerServices $services): string
    {
        $line = fgets($services->in);
        if ($line === false) {
            throw new \RuntimeException('Unexpected end of input.');
        }

        return trim($line, "\r\n");
    }

    /**
     * Reads a line with the terminal echo off. The echo is restored on every exit path, including Ctrl+C.
     *
     * @param resource $out
     */
    private function readSecret(InstallerServices $services, $out): string
    {
        $stty = static fn (string $flag) => ($services->run)(['stty', $flag], [], 5, null, true);
        $async = null;
        if (($services->extensionLoaded)('pcntl')) {
            $async = pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void {
                throw new \RuntimeException('Interrupted.');
            });
        }
        try {
            $stty('-echo');

            return $this->readLine($services);
        } finally {
            $stty('echo');
            fwrite($out, "\n");
            if ($async !== null) {
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_async_signals($async);
            }
        }
    }

    /**
     * Environment and network checks. Errors block the write, warnings do not.
     *
     * @param array<string, string> $values
     * @param list<string>          $errors
     * @param list<string>          $warnings
     */
    private function checkEnvironment(array $values, bool $skipNetwork, InstallerServices $services, array &$errors, array &$warnings): void
    {
        $required = null;
        $composer = $this->rootDir.'/composer.json';
        if (is_file($composer)) {
            $json = json_decode((string) file_get_contents($composer), true);
            $constraint = is_array($json) && is_array($json['require'] ?? null) ? ($json['require']['php'] ?? null) : null;
            if (is_string($constraint) && preg_match('/\d+(\.\d+)*/', $constraint, $m) === 1) {
                $required = $m[0];
            }
        }
        if ($required !== null && version_compare($services->phpVersion, $required, '<')) {
            $errors[] = "PHP {$required} or newer is required, running {$services->phpVersion}.";
        }
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!($services->extensionLoaded)($extension)) {
                $errors[] = "The PHP extension {$extension} is not loaded.";
            }
        }

        $env = array_filter($this->environment, 'is_string');
        $claude = ($values['CLAUDE_BIN'] ?? '') !== '' ? $values['CLAUDE_BIN'] : 'claude';
        $forge = ($values['FORGE'] ?? '') !== '' ? $values['FORGE'] : Config::FORGE_GITHUB;
        $forgeHost = ($values['FORGE_HOST'] ?? '') !== '' ? $values['FORGE_HOST'] : (Config::DEFAULT_HOSTS[$forge] ?? 'github.com');
        $cli = $forge === Config::FORGE_GITLAB ? 'glab' : 'gh';
        $available = [];
        foreach (['git' => 'git', $cli => $cli, $claude => 'CLAUDE_BIN='.$claude] as $binary => $label) {
            [$code] = ($services->run)([$binary, '--version'], $env, self::TIMEOUT_PROBE, null, false);
            $available[$binary] = $code === 0;
            if ($code !== 0) {
                $errors[] = "{$label} was not found or does not run (`{$binary} --version` exit code {$code}).";
            }
        }

        $network = static function (string $message) use ($skipNetwork, &$errors, &$warnings): void {
            if ($skipNetwork) {
                $warnings[] = $message.' (reported as a warning because of --skip-network-checks)';
            } else {
                $errors[] = $message;
            }
        };

        $token = $values['BOT_TOKEN'] ?? '';
        // Without curl the HTTP call would throw; the missing extension is already reported above.
        if (($services->extensionLoaded)('curl')) {
            $response = ($services->http)('https://api.telegram.org/bot'.$token.'/getMe', self::TIMEOUT_HTTP);
            $body = $response !== null ? json_decode($response[1], true) : null;
            if ($response === null) {
                $network('The Bot API is unreachable (getMe failed or timed out).');
            } elseif ($response[0] !== 200 || !is_array($body) || ($body['ok'] ?? false) !== true) {
                $network("The Bot API rejected BOT_TOKEN (getMe returned HTTP {$response[0]}).");
            }
        }

        if ($available['git']) {
            $gitEnv = $env;
            $gitEnv['GIT_TERMINAL_PROMPT'] = '0';
            $gitEnv['GIT_SSH_COMMAND'] = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10';
            [$code, $stdout, $stderr] = ($services->run)(['git', '-C', $values['REPO_PATH'], 'ls-remote'], $gitEnv, self::TIMEOUT_NETWORK, null, false);
            if ($code === InstallerServices::EXIT_TIMEOUT) {
                $network('`git ls-remote` timed out after '.self::TIMEOUT_NETWORK.'s: the remote of REPO_PATH is unreachable.');
            } elseif ($code !== 0) {
                $message = 'git cannot reach the remote of REPO_PATH without prompts (`git ls-remote` exit code '.$code.'): '.trim($stderr);
                if (preg_match('/host key|REMOTE HOST IDENTIFICATION/i', $stderr) === 1) {
                    $message .= "\nHint: the host key is unknown. Verify the fingerprint, then add it, e.g. `ssh-keyscan -H github.com >> ~/.ssh/known_hosts` (use your git host).";
                }
                $network($message);
            }
        }

        if ($available[$cli]) {
            $status = $forge === Config::FORGE_GITLAB ? ['glab', 'auth', 'status', '--hostname', $forgeHost] : ['gh', 'auth', 'status', '-h', $forgeHost];
            [$code] = ($services->run)($status, $env, self::TIMEOUT_PROBE, null, false);
            if ($code !== 0) {
                $login = $forge === Config::FORGE_GITLAB ? "glab auth login --hostname {$forgeHost}" : "gh auth login -h {$forgeHost}";
                $warnings[] = "`{$cli} auth status` failed: run `{$login}` as the bot's user".($forge === Config::FORGE_GITLAB ? '.' : ' (only epics need `gh`).');
            }
        }
    }

    private function unit(InstallerServices $services): string
    {
        $workingDirectory = str_replace('%', '%%', $this->rootDir);
        $exec = $this->unitWord($services->phpBinary).' '.$this->unitWord($this->rootDir.'/bin/bot.php');
        $user = $this->userName !== null ? "User={$this->userName}\n" : '';

        return <<<UNIT
            [Unit]
            Description=Workspace Assistant bot
            After=network.target

            [Service]
            WorkingDirectory={$workingDirectory}
            ExecStart={$exec}
            Restart=always
            {$user}
            [Install]
            WantedBy=multi-user.target

            UNIT;
    }

    /**
     * One ExecStart word: quoted only when it contains characters systemd would split or interpret.
     */
    private function unitWord(string $word): string
    {
        $word = str_replace('%', '%%', $word);
        if (preg_match('/[\s"\'\\\\]/', $word) !== 1) {
            return $word;
        }

        return '"'.addcslashes($word, '"\\').'"';
    }

    private function cronLine(string $stateDir): string
    {
        $word = static fn (string $path): string => preg_match('#^[A-Za-z0-9_./:@+,=-]+$#', $path) === 1
            ? $path : str_replace('%', '\\%', escapeshellarg($path));

        return '0 9 * * 1 '.$word($this->rootDir.'/bin/check_gh_auth.sh').' >> '
            .$word(rtrim($stateDir, '/').'/check_gh_auth.log').' 2>&1';
    }

    /**
     * Applies the write contract to the .env text: the last occurrence of a key is replaced, earlier
     * duplicates are removed, a commented `# KEY=value` is uncommented in place, everything else is
     * kept and new keys are appended.
     *
     * @param array<string, string> $values
     */
    public static function merge(string $content, array $values): string
    {
        $lines = $content === '' ? [] : explode("\n", str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
        $append = [];

        foreach ($values as $key => $value) {
            $last = null;
            $duplicates = [];
            $commented = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                    if ($last !== null) {
                        $duplicates[] = $last;
                    }
                    $last = $i;
                } elseif ($commented === null && preg_match('/^#\s*'.preg_quote($key, '/').'=/', $line) === 1) {
                    $commented = $i;
                }
            }

            $target = $last ?? $commented;
            if ($target === null) {
                $append[] = "{$key}={$value}";
                continue;
            }
            $lines[$target] = "{$key}={$value}";
            foreach ($duplicates as $i) {
                unset($lines[$i]);
            }
            $lines = array_values($lines);
        }

        return implode("\n", [...$lines, ...$append])."\n";
    }

    /**
     * Why a value cannot be written so that both parsers read it identically; null when it can.
     */
    public static function contractViolation(string $value): ?string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            return 'the value must not contain a line break';
        }
        if (str_contains($value, '#')) {
            return 'the value must not contain "#"';
        }
        if ($value !== trim($value)) {
            return 'the value must not have leading or trailing whitespace';
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            return 'the value must not be wrapped in quotes';
        }
        if (str_ends_with($value, '=')) {
            return 'the value must not end with "="';
        }

        return null;
    }

    public static function mask(string $secret): string
    {
        return strlen($secret) >= 12 ? '***'.substr($secret, -4) : '***';
    }

    /**
     * @param array<string, string> $keyByOption
     */
    private static function looksLikeKnownOption(string $arg, array $keyByOption): bool
    {
        if (!str_starts_with($arg, '--')) {
            return false;
        }
        $name = substr($arg, 2);
        if (($eq = strpos($name, '=')) !== false) {
            $name = substr($name, 0, $eq);
        }

        return isset($keyByOption[$name]) || in_array($name, ['bot-token-file', 'unit-file', 'allow-root', 'non-interactive', 'skip-network-checks'], true);
    }

    /**
     * @param list<string> $args
     *
     * @return array{array<string, string>, string|null, bool, list<string>, array{non-interactive: bool, skip-network-checks: bool, unit-file: string|null}}
     */
    private function parseArgs(array $args): array
    {
        $keyByOption = [];
        foreach ([...self::REQUIRED_KEYS, ...self::OPTIONAL_KEYS] as $key) {
            $keyByOption[strtolower(str_replace('_', '-', $key))] = $key;
        }
        foreach (self::LEGACY_KEYS as $legacy => $current) {
            $keyByOption[strtolower(str_replace('_', '-', $legacy))] = $current;
        }

        $options = [];
        $tokenFile = null;
        $allowRoot = false;
        $errors = [];
        $flags = ['non-interactive' => false, 'skip-network-checks' => false, 'unit-file' => null];

        for ($i = 0, $count = count($args); $i < $count; ++$i) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                $errors[] = "Unexpected argument: {$arg}";
                continue;
            }
            $name = substr($arg, 2);
            $value = null;
            if (($eq = strpos($name, '=')) !== false) {
                $value = substr($name, $eq + 1);
                $name = substr($name, 0, $eq);
            }

            if (in_array($name, ['non-interactive', 'allow-root', 'skip-network-checks'], true)) {
                if ($value !== null) {
                    $errors[] = "--{$name} does not take a value";
                }
                $allowRoot = $allowRoot || $name === 'allow-root';
                if ($name !== 'allow-root') {
                    $flags[$name] = true;
                }
                continue;
            }
            if (!in_array($name, ['bot-token-file', 'unit-file'], true) && !isset($keyByOption[$name])) {
                $errors[] = "Unknown option: --{$name}";
                continue;
            }
            if ($value === null) {
                if (!isset($args[$i + 1]) || self::looksLikeKnownOption($args[$i + 1], $keyByOption)) {
                    $errors[] = "--{$name} requires a value";
                    continue;
                }
                $value = $args[++$i];
            }
            if ($name === 'bot-token-file') {
                $tokenFile = $value;
            } elseif ($name === 'unit-file') {
                $flags['unit-file'] = $value;
            } else {
                $options[$keyByOption[$name]] = $value;
            }
        }

        return [$options, $tokenFile, $allowRoot, $errors, $flags];
    }

    /**
     * BOT_TOKEN comes from exactly one source: --bot-token-file, --bot-token or a non-empty BOT_TOKEN
     * environment variable. More than one source is an error: a stale exported BOT_TOKEN would otherwise
     * win over .env when the bot starts.
     *
     * @param array<string, string> $options
     * @param list<string>          $errors
     */
    private function resolveToken(array $options, ?string $tokenFile, array &$errors): ?string
    {
        $fromEnv = $this->environment['BOT_TOKEN'] ?? false;
        $fromEnv = is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
        $sources = array_filter([
            '--bot-token-file' => $tokenFile !== null,
            '--bot-token' => isset($options['BOT_TOKEN']),
            'the BOT_TOKEN environment variable' => $fromEnv !== null,
        ]);
        if (count($sources) > 1) {
            $errors[] = 'Pass the bot token with only one source, got: '.implode(', ', array_keys($sources)).'.';

            return null;
        }
        if ($tokenFile !== null) {
            $content = is_file($tokenFile) && is_readable($tokenFile) ? file_get_contents($tokenFile) : false;
            if ($content === false) {
                $errors[] = "Cannot read the token file {$tokenFile}.";

                return null;
            }

            return preg_replace('/\r?\n\z/', '', $content);
        }
        if (isset($options['BOT_TOKEN'])) {
            return $options['BOT_TOKEN'];
        }

        return $fromEnv;
    }

    private function howToPass(string $key): string
    {
        return $key === 'BOT_TOKEN'
            ? 'use --bot-token-file, --bot-token or the BOT_TOKEN environment variable'
            : 'use --'.strtolower(str_replace('_', '-', $key));
    }

    /**
     * @param list<string> $errors
     * @param resource     $err
     */
    private function fail(array $errors, $err): int
    {
        fwrite($err, "Nothing was written. Errors:\n");
        foreach ($errors as $error) {
            fwrite($err, "  - {$error}\n");
        }

        return 1;
    }
}
