<?php

namespace Maqiis\DocumentBuilder\Variable;

interface VariableResolver
{
    /** Kembalikan nilai mentah (belum di-escape), atau null bila jalur tidak dikenal. */
    public function resolve(string $path): ?string;
}
