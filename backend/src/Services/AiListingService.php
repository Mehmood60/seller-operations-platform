<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AiListingService
{
    private Client $http;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = $_ENV['GROQ_API_KEY'] ?? '';
        $this->model  = $_ENV['GROQ_MODEL'] ?? 'llama-3.1-8b-instant';
        $this->http   = new Client(['timeout' => 60]);
    }

    public function analyze(array $scrapedData): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException(
                'GROQ_API_KEY is not configured. Get a free key at https://console.groq.com'
            );
        }

        $prompt  = $this->buildPrompt($scrapedData);
        $rawText = $this->callGroq($prompt);
        return $this->parseResponse($rawText);
    }

    public function suggestSpecifics(string $title, string $description, array $currentSpecifics, array $missingFields): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $currentJson = json_encode($currentSpecifics, JSON_UNESCAPED_UNICODE);
        $fieldsJson  = json_encode($missingFields, JSON_UNESCAPED_UNICODE);
        $cleanDesc   = mb_substr(strip_tags($description), 0, 800);
        $example     = '{"Abteilung":"Damen","Größe":"M","Stil":"N/A"}';

        $prompt = "You are an eBay product specialist. Look at this listing and suggest values for the missing item specifics.\n\n"
            . "Product Title: {$title}\n"
            . "Product Description: {$cleanDesc}\n"
            . "Current Item Specifics: {$currentJson}\n\n"
            . "Missing fields: {$fieldsJson}\n\n"
            . "Rules:\n"
            . "- Look in title and description for each missing field value\n"
            . "- If found, use that value; if not determinable, use \"N/A\"\n"
            . "- Return ONLY a valid JSON object, no explanation, no markdown\n"
            . "- Example format: {$example}";

        $raw  = $this->callGroq($prompt);
        $text = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);

        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? '{}';
        }

        $data   = json_decode($text, true);
        $result = [];
        foreach ($missingFields as $field) {
            $result[$field] = (is_array($data) && !empty($data[$field])) ? (string) $data[$field] : 'N/A';
        }

        return $result;
    }

    public function feedbackResponse(string $feedbackText, string $type, string $tone): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $typeLabel = match ($type) {
            'negative_feedback' => 'Negative eBay-Bewertung',
            'buyer_message'     => 'Käufernachricht',
            'return_request'    => 'Rückgabeanfrage',
            'not_received'      => 'Artikel nicht erhalten (INR)',
            default             => 'Käufernachricht',
        };

        $toneLabel = match ($tone) {
            'apologetic'   => 'Entschuldigend und empathisch',
            'refund'       => 'Rückerstattung anbieten',
            'replacement'  => 'Ersatz anbieten',
            'explanation'  => 'Sachlich erklären (kein Fehler unsererseits)',
            'firm'         => 'Freundlich aber bestimmt',
            default        => 'Professionell und lösungsorientiert',
        };

        $cleanText = mb_substr(strip_tags($feedbackText), 0, 1000);

        $prompt = <<<PROMPT
Du bist ein professioneller eBay-Verkäufer in Deutschland. Schreibe eine professionelle Antwort auf folgende Käufernachricht oder Bewertung.

Art der Nachricht: {$typeLabel}
Gewünschter Ton: {$toneLabel}

Originalnachricht des Käufers:
"{$cleanText}"

Regeln:
- Antworte IMMER auf Deutsch
- Sei höflich, professionell und lösungsorientiert
- Beginne mit einer kurzen Begrüßung (z.B. "Sehr geehrte/r Käufer/in,")
- Adressiere das Problem direkt
- Biete eine konkrete Lösung entsprechend dem Ton
- Ende mit einer Einladung zur weiteren Kontaktaufnahme und einer Grußformel
- Halte die Antwort kurz (3-6 Sätze)
- Nenne keine konkreten Namen oder Bestellnummern (du kennst sie nicht)

