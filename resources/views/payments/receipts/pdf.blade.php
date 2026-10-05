<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <title>
        Payment Receipt - {{ $receipt['receipt_number'] ?? 'Receipt' }}
    </title>

    <style>
        /*
        |--------------------------------------------------------------------------
        | ADAMA CITY ADMINISTRATION
        | MUNICIPAL PAYMENT RECEIPT
        |--------------------------------------------------------------------------
        | A4 PORTRAIT
        | 595.28pt × 841.89pt
        |--------------------------------------------------------------------------
        */

        @page {
            size: A4 portrait;
            margin: 0;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            line-height: 1.3;
            color: #17212B;
            background: #FFFFFF;
        }

        /*
        |--------------------------------------------------------------------------
        | PAGE
        |--------------------------------------------------------------------------
        */

        .page {
            width: 595.28pt;
            height: 841.89pt;

            margin: 0;
            padding: 36pt 40pt 30pt 40pt;

            box-sizing: border-box;
            overflow: hidden;
        }

        .content {
            width: 515.28pt;
            max-width: 515.28pt;

            margin: 0;
            padding: 0;
        }

        /*
        |--------------------------------------------------------------------------
        | GLOBAL TABLE SYSTEM
        |--------------------------------------------------------------------------
        */

        table {
            width: 515.28pt;
            max-width: 515.28pt;

            margin: 0;
            padding: 0;

            border-collapse: collapse;
            border-spacing: 0;

            table-layout: fixed;
        }

        td {
            margin: 0;
            padding: 0;

            vertical-align: top;

            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /*
        |--------------------------------------------------------------------------
        | TYPOGRAPHY
        |--------------------------------------------------------------------------
        */

        .label {
            display: block;

            margin: 0 0 2pt 0;
            padding: 0;

            font-size: 6.2pt;
            line-height: 1.2;

            font-weight: normal;
            color: #7B8794;
        }

        .value {
            display: block;

            margin: 0;
            padding: 0;

            font-size: 8pt;
            line-height: 1.25;

            font-weight: bold;
            color: #17212B;
        }

        .section-title {
            font-size: 8.5pt;
            line-height: 1.2;

            font-weight: bold;
            color: #123B5D;
        }

        .section-reference {
            font-size: 6.2pt;
            line-height: 1.2;

            color: #8A96A3;
        }

        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        .header {
            width: 515.28pt;

            margin: 0;
            padding: 0 0 7pt 0;

            border-bottom: 1.5pt solid #123B5D;
        }

        .header-table {
            width: 515.28pt;
            max-width: 515.28pt;

            table-layout: fixed;
            border-collapse: collapse;
        }

        /*
        |--------------------------------------------------------------------------
        | LOGO — CLOSE TO MUNICIPAL IDENTITY
        |--------------------------------------------------------------------------
        */

        .header-logo-cell {
            width: 48pt;

            padding: 0 5pt 0 0;

            vertical-align: middle;
        }

        .city-logo {
            width: 40pt;
            height: 40pt;

            object-fit: contain;
        }

        /*
        |--------------------------------------------------------------------------
        | MUNICIPAL IDENTITY
        |--------------------------------------------------------------------------
        */

        .header-left {
            width: 287pt;

            padding: 0;

            vertical-align: middle;
        }

        .institution {
            font-size: 11.5pt;
            line-height: 13pt;

            font-weight: bold;

            color: #0B2C45;

            text-transform: uppercase;
        }

        .department {
            margin-top: 2pt;

            font-size: 7.8pt;
            line-height: 9pt;

            font-weight: bold;

            color: #123B5D;
        }

        .institution-meta {
            margin-top: 3pt;

            font-size: 6.1pt;
            line-height: 7.5pt;

            color: #7B8794;
        }

        /*
        |--------------------------------------------------------------------------
        | DOCUMENT IDENTITY
        |--------------------------------------------------------------------------
        */

        .header-right {
            width: 180.28pt;

            text-align: right;

            vertical-align: middle;
        }

        .document-label {
            font-size: 6.1pt;
            line-height: 7.5pt;

            font-weight: bold;

            letter-spacing: 0.45pt;

            text-transform: uppercase;

            color: #7B8794;
        }

        .document-title {
            margin-top: 2pt;

            font-size: 12.5pt;
            line-height: 14pt;

            font-weight: bold;

            color: #0B2C45;
        }

        .document-number {
            display: inline-block;

            margin-top: 3pt;
            padding: 3.5pt 6pt;

            border: 0.6pt solid #DCE3E8;

            background: #F7F9FB;

            font-size: 6.8pt;
            line-height: 8pt;

            font-weight: bold;

            color: #123B5D;
        }

        .official-label {
            margin-top: 2pt;

            font-size: 5.7pt;
            line-height: 7pt;

            font-weight: bold;

            letter-spacing: 0.3pt;

            text-transform: uppercase;

            color: #16704A;
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT SUMMARY
        |--------------------------------------------------------------------------
        */

        .summary {
            width: 515.28pt;

            margin: 8pt 0 0 0;
            padding: 0;
        }

        .summary-main {
            width: 66%;

            padding: 10pt 14pt;

            background: #123B5D;

            vertical-align: middle;
        }

        .summary-side {
            width: 34%;

            padding: 10pt 13pt;

            background: #0B2C45;

            vertical-align: middle;
        }

        .summary-label {
            font-size: 6.1pt;
            line-height: 1.15;

            font-weight: bold;

            letter-spacing: 0.4pt;

            text-transform: uppercase;

            color: #B9CCDC;
        }

        .amount {
            margin-top: 3pt;

            font-size: 20pt;
            line-height: 1;

            font-weight: bold;

            color: #FFFFFF;
        }

        .currency {
            margin-left: 2pt;

            font-size: 8pt;
            line-height: 1;

            font-weight: bold;

            color: #B9CCDC;
        }

        .summary-note {
            margin-top: 3pt;

            font-size: 6.1pt;
            line-height: 1.3;

            color: #B9CCDC;
        }

        .status-label {
            font-size: 6.1pt;
            line-height: 1.15;

            font-weight: bold;

            letter-spacing: 0.4pt;

            text-transform: uppercase;

            color: #B9CCDC;
        }

        .status {
            margin-top: 3pt;

            font-size: 8pt;
            line-height: 1.2;

            font-weight: bold;

            color: #FFFFFF;
        }

        .date-label {
            margin-top: 8pt;

            font-size: 6.1pt;
            line-height: 1.15;

            font-weight: bold;

            letter-spacing: 0.4pt;

            text-transform: uppercase;

            color: #B9CCDC;
        }

        .date {
            margin-top: 2pt;

            font-size: 7.2pt;
            line-height: 1.2;

            font-weight: bold;

            color: #FFFFFF;
        }

        /*
        |--------------------------------------------------------------------------
        | CONTENT SECTION
        |--------------------------------------------------------------------------
        */

        .content-section {
            width: 515.28pt;

            margin: 8pt 0 0 0;
            padding: 0;
        }

        .section-header {
            width: 515.28pt;

            margin: 0;
            padding: 0 0 3.5pt 0;

            border-bottom: 1pt solid #DCE3E8;
        }

        .section-header-left {
            width: 70%;
        }

        .section-header-right {
            width: 30%;

            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | PAYER / INVOICE
        |--------------------------------------------------------------------------
        */

        .info-grid {
            width: 515.28pt;

            margin: 0;
            padding: 0;

            border: 1pt solid #DCE3E8;
        }

        .info-cell {
            width: 50%;

            padding: 5pt 8pt;

            border-right: 1pt solid #DCE3E8;
            border-bottom: 1pt solid #DCE3E8;
        }

        .info-cell.last-column {
            border-right: none;
        }

        .info-cell.last-row {
            border-bottom: none;
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT DETAILS
        |--------------------------------------------------------------------------
        */

        .payment-table {
            width: 515.28pt;

            margin: 0;
            padding: 0;

            border: 1pt solid #DCE3E8;
        }

        .payment-table td {
            padding: 4.5pt 8pt;

            border-bottom: 1pt solid #E8EDF1;

            vertical-align: middle;
        }

        .payment-table tr:last-child td {
            border-bottom: none;
        }

        .payment-label {
            width: 36%;

            font-size: 6.4pt;
            line-height: 1.2;

            color: #7B8794;
        }

        .payment-value {
            width: 64%;

            font-size: 7.4pt;
            line-height: 1.2;

            font-weight: bold;

            color: #17212B;

            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFICATION
        |--------------------------------------------------------------------------
        */

        .verification {
            width: 515.28pt;

            margin: 7pt 0 0 0;
            padding: 0;
        }

        .verification-mark {
            width: 26pt;

            padding: 7pt 0 7pt 8pt;

            background: #EDF8F2;

            border-top: 1pt solid #C9E9D8;
            border-bottom: 1pt solid #C9E9D8;
            border-left: 1pt solid #C9E9D8;

            color: #16704A;

            font-size: 11pt;
            line-height: 1;

            font-weight: bold;

            vertical-align: middle;
        }

        .verification-content {
            padding: 6pt 8pt;

            background: #EDF8F2;

            border-top: 1pt solid #C9E9D8;
            border-bottom: 1pt solid #C9E9D8;
            border-right: 1pt solid #C9E9D8;
        }

        .verification-title {
            font-size: 7.3pt;
            line-height: 1.2;

            font-weight: bold;

            color: #16704A;
        }

        .verification-text {
            margin-top: 2pt;

            font-size: 6.1pt;
            line-height: 1.3;

            color: #536273;
        }

        /*
        |--------------------------------------------------------------------------
        | OFFICIAL AUTHENTICATION
        |--------------------------------------------------------------------------
        */

        .authentication {
            width: 515.28pt;

            margin: 7pt 0 0 0;
            padding: 6pt 0 0 0;

            border-top: 1pt solid #DCE3E8;
        }

        .authentication-info {
            width: 68%;

            vertical-align: middle;
        }

        .authentication-seal {
            width: 32%;

            text-align: right;

            vertical-align: middle;
        }

        .authentication-title {
            font-size: 7.3pt;
            line-height: 1.2;

            font-weight: bold;

            color: #123B5D;
        }

        .authentication-text {
            margin-top: 2pt;

            font-size: 6.1pt;
            line-height: 1.35;

            color: #7B8794;
        }

        .authentication-value {
            font-weight: bold;

            color: #536273;
        }

        /*
        |--------------------------------------------------------------------------
        | OFFICIAL REVENUE OFFICE SEAL
        |--------------------------------------------------------------------------
        */

        .seal-label {
            margin-bottom: 1pt;

            font-size: 5.7pt;
            line-height: 7pt;

            font-weight: bold;

            letter-spacing: 0.2pt;

            text-transform: uppercase;

            color: #7B8794;
        }

        .revenue-seal {
            width: 60pt;
            height: 60pt;

            object-fit: contain;
        }

        .seal-placeholder {
            width: 60pt;
            height: 60pt;

            margin-left: auto;

            box-sizing: border-box;

            border: 1pt dashed #C9D2DA;

            text-align: center;

            font-size: 5.3pt;
            line-height: 60pt;

            color: #8A96A3;
        }

        /*
        |--------------------------------------------------------------------------
        | FOOTER
        |--------------------------------------------------------------------------
        */

        .footer {
            width: 515.28pt;

            margin: 6pt 0 0 0;
            padding: 5pt 0 0 0;

            border-top: 1pt solid #E5E9ED;
        }

        .footer-main {
            width: 65%;

            font-size: 5.6pt;
            line-height: 1.3;

            color: #7B8794;
        }

        .footer-right {
            width: 35%;

            text-align: right;

            font-size: 5.6pt;
            line-height: 1.3;

            color: #7B8794;
        }

        .footer-strong {
            font-weight: bold;

            color: #536273;
        }

        /*
        |--------------------------------------------------------------------------
        | PDF SAFETY
        |--------------------------------------------------------------------------
        */

        .avoid-break {
            page-break-inside: avoid;
        }
    </style>
</head>

<body>

@php

    /*
    |--------------------------------------------------------------------------
    | PRESENTATION HELPERS
    |--------------------------------------------------------------------------
    */

    /*
     * Safely get the display value from:
     * - backed enums
     * - pure enums
     * - scalar values
     * - null
     */
    $displayValue = function ($value, $default = '-') {

        if ($value === null) {
            return $default;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value === ''
                ? $default
                : $value;
        }

        return $default;
    };


    $fmtDate = function ($value) {

        if (empty($value)) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($value)
                ->format('d M Y, H:i');
        } catch (\Throwable $e) {
            return '-';
        }
    };


    $clip = function ($value, $max = 60) use ($displayValue) {

        $value = trim(
            $displayValue($value, '')
        );

        return $value === ''
            ? '-'
            : \Illuminate\Support\Str::limit(
                $value,
                $max,
                '...'
            );
    };


    /*
    |--------------------------------------------------------------------------
    | LOCAL IMAGE → BASE64
    |--------------------------------------------------------------------------
    */

    $imageDataUri = function (
        string $path
    ): ?string {

        if (!is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path);

        if (!$mime) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return 'data:' .
            $mime .
            ';base64,' .
            base64_encode($contents);
    };


    /*
    |--------------------------------------------------------------------------
    | OFFICIAL MUNICIPAL BRANDING
    |--------------------------------------------------------------------------
    */

    $adamaLogo = $imageDataUri(
        public_path(
            'images/adama-city-logo.png'
        )
    );


    $revenueOfficeStamp = $imageDataUri(
        public_path(
            'images/revenue-office-stamp.png'
        )
    );


    /*
    |--------------------------------------------------------------------------
    | DATA
    |--------------------------------------------------------------------------
    */

    $municipality =
        $receipt['municipality'] ?? [];

    $customer =
        $receipt['customer'] ?? [];

    $invoice =
        $receipt['invoice'] ?? [];


    /*
    |--------------------------------------------------------------------------
    | PAYMENT METHOD
    |--------------------------------------------------------------------------
    */

    $method = strtoupper(
        $displayValue(
            $receipt['payment_method'] ?? null,
            '-'
        )
    );


    /*
    |--------------------------------------------------------------------------
    | RECEIPT STATUS
    |--------------------------------------------------------------------------
    */

    $receiptStatus = strtoupper(
        $displayValue(
            $receipt['receipt_status'] ?? null,
            'ISSUED'
        )
    );


    /*
    |--------------------------------------------------------------------------
    | PAYMENT STATUS
    |--------------------------------------------------------------------------
    */

    $paymentStatus = strtoupper(
        $displayValue(
            $receipt['status'] ?? null,
            'COMPLETED'
        )
    );


    /*
    |--------------------------------------------------------------------------
    | ISSUED BY
    |--------------------------------------------------------------------------
    |
    | Supports either:
    |
    | 1. String:
    |    "Abebe Kebede"
    |
    | 2. Array:
    |    [
    |        'name' => 'Abebe Kebede',
    |        'role' => 'Revenue Collector'
    |    ]
    |
    | 3. Array using:
    |    full_name / username / email / id
    |
    */

    $issuedBy =
        $receipt['receipt_issued_by']
        ?? null;

    $issuedByRole =
        $receipt['receipt_issued_by_role']
        ?? null;


    if (is_array($issuedBy)) {

        $issuedByDisplay =
            $issuedBy['name']
            ?? $issuedBy['full_name']
            ?? $issuedBy['username']
            ?? $issuedBy['email']
            ?? $issuedBy['id']
            ?? null;

        if (
            empty($issuedByRole)
            && !empty($issuedBy['role'])
        ) {
            $issuedByRole =
                $issuedBy['role'];
        }

    } else {

        $issuedByDisplay =
            $issuedBy;
    }


    /*
    |--------------------------------------------------------------------------
    | CURRENCY
    |--------------------------------------------------------------------------
    */

    $currency = $displayValue(
        $receipt['currency'] ?? null,
        'ETB'
    );


    /*
    |--------------------------------------------------------------------------
    | CONTACT INFORMATION
    |--------------------------------------------------------------------------
    */

    $contactParts = array_filter([

        !empty($municipality['phone'])
            ? 'Tel: ' .
                $municipality['phone']
            : null,

        !empty($municipality['email'])
            ? $municipality['email']
            : null,

        !empty($municipality['website'])
            ? $municipality['website']
            : null,

    ]);

@endphp


<div class="page">

    <div class="content">


        {{-- ============================================================= --}}
        {{-- HEADER                                                        --}}
        {{-- ============================================================= --}}

        <div class="header avoid-break">

            <table class="header-table">

                <tr>

                    {{-- ------------------------------------------------- --}}
                    {{-- ADAMA CITY LOGO                                   --}}
                    {{-- ------------------------------------------------- --}}

                    <td class="header-logo-cell">

                        @if($adamaLogo)

                            <img
                                src="{{ $adamaLogo }}"
                                alt="Adama City Administration"
                                class="city-logo"
                            >

                        @endif

                    </td>


                    {{-- ------------------------------------------------- --}}
                    {{-- MUNICIPAL IDENTITY — CLOSE TO LOGO                --}}
                    {{-- ------------------------------------------------- --}}

                    <td class="header-left">

                        <div class="institution">

                            {{ $clip(
                                $municipality['name']
                                    ?? 'Adama City Administration',
                                70
                            ) }}

                        </div>


                        <div class="department">
                            Revenue Management Office
                        </div>


                        @if(
                            !empty($municipality['address'])
                            || !empty($municipality['phone'])
                        )

                            <div class="institution-meta">

                                @if(!empty($municipality['address']))

                                    {{ $clip(
                                        $municipality['address'],
                                        75
                                    ) }}

                                @endif


                                @if(!empty($municipality['phone']))

                                    @if(
                                        !empty(
                                            $municipality['address']
                                        )
                                    )

                                        &nbsp;&nbsp;|&nbsp;&nbsp;

                                    @endif

                                    Tel:
                                    {{ $clip(
                                        $municipality['phone'],
                                        25
                                    ) }}

                                @endif

                            </div>

                        @endif

                    </td>


                    {{-- ------------------------------------------------- --}}
                    {{-- DOCUMENT IDENTITY                                 --}}
                    {{-- ------------------------------------------------- --}}

                    <td class="header-right">

                        <div class="document-label">
                            Official Financial Document
                        </div>


                        <div class="document-title">
                            Payment Receipt
                        </div>


                        <div class="document-number">

                            No.
                            {{ $clip(
                                $receipt['receipt_number']
                                    ?? '',
                                30
                            ) }}

                        </div>


                        <div class="official-label">
                            Official Record
                        </div>

                    </td>

                </tr>

            </table>

        </div>


        {{-- ============================================================= --}}
        {{-- PAYMENT SUMMARY                                               --}}
        {{-- ============================================================= --}}

        <table class="summary avoid-break">

            <tr>

                <td class="summary-main">

                    <div class="summary-label">
                        Amount Paid
                    </div>


                    <div class="amount">

                        {{ $receipt['amount'] ?? '0.00' }}

                        @if(!empty($currency))

                            <span class="currency">
                                {{ $currency }}
                            </span>

                        @endif

                    </div>


                    <div class="summary-note">

                        Successfully recorded in the
                        municipal revenue system.

                    </div>

                </td>


                <td class="summary-side">

                    <div class="status-label">
                        Receipt Status
                    </div>


                    <div class="status">
                        {{ $receiptStatus }}
                    </div>


                    <div class="date-label">
                        Issued
                    </div>


                    <div class="date">

                        {{ $fmtDate(
                            $receipt['receipt_issued_at']
                                ?? null
                        ) }}

                    </div>

                </td>

            </tr>

        </table>


        {{-- ============================================================= --}}
        {{-- PAYER & INVOICE                                               --}}
        {{-- ============================================================= --}}

        <div class="content-section avoid-break">

            <table class="section-header">

                <tr>

                    <td class="section-header-left">

                        <div class="section-title">
                            Payer &amp; Invoice
                        </div>

                    </td>


                    <td class="section-header-right">

                        <div class="section-reference">
                            Customer record
                        </div>

                    </td>

                </tr>

            </table>


            <table class="info-grid">

                <tr>

                    <td class="info-cell">

                        <span class="label">
                            Payer Name
                        </span>

                        <span class="value">

                            {{ $clip(
                                $customer['name'] ?? '',
                                55
                            ) }}

                        </span>

                    </td>


                    <td class="info-cell last-column">

                        <span class="label">
                            Phone Number
                        </span>

                        <span class="value">

                            {{ $clip(
                                $customer['phone'] ?? '',
                                35
                            ) }}

                        </span>

                    </td>

                </tr>


                <tr>

                    <td class="info-cell last-row">

                        <span class="label">
                            Email Address
                        </span>

                        <span class="value">

                            {{ $clip(
                                $customer['email'] ?? '',
                                50
                            ) }}

                        </span>

                    </td>


                    <td class="info-cell last-column last-row">

                        <span class="label">
                            Invoice Reference
                        </span>

                        <span class="value">

                            {{ $clip(
                                $invoice['reference'] ?? '',
                                35
                            ) }}

                        </span>

                    </td>

                </tr>

            </table>

        </div>


        {{-- ============================================================= --}}
        {{-- PAYMENT DETAILS                                               --}}
        {{-- ============================================================= --}}

        <div class="content-section avoid-break">

            <table class="section-header">

                <tr>

                    <td class="section-header-left">

                        <div class="section-title">
                            Payment Details
                        </div>

                    </td>


                    <td class="section-header-right">

                        <div class="section-reference">
                            Transaction record
                        </div>

                    </td>

                </tr>

            </table>


            <table class="payment-table">

                <tr>

                    <td class="payment-label">
                        Payment Number
                    </td>

                    <td class="payment-value">

                        {{ $clip(
                            $receipt['payment_number']
                                ?? '',
                            45
                        ) }}

                    </td>

                </tr>


                <tr>

                    <td class="payment-label">
                        Payment Date
                    </td>

                    <td class="payment-value">

                        {{ $fmtDate(
                            $receipt['payment_date']
                                ?? null
                        ) }}

                    </td>

                </tr>


                <tr>

                    <td class="payment-label">
                        Payment Method
                    </td>

                    <td class="payment-value">

                        {{ $clip(
                            $method,
                            30
                        ) }}

                    </td>

                </tr>


                <tr>

                    <td class="payment-label">
                        Transaction Reference
                    </td>

                    <td class="payment-value">

                        {{ $clip(
                            $receipt['payment_reference']
                                ?? '',
                            50
                        ) }}

                    </td>

                </tr>


                @if(
                    !empty(
                        $receipt['method_reference']
                    )
                )

                    <tr>

                        <td class="payment-label">
                            Method Reference
                        </td>

                        <td class="payment-value">

                            {{ $clip(
                                $receipt['method_reference'],
                                50
                            ) }}

                        </td>

                    </tr>

                @endif


                <tr>

                    <td class="payment-label">
                        Payment Status
                    </td>

                    <td class="payment-value">

                        {{ $paymentStatus }}

                    </td>

                </tr>

            </table>

        </div>


        {{-- ============================================================= --}}
        {{-- VERIFICATION                                                  --}}
        {{-- ============================================================= --}}

        <table class="verification avoid-break">

            <tr>

                <td class="verification-mark">
                    &#10003;
                </td>


                <td class="verification-content">

                    <div class="verification-title">
                        Payment Verified and Recorded
                    </div>


                    <div class="verification-text">

                        This receipt confirms that the payment
                        identified above has been successfully
                        processed and recorded by the municipal
                        revenue management system.

                        The receipt number, payment number, and
                        transaction reference provide the official
                        references for verification, reconciliation,
                        and audit purposes.

                    </div>

                </td>

            </tr>

        </table>


        {{-- ============================================================= --}}
        {{-- OFFICIAL AUTHENTICATION                                      --}}
        {{-- ============================================================= --}}

        <table class="authentication avoid-break">

            <tr>

                {{-- ----------------------------------------------------- --}}
                {{-- OFFICIAL RECORD INFORMATION                           --}}
                {{-- ----------------------------------------------------- --}}

                <td class="authentication-info">

                    <div class="authentication-title">
                        Official Municipal Record
                    </div>


                    <div class="authentication-text">

                        This electronically generated receipt
                        forms part of the official municipal
                        revenue record.

                        <br>

                        Receipt reference:
                        <strong class="authentication-value">
                            {{ $clip(
                                $receipt['receipt_number']
                                    ?? '',
                                35
                            ) }}
                        </strong>


                        {{-- --------------------------------------------- --}}
                        {{-- ISSUED BY                                    --}}
                        {{-- --------------------------------------------- --}}

                        @if(!empty($issuedByDisplay))

                            <br>

                            Issued by:
                            <strong class="authentication-value">
                                {{ $clip(
                                    $issuedByDisplay,
                                    45
                                ) }}
                            </strong>

                        @endif


                        {{-- --------------------------------------------- --}}
                        {{-- ISSUER ROLE                                   --}}
                        {{-- --------------------------------------------- --}}

                        @if(!empty($issuedByRole))

                            <br>

                            Role:
                            <strong class="authentication-value">
                                {{ $clip(
                                    $issuedByRole,
                                    35
                                ) }}
                            </strong>

                        @endif

                    </div>

                </td>


                {{-- ----------------------------------------------------- --}}
                {{-- REVENUE OFFICE SEAL                                   --}}
                {{-- ----------------------------------------------------- --}}

                <td class="authentication-seal">

                    <div class="seal-label">
                        Official Revenue Office Seal
                    </div>


                    @if($revenueOfficeStamp)

                        <img
                            src="{{ $revenueOfficeStamp }}"
                            alt="Official Revenue Office Seal"
                            class="revenue-seal"
                        >

                    @else

                        <div class="seal-placeholder">
                            Official Seal
                        </div>

                    @endif

                </td>

            </tr>

        </table>


        {{-- ============================================================= --}}
        {{-- FOOTER                                                        --}}
        {{-- ============================================================= --}}

        <table class="footer avoid-break">

            <tr>

                <td class="footer-main">

                    <span class="footer-strong">

                        {{ $clip(
                            $municipality['name']
                                ?? 'Adama City Administration',
                            70
                        ) }}

                    </span>


                    @if(!empty($contactParts))

                        <br>

                        {{ implode(
                            '   |   ',
                            array_map(
                                fn ($p) =>
                                    \Illuminate\Support\Str::limit(
                                        $p,
                                        45,
                                        '...'
                                    ),
                                $contactParts
                            )
                        ) }}

                    @endif


                    <br>

                    Electronically generated municipal payment receipt.

                </td>


                <td class="footer-right">

                    <span class="footer-strong">
                        Official Record
                    </span>

                    <br>

                    Retain for your records.

                </td>

            </tr>

        </table>

    </div>

</div>

</body>

</html>