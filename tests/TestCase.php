<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        if ($connection !== 'pgsql' || preg_match('/_(test|testing)$/', $database) !== 1
            || config('database.connections.'.$connection.'.url')) {
            throw new RuntimeException('Test hanya boleh memakai PostgreSQL dengan nama database berakhiran _test atau _testing dan DB_URL kosong.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SENOPATI_TESTS') !== '1') {
            config(['ai.senopati_key' => 'test-secret']);
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