Gib NUR dieses JSON zurück, kein Markdown:
{"response": "deine Antwort hier"}
PROMPT;

        $raw  = $this->callGroq($prompt, 512);
        $text = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);

        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? '{}';
        }

        $data = json_decode($text, true);
        return (string)($data['response'] ?? $text);
    }

    public function suggestPrice(
        string $title,
        float $currentPrice,
        string $shippingType,
        float $shippingCost,
        ?float $competitorLowest,
        int $competitorCount,
        array $topCompetitors
    ): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $currentTotal  = $currentPrice + ($shippingType === 'paid' ? $shippingCost : 0.0);
        $competitorJson = json_encode(array_slice($topCompetitors, 0, 5), JSON_UNESCAPED_UNICODE);
        $lowestStr      = $competitorLowest !== null ? number_format($competitorLowest, 2) : 'unknown';
        $shippingStr    = $shippingType === 'free' ? 'free' : ('€' . number_format($shippingCost, 2));

        $prompt = "You are an eBay pricing analyst. Analyze the competitive landscape and recommend an optimal listing price.\n\n"
            . "=== YOUR LISTING ===\n"
            . "Title: {$title}\n"
            . "Current price: €" . number_format($currentPrice, 2) . "\n"
            . "Shipping: {$shippingStr}\n"
            . "Total buyer cost: €" . number_format($currentTotal, 2) . "\n\n"
            . "=== MARKET DATA ===\n"
            . "Competitors found: {$competitorCount}\n"
            . "Lowest competitor total: €{$lowestStr}\n"
            . "Top competitors (title / total price / condition / location):\n{$competitorJson}\n\n"
            . "=== TASK ===\n"
            . "Recommend an optimal ITEM price (excluding shipping). Rules:\n"
            . "- 0-2 competitors: premium pricing is possible, suggest a slight increase\n"
            . "- 3-8 competitors: stay competitive — 3-8% below the lowest total\n"
            . "- 9+ competitors: undercut more aggressively — 8-15% below lowest total\n"
            . "- Ignore clearly non-comparable results (used/damaged at very low prices)\n"
            . "- Minimum margin: ensure item price > €0.50\n"
            . "- strategy values: 'undercut', 'match', 'premium', 'no_change'\n\n"
            . "Return ONLY this JSON, no markdown:\n"
            . "{\"suggested_price\": 24.99, \"strategy\": \"undercut\", \"reasoning\": \"Short English explanation\"}";

        $raw  = $this->callGroq($prompt, 512);
        $text = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);
        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? '{}';
        }

        $data = json_decode($text, true);
        return [
            'suggested_price' => round((float)($data['suggested_price'] ?? $currentPrice), 2),
            'strategy'        => (string)($data['strategy'] ?? 'no_change'),
            'reasoning'       => (string)($data['reasoning'] ?? 'No change recommended.'),
        ];
    }

    public function improveTitle(string $title, string $description): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $cleanDesc = mb_substr(strip_tags($description), 0, 600);
        $prompt = "Du bist ein professioneller eBay.de-Verkäufer. Verbessere diesen Titel für ein eBay-Inserat.\n\n"
            . "Aktueller Titel: {$title}\n"
            . "Produktbeschreibung: {$cleanDesc}\n\n"
            . "Regeln:\n"
            . "- STRIKT max. 80 Zeichen — niemals überschreiten, auch nicht um 1 Zeichen!\n"
            . "- Auf Deutsch schreiben\n"
            . "- SEO-optimiert: Hochvolumen-Suchbegriffe zuerst (Produktname + Typ + wichtigste Eigenschaft)\n"
            . "- Kein GROSSSCHRIFT, keine Sonderzeichen (!, @, #, *)\n"
            . "- Gib NUR dieses JSON zurück, kein Markdown: {\"title\": \"verbesserter Titel hier\"}";

        $raw  = $this->callGroq($prompt, 256);
        $text = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);
        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? '{}';
        }
        $data     = json_decode($text, true);
        $improved = (string)($data['title'] ?? $title);
        if (mb_strlen($improved) > 80) {
            $improved = mb_substr($improved, 0, 80);
        }
        return $improved;
    }

    public function improveDescription(string $title, string $description): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $cleanDesc = mb_substr(strip_tags($description), 0, 800);
        $prompt = "Du bist ein professioneller eBay.de-Verkäufer. Schreibe eine professionelle Produktbeschreibung für ein eBay-Inserat.\n\n"
            . "Produkttitel: {$title}\n"
            . "Aktuelle Beschreibung: {$cleanDesc}\n\n"
            . "Regeln:\n"
            . "- Schreibe auf Deutsch\n"
            . "- Professionelles HTML mit <ul><li>-Aufzählungen\n"
            . "- Hauptmerkmale, Lieferumfang, Kompatibilität auflisten\n"
            . "- 150-250 Wörter\n"
            . "- Mit kurzem Versandhinweis enden\n"
            . "- Gib NUR dieses JSON zurück, kein Markdown: {\"description\": \"HTML-Beschreibung hier\"}";

        $raw  = $this->callGroq($prompt, 1024);
        $text = preg_replace('/^```(?:json)?\s*/m', '', $raw) ?? $raw;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);
        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? '{}';
        }
        $data = json_decode($text, true);
        return (string)($data['description'] ?? $description);
    }

    public function translate(string $title, string $description): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured.');
        }

        $prompt = <<<PROMPT
