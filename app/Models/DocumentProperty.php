<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The link row between a Document and a Property (`document_properties`).
 *
 * Declared as the pivot class of Document::properties() and Property::documents() so that EVERY way
 * of filing a document onto a property (attach / sync / syncWithoutDetaching, whichever controller
 * or service does it) saves through a model, and DocumentPropertyObserver can raise
 * DocumentFiledToProperty at the one place all those paths share. Behaviour of the relations is
 * otherwise unchanged. Spec: road-to-sold.md step 6.
 */
class DocumentProperty extends Pivot
{
    protected $table = 'document_properties';

    public $incrementing = true;

    public $timestamps = true;

    protected $guarded = [];
}
