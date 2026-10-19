<?php
declare(strict_types=1);
namespace App\Services;

/** Spreadsheet presentation only; AnalyticsService remains responsible for scope and calculations. */
final class AnalyticsExport
{
    /** @param resource $stream */
    public static function row($stream, array $values): void
    {
        $cells = array_map(static function ($value): string {
            $text = (string) ($value ?? '');
            // Apply this to metadata as well as records: filter labels may contain user-entered text.
            return preg_match('/^[\s]*[=+@-]/u', $text) ? "'" . $text : $text;
        }, $values);
        fputcsv($stream, $cells, ',', '"', '', "\r\n");
    }

    public static function date(?string $value, bool $withTime = false): string
    {
        if (!$value) return 'Not yet available';
        try {
            return (new \DateTimeImmutable($value))->format($withTime ? 'M j, Y, g:i A' : 'M j, Y');
        } catch (\Exception) {
            return 'Not available';
        }
    }

    /** @param resource $stream */
    public static function heading($stream, string $section, array $metadata, array $records): void
    {
        self::row($stream, ['CampusTrack Analytics', AnalyticsMetrics::SECTIONS[$section]]);
        self::row($stream, ['Report', $records['title'] ?? AnalyticsMetrics::SECTIONS[$section] . ' records']);
        self::row($stream, ['Export generated', self::date($metadata['calculated_at'], true)]);
        self::row($stream, ['Campus timezone', $metadata['timezone']]);
        self::row($stream, ['Access scope', $metadata['scope']]);
        self::row($stream, ['Matching records', $records['total']]);
        self::row($stream, []);
        self::row($stream, ['Applied filters']);
        foreach ($metadata['filter_labels'] as $label => $value) self::row($stream, [$label, $value]);
        self::row($stream, []);
        self::row($stream, ['Calculation notes']);
        self::row($stream, ['Calculation version', $metadata['version']]);
        foreach ($metadata['definitions'] as $definition) {
            self::row($stream, ['How calculated', $records['formula'] ?? $definition['formula']]);
            self::row($stream, ['Unit of analysis', $definition['grain']]);
            self::row($stream, ['Excluded from calculations', implode('; ', $definition['exclusions'])]);
        }
        if (isset($records['description'])) self::row($stream, ['Report context', $records['description']]);
        if (isset($records['coverage'])) self::row($stream, ['Coverage', $records['coverage']]);
        self::row($stream, ['Historical coverage', 'Eligibility and policy history begins ' . self::date($metadata['baseline_at'], true) . '. Earlier records may have unknown eligibility or policies; legacy class labels use current metadata.']);
        self::row($stream, ['Analytics facts updated', self::date($metadata['built_at'], true)]);
        self::row($stream, ['Refresh status', $metadata['stale'] ? 'Refresh pending' : 'Up to date']);
        self::row($stream, ['Missing values', 'Blank cells mean unavailable or not recorded. Missing values are not zero.']);
        if (!$records['total']) self::row($stream, ['Results', 'No matching records for the applied filters.']);
        self::row($stream, []);
        self::row($stream, array_values($records['columns']));
    }

    public static function record(array $columns, array $record): array
    {
        $values = [];
        foreach (array_keys($columns) as $field) {
            $value = $record[$field] ?? '';
            if (in_array($field, ['start_time', 'end_time', 'start', 'end', 'hour'], true) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', (string) $value)) {
                $value = (new \DateTimeImmutable($value))->format('g:i A');
            } elseif (in_array($field, ['created_at', 'finalized_at'], true) && $value !== '') {
                $value = self::date((string) $value, true);
            } elseif (in_array($field, ['outcome', 'reason_code'], true)) {
                $value = ucfirst(str_replace('_', ' ', (string) $value));
            }
            $values[] = $value;
        }
        return $values;
    }
}
