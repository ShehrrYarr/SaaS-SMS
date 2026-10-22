<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use EnumOptions;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Card = 'card';
    case Online = 'online';
    case Other = 'other';
}
