<?php

declare(strict_types=1);

namespace App\Modules\DirectCollection\Services;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectCollectionService
{
    public function __construct(
        protected DirectCollectionInputService $inputService,
        protected DirectCollectionCalculator $calculator,
        protected DirectCollectionInvoiceService $invoiceService,
    ) {
    }

    /**
     * Calculate direct collection amount.
     *
     * This does NOT create an invoice.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function calculateAmount(
        array $data
    ): array {
        $this->ensureAuthenticated();

        $context =
            $this->inputService
                ->resolve(
                    $data
                );

        return $this->calculator
            ->calculate(
                taxpayer:
                    $context['taxpayer'],

                service:
                    $context['service'],

                fields:
                    $context['fields'],

                inputs:
                    $context['inputs'],
            );
    }

    /**
     * Create and issue a direct collection invoice.
     *
     * There is NO Assessment.
     * There is NO Review.
     * There is NO Confirmation state.
     *
     * @param array<string,mixed> $data
     */
    public function create(
        array $data
    ): Invoice {
        $this->ensureAuthenticated();

        /*
        |--------------------------------------------------------------------------
        | Resolve + Validate Inputs
        |--------------------------------------------------------------------------
        */

        $context =
            $this->inputService
                ->resolve(
                    $data
                );

        /*
        |--------------------------------------------------------------------------
        | Always Recalculate Server-Side
        |--------------------------------------------------------------------------
        */

        $calculation =
            $this->calculator
                ->calculate(
                    taxpayer:
                        $context['taxpayer'],

                    service:
                        $context['service'],

                    fields:
                        $context['fields'],

                    inputs:
                        $context['inputs'],
                );

        /*
        |--------------------------------------------------------------------------
        | Create + Issue Invoice
        |--------------------------------------------------------------------------
        */

        return $this->invoiceService
            ->create(
                data:
                    $data,

                context:
                    $context,

                calculation:
                    $calculation,
            );
    }

