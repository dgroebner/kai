<?php

namespace Kai\Tools\Assistant;

use Kai\Tools\Car\VehicleDashboardRepository;
use Kai\Tools\Einkaufsliste\ProductMasterRepository;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;
use Kai\Tools\PVCharge\PvDashboardService;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Weather\WeatherEvaluator;
use Kai\Tools\Weather\WeatherService;
use Throwable;

/**
 * Service und Orchestrator für Sprachassistenten-Befehle (Home Assistant / Google Assistant).
 *
 * Verarbeitet deterministische Aktionen (PV, Auto, Einkaufsliste, Wetter) sowie freie
 * Spracheingaben und erzeugt strukturierte Nutzdaten und natürlich klingende Sprechtexte.
 */
class AssistantService
{
    private PvDashboardService $pvService;
    private VehicleDashboardRepository $vehicleRepo;
    private ShoppingListRepository $shoppingListRepo;
    private ProductMasterRepository $productRepo;
    private WeatherService $weatherService;
    private WeatherEvaluator $weatherEvaluator;
    private ActivityLogger $activityLogger;
    private Logger $logger;

    public function __construct(
        ?PvDashboardService $pvService = null,
        ?VehicleDashboardRepository $vehicleRepo = null,
        ?ShoppingListRepository $shoppingListRepo = null,
        ?ProductMasterRepository $productRepo = null,
        ?WeatherService $weatherService = null,
        ?WeatherEvaluator $weatherEvaluator = null,
        ?ActivityLogger $activityLogger = null,
        ?Logger $logger = null
    ) {
        $this->pvService = $pvService ?? new PvDashboardService();
        $this->vehicleRepo = $vehicleRepo ?? new VehicleDashboardRepository();
        $this->shoppingListRepo = $shoppingListRepo ?? new ShoppingListRepository();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->weatherService = $weatherService ?? new WeatherService();
        $this->weatherEvaluator = $weatherEvaluator ?? new WeatherEvaluator();
        $this->activityLogger = $activityLogger ?? new ActivityLogger(Database::getInstance());
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Zentraler Dispatcher für eingehende Anfragen.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, action: string, speech: string, data: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        $action = strtolower(trim((string)($payload['action'] ?? '')));

        // Falls keine explizite Aktion, aber Freitext übergeben wurde:
        if ($action === '' && (!empty($payload['query']) || !empty($payload['text']))) {
            $action = 'voice_command';
        }

        return match ($action) {
            'get_pv_status', 'pv_status', 'solar_status', 'pv' => $this->getPvStatus(),
            'get_car_status', 'car_status', 'auto_status', 'car' => $this->getCarStatus(),
            'get_shopping_list', 'shopping_list', 'einkaufsliste' => $this->getShoppingList(
                isset($payload['market']) ? (string)$payload['market'] : null
            ),
            'add_shopping_item', 'add_item', 'einkaufsliste_add' => $this->addShoppingItem(
                (string)($payload['name'] ?? $payload['item'] ?? ''),
                isset($payload['market']) ? (string)$payload['market'] : null,
                max(0.01, (float)($payload['quantity'] ?? 1.0)),
                isset($payload['unit']) ? (string)$payload['unit'] : null
            ),
            'get_weather_status', 'weather_status', 'wetter' => $this->getWeatherStatus(),
            'get_summary', 'summary', 'uebersicht', 'status' => $this->getSummary(),
            'get_intro', 'intro', 'hallo', 'wer_bist_du' => $this->getIntro(),
            'voice_command', 'query', 'command' => $this->parseVoiceCommand(
                (string)($payload['text'] ?? $payload['query'] ?? $payload['command'] ?? '')
            ),
            default => [
                'success' => false,
                'action' => $action !== '' ? $action : 'unknown',
                'speech' => 'Unbekannte Aktion. Unterstützt werden PV, Auto, Einkaufsliste, Wetter und Zusammenfassung.',
                'data' => [],
            ],
        };
    }

