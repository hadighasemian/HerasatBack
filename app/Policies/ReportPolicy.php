<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Workflow visibility (permission based, never role-name based):
 *  - reports.review / reports.view_all : every report
 *  - reports.view_approved             : approved reports only
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.review') || $user->can('reports.view_all') || $user->can('reports.view_approved');
    }

    public function view(User $user, Report $report): bool
    {
        if ($user->can('reports.review') || $user->can('reports.view_all')) {
            return true;
        }

        return $user->can('reports.view_approved') && $report->status === Report::STATUS_APPROVED;
    }

    public function review(User $user, Report $report): bool
    {
        return $user->can('reports.review');
    }
}
