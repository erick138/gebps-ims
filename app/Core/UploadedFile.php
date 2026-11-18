<?php

namespace App\Core;

class UploadedFile
{
    public function __construct(private array $f)
    {
    }

    public function isValid(): bool
    {
        return ($this->f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) ($this->f['tmp_name'] ?? ''));
    }

    public function getTempName(): string
    {
        return (string) $this->f['tmp_name'];
    }

    public function getClientName(): string
    {
        return basename((string) ($this->f['name'] ?? ''));
    }

    public function getSize(): int
    {
        return (int) ($this->f['size'] ?? 0);
    }
}
