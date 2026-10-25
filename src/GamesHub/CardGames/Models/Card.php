<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Models;

class Card
{
    public function __construct(
        public readonly string $id,
        public readonly string $color,     // red, yellow, green, blue, wild, hearts, diamonds, spades, clubs
        public readonly string $type,      // number, skip, reverse, draw2, wild, wild_draw4, hit2, extreme_hit, discard_all, jack, ace
        public readonly ?int $value,       // 0-9 oder Skatwert
        public readonly string $label,     // Anzeige-Label z.B. "König ♥"
        public readonly int $scoreValue = 0,
        public readonly string $rank = '',  // "K", "D", "B", "A", "7" etc.
        public readonly string $suit = ''   // "♥", "♦", "♠", "♣"
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'color' => $this->color,
            'type' => $this->type,
            'value' => $this->value,
            'label' => $this->label,
            'score' => $this->scoreValue,
            'rank' => $this->rank,
            'suit' => $this->suit,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)($data['id'] ?? ''),
            color: (string)($data['color'] ?? 'wild'),
            type: (string)($data['type'] ?? 'number'),
            value: isset($data['value']) && $data['value'] !== null ? (int)$data['value'] : null,
            label: (string)($data['label'] ?? ''),
            scoreValue: (int)($data['score'] ?? 0),
            rank: (string)($data['rank'] ?? ''),
            suit: (string)($data['suit'] ?? '')
        );
    }
}
