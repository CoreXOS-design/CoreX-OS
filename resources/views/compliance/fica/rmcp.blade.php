@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8 max-w-4xl">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <a href="{{ route('compliance.fica.index') }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Compliance</a>
            <h1 class="text-2xl font-bold text-slate-900">Risk Management and Compliance Programme</h1>
            <p class="text-sm text-slate-500 mt-1">Home Finders Coastal — FICA Compliance</p>
        </div>
        <div class="flex items-center gap-2 mt-3 sm:mt-0">
            <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m0 0a48.1 48.1 0 0 1 10.5 0m-10.5 0V5.625A2.625 2.625 0 0 1 9.875 3h4.25a2.625 2.625 0 0 1 2.625 2.625v3.18" /></svg>
                Print
            </button>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rmcp-doc">
        <style>
            .rmcp-doc { font-size: 0.9375rem; color: #334155; line-height: 1.7; }
            .rmcp-doc .rmcp-cover { background: #0f172a; color: #fff; padding: 3rem 2.5rem; text-align: center; }
            .rmcp-doc .rmcp-cover h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 0.5rem; }
            .rmcp-doc .rmcp-cover p { font-size: 0.875rem; color: #94a3b8; margin: 0.25rem 0; }
            .rmcp-doc .rmcp-body { padding: 2.5rem; }
            .rmcp-doc .rmcp-section { margin-bottom: 2rem; }
            .rmcp-doc .rmcp-section h2 { font-size: 1.125rem; font-weight: 700; color: #0f172a; border-bottom: 2px solid #0d9488; padding-bottom: 0.375rem; margin-bottom: 0.75rem; }
            .rmcp-doc .rmcp-section h3 { font-size: 1rem; font-weight: 600; color: #1e293b; margin: 1rem 0 0.5rem; }
            .rmcp-doc .rmcp-toc { list-style: none; padding: 0; }
            .rmcp-doc .rmcp-toc li { padding: 0.375rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; }
            .rmcp-doc .rmcp-toc li a { color: #334155; text-decoration: none; }
            .rmcp-doc .rmcp-toc li a:hover { color: #0d9488; }
            .rmcp-doc .rmcp-schedule { background: #f1f5f9; border-left: 3px solid #0d9488; padding: 1rem 1.25rem; margin: 0.75rem 0; }
            .rmcp-doc ul, .rmcp-doc ol { padding-left: 1.5rem; margin: 0.5rem 0; }
            .rmcp-doc li { margin-bottom: 0.25rem; }
            .rmcp-doc .rmcp-highlight { background: #f0fdfa; border: 1px solid #99f6e4; padding: 0.75rem 1rem; margin: 0.75rem 0; font-size: 0.875rem; }
            @media print {
                .rmcp-doc { border: none !important; }
                .rmcp-doc .rmcp-cover { break-after: page; }
                .rmcp-doc .rmcp-section { break-inside: avoid; }
            }
        </style>

        {{-- Cover --}}
        <div class="rmcp-cover">
            <p style="color: #0d9488; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 2px;">Financial Intelligence Centre Act 38 of 2001</p>
            <h1>Risk Management and Compliance Programme</h1>
            <p>Prepared in terms of Section 42 of the FIC Act</p>
            <p style="margin-top: 0.75rem; color: #cbd5e1;">Prepared by Johan Reichel</p>
            <p style="color: #cbd5e1;">Ref: RMCP Version 4 &mdash; 26/03/2026</p>
            <p style="margin-top: 1.5rem; color: #64748b; font-size: 0.75rem;">Johan and Elize Properties t/a Home Finders Coastal</p>
        </div>

        <div class="rmcp-body">

            {{-- Table of Contents --}}
            <div class="rmcp-section" id="toc">
                <h2>Table of Contents</h2>
                <?php
                    $tocItems = [
                        'definitions' => 'Definitions',
                        'introduction' => 'Introduction',
                        'services' => 'Our Business Services',
                        'risk-level' => 'Level of Risk as Accountable Institution',
                        'risks' => 'What Are Our Risks',
                        'who' => 'Who Must We Apply These Rules To',
                        'section-20a' => 'Compliance with Section 20A',
                        'identity' => 'Establishment and Verification of Identity',
                        'ongoing-dd' => 'Ongoing Due Diligence',
                        'additional-dd' => 'Additional Due Diligence — Legal Persons, Trusts, Partnerships',
                        'complex' => 'Complex or Unusually Large Transactions',
                        'unusual' => 'Unusual Patterns or Transactions',
                        'basic-cdd' => 'Identification of Clients and Basic CDD',
                        'legal-persons' => 'Additional Requirements — Legal Persons',
                        'partnerships' => 'Additional Requirements — Partnerships',
                        'trusts' => 'Additional Requirements — Trusts',
                        'tfs-screening' => 'Targeted Financial Sanctions Screening',
                        'retention' => 'Retention of Records',
                        'duty-report' => 'Duty to Report Suspicious and Unusual Transactions',
                        'inability' => 'Inability to Conduct Due Diligence',
                        'prominent' => 'Foreign and Domestic Prominent Persons',
                        'cdd-levels' => 'Different Levels of Customer Due Diligence',
                        'cooperation' => 'Co-operation with Other Accountable Institutions',
                        'reports' => 'How to Make Reports',
                        'implementation' => 'Implementation',
                        'officer' => 'FICA Compliance Officer',
                        'offences' => 'Offences and Penalties',
                        'reassessment' => 'Reassessment of Risk',
                    ];
                ?>
                <ol class="rmcp-toc">
                    @foreach($tocItems as $id => $title)
                        <li><a href="#rmcp-{{ $id }}">{{ $loop->iteration }}. {{ $title }}</a></li>
                    @endforeach
                    <li style="margin-top: 0.5rem; font-weight: 600; border-top: 2px solid #e2e8f0; padding-top: 0.5rem;"><a href="#rmcp-schedules">Schedules</a></li>
                    <li><a href="#rmcp-annexure-a">Annexure A: CDD Process Per Transaction Type</a></li>
                    <li><a href="#rmcp-acknowledgement">Staff Acknowledgement</a></li>
                </ol>
            </div>

            {{-- 1. Definitions --}}
            <div class="rmcp-section" id="rmcp-definitions">
                <h2>1. Definitions</h2>
                <ul>
                    <li><strong>"FICA"</strong> means the Financial Intelligence Centre Act, No 38 of 2001, as amended from time to time.</li>
                    <li><strong>"FIC"</strong> means the Financial Intelligence Centre, a juristic person created under chapter 2 of FICA.</li>
                    <li><strong>"RMCP"</strong> means the Risk Management and Compliance Programme contained in this document, which has been designed in response to our obligation under section 42 of FICA.</li>
                    <li><strong>"Company"</strong> means Johan and Elize Properties t/a Home Finders Coastal. This includes all agents and staff employed by the company.</li>
                    <li><strong>"Client"</strong> means any seller, buyer, owner, tenant, or holiday maker.</li>
                    <li><strong>"CDD"</strong> means Customer Due Diligence.</li>
                    <li><strong>"Buyer"</strong> means any natural person/s or legal entity/s who wants to procure a property through the company.</li>
                    <li><strong>"Seller"</strong> means any natural person/s or legal entity/s who wants to sell a property through the company.</li>
                    <li><strong>"Owner"</strong> means any natural person/s or legal entity/s that is registered as the owner of a property.</li>
                    <li><strong>"Tenant"</strong> means the natural person/s or legal entity/s that wants to lease a property through the Company.</li>
                    <li><strong>"Holiday maker"</strong> means the natural person/s or legal entity/s that wants to lease a property for holiday purposes.</li>
                    <li><strong>"Property"</strong> means a residential or commercial property.</li>
                    <li><strong>"OTP"</strong> means Offer to Purchase &mdash; the sales agreement that all buyers and sellers must complete to conduct business.</li>
                    <li><strong>"Declaration"</strong> means any questionnaire and/or buyer's/seller's declaration which will accompany any OTP/Lease agreement/rental application.</li>
                    <li><strong>"OATS"</strong> means Open Authority To Sell &mdash; the open mandate a seller (for sales) and owner (for rentals) must sign and submit to the agency before any advertising is activated on a property.</li>
                    <li><strong>"EATS"</strong> means Exclusive Authority To Sell &mdash; the exclusive mandate a seller must sign and submit to the agency before any advertising is activated on a property.</li>
                    <li><strong>"TFS"</strong> means Targeted Financial Sanctions as maintained by the Financial Intelligence Centre.</li>
                    <li><strong>"TVA"</strong> means The Virtual Agent (thevirtualagent.co.za).</li>
                    <li><strong>"Lightstone"</strong> means Lightstone Property (lightstoneproperty.co.za).</li>
                    <li><strong>"CMA"</strong> means CMA Info (cmainfo.co.za).</li>
                </ul>
            </div>

            {{-- 2. Introduction --}}
            <div class="rmcp-section" id="rmcp-introduction">
                <h2>2. Introduction</h2>
                <p>As Property Practitioner, we are an Accountable Institution and subject to the provisions of FICA.</p>
                <p>To comply with FICA, and its latest amendment, we are obliged to create a RMCP. The purpose of this RMCP is to identify and assess the risk that our clients might, during their relationship with us, be seeking to launder money or to finance terrorism or the proliferation of weapons of mass destruction. While it is obvious that the risks of money laundering and terrorist financing in our industry are relatively low when compared to other industries, we nevertheless take our obligations seriously and have compiled this document with the intention that it will become an integral part of our business practices.</p>
                <p>Our obligations also extend to monitor, mitigate and manage the risk of our products or services being utilized for these illegal practices.</p>
                <p>This document seeks to set out how we intend to comply with these obligations, and other related matters.</p>
                <div class="rmcp-highlight">Version 4 has been updated on 26 March 2026 to incorporate the General Laws (Anti-Money Laundering and Combating Terrorism Financing) Amendment Act 22 of 2022, including updated beneficial ownership thresholds (5%), targeted financial sanctions screening requirements, proliferation financing provisions, and employee verification procedures.</div>
            </div>

            {{-- 3. Our Business Services --}}
            <div class="rmcp-section" id="rmcp-services">
                <h2>3. Our Business Services</h2>
                <p>Listing and selling residential and commercial properties where we will interact with:</p>
                <ul><li>Buyers</li><li>Sellers</li></ul>
                <p>Listing and leasing out residential and commercial properties on a long term (3 months or longer) basis where we will interact with:</p>
                <ul><li>Owners</li><li>Tenants</li></ul>
            </div>

            {{-- 4. Level of Risk --}}
            <div class="rmcp-section" id="rmcp-risk-level">
                <h2>4. Level of Risk as Accountable Institution</h2>
                <p>The company operates on the Lower South Coast of KwaZulu Natal and mainly deals in residential property sales and residential property leasing.</p>
                <p>The company has implemented strict policies in terms of money handling which limits the risk on the company. Our specific risk analysis and policies include:</p>
                <ul>
                    <li>The company does not accept any cash payments at our offices for any services offered.</li>
                    <li>All staff members will report any potential client who offers to pay any funds in cash to the FICA Officer. The officer will investigate the matter and should the risk level of said client meet the minimum criteria a report will be filed on the FIC system.</li>
                    <li>All sales cash/EFT/bond funds to be deposited directly to conveyancing attorneys who are appointed on the transactions. The conveyancing attorneys will where necessary supply the company with relevant proof that CDD has been performed in terms of FICA.</li>
                    <li>Written communication will be sent to all conveyancers that the company deals with to instruct the conveyancers to inform the company of any cash payments which were made into the trust accounts of the conveyancer. The company will also ask the conveyancer for their internal FIC report, as well as any Cash Threshold Reports filed.</li>
                    <li>The long term letting property transactions that we deal with do not normally exceed R10 000 per single transaction on a monthly basis.</li>
                    <li>The company sales records for all transactions for a 1 year period averages 120 Offer to Purchases completed. This averages to 10 sales transactions per month.</li>
                    <li>The company long term letting book does not exceed 70 properties at any given time.</li>
                </ul>
            </div>

            {{-- 5. What Are Our Risks --}}
            <div class="rmcp-section" id="rmcp-risks">
                <h2>5. What Are Our Risks</h2>
                <ul>
                    <li>That a purchaser or tenant might be using money from an illicit source to purchase or rent property, thereby laundering the money.</li>
                    <li>That a tenant might be using a rented property for a business that launders money or finances terrorism.</li>
                    <li>That the owner of a property might be using income generated from the sale or rental of the property to finance terrorism.</li>
                    <li>That an owner of a property or a purchaser or tenant might be concealing their identity to avoid detection while they launder money or finance terrorism.</li>
                    <li>That a transaction may be used to finance the proliferation of weapons of mass destruction.</li>
                </ul>
                <p>This list is not final. As and when additional risks become apparent, this list shall be updated and expanded.</p>
            </div>

            {{-- 6. Who Must We Apply These Rules To --}}
            <div class="rmcp-section" id="rmcp-who">
                <h2>6. Who Must We Apply These Rules To</h2>
                <p>Proper FICA compliance procedures and our CDD procedures, in accordance with this document, must be conducted in respect of all clients and prospective clients, whenever we are approached by the client and it appears probable that we will enter into a business relationship with the client or conduct a single transaction with the client.</p>
                <ul>
                    <li><strong>Sellers:</strong> CDD performed when we list a property to put up for sale (includes OATS and EATS properties).</li>
                    <li><strong>Owners:</strong> CDD performed when we list a property to put up for long term leasing.</li>
                    <li><strong>Buyers:</strong> CDD performed when the buyer completes an OTP with the company.</li>
                    <li><strong>Tenants:</strong> CDD performed when the tenant submits the Rental Application Form.</li>
                </ul>
                <p>Proper FICA compliance procedures must be applied and completed in respect of all such clients before any contract is concluded or any money is paid or received by such a client, or by a representative of the client.</p>
                <p>These procedures must also be implemented and applied in respect of clients that we are currently concluding single transactions with and in respect of clients that we have an existing business relationship with.</p>
            </div>

            {{-- 7. Compliance with Section 20A --}}
            <div class="rmcp-section" id="rmcp-section-20a">
                <h2>7. Compliance with Section 20A</h2>
                <p>Our company is prohibited by section 20A of FICA from establishing a business relationship, or concluding a single transaction, with an anonymous client or client with an apparent false or fictitious name. Any employee who breaches this rule will be guilty of an offence and subject to disciplinary action.</p>
            </div>

            {{-- 8. Establishment and Verification of Identity --}}
            <div class="rmcp-section" id="rmcp-identity">
                <h2>8. Establishment and Verification of Identity</h2>
                <p>To establish and verify the identity of all of our clients and our prospective clients, we are obliged to utilize the Declarations contained in the schedule to this document and the listed procedures. These Declarations are to be completed in full.</p>
            </div>

            {{-- 9. Ongoing Due Diligence --}}
            <div class="rmcp-section" id="rmcp-ongoing-dd">
                <h2>9. Ongoing Due Diligence</h2>
                <p>We are obliged to continue to monitor existing clients and to take note when the client behaves in a manner inconsistent with our previous knowledge of the client. Circumstances in which this provision might be relevant include:</p>
                <ul>
                    <li>If a landlord instructs us to remit rentals into the account of an unknown 3rd party without a proper explanation.</li>
                    <li>If a tenant continues to rent either residential or commercial premises when you are aware that their legitimate source of income has ceased to exist.</li>
                    <li>Any other unexplained change of behavior that raises suspicion.</li>
                    <li>If a client requests changes to bank account details without proper verification.</li>
                    <li>If transaction patterns change materially from what was expected at onboarding.</li>
                </ul>
                <p>Proper FICA compliance procedures must be implemented and applied in respect of existing clients with whom we have a continuous business relationship, for example a landlord or a tenant, at least every 12 months, unless changing circumstances require more frequent review. Ongoing due diligence triggers include: change of bank details, change of address, change of beneficial ownership, or any material change in the client's circumstances.</p>
            </div>

            {{-- 10. Additional Due Diligence --}}
            <div class="rmcp-section" id="rmcp-additional-dd">
                <h2>10. Additional Due Diligence &mdash; Legal Persons, Trusts and Partnerships</h2>
                <p>When dealing with legal persons, trusts and partnerships, we are obliged to implement the additional due diligence measures and procedures as set out in the relevant declarations contained in the schedule to this document. The declarations are to be completed in full.</p>
            </div>

            {{-- 11. Complex or Unusually Large Transactions --}}
            <div class="rmcp-section" id="rmcp-complex">
                <h2>11. Complex or Unusually Large Transactions</h2>
                <p>In the event that we are asked to become party to or to facilitate any complex or unusually large transaction, we must be especially vigilant about the possibility that the parties involved might be attempting to launder money or finance terrorism. These transactions and the parties involved must be subjected to enhanced due diligence.</p>
            </div>

            {{-- 12. Unusual Patterns --}}
            <div class="rmcp-section" id="rmcp-unusual">
                <h2>12. Unusual Patterns or Transactions</h2>
                <p>It is our duty to remain on the alert for unusual patterns or transactions which have no apparent business or lawful purpose. An example of this would be where a tenant rents commercial premises and the business which he purports to conduct from the premises is clearly unable to sustain the rent.</p>
                <p>It is our duty to remain on the alert for unusual patterns in rental transactions where:</p>
                <ul>
                    <li>The purpose for which the property is required is unusual.</li>
                    <li>Whether the rental required and paid is market related.</li>
                    <li>Whether rental payments are made in advance.</li>
                    <li>Whether there are requests of transactions and/or refunds of money already paid.</li>
                </ul>
            </div>

            {{-- 13. Identification of Clients and Basic CDD --}}
            <div class="rmcp-section" id="rmcp-basic-cdd">
                <h2>13. Identification of Clients and Basic CDD</h2>
                <p>In carrying out our basic FICA verification and CDD investigations, we must utilize the declarations contained in the schedule to this document and the procedures listed therein.</p>
                <p>When we engage with the prospective client to enter into a single transaction or to establish a business relationship we must, during the course of concluding that single transaction or establishing that business relationship, but before any transaction is finalized and before any money changes hands:</p>
                <ul>
                    <li>Establish and verify the identity of the client.</li>
                    <li>If the client is acting on behalf of another person, establish and verify the identity of that other person and the client's authority to act on their behalf.</li>
                    <li>If another person is acting on behalf of the client, establish and verify the identity of that other person and their authority to act on behalf of the client.</li>
                    <li>Establish whether the transaction is consistent with our knowledge of that client.</li>
                    <li>Establish the source of funds that the prospective clients intend to use in concluding the transaction/s.</li>
                </ul>
                <p>This is the most basic level of due diligence and FICA compliance that is expected when dealing with persons and transactions at the lowest risk level.</p>
            </div>

            {{-- 14. Additional Requirements — Legal Persons --}}
            <div class="rmcp-section" id="rmcp-legal-persons">
                <h2>14. Additional Requirements &mdash; Legal Persons</h2>
                <p>If our client is a legal person, a trust or a partnership, we must establish:</p>
                <ul>
                    <li>The nature of the client's business.</li>
                    <li>The ownership and control structure of the client.</li>
                    <li>The identity of the beneficial owner of the client.</li>
                </ul>
                <div class="rmcp-highlight">The identity of the beneficial owner must be established by determining the identity of each natural person who has a controlling ownership interest of <strong>5% or more</strong> in the client. If there is doubt as to who this might be, the identity of each natural person who exercises de facto control of the company must be established. If it is still not possible to establish the beneficial owner, the identity of the senior management must be established.</div>
                <p>The identity of the beneficial owner of the client must also be verified.</p>
            </div>

            {{-- 15. Additional Requirements — Partnerships --}}
            <div class="rmcp-section" id="rmcp-partnerships">
                <h2>15. Additional Requirements &mdash; Partnerships</h2>
                <ul>
                    <li>Establish the name of the partnership.</li>
                    <li>Establish the identity of every partner, including silent partners.</li>
                    <li>Establish the identity of the person who exercises executive control over the partnership.</li>
                    <li>Establish the identity of the person who is authorised to represent or bind the partnership.</li>
                    <li>Verify the identities of the persons so identified.</li>
                </ul>
            </div>

            {{-- 16. Additional Requirements — Trusts --}}
            <div class="rmcp-section" id="rmcp-trusts">
                <h2>16. Additional Requirements &mdash; Trusts</h2>
                <ul>
                    <li>Establish the identifying name and number of the trust.</li>
                    <li>Establish the address of the Master of the High Court where the trust is registered.</li>
                    <li>Establish the identity of the founder of the trust.</li>
                    <li>Establish the identity of each trustee and of any person who is authorized to represent or bind the trust.</li>
                    <li>Establish the identity of each beneficiary referred to in the trust deed or other document which created the trust.</li>
                    <li>If there are no named beneficiaries, establish how the beneficiaries of the trust are to be determined.</li>
                    <li>Verify the information obtained about the trust and the identities of the natural persons that have been so identified.</li>
                </ul>
            </div>

            {{-- 17. Targeted Financial Sanctions Screening --}}
            <div class="rmcp-section" id="rmcp-tfs-screening">
                <h2>17. Targeted Financial Sanctions Screening</h2>
                <p>All clients and prospective clients must be screened against the Targeted Financial Sanctions (TFS) list maintained by the Financial Intelligence Centre before entering into any business relationship or concluding any single transaction.</p>
                <p>The TFS list is available at <strong>tfs.fic.gov.za</strong> and is searchable by person name, identification number, and entity name.</p>
                <p>Screening must be conducted:</p>
                <ul>
                    <li>During client onboarding (before any business relationship is established).</li>
                    <li>When the UNSC adopts new TFS measures or expands existing ones.</li>
                    <li>Periodically as part of ongoing due diligence.</li>
                </ul>
                <p>If a potential match is identified, the matter must be escalated immediately to the FICA Compliance Officer and senior management. A suspicious transaction report and/or terrorist property report must be filed with the FIC. All property connected to a matched person or entity must be frozen and no further business conducted until cleared.</p>
                <p>The company utilises the CoreX OS compliance module to facilitate TFS screening as part of the FICA verification workflow.</p>
            </div>

            {{-- 18. Retention of Records --}}
            <div class="rmcp-section" id="rmcp-retention">
                <h2>18. Retention of Records</h2>
                <p>By their very nature, the results of our FICA enquiries are privileged personal records relating to our customers. As such they deserve the highest levels of protection.</p>
                <p>No FICA records are to be removed from the file for any reason without the authority of the FICA compliance officer and no such records to be made available to any third party without the consent of the client to whom they relate, unless required by law.</p>
                <p>FICA records are to be retained on the individual transaction file in our office and in our archives where we keep our old files, for a period of <strong>5 years</strong>. If a report is made, the FICA records must be retained for a period of 5 years from the date of the report.</p>
                <p>Risk rating decisions and supporting reasoning must be documented and retained on the client file for audit purposes.</p>
            </div>

            {{-- 19. Duty to Report --}}
            <div class="rmcp-section" id="rmcp-duty-report">
                <h2>19. Duty to Report Suspicious and Unusual Transactions</h2>
                <p>We all have a duty to report suspicious and unusual transactions which we know about, or of which we ought reasonably to have known about.</p>
                <p>Our duty to report arises if we know or even if we just suspect:</p>
                <ul>
                    <li>That one of the parties to the transaction will be using or receiving the proceeds of unlawful activities; or</li>
                    <li>That a property which is connected to an offence relating to money laundering or the financing of terrorist and related activities or the proliferation of weapons of mass destruction will be changing hands; or</li>
                    <li>If the transaction has no apparent business or lawful purpose; or</li>
                    <li>The transaction is designed for the purposes of avoiding giving rise to a reporting duty under FICA; or</li>
                    <li>The conduct of the parties may be related to the evasion or attempted evasion of a duty to pay any tax or duty or levy due to SARS or relates to money laundering or the financing of terrorist or related activities.</li>
                </ul>
                <p>Once we are aware of the suspicious or unusual transaction, this must be reported immediately. You have a duty to approach our FICA Compliance Officer and to make a full disclosure of the facts and circumstances.</p>
                <p><strong>You are not allowed to disclose any details about the suspicious or unusual transaction or your report to anyone else</strong>, unless this is required in terms of FICA, or for the purposes of legal proceedings or in terms of an order of court. You are specifically not entitled to disclose any details about the report to the client who is the subject of the report.</p>
            </div>

            {{-- 20. Inability to Conduct Due Diligence --}}
            <div class="rmcp-section" id="rmcp-inability">
                <h2>20. Inability to Conduct Due Diligence</h2>
                <p>If we are unable to comply with our FICA compliance and client due diligence obligations in respect of any client, we may not enter into a business relationship or conclude a single transaction with the client. We may also not conclude a transaction during the business relationship, or perform any act to give effect to a single transaction with that client.</p>
                <p>The termination of this relationship shall be effected by way of a written communication from senior management advising the client that the business relationship has been terminated as a result of our inability to conduct FICA compliance procedures and client due diligence.</p>
                <p>In the event that the inability to conduct the client due diligence comes about as a result of suspicious or unusual behaviour in suspicious or unusual circumstances, we are obliged to report the client to the FIC.</p>
            </div>

            {{-- 21. Foreign and Domestic Prominent Persons --}}
            <div class="rmcp-section" id="rmcp-prominent">
                <h2>21. Foreign and Domestic Prominent Persons</h2>
                <p>These categories of people must be given special treatment in terms of FICA. If we are dealing with Foreign Prominent Public Officials, or their family members or known close associates, these people are automatically high risk and:</p>
                <ul>
                    <li>You will require senior management approval to establish the business relationship; and</li>
                    <li>You will need to establish the source of wealth of the client and his or her source of funds to conclude the transaction; and</li>
                    <li>Enhanced ongoing monitoring of the business relationship must be conducted.</li>
                </ul>
                <p>In the event that your basic FICA verification and client due diligence investigations in respect of a Domestic Prominent Influential Person and/or their family members and their known close associates shows a higher risk, the same enhanced steps must be implemented.</p>
            </div>

            {{-- 22. Different Levels of CDD --}}
            <div class="rmcp-section" id="rmcp-cdd-levels">
                <h2>22. Different Levels of Customer Due Diligence</h2>
                <p>As per Section 22B of the Act, and Section 28, any single transaction or an aggregate of smaller amounts which combined exceeds R49 999.99 must be reported to FIC within the prescribed period.</p>
                <p>A report under section 28 of the FIC Act must be sent to the Centre as soon as possible but no later than 2 (two) days after becoming aware of the cash transaction.</p>
                <p>When dealing with a client that is entering into a single transaction with a value of less than R5 000, no customer due diligence is required. For all other clients we are obliged to carry out our standard customer due diligence procedures.</p>
                <p>A <strong>high-risk client</strong> is any client:</p>
                <ul>
                    <li>Who is a natural person, but is not a citizen or permanent resident of South Africa; or</li>
                    <li>That has no operations or premises in South Africa; or</li>
                    <li>That cannot or will not produce the due diligence documents or information required, and cannot provide a proper explanation for this failure; or</li>
                    <li>Who is a party to an unusual or complicated transaction; or</li>
                    <li>Who is, in the discretion of the employee or compliance officer, suspect.</li>
                </ul>
                <p>Where enhanced due diligence procedures are required, these shall be designed on an ad hoc basis, in consultation with the Compliance Officer and the highest levels of management, with regard to the risk that they are intended to mitigate.</p>
            </div>

            {{-- 23. Co-operation --}}
            <div class="rmcp-section" id="rmcp-cooperation">
                <h2>23. Co-operation with Other Accountable Institutions</h2>
                <p>If we have a client in common with another Accountable Institution, such as a bank or a conveyancing attorney (a Secondary Accountable Institution); and that client in common is in respect of the same transaction, and the Secondary Accountable Institution agrees to subject, or has already subjected the Client to customer due diligence procedures in accordance with that institution's own RMCP; and the institution agrees to furnish us with a letter confirming compliance &mdash; then we may rely on the letter and documents provided, and thus be regarded as having complied with FICA and the RMCP.</p>
                <p>If the letter does not cover all the information required in terms of our RMCP, we must supplement the information by means of our own customer due diligence.</p>
                <p>The term "Secondary Accountable Institution" includes analogous Accountable Institutions based outside South Africa provided that they are situated in other Financial Action Task Force Member States.</p>
            </div>

            {{-- 24. How to Make Reports --}}
            <div class="rmcp-section" id="rmcp-reports">
                <h2>24. How to Make Reports</h2>
                <p>Our reports are made on-line directly to the FIC via the goAML portal at <strong>goaml.fic.gov.za</strong>. These on-line reports are to be made by the FICA Compliance Officer in conjunction with the senior management of the company.</p>
                <p>Access credentials are held by the FICA Compliance Officer. Reports must be filed within the prescribed timeframes: suspicious transaction reports within 15 days, cash threshold reports within 2 days.</p>
            </div>

            {{-- 25. Implementation --}}
            <div class="rmcp-section" id="rmcp-implementation">
                <h2>25. Implementation</h2>
                <p>All staff members are to be made aware of the existence of this RMCP and each staff member shall receive either hardcopy or a digital copy. Each staff member must sign the declaration contained in the schedules confirming that they have read the RMCP and furnishing the undertaking to enforce its terms.</p>
                <p>This RMCP, and all amendments hereto, shall be approved by our board of directors.</p>
            </div>

            {{-- 26. FICA Compliance Officer --}}
            <div class="rmcp-section" id="rmcp-officer">
                <h2>26. FICA Compliance Officer</h2>
                <p>Until further notice, our FICA compliance officer shall be: <strong>Elize Reichel</strong> (7012310053085)</p>
                <p>It is the duty of our FICA compliance officer:</p>
                <ul>
                    <li>To ensure that our registration with the FIC is and remains up-to-date.</li>
                    <li>To implement the terms of this RMCP at our main office and any other branches that we might operate from.</li>
                    <li>To provide ongoing training to employees to enable them to comply with FICA and the RMCP.</li>
                    <li>In collaboration with management, to appoint a suitable person at any branch to ensure that the terms of this RMCP are implemented at branch level.</li>
                    <li>To make reports.</li>
                </ul>
            </div>

            {{-- 27. Offences and Penalties --}}
            <div class="rmcp-section" id="rmcp-offences">
                <h2>27. Offences and Penalties</h2>
                <p>Failure to comply with this RMCP and/or the Financial Intelligence Centre Act is an offence punishable by an internal sanction of this company and by harsh fines and prison sentences in terms of the Act. These fines can amount to <strong>R50 million</strong>, and the prison sentences can last for up to <strong>5 years</strong>. No employee can afford to be lax or careless when it comes to these matters.</p>
            </div>

            {{-- 28. Reassessment of Risk --}}
            <div class="rmcp-section" id="rmcp-reassessment">
                <h2>28. Reassessment of Risk</h2>
                <p>This RMCP shall be updated at intervals of no more than five years. This shall however not detract from the duty of management to update and amend this RMCP as and when additional risks, or risk management steps are identified.</p>
            </div>

            {{-- ══════════════ SCHEDULES ══════════════ --}}
            <div class="rmcp-section" id="rmcp-schedules">
                <h2>Schedules</h2>

                <div class="rmcp-schedule">
                    <h3>Schedule 1: Domestic Prominent Influential Persons (DPIPs)</h3>
                    <p>A domestic prominent influential person is an individual who holds, including in an acting position for a period exceeding six months, or has held at any time in the preceding 12 months, in the Republic:</p>
                    <ul>
                        <li>The President or Deputy President</li>
                        <li>A government minister or deputy minister</li>
                        <li>The Premier of a province</li>
                        <li>A member of the Executive Council of a province</li>
                        <li>An executive mayor of a municipality</li>
                        <li>A leader of a political party registered in terms of the Electoral Commission Act</li>
                        <li>A member of a royal family or senior traditional leader</li>
                        <li>The head, accounting officer or chief financial officer of a national or provincial department</li>
                        <li>The municipal manager of a municipality, or a chief financial officer</li>
                        <li>The chairperson of the controlling body, the chief executive officer, or a natural person who is the accounting authority, the chief financial officer or the chief investment officer of a public entity listed in Schedule 2 or 3 to the Public Finance Management Act</li>
                        <li>The chairperson of the controlling body, chief executive officer, chief financial officer or chief investment officer of a municipal entity</li>
                        <li>A constitutional court judge or any other judge</li>
                        <li>An ambassador or high commissioner or other senior representative of a foreign government based in the Republic</li>
                        <li>An officer of the South African National Defence Force above the rank of major-general</li>
                        <li>The chairperson of the board of directors, chairperson of the audit committee, executive officer, or chief financial officer of a company providing goods or services to an organ of state where the annual transactional value exceeds the amount determined by the Minister</li>
                        <li>The head, or other executive directly accountable to that head, of an international organisation based in the Republic</li>
                    </ul>
                </div>

                <div class="rmcp-schedule">
                    <h3>Schedule 2: Foreign Prominent Public Officials (FPPOs)</h3>
                    <p>A foreign prominent public official is an individual who holds, or has held at any time in the preceding 12 months, in any foreign country a prominent public function including:</p>
                    <ul>
                        <li>Head of State or head of a country or government</li>
                        <li>Member of a foreign royal family</li>
                        <li>Government minister or equivalent senior politician or leader of a political party</li>
                        <li>Senior judicial official</li>
                        <li>Senior executive of a state owned corporation</li>
                        <li>High-ranking member of the military</li>
                    </ul>
                </div>

                <div class="rmcp-schedule">
                    <h3>Schedule 3: Immediate Family Members</h3>
                    <p>Immediate family members of DPIPs and FPPOs include, but are not limited to:</p>
                    <ul>
                        <li>Their spouse, civil partner or life partner</li>
                        <li>Their previous spouse, civil partner or life partner</li>
                        <li>Children and step-children and their spouse, civil partner or life partner</li>
                        <li>Their parents</li>
                        <li>Siblings or step-siblings and their spouse, civil partner or life partner</li>
                    </ul>
                </div>

                <div class="rmcp-schedule">
                    <h3>Schedules 4&ndash;7: FICA Questionnaires</h3>
                    <p>The FICA questionnaires for Natural Persons (Schedule 4), Companies and Close Corporations (Schedule 5), Partnerships (Schedule 6), and Trusts (Schedule 7) are administered electronically through the CoreX OS FICA Compliance module. These questionnaires comply with sections 21, 21A, 21B, 21F, 21G and 21H of the Financial Intelligence Centre Act.</p>
                    <a href="{{ route('compliance.fica.create') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-600 hover:text-teal-800 mt-1">
                        Send a FICA Request
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

                <div class="rmcp-schedule">
                    <h3>Schedule 8: Employee Verification and Vetting</h3>

                    <h3>8.1 Pre-Employment Screening</h3>
                    <p>Before hiring any agent or staff member, the following verifications must be completed:</p>
                    <ul>
                        <li>Verify identity (SA ID document or foreign passport)</li>
                        <li>Verify residential address (proof of address less than 2 months old)</li>
                        <li>Criminal record check (police clearance certificate)</li>
                        <li>Credit check</li>
                        <li>PPRA registration verification (valid Fidelity Fund Certificate)</li>
                        <li>Qualification verification</li>
                        <li>Reference checks from previous employers</li>
                    </ul>

                    <h3>8.2 Targeted Financial Sanctions Screening</h3>
                    <p>Every employee must be screened against the TFS list maintained by the FIC before appointment and periodically thereafter. The TFS list is available at tfs.fic.gov.za.</p>

                    <h3>8.3 Ongoing Monitoring</h3>
                    <ul>
                        <li>Annual re-screening against TFS list</li>
                        <li>Annual FFC validity check</li>
                        <li>Report any employee behaviour that raises suspicion</li>
                        <li>Re-verify if employee circumstances change materially (change of name, nationality, etc.)</li>
                    </ul>

                    <h3>8.4 Record Keeping</h3>
                    <p>All screening results must be kept on the employee file. Records must be retained for 5 years after employment ends.</p>

                    <h3>8.5 Training Records</h3>
                    <ul>
                        <li>Initial FICA training within 30 days of appointment</li>
                        <li>Annual refresher training</li>
                        <li>Signed acknowledgement per training session</li>
                        <li>Training register maintained by the FICA Compliance Officer</li>
                    </ul>
                </div>
            </div>

            {{-- Annexure A --}}
            <div class="rmcp-section" id="rmcp-annexure-a">
                <h2>Annexure A: CDD Process Per Transaction Type</h2>

                <h3>Risk Rating Process</h3>
                <p>Home Finders Coastal uses a 3-tiered risk rating:</p>
                <ul>
                    <li><strong>Low Risk:</strong> A client who provides all relevant documentation, is a South African citizen, does not form part of any prominent persons categories, and whose details match verification through TVA/Lightstone. Low Risk clients are monitored as per standard procedures.</li>
                    <li><strong>Medium Risk (DEFAULT):</strong> All clients start at Medium Risk. A client who provides all documentation, is a South African citizen, does not form part of any prominent persons categories, but whose details show minor discrepancies with TVA/Lightstone verification. Medium risk clients may be downgraded to Low after successful monitoring period.</li>
                    <li><strong>High Risk:</strong> Includes prominent persons, non-South African citizens, members of high-risk states as identified by FATF. High risk clients will only be dealt with from a senior management level who will verify identification and address details and provide a written report. High risk clients will be reported through the goAML portal to FIC within prescribed timeframes.</li>
                </ul>

                <h3>Sales Transactions &mdash; Seller CDD</h3>
                <ol>
                    <li>Agent and seller make contact. Seller expresses need to sell property.</li>
                    <li>Agent draws Lightstone report on property and confirms ownership.</li>
                    <li>Agent acquires seller's ID, signed mandate and proof of residence.</li>
                    <li>Documentation processed through TVA for verification.</li>
                    <li>Reports attached to listing and filed.</li>
                    <li>Medium risk client downgraded only with all relevant checks done.</li>
                    <li>Any discrepancies reported and client upgraded to high risk.</li>
                    <li>TFS screening conducted via CoreX OS compliance module.</li>
                </ol>

                <h3>Sales Transactions &mdash; Buyer CDD</h3>
                <ol>
                    <li>Prospective buyer makes contact showing interest.</li>
                    <li>Before or during OTP completion, client provides identity numbers and address.</li>
                    <li>ID and address verified through TVA and/or Lightstone.</li>
                    <li>Reports placed in transaction file for risk management officer verification.</li>
                    <li>If no discrepancies, client given low risk status.</li>
                    <li>Any discrepancy escalated to senior management; if unresolved, client upgraded to high risk.</li>
                    <li>TFS screening conducted via CoreX OS compliance module.</li>
                </ol>

                <h3>Long Term Leasing &mdash; Owner CDD</h3>
                <ol>
                    <li>Company sources rental property or owner makes contact.</li>
                    <li>Company visits and lists property.</li>
                    <li>Owner completes mandate, provides proof of residence and ID.</li>
                    <li>ID and address verified through Lightstone and TVA.</li>
                    <li>If no discrepancies, client given low risk rating.</li>
                    <li>Discrepancies investigated by senior management.</li>
                    <li>TFS screening conducted via CoreX OS compliance module.</li>
                </ol>

                <h3>Long Term Leasing &mdash; Tenant CDD</h3>
                <ol>
                    <li>Prospective tenant contacts company.</li>
                    <li>Company shows rental properties.</li>
                    <li>Tenant completes rental application including proof of residence, income and bank statements.</li>
                    <li>Bank statement and income scrutinised against declared profession/business.</li>
                    <li>Tenant details verified through TVA.</li>
                    <li>If no discrepancies and purpose is not suspicious, tenant downgraded to low risk.</li>
                    <li>Lease includes clause prohibiting business from residential premises without written consent.</li>
                    <li>Medium risk upgraded to high risk if discrepancies in address or ID verification.</li>
                    <li>TFS screening conducted via CoreX OS compliance module.</li>
                </ol>
            </div>

            {{-- Staff Acknowledgement --}}
            <div class="rmcp-section" id="rmcp-acknowledgement">
                <h2>Staff Acknowledgement</h2>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.5rem; line-height: 1.8;">
                    <p>I, ______________________ (FULL NAME), hereby declare the following:</p>
                    <p>I have read the contents of this RMCP, which has been distributed or otherwise made available to me, and I have also attended the necessary training workshops offered by HOME FINDERS COASTAL in this regard; and</p>
                    <p>I acknowledge that to the extent that I do not understand any of my duties under the RMCP, I have contacted the FICA Compliance Officer for clarification; and</p>
                    <p>I undertake to observe strictly and diligently all my duties imposed by FICA and the RMCP, fully understanding that my failure to do so:</p>
                    <ul>
                        <li>will potentially expose HOME FINDERS COASTAL to unacceptable risk, as well as financial and reputational risk from the penalties that may be levied by the FIC against the Business for any instances of non-compliance with FICA and the RMCP; and</li>
                        <li>is a criminal offence in terms of FICA, and constitutes serious misconduct in terms of the Business' disciplinary code.</li>
                    </ul>
                    <div style="margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <div><p>Signature: ______________________</p><p>Date: ______________________</p></div>
                        <div><p>Witness: ______________________</p><p>Date: ______________________</p></div>
                    </div>
                </div>
            </div>

            {{-- Approval --}}
            <div class="rmcp-section">
                <h2>Approval</h2>
                <p>This Risk Management and Compliance Programme has been approved for use at Home Finders Coastal.</p>
                <p style="color: #64748b; font-size: 0.875rem; font-style: italic;">Board approval signatures to be captured separately.</p>
            </div>

            {{-- Footer --}}
            <div style="margin-top: 3rem; padding-top: 1.5rem; border-top: 2px solid #0d9488; text-align: center;">
                <p style="font-size: 0.8125rem; color: #64748b;">Last updated: 26 March 2026 &mdash; Version 4</p>
                <p style="font-size: 0.8125rem; color: #94a3b8; margin-top: 0.25rem;">This document must be reviewed at intervals of no more than five years. Next review due: 26 March 2031.</p>
            </div>
        </div>
    </div>
</div>
@endsection
