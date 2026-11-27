<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Coder;

/** A credential-free, non-interactive environment for local coder execution. */
final class ExecutionEnvironment
{
    private static ?string $configDirectory = null;

    private const array FORGE_CREDENTIALS = [
        'GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_PAT', 'GH_ENTERPRISE_TOKEN',
        'GITHUB_ENTERPRISE_TOKEN', 'GITLAB_TOKEN', 'GL_TOKEN', 'GITLAB_PAT', 'CI_JOB_TOKEN',
        'SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GIT_ASKPASS', 'SSH_ASKPASS', 'GIT_SSH', 'GIT_SSH_COMMAND',
        'GIT_HTTP_EXTRAHEADER', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_GLOBAL', 'GIT_CONFIG_SYSTEM',
    ];

    /**
     * @param array<string, string>|null $environment
     *
     * @return array<string, string>
     */
    public static function sanitize(?array $environment = null): array
    {
        $environment = $environment ?? getenv();
        foreach ($environment as $name => $value) {
            if (in_array($name, self::FORGE_CREDENTIALS, true)
                || preg_match('/^(?:GITHUB|GH|GITLAB|GL)_.*(?:TOKEN|PASSWORD|PASSWD|SECRET|CREDENTIAL|_KEY|_PAT)/', $name) === 1
                || str_starts_with($name, 'GIT_CREDENTIAL_')
                || preg_match('/^GIT_CONFIG_(?:COUNT|KEY_\d+|VALUE_\d+)$/', $name) === 1) {
                unset($environment[$name]);
            }
        }

        // Forge CLIs otherwise read saved credentials from these locations even with all token variables removed.
        $configDirectory = self::configDirectory();
        $environment['GH_CONFIG_DIR'] = $configDirectory.'/gh';
        $environment['GLAB_CONFIG_DIR'] = $configDirectory.'/glab';
        $environment['XDG_CONFIG_HOME'] = $configDirectory.'/xdg';

        // Empty credential.helper disables every inherited helper. ClaudeRunner may append its hooks
        // after these entries with GitHooks::apply(); that does not restore an authorization path.
        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $environment['GIT_ASKPASS'] = '/bin/false';
        $environment['SSH_ASKPASS'] = '/bin/false';
        $environment['GIT_SSH_COMMAND'] = 'ssh -o BatchMode=yes -o IdentitiesOnly=yes -o IdentityFile=/dev/null';
        $environment['GIT_CONFIG_COUNT'] = '2';
        $environment['GIT_CONFIG_KEY_0'] = 'credential.helper';
        $environment['GIT_CONFIG_VALUE_0'] = '';
        $environment['GIT_CONFIG_KEY_1'] = 'core.askPass';
        $environment['GIT_CONFIG_VALUE_1'] = '/bin/false';

        return $environment;
    }

    /** Apply in the dedicated worker/engine process, never in the Bot process. */
    public static function apply(): void
    {
        // A supervisor applies this after forking, giving every engine run a fresh configuration root.
        self::$configDirectory = null;
        /** @var array<string, string> $before */
        $before = getenv();
        $after = self::sanitize($before);
        foreach (array_diff_key($before, $after) as $name => $value) {
            putenv($name);
        }
        foreach ($after as $name => $value) {
            putenv($name.'='.$value);
        }
    }

    private static function configDirectory(): string
    {
        if (self::$configDirectory !== null) {
            return self::$configDirectory;
        }
        $directory = sys_get_temp_dir().'/workspace-assistant-coder-config-'.bin2hex(random_bytes(16));
        foreach (['', '/gh', '/glab', '/xdg'] as $suffix) {
            if (!mkdir($directory.$suffix, 0700, true) && !is_dir($directory.$suffix)) {
                throw new \RuntimeException('Unable to create the credential-free coder configuration directory.');
            }
        }
        self::$configDirectory = $directory;

        return $directory;
    }
}
