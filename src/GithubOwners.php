<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * GitHub owners the bot works with (`GITHUB_OWNERS`, comma-separated). The first one is the
 * default owner: the short forms `repo#N` and `repo` belong to it.
 *
 * Task identifiers are `owner/repo#N` or the short `repo#N`. Stored short identifiers stay
 * valid: they are normalized on read and never rewritten.
 */
final class GithubOwners
{
    public const string NOT_SET = 'set GITHUB_OWNERS';

    private const string NAME = '[A-Za-z0-9][A-Za-z0-9._-]*';

    /** @var list<string> */
    private readonly array $owners;

    /**
     * @param array<string> $owners
     */
    public function __construct(array $owners = [])
    {
        $this->owners = array_values(array_unique(array_filter(array_map('trim', $owners), static fn (string $o): bool => $o !== '')));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->owners;
    }

    public function isEmpty(): bool
    {
        return $this->owners === [];
    }

    /** @throws \RuntimeException when no owner is configured */
    public function default(): string
    {
        return $this->owners[0] ?? throw new \RuntimeException(self::NOT_SET);
    }

    /** The configured spelling of $owner (GitHub owners are case-insensitive), or null if it is not listed. */
    public function resolve(string $owner): ?string
    {
        foreach ($this->owners as $known) {
            if (strcasecmp($known, $owner) === 0) {
                return $known;
            }
        }

        return null;
    }

    /**
     * Regexp alternation of the configured owners for use inside a pattern.
     *
     * @throws \RuntimeException when no owner is configured
     */
    public function pattern(): string
    {
        if ($this->owners === []) {
            throw new \RuntimeException(self::NOT_SET);
        }

        return implode('|', array_map(static fn (string $o): string => preg_quote($o, '~'), $this->owners));
    }

    /**
     * Splits `owner/repo#N`, `repo#N`, `owner/repo` or `repo` into [owner, repo, number|null].
     * The short forms get the default owner.
     *
     * @return array{string, string, ?int}
     *
     * @throws \RuntimeException         when a short form is used and no owner is configured
     * @throws \InvalidArgumentException on a malformed reference or an owner missing from GITHUB_OWNERS
     */
    public function parse(string $ref): array
    {
        if (preg_match('~^(?:('.self::NAME.')/)?('.self::NAME.')(?:#([1-9][0-9]*))?$~', $ref, $m) !== 1) {
            throw new \InvalidArgumentException("invalid issue or repository reference: {$ref}");
        }

        if ($m[1] === '') {
            $owner = $this->default();
        } else {
            $owner = $this->resolve($m[1]) ?? throw new \InvalidArgumentException("owner {$m[1]} is not in GITHUB_OWNERS: {$ref}");
        }

        return [$owner, $m[2], isset($m[3]) ? (int) $m[3] : null];
    }

    /** `owner/repo` of a task, a PR or a repository reference; `repo#N` and `<default>/repo#N` give the same value. */
    public function slug(string $ref): string
    {
        [$owner, $repo] = $this->parse($ref);

        return $owner.'/'.$repo;
    }

    /**
     * Canonical reference: the default owner is dropped (`repo#N`, as stored before several
     * owners were supported), other owners are kept (`owner/repo#N`). Owners are matched
     * case-insensitively.
     */
    public function canonical(string $ref): string
    {
        [$owner, $repo, $number] = $this->parse($ref);
        $prefix = strcasecmp($owner, $this->default()) === 0 ? '' : $owner.'/';

        return $prefix.$repo.($number !== null ? '#'.$number : '');
    }

    /**
     * Repository key of a task or repository reference: canonical `repo` for the default owner,
     * `owner/repo` for the others. `repo#N` and `<default>/repo#N` give the same key.
     */
    public function repoKey(string $ref): string
    {
        [$owner, $repo] = $this->parse($ref);

        return $this->canonical($owner.'/'.$repo);
    }
}
