<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JR Rental mandate test import — Home Finders Coastal</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 20mm 15mm 20mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #1a1a1a;
            background: white;
        }

        p {
            margin: 0 0 2pt 0;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 20mm 15mm 20mm;
            background: white;
        }

        @media screen {
            body {
                background: #e5e7eb;
            }
            .page {
                box-shadow: 0 2px 16px rgba(0,0,0,0.15);
                margin-top: 20px;
                margin-bottom: 20px;
            }
        }

        @media print {
            body { background: white; }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }

        .field {
            display: inline-block;
            min-width: 120pt;
            border-bottom: 1px solid #333;
            padding: 1pt 4pt;
            min-height: 14pt;
        }

        .field-short {
            min-width: 40pt;
        }

        strong { font-weight: bold; }
        em { font-style: italic; }
    </style>
</head>
<body>
<div class="page">
    @include('docuperfect.web-templates.components.company-header')

<p><span style="font-size:10pt;"><u><strong>Johan and Elize Properties T/A</strong></u></span></p>
<p style="text-align:center;"> </p>
<p> </p>
<p><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Shop 5 The Emporium, </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>cnr</strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong> King Rd </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>&amp; Marine Drive, Shelly Beach          </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                               </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>     </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Fax No: </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>086 514 7632</strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>  </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>   </strong></span></p>
<p><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Reg</strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong> no:   2017/431318/07                                                                      </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                    </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>   </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                  FFC: 202615038880000</strong></span></p>
<p><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Vat: 463087821</strong></span></p>
<p><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Email Address:    </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>@hfcoastal.co.za</strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                              </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                       </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>                               FIC AI/180629/0000019 </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>   </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>   </strong></span><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>         </strong></span></p>
<p><span style="font-size:9pt;font-family:'Arial',sans-serif;"><strong>Elize Reichel Cell:  071 351 0291                                                                                  Johan Reichel Cell: 076 618 5578</strong></span></p>
<p> </p>
<p style="text-align:center;"><span style="font-family:'Arial',sans-serif;"><strong>Mandate entered into between</strong></span></p>
<p style="text-align:center;"> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;"><strong>The Parties</strong></span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">Owner</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">/s:</span><span class="field" data-field="contact.lessor_name">{{ $contact_lessor_name ?? '' }}</span>___</p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Home Finders Coastal (Agent)</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">:</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">_</span><span class="field" data-field="contact.lessor_name">{{ $contact_lessor_name ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;"> The owner hereby grants to the Agent a Mandate to offer to let the property known </span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">as</span><span class="field" data-field="deal.bank_name">{{ $deal_bank_name ?? '' }}</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">subject</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> to the conditions set out in this agreement.</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The rental amount required by the Owner for the property is R________</span><span class="field" data-field="deal.account_number">{{ $deal_account_number ?? '' }}</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">_______ which includes the commission as stated in clause 4.  In the event of the Agency not finding a suitable Tenant to rent the property</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> at such rental amount, then, between the Owner and the Agency they will agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said property, which includes commission as stated in clause 4.</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The sole mandate hereby granted shall commence on date of signature hereof and shall remain in force until 22h00 on the ________</span><span class="field" data-field="custom.field_7">{{ $custom_field_7 ?? '' }}</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">_day of </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">_________________________20_________</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The Owner will pay to the Agent a commission, calculated at a percentage of ___</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">__</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">__</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">__% </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">plus VAT</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">on the letting price of the property.</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The Agency will screen all possible tenants prior to occupation to ensure a hassle free letting of the property.</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">The Agent will deposit the monthly rental collections into the following Bank Account supplied by the Owner, by no later than the 7</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">th</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> day of every month.</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Account Holder's Name:</span><span class="field-blank field-blank-highlight" data-raw="____________________________________________" data-field-index="4" data-confidence="low" contenteditable="false" style="cursor: pointer;">Account Number<button class="field-remove-btn" type="button">×</button></span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Bank Name:</span><span class="field" data-field="custom.field_8">{{ $custom_field_8 ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Account Number:</span><span class="field" data-field="contact.lessor_name">{{ $contact_lessor_name ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Branch Name and Code:</span><span class="field" data-field="custom.field_9">{{ $custom_field_9 ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Owner's Contact details:</span><span class="field" data-field="custom.field_14">{{ $custom_field_14 ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Owner's Email Address:</span><span class="field" data-field="agent.agent_name">{{ $agent_agent_name ?? '' }}</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;"> </span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">The Owner shall supply the Agency with water and lights service usage charges every month, so the Agency may add this to the statement forwarded to the Tenant</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">.</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">This A</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">greement </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">has been accepted and signed by the </span><span style="font-size:10pt;font-family:'Arial',sans-serif;">Owner/s</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> at _________________________</span></p>
<p> </p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">on</span><span style="font-size:10pt;font-family:'Arial',sans-serif;"> this ________ day of ______________________ at _______________(am / pm)</span></p>
<p> </p>
<p> </p>
<p> </p>
<p><span style="font-size: 10pt; font-family: Arial, sans-serif;"><span class="signature-block-pill" contenteditable="false">✍ Signature Block</span><span class="signature-block-pill" contenteditable="false">✍ Signature Block</span><span class="signature-block-pill" contenteditable="false">✍ Signature Block</span><br></span></p><p><span style="font-size: 10pt; font-family: Arial, sans-serif;">          Owner                       </span><span style="font-size: 10pt; font-family: Arial, sans-serif;">Owner                         </span><span style="font-size: 10pt; font-family: Arial, sans-serif;">Agent</span></p>
<p> </p>
<p><span class="field" data-field="property.address">{{ $property_address ?? '' }}</span><span class="field-blank" data-raw="_______________________" data-field-index="14">_______________________</span><span class="field-blank" data-raw="__________________" data-field-index="15">__________________</span></p>
<p><span style="font-size:10pt;font-family:'Arial',sans-serif;">Print Name</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">Print Name</span><span style="font-size:10pt;font-family:'Arial',sans-serif;">Print name</span></p>

    @include('docuperfect.web-templates.components.signature-block')
</div>
</body>
</html>