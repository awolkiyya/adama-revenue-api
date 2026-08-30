<?php

namespace App\Supports;

final class SystemLogAction
{
    private function __construct()
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD
    |--------------------------------------------------------------------------
    */

    public const CREATE = 'CREATE';

    public const UPDATE = 'UPDATE';

    public const DELETE = 'DELETE';

    public const VIEW = 'VIEW';


    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public const LOGIN = 'LOGIN';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const LOGOUT = 'LOGOUT';

    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    public const PASSWORD_RESET = 'PASSWORD_RESET';


    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public const PERMISSION_DENIED = 'PERMISSION_DENIED';


    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_CHANGED = 'STATUS_CHANGED';


    /*
    |--------------------------------------------------------------------------
    | Workflow
    |--------------------------------------------------------------------------
    */

    public const SUBMIT = 'SUBMIT';

    public const APPROVE = 'APPROVE';

    public const REJECT = 'REJECT';

    public const REVIEW = 'REVIEW';

    public const CLOSE = 'CLOSE';


    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    public const EXPORT = 'EXPORT';

    public const IMPORT = 'IMPORT';

    public const DOWNLOAD = 'DOWNLOAD';


    /*
    |--------------------------------------------------------------------------
    | Financial
    |--------------------------------------------------------------------------
    */

    public const PAYMENT = 'PAYMENT';

    public const REFUND = 'REFUND';

    public const CANCEL = 'CANCEL';

    public const VOID = 'VOID';
}