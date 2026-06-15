<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export of agency contacts.
 *
 * The column layout is the round-trip twin of {@see ContactImportController}:
 * a file produced here can be re-imported and every backed field lands back
 * where it came from. The "Agents" column carries the owning agent's name so
 * the importer's resolveAgent() re-assigns each contact to that agent
 * (created_by_user_id) on re-import.
 *
 * Columns with no native CoreX field — Category, Phone (secondary), Wish Lists,
 * SMS, Opt-In — are emitted as empty cells by design (see contacts spec §
 * Import/Export). Matches/Emails/WhatsApp carry real counts.
 *
 * Scope is ALWAYS enforced from the caller's contacts data-scope — an
 * 'own'-scope agent exporting "all" still only gets their own contacts.
 */
class ContactExportController extends Controller
{
    /** Header row — exact order/labels of the agreed export format. */
    private const HEADERS = [
        'Category', 'Name', 'Surname', 'Email', 'Cell', 'Phone', 'Type',
        '*ID Number', 'BirthDay', 'Tags', 'Source', 'Address', 'Wish Lists',
        'Matches', 'SMS', 'Emails', 'WhatsApp', 'Opt-In', 'Agents',
        'Loaded', 'Modified', 'Last Contacted', 'Additional Info',
    ];

    /** 1-indexed columns whose values must stay textual (no numeric coercion). */
    private const TEXT_COLUMNS = [5, 6, 8]; // Cell, Phone, *ID Number

    public function export(Request $request): StreamedResponse
    {
        $user = auth()->user();

        $query = $this->buildQuery($request, $user)
            ->with(['type', 'source', 'tags', 'createdBy'])
            ->withCount('matches');

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');

        // Header row
        foreach (self::HEADERS as $i => $label) {
            $sheet->setCellValue([$i + 1, 1], $label);
        }
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        // Data rows — lazy() keeps memory flat on large agencies.
        $rowNum = 2;
        foreach ($query->lazy(500) as $contact) {
            foreach ($this->rowFor($contact) as $i => $value) {
                $col = $i + 1;
                if (in_array($col, self::TEXT_COLUMNS, true) && $value !== '' && $value !== null) {
                    $sheet->setCellValueExplicit([$col, $rowNum], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$col, $rowNum], $value);
                }
            }
            $rowNum++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'contacts-' . now()->format('Y-m-d') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0, no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * One spreadsheet row, in HEADERS order. Blank strings for columns with
     * no native field (Category, Phone, Wish Lists, SMS, Opt-In).
     */
    private function rowFor(Contact $contact): array
    {
        return [
            '',                                                        // Category
            $contact->first_name,                                      // Name
            $contact->last_name,                                       // Surname
            $contact->email,                                           // Email
            $contact->phone,                                           // Cell
            '',                                                        // Phone (no secondary field stored)
            $contact->type?->name,                                     // Type
            $contact->id_number,                                       // *ID Number
            optional($contact->birthday)->format('Y-m-d'),             // BirthDay
            $contact->tags->pluck('name')->implode(', '),              // Tags
            $contact->source?->name,                                   // Source
            $contact->address,                                         // Address
            '',                                                        // Wish Lists
            (int) ($contact->matches_count ?? 0),                      // Matches
            '',                                                        // SMS
            (int) $contact->email_count,                               // Emails
            (int) $contact->whatsapp_count,                            // WhatsApp
            '',                                                        // Opt-In
            $contact->createdBy?->name,                                // Agents
            optional($contact->loaded_at ?? $contact->created_at)->format('Y-m-d H:i'),     // Loaded
            optional($contact->modified_at ?? $contact->updated_at)->format('Y-m-d H:i'),   // Modified
            optional($contact->last_contacted_at)->format('Y-m-d H:i'),                     // Last Contacted
            $contact->notes,                                           // Additional Info
        ];
    }

    /**
     * Build the contact query. Data scope is ALWAYS enforced. When the request
     * is NOT an "export all" (`all=1`), the on-screen filters (agent_id, search,
     * type) are applied so the export mirrors exactly what the user is viewing.
     *
     * This intentionally mirrors {@see ContactController::index()} — keep the
     * two in sync.
     */
    private function buildQuery(Request $request, User $user): Builder
    {
        $dataScope    = PermissionService::getDataScope($user, 'contacts');
        $canPickAgent = in_array($dataScope, ['all', 'branch']);
        $exportAll    = $request->boolean('all');

        $query = Contact::query()->orderBy('last_name')->orderBy('first_name');

        // Resolve the effective agent filter (matches index's default-to-self).
        if ($exportAll) {
            $filterAgentId = ''; // "All" within the caller's allowed scope.
        } elseif ($request->has('agent_id')) {
            $filterAgentId = (string) $request->query('agent_id', '');
        } elseif ($canPickAgent) {
            $filterAgentId = (string) $user->id;
        } else {
            $filterAgentId = '';
        }

        if ($canPickAgent) {
            if ($filterAgentId !== '') {
                $query->where('created_by_user_id', (int) $filterAgentId);
            } elseif ($dataScope === 'branch' && $user->branch_id) {
                $query->whereHas('createdBy', fn ($q) => $q->where('branch_id', $user->branch_id));
            }
            // 'all' scope with no filter = whole agency (AgencyScope handles tenancy).
        } else {
            // 'own' scope: agents only ever export their own contacts.
            $query->where('created_by_user_id', $user->id);
        }

        if (!$exportAll) {
            if ($request->filled('search')) {
                $words = array_filter(explode(' ', trim($request->search)));
                foreach ($words as $word) {
                    $query->where(function ($q) use ($word) {
                        $q->where('first_name', 'like', "%{$word}%")
                          ->orWhere('last_name',  'like', "%{$word}%")
                          ->orWhere('phone',      'like', "%{$word}%")
                          ->orWhere('email',      'like', "%{$word}%")
                          ->orWhere('id_number',  'like', "%{$word}%");
                    });
                }
            }

            if ($request->filled('type')) {
                $typeId    = (int) $request->type;
                $esignRole = ContactType::whereKey($typeId)->value('esign_role');

                if ($esignRole === 'buyer') {
                    $query->where('is_buyer', 1);
                } elseif ($esignRole === 'seller') {
                    $query->whereHas('properties', fn ($q) => $q->where('contact_property.role', 'owner'));
                } else {
                    $query->where('contact_type_id', $typeId);
                }
            }
        }

        return $query;
    }
}
