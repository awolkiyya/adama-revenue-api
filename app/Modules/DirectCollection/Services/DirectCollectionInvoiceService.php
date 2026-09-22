<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Citizen;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RevenueService;
use App\Modules\Invoice\Services\InvoiceIssuanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DirectCollectionInvoiceService
{
    private const SOURCE_TYPE = 'DIRECT_COLLECTION';

    public function __construct(
        protected InvoiceIssuanceService $invoiceIssuanceService,
    ) {
    }

    /**
     * Create and issue the direct collection invoice.
     *
     * @param array<string,mixed> $data
     * @param array{
     *     taxpayer: Citizen,
     *     service: RevenueService,
     *     fields:mixed,
     *     inputs:array<string,mixed>
     * } $context
     * @param array<string,mixed> $calculation
     */
    public function create(
        array $data,
        array $context,
        array $calculation,
    ): Invoice {
        $user = Auth::user();

        if (! $user) {
            throw ValidationException::withMessages([
                'user' => [
                    'An authenticated user is required.',
                ],
            ]);
        }

        $dueDate =
            $calculation['due_date']
            ?? null;

        if (! $dueDate) {
            throw ValidationException::withMessages([
                'due_date' => [
                    'The direct collection calculation did not produce a due date.',
                ],
            ]);
        }

        return DB::transaction(
            function () use (
                $data,
                $context,
                $calculation,
                $user,
                $dueDate,
            ): Invoice {
                /** @var Citizen $taxpayer */
                $taxpayer =
                    $context['taxpayer'];

                /** @var RevenueService $service */
                $service =
                    $context['service'];

                /*
                |--------------------------------------------------------------------------
                | Lock Taxpayer
                |--------------------------------------------------------------------------
                */

                /** @var Citizen|null $lockedTaxpayer */
                $lockedTaxpayer =
                    Citizen::query()
                        ->lockForUpdate()
                        ->find(
                            $taxpayer->id
                        );

                if (! $lockedTaxpayer) {
                    throw ValidationException::withMessages([
                        'taxpayer_id' => [
                            'The selected taxpayer could not be found.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Service Availability
                |--------------------------------------------------------------------------
                */

                $this->ensureServiceIsAvailable(
                    $service
                );

                /*
                |--------------------------------------------------------------------------
                | Financial Values
                |--------------------------------------------------------------------------
                */

                $amount =
                    $calculation['amount'];

                $currency =
                    $calculation['currency'];

                /*
                |--------------------------------------------------------------------------
                | Invoice Number
                |--------------------------------------------------------------------------
                */

                $invoiceNumber =
                    $this->generateInvoiceNumber();

                /*
                |--------------------------------------------------------------------------
                | Create Draft Invoice
                |--------------------------------------------------------------------------
                */

                $invoice =
                    Invoice::query()->create([
                        'invoice_number' =>
                            $invoiceNumber,

                        'source_type' =>
                            self::SOURCE_TYPE,

                        'assessment_id' =>
                            null,

                        'citizen_id' =>
                            $lockedTaxpayer->id,

                        'administrative_unit_id' =>
                            $data['administrative_unit_id']
                            ?? null,

                        'status' =>
                            'DRAFT',

                        'currency' =>
                            $currency,

                        'subtotal' =>
                            $amount,

                        'discount_amount' =>
                            0,

                        'penalty_amount' =>
                            0,

                        'interest_amount' =>
                            0,

                        'total_amount' =>
                            $amount,

                        'paid_amount' =>
                            0,

                        'balance_due' =>
                            $amount,

                        'issued_at' =>
                            null,

                        'due_date' =>
                            $dueDate,

                        'paid_at' =>
                            null,

                        'cancelled_at' =>
                            null,

                        'cancelled_by' =>
                            null,

                        'cancellation_reason' =>
                            null,

                        'voided_at' =>
                            null,

                        'voided_by' =>
                            null,

                        'void_reason' =>
                            null,

                        'notes' =>
                            $data['notes']
                            ?? null,

                        'created_by' =>
                            $user->id,

                        'issued_by' =>
                            null,

                        'source_metadata' => [
                            'source' =>
                                self::SOURCE_TYPE,

                            'service_id' =>
                                (string) $service->id,

                            'service_code' =>
                                $service->code
                                ?? null,

                            'taxpayer_id' =>
                                (string) $lockedTaxpayer->id,

                            'created_by' =>
                                (string) $user->id,

                            'created_at' =>
                                now()->toISOString(),

                            'metadata' =>
                                $data['metadata']
                                ?? null,

                            'calculation' =>
                                $calculation[
                                    'calculation_snapshot'
                                ],

                            'input_snapshot' =>
                                $calculation[
                                    'input_snapshot'
                                ],
                        ],
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Create Invoice Item
                |--------------------------------------------------------------------------
                */

                $invoiceItem =
                    InvoiceItem::query()->create([
                        'invoice_id' =>
                            $invoice->id,

                        'assessment_service_id' =>
                            null,

                        'service_id' =>
                            $service->id,

                        'line_number' =>
                            1,

                        'description' =>
                            $this->buildDescription(
                                $service
                            ),

                        'quantity' =>
                            $calculation[
                                'quantity'
                            ],

                        'unit' =>
                            $calculation[
                                'unit'
                            ],

                        'unit_price' =>
                            $calculation[
                                'unit_price'
                            ],

                        'amount' =>
                            $amount,

                        'discount_amount' =>
                            0,

                        'penalty_amount' =>
                            0,

                        'interest_amount' =>
                            0,

                        'total_amount' =>
                            $amount,

                        'currency' =>
                            $currency,

                        'tariff_version_id' =>
                            $calculation[
                                'tariff_version_id'
                            ],

                        'tariff_rule_id' =>
                            $calculation[
                                'tariff_rule_id'
                            ],

                        'input_snapshot' =>
                            $calculation[
                                'input_snapshot'
                            ],

                        'calculation_snapshot' =>
                            $calculation[
                                'calculation_snapshot'
                            ],
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Issue Invoice
                |--------------------------------------------------------------------------
                */

                $issuedInvoice =
                    $this->invoiceIssuanceService
                        ->issue(
                            $invoice
                        );

                /*
                |--------------------------------------------------------------------------
                | Log
                |--------------------------------------------------------------------------
                */

                Log::info(
                    'Direct collection created and issued.',
                    [
                        'invoice_id' =>
                            $issuedInvoice->id,

                        'invoice_number' =>
                            $issuedInvoice->invoice_number,

                        'taxpayer_id' =>
                            $lockedTaxpayer->id,

                        'revenue_service_id' =>
                            $service->id,

                        'invoice_item_id' =>
                            $invoiceItem->id,

                        'amount' =>
                            $amount,

                        'currency' =>
                            $currency,

                        'created_by' =>
                            $user->id,

                        'issued_by' =>
                            $issuedInvoice->issued_by,
                    ]
                );

                return $issuedInvoice->fresh([
                    'citizen',
                    'items',
                    'items.service',
                ]);
            },
            attempts: 3
        );
    }

    protected function ensureServiceIsAvailable(
        RevenueService $service
    ): void {
        if (
            isset($service->is_active)
            && ! $service->is_active
        ) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service is not active.',
                ],
            ]);
        }

        if (
            isset($service->status)
            && in_array(
                strtoupper(
                    (string) $service->status
                ),
                [
                    'INACTIVE',
                    'DISABLED',
                    'ARCHIVED',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'revenue_service_id' => [
                    'The selected revenue service is not available.',
                ],
            ]);
        }
    }

    /**
     * Generate invoice number.
     */
    protected function generateInvoiceNumber(): string
    {
        $year =
            now()->format('Y');

        $prefix =
            "INV-{$year}-";

        $lastInvoice =
            Invoice::query()
                ->where(
                    'invoice_number',
                    'like',
                    "{$prefix}%"
                )
                ->orderByDesc(
                    'invoice_number'
                )
                ->lockForUpdate()
                ->first();

        $sequence = 1;

        if ($lastInvoice) {
            $number =
                substr(
                    $lastInvoice->invoice_number,
                    strlen($prefix)
                );

            if (ctype_digit($number)) {
                $sequence =
                    ((int) $number) + 1;
            }
        }

        return sprintf(
            '%s%06d',
            $prefix,
            $sequence
        );
    }

    protected function buildDescription(
        RevenueService $service
    ): string {
        return (string) (
            $service->name
            ?? $service->service_name
            ?? $service->code
            ?? 'Revenue Service'
        );
    }
}