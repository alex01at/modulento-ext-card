<?php

declare(strict_types=1);

namespace Modulento\Card;

/**
 * Bulk-invites employees from a CSV file: one row per invitation, run
 * through the exact same Organizations::invite() the single-invite form
 * uses, so every rule (valid address, not already taken, seat maximum)
 * applies identically - this is only a loop around it, never a shortcut
 * past it.
 */
final class TeamCsvImport
{
    public const TEMPLATE_HEADER = ['email', 'workplace'];
    private const MAX_ROWS = 1000;

    /** @return array{invited: int, errors: list<array{row: int, email: string, key: string}>, fatal: ?string} */
    public function import(string $path, int $organizationId, string $locale, Organizations $organizations): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['invited' => 0, 'errors' => [], 'fatal' => 'card.team.import.error.file'];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);

            return ['invited' => 0, 'errors' => [], 'fatal' => 'card.team.import.error.empty'];
        }
        $header = array_map(fn (string $name) => mb_strtolower(trim($name)), $header);

        $seen = [];
        $invited = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false && $rowNumber <= self::MAX_ROWS) {
            $rowNumber++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $fields = [];
            foreach ($header as $index => $name) {
                $fields[$name] = trim((string) ($row[$index] ?? ''));
            }
            $email = mb_strtolower((string) ($fields['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            if (isset($seen[$email])) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'key' => 'card.team.import.error.duplicate'];
                continue;
            }
            $seen[$email] = true;

            $workplace = trim((string) ($fields['workplace'] ?? ''));
            $problem = $organizations->invite($organizationId, $email, $locale, $workplace !== '' ? $workplace : null);
            if ($problem !== null) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'key' => $problem];
                continue;
            }
            $invited++;
        }
        fclose($handle);

        return ['invited' => $invited, 'errors' => $errors, 'fatal' => null];
    }
}
