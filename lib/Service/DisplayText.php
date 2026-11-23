<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

/**
 * Cleans text on its way out to a caller. Stored values do not change; this
 * is the one place that cleans a name before it is shown.
 * arch: D4.1, D4.2, D4.3, D4.4
 */
final class DisplayText {
	/**
	 * Control characters and bidi-override marks that can hide or reorder a
	 * name (arch: D4.1). Keeps U+200C (ZWNJ) and U+200D (ZWJ): they join
	 * letters and emoji and carry no override. Keeps language tag characters.
	 */
	public const STRIP = '/[\p{Cc}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u';

	/**
	 * Strip the marks of self::STRIP from a name shown to a user. mb_scrub
	 * first makes the string valid UTF-8, so the /u pattern cannot fail on
	 * backup bytes. Null in, null out.
	 */
	public static function name(?string $s): ?string {
		if ($s === null) {
			return null;
		}
		return preg_replace(self::STRIP, '', mb_scrub($s, 'UTF-8')) ?? '';
	}

	/**
	 * True when the declared mime and the real mime name different kinds of
	 * file — the spoof that matters (CVE-2025-30401 class): a file shown as
	 * one kind, opened as another. Both mimes are lower-cased and their
	 * parameters dropped, then only the top-level type is compared, so alias
	 * noise inside one kind does not trigger (audio/ogg vs
	 * audio/ogg;codecs=opus; application/zip vs
	 * application/x-zip-compressed). A real mime of application/octet-stream
	 * (the file cache could not tell) never triggers. arch: D4.3
	 */
	public static function mimeMismatch(?string $declared, ?string $real): bool {
		if ($declared === null || $real === null) {
			return false;
		}
		$declaredBase = self::mimeBase($declared);
		$realBase = self::mimeBase($real);
		if ($declaredBase === '' || $realBase === '') {
			return false;
		}
		if ($realBase === 'application/octet-stream') {
			return false;
		}
		return self::topType($declared) !== self::topType($real);
	}

	/** Lower-cased mime with parameters (";...") dropped. */
	private static function mimeBase(string $mime): string {
		return strtolower(trim(explode(';', $mime, 2)[0]));
	}

	/** The part of a mime before the '/', lower-cased, parameters dropped. */
	private static function topType(string $mime): string {
		return explode('/', self::mimeBase($mime), 2)[0];
	}
}
