<?php

declare(strict_types=1);

use GpsLab\WorkspaceAssistant\PromptFiles;

require dirname(__DIR__).'/vendor/autoload.php';

// Shipped texts only: a local prompts/<name>.md of the checkout must not leak into the tests.
$dist = sys_get_temp_dir().'/prompts-dist-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($dist);
foreach (glob(dirname(__DIR__).'/prompts/*.md.dist') ?: [] as $file) {
    copy($file, $dist.'/'.basename($file));
}
mkdir($dist.'/agents');
foreach (glob(dirname(__DIR__).'/prompts/agents/*.md.dist') ?: [] as $file) {
    copy($file, $dist.'/agents/'.basename($file));
}
PromptFiles::useDefaultDirectory($dist);

register_shutdown_function(static function () use ($dist): void {
    foreach ([...(glob($dist.'/agents/*') ?: []), ...(glob($dist.'/*.*') ?: [])] as $file) {
        unlink($file);
    }
    rmdir($dist.'/agents');
    rmdir($dist);
});
