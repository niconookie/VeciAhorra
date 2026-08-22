<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Minimarket\Onboarding\Account;
interface PendingUserGateway
{
    /** @return list<PendingUser> */ public function findByEmail(string $email):array;
    public function findByLogin(string $login):?PendingUser;
    public function find(int $id):?PendingUser;
    public function create(string $login,string $email,SensitivePassword $password):PendingUser;
    public function isLoginOccupied(string $login):bool;
    public function isCompatible(PendingUser $user,int $applicationId):bool;
    public function canCompensate(PendingUser $user,int $applicationId):bool;
    public function compensate(PendingUser $user):bool;
}
