<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class AgencySigningParty extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'agency_signing_parties';

    protected $fillable = [
        'agency_id',
        'name',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Scope: parties for a specific agency.
     */
    public function scopeForAgency($query, $agencyId)
    {
        return $query->where('agency_id', $agencyId);
    }

    /**
     * Seed default signing parties for an agency that has none.
     */
    public static function seedDefaultsForAgency(int $agencyId): void
    {
        $defaults = [
            ['name' => 'Lessor',  'sort_order' => 0],
            ['name' => 'Lessee',  'sort_order' => 1],
            ['name' => 'Agent',   'sort_order' => 2],
            ['name' => 'Witness', 'sort_order' => 3],
            ['name' => 'Buyer',   'sort_order' => 4],
            ['name' => 'Seller',  'sort_order' => 5],
        ];

        foreach ($defaults as $party) {
            self::create([
                'agency_id'  => $agencyId,
                'name'       => $party['name'],
                'sort_order' => $party['sort_order'],
                'is_default' => true,
            ]);
        }
    }
}
