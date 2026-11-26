<?php

declare(strict_types=1);

namespace Showcase\Agent\Tools;

interface Tool
{
    public function name(): string;

    /** Written for the model: when to call it and what comes back. */
    public function description(): string;

    /** JSON Schema of the input. */
    public function inputSchema(): array;

    /**
     * Run it. Input comes from the model, so treat it as untrusted: validate, clamp, never pass it to a shell or a
     * query unescaped.
     *
     * @param \Closure(array): void $emit send an event to the client right away (e.g. "searching…")
     */
    public function run(array $input, \Closure $emit): ToolOutput;
}
