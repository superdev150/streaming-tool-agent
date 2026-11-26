<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

/** A turn whose request is already open: stream its text, then read how it ended. */
interface ModelTurn
{
    /** @return iterable<string> visible text deltas, as they arrive */
    public function text(): iterable;

    /** Only valid after text() was fully consumed. */
    public function result(): TurnResult;
}
