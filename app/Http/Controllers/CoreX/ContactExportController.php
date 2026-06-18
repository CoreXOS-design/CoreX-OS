<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export of agency contacts.
 *
 * Streams the `.xlsx` with OpenSpout — a row-at-a-time writer that holds
 * constant memory regardless of how many contacts the agency has. (PhpSpreadsheet
 * builds the whole workbook as objects in memory and exhausts the 128 MB
 * `memory_limit` on a few thousand rows — the exact failure this replaces.)
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

    public function export(Request $request): StreamedResponse
    {
        $user = auth()->user();

        $query = $this->buildQuery($request, $user)
            ->with(['type', 'source', 'tags', 'createdBy'])
            ->withCount('matches');

        $filename = 'contacts-' . now()->format('Y-m-d') . '.xlsx';

        return new StreamedResponse(function () use ($query) {
            // OpenSpout streams sheet XML to a temp file as rows are added, so
            // peak memory stays flat. We write to a temp path (a non-seekable
            // php://output can corrupt the zip central directory), then stream
            // the finished file out and delete it.
            $tmp    = tempnam(sys_get_temp_dir(), 'cexp');
            $writer = new Writer();
            $writer->openToFile($tmp);

            try {
                $writer->addRow(Row::fromValues(self::HEADERS));

                foreach ($query->lazy(500) as $contact) {
                    $writer->addRow(Row::fromValues($this->rowFor($contact)));
                }
            } finally {
                $writer->close();
            }

            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0, no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * One spreadsheet row, in HEADERS order. Blank strings for columns with
     * no native field (Category, Phone, Wish Lists, SMS, Opt-In). String values
     * (phone, ID number) are written as text cells by OpenSpout, so no numeric
     * coercion / scientific-notation mangling occurs.
     */
    private function rowFor(Contact $contact): array
    {
        return [
            '',                                                        // Category
            $this->safeText($contact->first_name),                     // Name
            $this->safeText($contact->last_name),                      // Surname
            $this->safeText($contact->email),                          // Email
            $this->safeText($contact->phone),                          // Cell
            '',                                                        // Phone (no secondary field stored)
            $this->safeText($contact->type?->name ?? ''),             // Type
            $this->safeText($contact->id_number),                      // *ID Number
            optional($contact->birthday)->format('Y-m-d') ?? '',       // BirthDay
            $this->safeText($contact->tags->pluck('name')->implode(', ')), // Tags
            $this->safeText($contact->source?->name ?? ''),           // Source
            $this->safeText($contact->address),                        // Address
            '',                                                        // Wish Lists
            (int) ($contact->matches_count ?? 0),                      // Matches
            '',                                                        // SMS
            (int) $contact->email_count,                               // Emails
            (int) $contact->whatsapp_count,                            // WhatsApp
            '',                                                        // Opt-In
            $this->safeText($contact->createdBy?->name ?? ''),        // Agents
            optional($contact->loaded_at ?? $contact->created_at)->format('Y-m-d H:i') ?? '',     // Loaded
            optional($contact->modified_at ?? $contact->updated_at)->format('Y-m-d H:i') ?? '',   // Modified
            optional($contact->last_contacted_at)->format('Y-m-d H:i') ?? '',                     // Last Contacted
            $this->safeText($contact->notes),                          // Additional Info
        ];
    }

    /**
     * Neutralise spreadsheet formula injection (CSV injection).
     *
     * Contact fields are user/import supplied. A value beginning with =, +, -, @,
     * or a leading tab/CR is interpreted as a live formula when the .xlsx is
     * opened in Excel / Google Sheets (e.g. =HYPERLINK(), =cmd|'/c …'). Prefixing
     * such values with a single quote forces them to render as literal text.
     * The leading quote is stripped by the importer's text handling on re-import,
     * preserving the round-trip.
     */
    private function safeText(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
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
