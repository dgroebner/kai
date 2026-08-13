<?php
namespace Kai\Tools\Bank\Parser;

class BankCsvParser
{
    /**
     * Liest die hochgeladene CSV-Datei ein und konvertiert sie in ein Array.
     *
     * @param string $filePath
     * @return array
     */
    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Konvertierung von ISO-8859-1 auf UTF-8
        $contentUtf8 = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        $lines = explode("\n", $contentUtf8);
        
        $transactions = [];
        $headerFound = false;

        foreach ($lines as $line) {
            $data = str_getcsv($line, ';');

            // Header-Zeile suchen ("Buchungstag")
            if (!$headerFound) {
                if (isset($data[0]) && str_contains($data[0], 'Buchungstag')) {
                    $headerFound = true;
                }
                continue;
            }

            if (count($data) < 5 || empty(trim($data[0]))) {
                continue;
            }

            $bookingDate = date('Y-m-d', strtotime(trim($data[0])));
            $valutaDate  = date('Y-m-d', strtotime(trim($data[1])));
            $type        = trim($data[2]);
            $text        = trim($data[3]);

            // Betrag säubern ("-84,24" -> -84.24)
            $amountClean = str_replace(['.', ','], ['', '.'], trim($data[4]));
            $amount      = (float)$amountClean;

            // Deterministischer SHA-256 Hash als Zeilen-Identifikator gegen Dubletten
            $rawPayload  = implode('|', [$bookingDate, $valutaDate, $type, $text, $amount]);
            $txHash      = hash('sha256', $rawPayload);

            $transactions[] = [
                'tx_hash'      => $txHash,
                'booking_date' => $bookingDate,
                'valuta_date'  => $valutaDate,
                'type'         => $type,
                'raw_text'     => $text,
                'amount'       => $amount,
            ];
        }

        return $transactions;
    }
}