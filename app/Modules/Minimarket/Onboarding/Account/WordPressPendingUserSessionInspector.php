<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
final class WordPressPendingUserSessionInspector implements PendingUserSessionInspector
{
    public function hasActiveSessions(int $userId):bool{return \WP_Session_Tokens::get_instance($userId)->get_all()!==[];}
}
