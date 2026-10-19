<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class ViolationService
{
    private const EVIDENCE_TYPES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    ];

    public static function catalog(): array
    {
        return config('violations');
    }

    public static function issue(array $data, int $issuerId, ?array $evidence = null): int
    {
        $studentId = (int) ($data['student_id'] ?? 0);
        $group = trim((string) ($data['violation_group'] ?? ''));
        $selectedSpecific = trim((string) ($data['specific_violation'] ?? ''));
        $specific = $selectedSpecific;
        $other = trim((string) ($data['other_violation'] ?? ''));
        $classification = trim((string) ($data['classification'] ?? ''));
        $progressive = isset($data['is_progressive']) && (string) $data['is_progressive'] === '1';
        $previous = $progressive ? filter_var($data['previous_infractions'] ?? null, FILTER_VALIDATE_INT) : null;
        $current = $progressive ? filter_var($data['current_occurrence'] ?? null, FILTER_VALIDATE_INT) : null;
        $date = trim((string) ($data['incident_date'] ?? ''));
        $time = normalize_time($data['incident_time'] ?? '');
        $location = trim((string) ($data['location'] ?? ''));
        $description = trim((string) ($data['incident_description'] ?? ''));
        $witnesses = trim((string) ($data['witnesses'] ?? ''));

        $catalog = self::catalog();
        if ($studentId < 1) throw new DomainException('Select a student.');
        if (!isset($catalog[$group])) throw new DomainException('Select a valid violation.');
        if ($selectedSpecific === 'Other') $specific = $other;
        if ($specific === '' || mb_strlen($specific) > 500) throw new DomainException('Select or enter a specific violation up to 500 characters.');
        if ($selectedSpecific !== 'Other' && !in_array($selectedSpecific, $catalog[$group]['items'], true)) throw new DomainException('Select a specific violation from the handbook list.');
        if (!in_array($classification, ['Category A', 'Category B', 'Category C'], true)) throw new DomainException('Select a valid classification.');
        if ($progressive && (!is_int($previous) || !is_int($current) || $previous < 0 || $previous > 20 || $current < 1 || $current > 21 || $current !== $previous + 1)) {
            throw new DomainException('For a progressive violation, current occurrence must be one more than previous infractions.');
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || $date > date('Y-m-d')) throw new DomainException('Select a valid incident date that is not in the future.');
        if ($location === '' || mb_strlen($location) > 180) throw new DomainException('Enter an incident location up to 180 characters.');
        if (mb_strlen($description) < 10 || mb_strlen($description) > 5000) throw new DomainException('Enter an incident description between 10 and 5,000 characters.');
        if (mb_strlen($witnesses) > 2000) throw new DomainException('Witness details cannot exceed 2,000 characters.');

        $pdo = Database::connection();
        $student = $pdo->prepare('SELECT school_id, first_name, last_name FROM users WHERE id = :id AND status = "active" AND EXISTS(SELECT 1 FROM user_roles WHERE user_id = users.id AND role_id = 3)');
        $student->execute(['id' => $studentId]);
        $studentRow = $student->fetch();
        if (!$studentRow) throw new DomainException('The selected active student could not be found.');

        $saved = self::storeEvidence($evidence);
        try {
            $statement = $pdo->prepare('INSERT INTO disciplinary_violations
                (student_id, issued_by, violation_group, specific_violation, classification, is_progressive, previous_infractions, current_occurrence, incident_date, incident_time, location, incident_description, witnesses, evidence_path, evidence_original_name, evidence_mime, evidence_size)
                VALUES (:student, :issuer, :violation_group, :specific, :classification, :progressive, :previous, :current, :incident_date, :incident_time, :location, :description, :witnesses, :evidence_path, :evidence_name, :evidence_mime, :evidence_size)');
            $statement->execute([
                'student'=>$studentId, 'issuer'=>$issuerId, 'violation_group'=>$group, 'specific'=>$specific,
                'classification'=>$classification, 'progressive'=>$progressive ? 1 : 0, 'previous'=>$previous, 'current'=>$current,
                'incident_date'=>$date, 'incident_time'=>$time, 'location'=>$location, 'description'=>$description,
                'witnesses'=>$witnesses !== '' ? $witnesses : null, 'evidence_path'=>$saved['path'] ?? null,
                'evidence_name'=>$saved['name'] ?? null, 'evidence_mime'=>$saved['mime'] ?? null, 'evidence_size'=>$saved['size'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();
            AuditService::log($issuerId, 'issue_violation', 'disciplinary_violation', $id, json_encode(['student_id'=>$studentId,'classification'=>$classification,'violation_group'=>$group], JSON_THROW_ON_ERROR));
            return $id;
        } catch (Throwable $exception) {
            if (isset($saved['absolute']) && is_file($saved['absolute'])) @unlink($saved['absolute']);
            throw $exception;
        }
    }

    private static function storeEvidence(?array $file): ?array
    {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('The evidence upload could not be completed.');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 5_242_880) throw new DomainException('Evidence must be a PDF, JPG, PNG, or WebP file no larger than 5 MB.');
        $temporary = (string) ($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) throw new DomainException('The evidence upload could not be verified.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!is_string($mime) || !isset(self::EVIDENCE_TYPES[$mime])) throw new DomainException('Evidence must be a PDF, JPG, PNG, or WebP file.');
        $directory = BASE_PATH . '/storage/violation-evidence';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new DomainException('Private evidence storage is unavailable.');
        $filename = bin2hex(random_bytes(24)) . '.' . self::EVIDENCE_TYPES[$mime];
        $absolute = $directory . '/' . $filename;
        if (!move_uploaded_file($temporary, $absolute)) throw new DomainException('The evidence file could not be saved.');
        @chmod($absolute, 0600);
        return ['path'=>'storage/violation-evidence/'.$filename, 'absolute'=>$absolute, 'name'=>mb_substr(basename((string) ($file['name'] ?? 'evidence')),0,255), 'mime'=>$mime, 'size'=>$size];
    }
}
