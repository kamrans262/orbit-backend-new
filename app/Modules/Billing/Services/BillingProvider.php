<?php
namespace App\Modules\Billing\Services;
/** Network adapters return normalized, server-verified canonical evidence only. */
interface BillingProvider{
    public function ready(string $provider):bool;
    public function reference(string $provider,string $proof):string;
    public function lookup(string $provider,string $reference):array;
    public function notice(string $provider,array $body,?string $bearer):array;
    public function acknowledge(string $provider,string $reference,string $product):void;
}
