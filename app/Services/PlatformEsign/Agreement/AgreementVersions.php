<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\DevSetting;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\WordingAudit;
use App\Models\PlatformEsign\WordingVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The wording editor's engine (spec §11.14): drafts copied from a published version, clause-level section saves,
 * rates, publish (immutable from then on), discard / restore of drafts, audit, and "what changed" between two versions.
 * Every rule that protects a sent agreement lives here, not in a controller: published versions are never touched,
 * field markers can't be removed, and every change is attributed.
 */
class AgreementVersions
{
    /** rate key => [label, kind] — the editable pricing of a version (kind: money | int). */
    public const RATE_FIELDS = [
        'team_seat'          => ['CoreX Team — price per seat (R)', 'money'],
        'team_max_seats'     => ['CoreX Team — most seats on this plan', 'int'],
        'agency_base'        => ['CoreX Agency — base fee (R)', 'money'],
        'agency_t1'          => ['CoreX Agency — price per seat, first tier (R)', 'money'],
        'agency_t1_max'      => ['First tier runs up to seat number', 'int'],
        'agency_t2'          => ['CoreX Agency — price per seat, second tier (R)', 'money'],
        'agency_t2_max'      => ['Second tier runs up to seat number', 'int'],
        'agency_t3'          => ['CoreX Agency — price per seat, third tier and above (R)', 'money'],
        'branch'             => ['Additional branch — price per branch (R)', 'money'],
        'quote_above_agents' => ['Show the “quoted rate” notice above this many agents', 'int'],
    ];

    public function __construct(private AgreementContent $content, private AgreementLayout $layout)
    {
    }

    // ── Reading ────────────────────────────────────────────────────────────

    public function template(): Template
    {
        $this->content->ensureSeeded();

        return AgreementContent::template();
    }

    public function current(): WordingVersion
    {
        return $this->content->ensureSeeded();
    }

    public function draft(): ?WordingVersion
    {
        return WordingVersion::drafts()->where('template_id', $this->template()->id)->orderByDesc('id')->first();
    }

    /** Newest first: drafts, then published versions. */
    public function listing()
    {
        $tid = $this->template()->id;

        return WordingVersion::where('template_id', $tid)->withCount('documents')->with(['publisher', 'creator'])
            ->orderBy('is_published')->orderByDesc('published_at')->orderByDesc('id')->get();
    }

    public function discardedDrafts()
    {
        return WordingVersion::onlyTrashed()->where('template_id', $this->template()->id)->where('is_published', false)->with('creator')->orderByDesc('deleted_at')->get();
    }

    /** The next unused "major.minor" after the highest published version. */
    public function nextVersion(): string
    {
        $versions = WordingVersion::withTrashed()->where('template_id', $this->template()->id)->where('is_published', true)->pluck('version')->all();
        usort($versions, 'version_compare');
        $top = end($versions) ?: '1.0';
        $p = array_map('intval', explode('.', $top));
        $next = ($p[0] ?? 1) . '.' . (($p[1] ?? 0) + 1);

        return in_array($next, $versions, true) ? $next . '.1' : $next;
    }

    /** @return string[] the part's clauses (Markdown source) */
    public function clauses(WordingVersion $v, string $part): array
    {
        return AgreementBlocks::split((string) ($v->content_json[$part] ?? ''));
    }

    // ── Drafts ─────────────────────────────────────────────────────────────

    /**
     * Copy a published version (default: the current one) into the one allowed draft.
     *
     * @throws \DomainException
     */
    public function createDraft(int $userId, ?int $fromId = null): WordingVersion
    {
        $tpl = $this->template();

        return DB::transaction(function () use ($tpl, $userId, $fromId) {
            Template::whereKey($tpl->id)->lockForUpdate()->first();
            if (WordingVersion::drafts()->where('template_id', $tpl->id)->exists()) {
                throw new \DomainException('There is already a draft in progress — continue it or discard it first.');
            }
            $from = $fromId
                ? WordingVersion::published()->where('template_id', $tpl->id)->find($fromId)
                : WordingVersion::currentFor($tpl->id)->first();
            if (!$from) {
                throw new \DomainException('That version cannot be copied.');
            }
            $draft = WordingVersion::create([
                'template_id' => $tpl->id, 'parent_version_id' => $from->id, 'version' => 'draft-' . Str::lower(Str::random(8)),
                'version_date' => now()->toDateString(), 'change_note' => null, 'content_json' => $from->content_json,
                'rates_json' => $from->rates_json, 'layout_json' => null, 'is_published' => false, 'rev' => 0, 'created_by' => $userId,
            ]);
            $this->audit($tpl->id, $draft->id, 'draft_created', $userId, 'New draft copied from version ' . $from->version);

            return $draft;
        });
    }

