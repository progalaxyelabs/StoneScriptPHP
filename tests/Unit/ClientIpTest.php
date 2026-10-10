<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Application;
use StoneScriptPHP\Env;
use StoneScriptPHP\Http\ClientIp;
use StoneScriptPHP\RequestLogging\RequestLogger;
use StoneScriptPHP\Routing\Middleware\RateLimitMiddleware;
use StoneScriptPHP\Security\RateLimiter;

/**
 * client_ip() / ClientIp: spoof-safe by default, trusted-proxy aware.
 */
final class ClientIpTest extends TestCase
{
    private array $serverBackup = [];
    private array $envBackup = [];

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
        // Fresh Env singleton (no constructor => no required-secret boot checks) + fresh ClientIp cache.
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setValue(null, (new \ReflectionClass(Env::class))->newInstanceWithoutConstructor());
        ClientIp::reset();
    }

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        $this->setEnv('TRUSTED_PROXIES', null);
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR', 'TRUSTED_PROXIES', 'TRUST_PROXY'] as $k) {
            unset($_SERVER[$k], $_ENV[$k]);
        }
        putenv('TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        (new \ReflectionProperty(Env::class, '_instance'))->setValue(null, null);
        ClientIp::reset();
    }

    // ---- default: trust nobody ------------------------------------------------

    public function test_default_uses_remote_addr_and_ignores_forged_xff(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['HTTP_X_REAL_IP'] = '5.6.7.8';
        $this->assertSame('198.51.100.7', client_ip());
    }

    public function test_default_ignores_xff_even_from_private_peer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('10.0.0.5', client_ip());
    }

    public function test_unknown_when_no_remote_addr(): void
    {
        $this->assertSame('unknown', client_ip());
    }

    public function test_unknown_when_remote_addr_is_garbage(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $this->assertSame('unknown', client_ip());
    }

    // ---- trusted proxy: right-to-left walk -----------------------------------

    public function test_trusted_peer_uses_rightmost_untrusted_xff_hop(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            ['10.0.0.5']
        );
        $this->assertSame('203.0.113.9', $r);
    }

    public function test_client_prepended_xff_entries_are_ignored(): void
    {
        // attacker sent "XFF: 1.2.3.4"; proxy appended the real peer 203.0.113.9
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9'],
            ['10.0.0.5']
        );
        $this->assertSame('203.0.113.9', $r);
    }

    public function test_multiple_trusted_proxies_are_skipped(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.9, 10.0.1.1'],
            ['10.0.0.0/8']
        );
        $this->assertSame('203.0.113.9', $r);
    }

    public function test_untrusted_peer_cannot_use_xff_even_if_list_configured(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            ['10.0.0.0/8']
        );
        $this->assertSame('198.51.100.7', $r);
    }

    public function test_trusted_peer_without_xff_returns_peer(): void
    {
        $this->assertSame('10.0.0.5', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5'], ['10.0.0.5']));
    }

    public function test_x_real_ip_is_never_consulted_even_from_trusted_peer(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_REAL_IP' => '1.2.3.4'],
            ['10.0.0.5']
        );
        $this->assertSame('10.0.0.5', $r);
    }

    public function test_garbage_hop_falls_back_to_peer(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, garbage'],
            ['10.0.0.5']
        );
        $this->assertSame('10.0.0.5', $r);
    }

    public function test_all_hops_trusted_returns_peer(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.7, 10.0.0.8'],
            ['10.0.0.0/8']
        );
        $this->assertSame('10.0.0.5', $r);
    }

    public function test_empty_xff_entries_and_whitespace(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => ' 203.0.113.9 ,  '],
            ['10.0.0.5']
        );
        // trailing empty hop is garbage: do not guess
        $this->assertSame('10.0.0.5', $r);
    }

    public function test_ipv6_client_and_ipv6_proxy_cidr(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::77'],
            ['fd00::/8']
        );
        $this->assertSame('2001:db8::77', $r);
    }

    public function test_ipv4_mapped_ipv6_peer_is_normalised_and_matches_ipv4_proxy(): void
    {
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '::ffff:10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            ['10.0.0.5']
        );
        $this->assertSame('203.0.113.9', $r);
        $this->assertSame('198.51.100.7', ClientIp::resolve(['REMOTE_ADDR' => '::ffff:198.51.100.7'], []));
    }

    public function test_private_keyword(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
        $this->assertSame('203.0.113.9', ClientIp::resolve($server, ['private']));
        $server['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertSame('198.51.100.7', ClientIp::resolve($server, ['private']));
    }

    public function test_cidr_boundaries(): void
    {
        $server = fn(string $peer) => ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
        $this->assertSame('203.0.113.9', ClientIp::resolve($server('172.16.0.1'), ['172.16.0.0/12']));
        $this->assertSame('203.0.113.9', ClientIp::resolve($server('172.31.255.254'), ['172.16.0.0/12']));
        $this->assertSame('172.32.0.1', ClientIp::resolve($server('172.32.0.1'), ['172.16.0.0/12']));
        $this->assertSame('203.0.113.9', ClientIp::resolve($server('10.1.2.3'), ['10.1.2.3/32']));
        $this->assertSame('10.1.2.4', ClientIp::resolve($server('10.1.2.4'), ['10.1.2.3/32']));
    }

    // ---- config surface -------------------------------------------------------

    public function test_env_configures_trusted_proxies(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.5, 192.168.0.0/16');
        $this->assertSame(['10.0.0.5', '192.168.0.0/16'], ClientIp::trustedProxies());
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';
        $this->assertSame('203.0.113.9', client_ip());
    }

    public function test_env_change_is_picked_up(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.5');
        $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies());
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.6');
        $this->assertSame(['10.0.0.6'], ClientIp::trustedProxies());
    }

    public function test_catch_all_and_invalid_entries_are_rejected(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '*, 0.0.0.0/0, ::/0, 10.0.0.0/33, bogus, 10.0.0.9, 0.0.0.0/1, 8.0.0.0/7, 2001:db8::/31, ::/1');
        $this->assertSame(['10.0.0.9'], ClientIp::trustedProxies());

        // "trust everyone" must not enable spoofing
        $this->setEnv('TRUSTED_PROXIES', '0.0.0.0/0');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('198.51.100.7', client_ip());
    }

    public function test_configure_overrides_env_and_null_restores(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.5');
        ClientIp::configure(['10.0.0.6']);
        $this->assertSame(['10.0.0.6'], ClientIp::trustedProxies());
        ClientIp::configure(null);
        $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies());
    }

    public function test_bootstrap_config_key_wins_over_env(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.5');
        ClientIp::bootstrap(['trusted_proxies' => ['10.9.9.9']]);
        $this->assertSame(['10.9.9.9'], ClientIp::trustedProxies());
        ClientIp::bootstrap(['trusted_proxies' => []]);
        $this->assertSame([], ClientIp::trustedProxies());
    }

    public function test_bootstrap_legacy_trust_proxy_maps_to_private_only(): void
    {
        ClientIp::bootstrap(['request_logging' => ['trust_proxy' => true]]);
        $this->assertSame(['private'], ClientIp::trustedProxies());

        // a public client still cannot forge its IP under the legacy switch
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('198.51.100.7', client_ip());
    }

    public function test_bootstrap_legacy_env_and_explicit_list_precedence(): void
    {
        $this->setEnv('TRUST_PROXY', 'true');
        ClientIp::bootstrap([]);
        $this->assertSame(['private'], ClientIp::trustedProxies());

        $this->setEnv('TRUSTED_PROXIES', '10.0.0.5');
        ClientIp::bootstrap([]);
        $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies(), 'explicit list beats legacy switch');

        $this->setEnv('TRUSTED_PROXIES', null);
        $this->setEnv('TRUST_PROXY', 'false');
        ClientIp::bootstrap([]);
        $this->assertSame([], ClientIp::trustedProxies());
    }

    // ---- review fixes ----------------------------------------------------------

    public function test_bootstrap_before_late_dotenv_still_sees_trusted_proxies(): void
    {
        // bootstrap() runs BEFORE .env is loaded (nothing in env yet) ...
        ClientIp::bootstrap([]);
        // ... .env loads afterwards:
        $this->setEnvKeepConfig('TRUSTED_PROXIES', '10.0.0.5');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
        $this->assertSame('203.0.113.9', client_ip());
    }

    public function test_legacy_switch_never_shadows_explicit_trusted_proxies_loaded_late(): void
    {
        ClientIp::bootstrap(['request_logging' => ['trust_proxy' => true]]);
        $this->setEnvKeepConfig('TRUSTED_PROXIES', '10.0.0.5');
        $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies());

        // and an explicit-but-all-invalid list does not silently fall back to legacy `private`
        $this->setEnvKeepConfig('TRUSTED_PROXIES', '0.0.0.0/0');
        $this->assertSame([], ClientIp::trustedProxies());
    }

    public function test_application_run_configures_trust(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/robots.txt'; // run() returns right after bootstrap
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.9';
        ob_start();
        try {
            Application::run(['trusted_proxies' => ['10.0.0.5'], 'request_logging' => ['enabled' => false]]);
        } finally {
            ob_end_clean();
            RequestLogger::reset();
        }
        $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies());
        $this->assertSame('203.0.113.9', client_ip());
    }

    public function test_ipv6_output_is_canonical(): void
    {
        $s = ['REMOTE_ADDR' => 'FD00:0:0:0:0:0:0:1', 'HTTP_X_FORWARDED_FOR' => '2001:DB8:0:0:0:0:0:0077'];
        $this->assertSame('2001:db8::77', ClientIp::resolve($s, ['fd00::/8']));
        $this->assertSame('fd00::1', ClientIp::resolve(['REMOTE_ADDR' => 'FD00:0:0:0:0:0:0:1'], []));
    }

    public function test_hop_forms_port_brackets_zone(): void
    {
        $p = ['10.0.0.5'];
        $x = fn(string $xff) => ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => $xff], $p);
        $this->assertSame('203.0.113.9', $x('203.0.113.9:51234'));
        $this->assertSame('2001:db8::1', $x('[2001:DB8::1]:443'));
        $this->assertSame('2001:db8::1', $x('[2001:db8::1]'));
        $this->assertSame('10.0.0.5', $x('fe80::1%eth0'), 'zone id is invalid: do not guess');
        $this->assertSame('10.0.0.5', $x('203.0.113.9:99999999'), 'bad port: do not guess');
        $this->assertSame('10.0.0.5', $x('203.0.113.9:80:80'));
    }

    public function test_duplicate_and_joined_xff_headers(): void
    {
        // php-fpm joins repeated header lines with ", " => identical to one header
        $r = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 7.7.7.7, 203.0.113.9, 10.0.0.9'],
            ['10.0.0.0/24']
        );
        $this->assertSame('203.0.113.9', $r);
    }

    public function test_non_byte_aligned_ipv6_cidrs(): void
    {
        $s = fn(string $peer) => ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '2001:db8::77'];
        $this->assertSame('2001:db8::77', ClientIp::resolve($s('fc00::1'), ['fc00::/7']));
        $this->assertSame('2001:db8::77', ClientIp::resolve($s('fdff::1'), ['fc00::/7']));
        $this->assertSame('fe00::1', ClientIp::resolve($s('fe00::1'), ['fc00::/7']));
        $this->assertSame('2001:db8::77', ClientIp::resolve($s('fe80::1'), ['fe80::/10']));
        $this->assertSame('2001:db8::77', ClientIp::resolve($s('febf::1'), ['fe80::/10']));
        $this->assertSame('fec0::1', ClientIp::resolve($s('fec0::1'), ['fe80::/10']));
        $this->assertSame(['fc00::/7', 'fe80::/10'], (function () {
            $this->setEnv('TRUSTED_PROXIES', 'fc00::/7 fe80::/10');
            return ClientIp::trustedProxies();
        })(), 'ULA/link-local blocks are exempt from the floor');
    }

    public function test_private_keyword_covers_cgnat_ula_linklocal_and_not_public(): void
    {
        $s = fn(string $peer) => ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
        foreach (['100.64.0.1', '100.127.255.254', 'fd12:3456::1', 'fe80::2', '169.254.1.1', '172.20.0.1', '127.0.0.1', '::1'] as $peer) {
            $this->assertSame('203.0.113.9', ClientIp::resolve($s($peer), ['private']), $peer);
        }
        foreach (['100.128.0.1', '172.32.0.1', '2001:db8::1', '8.8.8.8'] as $peer) {
            $this->assertSame($peer, ClientIp::resolve($s($peer), ['private']), $peer);
        }
    }

    public function test_prefix_floor(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.0/8 11.0.0.0/8 12.0.0.0/7 100.64.0.0/10 2001:db8::/32 2001::/16 fc00::/7');
        $this->assertSame(['10.0.0.0/8', '11.0.0.0/8', '100.64.0.0/10', '2001:db8::/32', 'fc00::/7'], ClientIp::trustedProxies());
    }

    public function test_ipv4_mapped_entries_are_normalised(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '::ffff:10.0.0.5, ::ffff:10.1.0.0/112, ::ffff:0.0.0.0/80');
        $this->assertSame(['10.0.0.5', '10.1.0.0/16'], ClientIp::trustedProxies());
        $this->assertSame('203.0.113.9', ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            ClientIp::trustedProxies()
        ));
    }

    public function test_env_accessor_honours_secret_file(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ssp_tp_');
        file_put_contents($f, "10.0.0.5\n");
        try {
            $this->setEnv('TRUSTED_PROXIES_FILE', $f);
            $this->assertSame(['10.0.0.5'], ClientIp::trustedProxies());
        } finally {
            unlink($f);
            unset($_ENV['TRUSTED_PROXIES_FILE']);
        }
    }

    public function test_network_prefix(): void
    {
        $this->assertSame('203.0.113', ClientIp::networkPrefix('203.0.113.9'));
        $this->assertSame('203.0.113', ClientIp::networkPrefix('::ffff:203.0.113.9'));
        $this->assertSame('2001:db8:1:2::/64', ClientIp::networkPrefix('2001:DB8:1:2:aaaa::1'));
        $this->assertSame('unknown', ClientIp::networkPrefix('unknown'));
    }

    public function test_unknown_shares_one_bucket(): void
    {
        $this->assertSame('unknown', ClientIp::rateKey('unknown'));
        $this->assertSame('unknown', client_ip());
    }

    public function test_request_logger_shim(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.9';
        $this->assertSame('10.0.0.5', RequestLogger::resolveClientIp(false));
        // true + nothing configured => `private` only
        $this->assertSame('203.0.113.9', RequestLogger::resolveClientIp(true));
        // true + explicit list that does not include the peer => peer
        $this->setEnv('TRUSTED_PROXIES', '10.9.9.9');
        $this->assertSame('10.0.0.5', RequestLogger::resolveClientIp(true));
        // no REMOTE_ADDR => ''
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('', RequestLogger::resolveClientIp(true));
    }

    public function test_csrf_fingerprint_uses_network_prefix(): void
    {
        $h = new \StoneScriptPHP\Security\CsrfTokenHandler('k');
        $m = new \ReflectionMethod($h, 'getClientFingerprint');
        $_SERVER['HTTP_USER_AGENT'] = 'ua';
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
        $a = $m->invoke($h);
        $_SERVER['REMOTE_ADDR'] = '2001:DB8:1:2:ffff::9';
        $this->assertSame($a, $m->invoke($h), 'same /64 => same fingerprint');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
        $this->assertNotSame($a, $m->invoke($h));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $v4 = $m->invoke($h);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
        $this->assertSame($v4, $m->invoke($h));
    }

    public function test_rate_limiter_blacklist_covers_ipv6_slash64_and_whitelist_is_exact(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'ua';
        $rl = new RateLimiter();
        $rl->addToBlacklist('2001:DB8:1:2::1');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:dead:beef:0:9'; // different host, same /64
        $this->assertFalse($rl->check('login'));
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
        $this->assertTrue($rl->check('login'));

        $rl2 = new RateLimiter();
        $rl2->addToWhitelist('2001:DB8:1:2::1');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
        $this->assertTrue($rl2->check('login', 1, 60));
        $rl2->record('login');
        $rl2->record('login');
        $this->assertTrue($rl2->check('login', 1, 60), 'whitelisted exact address (any spelling)');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::2'; // neighbour is NOT whitelisted
        $rl2->record('login');
        $rl2->record('login');
        $this->assertFalse($rl2->check('login', 1, 60));
    }

    /** setEnv() without dropping config recorded by bootstrap() (simulates a late .env load). */
    private function setEnvKeepConfig(string $key, ?string $value): void
    {
        $cfg = new \ReflectionProperty(ClientIp::class, 'configLegacy');
        $legacy = $cfg->getValue();
        $prop = new \ReflectionProperty(Env::class, '_instance');
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
        $prop->setValue(null, (new \ReflectionClass(Env::class))->newInstanceWithoutConstructor());
        $eff = new \ReflectionProperty(ClientIp::class, 'effective');
        $eff->setValue(null, null);
        $cfg->setValue(null, $legacy);
    }

    // ---- rate key / internal --------------------------------------------------

    public function test_rate_key(): void
    {
        $this->assertSame('203.0.113.9', ClientIp::rateKey('203.0.113.9'));
        $this->assertSame('203.0.113.9', ClientIp::rateKey('::ffff:203.0.113.9'));
        $this->assertSame(ClientIp::rateKey('2001:db8:1:2::1'), ClientIp::rateKey('2001:db8:1:2:ffff::9'));
        $this->assertSame('2001:db8:1:2::/64', ClientIp::rateKey('2001:db8:1:2::1'));
        $this->assertNotSame(ClientIp::rateKey('2001:db8:1:2::1'), ClientIp::rateKey('2001:db8:1:3::1'));
    }

    public function test_is_internal(): void
    {
        foreach (['127.0.0.1', '10.1.1.1', '192.168.1.1', '::1', 'garbage'] as $ip) {
            $this->assertTrue(ClientIp::isInternal($ip), $ip);
        }
        $this->assertFalse(ClientIp::isInternal('203.0.113.9'));
        $this->assertFalse(ClientIp::isInternal('8.8.8.8'));
    }

    // ---- callers: spoof cannot bypass IP-keyed protection ---------------------

    public function test_rate_limit_middleware_cannot_be_bypassed_with_forged_xff(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ssp_rl_');
        @unlink($file);
        try {
            $mw = new RateLimitMiddleware(2, 60, $file);
            $_SERVER['REQUEST_URI'] = '/api/x';
            $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
            $results = [];
            foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3', '4.4.4.4'] as $forged) {
                $_SERVER['HTTP_X_FORWARDED_FOR'] = $forged;
                $res = $mw->handle([], fn() => null);
                $results[] = $res === null ? 'pass' : 'blocked';
            }
            $this->assertSame(['pass', 'pass', 'blocked', 'blocked'], $results);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_rate_limiter_bucket_is_not_affected_by_forged_headers(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'ua';
        $rl = new RateLimiter();
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $forged) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forged;
            $rl->record('probe');
        }
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
        // 3 attempts in window counted against ONE identity regardless of header
        $this->assertFalse($rl->check('probe', 3, 60));
        $this->assertTrue($rl->check('probe', 4, 60));
    }

    public function test_rate_limiter_blacklist_applies_despite_forged_xff(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
        $rl = new RateLimiter();
        $rl->addToBlacklist('198.51.100.7');
        $this->assertFalse($rl->check('login'));
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertFalse($rl->check('login'));
    }
}
