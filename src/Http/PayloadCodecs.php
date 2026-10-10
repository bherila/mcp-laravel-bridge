<?php

namespace Bherila\McpLaravelBridge\Http;

use InvalidArgumentException;

/**
 * The codecs an application serves beside JSON. Bind one in the container
 * and `Route::operation()` negotiates against it; pass it to
 * `OpenApiDocumentBuilder` and the document offers each media type it serves.
 */
final readonly class PayloadCodecs
{
    public const string JSON = 'application/json';

    /** @var array<string, PayloadCodec> lower-cased media type => codec */
    private array $codecs;

    public function __construct(PayloadCodec ...$codecs)
    {
        $byType = [];
        foreach ($codecs as $codec) {
            $type = self::normalize($codec->mediaType());
            if ($type === self::JSON || preg_match("~^[a-z0-9!#$%&'*+.^_`|\\~-]+/[a-z0-9!#$%&'*+.^_`|\\~-]+$~", $type) !== 1 || in_array('*', explode('/', $type), true)) {
                throw new InvalidArgumentException("A payload codec cannot serve [{$codec->mediaType()}]: it must be a concrete media type other than JSON.");
            }
            if (isset($byType[$type])) {
                throw new InvalidArgumentException("Two payload codecs serve [{$type}].");
            }
            $byType[$type] = $codec;
        }
        $this->codecs = $byType;
    }

    /** Whether a media type is JSON: `application/json` or a `+json` structured suffix. */
    public static function isJson(string $mediaType): bool
    {
        $type = self::normalize($mediaType);

        return $type === self::JSON || str_ends_with($type, '+json');
    }

    /** @return list<string> in registration order */
    public function mediaTypes(): array
    {
        return array_keys($this->codecs);
    }

    public function isEmpty(): bool
    {
        return $this->codecs === [];
    }

    /** The codec for a Content-Type header value (parameters ignored), if any. */
    public function forContentType(?string $contentType): ?PayloadCodec
    {
        return $contentType === null ? null : ($this->codecs[self::normalize($contentType)] ?? null);
    }

    /**
     * The codec an Accept header prefers over JSON, or null for JSON. Each
     * offered type takes the quality of the most specific range matching it;
     * the highest wins and JSON wins a tie, so a client that never asks for a
     * codec's type, or asks for anything, gets JSON as before.
     */
    public function negotiate(?string $accept): ?PayloadCodec
    {
        if ($accept === null || trim($accept) === '' || $this->codecs === []) {
            return null;
        }
        $ranges = [];
        foreach (explode(',', $accept) as $part) {
            $pieces = explode(';', $part);
            $range = self::normalize($pieces[0]);
            if ($range === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
                if (strtolower(trim($name)) === 'q' && is_numeric(trim($value))) {
                    $quality = max(0.0, min(1.0, (float) trim($value)));
                }
            }
            $ranges[] = [$range, $quality];
        }

        $best = null;
        $bestQuality = self::quality(self::JSON, $ranges);
        foreach ($this->codecs as $type => $codec) {
            $quality = self::quality($type, $ranges);
            if ($quality > $bestQuality) {
                [$best, $bestQuality] = [$codec, $quality];
            }
        }

        return $best;
    }

    /** @param  list<array{0: string, 1: float}>  $ranges */
    private static function quality(string $type, array $ranges): float
    {
        [$major] = explode('/', $type);
        $specificity = -1;
        $quality = 0.0;
        foreach ($ranges as [$range, $q]) {
            $match = match (true) {
                $range === $type => 2,
                $range === $major.'/*' => 1,
                $range === '*/*' => 0,
                default => -1,
            };
            if ($match > $specificity) {
                [$specificity, $quality] = [$match, $q];
            }
        }

        return $quality;
    }

    private static function normalize(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType)[0]));
    }
}
