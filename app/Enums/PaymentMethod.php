<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cod = 'cod';
    case BankTransfer = 'bank_transfer';
    case CreditCard = 'credit_card';
    case EWallet = 'e_wallet';

    /**
     * Methods customers can choose today. Gateway methods are modelled but
     * not integrated in the MVP.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return [self::Cod];
    }
}
