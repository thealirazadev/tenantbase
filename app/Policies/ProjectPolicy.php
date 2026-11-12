<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

/**
 * Role matrix in docs/design.md: every member creates and edits (the membership
 * middleware already guarantees that); only owners and admins delete.
 */
class ProjectPolicy
{
    public function delete(User $user, Project $project): bool
    {
        return in_array(Membership::roleOf($user), ['owner', 'admin'], true);
    }
}
