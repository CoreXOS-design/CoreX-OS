<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Docuperfect\FieldLabel;
use App\Services\Docuperfect\LeasePhraseMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * esign-lease-field-source.md - every lease phrase of the residential lease the importer once left unlinked now points at the
 * Lease source, and lease dates never point at a mandate or property date.
 */
final class LeasePhraseMatcherTest extends TestCase
{
    /** @return array<string, array{0:string,1:string,2:string,3:string,4:?string,5:string}> before, after, clause, above, previousKey, expected */
    public static function phrases(): array
    {
        return [
            'adults' => ["\tThe Premises shall be personally occupied by ", ' Adults and not more than ', '', '', null, 'adults'],
            'other persons' => [' Adults and not more than ', ' other persons. ', '', '', 'adults', 'max_other_persons'],
            'rental figure' => ["\tThe Rental shall be ", '(', '', '', null, 'rent'],
            'rental in words' => ['(', ') per month, subject to 4.2', '', '', 'rent', 'rent_in_words'],
            'escalation %' => [' The Rental shall be subject to an escalation of ', '% (', '', '', null, 'escalation_percent'],
            'escalation in words' => ['% (', ') per annum from the 1st day of', '', '', 'escalation_percent', 'escalation_in_words'],
            'escalation month' => [') per annum from the 1st day of ', ' each year, and the amount escalated', 'The Rental shall be subject to an escalation of x% (y) per annum from the 1st day of z each year', '', 'escalation_in_words', 'escalation_month'],
            'commence' => ['The lease shall commence on ', ', and shall continue thereafter', '', '', null, 'start_date'],
            'earliest termination' => ['may not be given by either party to expire prior to the ', '', '', '', null, 'earliest_termination_date'],
            'expire at midnight' => ["\tThe lease shall expire at midnight on ", ', (the expiry date).', '', '', null, 'end_date'],
            'renewal' => ['the Lessee has the option to renew the lease for a further period of ', ' months (the renewal period)', '', '', null, 'renewal_option_months'],
            'pets' => ['             ', '', '', 'only the following type and number of pets on the PREMISES:', null, 'pets'],
            'other conditions' => ['', '', '', '23.  OTHER CONDITIONS', null, 'other_conditions'],
            'total rental' => ["Total Rental Amount \t\t\t", '', '', 'Breakdown', null, 'rent'],
            'service fee' => ["Less Agent\u{2019}s Service Fee (Including VAT)\t", '', '', '', null, 'agent_service_fee'],
            'net to owner' => ["Net Amount to Owner\t\t\t", '', '', '', null, 'net_to_owner'],
            'lease fee by its generic name' => ["Lease fee\t\t", '', '', '', null, 'lease_fee'],
            'deposit' => ['A deposit of ', '', '', '', null, 'deposit'],
        ];
    }

    #[DataProvider('phrases')]
    public function test_a_lease_phrase_points_at_the_lease_source(string $before, string $after, string $clause, string $above, ?string $prev, string $expected): void
    {
        $hit = (new LeasePhraseMatcher())->match($before, $after, $clause, $above, $prev);

        $this->assertNotNull($hit, "no lease field found for: {$before}");
        $this->assertSame('lease_' . $expected, $hit['field_name']);
        $this->assertSame('lease', $hit['source']);
    }

    public function test_the_agencys_own_name_for_its_lease_fee_is_recognised_without_any_agency_in_the_code(): void
    {
        $m = new LeasePhraseMatcher("Tenant Welcome Fee");
        $this->assertSame('lease_lease_fee', $m->match("Tenant Welcome Fee\t\t", '')['field_name']);
        $this->assertNull((new LeasePhraseMatcher())->match("Tenant Welcome Fee\t\t", ''));
    }

    public function test_lease_dates_never_become_a_mandate_or_property_date(): void
    {
        foreach ([['The lease shall commence on ', ''], ['The lease shall expire at midnight on ', ''], ['expire prior to the ', '']] as [$b, $a]) {
            $hit = (new LeasePhraseMatcher())->match($b, $a);
            $this->assertStringStartsWith('lease_', $hit['field_name']);
            $this->assertStringNotContainsString('deal.', $hit['field_name']);
            $this->assertStringNotContainsString('property.', $hit['field_name']);
        }
    }

    public function test_ordinary_wording_is_left_to_the_other_rules(): void
    {
        $this->assertNull((new LeasePhraseMatcher())->match('Physical address: ', ''));
        $this->assertNull((new LeasePhraseMatcher())->match('Name of the lessee ', ''));
    }

    public function test_only_a_lease_is_read_with_lease_wording(): void
    {
        $m = new LeasePhraseMatcher();
        $this->assertTrue($m->isLeaseDocument('LEASE AGREEMENT - RESIDENTIAL'));
        $this->assertTrue($m->isLeaseDocument('Agreement of Lease'));
        $this->assertFalse($m->isLeaseDocument('LETTING MANDATE (sole)'));
        $this->assertFalse($m->isLeaseDocument('Notice of intention to terminate the lease'));
        $this->assertFalse($m->isLeaseDocument('EXCLUSIVE AUTHORITY TO SELL'));
        $this->assertFalse($m->isLeaseDocument('Rental application'));
    }

    public function test_labels_keep_apostrophes_and_refuse_sentence_fragments(): void
    {
        $this->assertSame("Let's Assist Fee", FieldLabel::fromPrecedingText("Let\u{2019}s Assist Fee\t\t\t"));
        $this->assertSame('Date of birth', FieldLabel::fromPrecedingText('Date of birth: '));
        $this->assertNull(FieldLabel::fromPrecedingText('The Rental shall be '));
        $this->assertNull(FieldLabel::fromPrecedingText(' Adults and not more than '));
        $this->assertNull(FieldLabel::fromPrecedingText('The Premises shall be personally occupied by '));
        $this->assertNull(FieldLabel::fromPrecedingText('ab'));
    }
}