    /**
     * Save one section's clauses. $rev is the draft revision the browser last saw (two-tab guard).
     *
     * @param string[] $clauses
     * @return int the new revision
     * @throws WordingInvalid|\DomainException
     */
    public function saveSection(WordingVersion $draft, string $part, array $clauses, int $rev, int $userId): int
    {
        if (!isset(AgreementContent::PARTS[$part])) {
            throw new \DomainException('That section does not exist.');
        }
        $clauses = AgreementBlocks::sanitise($clauses);
        if (!$clauses) {
            throw new WordingInvalid(['A section cannot be empty.']);
        }
        $md = AgreementBlocks::join($clauses);

        return DB::transaction(function () use ($draft, $part, $md, $rev, $userId) {
            $locked = WordingVersion::drafts()->whereKey($draft->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \DomainException('This draft is no longer open for editing.');
            }
            if ($rev !== (int) $locked->rev) {
                throw new AgreementConflict((int) $locked->rev, []);
            }
            $label = AgreementContent::PARTS[$part];
            $errors = AgreementTokens::validate($md, (array) $locked->rates_json, $label);
            $baseline = (string) ($locked->parent?->content_json[$part] ?? $this->current()->content_json[$part] ?? '');
            $errors = array_merge($errors, AgreementTokens::compare($baseline, $md, $label));
            if ($errors) {
                throw new WordingInvalid($errors);
            }
            $old = AgreementBlocks::split((string) ($locked->content_json[$part] ?? ''));
            $new = AgreementBlocks::split($md);
            $sum = AgreementDiff::summary(AgreementDiff::clauses($old, $new));
            $content = (array) $locked->content_json;
            $content[$part] = $md;
            $locked->content_json = $content;
            $locked->layout_json = null;
            $locked->rev = (int) $locked->rev + 1;
            $locked->save();
            if ($sum['added'] + $sum['removed'] + $sum['changed'] > 0) {
                $this->audit($locked->template_id, $locked->id, 'section_saved', $userId,
                    $label . ' saved — ' . $sum['changed'] . ' edited, ' . $sum['added'] . ' added, ' . $sum['removed'] . ' removed');
            }

            return (int) $locked->rev;
        });
    }

    /**
     * @param array<string,mixed> $input rate key => entered value
     * @return int the new revision
     * @throws WordingInvalid|\DomainException
     */
    public function saveRates(WordingVersion $draft, array $input, int $rev, int $userId): int
    {
        $rates = $this->cleanRates($input);

        return DB::transaction(function () use ($draft, $rates, $rev, $userId) {
            $locked = WordingVersion::drafts()->whereKey($draft->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \DomainException('This draft is no longer open for editing.');
            }
            if ($rev !== (int) $locked->rev) {
                throw new AgreementConflict((int) $locked->rev, []);
            }
            $changed = [];
            foreach ($rates as $k => $v) {
                if ((float) ($locked->rates_json[$k] ?? -1) !== (float) $v) {
                    $changed[] = self::RATE_FIELDS[$k][0] . ': ' . AgreementPricing::number((float) ($locked->rates_json[$k] ?? 0)) . ' → ' . AgreementPricing::number((float) $v);
                }
            }
            $locked->rates_json = $rates;
            $locked->layout_json = null;
            $locked->rev = (int) $locked->rev + 1;
            $locked->save();
            if ($changed) {
                $this->audit($locked->template_id, $locked->id, 'rates_saved', $userId, Str::limit(implode('; ', $changed), 480, '…'));
            }

            return (int) $locked->rev;
        });
    }

