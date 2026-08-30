<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <title>
        Payment Receipt - {{ $receipt['receipt_number'] }}
    </title>

    <style>
        /*
        |--------------------------------------------------------------------------
        | PDF BASE
        |--------------------------------------------------------------------------
        */

        @page {
            margin: 28px 32px 35px 32px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;

            font-family: DejaVu Sans, sans-serif;

            font-size: 10px;
            line-height: 1.5;

            color: #1f2937;

            background: #ffffff;
        }

        /*
        |--------------------------------------------------------------------------
        | DOCUMENT
        |--------------------------------------------------------------------------
        */

        .document {
            width: 100%;
        }

        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        .header {
            width: 100%;
            padding-bottom: 18px;

            border-bottom: 2px solid #111827;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-left {
            width: 65%;
            vertical-align: top;
        }

        .header-right {
            width: 35%;
            vertical-align: top;
            text-align: right;
        }

        .municipality-name {
            margin: 0;

            font-size: 18px;
            line-height: 1.3;

            font-weight: bold;

            color: #111827;
        }

        .municipality-address {
            margin-top: 5px;

            font-size: 9px;

            color: #6b7280;
        }

        .receipt-title {
            margin: 0;

            font-size: 16px;

            font-weight: bold;

            text-transform: uppercase;

            color: #111827;
        }

        .official-label {
            margin-top: 5px;

            font-size: 8px;

            text-transform: uppercase;

            letter-spacing: 1px;

            color: #6b7280;
        }

        /*
        |--------------------------------------------------------------------------
        | RECEIPT NUMBER
        |--------------------------------------------------------------------------
        */

        .receipt-meta {
            margin-top: 18px;

            width: 100%;

            border-collapse: collapse;
        }

        .receipt-meta td {
            padding: 5px 0;

            vertical-align: top;
        }

        .meta-label {
            width: 32%;

            font-weight: bold;

            color: #6b7280;
        }

        .meta-value {
            width: 68%;

            color: #111827;
        }

        .receipt-number {
            font-size: 12px;

            font-weight: bold;

            letter-spacing: 0.5px;
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .status {
            display: inline-block;

            padding: 4px 10px;

            border: 1px solid #15803d;

            color: #15803d;

            font-size: 8px;

            font-weight: bold;

            text-transform: uppercase;

            letter-spacing: 0.8px;
        }

        /*
        |--------------------------------------------------------------------------
        | SECTION
        |--------------------------------------------------------------------------
        */

        .section {
            margin-top: 20px;
        }

        .section-title {
            margin: 0 0 8px 0;

            padding-bottom: 5px;

            border-bottom: 1px solid #d1d5db;

            font-size: 11px;

            font-weight: bold;

            text-transform: uppercase;

            letter-spacing: 0.5px;

            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | INFORMATION TABLE
        |--------------------------------------------------------------------------
        */

        .info-table {
            width: 100%;

            border-collapse: collapse;

            table-layout: fixed;
        }

        .info-table td {
            width: 50%;

            padding: 7px 9px;

            vertical-align: top;

            border-bottom: 1px solid #e5e7eb;
        }

        .info-table td:first-child {
            border-right: 1px solid #e5e7eb;
        }

        .field-label {
            display: block;

            margin-bottom: 2px;

            font-size: 8px;

            color: #6b7280;

            text-transform: uppercase;

            letter-spacing: 0.3px;
        }

        .field-value {
            display: block;

            font-size: 10px;

            font-weight: bold;

            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | AMOUNT BOX
        |--------------------------------------------------------------------------
        */

        .amount-section {
            margin-top: 22px;
        }

        .amount-table {
            width: 100%;

            border-collapse: collapse;

            background: #f9fafb;

            border: 1px solid #d1d5db;
        }

        .amount-label {
            width: 65%;

            padding: 13px;

            font-size: 10px;

            font-weight: bold;

            text-transform: uppercase;

            color: #374151;
        }

        .amount-value {
            width: 35%;

            padding: 13px;

            text-align: right;

            font-size: 17px;

            font-weight: bold;

            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | INVOICE
        |--------------------------------------------------------------------------
        */

        .invoice-table {
            width: 100%;

            border-collapse: collapse;

            border: 1px solid #d1d5db;
        }

        .invoice-table th {
            padding: 8px;

            text-align: left;

            font-size: 8px;

            text-transform: uppercase;

            letter-spacing: 0.4px;

            color: #4b5563;

            background: #f3f4f6;

            border-bottom: 1px solid #d1d5db;
        }

        .invoice-table td {
            padding: 9px;

            border-bottom: 1px solid #e5e7eb;

            color: #111827;
        }

        /*
        |--------------------------------------------------------------------------
        | PROVIDER INFORMATION
        |--------------------------------------------------------------------------
        */

        .provider-box {
            margin-top: 20px;

            padding: 10px 12px;

            border: 1px solid #d1d5db;

            background: #ffffff;
        }

        .provider-table {
            width: 100%;

            border-collapse: collapse;
        }

        .provider-table td {
            width: 50%;

            padding: 4px 0;

            vertical-align: top;
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFICATION
        |--------------------------------------------------------------------------
        */

        .verification {
            margin-top: 22px;

            padding: 12px;

            border: 1px solid #d1d5db;

            background: #fafafa;
        }

        .verification-title {
            margin-bottom: 5px;

            font-weight: bold;

            font-size: 9px;

            text-transform: uppercase;
        }

        .verification-text {
            margin: 0;

            font-size: 8px;

            line-height: 1.6;

            color: #6b7280;
        }

        /*
        |--------------------------------------------------------------------------
        | FOOTER
        |--------------------------------------------------------------------------
        */

        .footer {
            margin-top: 28px;

            padding-top: 12px;

            border-top: 1px solid #d1d5db;

            text-align: center;

            color: #6b7280;

            font-size: 8px;
        }

        .footer-main {
            margin-bottom: 4px;

            font-weight: bold;

            color: #374151;
        }

        .footer-small {
            font-size: 7px;
        }

        /*
        |--------------------------------------------------------------------------
        | UTILITIES
        |--------------------------------------------------------------------------
        */

        .text-right {
            text-align: right;
        }

        .muted {
            color: #6b7280;
        }

        .strong {
            font-weight: bold;
        }

        .nowrap {
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | PAGE BREAK
        |--------------------------------------------------------------------------
        */

        .page-break {
            page-break-before: always;
        }
    </style>
</head>

<body>

<div class="document">

    {{-- =========================================================
         HEADER
    ========================================================== --}}

    <div class="header">

        <table class="header-table">

            <tr>

                <td class="header-left">

                    <div class="municipality-name">
                        {{ $receipt['municipality']['name'] }}
                    </div>

                    @if(
                        !empty(
                            $receipt['municipality']['address']
                        )
                    )
                        <div class="municipality-address">
                            {{ $receipt['municipality']['address'] }}
                        </div>
                    @endif

                    @if(
                        !empty(
                            $receipt['municipality']['phone']
                        )
                    )
                        <div class="municipality-address">
                            Tel:
                            {{ $receipt['municipality']['phone'] }}
                        </div>
                    @endif

                    @if(
                        !empty(
                            $receipt['municipality']['email']
                        )
                    )
                        <div class="municipality-address">
                            {{ $receipt['municipality']['email'] }}
                        </div>
                    @endif

                </td>


                <td class="header-right">

                    <div class="receipt-title">
                        Payment Receipt
                    </div>

                    <div class="official-label">
                        Official Electronic Receipt
                    </div>

                </td>

            </tr>

        </table>

    </div>


    {{-- =========================================================
         RECEIPT METADATA
    ========================================================== --}}

    <table class="receipt-meta">

        <tr>

            <td class="meta-label">
                Receipt Number
            </td>

            <td class="meta-value receipt-number">
                {{ $receipt['receipt_number'] }}
            </td>

        </tr>


        <tr>

            <td class="meta-label">
                Receipt Date
            </td>

            <td class="meta-value">

                @if($receipt['receipt_issued_at'])

                    {{ \Carbon\Carbon::parse(
                        $receipt['receipt_issued_at']
                    )->format('d M Y H:i') }}

                @else

                    -

                @endif

            </td>

        </tr>


        <tr>

            <td class="meta-label">
                Payment Status
            </td>

            <td class="meta-value">

                <span class="status">
                    {{ strtoupper(
                        $receipt['status'] ?? 'SUCCESS'
                    ) }}
                </span>

            </td>

        </tr>

    </table>


    {{-- =========================================================
         CUSTOMER INFORMATION
    ========================================================== --}}

    <div class="section">

        <div class="section-title">
            Payer Information
        </div>


        <table class="info-table">

            <tr>

                <td>

                    <span class="field-label">
                        Full Name
                    </span>

                    <span class="field-value">
                        {{ $receipt['customer']['name'] ?? '-' }}
                    </span>

                </td>


                <td>

                    <span class="field-label">
                        Phone Number
                    </span>

                    <span class="field-value">
                        {{ $receipt['customer']['phone'] ?? '-' }}
                    </span>

                </td>

            </tr>


            <tr>

                <td>

                    <span class="field-label">
                        Email Address
                    </span>

                    <span class="field-value">
                        {{ $receipt['customer']['email'] ?? '-' }}
                    </span>

                </td>


                <td>

                    <span class="field-label">
                        Invoice Reference
                    </span>

                    <span class="field-value">

                        {{
                            $receipt['invoice']['reference']
                            ?? '-'
                        }}

                    </span>

                </td>

            </tr>

        </table>

    </div>


    {{-- =========================================================
         PAYMENT INFORMATION
    ========================================================== --}}

    <div class="section">

        <div class="section-title">
            Payment Information
        </div>


        <table class="info-table">

            <tr>

                <td>

                    <span class="field-label">
                        Payment Reference
                    </span>

                    <span class="field-value">
                        {{ $receipt['payment_reference'] }}
                    </span>

                </td>


                <td>

                    <span class="field-label">
                        Payment Date
                    </span>

                    <span class="field-value">

                        @if($receipt['payment_date'])

                            {{
                                \Carbon\Carbon::parse(
                                    $receipt['payment_date']
                                )->format('d M Y H:i')
                            }}

                        @else

                            -

                        @endif

                    </span>

                </td>

            </tr>


            <tr>

                <td>

                    <span class="field-label">
                        Payment Provider
                    </span>

                    <span class="field-value">
                        {{ strtoupper(
                            $receipt['payment_provider'] ?? '-'
                        ) }}
                    </span>

                </td>


                <td>

                    <span class="field-label">
                        Payment Method
                    </span>

                    <span class="field-value">
                        {{ strtoupper(
                            $receipt['payment_method'] ?? '-'
                        ) }}
                    </span>

                </td>

            </tr>

        </table>

    </div>


    {{-- =========================================================
         AMOUNT
    ========================================================== --}}

    <div class="amount-section">

        <table class="amount-table">

            <tr>

                <td class="amount-label">
                    Total Amount Paid
                </td>

                <td class="amount-value">

                    {{ number_format(
                        $receipt['amount'],
                        2
                    ) }}

                    {{ $receipt['currency'] }}

                </td>

            </tr>

        </table>

    </div>


    {{-- =========================================================
         PROVIDER REFERENCE
    ========================================================== --}}

    @if(
        !empty(
            $receipt['provider_reference']
        )
    )

        <div class="provider-box">

            <table class="provider-table">

                <tr>

                    <td>

                        <span class="field-label">
                            Provider Transaction Reference
                        </span>

                        <span class="field-value">
                            {{ $receipt['provider_reference'] }}
                        </span>

                    </td>


                    <td>

                        <span class="field-label">
                            Provider
                        </span>

                        <span class="field-value">
                            {{ strtoupper(
                                $receipt['payment_provider']
                                ?? '-'
                            ) }}
                        </span>

                    </td>

                </tr>

            </table>

        </div>

    @endif


    {{-- =========================================================
         VERIFICATION NOTICE
    ========================================================== --}}

    <div class="verification">

        <div class="verification-title">
            Payment Verification
        </div>

        <p class="verification-text">

            This receipt confirms that the payment identified above
            was successfully processed and verified by the municipal
            payment system.

            The receipt reference and payment reference should be
            retained for future inquiries, reconciliation, or
            official record purposes.

        </p>

    </div>


    {{-- =========================================================
         FOOTER
    ========================================================== --}}

    <div class="footer">

        <div class="footer-main">

            {{ $receipt['municipality']['name'] }}

        </div>


        @if(
            !empty(
                $receipt['municipality']['website']
            )
        )

            <div>
                {{ $receipt['municipality']['website'] }}
            </div>

        @endif


        <div class="footer-small">

            This is an electronically generated payment receipt.
            No physical signature is required.

        </div>

    </div>

</div>

</body>

</html>