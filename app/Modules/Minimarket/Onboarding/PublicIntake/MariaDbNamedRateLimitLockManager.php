<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class MariaDbNamedRateLimitLockManager implements RateLimitLockManager
{
    public function __construct(private \wpdb $database) {}

    public function synchronized(array $lockNames, callable $criticalSection): mixed
    {
        $locks = array_values(array_unique($lockNames));
        sort($locks, SORT_STRING);
        if ($locks === [] || count($locks) > 4) throw new PublicIntakeException('rate_limit_unavailable');
        foreach ($locks as $lock) if (preg_match('/\Ava-r1c-[a-f0-9]{48}\z/', $lock) !== 1) throw new PublicIntakeException('rate_limit_unavailable');
        $acquired = [];
        $releaseFailed = false;
        try {
            foreach ($locks as $lock) {
                $result = $this->database->get_var($this->database->prepare('SELECT GET_LOCK(%s, %f)', $lock, 0.25));
                if ((string) $result !== '1') throw new PublicIntakeException('rate_limit_unavailable');
                $acquired[] = $lock;
            }
            return $criticalSection();
        } finally {
            foreach (array_reverse($acquired) as $lock) {
                $result = $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                if ((string) $result !== '1') $releaseFailed = true;
            }
            if ($releaseFailed) throw new PublicIntakeException('rate_limit_unavailable');
        }
    }
}