    /** @return array<string,int|float> @throws WordingInvalid */
    public function cleanRates(array $input): array
    {
        $errors = [];
        $out = [];
        foreach (self::RATE_FIELDS as $key => [$label, $kind]) {
            $raw = trim(str_replace([' ', ','], ['', '.'], (string) ($input[$key] ?? '')));
            $ok = $kind === 'int' ? preg_match('/^\d{1,4}$/', $raw) : preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw);
            if (!$ok) {
                $errors[] = $label . ' must be ' . ($kind === 'int' ? 'a whole number.' : 'an amount in rand (for example 1495 or 295.50).');
                continue;
            }
            $out[$key] = $kind === 'int' ? (int) $raw : (fmod((float) $raw, 1.0) === 0.0 ? (int) $raw : (float) $raw);
        }
        if (!$errors) {
            if ($out['agency_t1_max'] < 1) {
                $errors[] = 'The first seat tier must cover at least one seat.';
            }
            if ($out['agency_t2_max'] <= $out['agency_t1_max']) {
                $errors[] = 'The second tier must end at a higher seat number than the first.';
            }
            if ($out['team_max_seats'] < 1) {
                $errors[] = 'The CoreX Team plan must allow at least one seat.';
            }
        }
        if ($errors) {
            throw new WordingInvalid($errors);
        }

