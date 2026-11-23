<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Service;

use OCP\Contacts\IManager;

/**
 * Phone jid -> display name from the user's Nextcloud address books.
 * Works in a web request only: the DAV app registers the address books of
 * the logged-in user lazily; cron and occ have no user.
 */
class ContactNames {
	/** @var array<string, ?string> */
	private array $cache = [];

	public function __construct(private IManager $contacts) {
	}

	/**
	 * Batch lookup of multiple JIDs. Returns a map of jid -> name.
	 * Runs one contacts search for the whole request (not one per number),
	 * then matches every uncached number against that single result set.
	 * @param string[] $jids
	 * @return array<string, ?string>
	 */
	public function namesFor(array $jids): array {
		// Collect distinct valid numbers to lookup
		$distinct = [];
		foreach ($jids as $jid) {
			if ($jid === null || !str_ends_with($jid, '@s.whatsapp.net')) {
				continue;
			}
			$number = preg_replace('/\D/', '', strstr($jid, '@', true) ?: '');
			if ($number !== '' && !array_key_exists($number, $this->cache)) {
				$distinct[$number] = true;
			}
		}

		// Warm the cache for every uncached number from ONE contacts search,
		// not one search per number. array_keys() would hand back ints here:
		// a numeric string key is cast to int by PHP, so numbers are recast.
		if ($distinct !== []) {
			$found = $this->contacts->isEnabled() ? $this->contacts->search('', ['TEL']) : [];
			foreach (array_keys($distinct) as $number) {
				$number = (string)$number;
				$this->cache[$number] = $this->matchTel($number, $found);
			}
		}

		// Return the map for all requested jids
		$result = [];
		foreach ($jids as $jid) {
			$result[$jid] = $this->nameFor($jid);
		}
		return $result;
	}

	/**
	 * Walk a contacts search result and return the FN of the first contact
	 * with a TEL whose digits end with $number, or that $number ends with.
	 * Shared by nameFor() (single, 7-digit-tail lookup) and namesFor()
	 * (one lookup for every peer in the request).
	 * @param iterable<array<string,mixed>> $contacts
	 */
	private function matchTel(string $number, iterable $contacts): ?string {
		foreach ($contacts as $contact) {
			$tels = (array)($contact['TEL'] ?? []);
			foreach ($tels as $tel) {
				$digits = preg_replace('/\D/', '', (string)$tel);
				// A short TEL such as "123" must not match every number that ends in 123.
				if (strlen($digits) >= min(7, strlen($number)) && (str_ends_with($number, $digits) || str_ends_with($digits, $number))) {
					$fn = $contact['FN'] ?? null;
					return ($fn !== null && $fn !== '') ? (string)$fn : null;
				}
			}
		}
		return null;
	}

	public function nameFor(?string $jid): ?string {
		if ($jid === null || !str_ends_with($jid, '@s.whatsapp.net')) {
			return null;
		}
		$number = preg_replace('/\D/', '', strstr($jid, '@', true) ?: '');
		if ($number === '' || !$this->contacts->isEnabled()) {
			return null;
		}
		if (array_key_exists($number, $this->cache)) {
			return $this->cache[$number];
		}
		$tail = substr($number, -7);
		return $this->cache[$number] = $this->matchTel($number, $this->contacts->search($tail, ['TEL']));
	}

	/** "+23000001" for a private jid, the group id otherwise. */
	public static function fallback(string $jid): string {
		$user = strstr($jid, '@', true) ?: $jid;
		return str_ends_with($jid, '@s.whatsapp.net') ? '+' . $user : $user;
	}
}
