<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Contact::query();

        if ($search = $request->input('q')) {
            // AT-131 canonical (all identifiers + relevance + newest-first tiebreak).
            $query->search($search);
        } else {
            $query->latest('id');
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(Contact $contact): JsonResponse
    {
        return response()->json($contact);
    }
}
