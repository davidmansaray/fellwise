<?php

declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates wire data against the published contracts in docs/events. Each
 * schema's `$id` is `{BASE_URI}{file}`, so the resolver maps ids to files and
 * a payload schema is found purely from the envelope's routing key.
 */
final class EventSchemas
{
    public const string BASE_URI = 'https://schemas.fellwise.co.uk/events/';

    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator(max_errors: 20);
        $this->validator->resolver()?->registerPrefix(self::BASE_URI, self::directory());
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/docs/events';
    }

    public static function payloadSchemaFile(string $routingKey): string
    {
        return "{$routingKey}.schema.json";
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, list<string>> violations keyed by JSON pointer; empty when valid
     */
    public function envelopeViolations(array $envelope): array
    {
        return $this->violations('envelope.schema.json', $envelope);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    public function payloadViolations(string $routingKey, array $payload): array
    {
        return $this->violations(self::payloadSchemaFile($routingKey), $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function violations(string $schemaFile, array $data): array
    {
        // Round-trip through JSON so the validator sees exactly what goes on the wire.
        $json = json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($json, self::BASE_URI.$schemaFile);

        if ($result->isValid()) {
            return [];
        }

        /** @var array<string, list<string>> $errors */
        $errors = (new ErrorFormatter)->format($result->error());

        return $errors;
    }
}
