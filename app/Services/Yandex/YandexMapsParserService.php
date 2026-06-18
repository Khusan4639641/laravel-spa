<?php

namespace App\Services\Yandex;

use App\DTO\ParsedOrganizationData;
use App\Exceptions\YandexParserException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Process\Process;

class YandexMapsParserService
{
    public function parse(string $url): ParsedOrganizationData
    {
        $scriptPath = base_path('scripts/yandex-parser.mjs');

        if (! is_file($scriptPath)) {
            throw new YandexParserException('Парсер Яндекс.Карт не найден.');
        }

        $process = new Process(['node', $scriptPath, $url], base_path());
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $safeMessage = $this->safeErrorMessage($stderr ?: $stdout);

            Log::warning('Yandex Maps parser failed', [
                'exit_code' => $process->getExitCode(),
                'url' => $url,
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);

            throw new YandexParserException($safeMessage, $stderr ?: $stdout, (int) $process->getExitCode());
        }

        try {
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('Yandex Maps parser returned invalid JSON', [
                'url' => $url,
                'output' => $process->getOutput(),
                'error' => $exception->getMessage(),
            ]);

            throw new YandexParserException('Парсер вернул некорректный ответ.');
        }

        if (! is_array($payload)) {
            throw new YandexParserException('Парсер вернул пустой ответ.');
        }

        $data = ParsedOrganizationData::fromArray($payload);

        if ($data->title === null && $data->rating === null && $data->reviews === []) {
            throw new YandexParserException('Не удалось найти данные организации. Возможно, страница недоступна или разметка Яндекс.Карт изменилась.');
        }

        return $data;
    }

    private function safeErrorMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if ($message === '') {
            return 'Не удалось получить данные из Яндекс.Карт.';
        }

        if (str_contains(strtolower($message), 'captcha') || str_contains($message, 'blocked')) {
            return 'Yandex blocked automated access or captcha required';
        }

        if (str_contains($message, 'Playwright Chromium is not installed')) {
            return $message;
        }

        return mb_substr($message, 0, 300);
    }
}
