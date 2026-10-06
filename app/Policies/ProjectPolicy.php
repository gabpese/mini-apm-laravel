<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A project belongs to one user. Someone else's project answers 404, not 403,
 * so the existence of other people's projects is not revealed.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        return $this->owns($user, $project);
    }

    public function update(User $user, Project $project): Response
    {
        return $this->owns($user, $project);
    }

    public function delete(User $user, Project $project): Response
    {
        return $this->owns($user, $project);
    }

    private function owns(User $user, Project $project): Response
    {
        return $user->id === $project->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
