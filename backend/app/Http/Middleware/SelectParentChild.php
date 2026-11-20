<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ParentStudent;
use App\Models\Role;
use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parent portal: the student self-service pages (my scores, attendance,
 * invoices, leave requests...) read `$user->student`. For a parent, that is
 * the child picked in the portal (header X-Child), and only one of their own
 * children (parent_students). A parent-only login with one child needs no
 * header. Parents only look, except sending a leave request for the child.
 */
final class SelectParentChild
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->hasRole(Role::PARENT)) {
            return $next($request);
        }

        $asked = $request->header('X-Child');
        if ($asked === null) {
            // A teacher who is also a parent, on their own pages: untouched.
            if ($user->student !== null || $user->staff !== null) {
                return $next($request);
            }
            $ids = ParentStudent::childIdsOf($user->id);
            if (count($ids) !== 1) {
                throw ValidationException::withMessages(['child' => __('Choose a child first.')]);
            }
            $asked = (string) $ids[0];
        }

        $id = (int) $asked;
        abort_unless(in_array($id, ParentStudent::childIdsOf($user->id), true), 403, __('This is not one of your children.'));
        abort_unless($request->isMethodSafe() || $request->routeIs('*my-leave-requests.*'), 403, __('Parents can view this, not change it.'));

        $user->setRelation('student', Student::query()->findOrFail($id));

        return $next($request);
    }
}
