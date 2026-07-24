<?php
/**
 * User — 用户实体
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Domain;

final class User
{
    public int $id;
    public string $email;
    public string $passHash;
    public string $tier;
    public string $createdAt;

    public function __construct(int $id, string $email, string $passHash = '', string $tier = 'community', string $createdAt = '')
    {
        $this->id = $id; $this->email = $email; $this->passHash = $passHash;
        $this->tier = $tier; $this->createdAt = $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s');
    }
}
