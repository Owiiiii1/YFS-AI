<?php

namespace App\Support;

class OrderCatalogDefaults
{
    /**
     * Occasion names from live orders plus common bakery events.
     *
     * @return list<string>
     */
    public static function occasions(): array
    {
        return [
            'Birthday',
            "Children's birthday",
            'First birthday',
            'Graduation',
            'Baptism',
            'Wedding',
            'Anniversary',
            'Baby shower',
            'Gender reveal',
            'Bridal shower',
            'Corporate event',
            'Party',
            'Photoshoot',
            'Centerpieces',
        ];
    }

    /**
     * Allergy / dietary options from live orders, dialogs, and bakery policy.
     *
     * @return list<string>
     */
    public static function allergies(): array
    {
        return [
            'None',
            'Peanuts',
            'Tree nuts',
            'Pistachio',
            'Cashew',
            'Hazelnut',
            'Walnut',
            'Dairy / lactose',
            'Gluten',
            'Eggs',
            'Halal',
        ];
    }
}
