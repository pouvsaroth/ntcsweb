<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Support\Tenancy\TenantHost;
use Tests\TestCase;

class TenantHostTest extends TestCase
{
    private TenantHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.root_domain' => 'ntcsweb.com']);
        config(['tenancy.central_domains' => ['localhost', 'admin.ntcsweb.com']]);

        $this->host = new TenantHost;
    }

    public function test_normalise_lowercases_and_strips_port(): void
    {
        $this->assertSame('newtech.ntcsweb.com', $this->host->normalise('NewTech.NTCSWEB.com:8080'));
    }

    public function test_normalise_strips_trailing_dot(): void
    {
        $this->assertSame('newtech.ntcsweb.com', $this->host->normalise('newtech.ntcsweb.com.'));
    }

    public function test_central_domains_are_recognised(): void
    {
        $this->assertTrue($this->host->isCentral('localhost'));
        $this->assertTrue($this->host->isCentral('admin.ntcsweb.com'));
        $this->assertFalse($this->host->isCentral('newtech.ntcsweb.com'));
    }

    public function test_subdomain_extraction_matches_a_single_label(): void
    {
        $this->assertSame('newtech', $this->host->subdomainOfRoot('newtech.ntcsweb.com'));
    }

    public function test_subdomain_extraction_rejects_the_bare_root_domain(): void
    {
        $this->assertNull($this->host->subdomainOfRoot('ntcsweb.com'));
    }

    public function test_subdomain_extraction_rejects_a_custom_domain(): void
    {
        $this->assertNull($this->host->subdomainOfRoot('school.example.edu.kh'));
    }

    /**
     * A slug is always exactly one DNS label. Without this guard, a stray
     * "a.b.ntcsweb.com" would be treated as tenant slug "a.b", matching
     * nothing and silently falling through — surprising, not helpful.
     */
    public function test_subdomain_extraction_rejects_multi_level_labels(): void
    {
        $this->assertNull($this->host->subdomainOfRoot('a.b.ntcsweb.com'));
    }

    public function test_cache_key_is_namespaced_and_stable(): void
    {
        $this->assertSame(
            $this->host->cacheKey('newtech.ntcsweb.com'),
            $this->host->cacheKey('newtech.ntcsweb.com'),
        );

        $this->assertNotSame(
            $this->host->cacheKey('newtech.ntcsweb.com'),
            $this->host->cacheKey('abcschool.ntcsweb.com'),
        );
    }
}
