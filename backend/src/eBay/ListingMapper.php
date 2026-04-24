<?php

declare(strict_types=1);

namespace App\eBay;

class ListingMapper
{
    /**
     * Map an eBay Inventory API inventory_item response to our internal Listing model.
     */
    public function mapInventoryItem(array $item, string $itemId): array
    {
        $product      = $item['product'] ?? [];
        $condition    = $item['condition'] ?? '';
        $availability = $item['availability']['shipToLocationAvailability'] ?? [];

        return [
            'id'                  => $itemId,
            'ebay_item_id'        => $itemId,
            'title'               => $product['title'] ?? '',
            'sku'                 => $item['sku'] ?? $itemId,
            'status'              => 'ACTIVE',
            'category'            => [
                'ebay_category_id' => '',
                'name'             => '',
            ],
            'price'               => ['value' => '0.00', 'currency' => 'GBP'],
            'quantity'            => [
                'available' => (int)($availability['quantity'] ?? 0),
                'sold'      => 0,
            ],
            'images'              => array_values($product['imageUrls'] ?? []),
            'condition'           => $condition,
            'description_snippet' => substr($product['description'] ?? '', 0, 300),
            'listing_url'         => '',
            'listed_at'           => date('c'),
            'ends_at'             => '',
            'synced_at'           => date('c'),
        ];
    }

    /**
     * Map a Trading API GetMyeBaySelling item to our internal Listing model.
     */
    public function mapTradingApiItem(array $item): array
    {
        $description    = $item['description'] ?? '';
        $images         = !empty($item['pictureUrls']) ? $item['pictureUrls'] : [];
        $rawSpecifics   = $item['itemSpecifics'] ?? [];

        // Convert item specifics map → [{name,value}] array used by our edit UI
        $itemSpecifics = [];
        foreach ($rawSpecifics as $name => $value) {
            if ($name !== '') {
                $itemSpecifics[] = ['name' => $name, 'value' => $value];
            }
        }

        return [
            'id'                  => $item['itemId'],
            'ebay_item_id'        => $item['itemId'],
            'title'               => $item['title'],
            'sku'                 => $item['sku'],
            'status'              => strtoupper($item['listingStatus']) === 'ACTIVE' ? 'ACTIVE' : strtoupper($item['listingStatus']),
            'category'            => [
                'ebay_category_id' => $item['categoryId'] ?? '',
                'name'             => $item['categoryName'] ?? '',
            ],
            'price'               => $item['price'],
            'quantity'            => [
                'available' => $item['quantity'],
                'sold'      => $item['quantitySold'],
            ],
            'images'              => $images,
            'condition'           => $item['condition'] ?? '',
            'description'         => $description,
            'description_snippet' => mb_substr(strip_tags($description), 0, 300),
            'item_specifics'      => $itemSpecifics,
            'listing_url'         => $item['viewItemUrl'],
            'listed_at'           => $item['startTime'] ?: date('c'),
            'ends_at'             => $item['endTime'] ?? '',
            'synced_at'           => date('c'),
        ];
    }

    /**
     * Map an eBay Browse/Trading API item summary to our internal Listing model.
     * Used when syncing via the Sell Listing API or active listings feed.
     */
    public function mapItemSummary(array $item): array
    {
        $itemId = $item['itemId'] ?? $item['legacyItemId'] ?? '';
        $price  = $item['price'] ?? $item['currentPrice'] ?? [];

        return [
            'id'                  => $itemId,
            'ebay_item_id'        => $itemId,
            'title'               => $item['title'] ?? '',
            'sku'                 => $item['sku'] ?? '',
            'status'              => $this->mapListingStatus($item),
            'category'            => [
                'ebay_category_id' => $item['categoryId'] ?? $item['primaryItemGroup']['itemGroupId'] ?? '',
                'name'             => $item['categoryPath'] ?? '',
            ],
            'price'               => [
                'value'    => $price['value'] ?? '0.00',
                'currency' => $price['currency'] ?? 'GBP',
            ],
            'quantity'            => [
                'available' => (int)($item['estimatedAvailabilities'][0]['estimatedAvailableQuantity'] ?? 0),
                'sold'      => (int)($item['estimatedAvailabilities'][0]['estimatedSoldQuantity'] ?? 0),
            ],
            'images'              => array_map(
                fn($img) => $img['imageUrl'] ?? '',
                !empty($item['image'])
                    ? [$item['image']]
                    : ($item['additionalImages'] ?? [])
            ),
            'condition'           => $item['condition'] ?? '',
            'description_snippet' => substr(
                strip_tags($item['shortDescription'] ?? $item['description'] ?? ''),
                0,
                300
            ),
            'listing_url'         => $item['itemWebUrl'] ?? '',
            'listed_at'           => $item['itemCreationDate'] ?? date('c'),
            'ends_at'             => $item['itemEndDate'] ?? '',
            'synced_at'           => date('c'),
        ];
    }

    private function mapListingStatus(array $item): string
    {
        if (!empty($item['buyingOptions']) && in_array('FIXED_PRICE', $item['buyingOptions'])) {
            return 'ACTIVE';
        }
        return 'ACTIVE';
    }
}
