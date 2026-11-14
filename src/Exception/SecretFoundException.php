<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant\Exception;

use GpsLab\WorkspaceAssistant\OutgoingSecret;

/**
 * The commit gate found a secret in the outgoing changes: nothing was committed or pushed and the
 * index was reset. The findings carry types and places, never values.
 */
final class SecretFoundException extends \RuntimeException
{
    /**
     * @param non-empty-list<OutgoingSecret> $findings
     */
    public function __construct(public readonly array $findings)
    {
        parent::__construct('secret found in outgoing changes: '.implode(', ', array_map(
            static fn (OutgoingSecret $f): string => $f->location().' ('.$f->type.')',
            $findings,
        )));
    }
}