    /**
     * Liefert den Status der Photovoltaikanlage und des Speichers.
     */
    public function getPvStatus(): array
    {
        try {
            $data = $this->pvService->getDashboardData();
            $live = $data['live'] ?? [];
            $kpis = $data['kpis'] ?? [];

            if (empty($live)) {
                return [
                    'success' => true,
                    'action' => 'get_pv_status',
                    'speech' => 'Es liegen aktuell keine Live-Daten der Photovoltaikanlage vor.',
                    'data' => [],
                ];
            }

            $pvW = (int)($live['pv_power_w'] ?? 0);
            $pvText = ($pvW >= 1000)
                ? number_format($pvW / 1000, 1, ',', '.') . ' Kilowatt'
                : $pvW . ' Watt';

            $soc = (int)($live['battery_soc_pct'] ?? 0);
            $batW = (int)($live['battery_power_w'] ?? 0);

            if ($batW > 20) {
                $batText = 'und wird mit ' . $batW . ' Watt geladen';
            } elseif ($batW < -20) {
                $batText = 'und speist mit ' . abs($batW) . ' Watt aus';
            } else {
                $batText = 'und ist im Ruhezustand';
            }

            $yield = (float)($kpis['yieldDailyKwh'] ?? ($live['yield_daily_kwh'] ?? 0));
            $yieldText = number_format($yield, 1, ',', '.') . ' Kilowattstunden';

            $speech = "Die Photovoltaikanlage erzeugt aktuell {$pvText}. "
                    . "Der Batteriespeicher ist zu {$soc} Prozent geladen {$batText}. "
                    . "Heute wurden bisher {$yieldText} Strom erzeugt.";

            return [
                'success' => true,
                'action' => 'get_pv_status',
                'speech' => $speech,
                'data' => [
                    'pv_power_w' => $pvW,
                    'battery_soc_pct' => $soc,
                    'battery_power_w' => $batW,
                    'grid_power_w' => (float)($live['grid_total_w'] ?? 0),
                    'house_load_w' => (float)($live['house_load_w'] ?? 0),
                    'yield_daily_kwh' => $yield,
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('AssistantService: Fehler bei getPvStatus.', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'action' => 'get_pv_status',
                'speech' => 'Fehler beim Abrufen der Photovoltaik-Daten.',
                'data' => [],
            ];
        }
    }

    /**
     * Liefert den aktuellen Status des Elektrofahrzeugs (VW ID.Buzz).
     */
    public function getCarStatus(?string $customName = null): array
    {
        try {
            $state = $this->vehicleRepo->getLatestState();

            if ($state === null) {
                return [
                    'success' => true,
                    'action' => 'get_car_status',
                    'speech' => 'Es liegen aktuell keine Fahrzeugdaten vor.',
                    'data' => [],
                ];
            }

            $soc = (int)($state['soc_percent'] ?? 0);
            $range = (int)($state['range_km'] ?? 0);
            $targetSoc = (int)($state['target_soc'] ?? 80);
            $chargeKw = (float)($state['charge_power_kw'] ?? 0);
            $chargingState = strtolower((string)($state['charging_state'] ?? ''));
            $plug = (bool)($state['plug_connected'] ?? false);

            $carName = !empty($customName) ? $customName : 'Das Auto';
            $speech = "{$carName} hat aktuell {$soc} Prozent Ladestand und eine Reichweite von {$range} Kilometern.";

            if ($chargeKw > 0.1 || $chargingState === 'charging') {
                $chargeKwText = number_format($chargeKw, 1, ',', '.');
                $speech .= " Es lädt derzeit mit {$chargeKwText} Kilowatt auf das Ladeziel von {$targetSoc} Prozent.";
            } elseif ($plug) {
                $speech .= " Das Ladekabel ist verbunden, aber es wird aktuell nicht geladen.";
            } else {
                $speech .= " Das Fahrzeug ist nicht an der Wallbox angeschlossen.";
            }

            return [
                'success' => true,
                'action' => 'get_car_status',
                'speech' => $speech,
                'data' => $state,
            ];
        } catch (Throwable $e) {
            $this->logger->error('AssistantService: Fehler bei getCarStatus.', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'action' => 'get_car_status',
                'speech' => 'Fehler beim Abrufen der Fahrzeugdaten.',
                'data' => [],
            ];
        }
    }

    /**
     * Liefert offene Einträge der Einkaufsliste.
     */
    public function getShoppingList(?string $market = null): array
    {
        try {
            $marketFilter = ($market !== null && $market !== '' && $market !== 'all') ? $market : null;
            $items = $this->shoppingListRepo->getItems($marketFilter, false);

            $count = count($items);
            $marketSuffix = $marketFilter !== null ? " für {$marketFilter}" : '';

            if ($count === 0) {
                $speech = "Die Einkaufsliste{$marketSuffix} ist aktuell leer.";
            } elseif ($count === 1) {
                $speech = "Auf der Einkaufsliste{$marketSuffix} steht 1 Artikel: " . $items[0]['name'] . ".";
            } elseif ($count <= 5) {
                $names = array_column($items, 'name');
                $last = array_pop($names);
                $speech = "Auf der Einkaufsliste{$marketSuffix} stehen {$count} Artikel: "
                        . implode(', ', $names) . ' und ' . $last . '.';
            } else {
                $names = array_column(array_slice($items, 0, 4), 'name');
                $remaining = $count - 4;
                $speech = "Auf der Einkaufsliste{$marketSuffix} stehen {$count} Artikel, darunter "
                        . implode(', ', $names) . " und {$remaining} weitere.";
            }

            return [
                'success' => true,
                'action' => 'get_shopping_list',
                'speech' => $speech,
                'data' => [
                    'count' => $count,
                    'market' => $marketFilter ?? 'all',
                    'items' => array_map(static fn($i): array => [
                        'id' => (int)$i['id'],
                        'name' => $i['name'],
                        'quantity' => (float)$i['quantity'],
                        'unit' => $i['unit'],
                        'market' => $i['market'],
                        'category' => $i['category'],
                    ], $items),
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('AssistantService: Fehler bei getShoppingList.', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'action' => 'get_shopping_list',
                'speech' => 'Fehler beim Abrufen der Einkaufsliste.',
                'data' => [],
            ];
        }
    }

    /**
     * Fügt einen Artikel zur Einkaufsliste hinzu und ermittelt intelligent Markt & Kategorie.
     */
    public function addShoppingItem(
        string $name,
        ?string $market = null,
        float $quantity = 1.0,
        ?string $unit = null
    ): array {
        $cleanName = trim($name);
        if ($cleanName === '') {
            return [
                'success' => false,
                'action' => 'add_shopping_item',
                'speech' => 'Bitte nenne einen Artikel, der zur Einkaufsliste hinzugefügt werden soll.',
                'data' => [],
            ];
        }

        try {
            // Artikelstamm nach bekanntem Artikel oder Synonym durchsuchen
            $master = $this->productRepo->findByLabelOrName($cleanName);
            $productId = null;
            $effectiveName = $cleanName;
            $effectiveMarket = ($market !== null && $market !== '') ? $market : 'Rewe';
            $effectiveCategory = 'Sonstiges';

            if ($master !== null) {
                $productId = (int)$master['id'];
                if (!empty($master['custom_label'])) {
                    $effectiveName = $master['custom_label'];
                }
                if ($market === null || $market === '') {
                    $effectiveMarket = !empty($master['preferred_market']) ? $master['preferred_market'] : 'Rewe';
                }
                if (!empty($master['default_category'])) {
                    $effectiveCategory = $master['default_category'];
                }
            } else {
                // Neu im Artikelstamm vormerken
                $productId = $this->productRepo->saveOrUpdate([
                    'name' => $cleanName,
                    'preferred_market' => $effectiveMarket,
                    'default_category' => $effectiveCategory,
                ]);
            }

            $itemId = $this->shoppingListRepo->addItem([
                'product_id' => $productId,
                'name' => $effectiveName,
                'quantity' => $quantity > 0 ? $quantity : 1.0,
                'unit' => $unit ?: 'Stück',
                'market' => $effectiveMarket,
                'category' => $effectiveCategory,
                'is_spontaneous' => 0,
                'source' => 'voice_assistant',
            ]);

            // Ereignis im Activity-Log festhalten
            $this->activityLogger->log(
                'shopping_item_added',
                "Sprachbefehl: {$effectiveName} zur Einkaufsliste hinzugefügt ({$effectiveMarket})",
                '/einkaufsliste/',
                $itemId
            );

            $speech = "{$effectiveName} wurde zur Einkaufsliste für {$effectiveMarket} hinzugefügt.";

            return [
                'success' => true,
                'action' => 'add_shopping_item',
                'speech' => $speech,
                'data' => [
                    'id' => $itemId,
                    'name' => $effectiveName,
                    'market' => $effectiveMarket,
                    'category' => $effectiveCategory,
                    'quantity' => $quantity,
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('AssistantService: Fehler bei addShoppingItem.', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'action' => 'add_shopping_item',
                'speech' => 'Konnte den Artikel nicht zur Einkaufsliste hinzufügen.',
                'data' => [],
            ];
        }
    }

    /**
     * Liefert den aktuellen Wetterstatus inklusive Kleidungsempfehlung.
     */
    public function getWeatherStatus(): array
    {
        try {
            $forecast = $this->weatherService->getForecastFromDb();
            if (empty($forecast) || empty($forecast['current'])) {
                return [
                    'success' => true,
                    'action' => 'get_weather_status',
                    'speech' => 'Es liegen aktuell keine Wetterdaten vor.',
                    'data' => [],
                ];
            }

            $evaluation = $this->weatherEvaluator->evaluate($forecast);
            $temp = round((float)($forecast['current']['temperature_2m'] ?? 0), 1);
            $jacketText = $evaluation['jacket']['text'] ?? '';
            $umbrellaText = $evaluation['umbrella']['text'] ?? '';

            $tempFormatted = number_format($temp, 1, ',', '.');
            $speech = "Aktuell sind es {$tempFormatted} Grad. {$jacketText} {$umbrellaText}";

            return [
                'success' => true,
                'action' => 'get_weather_status',
                'speech' => trim($speech),
                'data' => [
                    'temperature_c' => $temp,
                    'evaluation' => $evaluation,
                    'current' => $forecast['current'],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('AssistantService: Fehler bei getWeatherStatus.', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'action' => 'get_weather_status',
                'speech' => 'Fehler beim Abrufen der Wetterdaten.',
                'data' => [],
            ];
        }
    }

    /**
     * Erzeugt eine kurze Gesamtzusammenfassung (PV, Auto, Einkaufsliste, Wetter).
     */
    public function getSummary(): array
    {
        $pv = $this->getPvStatus();
        $car = $this->getCarStatus();
        $shopping = $this->getShoppingList();
        $weather = $this->getWeatherStatus();

        $parts = [];

        // PV Kurzinformation
        if (!empty($pv['data']['pv_power_w']) || isset($pv['data']['battery_soc_pct'])) {
            $pvKw = number_format(($pv['data']['pv_power_w'] ?? 0) / 1000, 1, ',', '.');
            $soc = $pv['data']['battery_soc_pct'] ?? 0;
            $parts[] = "Die PV-Anlage liefert {$pvKw} Kilowatt bei {$soc} Prozent Hausspeicher.";
        }

        // Auto Kurzinformation
        if (!empty($car['data']['soc_percent'])) {
            $carSoc = $car['data']['soc_percent'];
            $range = $car['data']['range_km'] ?? 0;
            $parts[] = "Das Auto hat {$carSoc} Prozent Akku und {$range} Kilometer Reichweite.";
        }

        // Einkaufsliste Kurzinformation
        $shoppingCount = (int)($shopping['data']['count'] ?? 0);
        if ($shoppingCount > 0) {
            $parts[] = "Auf der Einkaufsliste stehen {$shoppingCount} Artikel.";
        } else {
            $parts[] = "Die Einkaufsliste ist leer.";
        }

        // Wetter Kurzinformation
        if (isset($weather['data']['temperature_c'])) {
            $temp = number_format($weather['data']['temperature_c'], 1, ',', '.');
            $parts[] = "Draußen sind es {$temp} Grad.";
        }

        $speech = !empty($parts)
            ? 'Hier ist deine Kai-Zusammenfassung: ' . implode(' ', $parts)
            : 'Es liegen derzeit keine Statusdaten im Kai Toolset vor.';

        return [
            'success' => true,
            'action' => 'get_summary',
            'speech' => $speech,
            'data' => [
                'pv' => $pv['data'],
                'car' => $car['data'],
                'shopping' => $shopping['data'],
                'weather' => $weather['data'],
            ],
        ];
    }

    /**
     * Stellt Kai persönlich vor und begrüßt die Familie.
     */
    public function getIntro(): array
    {
        $speech = "Hallo! Ich bin Kai, euer persönlicher Assistent für das ganze Haus. "
                . "Ich passe auf Buzzy auf, überwache den Solarstrom auf dem Dach und merke mir eure Einkäufe. "
                . "Fragt mich einfach nach Buzzy, der Photovoltaikanlage, dem Wetter oder der Einkaufsliste!";

        return [
            'success' => true,
            'action' => 'get_intro',
            'speech' => $speech,
            'data' => [
                'name' => 'Kai',
                'role' => 'Haushalts- und Energieassistent',
            ],
        ];
    }

    /**
     * Analysiert einen Freitext-Sprachbefehl und führt die passende Aktion aus.
     */
    public function parseVoiceCommand(string $query): array
    {
        $text = trim($query);
        if ($text === '') {
            return [
                'success' => false,
                'action' => 'voice_command',
                'speech' => 'Ich habe keinen Sprachbefehl verstanden.',
                'data' => [],
            ];
        }

        // 0. Vorstellung / Begrüßung
        if (preg_match('/(?:wer bist du|stell dich vor|hallo kai|hi kai|sprich mit kai|über dich|ueber dich)/iu', $text)) {
            return $this->getIntro();
        }

        // 1. Regex-Muster: Artikel auf die Einkaufsliste setzen
        if (preg_match('/(?:setze|pack|packe|schreib|schreibe|tue|füge|fuege)\s+(.+?)\s+(?:auf|zu|in)\s+(?:die\s+)?(?:einkaufsliste|liste)/iu', $text, $matches)
            || preg_match('/(?:auf\s+die\s+(?:einkaufs|liste)\s+(?:setzen|packen|schreiben)):\s*(.+)/iu', $text, $matches)) {
            $rawItem = trim($matches[1] ?? $matches[2] ?? '');
            return $this->processAddVoiceItem($rawItem);
        }

        // 2. Regex-Muster: Einkaufsliste abfragen
        if (preg_match('/einkaufsliste/iu', $text)) {
            $market = null;
            if (preg_match('/rewe/iu', $text)) {
                $market = 'Rewe';
            } elseif (preg_match('/globus/iu', $text)) {
                $market = 'Globus';
            }
            return $this->getShoppingList($market);
        }

        // 3. Regex-Muster: PV / Solar
        if (preg_match('/(?:photovoltaik|solar|\bpv\b|hausspeicher|solaranlage|stromerzeugung|einspeisung)/iu', $text)) {
            return $this->getPvStatus();
        }

        // 4. Regex-Muster: Auto / Car / ID.Buzz / Buzzy
        if (preg_match('/(?:auto|\bcar\b|id\.buzz|idbuzz|\bbuzz\b|\bbuzzy\b|wagen|fahrzeug|reichweite|ladestand|wallbox)/iu', $text)) {
            $carName = preg_match('/buzzy/iu', $text) ? 'Buzzy' : (preg_match('/\bbuzz\b/iu', $text) ? 'Der Buzz' : null);
            return $this->getCarStatus($carName);
        }

        // 5. Regex-Muster: Wetter / Jacke / Regenschirm
        if (preg_match('/(?:wetter|temperatur|regen|schirm|jacke|sonne|grad)/iu', $text)) {
            return $this->getWeatherStatus();
        }

        // 6. Regex-Muster: Zusammenfassung / Überblick / Status
        if (preg_match('/(?:zusammenfassung|übersicht|uebersicht|überblick|ueberblick|status|briefing|guten morgen)/iu', $text)) {
            return $this->getSummary();
        }

        // 7. Fallback über Gemini KI (falls Key vorhanden)
        $geminiResult = $this->tryGeminiIntentRecognition($text);
        if ($geminiResult !== null) {
            return $this->handle($geminiResult);
        }

        return [
            'success' => false,
            'action' => 'voice_command',
            'speech' => 'Entschuldigung, diesen Befehl konnte ich keinem Bereich im Kai Toolset zuordnen. '
                      . 'Du kannst nach Photovoltaik, Auto, Wetter oder der Einkaufsliste fragen.',
            'data' => ['query' => $text],
        ];
    }

    /**
     * Bereinigt gesprochene Artikelangaben und extrahiert ggf. Mengen.
     */
    private function processAddVoiceItem(string $raw): array
    {
        $item = preg_replace('/^(?:ein|eine|einen|einem|etwas|mal)\s+/iu', '', trim($raw));
        $quantity = 1.0;
        $unit = null;
        $market = null;

        // Optional Marktangabe am Ende erkennen: "Milch bei Rewe"
        if (preg_match('/(.+?)\s+(?:bei|im|von)\s+(rewe|globus)/iu', $item, $m)) {
            $item = trim($m[1]);
            $market = ucfirst(strtolower($m[2]));
        }

        // Optional Zahl am Anfang: "2 Flaschen Milch" oder "3 Tomaten"
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(kg|kilo|gramm|g|liter|l|flaschen?|packungen?|st(?:ü|ue)ck)?\s+(.+)$/iu', $item, $m)) {
            $quantity = (float)str_replace(',', '.', $m[1]);
            if (!empty($m[2])) {
                $unit = trim($m[2]);
            }
            $item = trim($m[3]);
        }

        return $this->addShoppingItem($item, $market, $quantity, $unit);
    }

    /**
     * Versucht über Gemini eine Intent-Klassifikation, falls der RegEx-Parser nicht griff.
     *
     * @return array<string, mixed>|null
     */
    private function tryGeminiIntentRecognition(string $query): ?array
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return null;
        }

        try {
            $client = new GeminiClient();
            $systemInstruction = "Du bist der Intent-Parser für das Kai Toolset Smart Home. "
                . "Erkenne die beabsichtigte Aktion aus der gesprochenen Nutzereingabe. "
                . "Erlaubte Aktionen: get_pv_status, get_car_status, get_shopping_list, add_shopping_item, get_weather_status, get_summary. "
                . "Gib ausschließlich valides JSON mit 'action' und optionalen Parametern wie 'name', 'market', 'quantity' zurück.";

            $schema = [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => [
                            'get_pv_status',
                            'get_car_status',
                            'get_shopping_list',
                            'add_shopping_item',
                            'get_weather_status',
                            'get_summary',
                            'unknown',
                        ],
                    ],
                    'name' => ['type' => 'string'],
                    'market' => ['type' => 'string'],
                    'quantity' => ['type' => 'number'],
                ],
                'required' => ['action'],
            ];

            $result = $client->generate(
                prompt: "Nutzereingabe: \"$query\"",
                jsonMode: true,
                responseSchema: $schema,
                systemInstruction: $systemInstruction
            );

            if (is_array($result) && !empty($result['action']) && $result['action'] !== 'unknown') {
                return $result;
            }
        } catch (Throwable $e) {
            $this->logger->warn('AssistantService: Gemini Intent-Erkennung übersprungen.', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
