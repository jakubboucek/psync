<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Install;

use RuntimeException;

/**
 * Renders the server agent from a template – fills in the public key and protect-list.
 */
final readonly class AgentBuilder
{
    private string $templatePath;

    public function __construct(?string $templatePath = null)
    {
        $this->templatePath = $templatePath ?? __DIR__ . '/../../agent/agent.template.php';
    }

    /**
     * @param list<string> $protect
     */
    public function build(string $publicKeyBase64, array $protect = []): string
    {
        $tpl = @file_get_contents($this->templatePath);
        if ($tpl === false) {
            throw new RuntimeException("Unable to load the agent template: {$this->templatePath}");
        }

        if (!str_contains($tpl, 'PSYNC_PUBLICKEY_PLACEHOLDER') || !str_contains($tpl, '/* PSYNC_PROTECT */')) {
            throw new RuntimeException('The agent template does not contain the expected placeholders.');
        }

        $protectCode = implode(', ', array_map(
            static fn(string $p): string => var_export($p, true),
            array_values($protect),
        ));

        $tpl = str_replace('PSYNC_PUBLICKEY_PLACEHOLDER', $publicKeyBase64, $tpl);
        $tpl = str_replace('/* PSYNC_PROTECT */', $protectCode, $tpl);

        return $tpl;
    }
}
