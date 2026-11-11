<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

class SchnauzerCard
{
    public function __construct(
        public readonly string $id,
        public readonly string $suit,     // clubs, spades, hearts, diamonds
        public readonly string $rank,     // 7, 8, 9, 10, J, Q, K, A
        public readonly int $value,       // 7..10, J=10, Q=10, K=10, A=11
        public readonly string $label = ''
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'suit' => $this->suit,
            'rank' => $this->rank,
            'value' => $this->value,
            'label' => $this->label,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)($data['id'] ?? ''),
            suit: (string)($data['suit'] ?? ''),
            rank: (string)($data['rank'] ?? ''),
            value: (int)($data['value'] ?? 0),
            label: (string)($data['label'] ?? '')
        );
    }
}
