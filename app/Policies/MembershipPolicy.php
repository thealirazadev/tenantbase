<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

/** Only owners change roles or remove members; the last-owner rule lives on the model. */
class MembershipPolicy
{
    public function update(User $user, Membership $membership): bool
    {
        return Membership::roleOf($user) === 'owner';
    }

    public function delete(User $user, Membership $membership): bool
    {
        return Membership::roleOf($user) === 'owner';
    }
}
