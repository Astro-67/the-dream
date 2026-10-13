<?php

use App\Support\Money\Currency;
use App\Support\Money\Money;

test('money adds integer minor units of one currency', function () {
    $sum = (new Money(1050, Currency::USD))->plus(new Money(25, Currency::USD));

    expect($sum->amount)->toBe(1075)->and($sum->currency)->toBe(Currency::USD);
});

test('adding money in different currencies throws', function () {
    (new Money(1000, Currency::USD))->plus(new Money(1000, Currency::TZS));
})->throws(InvalidArgumentException::class, 'Cannot add TZS to USD.');
