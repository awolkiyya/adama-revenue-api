<?php

namespace App\Services;

use App\Models\CitizenSequence;
use Andegna\DateTime as EthiopianDateTime;
use Illuminate\Support\Facades\DB;

class CitizenUidService
{
    public function generate(): string
    {
        $year = (new EthiopianDateTime(now()))
            ->getYear();


        $sequence = DB::transaction(function () use ($year) {

            $sequence = CitizenSequence::lockForUpdate()
                ->firstOrCreate(
                    [
                        'year' => $year,
                    ],
                    [
                        'last_number' => 0,
                    ]
                );


            $sequence->increment('last_number');


            return $sequence->fresh();

        });


        return 'CIT-' .
            $year .
            '-' .
            str_pad(
                $sequence->last_number,
                6,
                '0',
                STR_PAD_LEFT
            );
    }
}