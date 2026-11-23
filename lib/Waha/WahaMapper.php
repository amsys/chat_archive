<?php

declare(strict_types=1);

// Pure mapping from WAHA message JSON to the neutral message row of the backup reader.
// arch: D24.1

namespace OCA\ChatArchive\Waha;

final class WahaMapper
{
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    /** Convert a WAHA chat id to a backup jid. Null for ids that are not chats. */
    public static function jid(?string $wahaId): ?string
    {
        if ($wahaId === null || $wahaId === '' || $wahaId === 'status@broadcast') {
            return null;
        }
        if (str_ends_with($wahaId, '@newsletter') || str_ends_with($wahaId, '@broadcast')) {
            return null;
        }
        if (str_ends_with($wahaId, '@c.us')) {
            return substr($wahaId, 0, -5) . '@s.whatsapp.net';
        }
        return $wahaId;
    }

    /** Take the key id out of a WAHA message id. Null when there is none. */
    public static function keyId(string $wahaMessageId): ?string
    {
        if ($wahaMessageId === '') {
            return null;
        }
        if (!str_contains($wahaMessageId, '_')) {
            return $wahaMessageId;
        }
        $parts = explode('_', $wahaMessageId);
        $id = $parts[2] ?? '';
        return $id === '' ? null : $id;
    }

    /**
     * Message kind from the documented fields only.
     *
     * @param array<string,mixed> $m
     */
    public static function kind(array $m): string
    {
        if (!empty($m['hasMedia'])) {
            // WAHA can leave out the mimetype of media it did not download.
            $mime = self::mime($m);
            if ($mime === null) {
                return 'document';
            }
            if ($mime === 'image/webp') {
                return 'sticker';
            }
            if (str_starts_with($mime, 'image/')) {
                return 'image';
            }
            if (str_starts_with($mime, 'video/')) {
                return 'video';
            }
            if (str_starts_with($mime, 'audio/')) {
                return 'audio';
            }
            return 'document';
        }
        if (!empty($m['location'])) {
            return 'location';
        }
        if (!empty($m['vCards'])) {
            return is_array($m['vCards']) && count($m['vCards']) > 1 ? 'vcard_array' : 'vcard';
        }
        return 'text';
    }

    /**
     * Body text of a message with no media: the text, the names on the
     * contact cards, or the position of a location. Null when there is none.
     *
     * @param array<string,mixed> $m
     */
    private static function body(array $m, string $kind, string $text): ?string
    {
        return match ($kind) {
            'text' => $text,
            'vcard', 'vcard_array' => self::cardNames((array)$m['vCards']),
            'location' => is_array($m['location']) ? self::position($m['location']) : null,
            default => null,
        };
    }

    /**
     * The display names on contact cards, from the FN line of each card.
     *
     * @param array<array-key,mixed> $cards
     */
    private static function cardNames(array $cards): ?string
    {
        $names = [];
        foreach ($cards as $card) {
            // A parameter can follow the property name: FN;CHARSET=UTF-8:name.
            if (is_string($card) && preg_match('/^FN(?:;[^:\r\n]*)?:(.+)$/mi', $card, $hit) === 1 && trim($hit[1]) !== '') {
                $names[] = trim($hit[1]);
            }
        }
        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * Place name and coordinates of a location, as 'name (lat, lng)'.
     *
     * @param array<array-key,mixed> $loc
     */
    private static function position(array $loc): ?string
    {
        $lat = $loc['latitude'] ?? null;
        $lng = $loc['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $place = array_filter(
            [$loc['name'] ?? null, $loc['address'] ?? null],
            static fn ($p) => is_string($p) && trim($p) !== '',
        );
        return ($place === [] ? '' : implode(', ', $place) . ' ') . '(' . $lat . ', ' . $lng . ')';
    }

    /**
     * Neutral message row or null to skip.
     *
     * @param array<string,mixed> $m
     * @param callable(string):?string $lidToPhone '<id>@lid' to '<digits>@c.us' or null
     * @return array<string,mixed>|null
     */
    public static function message(array $m, bool $isGroup, callable $lidToPhone): ?array
    {
        $keyId = self::keyId((string)($m['id'] ?? ''));
        if ($keyId === null) {
            return null;
        }
        $fromMe = !empty($m['fromMe']);
        $sender = null;
        if (!$fromMe) {
            $raw = $isGroup
                ? ($m['participant'] ?? $m['author'] ?? $m['from'] ?? null)
                : ($m['from'] ?? null);
            if (is_string($raw) && str_ends_with($raw, '@lid')) {
                $raw = $lidToPhone($raw) ?? $raw;
            }
            $sender = self::jid(is_string($raw) ? $raw : null);
        }
        $kind = self::kind($m);
        $text = is_string($m['body'] ?? null) ? $m['body'] : '';
        $isMedia = in_array($kind, ['sticker', 'image', 'video', 'audio', 'document'], true);
        $media = null;
        if ($isMedia) {
            $media = [
                'file_hash' => null,
                'mime' => self::mime($m),
                'size' => null,
                'file_name' => $m['media']['filename'] ?? null,
                'caption' => $text !== '' ? $text : null,
                'src_path' => null,
                'suspicious' => null,
            ];
        }
        return [
            'platform_msg_id' => $keyId,
            'sent_at' => (int)round(((float)($m['timestamp'] ?? 0)) * 1000),
            'from_me' => $fromMe,
            'sender' => $sender,
            'kind' => $kind,
            'body' => $isMedia ? null : self::body($m, $kind, $text),
            'media' => $media,
            'call' => null,
        ];
    }

    /**
     * The media mimetype, or null when WAHA did not send one.
     *
     * @param array<string,mixed> $m
     */
    private static function mime(array $m): ?string
    {
        $mime = $m['media']['mimetype'] ?? null;
        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /** Safe file name '<keyId>.<ext>' for a synced media file. */
    public static function mediaName(string $keyId, ?string $mime, ?string $fileName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]/', '', $keyId);
        $mime = $mime === null ? '' : strtolower(trim(explode(';', $mime)[0]));
        return $safe . '.' . (self::MIME_EXT[$mime] ?? 'bin');
    }
}
