<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/helpers.php';

function assertNormalized(string $actual, string $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, $message . "\nExpected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

assertNormalized(normalize_person_name('  JZHIRUBESON   yao '), 'Jzhirubeson Yao', 'Uppercase names should become proper case.');
assertNormalized(normalize_person_name('jezryl margareth villarma'), 'Jezryl Margareth Villarma', 'Lowercase names should become proper case.');
assertNormalized(normalize_person_name("o'connor"), "O'Connor", 'Letters after apostrophes should be capitalized.');
assertNormalized(normalize_person_name('ana-marie mcDONALD j. r.'), 'Ana-Marie McDonald J. R.', 'Hyphenated names, Mc names, and initials should be preserved.');
assertNormalized(normalize_title_case('bachelor of science in information technology'), 'Bachelor of Science in Information Technology', 'Academic names should use title case and retain minor words.');
assertNormalized(normalize_title_case('introduction to RFID and iot systems'), 'Introduction to RFID and IoT Systems', 'Known academic and technical acronyms should remain uppercase.');
assertNormalized(normalize_title_case('UI/UX DESIGN'), 'UI/UX Design', 'Slash-separated acronyms should remain uppercase.');
assertNormalized(normalize_title_case('USLS information technology week'), 'USLS Information Technology Week', 'Campus acronyms should remain uppercase.');
assertNormalized(normalize_title_case('capstone and research ii'), 'Capstone and Research II', 'Roman numerals should remain uppercase.');
assertNormalized(normalize_title_case('2d animation'), '2D Animation', 'Dimension abbreviations should retain their conventional case.');

echo "text normalization checks passed\n";
