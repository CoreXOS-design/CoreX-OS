<?php

/*
|--------------------------------------------------------------------------
| Property Spaces & Features Catalog
|--------------------------------------------------------------------------
|
| Single source of truth for the available property "space" types
| (Bedroom, Bathroom, Kitchen, etc.) and the feature options that can
| be assigned to each. Mirrors the JS constants in
| resources/views/corex/properties/show.blade.php.
|
| Used by:
|   - Web UI (Blade — currently duplicated in JS, to be DRY'd later)
|   - Mobile API (App\Http\Controllers\Api\MobilePropertyController)
|
*/

return [

    // Spaces that support half-units (e.g. ½ bathroom = toilet only)
    'half_unit_spaces' => ['Bathroom', 'Parking'],

    // Every space type the user can add (drop-down list)
    'all_space_types' => [
        'Bedroom', 'Bathroom', 'Garage', 'Parking', 'Kitchen', 'Garden',
        'Pool', 'Flatlet', 'Study', 'Domestic Room', 'Lounge', 'Dining Room',
        'Outside Toilet', 'Domestic Bathroom', 'Entrance Hall', 'Bar',
        'Boardroom', 'Boat Launch', 'Boathouse', 'Braai Room', 'Cellar',
        'Changing Room', 'Clubhouse', 'Courtyard', 'Gazebo', 'Greenhouse',
        'Gym', 'Jacuzzi', 'Jetty', 'Lapa', 'Laundry Room', 'Linen Room',
        'Loft', 'Office', 'Patio', 'Pool Shed', 'Reception Room', 'Sauna',
        'Scullery', 'Shed', 'Squash Court', 'Stable', 'Storeroom', 'Studio',
        'Tennis Court', 'TV Room', 'Veranda', 'Wendy House', 'Workshop', 'Yard',
    ],

    // Default feature groups used for any space type not explicitly listed
    'default_space_features' => [
        'Door'   => ['Sliding Doors'],
        'Floor'  => ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
        'Wall'   => ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
        'Window' => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
    ],

    // Per-space-type feature groups
    'space_features' => [
        'Bedroom' => [
            'General' => ['Air Conditioned','Balcony','Built-in Cupboards','Fan','Fireplace','Fridge','TV Port','Walk in Closet'],
            'Bed'     => ['Double Bed','King Bed','Queen Bed','Single Bed','Twin Bed'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
            'Layout'  => ['Open Plan'],
            'Wall'    => ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Bathroom' => [
            'General' => ['Basin','Bath','Bidet','Built-in Cupboards','Communal','Double Basin','En-suite','Full Bathroom','Guest Toilet','Half Bathroom','Jacuzzi Bath','Main en-suite','Separate Toilet','Shower','Toilet','Urinal','Commercial','Executive','In Unit','Unisex'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
            'Wall'    => ['Brick Wall','Concrete Wall','Glass Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Garage' => [
            'General' => ['Built-in Cupboards','Dishwasher','Dishwasher Connection','Double Garage','Garbage Disposal','Single Garage','Tandem Garage','Tumble Dryer','Washing Machine','Washing Machine Connection','Zinc'],
            'Door'    => ['Automated Garage Doors','Rollup Door','Tipup Door'],
            'Floor'   => ['Tiled Floors'],
        ],
        'Parking' => [
            'General' => ['Carport','Secure Parking','Shade Net Covered Parking','Street Parking','Underground Parking','Visitors Parking'],
            'Layout'  => ['Double Parking','Single Parking','Tandem Parking','Triple Parking'],
        ],
        'Pool' => [
            'General' => ['Auto Cleaning Equipment','Chlorinator','Fenced','Heated','Safety Net','Water Feature'],
            'Type'    => ['Communal Pool','Fibreglass in Ground','Indoor Pool','Portapool','Rock Pool','Splash Pool'],
        ],
        'Garden' => [
            'General' => ['Communal','Garden Services','Garden Terrace','Irrigation','Landscaped','Lighting','Sprinklers','Water Feature','Zen Garden'],
            'Wall'    => ['Brick Wall','Concrete Wall','Stone Wall'],
        ],
        'Kitchen' => [
            'General' => ['Air Conditioned','Basin','Breakfast Nook','Built-in Cupboards','Coffee Machine','Dishwasher','Dishwasher Connection','Double Basin','Extractor Fan','Eye Level Oven','Fan','Fireplace','Fridge','Garbage Disposal','Gas Hob','Gas Oven','Granite Tops','Grill','Hob','Icemaker','Oven and Hob','Pantry','Sink','Tumble Dryer','Under Counter Oven','Washing Machine','Washing Machine Connection','Water Cooler','Zinc'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
            'Layout'  => ['Open Plan'],
            'Wall'    => ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Dining Room' => [
            'General' => ['Air Conditioned','Built-In Braai','Built-in Cupboards','Fan','Fireplace'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
            'Layout'  => ['Open Plan'],
            'Wall'    => ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Lounge' => [
            'General' => ['Air Conditioned','Balcony','Built-In Braai','Built-in Cupboards','Fan','Fireplace','TV Port'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Underfloor Heating','Vinyl Floors','Wooden Floors'],
            'Layout'  => ['Open Plan'],
            'Wall'    => ['Brick Wall','Concrete Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Cottage Windows','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Study' => [
            'General' => ['Air Conditioned','Built-in Cupboards','Fan','Fridge','TV Port'],
            'Door'    => ['Sliding Doors'],
            'Floor'   => ['Carpet','Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
            'Wall'    => ['Brick Wall','Concrete Wall','Glass Wall','Plaster Wall','Wood Wall'],
            'Window'  => ['Aluminium Windows','Bay Windows','Blinds','Curtain Rails','Double Glazed Windows','Lead Windows','Picture Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
        'Laundry Room' => [
            'General' => ['Basin','Built-in Cupboards','Double Basin','Tumble Dryer','Washing Machine','Washing Machine Connection','Zinc'],
            'Floor'   => ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
            'Window'  => ['Cottage Windows'],
        ],
        'Patio' => [
            'General' => ['Built-In Braai','Covered','Pizza Oven'],
            'Floor'   => ['Laminated Floors','Parquet Floors','Tiled Floors','Vinyl Floors','Wooden Floors'],
            'Window'  => ['Aluminium Windows','Bay Windows','Cottage Windows','Double Glazed Windows','Lead Windows','Sash Windows','Skylight Window','Stained Windows','Steel Windows','Wood Windows'],
        ],
    ],

    // Global property feature groups (not tied to a specific space)
    'feature_categories' => [
        'theProperty' => [
            'label'    => 'The Property',
            'features' => ['Air Conditioned','Balcony','Cleaning Service','Freehold','Furnished','Green Building','Ground Floor Unit','Investment','Leasehold','Multi Tenanted','Natural Light','Pet Friendly','Pets Not Allowed','Renovation Fixer-Upper','Second Floor and Above','Sectional Title','Serviced','Single Storey','Standalone','Top Floor','Unfurnished','Wheelchair Friendly'],
        ],
        'security' => [
            'label'    => 'Security',
            'features' => ['24 Hour Access','24 Hour Guard','Alarm System','Armed Response','Boomed Area','Burglar Bars','CCTV','Electric Fence','Electric Gate','Gated Community','Guard House','In Security','Indoor Beams','Intercom','Outdoor Beams','Partially Fenced','Perimeter Wall','Safe','Security Gate','Totally Fenced','Totally Walled','Security Complex','Automated Garage Doors','Security Estate'],
        ],
        'connectivity' => [
            'label'    => 'Connectivity',
            'features' => ['ADSL','Cable TV','Fast Internet','Fibre','Internet Port','Satellite Dish','Satellite Internet','Telephone Port','TV Port','Wi-Fi'],
        ],
        'sustainability' => [
            'label'    => 'Sustainability',
            'features' => ['Backup Battery','Backup Water','Borehole','Gas Geyser','Gas Hob','Gas Oven','Generator','Inverter','Septic Tank','Solar Geyser','Solar Heating','Solar Panel','Water Tank'],
        ],
    ],
];
