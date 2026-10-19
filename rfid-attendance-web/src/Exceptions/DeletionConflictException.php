<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class DeletionConflictException extends DomainException
{
    public function __construct(private readonly array $conflict)
    {
        parent::__construct((string) ($conflict['message'] ?? 'This record cannot be deleted while related records still exist.'));
    }

    public function payload(): array
    {
        return ['ok' => false, 'code' => 'deletion_conflict'] + $this->conflict;
    }
}
