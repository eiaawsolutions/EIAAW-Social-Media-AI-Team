<?php

namespace App\Support\Legal;

/**
 * Typed, testable accessor over config/legal.php.
 *
 * Gives the gate middleware (EnforceLegalAcceptance), the acceptance page, and
 * the acceptance recorder (User::recordLegalAcceptance) one place to read the
 * current legal version and document set — so none of them needs to know the
 * config-key shape, and the audit-row manifest is built identically everywhere.
 *
 * Sits beside App\Support\MailTransport and App\Support\Compliance\* — the
 * established home for cross-cutting, dependency-free support helpers.
 */
final class LegalDocuments
{
    /**
     * The current legal version, e.g. "2026-06-17". This is the value stamped
     * onto users.legal_accepted_version and compared by the gate.
     */
    public static function version(): string
    {
        return (string) config('legal.version', '');
    }

    /**
     * The ordered document map: key => ['name', 'route', 'updated'].
     *
     * @return array<string, array{name: string, route: string, updated: string}>
     */
    public static function documents(): array
    {
        return (array) config('legal.documents', []);
    }

    /**
     * Shown only on re-acceptance (returning users on a stale version).
     */
    public static function changeNote(): string
    {
        return (string) config('legal.change_note', '');
    }

    /**
     * Self-contained snapshot stored in each legal_acceptances row, so an audit
     * record stays meaningful even after config changes: the exact version and
     * the name+date of every document the user agreed to at that moment.
     *
     * @return array{version: string, documents: array<string, array{name: string, updated: string}>}
     */
    public static function manifest(): array
    {
        $documents = [];
        foreach (self::documents() as $key => $meta) {
            $documents[$key] = [
                'name' => (string) ($meta['name'] ?? $key),
                'updated' => (string) ($meta['updated'] ?? ''),
            ];
        }

        return [
            'version' => self::version(),
            'documents' => $documents,
        ];
    }
}
