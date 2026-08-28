<?php

declare(strict_types=1);

use VeciAhorra\Core\Session;

$earlyCase = $argv[1] ?? '';
if (in_array($earlyCase, ['anonymous_503', 'user_meta_503'], true)) {
    eval('namespace VeciAhorra\\Core; final class Session { public static function putVerifiedAndClose(string $key, mixed $value): bool { return false; } public static function get(string $key, mixed $default=null): mixed { return $default; } }');
    eval('namespace VeciAhorra\\Modules\\Sectorization; final class ServiceZoneRepository { public function findActive(int $id): ?array { return ["id"=>$id,"name"=>"fixture","commune"=>"fixture"]; } public function active(): array { return []; } }');
    eval('class WP_REST_Request extends ArrayObject {} class WP_REST_Response { public function __construct(public array $data, public int $status=200) {} }');
    $GLOBALS['va_routes'] = [];
    function add_action(string $hook, callable $callback): void {}
    function add_submenu_page(...$args): void {}
    function register_rest_route(string $namespace, string $route, array $definition): void { $GLOBALS['va_routes'][$route] = $definition; }
    function is_user_logged_in(): bool { return ($GLOBALS['va_case'] ?? '') === 'user_meta_503'; }
    function get_current_user_id(): int { return 77; }
    function update_user_meta(int $userId, string $key, mixed $value): bool|int { return false; }
    function get_user_meta(int $userId, string $key, bool $single): mixed { return ''; }
    $GLOBALS['va_case'] = $earlyCase;
    require_once dirname(__DIR__, 2) . '/app/Modules/Sectorization/CurrentSector.php';
    require_once dirname(__DIR__, 2) . '/app/Modules/Sectorization/SectorizationModule.php';
    (new VeciAhorra\Modules\Sectorization\SectorizationModule())->routes();
    $definition = $GLOBALS['va_routes']['/sector/current/(?P<id>\d+)'];
    $response = $definition['callback'](new WP_REST_Request(['id' => 1]));
    if (! $response instanceof WP_REST_Response || $response->status !== 503 || ($response->data['error']['code'] ?? '') !== 'sector_persistence_failed') {
        throw new RuntimeException($earlyCase . '_contract_failed');
    }
    echo $earlyCase . "=PASS\n";
    exit(0);
}

require_once dirname(__DIR__, 2) . '/app/Core/Session.php';

final class ContractSessionHandler implements SessionHandlerInterface
{
    /** @var array<string,string> */
    public static array $storage = [];
    public static string $mode = 'functional';
    public static int $opens = 0;

    public function open(string $path, string $name): bool
    {
        self::$opens++;
        return self::$mode !== 'initial_fail' && ! (self::$mode === 'reopen_fail' && self::$opens > 1);
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return self::$storage[$id] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        if (self::$mode === 'write_false') {
            return false;
        }
        if (self::$mode === 'lying') {
            return true;
        }
        if (self::$mode === 'mismatch') {
            self::$storage[$id] = str_replace('target|i:1;', 'target|i:2;', $data);
            return true;
        }
        self::$storage[$id] = $data;
        return true;
    }

    public function destroy(string $id): bool
    {
        unset(self::$storage[$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}

function contractAssert(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

function historicalPut(string $key, mixed $value): bool
{
    if (! Session::start()) {
        return false;
    }
    $_SESSION[$key] = $value;
    $verified = array_key_exists($key, $_SESSION) && $_SESSION[$key] === $value;
    $closed = @session_write_close();
    return $verified && $closed && session_status() === PHP_SESSION_NONE;
}

function runChildCase(string $case): void
{
    ContractSessionHandler::$mode = $case;
    session_set_save_handler(new ContractSessionHandler(), true);
    session_id('contractcase');

    if ($case === 'functional' || $case === 'idempotent') {
        contractAssert(@session_start(), 'seed_start_failed');
        $_SESSION['foreign_a'] = 'preserved_a';
        $_SESSION['foreign_b'] = ['preserved_b'];
        if ($case === 'idempotent') {
            $_SESSION['target'] = 1;
        }
        contractAssert(@session_write_close(), 'seed_close_failed');
    }

    if ($case === 'historical_lying') {
        ContractSessionHandler::$mode = 'lying';
        contractAssert(historicalPut('target', 1), 'historical_did_not_false_pass');
        echo "historical_lying=PASS\n";
        return;
    }

    $expected = in_array($case, ['functional', 'idempotent'], true);
    $actual = Session::putVerifiedAndClose('target', 1);
    contractAssert($actual === $expected, $case . '_unexpected_result');
    contractAssert(session_status() === PHP_SESSION_NONE, $case . '_left_active');

    if ($expected) {
        contractAssert(@session_start(), $case . '_final_reopen_failed');
        contractAssert(($_SESSION['target'] ?? null) === 1, $case . '_target_missing');
        contractAssert(($_SESSION['foreign_a'] ?? null) === 'preserved_a', $case . '_foreign_a_missing');
        contractAssert(($_SESSION['foreign_b'] ?? null) === ['preserved_b'], $case . '_foreign_b_missing');
        contractAssert(@session_write_close(), $case . '_final_close_failed');
        contractAssert(session_status() === PHP_SESSION_NONE, $case . '_final_active');
    }

    echo $case . "=PASS\n";
}

$childCase = $argv[1] ?? '';
if ($childCase !== '') {
    runChildCase($childCase);
    exit(0);
}

$cases = ['functional', 'write_false', 'lying', 'mismatch', 'reopen_fail', 'initial_fail', 'idempotent', 'historical_lying', 'anonymous_503', 'user_meta_503'];
foreach ($cases as $case) {
    $command = [PHP_BINARY, __FILE__, $case];
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    contractAssert(is_resource($process), 'child_process_failed');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    contractAssert($exit === 0, $case . '_child_failed');
    contractAssert($stderr === '', $case . '_stderr_not_empty');
    contractAssert($stdout === $case . "=PASS\n", $case . '_unsafe_or_unexpected_output');
    echo strtoupper($case) . "=PASS\n";
}

echo "SESSION_PUT_VERIFIED_AND_CLOSE=PASS\n";
