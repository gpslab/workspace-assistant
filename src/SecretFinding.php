<?php

declare(strict_types=1);

namespace GpsLab\WorkspaceAssistant;

/**
 * One masked place in a text. Carries the type (`known:<KEY_NAME>` or the pattern name) and the
 * location, never the value or any part of it.
 */
final readonly class SecretFinding
{
    /**
     * @param int $offset byte offset of the match in the scanned text
     * @param int $line   1-based line of the match in the scanned text
     */
    public function __construct(
        public string $type,
        public int $offset,
        public int $line,
    ) {
    }
}
