<?php
declare(strict_types=1);
namespace App\Exceptions;
final class ScanRejection extends \DomainException
{
    public function __construct(string $message,public readonly string $reasonCode){parent::__construct($message);}
}
