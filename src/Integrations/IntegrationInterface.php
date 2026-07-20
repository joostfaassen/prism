<?php

namespace App\Integrations;

interface IntegrationInterface
{
    /**
     * Profile type string. Must match the `type:` value in prism config files
     * and ToolInterface::getProfileType() of this integration's tools.
     */
    public function getType(): string;

    /** Human-readable name, e.g. "bunq". */
    public function getLabel(): string;

    /** One-line description shown on the integrations admin page. */
    public function getDescription(): string;
}
