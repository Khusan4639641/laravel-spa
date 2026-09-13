<?php

namespace App\Services\Yandex;

use App\DTO\ParsedYandexResult;
use App\Exceptions\YandexParserException;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PlaywrightYandexMapsParser implements YandexMapsParserInterface
{
    public function parse(string $url, ?callable $onProgress = null): ParsedYandexResult
    {
        $url = app(YandexMapsUrlNormalizer::class)->normalize($url);
        $options = config('yandex');
        $process = new Process([
            $options['node_binary'], base_path('scripts/yandex-parser.mjs'), $url,
            json_encode($options, JSON_THROW_ON_ERROR),
        ], base_path(), ['PLAYWRIGHT_BROWSERS_PATH' => $options['browser_path']]);
        $process->setTimeout($options['total_timeout_ms'] / 1000 + 15);
        $buffer = '';
        try {
            $process->run(function (string $type, string $chunk) use (&$buffer, $onProgress, $process): void {
                if (strlen($process->getOutput()) > 32 * 1024 * 1024) {
                    $process->stop(1);
                    throw new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Parser output limit exceeded.');
                }
                if ($type !== Process::ERR) {
                    return;
                }
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $event = json_decode($line, true);
                    if (($event['type'] ?? null) === 'progress' && $onProgress !== null) {
                        $onProgress($event);
                    }
                }
                if (strlen($buffer) > 65536) {
                    $buffer = '';
                }
                $process->clearErrorOutput();
            });
        } catch (ProcessTimedOutException $exception) {
            throw new YandexParserException('YANDEX_NETWORK_ERROR', 'Node process exceeded its deadline.', $exception);
        }
        try {
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new YandexParserException('YANDEX_PARSER_UNAVAILABLE', 'Node returned invalid JSON; exit '.$process->getExitCode(), $exception);
        }
        if (isset($payload['error'])) {
            $code = $payload['error']['code'] ?? '';
            throw new YandexParserException(in_array($code, [
                'YANDEX_BLOCKED', 'YANDEX_NETWORK_ERROR', 'YANDEX_SOURCE_STRUCTURE_CHANGED', 'YANDEX_PARSER_UNAVAILABLE',
            ], true) ? $code : 'YANDEX_PARSER_UNAVAILABLE', $payload['error']['detail'] ?? '');
        }
        if (! $process->isSuccessful() || ! is_array($payload)) {
            throw new YandexParserException('YANDEX_PARSER_UNAVAILABLE', 'Unexpected process exit: '.$process->getExitCode());
        }

        return ParsedYandexResult::fromArray($payload);
    }
}
