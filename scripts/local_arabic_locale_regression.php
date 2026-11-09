<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\StorefrontLocale;
use Illuminate\Http\Request;

$cases = [
    [Request::create('/?locale=ar'), 'ar'],
    [Request::create('/?locale=en'), 'en'],
    [Request::create('/?locale=fr'), 'en'],
    [Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'ar-SA,ar;q=0.9,en;q=0.8']), 'ar'],
    [Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,ar;q=0.8']), 'en'],
    [Request::create('/?locale=en', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'ar-SA']), 'en'],
    [Request::create('/'), 'en'],
];

foreach ($cases as $index => [$request, $expected]) {
    if (StorefrontLocale::resolve($request) !== $expected) {
        fwrite(STDERR, 'FAIL: locale case ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
    echo 'PASS: locale case ' . ($index + 1) . PHP_EOL;
}
if (StorefrontLocale::text('English', 'العربية', 'ar') !== 'العربية'
    || StorefrontLocale::text('English', '', 'ar') !== 'English'
    || StorefrontLocale::text('English', null, 'ar') !== 'English'
    || StorefrontLocale::text('English', 'العربية', 'en') !== 'English') {
    fwrite(STDERR, "FAIL: content fallback\n");
    exit(1);
}
echo "PASS: Arabic content fallback\nALL LOCALE CHECKS PASSED\n";
