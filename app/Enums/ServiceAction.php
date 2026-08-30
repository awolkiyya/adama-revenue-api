<?php

namespace App\Enums;

enum ServiceAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case SUBMIT = 'SUBMIT';

    case VERIFY = 'VERIFY';
    case ASSESS = 'ASSESS';
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';

    case PAYMENT = 'PAYMENT';

    case COMPLETE = 'COMPLETE';
    case CANCEL = 'CANCEL';
}