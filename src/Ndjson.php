<?php

declare(strict_types=1);

namespace Showcase\Agent;

/** One JSON object per line, flushed immediately (with `X-Accel-Buffering: no` so nginx does not hold it back). */
final class Ndjson
{
    public static function emitter(): \Closure
    {
        return static function (array $event): void {
            echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };
    }

    public static function headers(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
