<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\SkipBo;

/**
 * Repräsentiert eine einzelne Skip-Bo Spielkarte.
 */
class SkipBoCard
{
    public const TYPE_NUMBER = 'number';
    public const TYPE_SKIPBO = 'skipbo';

    private string $id;
    private string $type;
    private int $value; // 1..12 für Zahlenkarten, 0 für Skip-Bo Wildcard
    private string $colorGroup; // 'blue' (1-4), 'green' (5-8), 'orange' (9-12), 'wild' (Skip-Bo)

    public function __construct(string $id, string $type, int $value, string $colorGroup)
    {
        $this->id = $id;
        $this->type = $type;
        $this->value = $value;
        $this->colorGroup = $colorGroup;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getColorGroup(): string
    {
        return $this->colorGroup;
    }

    public function isSkipBo(): bool
    {
        return $this->type === self::TYPE_SKIPBO;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'color' => $this->colorGroup,
            'label' => $this->isSkipBo() ? 'SKIP-BO' : (string)$this->value,
        ];
    }
}
