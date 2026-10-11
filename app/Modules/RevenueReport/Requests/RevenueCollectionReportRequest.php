<?php

namespace App\Modules\RevenueReport\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RevenueCollectionReportRequest extends FormRequest
{
    /**
     * Authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize optional query-string filters.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_date' => $this->filled('start_date')
                ? trim((string) $this->input('start_date'))
                : null,

            'end_date' => $this->filled('end_date')
                ? trim((string) $this->input('end_date'))
                : null,

            'payment_method' => $this->filled('payment_method')
                ? strtoupper(trim((string) $this->input('payment_method')))
                : null,

            'payment_provider' => $this->filled('payment_provider')
                ? strtoupper(trim((string) $this->input('payment_provider')))
                : null,

            'status' => $this->filled('status')
                ? strtoupper(trim((string) $this->input('status')))
                : null,

            'payment_source' => $this->filled('payment_source')
                ? strtoupper(trim((string) $this->input('payment_source')))
                : null,

            'revenue_domain' => $this->filled('revenue_domain')
                ? strtoupper(trim((string) $this->input('revenue_domain')))
                : null,

            'revenue_category_id' => $this->filled('revenue_category_id')
                ? trim((string) $this->input('revenue_category_id'))
                : null,

            'service_id' => $this->filled('service_id')
                ? trim((string) $this->input('service_id'))
                : null,

            'administrative_unit_id' => $this->filled('administrative_unit_id')
                ? trim((string) $this->input('administrative_unit_id'))
                : null,
        ]);
    }

    /**
     * Validate report filters.
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Reporting Period
            |--------------------------------------------------------------------------
            */

            'start_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'end_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment Filters
            |--------------------------------------------------------------------------
            */

            'payment_method' => [
                'nullable',
                Rule::in([
                    'CASH',
                    'BANK_TRANSFER',
                    'ONLINE',
                ]),
            ],

            'payment_provider' => [
                'nullable',
                Rule::in([
                    'CASH',
                    'BANK',
                    'CHAPA',
                    'TELEBIRR',
                ]),
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'PENDING',
                    'PROCESSING',
                    'COMPLETED',
                    'FAILED',
                    'CANCELLED',
                    'EXPIRED',
                    'REVERSED',
                ]),
            ],

            'payment_source' => [
                'nullable',
                Rule::in([
                    'ONLINE',
                    'AGENT_ASSISTED',
                    'OFFICE_RECORDED',
                    'FIELD_COLLECTION',
                    'SYSTEM',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Revenue Classification
            |--------------------------------------------------------------------------
            */

            'revenue_domain' => [
                'nullable',
                Rule::in([
                    'TAX',
                    'RENT',
                    'INVESTMENT',
                    'SERVICE',
                    'SALE',
                    'CAPITAL',
                ]),
            ],

            'revenue_category_id' => [
                'nullable',
                'uuid',
                'exists:revenue_categories,id',
            ],

            'service_id' => [
                'nullable',
                'uuid',
                'exists:revenue_services,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Administrative Unit
            |--------------------------------------------------------------------------
            */

            'administrative_unit_id' => [
                'nullable',
                'uuid',
                'exists:administrative_units,id',
            ],
        ];
    }

    /**
     * Validate relationships between selected classification filters.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $categoryId = $this->input('revenue_category_id');
                $serviceId = $this->input('service_id');
                $domain = $this->input('revenue_domain');

                /*
                 * A selected service must belong to the selected
                 * category and/or revenue domain.
                 */
                if ($serviceId && ($categoryId || $domain)) {
                    $serviceQuery = DB::table('revenue_services')
                        ->join(
                            'revenue_codes',
                            'revenue_codes.id',
                            '=',
                            'revenue_services.revenue_code_id'
                        )
                        ->join(
                            'revenue_categories',
                            'revenue_categories.id',
                            '=',
                            'revenue_codes.category_id'
                        )
                        ->where('revenue_services.id', $serviceId)
                        ->whereNull('revenue_services.deleted_at')
                        ->whereNull('revenue_codes.deleted_at')
                        ->whereNull('revenue_categories.deleted_at');

                    if ($categoryId) {
                        $serviceQuery->where(
                            'revenue_categories.id',
                            $categoryId
                        );
                    }

                    if ($domain) {
                        $serviceQuery->where(
                            'revenue_categories.revenue_domain',
                            $domain
                        );
                    }

                    if (!$serviceQuery->exists()) {
                        $validator->errors()->add(
                            'service_id',
                            'The selected service does not belong to the selected revenue category or domain.'
                        );
                    }
                }

                /*
                 * The selected category must belong to the selected domain.
                 */
                if ($categoryId && $domain) {
                    $categoryMatchesDomain = DB::table('revenue_categories')
                        ->where('id', $categoryId)
                        ->where('revenue_domain', $domain)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$categoryMatchesDomain) {
                        $validator->errors()->add(
                            'revenue_category_id',
                            'The selected revenue category does not belong to the selected revenue domain.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * Human-readable validation messages.
     */
    public function messages(): array
    {
        return [
            'start_date.date_format' =>
                'The start date must use the YYYY-MM-DD format.',

            'end_date.date_format' =>
                'The end date must use the YYYY-MM-DD format.',

            'end_date.after_or_equal' =>
                'The end date must be on or after the start date.',

            'payment_method.in' =>
                'The selected payment method is invalid.',

            'payment_provider.in' =>
                'The selected payment provider is invalid.',

            'status.in' =>
                'The selected payment status is invalid.',

            'payment_source.in' =>
                'The selected payment source is invalid.',

            'revenue_domain.in' =>
                'The selected revenue domain is invalid.',

            'revenue_category_id.uuid' =>
                'The revenue category identifier must be a valid UUID.',

            'revenue_category_id.exists' =>
                'The selected revenue category does not exist.',

            'service_id.uuid' =>
                'The service identifier must be a valid UUID.',

            'service_id.exists' =>
                'The selected revenue service does not exist.',

            'administrative_unit_id.uuid' =>
                'The administrative unit identifier must be a valid UUID.',

            'administrative_unit_id.exists' =>
                'The selected administrative unit does not exist.',
        ];
    }
}