        return $out;
    }

    /** Every rule a draft must satisfy before it can be published. @return string[] */
    public function problems(WordingVersion $draft): array
    {
        $errors = [];
        try {
            $this->cleanRates((array) $draft->rates_json);
        } catch (WordingInvalid $e) {
            $errors = array_merge($errors, $e->errors);
        }
        foreach (AgreementContent::PARTS as $part => $label) {
            $md = (string) ($draft->content_json[$part] ?? '');
            if (trim($md) === '') {
                $errors[] = $label . ' is empty.';
                continue;
            }
            $errors = array_merge($errors, AgreementTokens::validate($md, (array) $draft->rates_json, $label));
            $errors = array_merge($errors, AgreementTokens::compare((string) ($draft->parent?->content_json[$part] ?? $this->current()->content_json[$part] ?? ''), $md, $label));
        }

        return $errors;
    }

    /** Has the draft's wording or pricing changed compared with the CURRENT published version? */
    public function differsFromCurrent(WordingVersion $draft): bool
    {
        $cur = $this->current();

        return $draft->content_json != $cur->content_json || $this->ratesOf($draft) != $this->ratesOf($cur);
    }

    private function ratesOf(WordingVersion $v): array
    {
        $r = array_merge(AgreementPricing::DEFAULT_RATES, (array) $v->rates_json);
        ksort($r);

        return array_map('floatval', $r);
    }

    /**
     * Publish the draft as an immutable version.
     *
     * @throws WordingInvalid|\DomainException
     */
    public function publish(WordingVersion $draft, string $version, string $date, string $note, int $userId): WordingVersion
    {
        $version = trim($version);
        $note = trim($note);
        $errors = $this->problems($draft);
        if (!preg_match('/^\d{1,3}\.\d{1,3}(\.\d{1,3})?$/', $version)) {
            $errors[] = 'The version number must look like 1.1 (digits and dots).';
        } elseif (WordingVersion::withTrashed()->where('template_id', $draft->template_id)->where('version', $version)->whereKeyNot($draft->id)->exists()) {
            $errors[] = 'Version ' . $version . ' already exists — versions are never reused. Choose another number.';
        }
        try {
            $d = Carbon::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date || $d->year < 2026 || $d->gt(now()->addYear())) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            $errors[] = 'Enter a valid version date.';
        }
        if (mb_strlen($note) < 5) {
            $errors[] = 'Write a short change note (at least a few words) — it is shown with the version.';
        }
        if (mb_strlen($note) > 500) {
            $errors[] = 'The change note is limited to 500 characters.';
        }
        if (!$errors && !$this->differsFromCurrent($draft)) {
            $errors[] = 'This draft is identical to the current version ' . $this->current()->version . ' — there is nothing to publish.';
        }
        if ($errors) {
            throw new WordingInvalid($errors);
        }

        // Calibrate pagination now (a real PDF render) so the version is ready to use and never calibrates on a recipient's request.
        $revSeen = (int) $draft->rev;
        $draft->layout_json = null;
        $layout = $this->layout->compute($draft);

        return DB::transaction(function () use ($draft, $version, $date, $note, $userId, $revSeen, $layout) {
            $locked = WordingVersion::drafts()->whereKey($draft->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \DomainException('This draft is no longer open for publishing.');
            }
            if ((int) $locked->rev !== $revSeen) {
                throw new AgreementConflict((int) $locked->rev, []);
            }
            $locked->forceFill([
                'version' => $version, 'version_date' => $date, 'change_note' => $note, 'layout_json' => $layout, 'is_published' => true,
                'published_at' => now(), 'published_by' => $userId,
            ])->save();
            $this->audit($locked->template_id, $locked->id, 'published', $userId, 'Published version ' . $version . ' — ' . Str::limit($note, 400, '…'));

            return $locked->fresh();
        });
    }

    /** Soft-discard a draft (never a published version). */
    public function discard(WordingVersion $draft, int $userId): void
    {
        DB::transaction(function () use ($draft, $userId) {
            $locked = WordingVersion::drafts()->whereKey($draft->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \DomainException('Only an open draft can be discarded.');
            }
            $locked->delete();
            $this->audit($locked->template_id, $locked->id, 'discarded', $userId, 'Draft discarded (it can be restored)');
        });
    }

    public function restore(int $id, int $userId): WordingVersion
    {
        $tpl = $this->template();

        return DB::transaction(function () use ($tpl, $id, $userId) {
            Template::whereKey($tpl->id)->lockForUpdate()->first();
            $v = WordingVersion::onlyTrashed()->where('template_id', $tpl->id)->where('is_published', false)->find($id);
            if (!$v) {
                throw new \DomainException('That draft cannot be restored.');
            }
            if (WordingVersion::drafts()->where('template_id', $tpl->id)->exists()) {
                throw new \DomainException('There is already a draft in progress — discard it before restoring another.');
            }
            $v->restore();
            $this->audit($tpl->id, $v->id, 'restored', $userId, 'Discarded draft restored');

            return $v;
        });
    }

    // ── Audit ──────────────────────────────────────────────────────────────

    public function audit(int $templateId, ?int $versionId, string $action, ?int $userId, ?string $detail = null): void
    {
        WordingAudit::create(['template_id' => $templateId, 'version_id' => $versionId, 'action' => $action, 'user_id' => $userId,
            'detail' => $detail ? Str::limit($detail, 490, '') : null, 'created_at' => now()]);
    }

    public function auditTrail(int $limit = 60)
    {
        return WordingAudit::with(['user', 'version'])->where('template_id', $this->template()->id)->orderByDesc('id')->limit($limit)->get();
    }

    // ── What changed ───────────────────────────────────────────────────────

    /**
     * @return array{parts:array<string,array{label:string,rows:array,summary:array}>,rates:array<int,array{label:string,a:string,b:string}>,total:array{added:int,removed:int,changed:int}}
     */
    public function compare(WordingVersion $a, WordingVersion $b): array
    {
        $parts = [];
        $total = ['added' => 0, 'removed' => 0, 'changed' => 0];
        foreach (AgreementContent::PARTS as $part => $label) {
            $rows = AgreementDiff::clauses(AgreementBlocks::split((string) ($a->content_json[$part] ?? '')), AgreementBlocks::split((string) ($b->content_json[$part] ?? '')));
            $sum = AgreementDiff::summary($rows);
            foreach ($sum as $k => $n) {
                $total[$k] += $n;
            }
            $parts[$part] = ['label' => $label, 'rows' => $rows, 'summary' => $sum];
        }
        $ra = array_merge(AgreementPricing::DEFAULT_RATES, (array) $a->rates_json);
        $rb = array_merge(AgreementPricing::DEFAULT_RATES, (array) $b->rates_json);
        $rates = [];
        foreach (self::RATE_FIELDS as $key => [$label]) {
            if ((float) ($ra[$key] ?? 0) !== (float) ($rb[$key] ?? 0)) {
                $rates[] = ['label' => $label, 'a' => AgreementPricing::number((float) ($ra[$key] ?? 0)), 'b' => AgreementPricing::number((float) ($rb[$key] ?? 0))];
            }
        }

        return ['parts' => $parts, 'rates' => $rates, 'total' => $total];
    }
}