Translate the following eBay listing from German to English.
Return ONLY valid JSON, no markdown, no explanation.

Title: {$title}

Description (HTML): {$description}

Return exactly:
{"title": "translated title here", "description": "translated HTML description here"}
PROMPT;

        $raw  = $this->callGroq($prompt);
        $data = json_decode(trim($raw), true);

        return [
            'title'       => $data['title']       ?? $title,
            'description' => $data['description'] ?? $description,
        ];
    }

    private function buildPrompt(array $d): string
    {
        $origin = match ($d['origin'] ?? 'UNKNOWN') {
            'CN'    => 'China (AliExpress / dropshipping supplier)',
            'DE'    => 'Germany (local stock, fast delivery)',
            default => 'Unknown — assume China/dropshipping to be safe',
        };

        $supplierPrice = !empty($d['price']['value'])
            ? $d['price']['value'] . ' ' . ($d['price']['currency'] ?? 'EUR')
            : 'not detected';

        $imageLines = implode("\n", array_slice($d['images'] ?? [], 0, 6));

        return <<<PROMPT
Du bist ein professioneller eBay.de-Verkäufer. Analysiere die Produktdaten unten und erstelle ein optimiertes deutsches eBay-Inserat.

=== PRODUKTDATEN ===
URL: {$d['url']}
Rohtitel: {$d['title']}
Rohbeschreibung: {$d['description']}
Lieferantenpreis: {$supplierPrice}
Versandherkunft: {$origin}
Gefundene Bilder:
{$imageLines}
Seitentext-Auszug:
{$d['text_snippet']}

=== REGELN ===
SPRACHE: Schreibe Titel, Beschreibung und Schlüsselwörter auf DEUTSCH.
1. TITEL: STRIKT max. 80 Zeichen — niemals überschreiten, auch nicht um 1 Zeichen! SEO-optimiert für eBay-Suche: Hochvolumen-Suchbegriffe zuerst (Produktname + Typ + wichtigste Eigenschaft + Zielgruppe/Material/Farbe). Keine Füllwörter. Kein GROSSSCHRIFT, keine Sonderzeichen (!, @, #, *). Beispiel: "Nike Laufschuhe Herren Schwarz Atmungsaktiv Gr. 42 Sport Running"
2. ZUSTAND: "Neu" für AliExpress/Dropshipping. "Gebraucht - Wie neu" nur wenn Seite explizit "gebraucht" sagt.
3. BESCHREIBUNG: Professionelles HTML mit <ul><li>-Aufzählungen auf Deutsch. Hauptmerkmale, Lieferumfang, Kompatibilität. 150-250 Wörter. Mit kurzem Versandhinweis enden.
4. PREIS (EUR):
   - China: Lieferantenpreis mind. 2,8x multiplizieren. Ohne Preis: nach Produkttyp schätzen.
   - Deutschland: 1,6x multiplizieren.
   - Als String ausgeben z.B. "24.99".
5. VERSAND:
   - Deutschland: type=free, cost=0.00, service=Standardversand (eBay), Bearbeitung 1-2 Tage, Lieferung 1-2 Tage.
   - China: type=paid, cost=3.99, service=AliExpress Standardversand, Bearbeitung 5-7 Tage, Lieferung 15-25 Tage.
   - Unbekannt: China-Preset verwenden.
6. KATEGORIE: Bester eBay.de-Kategoriepfad, z.B. "Haustierbedarf > Hunde > Zubehör".
7. SCHLÜSSELWÖRTER: 6-10 deutsche Suchbegriffe die Käufer eingeben.
8. ARTIKELMERKMALE: Extrahiere ALLE passenden eBay-Artikelmerkmale aus den Produktdaten.
   IMMER angeben (für alle Produkttypen):
   - Marke: Produktmarke wenn erkennbar, sonst "Ohne Markenzeichen"
   - Farbe: Hauptfarbe auf Deutsch (z.B. "Schwarz", "Blau", "Beige")
   - Material: Hauptmaterial (z.B. "Polyester", "Baumwolle", "Kunststoff", "Aluminium")
   - Produktart: PFLICHT für alle Typen — z.B. "Rucksack", "Tasche", "Koffer", "Top", "Hose", "Lampe", "Kabel", "Halter", "Box", "Matte"

   Für Kleidung/Mode ZUSÄTZLICH:
   - Abteilung: "Damen", "Herren", "Kinder" oder "Unisex"
   - Größe: z.B. "M", "L", "XL", "One Size"
   - Stil: z.B. "Casual", "Elegant", "Sportlich", "Basic"
   - Ärmellänge: PFLICHT — "Ärmellos" für Westen/Tops/Träger, "Kurzarm" für T-Shirts, "Langarm" für Pullover, "3/4-Arm" usw.

   Für Taschen/Koffer/Behälter/Möbel/Geräte ZUSÄTZLICH (wenn in Produktdaten vorhanden):
   - Breite: Maß mit Einheit, z.B. "30 cm", "12 inch" — aus Produktspezifikationen entnehmen
   - Höhe: Maß mit Einheit, z.B. "45 cm"
   - Länge: Maß mit Einheit, z.B. "20 cm"
   - Fassungsvermögen: z.B. "20 L", "5 kg" — wenn angegeben

   Für Elektronik ZUSÄTZLICH: Modell, Kompatibilität, Anschlusstyp.
   Für Haustiere ZUSÄTZLICH: Tierart, Gewichtsklasse.

Gib NUR dieses JSON zurück, keine Erklärung, kein Markdown:
{
  "title": "string",
  "condition": "Neu",
  "description": "<ul><li>...</li></ul><p>...</p>",
  "price": { "value": "24.99", "currency": "EUR" },
  "shipping_origin": "CN",
  "shipping": {
    "type": "paid",
    "cost": "3.99",
    "service": "AliExpress Standardversand",
    "processing_days_min": 5,
    "processing_days_max": 7,
    "delivery_days_min": 15,
    "delivery_days_max": 25
  },
  "category_suggestion": "string",
  "keywords": ["string"],
  "item_specifics": {
    "Marke": "Ohne Markenzeichen",
    "Produktart": "Rucksack",
    "Farbe": "Schwarz",
    "Material": "Polyester",
    "Breite": "30 cm",
    "Höhe": "45 cm",
    "Länge": "15 cm"
  }
}
PROMPT;
    }

    private function callGroq(string $prompt, int $maxTokens = 2048): string
    {
        try {
            $response = $this->http->post('https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $this->model,
                    'temperature' => 0.3,
                    'max_tokens'  => $maxTokens,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a professional eBay seller assistant. Respond with valid JSON only — no markdown, no explanation, no code fences.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            return $body['choices'][0]['message']['content'] ?? '';
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Groq API error: ' . $e->getMessage());
        }
    }

    private function parseResponse(string $text): array
    {
        // Strip markdown fences if model added them despite instructions
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;
        $text = trim($text);

        // Extract first JSON object if surrounded by extra text
        if (!str_starts_with($text, '{')) {
            preg_match('/\{[\s\S]+\}/', $text, $m);
            $text = $m[0] ?? $text;
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AI returned invalid JSON. Raw: ' . mb_substr($text, 0, 300));
        }

        if (!empty($data['title']) && mb_strlen($data['title']) > 80) {
            $data['title'] = mb_substr($data['title'], 0, 80);
        }

        $origin = $data['shipping_origin'] ?? 'UNKNOWN';
        $data['condition']           ??= 'New';
        $data['shipping_origin']       = $origin;
        $data['price']               ??= ['value' => '', 'currency' => 'EUR'];
        $data['shipping']            ??= $this->defaultShipping($origin);
        $data['keywords']            ??= [];
        $data['category_suggestion'] ??= '';
        $data['description']         ??= '';
        if (empty($data['item_specifics']) || !is_array($data['item_specifics'])) {
            $data['item_specifics'] = ['Marke' => 'Ohne Markenzeichen'];
        }
        // Ensure Marke is always present
        if (empty($data['item_specifics']['Marke'])) {
            $data['item_specifics']['Marke'] = 'Ohne Markenzeichen';
        }

        return $data;
    }

    private function defaultShipping(string $origin): array
    {
        if ($origin === 'DE') {
            return [
                'type'                => 'free',
                'cost'                => '0.00',
                'service'             => 'Standardversand (eBay)',
                'processing_days_min' => 1,
                'processing_days_max' => 2,
                'delivery_days_min'   => 1,
                'delivery_days_max'   => 2,
            ];
        }

        return [
            'type'                => 'paid',
            'cost'                => '3.99',
            'service'             => 'AliExpress Standardversand',
            'processing_days_min' => 5,
            'processing_days_max' => 7,
            'delivery_days_min'   => 15,
            'delivery_days_max'   => 25,
        ];
    }
}
