<?php

namespace Maqiis\DocumentBuilder\Schema;

use InvalidArgumentException;

final class SchemaValidationException extends InvalidArgumentException
{
    /** @param array<string,string> $errors berkunci jalur bertitik, contoh "zones.body.blocks.0.type" */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Schema template tidak valid: '.implode('; ', $errors));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
