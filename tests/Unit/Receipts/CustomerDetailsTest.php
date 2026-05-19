<?php
declare(strict_types=1);

use Froshly\Parakit\Receipts\CustomerDetails;

it('builds from an array, ignoring empty and non-string values', function () {
    $c = CustomerDetails::fromArray([
        'name'  => 'Ada Lovelace',
        'email' => '',
        'phone' => 7700900000,
    ]);

    expect($c->name)->toBe('Ada Lovelace')
        ->and($c->email)->toBeNull()
        ->and($c->phone)->toBeNull();
});

it('wraps an array but passes a DTO straight through', function () {
    $dto = new CustomerDetails(name: 'Grace');

    expect(CustomerDetails::wrap($dto))->toBe($dto)
        ->and(CustomerDetails::wrap(['name' => 'Grace'])->name)->toBe('Grace');
});
