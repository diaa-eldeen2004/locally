<?php

declare(strict_types=1);

namespace Locally\Security;

/**
 * Optional external scanner hook for uploaded files.
 *
 * If command is empty, scanning is treated as disabled and returns clean.
 * Command may include "{file}" placeholder; if absent, file path is appended.
 * Exit code 0 => clean, non-zero => failed/infected.
 */
final class UploadScanner
{
    public function __construct(
        private readonly string $scanCommandTemplate,
        private readonly bool $scanRequired,
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function scan(string $absoluteFilePath): array
    {
        if ($this->scanCommandTemplate === '') {
            return ['ok' => true, 'message' => 'scan-disabled'];
        }

        $escaped = escapeshellarg($absoluteFilePath);
        $cmd = str_contains($this->scanCommandTemplate, '{file}')
            ? str_replace('{file}', $escaped, $this->scanCommandTemplate)
            : $this->scanCommandTemplate . ' ' . $escaped;

        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0) {
            return ['ok' => true, 'message' => 'clean'];
        }

        if (!$this->scanRequired) {
            return ['ok' => true, 'message' => 'scanner-failed-but-optional'];
        }

        return [
            'ok' => false,
            'message' => 'scanner rejected upload' . ($out !== [] ? ': ' . trim((string) ($out[0] ?? '')) : ''),
        ];
    }
}
