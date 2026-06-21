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
     * @param string $scopeRelPath baked path from the agent's __DIR__ to the sync root ('' = __DIR__)
     * @param string $version client version baked into the header comment (purely informational)
     */
    public function build(string $publicKeyBase64, string $scopeRelPath = '', array $protect = [], string $version = 'unknown'): string
    {
        $tpl = @file_get_contents($this->templatePath);
        if ($tpl === false) {
            throw new RuntimeException("Unable to load the agent template: {$this->templatePath}");
        }

        if (
            !str_contains($tpl, 'PSYNC_PUBLICKEY_PLACEHOLDER')
            || !str_contains($tpl, '/* PSYNC_PROTECT */')
            || !str_contains($tpl, 'PSYNC_SCOPE_PLACEHOLDER')
            || !str_contains($tpl, '{{ PsyncGeneratedAt }}')
            || !str_contains($tpl, '{{ PsyncVersion }}')
        ) {
            throw new RuntimeException('The agent template does not contain the expected placeholders.');
        }

        $protectCode = implode(', ', array_map(
            static fn(string $p): string => var_export($p, true),
            array_values($protect),
        ));

        $tpl = str_replace('PSYNC_PUBLICKEY_PLACEHOLDER', $publicKeyBase64, $tpl);
        $tpl = str_replace('/* PSYNC_PROTECT */', $protectCode, $tpl);
        // A bareword placeholder replaced with a string literal (var_export quotes
        // and escapes it), mirroring how the protect-list is injected.
        $tpl = str_replace('PSYNC_SCOPE_PLACEHOLDER', var_export($scopeRelPath, true), $tpl);

        // Informational header stamp (lives inside the /** */ comment, so it never
        // touches the protocol). Local time with offset, e.g. 2026-06-21 16:30:00+02:00.
        $tpl = str_replace('{{ PsyncGeneratedAt }}', date('Y-m-d H:i:sP'), $tpl);
        $tpl = str_replace('{{ PsyncVersion }}', $version, $tpl);

        return $tpl;
    }
}
