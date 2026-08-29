<?php
declare(strict_types=1);
namespace NeonLib;

final class AccountId
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function generate(): string
    {
        $bits = '00';
        foreach (str_split(random_bytes(16)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        for ($offset = 0; $offset < 130; $offset += 5) {
            $encoded .= self::ALPHABET[bindec(substr($bits, $offset, 5))];
        }
        return 'acc_' . $encoded;
    }
}
