<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Tests;

use GpsLab\WorkspaceAssistant\SecretGuard;
use GpsLab\WorkspaceAssistant\SecretScanner;
use GpsLab\WorkspaceAssistant\SecretSources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The decision of the secret guard of an OpenCode run ({@see SecretGuard}): a command that carries a
 * secret is denied, an ordinary command passes, and the reason never holds a value.
 *
 * Fake values are built by concatenation: no literal token lands in the repository.
 */
#[CoversClass(SecretGuard::class)]
final class SecretGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/secret-guard-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
        SecretScanner::setShared(null);
    }

    /**
     * A scanner with the known values of the given `.env` and `SECRET_ENV_FILES`.
     *
     * @param list<string> $files
     */
    private function scanner(string $env = '', array $files = []): SecretScanner
    {
        $envFile = $this->dir.'/.env';
        file_put_contents($envFile, $env);

        return new SecretScanner(new SecretSources($envFile, $files, []));
    }

    /** A fake GitHub token: a shape the scanner knows without any source. */
    private static function token(): string
    {
        return 'ghp_'.substr(str_repeat('aB3dE5gH7jK9mN1pQ4rS', 2), 0, 36);
    }

    public function testACommandWithAShapedSecretIsDeniedWithItsType(): void
    {
        $token = self::token();

        $reason = SecretGuard::decide('curl -H "Authorization: token '.$token.'" https://api.github.com/user', $this->scanner());

        self::assertNotNull($reason);
        self::assertStringContainsString('github_token', $reason);
    }

    public function testAKnownValueIsDeniedToo(): void
    {
        $value = 'Zq7Xm2Vb9Lk4Pw8';
        $secrets = $this->dir.'/secrets.env';
        file_put_contents($secrets, 'DB_PASSWORD='.$value."\n");

        $reason = SecretGuard::decide('psql "postgres://db:'.$value.'@localhost/app"', $this->scanner(files: [$secrets]));

        self::assertNotNull($reason);
        self::assertStringContainsString('known:', $reason);
        self::assertStringNotContainsString($value, $reason);
    }

    public function testTheReasonNamesEveryTypeOnce(): void
    {
        $command = 'echo '.self::token().' && echo glpat-'.str_repeat('b', 20).' && echo '.self::token();

        $reason = (string) SecretGuard::decide($command, $this->scanner());

        self::assertStringContainsString('(github_token, gitlab_token)', $reason);
    }

    public function testAnOrdinaryCommandPasses(): void
    {
        self::assertNull(SecretGuard::decide('git status --short && ls -la', $this->scanner()));
    }

    public function testAnEmptyCommandPasses(): void
    {
        self::assertNull(SecretGuard::decide('', $this->scanner()));
    }

    /** A command that only names a placeholder or a variable is not a secret. */
    public function testACommandWithAVariableReferencePasses(): void
    {
        self::assertNull(SecretGuard::decide('curl -H "Authorization: Bearer $GITHUB_TOKEN" https://api.github.com', $this->scanner()));
    }

    public function testTheFailureReasonIsFixed(): void
    {
        self::assertStringContainsString('secret guard failed', SecretGuard::FAILURE_REASON);
    }
}
