<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep;

enum CepFormat: string
{
    case PDF = 'PDF';
    case XML = 'XML';
    case ZIP = 'ZIP';

    public function extension(): string
    {
        return strtolower($this->value);
    }
}
