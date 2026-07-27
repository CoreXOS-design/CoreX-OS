<?php

declare(strict_types=1);

namespace App\Services\AI\Ellie;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Docuperfect\Clause;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\User;
use App\Services\AI\EllieReferenceSourceSearchService;
use App\Services\AI\KnowledgeSearchService;
use App\Services\AI\NavigationAtlasService;
use App\Services\AI\TourKnowledgeService;
use App\Services\Agent\AgentPerformanceService;
use App\Services\PropertyCostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ellie's tools — what she can look up, on demand, mid-conversation.
 *
 * This replaces the guess-up-front retrieval that capped Ellie at ~60% useful.
 * Previously EllieController decided what to inject from keyword heuristics
 * BEFORE the model saw the question, then made one shot at an answer. The model
 * could not expand "OTP" to "Offer to Purchase", could not retry a search with
 * better words, and could not look at a single row of live data. Agents asked
 * "whats clause 9 of the otp" five times and were told Ellie had no access — to
 * a document that was embedded and ellie-enabled the whole time.
 *
 * EVERY TOOL IS READ-ONLY. Ellie advises, humans decide (.ai/specs/ellie.md).
 * There is no write path here, by construction, and adding one is a spec change.
 *
 * SCOPING: every pillar tool routes through the model's own scopeVisibleTo(),
 * which reads PermissionService::getDataScope(). An agent sees their own records,
 * a branch manager their branch, an admin the agency — exactly what that user
 * already sees in the UI. AgencyScope (non-negotiable #7) sits underneath. Ellie
 * cannot widen a user's view by one row.
 *
 * Spec: .ai/specs/ellie-v2.md §3.
 */
class EllieToolkit
{
    /** Hard cap on rows any tool will return, whatever the model asks for. */
    private const MAX_LIMIT = 25;

    /** Hard cap on characters one knowledge search may return. */
    private const MAX_EXCERPT_CHARS = 6000;

    public function __construct(
        private readonly KnowledgeSearchService $knowledge,
        private readonly NavigationAtlasService $navigation,
        private readonly TourKnowledgeService $tours,
        private readonly AgentPerformanceService $performance,
        private readonly EllieReferenceSourceSearchService $referenceSources,
    ) {
    }

    /**
     * Anthropic tool definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'search_knowledge',
                'description' =>
                    'Search the agency knowledge base: CoreX training guides, company documents '
                    . '(Offer to Purchase, Exclusive/Open Authority to Sell, Dual Mandate, FICA forms, '
                    . 'addenda, mandatory disclosure) and South African legislation (Property Practitioners '
                    . 'Act, FICA, POPIA, CPA, Rental Housing Act, Sectional Title Act, Alienation of Land Act). '
                    . 'USE THIS for any question about what a clause says, what the law requires, or company '
                    . 'policy. Expand abbreviations before searching — "OTP" means "Offer to Purchase", '
                    . '"EATS" means "Exclusive Authority To Sell", "OATS" means "Open Authority To Sell", '
                    . '"MD" means "Mandatory Disclosure". If the first search is thin, search again with '
                    . 'different words rather than telling the user you do not know.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to look up. Use full words, not the abbreviation the user typed.'],
                        'limit' => ['type' => 'integer', 'description' => 'Number of excerpts (default 4).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'search_reference_sites',
                'description' =>
                    'Search a small, admin-approved allowlist of external web pages — NOT the open '
                    . 'internet. USE THIS ONLY as a second resort, after search_knowledge and the live '
                    . 'pillar/calculator tools have come back empty, for facts CoreX itself does not '
                    . 'track (e.g. a specific bank\'s current rate page an admin has approved). If this '
                    . 'also returns nothing, say plainly that you could not find it — do NOT answer from '
                    . 'your own general knowledge instead. Whenever you use a result from this tool, '
                    . 'name the source URL it came from in your reply so the user knows it came from an '
                    . 'external page, not CoreX.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to look up.'],
                        'limit' => ['type' => 'integer', 'description' => 'Number of excerpts (default 4).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'find_page',
                'description' =>
                    'Find WHERE something lives in CoreX. Returns the real page and a working link, '
                    . 'already filtered to what this user is permitted to open. USE THIS whenever the '
                    . 'user asks where to find, where to go, or where something is done. Always give '
                    . 'the user the returned link verbatim.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The feature or destination being looked for.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'find_how_to',
                'description' =>
                    'Get the step-by-step walkthrough for a CoreX task, from the guided-tour library. '
                    . 'USE THIS for "how do I…" questions about using the system. Follow the returned '
                    . 'steps EXACTLY — do not invent buttons, menus or steps that are not in them. '
                    . 'If nothing is returned, say the walkthrough is not documented and use find_page '
                    . 'to at least point the user at the right screen.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The task the user wants to perform.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'list_document_templates',
                'description' =>
                    'List the agency document templates and reusable clauses available to this user in '
                    . 'DocuPerfect (mandates, OTPs, FICA forms, addenda, lease agreements). USE THIS when '
                    . 'the user asks which document to use, what templates exist, or where to get a form. '
                    . 'Returns names only — use search_knowledge for what a document actually says.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Optional filter, e.g. "FICA" or "mandate".'],
                    ],
                ],
            ],
            [
                'name' => 'my_listings',
                'description' =>
                    'Count and list property listings. USE THIS for "how many listings do I have", '
                    . '"what are my active listings", "which of my properties are sold". Returns a real '
                    . 'count from the database — never estimate or guess a number. '
                    . 'By default this returns only the listings where THIS USER is the agent, which is '
                    . 'what someone means by "my listings". Pass scope="agency" only when they explicitly '
                    . 'ask about the whole company or another agent.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status'       => ['type' => 'string', 'description' => 'Optional status filter, e.g. "active", "sold", "rented".'],
                        'listing_type' => ['type' => 'string', 'description' => 'Optional: "sale" or "rental".'],
                        'scope'        => ['type' => 'string', 'enum' => ['mine', 'agency'], 'description' => '"mine" (default) = this user\'s own listings. "agency" = everything they are permitted to see.'],
                        'limit'        => ['type' => 'integer', 'description' => 'How many examples to return (default 10).'],
                    ],
                ],
            ],
            [
                'name' => 'my_deals',
                'description' =>
                    'Count and list deals. USE THIS for "how many deals do I have", "what is in my '
                    . 'pipeline", "which deals are pending". Returns real database counts. '
                    . 'By default returns only deals THIS USER is on. Pass scope="agency" only when they '
                    . 'explicitly ask about the whole company.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional accepted_status filter, e.g. "Pending", "Granted", "Registered".'],
                        'scope'  => ['type' => 'string', 'enum' => ['mine', 'agency'], 'description' => '"mine" (default) = deals this user is on. "agency" = everything they are permitted to see.'],
                        'limit'  => ['type' => 'integer', 'description' => 'How many examples to return (default 10).'],
                    ],
                ],
            ],
            [
                'name' => 'my_performance',
                'description' =>
                    'This user\'s performance for the current month: targets, actuals, gap to target, '
                    . 'required daily pace, activity points and days left. USE THIS for "how am I doing", '
                    . '"what is my target today", "how far behind am I".',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'find_contact',
                'description' =>
                    'Look up a person in the agency contact book by name, phone or email — buyers, '
                    . 'sellers, tenants, landlords. USE THIS when the user names someone and asks about '
                    . 'them. Returns contact detail this user can already see in CoreX.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name'  => ['type' => 'string', 'description' => 'Name, phone or email to search for.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 5).'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'calculate_transfer_costs',
                'description' =>
                    'Calculate South African transfer and bond registration costs for a purchase price, '
                    . 'using the agency\'s configured Law Society guideline tariff — conveyancing fees, '
                    . 'posts and petties, VAT, deeds office fees and transfer duty. USE THIS whenever a '
                    . 'user asks what transfer costs, transfer duty, conveyancing fees or bond costs '
                    . 'would be. NEVER work these out yourself — the tariff is a lookup table and your '
                    . 'arithmetic will be wrong.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'purchase_price' => ['type' => 'number', 'description' => 'Purchase price in Rands, e.g. 2500000.'],
                        'bond_amount'    => ['type' => 'number', 'description' => 'Optional bond amount. Defaults to the purchase price (100% bond).'],
                    ],
                    'required' => ['purchase_price'],
                ],
            ],
            [
                'name' => 'sa_prime_rate',
                'description' =>
                    'The current South African prime lending rate as configured by the agency. USE THIS '
                    . 'for any question about the prime rate, interest rates or lending rates — you have '
                    . 'no reliable knowledge of the current rate and must not state one from memory.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'find_property',
                'description' =>
                    'Look up a property by address, street, suburb, complex or reference — scoped to '
                    . 'this user\'s permissions. USE THIS when the user names a property and asks about '
                    . 'it, e.g. its price, status or agent.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Address, suburb, complex name or reference.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 5).'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Execute one tool call and return its result as a JSON string.
     *
     * NEVER throws. A broken tool returns a readable error the model can route
     * around — a failing lookup must not take down the conversation
     * (BUILD_STANDARD §4). "Nothing found" is returned explicitly so the model
     * can tell an empty result from a broken tool.
     */
    public function execute(string $tool, array $input, User $user): string
    {
        try {
            $result = match ($tool) {
                'search_knowledge'        => $this->searchKnowledge($input),
                'search_reference_sites'  => $this->searchReferenceSites($input),
                'find_page'               => $this->findPage($input, $user),
                'find_how_to'             => $this->findHowTo($input, $user),
                'list_document_templates' => $this->listTemplates($input, $user),
                'my_listings'             => $this->myListings($input, $user),
                'my_deals'                => $this->myDeals($input, $user),
                'my_performance'          => $this->myPerformance($user),
                'find_contact'            => $this->findContact($input),
                'find_property'           => $this->findProperty($input, $user),
                'calculate_transfer_costs' => $this->transferCosts($input),
                'sa_prime_rate'           => $this->primeRate(),
                default                   => ['error' => "Unknown tool '{$tool}'."],
            };
        } catch (Throwable $e) {
            Log::warning('ELLIE_TOOL_ERROR', [
                'tool'    => $tool,
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            $result = ['error' => 'That lookup failed. Answer from what you already know, and tell the user the lookup was unavailable.'];
        }

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── Knowledge & help ────────────────────────────────────────────────────

    private function searchKnowledge(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $found = $this->knowledge->search($query, $this->limit($input, 4, 8));

        $context = trim((string) ($found['context'] ?? ''));

        if ($context === '') {
            return ['result' => 'no results', 'hint' => 'Nothing matched. Try different wording, or a broader term.'];
        }

        // A single search can return 10k+ characters, and the model may search
        // several times in one answer. Trim each result so a multi-search
        // conversation stays inside a sane token budget.
        if (mb_strlen($context) > self::MAX_EXCERPT_CHARS) {
            $context = mb_substr($context, 0, self::MAX_EXCERPT_CHARS)
                . "\n\n[…excerpt truncated — search with more specific words if you need the rest]";
        }

        return [
            'excerpts' => $context,
            'sources'  => $found['sources'],
        ];
    }

    private function searchReferenceSites(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $found = $this->referenceSources->search($query, $this->limit($input, 4, 8));

        $context = trim((string) ($found['context'] ?? ''));

        if ($context === '') {
            return ['result' => 'no results', 'hint' => 'No approved external source matched. Do not answer from general knowledge instead — say you could not find it.'];
        }

        if (mb_strlen($context) > self::MAX_EXCERPT_CHARS) {
            $context = mb_substr($context, 0, self::MAX_EXCERPT_CHARS)
                . "\n\n[…excerpt truncated — search with more specific words if you need the rest]";
        }

        return [
            'excerpts' => $context,
            'sources'  => $found['sources'],
            'note'     => 'This came from an approved EXTERNAL page, not CoreX\'s own knowledge base — cite the source URL when you use it.',
        ];
    }

    private function findPage(array $input, User $user): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $matches = $this->navigation->search($query, $user, 3);
        if (empty($matches)) {
            return ['result' => 'no results', 'hint' => 'No CoreX page matched. Do not invent one — say you are not sure where it lives.'];
        }

        return ['pages' => array_map(fn ($m) => [
            'name'     => $m['label'],
            'category' => $m['category'],
            'what'     => $m['blurb'],
            'link'     => $m['url'],
        ], $matches)];
    }

    private function findHowTo(array $input, User $user): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $found = $this->tours->buildContext($query, $user, 2);
        if (trim((string) ($found['context'] ?? '')) === '') {
            return ['result' => 'no results', 'hint' => 'No walkthrough is documented for this. Use find_page to point at the right screen instead of inventing steps.'];
        }

        return ['walkthroughs' => $found['context'], 'sources' => $found['sources']];
    }

    private function listTemplates(array $input, User $user): array
    {
        $filter = trim((string) ($input['query'] ?? ''));

        $templates = Template::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->when($filter !== '', fn ($q) => $q->where('name', 'like', '%' . $filter . '%'))
            ->orderBy('name')
            ->limit(self::MAX_LIMIT)
            ->pluck('name')
            ->all();

        $clauses = Clause::query()
            ->visibleTo($user)
            ->when($filter !== '', fn ($q) => $q->where('name', 'like', '%' . $filter . '%'))
            ->orderBy('name')
            ->limit(self::MAX_LIMIT)
            ->pluck('name')
            ->all();

        if (empty($templates) && empty($clauses)) {
            return ['result' => 'no results', 'hint' => 'No templates or clauses matched that filter.'];
        }

        return [
            'document_templates' => $templates,
            'reusable_clauses'   => $clauses,
            'where'              => 'DocuPerfect → Templates',
        ];
    }

    // ── Live pillar data (read-only, permission-scoped) ─────────────────────

    private function myListings(array $input, User $user): array
    {
        $status = trim((string) ($input['status'] ?? ''));
        $type   = trim((string) ($input['listing_type'] ?? ''));

        // visibleTo() is the SECURITY ceiling — never bypassed. On top of it sits
        // the INTENT filter: "how many listings do I have" means the ones this
        // agent is on, not the 4,800 the agency's permission config happens to
        // let them view. Without this, Ellie answered "you have 4,816 listings"
        // to an agent with 306. Widening is opt-in and still capped by visibleTo.
        $query = Property::query()->visibleTo($user);

        if ($this->scope($input) === 'mine') {
            $query->where('agent_id', $user->id);
        }

        // Status is stored mixed-case (the P24 importer writes 'Sold'/'Rented'),
        // so it is always compared case-insensitively.
        if ($status !== '') {
            $query->whereRaw('LOWER(COALESCE(status, "")) LIKE ?', ['%' . mb_strtolower($status) . '%']);
        }

        // listing_type is NOT normalised either — the P24 importer writes
        // 'Rental' while other paths write lowercase. Compare case-insensitively.
        if ($type !== '') {
            $needle = str_contains(mb_strtolower($type), 'rent') ? 'rental' : 'sale';
            $query->whereRaw('LOWER(COALESCE(listing_type, "")) LIKE ?', ['%' . $needle . '%']);
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('updated_at')
            ->limit($this->limit($input, 10))
            ->get(['id', 'address', 'suburb', 'price', 'status', 'listing_type', 'property_type', 'beds', 'baths']);

        return [
            'total_count' => $total,
            'counting'    => $this->scope($input) === 'mine'
                ? 'listings where this user is the agent'
                : 'all listings this user is permitted to see (whole agency)',
            'filters'     => array_filter(['status' => $status, 'listing_type' => $type]),
            'examples'    => $rows->map(fn ($p) => [
                'address'  => trim(($p->address ?: '') . ($p->suburb ? ', ' . $p->suburb : '')) ?: 'No address captured',
                'price'    => $p->price !== null ? 'R ' . number_format((float) $p->price, 0, '.', ',') : null,
                'status'   => $p->status,
                'type'     => $p->property_type,
                'for'      => $p->listing_type,
                'beds'     => $p->beds,
                'baths'    => $p->baths,
                'link'     => '/corex/properties/' . $p->id,
            ])->all(),
        ];
    }

    private function myDeals(array $input, User $user): array
    {
        $status = trim((string) ($input['status'] ?? ''));

        // Security ceiling, then intent filter — see myListings() for why.
        $query = Deal::query()->visibleTo($user);

        if ($this->scope($input) === 'mine') {
            $query->whereIn('id', function ($sub) use ($user) {
                $sub->select('deal_id')->from('deal_user')->where('user_id', $user->id);
            });
        }

        if ($status !== '') {
            $query->whereRaw('LOWER(COALESCE(accepted_status, "")) LIKE ?', ['%' . mb_strtolower($status) . '%']);
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('deal_date')
            ->limit($this->limit($input, 10))
            ->get(['id', 'deal_no', 'property_address', 'property_value', 'accepted_status', 'commission_status', 'deal_date', 'registration_date']);

        return [
            'total_count' => $total,
            'counting'    => $this->scope($input) === 'mine'
                ? 'deals this user is on'
                : 'all deals this user is permitted to see (whole agency)',
            'filters'     => array_filter(['status' => $status]),
            'examples'    => $rows->map(fn ($d) => [
                'deal_no'      => $d->deal_no,
                'address'      => $d->property_address,
                'value'        => $d->property_value !== null ? 'R ' . number_format((float) $d->property_value, 0, '.', ',') : null,
                'status'       => $d->accepted_status,
                'commission'   => $d->commission_status,
                'deal_date'    => optional($d->deal_date)->format('Y-m-d') ?? $d->deal_date,
                'registered'   => optional($d->registration_date)->format('Y-m-d') ?? $d->registration_date,
                'link'         => '/corex/deals/' . $d->id,
            ])->all(),
        ];
    }

    private function myPerformance(User $user): array
    {
        $snapshot = $this->performance->getMonthlySnapshot($user, now());

        return [
            'period'            => $snapshot['period'] ?? null,
            'targets'           => $snapshot['derived_targets'] ?? [],
            'actuals'           => $snapshot['actuals'] ?? [],
            'remaining'         => $snapshot['remaining'] ?? [],
            'required_pace'     => $snapshot['pace'] ?? [],
            'progress_percent'  => $snapshot['progress'] ?? [],
            'note' => 'Targets come from the agent\'s Worksheet. If every target is zero the Worksheet '
                    . 'for this month has not been completed — tell them that rather than reporting zeros.',
        ];
    }

    private function findContact(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'A name to search for is required.'];
        }

        // Contacts are agency-wide in CoreX (isolation via BelongsToAgency /
        // AgencyScope, applied automatically). find_contact deliberately mirrors
        // that existing product behaviour rather than inventing a stricter rule
        // Ellie alone enforces. Spec: .ai/specs/ellie-v2.md §3.2.
        $rows = Contact::query()
            ->search($name)
            ->with('type')
            ->orderBy('last_name')
            ->limit($this->limit($input, 5))
            ->get(['id', 'first_name', 'last_name', 'phone', 'email', 'contact_type_id', 'suburb', 'last_contacted_at', 'is_buyer']);

        if ($rows->isEmpty()) {
            return ['result' => 'no results', 'hint' => 'Nobody matched that name in the contact book.'];
        }

        return ['contacts' => $rows->map(fn ($c) => [
            'name'           => trim($c->first_name . ' ' . $c->last_name),
            'phone'          => $c->phone,
            'email'          => $c->email,
            'type'           => $c->type->name ?? null,
            'suburb'         => $c->suburb,
            'is_buyer'       => (bool) $c->is_buyer,
            'last_contacted' => optional($c->last_contacted_at)->format('Y-m-d'),
            'link'           => '/corex/contacts/' . $c->id,
        ])->all()];
    }

    private function findProperty(array $input, User $user): array
    {
        $q = trim((string) ($input['query'] ?? ''));
        if ($q === '') {
            return ['error' => 'A search query is required.'];
        }

        $like = '%' . $q . '%';

        $rows = Property::query()
            ->visibleTo($user)
            ->where(function ($w) use ($like) {
                $w->where('address', 'like', $like)
                  ->orWhere('street_name', 'like', $like)
                  ->orWhere('suburb', 'like', $like)
                  ->orWhere('complex_name', 'like', $like)
                  ->orWhere('title', 'like', $like)
                  ->orWhere('p24_ref', 'like', $like)
                  ->orWhere('property_number', 'like', $like);
            })
            ->limit($this->limit($input, 5))
            ->get(['id', 'address', 'suburb', 'price', 'status', 'listing_type', 'property_type', 'beds', 'baths', 'agent_id']);

        if ($rows->isEmpty()) {
            return ['result' => 'no results', 'hint' => 'No property you have access to matched. It may belong to another agent, or not be captured yet.'];
        }

        return ['properties' => $rows->map(fn ($p) => [
            'address' => trim(($p->address ?: '') . ($p->suburb ? ', ' . $p->suburb : '')) ?: 'No address captured',
            'price'   => $p->price !== null ? 'R ' . number_format((float) $p->price, 0, '.', ',') : null,
            'status'  => $p->status,
            'type'    => $p->property_type,
            'for'     => $p->listing_type,
            'beds'    => $p->beds,
            'baths'   => $p->baths,
            'link'    => '/corex/properties/' . $p->id,
        ])->all()];
    }

    // ── Calculators & agency settings ───────────────────────────────────────

    private function transferCosts(array $input): array
    {
        $price = (float) ($input['purchase_price'] ?? 0);
        if ($price <= 0) {
            return ['error' => 'A purchase price greater than zero is required.'];
        }

        $bondAmount = (float) ($input['bond_amount'] ?? 0);
        if ($bondAmount <= 0) {
            $bondAmount = $price;
        }

        $transfer = PropertyCostService::calcTransferCosts($price);
        $bond     = PropertyCostService::calcBondCosts($bondAmount);

        $zar = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ',');

        return [
            'purchase_price' => $zar($price),
            'transfer' => [
                'conveyancing_fee' => $zar($transfer['conveyancing_fee']),
                'posts_petties'    => $zar($transfer['posts_petties']),
                'vat'              => $zar($transfer['vat']),
                'deeds_office'     => $zar($transfer['deeds_office']),
                'transfer_duty'    => $zar($transfer['transfer_duty']),
                'total'            => $zar($transfer['total']),
            ],
            'bond_registration' => [
                'bond_amount'      => $zar($bondAmount),
                'conveyancing_fee' => $zar($bond['conveyancing_fee']),
                'posts_petties'    => $zar($bond['posts_petties']),
                'vat'              => $zar($bond['vat']),
                'deeds_office'     => $zar($bond['deeds_office']),
                'total'            => $zar($bond['total']),
            ],
            'grand_total' => $zar($transfer['total'] + $bond['total']),
            'disclaimer'  => 'Law Society Guideline Tariff 2025 (Van Dyk & Swart). Actual fees vary by '
                           . 'attorney. Additional costs apply: bank initiation ~R6,038, FICA R1,265 per '
                           . 'person, rates clearance certificate ~R950.',
        ];
    }

    private function primeRate(): array
    {
        $rate    = DB::table('performance_settings')->where('key', 'sa_prime_rate')->value('value');
        $updated = DB::table('performance_settings')->where('key', 'sa_prime_rate_updated_at')->value('value');

        if (empty($rate)) {
            return [
                'result' => 'no results',
                'hint'   => 'The prime rate has not been manually configured in Performance Settings — this '
                          . 'does NOT mean the answer is unavailable. Before telling the user it is not set, '
                          . 'call search_reference_sites — an admin may have approved an external rate page '
                          . '(e.g. a central bank or market-data site) that answers this directly. Only if '
                          . 'that also returns nothing should you tell the user neither source has it '
                          . 'configured, never quoting a rate from memory either way.',
            ];
        }

        return [
            'sa_prime_lending_rate' => $rate . '%',
            'last_updated'          => $updated ?: 'unknown',
            'source'                => 'Agency Performance Settings',
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Intent scope for the pillar tools. Defaults to "mine" — anything the model
     * does not explicitly widen stays personal. Never affects the security
     * ceiling, which is always visibleTo().
     */
    private function scope(array $input): string
    {
        return mb_strtolower(trim((string) ($input['scope'] ?? 'mine'))) === 'agency' ? 'agency' : 'mine';
    }

    /**
     * A model-supplied limit is a suggestion, never a promise — clamp it so a
     * hallucinated limit of 5000 cannot pull the database into the prompt.
     */
    private function limit(array $input, int $default, int $max = self::MAX_LIMIT): int
    {
        $raw = $input['limit'] ?? null;
        if (! is_numeric($raw)) {
            return $default;
        }

        return max(1, min((int) $raw, $max));
    }
}
