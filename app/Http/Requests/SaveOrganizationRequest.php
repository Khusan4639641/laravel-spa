<?php

namespace App\Http\Requests;

use App\Services\Yandex\YandexMapsUrlNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class SaveOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_url' => [
                'required',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, callable $fail): void {
                    try {
                        app(YandexMapsUrlNormalizer::class)->normalize((string) $value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }
}
