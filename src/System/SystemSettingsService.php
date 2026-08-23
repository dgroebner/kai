<?php

namespace Kai\Tools\System;

class SystemSettingsService
{
    private SystemSettingsRepository $repository;

    public function __construct(?SystemSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new SystemSettingsRepository();
    }

    public function getGridImportPrice(): float
    {
        return (float)$this->repository->get('grid_import_price_kwh', 0.2689);
    }

    public function getGridExportPrice(): float
    {
        return (float)$this->repository->get('grid_export_price_kwh', 0.06);
    }

    public function setSetting(string $key, string $value, ?string $label = null): void
    {
        $this->repository->set($key, $value, $label);
    }
}