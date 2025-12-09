<?php

declare(strict_types=1);

use Adichan\Transaction\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/* use Orchestra\Testbench\TestCase; */

pest()->extend(TestCase::class)
    /* ->use(RefreshDatabase::class) */
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void {}

/**/
/* function withPackageProviders(): void */
/* { */
/*     config([ */
/*         'database.default' => 'testing', */
/*         'database.connections.testing' => [ */
/*             'driver' => 'sqlite', */
/*             'database' => ':memory:', */
/*             'prefix' => '', */
/*         ], */
/*     ]); */
/**/
/*     (new ProductServiceProvider(app()))->boot(); */
/* } */

function migratePackage(): void
{
    /* Artisan::call('migrate'); */
}