    /**
     * Update an existing direct collection.
     *
     * ONLY ISSUED invoices can be edited.
     *
     * Status rules:
     *
     *     ISSUED          → ALLOWED
     *     PARTIALLY_PAID  → BLOCKED
     *     PAID            → BLOCKED
     *     CANCELLED       → BLOCKED
     *
     * The invoice number remains unchanged.
     *
     * The invoice is recalculated using the current submitted
     * taxpayer/service/field values.
     *
     * @param string $invoiceId
     * @param array<string,mixed> $data
     */
    public function update(
        string $invoiceId,
        array $data
    ): Invoice {
        $this->ensureAuthenticated();

        return DB::transaction(
            function () use (
                $invoiceId,
                $data
            ): Invoice {

                /*
                |--------------------------------------------------------------------------
                | 1. Find + Lock Invoice
                |--------------------------------------------------------------------------
                */

                $invoice = Invoice::query()
                    ->where(
                        'source_type',
                        'DIRECT_COLLECTION'
                    )
                    ->where(
                        'id',
                        $invoiceId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                |--------------------------------------------------------------------------
                | 2. Verify Payment State
                |--------------------------------------------------------------------------
                |
                | Direct Collection can only be edited before payment.
                |
                */

                $status = strtoupper(
                    (string) $invoice->status
                );

                if ($status !== 'ISSUED') {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'Only pending-payment direct collections can be edited.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 3. Additional Payment Protection
                |--------------------------------------------------------------------------
                |
                | Even if the status is accidentally still ISSUED,
                | do not allow editing if money has already been recorded.
                |
                */

                $paidAmount = (float) (
                    $invoice->paid_amount
                    ?? 0
                );

                if ($paidAmount > 0) {
                    throw ValidationException::withMessages([
                        'invoice' => [
                            'This direct collection cannot be edited because payment has already been recorded.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. Resolve New Input
                |--------------------------------------------------------------------------
                */

                $context =
                    $this->inputService
                        ->resolve(
                            $data
                        );

                /*
                |--------------------------------------------------------------------------
                | 5. Recalculate
                |--------------------------------------------------------------------------
                |
                | Never trust amount/tariff/due date from frontend.
                |
                */

                $calculation =
                    $this->calculator
                        ->calculate(
                            taxpayer:
                                $context['taxpayer'],

                            service:
                                $context['service'],

                            fields:
                                $context['fields'],

                            inputs:
                                $context['inputs'],
                        );

                /*
                |--------------------------------------------------------------------------
                | 6. Update Invoice
                |--------------------------------------------------------------------------
                */

                $this->updateInvoice(
                    invoice:
                        $invoice,

                    context:
                        $context,

                    calculation:
                        $calculation,

                    data:
                        $data,
                );

                /*
                |--------------------------------------------------------------------------
                | 7. Update Invoice Item
                |--------------------------------------------------------------------------
                */

                $this->updateInvoiceItem(
                    invoice:
                        $invoice,

                    context:
                        $context,

                    calculation:
                        $calculation,
                );

                /*
                |--------------------------------------------------------------------------
                | 8. Return Fresh Invoice
                |--------------------------------------------------------------------------
                */

                return $invoice->fresh([
                    'citizen',
                    'items',
                    'items.service',
                ]);
            },
            attempts: 3
        );
    }

    /**
     * Update the invoice financial information.
     *
     * @param Invoice $invoice
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     * @param array<string,mixed> $data
     */
    protected function updateInvoice(
        Invoice $invoice,
        array $context,
        array $calculation,
        array $data
    ): void {
        $amount =
            $calculation['amount'];
    
        $currency =
            $calculation['currency']
            ?? $invoice->currency
            ?? 'ETB';
    
        /*
        |--------------------------------------------------------------------------
        | Financial Values
        |--------------------------------------------------------------------------
        */
    
        $invoice->subtotal =
            $amount;
    
        $invoice->discount_amount =
            0;
    
        $invoice->penalty_amount =
            0;
    
        $invoice->interest_amount =
            0;
    
        $invoice->total_amount =
            $amount;
    
        /*
        |--------------------------------------------------------------------------
        | Payment State
        |--------------------------------------------------------------------------
        |
        | We already verified that paid_amount = 0.
        |
        */
    
        $invoice->paid_amount =
            0;
    
        $invoice->balance_due =
            $amount;
    
        $invoice->paid_at =
            null;
    
        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */
    
        $invoice->currency =
            strtoupper(
                (string) $currency
            );
    
        /*
        |--------------------------------------------------------------------------
        | Due Date
        |--------------------------------------------------------------------------
        */
    
        $invoice->due_date =
            $calculation['due_date']
            ?? null;
    
        /*
        |--------------------------------------------------------------------------
        | Taxpayer
        |--------------------------------------------------------------------------
        */
    
        $invoice->citizen_id =
            $context['taxpayer']->id;
    
        /*
        |--------------------------------------------------------------------------
        | Administrative Unit
        |--------------------------------------------------------------------------
        */
    
        if (
            array_key_exists(
                'administrative_unit_id',
                $data
            )
        ) {
            $invoice->administrative_unit_id =
                $data['administrative_unit_id'];
        }
    
        /*
        |--------------------------------------------------------------------------
        | Notes
        |--------------------------------------------------------------------------
        */
    
        if (
            array_key_exists(
                'notes',
                $data
            )
        ) {
            $invoice->notes =
                $data['notes'];
        }
    
        /*
        |--------------------------------------------------------------------------
        | Preserve ISSUED
        |--------------------------------------------------------------------------
        */
    
        $invoice->status =
            'ISSUED';
    
        /*
        |--------------------------------------------------------------------------
        | Updated By
        |--------------------------------------------------------------------------
        */
    
        if (
            $invoice->isFillable('updated_by')
        ) {
            $invoice->updated_by =
                Auth::id();
        }
    
        /*
        |--------------------------------------------------------------------------
        | Source Metadata
        |--------------------------------------------------------------------------
        */
    
        $metadata =
            is_array(
                $invoice->source_metadata
            )
                ? $invoice->source_metadata
                : [];
    
        $metadata['last_edited_at'] =
            now()->toISOString();
    
        $metadata['last_edited_by'] =
            Auth::id();
    
        /*
        |--------------------------------------------------------------------------
        | Latest Calculation
        |--------------------------------------------------------------------------
        */
    
        $metadata['calculation'] =
            $calculation[
                'calculation_snapshot'
            ];
    
        /*
        |--------------------------------------------------------------------------
        | Latest Input Snapshot
        |--------------------------------------------------------------------------
        */
    
        $metadata['input_snapshot'] =
            $calculation[
                'input_snapshot'
            ];
    
        /*
        |--------------------------------------------------------------------------
        | Service Information
        |--------------------------------------------------------------------------
        */
    
        $metadata['service_id'] =
            (string) $context['service']->id;
    
        $metadata['service_code'] =
            $context['service']->code
            ?? null;
    
        /*
        |--------------------------------------------------------------------------
        | Taxpayer Information
        |--------------------------------------------------------------------------
        */
    
        $metadata['taxpayer_id'] =
            (string) $context['taxpayer']->id;
    
        /*
        |--------------------------------------------------------------------------
        | Edit Reason
        |--------------------------------------------------------------------------
        */
    
        if (
            isset($data['reason'])
            && trim(
                (string) $data['reason']
            ) !== ''
        ) {
            $metadata['edit_reason'] =
                trim(
                    (string) $data['reason']
                );
        }
    
        $invoice->source_metadata =
            $metadata;
    
        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */
    
        $invoice->save();
    }
    /**
     * Update the existing invoice item.
     *
     * Direct Collection currently creates one invoice item.
     *
     * @param Invoice $invoice
     * @param array<string,mixed> $context
     * @param array<string,mixed> $calculation
     */
    protected function updateInvoiceItem(
        Invoice $invoice,
        array $context,
        array $calculation
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Find + Lock Existing Item
        |--------------------------------------------------------------------------
        */

        $item = $invoice
            ->items()
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'The direct collection invoice does not contain an invoice item.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Service
        |--------------------------------------------------------------------------
        */

        $item->service_id =
            $context['service']->id;

        /*
        |--------------------------------------------------------------------------
        | Quantity
        |--------------------------------------------------------------------------
        */

        $item->quantity =
            $calculation['quantity']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Unit
        |--------------------------------------------------------------------------
        */

        $item->unit =
            $calculation['unit']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Unit Price
        |--------------------------------------------------------------------------
        */

        $item->unit_price =
            $calculation['unit_price']
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        $item->amount =
            $calculation['amount'];

        /*
        |--------------------------------------------------------------------------
        | Discount / Penalty / Interest
        |--------------------------------------------------------------------------
        */

        $item->discount_amount =
            0;

        $item->penalty_amount =
            0;

        $item->interest_amount =
            0;

        /*
        |--------------------------------------------------------------------------
        | Total
        |--------------------------------------------------------------------------
        */

        $item->total_amount =
            $calculation['amount'];

        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        $item->currency =
            $calculation['currency'];

        /*
        |--------------------------------------------------------------------------
        | Tariff Version
        |--------------------------------------------------------------------------
        */

        $item->tariff_version_id =
            $calculation[
                'tariff_version_id'
            ];

        /*
        |--------------------------------------------------------------------------
        | Tariff Rule
        |--------------------------------------------------------------------------
        */

        $item->tariff_rule_id =
            $calculation[
                'tariff_rule_id'
            ];

        /*
        |--------------------------------------------------------------------------
        | Input Snapshot
        |--------------------------------------------------------------------------
        */

        $item->input_snapshot =
            $calculation[
                'input_snapshot'
            ];

        /*
        |--------------------------------------------------------------------------
        | Calculation Snapshot
        |--------------------------------------------------------------------------
        */

        $item->calculation_snapshot =
            $calculation[
                'calculation_snapshot'
            ];

        /*
        |--------------------------------------------------------------------------
        | Description
        |--------------------------------------------------------------------------
        */

        $item->description =
            $context['service']->name
            ?? $context['service']->service_name
            ?? $context['service']->code
            ?? $item->description;

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $item->save();
    }

    /**
     * Get paginated direct collections.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(
        array $filters = []
    ): LengthAwarePaginator {
        $this->ensureAuthenticated();

        $query = Invoice::query()
            ->with([
                'citizen',
                'items',
                'items.service',
            ])
            ->where(
                'source_type',
                'DIRECT_COLLECTION'
            )
            ->latest('created_at');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['search'])
            && trim((string) $filters['search']) !== ''
        ) {
            $search = trim(
                (string) $filters['search']
            );

            $query->where(function ($builder) use ($search) {
                $builder
                    ->where(
                        'invoice_number',
                        'ilike',
                        "%{$search}%"
                    )
                    ->orWhereHas(
                        'citizen',
                        function ($citizenQuery) use ($search) {
                            $citizenQuery
                                ->where(
                                    'first_name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'middle_name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'last_name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'phone',
                                    'ilike',
                                    "%{$search}%"
                                );
                        }
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['status'])
            && trim((string) $filters['status']) !== ''
        ) {
            $query->where(
                'status',
                trim((string) $filters['status'])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Taxpayer Filter
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['taxpayer_id'])
            && trim((string) $filters['taxpayer_id']) !== ''
        ) {
            $query->where(
                'citizen_id',
                trim((string) $filters['taxpayer_id'])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Revenue Service Filter
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['revenue_service_id'])
            && trim((string) $filters['revenue_service_id']) !== ''
        ) {
            $serviceId = trim(
                (string) $filters['revenue_service_id']
            );

            $query->whereHas(
                'items',
                function ($itemQuery) use ($serviceId) {
                    $itemQuery->where(
                        'service_id',
                        $serviceId
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | From Date
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['from_date'])
            && trim((string) $filters['from_date']) !== ''
        ) {
            $query->whereDate(
                'created_at',
                '>=',
                trim((string) $filters['from_date'])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | To Date
        |--------------------------------------------------------------------------
        */

        if (
            isset($filters['to_date'])
            && trim((string) $filters['to_date']) !== ''
        ) {
            $query->whereDate(
                'created_at',
                '<=',
                trim((string) $filters['to_date'])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) (
            $filters['per_page'] ?? 20
        );

        $perPage = max(
            1,
            min(
                $perPage,
                100
            )
        );

        return $query->paginate(
            $perPage
        );
    }

    /**
     * Find one direct collection.
     */
    public function find(
        string $invoiceId
    ): Invoice {
        $this->ensureAuthenticated();

        return Invoice::query()
            ->with([
                'citizen',
                'items',
                'items.service',
            ])
            ->where(
                'source_type',
                'DIRECT_COLLECTION'
            )
            ->where(
                'id',
                $invoiceId
            )
            ->firstOrFail();
    }

    /**
     * Require authenticated user.
     */
    protected function ensureAuthenticated(): void
    {
        if (! Auth::user()) {
            throw ValidationException::withMessages([
                'user' => [
                    'An authenticated user is required.',
                ],
            ]);
        }
    }
}