<?php

declare(strict_types=1);

namespace Showcase\Agent\Tools;

final class ToolOutput
{
    /**
     * @param string $content what the model reads
     * @param bool $isError the model sees it as a failed call and can try something else
     * @param bool $terminal nothing for the model to read here (it only drives the UI): if a round calls only terminal
     *                       tools and the visible reply is already written, the loop stops without another model call
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
        public readonly bool $terminal = false,
    ) {
    }
}
