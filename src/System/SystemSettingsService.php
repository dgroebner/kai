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

    public function getHomeLatitude(): float
    {
        return (float)$this->repository->get('home_latitude', 51.2956492);
    }

    public function getHomeLongitude(): float
    {
        return (float)$this->repository->get('home_longitude', 12.4541829);
    }

    public function getHomeGeofenceRadius(): int
    {
        return (int)$this->repository->get('home_geofence_radius_m', 200);
    }

    public function getHomeAddress(): string
    {
        return (string)$this->repository->get('home_address', 'Zuhause');
    }

    public function getTripCalendarAllowedSenders(): string
    {
        return (string)$this->repository->get('trip_calendar_allowed_senders', '');
    }

    public function isTripCalendarAutoAcceptEnabled(): bool
    {
        return (bool)$this->repository->get('trip_calendar_auto_accept', 1);
    }

    public function getStoveType(): string
    {
        return (string)$this->repository->get('stove_type', 'Ceranfeld');
    }

    public function getStoveLevels(): int
    {
        return max(1, min(20, (int)$this->repository->get('stove_levels', 12)));
    }

    public function getOvenDefaultMode(): string
    {
        return (string)$this->repository->get('oven_default_mode', 'Umluft');
    }

    public function isRecipePreferShallotsEnabled(): bool
    {
        return (bool)$this->repository->get('recipe_prefer_shallots', 1);
    }

    public function getRecipeDefaultAdults(): int
    {
        return max(1, min(20, (int)$this->repository->get('recipe_default_adults', 2)));
    }

    public function getRecipeDefaultKids(): int
    {
        return max(0, min(20, (int)$this->repository->get('recipe_default_kids', 2)));
    }

    public function getRecipeKidPortionPct(): int
    {
        return max(30, min(100, (int)$this->repository->get('recipe_kid_portion_pct', 80)));
    }

    public function getRecipeHouseholdPreferences(): string
    {
        return trim((string)$this->repository->get('recipe_household_preferences', ''));
    }

    public function getRecipeAvailableTools(): string
    {
        return trim((string)$this->repository->get('recipe_available_tools', 'Schneidmühle mit Seilzug'));
    }

    /**
     * Speichert alle Parameter des Küchenprofils und der Haushalts-Präferenzen.
     *
     * @param array<string, mixed> $data
     */
    public function saveKitchenProfile(array $data): void
    {
        $allowedStoveTypes = ['Ceranfeld', 'Induktion', 'Gasherd', 'Massekochfeld'];
        $stoveType = trim((string)($data['stove_type'] ?? 'Ceranfeld'));
        if (!in_array($stoveType, $allowedStoveTypes, true)) {
            $stoveType = 'Ceranfeld';
        }

        $stoveLevels = max(3, min(18, (int)($data['stove_levels'] ?? 12)));

        $allowedOvenModes = ['Umluft', 'Ober-/Unterhitze', 'Heißluft'];
        $ovenMode = trim((string)($data['oven_default_mode'] ?? 'Umluft'));
        if (!in_array($ovenMode, $allowedOvenModes, true)) {
            $ovenMode = 'Umluft';
        }

        $preferShallots = !empty($data['recipe_prefer_shallots']) ? '1' : '0';
        $defaultAdults = max(1, min(20, (int)($data['recipe_default_adults'] ?? 2)));
        $defaultKids = max(0, min(20, (int)($data['recipe_default_kids'] ?? 2)));
        $kidPortionPct = max(30, min(100, (int)($data['recipe_kid_portion_pct'] ?? 80)));
        $householdPrefs = mb_substr(trim((string)($data['recipe_household_preferences'] ?? '')), 0, 1000);
        $availableTools = mb_substr(trim((string)($data['recipe_available_tools'] ?? '')), 0, 1000);

        $this->repository->set('stove_type', $stoveType, 'Herd-Typ (z. B. Ceranfeld, Induktion, Gas)');
        $this->repository->set('stove_levels', (string)$stoveLevels, 'Anzahl Herdstufen (Skala z. B. 9 oder 12)');
        $this->repository->set('oven_default_mode', $ovenMode, 'Bevorzugte Backofen-Beheizungsart');
        $this->repository->set('recipe_prefer_shallots', $preferShallots, 'Rezepte: Schalotten statt Zwiebeln bevorzugen (1/0)');
        $this->repository->set('recipe_default_adults', (string)$defaultAdults, 'Rezepte: Standard-Anzahl Erwachsene');
        $this->repository->set('recipe_default_kids', (string)$defaultKids, 'Rezepte: Standard-Anzahl Kinder/Jugendliche');
        $this->repository->set('recipe_kid_portion_pct', (string)$kidPortionPct, 'Rezepte: Portionsfaktor Kinder in Prozent (z. B. 80)');
        $this->repository->set('recipe_household_preferences', $householdPrefs, 'Rezepte: Weitere Haushalts-Vorlieben & No-Gos');
        $this->repository->set('recipe_available_tools', $availableTools, 'Rezepte: Vorhandene Küchenwerkzeuge & Helfer');
    }
}
