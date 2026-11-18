<?php

namespace App\Core;

class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';

    public function setStatusCode(int $code): static
    {
        $this->status = $code;

        return $this;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function setJSON($data): static
    {
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        $this->body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
