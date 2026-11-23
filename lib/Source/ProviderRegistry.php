<?php

declare(strict_types=1);

namespace OCA\ChatArchive\Source;

use OCA\ChatArchive\Source\WhatsApp\WhatsAppProvider;
use Psr\Container\ContainerInterface;

/** Known providers by id. Add a class here when a new platform lands. */
class ProviderRegistry {
	private const PROVIDERS = [
		WhatsAppProvider::class,
	];

	/** @var array<string, IProvider> */
	private array $byId = [];

	public function __construct(ContainerInterface $container) {
		foreach (self::PROVIDERS as $class) {
			/** @var IProvider $p */
			$p = $container->get($class);
			$this->byId[$p->id()] = $p;
		}
	}

	/** @throws \InvalidArgumentException */
	public function get(string $id): IProvider {
		if (!isset($this->byId[$id])) {
			throw new \InvalidArgumentException("unknown provider: $id");
		}
		return $this->byId[$id];
	}

	/** @return array<int, array{id:string, name:string, needsSecret:bool}> */
	public function describe(): array {
		return array_values(array_map(static fn (IProvider $p) => [
			'id' => $p->id(), 'name' => $p->name(), 'needsSecret' => $p->needsSecret(),
		], $this->byId));
	}
}
