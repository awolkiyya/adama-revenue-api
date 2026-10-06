<?php

namespace App\Modules\Payment\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BankTransferDetail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentSequenceService;
use App\Services\Storage\StorageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BankTransferService
{
    /**
     * Monetary precision used throughout the payment domain.
     */
    protected const MONEY_SCALE = 4;

    /**
     * Invoice statuses that may receive payments.
     */
    protected const PAYABLE_INVOICE_STATUSES = [
        'ISSUED',
        'PARTIALLY_PAID',
        'OVERDUE',
    ];

    /**
     * Bank-transfer-specific verification states.
     *
     * These are intentionally separate from PaymentStatus.
     */
    protected const VERIFICATION_PENDING = 'PENDING';

    protected const VERIFICATION_VERIFIED = 'VERIFIED';

    protected const VERIFICATION_REJECTED = 'REJECTED';

    /**
     * File collection for bank-transfer evidence.
     */
    protected const EVIDENCE_COLLECTION = 'BANK_TRANSFER_EVIDENCE';

    /**
     * Bank-transfer evidence is financially sensitive.
     */
    protected const EVIDENCE_VISIBILITY = 'private';

    /**
     * Physical storage folder for bank-transfer evidence.
     */
    protected const EVIDENCE_FOLDER = 'payment/bank-transfers/evidence';

    public function __construct(
        protected DocumentSequenceService $documentSequenceService,
        protected PaymentReceiptService $receiptService,
        protected StorageService $storageService,
    ) {
    }

    /**
     * Record a new bank-transfer payment.
     *
     * Lifecycle:
     *
     * Invoice
     *     ↓
     * Payment::PENDING
     *     ↓
     * BankTransferDetail::PENDING
     *     ↓
     * Optional File evidence
     *
     * The invoice financial state is NOT changed here.
     *
     * The payment becomes financially successful only after
     * verify() changes its status to COMPLETED.
     */
    public function record(
        User $user,
        array $data,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $user,
            $data,
            $requestId
        ): Payment {
            /*
             * =========================================================
             * 1. LOCK AND LOAD INVOICE
             * =========================================================
             */
            $invoice = Invoice::query()
                ->with('citizen')
                ->whereKey($data['invoice_id'] ?? null)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'The selected invoice does not exist.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 2. VALIDATE INVOICE
             * =========================================================
             */
            $this->validateInvoiceForPayment($invoice);

            /*
             * =========================================================
             * 3. PREVENT MULTIPLE PENDING BANK TRANSFERS
             * =========================================================
             *
             * Multiple completed payments are allowed.
             *
             * However, only one unverified bank-transfer submission
             * may exist for the same invoice at a time.
             */
            $pendingPayment = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where(
                    'payment_method',
                    PaymentMethod::BANK_TRANSFER->value
                )
                ->where(
                    'status',
                    PaymentStatus::PENDING->value
                )
                ->lockForUpdate()
                ->first();

            if ($pendingPayment) {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice already has a pending bank-transfer payment awaiting verification.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 4. NORMALIZE PAYMENT AMOUNT
             * =========================================================
             */
            $amount = $this->normalizeAmount(
                $data['amount'] ?? null
            );

            /*
             * =========================================================
             * 5. CALCULATE CURRENT OUTSTANDING BALANCE
             * =========================================================
             */
            $outstanding = $this->calculateOutstandingAmount(
                $invoice
            );

            if (
                bccomp(
                    $amount,
                    $outstanding,
                    self::MONEY_SCALE
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The payment amount cannot exceed the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 6. VALIDATE MUNICIPAL BANK ACCOUNT
             * =========================================================
             */
            $bankAccountId = trim(
                (string) (
                    $data['bank_account_id'] ?? ''
                )
            );

            if ($bankAccountId === '') {
                throw ValidationException::withMessages([
                    'bank_account_id' => [
                        'A municipal bank account is required.',
                    ],
                ]);
            }

            $bankAccount = DB::table('bank_accounts')
                ->where('id', $bankAccountId)
                ->first();

            if (!$bankAccount) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [
                        'The selected municipal bank account does not exist.',
                    ],
                ]);
            }

            /*
             * DB::table()->first() returns stdClass.
             *
             * isset() safely handles installations where the
             * is_active column exists.
             */
            if (
                isset($bankAccount->is_active)
                && !$bankAccount->is_active
            ) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [
                        'The selected municipal bank account is inactive.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 7. VALIDATE BANK TRANSFER REFERENCE
             * =========================================================
             */
            $transferReference = trim(
                (string) (
                    $data['transfer_reference'] ?? ''
                )
            );

            if ($transferReference === '') {
                throw ValidationException::withMessages([
                    'transfer_reference' => [
                        'The bank transfer reference is required.',
                    ],
                ]);
            }

            $this->ensureTransferReferenceIsUnique(
                transferReference: $transferReference,
                bankAccountId: $bankAccountId,
            );

            /*
             * =========================================================
             * 8. GENERATE PAYMENT NUMBER
             * =========================================================
             */
            $paymentNumber = $this->documentSequenceService->generate(
                sequenceType: 'payment',
            );

            /*
             * =========================================================
             * 9. GENERATE MUNICIPAL TRANSACTION REFERENCE
             * =========================================================
             *
             * This is different from the bank's transfer reference.
             */
            $transactionReference =
                $this->generateTransactionReference();

            /*
             * =========================================================
             * 10. CREATE COMMON PAYMENT
             * =========================================================
             */
            $payment = new Payment();

            $payment->payment_number = $paymentNumber;

            $payment->invoice_id = $invoice->id;

            /*
             * Never trust citizen_id from the frontend.
             */
            $payment->citizen_id = $invoice->citizen_id;

            /*
             * Controlled by this service.
             */
            $payment->payment_method =
                PaymentMethod::BANK_TRANSFER;

            /*
             * Manually recorded by municipal staff.
             */
            $payment->payment_source =
                'OFFICE_RECORDED';

            /*
             * Awaiting verification.
             */
            $payment->status =
                PaymentStatus::PENDING;

            $payment->transaction_reference =
                $transactionReference;

            $payment->amount =
                $amount;

            $payment->currency =
                $invoice->currency ?? 'ETB';

            /*
             * Officer who recorded the submission.
             */
            $payment->processed_by =
                $user->id;

            /*
             * Prefer explicitly supplied payer information.
             * Otherwise use the invoice citizen.
             */
            $payment->payer_name =
                $data['payer_name']
                ?? $invoice->citizen?->name;

            $payment->payer_phone =
                $data['payer_phone']
                ?? $invoice->citizen?->phone;

            $payment->failure_reason = null;

            $payment->metadata =
                $data['metadata'] ?? null;

            $payment->save();

            /*
             * =========================================================
             * 11. CREATE BANK TRANSFER DETAIL
             * =========================================================
             *
             * This record is created before evidence because it is
             * the polymorphic owner of the uploaded File.
             */
            $bankDetails = new BankTransferDetail();

            $bankDetails->payment_id =
                $payment->id;

            $bankDetails->bank_account_id =
                $bankAccountId;

            $bankDetails->transfer_reference =
                $transferReference;

            $bankDetails->transfer_date =
                $data['transfer_date'] ?? null;

            $bankDetails->sender_name =
                $data['sender_name'] ?? null;

            $bankDetails->sender_account =
                $data['sender_account'] ?? null;

            $bankDetails->verification_status =
                self::VERIFICATION_PENDING;

            $bankDetails->verified_by = null;

            $bankDetails->verified_at = null;

            $bankDetails->notes =
                $data['notes'] ?? null;

            $bankDetails->save();

            /*
             * =========================================================
             * 12. STORE OPTIONAL BANK TRANSFER EVIDENCE
             * =========================================================
             *
             * StorageService:
             *
             *     physical file
             *          +
             *     files database record
             *
             * attachToModel():
             *
             *     files.fileable_type
             *     files.fileable_id
             *
             * The evidence belongs to BankTransferDetail.
             */
            $hasEvidence =
                isset($data['evidence'])
                && $data['evidence'] instanceof UploadedFile;

            if ($hasEvidence) {
                $this->storeEvidence(
                    file: $data['evidence'],
                    bankDetails: $bankDetails,
                    user: $user,
                );
            }

            /*
             * =========================================================
             * 13. AUDIT LOG
             * =========================================================
             */
            Log::info(
                'Bank transfer payment recorded.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'transfer_reference' =>
                        $transferReference,

                    'invoice_id' =>
                        $invoice->id,

                    'amount' =>
                        $payment->amount,

                    'currency' =>
                        $payment->currency,

                    'payment_method' =>
                        PaymentMethod::BANK_TRANSFER->value,

                    'payment_source' =>
                        $payment->payment_source,

                    'status' =>
                        PaymentStatus::PENDING->value,

                    'verification_status' =>
                        self::VERIFICATION_PENDING,

                    'bank_account_id' =>
                        $bankAccountId,

                    'processed_by' =>
                        $user->id,

                    'has_evidence' =>
                        $hasEvidence,
                ]
            );

            /*
             * =========================================================
             * 14. RETURN FULLY LOADED PAYMENT
             * =========================================================
             */
            return $payment->fresh([
                'invoice',
                'citizen',
                'bankTransferDetails.bankAccount',
                'bankTransferDetails.files',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
            ]);
        });
    }

    /**
     * Verify a pending bank-transfer payment.
     *
     * PENDING → COMPLETED
     * PENDING → VERIFIED
     *
     * Only after verification:
     *
     * - receipt is created
     * - invoice paid_amount is recalculated
     * - invoice balance_due is recalculated
     * - invoice status is updated
     */
    public function verify(
        string $paymentId,
        User $user,
        ?string $verificationNotes = null,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $verificationNotes,
            $requestId
        ): Payment {
            /*
             * =========================================================
             * 1. FIND PAYMENT REFERENCE
             * =========================================================
             */
            $paymentReference = Payment::query()
                ->whereKey($paymentId)
                ->first();

            if (!$paymentReference) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            $this->validateBankTransferPayment(
                $paymentReference
            );

            /*
             * =========================================================
             * 2. LOCK INVOICE FIRST
             * =========================================================
             */
            $invoice = Invoice::query()
                ->with('citizen')
                ->whereKey($paymentReference->invoice_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The invoice associated with this payment does not exist.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 3. LOCK PAYMENT SECOND
             * =========================================================
             */
            $payment = Payment::query()
                ->with([
                    'bankTransferDetails',
                    'bankTransferDetails.files',
                ])
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            $this->validateBankTransferPayment(
                $payment
            );

            /*
             * =========================================================
             * 4. IDEMPOTENT COMPLETION
             * =========================================================
             *
             * If another request already completed this payment,
             * simply return the completed payment.
             */
            if ($this->isCompleted($payment)) {
                return $payment->fresh([
                    'invoice',
                    'citizen',
                    'bankTransferDetails.bankAccount',
                    'bankTransferDetails.files',
                    'processedBy',
                    'verifiedBy',
                    'receipt.issuedBy',
                ]);
            }

            /*
             * =========================================================
             * 5. ONLY PENDING PAYMENTS CAN BE VERIFIED
             * =========================================================
             */
            if (!$this->isPending($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only pending bank-transfer payments can be verified.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 6. AUTHORIZE VERIFICATION
             * =========================================================
             */
            // $this->authorizeVerification($user);

            /*
             * =========================================================
             * 7. GET BANK TRANSFER DETAILS
             * =========================================================
             */
            $bankDetails =
                $payment->bankTransferDetails;

            if (!$bankDetails) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Bank-transfer details were not found.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 8. ONLY PENDING DETAILS CAN BE VERIFIED
             * =========================================================
             */
            if (
                $this->verificationStatus($bankDetails)
                !== self::VERIFICATION_PENDING
            ) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only bank transfers pending verification can be verified.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 9. REVALIDATE INVOICE
             * =========================================================
             */
            $this->validateInvoiceForPayment(
                $invoice
            );

            /*
             * =========================================================
             * 10. RECHECK OUTSTANDING BALANCE
             * =========================================================
             */
            $paymentAmount =
                $this->normalizeAmount(
                    $payment->amount
                );

            $outstanding =
                $this->calculateOutstandingAmount(
                    $invoice
                );

            if (
                bccomp(
                    $paymentAmount,
                    $outstanding,
                    self::MONEY_SCALE
                ) > 0
            ) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'The bank transfer amount now exceeds the outstanding invoice balance.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 11. MARK BANK TRANSFER AS VERIFIED
             * =========================================================
             */
            $bankDetails->verification_status =
                self::VERIFICATION_VERIFIED;

            $bankDetails->verified_by =
                $user->id;

            $bankDetails->verified_at =
                now();

            if (
                $verificationNotes !== null
                && trim($verificationNotes) !== ''
            ) {
                $existingNotes =
                    trim(
                        (string) (
                            $bankDetails->notes ?? ''
                        )
                    );

                $verificationNote =
                    trim($verificationNotes);

                $bankDetails->notes =
                    $existingNotes !== ''
                        ? $existingNotes
                            . PHP_EOL
                            . 'Verification: '
                            . $verificationNote
                        : 'Verification: '
                            . $verificationNote;
            }

            $bankDetails->save();

            /*
             * =========================================================
             * 12. COMPLETE PAYMENT
             * =========================================================
             */
            $payment->status =
                PaymentStatus::COMPLETED;

            $payment->verified_by =
                $user->id;

            $payment->verified_at =
                now();

            $payment->failure_reason = null;

            $payment->save();

            /*
             * =========================================================
             * 13. CREATE OFFICIAL RECEIPT
             * =========================================================
             */
            $receipt = $this->receiptService->create(
                payment: $payment,
                user: $user,
            );

            /*
             * =========================================================
             * 14. APPLY COMPLETED PAYMENT TO INVOICE
             * =========================================================
             */
            $this->applyPaymentToInvoice(
                invoice: $invoice,
            );

            /*
             * =========================================================
             * 15. AUDIT LOG
             * =========================================================
             */
            Log::info(
                'Bank transfer payment verified and completed.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'transfer_reference' =>
                        $bankDetails->transfer_reference,

                    'receipt_id' =>
                        $receipt->id,

                    'receipt_number' =>
                        $receipt->receipt_number,

                    'invoice_id' =>
                        $invoice->id,

                    'amount' =>
                        $paymentAmount,

                    'currency' =>
                        $payment->currency,

                    'payment_method' =>
                        PaymentMethod::BANK_TRANSFER->value,

                    'payment_source' =>
                        $payment->payment_source,

                    'status' =>
                        PaymentStatus::COMPLETED->value,

                    'verification_status' =>
                        self::VERIFICATION_VERIFIED,

                    'processed_by' =>
                        $payment->processed_by,

                    'verified_by' =>
                        $user->id,

                    'invoice_paid_amount' =>
                        $invoice->paid_amount,

                    'invoice_balance_due' =>
                        $invoice->balance_due,

                    'invoice_status' =>
                        $invoice->status?->value
                        ?? $invoice->status,
                ]
            );

            /*
             * =========================================================
             * 16. RETURN FULLY LOADED PAYMENT
             * =========================================================
             */
            return $payment->fresh([
                'invoice',
                'citizen',
                'bankTransferDetails.bankAccount',
                'bankTransferDetails.files',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
            ]);
        });
    }

    /**
     * Reject a pending bank-transfer payment.
     *
     * PENDING → FAILED
     * PENDING → REJECTED
     *
     * Rejection does not affect invoice financial values because
     * FAILED payments are not included in the completed-payment sum.
     */
    public function reject(
        string $paymentId,
        User $user,
        string $reason,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $user,
            $reason,
            $requestId
        ): Payment {
            /*
             * =========================================================
             * 1. FIND PAYMENT REFERENCE
             * =========================================================
             */
            $paymentReference = Payment::query()
                ->whereKey($paymentId)
                ->first();

            if (!$paymentReference) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            $this->validateBankTransferPayment(
                $paymentReference
            );

            /*
             * =========================================================
             * 2. LOCK INVOICE FIRST
             * =========================================================
             */
            $invoice = Invoice::query()
                ->whereKey($paymentReference->invoice_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice' => [
                        'The invoice associated with this payment does not exist.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 3. LOCK PAYMENT SECOND
             * =========================================================
             */
            $payment = Payment::query()
                ->with([
                    'bankTransferDetails',
                    'bankTransferDetails.files',
                ])
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        Payment::class,
                        [$paymentId]
                    );
            }

            $this->validateBankTransferPayment(
                $payment
            );

            /*
             * =========================================================
             * 4. COMPLETED PAYMENTS CANNOT BE REJECTED
             * =========================================================
             */
            if ($this->isCompleted($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'A completed bank transfer cannot be rejected. Use the payment reversal process instead.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 5. ONLY PENDING PAYMENTS CAN BE REJECTED
             * =========================================================
             */
            if (!$this->isPending($payment)) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only pending bank-transfer payments can be rejected.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 6. AUTHORIZE REJECTION
             * =========================================================
             */
            $this->authorizeVerification($user);

            /*
             * =========================================================
             * 7. VALIDATE REJECTION REASON
             * =========================================================
             */
            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [
                        'A rejection reason is required.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 8. GET BANK TRANSFER DETAILS
             * =========================================================
             */
            $bankDetails =
                $payment->bankTransferDetails;

            if (!$bankDetails) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Bank-transfer details were not found.',
                    ],
                ]);
            }

            if (
                $this->verificationStatus($bankDetails)
                !== self::VERIFICATION_PENDING
            ) {
                throw ValidationException::withMessages([
                    'payment' => [
                        'Only bank transfers pending verification can be rejected.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * 9. RECORD REJECTION
             * =========================================================
             *
             * Current bank_transfer_details migration does not have
             * rejected_by, rejected_at, or rejection_reason columns.
             *
             * Therefore the reason is stored in notes.
             */
            $bankDetails->verification_status =
                self::VERIFICATION_REJECTED;

            $existingNotes =
                trim(
                    (string) (
                        $bankDetails->notes ?? ''
                    )
                );

            $rejectionNote =
                'Rejection: ' . $reason;

            $bankDetails->notes =
                $existingNotes !== ''
                    ? $existingNotes
                        . PHP_EOL
                        . $rejectionNote
                    : $rejectionNote;

            $bankDetails->save();

            /*
             * =========================================================
             * 10. MARK PAYMENT AS FAILED
             * =========================================================
             */
            $payment->status =
                PaymentStatus::FAILED;

            $payment->failure_reason =
                $reason;

            $payment->save();

            /*
             * =========================================================
             * 11. AUDIT LOG
             * =========================================================
             */
            Log::warning(
                'Bank transfer payment rejected.',
                [
                    'request_id' =>
                        $requestId,

                    'payment_id' =>
                        $payment->id,

                    'payment_number' =>
                        $payment->payment_number,

                    'transaction_reference' =>
                        $payment->transaction_reference,

                    'transfer_reference' =>
                        $bankDetails->transfer_reference,

                    'invoice_id' =>
                        $payment->invoice_id,

                    'amount' =>
                        $payment->amount,

                    'currency' =>
                        $payment->currency,

                    'payment_method' =>
                        PaymentMethod::BANK_TRANSFER->value,

                    'status' =>
                        PaymentStatus::FAILED->value,

                    'verification_status' =>
                        self::VERIFICATION_REJECTED,

                    'rejected_by' =>
                        $user->id,

                    'reason' =>
                        $reason,
                ]
            );

            /*
             * =========================================================
             * 12. RETURN FULLY LOADED PAYMENT
             * =========================================================
             */
            return $payment->fresh([
                'invoice',
                'citizen',
                'bankTransferDetails.bankAccount',
                'bankTransferDetails.files',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
            ]);
        });
    }

    /**
     * Find a bank-transfer payment.
     */
    public function find(
        string $paymentId,
        User $user,
    ): Payment {
        $payment = Payment::query()
            ->with([
                'invoice',
                'citizen',
                'bankTransferDetails.bankAccount',
                'bankTransferDetails.files',
                'processedBy',
                'verifiedBy',
                'receipt.issuedBy',
            ])
            ->whereKey($paymentId)
            ->first();

        if (!$payment) {
            throw (new ModelNotFoundException())
                ->setModel(
                    Payment::class,
                    [$paymentId]
                );
        }

        $this->validateBankTransferPayment(
            $payment
        );

        $this->authorizeView(
            payment: $payment,
            user: $user,
        );

        return $payment;
    }

    /**
     * Get pending bank-transfer payments.
     *
     * This is the verification queue.
     */
    public function pending(
        User $user,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->authorizeVerification(
            $user
        );

        return Payment::query()
            ->with([
                'invoice',
                'citizen',
                'bankTransferDetails.bankAccount',
                'bankTransferDetails.files',
                'processedBy',
            ])
            ->where(
                'payment_method',
                PaymentMethod::BANK_TRANSFER->value
            )
            ->where(
                'status',
                PaymentStatus::PENDING->value
            )
            ->whereHas(
                'bankTransferDetails',
                function ($query) {
                    $query->where(
                        'verification_status',
                        self::VERIFICATION_PENDING
                    );
                }
            )
            ->latest('created_at')
            ->paginate(
                min(
                    max($perPage, 1),
                    100
                )
            );
    }

    /**
     * Validate invoice payment eligibility.
     */
    protected function validateInvoiceForPayment(
        Invoice $invoice,
    ): void {
        $status =
            $this->invoiceStatus($invoice);

        if (
            !in_array(
                $status,
                self::PAYABLE_INVOICE_STATUSES,
                true
            )
        ) {
            if ($status === 'DRAFT') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has not been issued and cannot receive a payment.',
                    ],
                ]);
            }

            if ($status === 'PAID') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has already been fully paid.',
                    ],
                ]);
            }

            if ($status === 'CANCELLED') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has been cancelled and cannot receive a payment.',
                    ],
                ]);
            }

            if ($status === 'VOID') {
                throw ValidationException::withMessages([
                    'invoice_id' => [
                        'This invoice has been voided and cannot receive a payment.',
                    ],
                ]);
            }

            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice is not currently payable.',
                ],
            ]);
        }

        $outstanding =
            $this->calculateOutstandingAmount(
                $invoice
            );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'invoice_id' => [
                    'This invoice has no outstanding balance.',
                ],
            ]);
        }
    }

    /**
     * Ensure the payment is a bank transfer.
     */
    protected function validateBankTransferPayment(
        Payment $payment,
    ): void {
        $method = strtoupper(
            (string) (
                $payment->payment_method?->value
                ?? $payment->payment_method
                ?? ''
            )
        );

        if (
            $method !== PaymentMethod::BANK_TRANSFER->value
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'The selected payment is not a bank transfer.',
                ],
            ]);
        }
    }

    /**
     * Authorize bank-transfer verification/rejection.
     */
    protected function authorizeVerification(
        User $user,
    ): void {
        if (
            !$user->can(
                'BANK_TRANSFER_PAYMENTS_VERIFY'
            )
        ) {
            throw new AuthorizationException(
                'You are not authorized to verify bank transfer payments.'
            );
        }
    }

    /**
     * Authorize viewing a bank-transfer payment.
     */
    protected function authorizeView(
        Payment $payment,
        User $user,
    ): void {
        if (
            !$user->can(
                'view',
                $payment
            )
        ) {
            throw new AuthorizationException(
                'You are not authorized to view this payment.'
            );
        }
    }

    /**
     * Ensure a bank transfer reference is unique for the
     * destination municipal bank account.
     */
    protected function ensureTransferReferenceIsUnique(
        string $transferReference,
        string $bankAccountId,
    ): void {
        $exists = BankTransferDetail::query()
            ->where(
                'transfer_reference',
                $transferReference
            )
            ->where(
                'bank_account_id',
                $bankAccountId
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'transfer_reference' => [
                    'This bank transfer reference has already been submitted for the selected municipal bank account.',
                ],
            ]);
        }
    }

    /**
     * Calculate authoritative outstanding invoice balance.
     *
     * Only COMPLETED payments reduce the balance.
     */
    protected function calculateOutstandingAmount(
        Invoice $invoice,
    ): string {
        $invoiceTotal =
            $this->invoiceTotal($invoice);

        $completedAmount =
            Payment::query()
                ->where(
                    'invoice_id',
                    $invoice->getKey()
                )
                ->where(
                    'status',
                    PaymentStatus::COMPLETED->value
                )
                ->sum('amount');

        $completedAmount =
            $this->normalizeMoney(
                $completedAmount
            );

        if (
            bccomp(
                $completedAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) >= 0
        ) {
            return '0.0000';
        }

        $outstanding =
            bcsub(
                $invoiceTotal,
                $completedAmount,
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $outstanding,
                '0.0000',
                self::MONEY_SCALE
            ) < 0
        ) {
            return '0.0000';
        }

        return $outstanding;
    }

    /**
     * Get invoice total.
     */
    protected function invoiceTotal(
        Invoice $invoice,
    ): string {
        if ($invoice->total_amount === null) {
            throw new RuntimeException(
                'Unable to determine the invoice total amount.'
            );
        }

        return $this->normalizeMoney(
            $invoice->total_amount
        );
    }

    /**
     * Recalculate invoice financial state.
     *
     * Only COMPLETED payments are included.
     */
    protected function applyPaymentToInvoice(
        Invoice $invoice,
    ): void {
        /*
         * =========================================================
         * 1. INVOICE TOTAL
         * =========================================================
         */
        $invoiceTotal =
            $this->invoiceTotal($invoice);

        /*
         * =========================================================
         * 2. COMPLETED PAYMENTS
         * =========================================================
         */
        $completedAmount =
            Payment::query()
                ->where(
                    'invoice_id',
                    $invoice->getKey()
                )
                ->where(
                    'status',
                    PaymentStatus::COMPLETED->value
                )
                ->sum('amount');

        $paidAmount =
            $this->normalizeMoney(
                $completedAmount
            );

        /*
         * =========================================================
         * 3. OVERPAYMENT PROTECTION
         * =========================================================
         */
        if (
            bccomp(
                $paidAmount,
                $invoiceTotal,
                self::MONEY_SCALE
            ) > 0
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'Completed payments exceed the invoice total.',
                ],
            ]);
        }

        /*
         * =========================================================
         * 4. BALANCE DUE
         * =========================================================
         */
        $balanceDue =
            bcsub(
                $invoiceTotal,
                $paidAmount,
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $balanceDue,
                '0.0000',
                self::MONEY_SCALE
            ) < 0
        ) {
            $balanceDue =
                '0.0000';
        }

        /*
         * =========================================================
         * 5. UPDATE FINANCIAL VALUES
         * =========================================================
         */
        $invoice->paid_amount =
            $paidAmount;

        $invoice->balance_due =
            $balanceDue;

        /*
         * =========================================================
         * 6. UPDATE INVOICE STATUS
         * =========================================================
         */
        if (
            bccomp(
                $balanceDue,
                '0.0000',
                self::MONEY_SCALE
            ) === 0
        ) {
            $invoice->status =
                'PAID';

            if ($invoice->paid_at === null) {
                $invoice->paid_at =
                    now();
            }
        } else {
            $invoice->status =
                'PARTIALLY_PAID';

            $invoice->paid_at =
                null;
        }

        /*
         * =========================================================
         * 7. SAVE
         * =========================================================
         */
        $invoice->save();
    }

    /**
     * Store bank-transfer evidence.
     *
     * The File belongs to BankTransferDetail through the
     * polymorphic fileable relationship.
     */
    protected function storeEvidence(
        UploadedFile $file,
        BankTransferDetail $bankDetails,
        User $user,
    ): void {
        if (!$file->isValid()) {
            throw ValidationException::withMessages([
                'evidence' => [
                    'The uploaded bank-transfer evidence is invalid.',
                ],
            ]);
        }

        /*
         * StorageService:
         *
         *     UploadedFile
         *         ↓
         *     physical storage
         *         +
         *     files table
         */
        $storedFile = $this->storageService->upload(
            uploadedFile: $file,
            folder: self::EVIDENCE_FOLDER,
            uploadedBy: $user->id,
            category: self::EVIDENCE_COLLECTION,
            visibility: self::EVIDENCE_VISIBILITY,
        );

        /*
         * Establish:
         *
         *     files.fileable_type
         *     files.fileable_id
         *
         * pointing to BankTransferDetail.
         */
        $this->storageService->attachToModel(
            file: $storedFile,
            model: $bankDetails,
        );
    }

    /**
     * Get bank-transfer verification status.
     */
    protected function verificationStatus(
        BankTransferDetail $details,
    ): string {
        return strtoupper(
            (string) (
                $details->verification_status?->value
                ?? $details->verification_status
                ?? ''
            )
        );
    }

    /**
     * Determine whether payment is pending.
     */
    protected function isPending(
        Payment $payment,
    ): bool {
        return $this->paymentStatus($payment)
            === PaymentStatus::PENDING->value;
    }

    /**
     * Determine whether payment is completed.
     */
    protected function isCompleted(
        Payment $payment,
    ): bool {
        return $this->paymentStatus($payment)
            === PaymentStatus::COMPLETED->value;
    }

    /**
     * Get normalized payment status.
     */
    protected function paymentStatus(
        Payment $payment,
    ): string {
        return strtoupper(
            (string) (
                $payment->status?->value
                ?? $payment->status
                ?? ''
            )
        );
    }

    /**
     * Get normalized invoice status.
     */
    protected function invoiceStatus(
        Invoice $invoice,
    ): string {
        return strtoupper(
            (string) (
                $invoice->status?->value
                ?? $invoice->status
                ?? ''
            )
        );
    }

    /**
     * Generate internal municipal transaction reference.
     *
     * Example:
     *
     *     TXN-01M44CA6FD18N4K3DG7VY47J4K
     */
    protected function generateTransactionReference(): string
    {
        return 'TXN-' .
            strtoupper(
                Str::ulid()->toBase32()
            );
    }

    /**
     * Normalize a positive payment amount.
     *
     * BCMath is used to avoid binary floating-point arithmetic.
     */
    protected function normalizeAmount(
        mixed $amount,
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'A payment amount is required.',
                ],
            ]);
        }

        $value =
            trim((string) $amount);

        /*
         * Reject scientific notation and malformed decimals.
         */
        if (
            !preg_match(
                '/^\d+(?:\.\d+)?$/',
                $value
            )
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be a valid decimal number.',
                ],
            ]);
        }

        /*
         * Maximum four decimal places.
         */
        $decimalPosition =
            strpos($value, '.');

        if (
            $decimalPosition !== false
            && strlen($value)
                - $decimalPosition
                - 1
                > self::MONEY_SCALE
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount may have up to four decimal places.',
                ],
            ]);
        }

        /*
         * Must be greater than zero.
         */
        if (
            bccomp(
                $value,
                '0',
                self::MONEY_SCALE
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be greater than zero.',
                ],
            ]);
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }

    /**
     * Normalize a monetary value where zero is allowed.
     */
    protected function normalizeMoney(
        mixed $amount,
    ): string {
        if (
            $amount === null
            || $amount === ''
        ) {
            return '0.0000';
        }

        $value =
            trim((string) $amount);

        if (!is_numeric($value)) {
            throw new RuntimeException(
                'Invalid monetary value.'
            );
        }

        return bcadd(
            $value,
            '0',
            self::MONEY_SCALE
        );
    }
}

